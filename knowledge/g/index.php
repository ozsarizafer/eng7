<?php
// --- CONFIG & INITIALIZATION ---
session_start();
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_FILE', 'quiz.db');
define('TOTAL_QUESTIONS', 14);
define('QUESTION_TIME_LIMIT', 30);
define('ANSWER_REVEAL_TIME', 7);

// --- DATABASE SETUP ---
function getDB() {
    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("Veritabanı bağlantı hatası: " . $e->getMessage());
    }
}

function initializeDatabase() {
    if (file_exists(DB_FILE)) return;

    $db = getDB();
    // Tabloları oluştur
    $db->exec("
        CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question TEXT NOT NULL,
            options TEXT NOT NULL,
            correct_answer TEXT NOT NULL,
            category TEXT,
            difficulty TEXT
        );
        CREATE TABLE IF NOT EXISTS players (
            session_id TEXT PRIMARY KEY,
            team TEXT NOT NULL,
            player_number INTEGER NOT NULL,
            last_seen INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS game_state (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            status TEXT NOT NULL DEFAULT 'waiting', -- waiting, in_progress, show_answer, finished
            current_turn TEXT, -- 'A' or 'B'
            question_number INTEGER DEFAULT 0,
            team_a_score INTEGER DEFAULT 0,
            team_b_score INTEGER DEFAULT 0,
            current_question_id INTEGER,
            last_action_time INTEGER,
            asked_question_ids TEXT DEFAULT '[]'
        );
    ");

    // Soruları JSON'dan yükle
    if (file_exists('questions.json')) {
        $questionsJson = file_get_contents('questions.json');
        $questions = json_decode($questionsJson, true);
        if ($questions) {
            $stmt = $db->prepare("INSERT INTO questions (question, options, correct_answer, category, difficulty) VALUES (?, ?, ?, ?, ?)");
            foreach ($questions as $q) {
                $stmt->execute([
                    $q['question'],
                    json_encode($q['options']),
                    $q['correct_answer'],
                    $q['category'],
                    $q['difficulty']
                ]);
            }
        }
    }
    
    // Başlangıç oyun durumunu ayarla
    $db->exec("INSERT OR IGNORE INTO game_state (id) VALUES (1)");
}

// Veritabanını ve tabloları kontrol et
initializeDatabase();


// --- BACKEND ACTIONS (API) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $db = getDB();
    $userId = session_id();

    // Takıma katılma
    if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $team = $_POST['team'] ?? null;
        if ($team !== 'A' && $team !== 'B') {
            echo json_encode(['success' => false, 'message' => 'Geçersiz takım.']);
            exit;
        }

        try {
            $db->beginTransaction();

            // Oyuncunun mevcut takımını kontrol et
            $stmt = $db->prepare("SELECT team FROM players WHERE session_id = ?");
            $stmt->execute([$userId]);
            $currentPlayerTeam = $stmt->fetchColumn();

            // Hedef takımın oyuncu sayısını al
            $stmt = $db->prepare("SELECT COUNT(*) FROM players WHERE team = ?");
            $stmt->execute([$team]);
            $teamCount = $stmt->fetchColumn();
            
            // Takımın dolu olup olmadığını kontrol et (oyuncunun kendisi hariç)
            $isAlreadyOnThisTeam = ($currentPlayerTeam === $team);
            if ($teamCount >= 2 && !$isAlreadyOnThisTeam) {
                throw new Exception('Bu takım zaten dolu.');
            }

            // Oyuncuyu ekle/güncelle
            $player_number = $isAlreadyOnThisTeam ? $db->query("SELECT player_number FROM players WHERE session_id = '{$userId}'")->fetchColumn() : $teamCount + 1;
            $stmt = $db->prepare("INSERT OR REPLACE INTO players (session_id, team, player_number, last_seen) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $team, $player_number, time()]);

            // Oyunu başlatma kontrolü
            $team_a_count = $db->query("SELECT COUNT(*) FROM players WHERE team = 'A'")->fetchColumn();
            $team_b_count = $db->query("SELECT COUNT(*) FROM players WHERE team = 'B'")->fetchColumn();
            $current_status = $db->query("SELECT status FROM game_state WHERE id = 1")->fetchColumn();

            if ($current_status === 'waiting' && $team_a_count == 2 && $team_b_count == 2) {
                 // Oyunu başlat
                $stmt = $db->prepare("UPDATE game_state SET status = 'in_progress', current_turn = ?, question_number = 1, last_action_time = ? WHERE id = 1");
                $starting_team = rand(0,1) ? 'A' : 'B'; // İlk turu rastgele belirle
                $stmt->execute([$starting_team, time()]);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true, 'userId' => $userId, 'team' => $team, 'player_number' => $player_number]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Cevap gönderme
    if ($action === 'submit_answer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $answer = $_POST['answer'] ?? null;
        $state = $db->query("SELECT * FROM game_state WHERE id = 1")->fetch();
        $player = $db->query("SELECT * FROM players WHERE session_id = '{$userId}'")->fetch();

        if ($state['status'] === 'in_progress' && $player && $player['team'] === $state['current_turn']) {
            $question = $db->query("SELECT * FROM questions WHERE id = {$state['current_question_id']}")->fetch();
            $is_correct = ($answer === $question['correct_answer']);
            
            if ($is_correct) {
                $score_field = "team_{$player['team']}_score";
                $db->exec("UPDATE game_state SET {$score_field} = {$score_field} + 1");
            }

            $db->exec("UPDATE game_state SET status = 'show_answer', last_action_time = " . time());
            echo json_encode(['success' => true, 'correct' => $is_correct]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Cevap gönderme sırası sizde değil.']);
        }
        exit;
    }

    // Oyunu sıfırlama
    if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $db->exec("DELETE FROM players;");
        $db->exec("UPDATE game_state SET status = 'waiting', current_turn = NULL, question_number = 0, team_a_score = 0, team_b_score = 0, current_question_id = NULL, last_action_time = NULL, asked_question_ids = '[]' WHERE id = 1;");
        echo json_encode(['success' => true]);
        exit;
    }

    // Server-Sent Events (SSE) ile oyun durumunu gönderme
    if ($action === 'sse') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        while (true) {
            $db_sse = getDB(); // Döngü içinde yeni bağlantı
            
            // --- Oyun Mantığı Güncellemeleri ---
            $state = $db_sse->query("SELECT * FROM game_state WHERE id = 1")->fetch();
            $now = time();

            // Zaman aşımı kontrolü
            if ($state['status'] === 'in_progress' && ($now - $state['last_action_time']) > QUESTION_TIME_LIMIT) {
                $db_sse->exec("UPDATE game_state SET status = 'show_answer', last_action_time = {$now} WHERE id = 1");
                $state['status'] = 'show_answer';
                $state['last_action_time'] = $now;
            }

            // Cevap gösteriminden sonra yeni soruya geçiş
            if ($state['status'] === 'show_answer' && ($now - $state['last_action_time']) > ANSWER_REVEAL_TIME) {
                 if ($state['question_number'] >= TOTAL_QUESTIONS) {
                    $db_sse->exec("UPDATE game_state SET status = 'finished', last_action_time = {$now} WHERE id = 1");
                 } else {
                    $next_turn = ($state['current_turn'] === 'A') ? 'B' : 'A';
                    // *** FIX: Set current_question_id to NULL to trigger new question selection ***
                    $db_sse->exec("UPDATE game_state SET status = 'in_progress', current_turn = '{$next_turn}', last_action_time = {$now}, question_number = question_number + 1, current_question_id = NULL WHERE id = 1");
                 }
                 // Durumu yeniden çek
                 $state = $db_sse->query("SELECT * FROM game_state WHERE id = 1")->fetch();
            }

            // Yeni soru seçimi
            if ($state['status'] === 'in_progress' && is_null($state['current_question_id'])) {
                $asked_ids = json_decode($state['asked_question_ids'], true);
                $placeholders = !empty($asked_ids) ? implode(',', array_fill(0, count($asked_ids), '?')) : '';
                $sql = "SELECT id FROM questions";
                if(!empty($asked_ids)){
                   $sql .= " WHERE id NOT IN ($placeholders)";
                }
                $sql .= " ORDER BY RANDOM() LIMIT 1";
                
                $stmt = $db_sse->prepare($sql);
                if(!empty($asked_ids)){
                    $stmt->execute($asked_ids);
                } else {
                    $stmt->execute();
                }

                $new_question = $stmt->fetch();

                if ($new_question) {
                    $asked_ids[] = $new_question['id'];
                    $db_sse->prepare("UPDATE game_state SET current_question_id = ?, asked_question_ids = ? WHERE id = 1")
                         ->execute([$new_question['id'], json_encode($asked_ids)]);
                    $state['current_question_id'] = $new_question['id'];
                } else {
                    // Sorular bitti
                     $db_sse->exec("UPDATE game_state SET status = 'finished', last_action_time = {$now} WHERE id = 1");
                     $state['status'] = 'finished';
                }
            } else if($state['status'] !== 'in_progress' && $state['status'] !== 'show_answer'){
                 $db_sse->exec("UPDATE game_state SET current_question_id = NULL WHERE id = 1");
                 $state['current_question_id'] = null;
            }


            // --- İstemciye Gönderilecek Veriyi Hazırlama ---
            $output = ['state' => $state];
            $output['players'] = $db_sse->query("SELECT team, COUNT(*) as count FROM players GROUP BY team")->fetchAll(PDO::FETCH_KEY_PAIR);
            $output['userId'] = $userId;
            $my_player_info = $db_sse->query("SELECT * FROM players WHERE session_id = '{$userId}'")->fetch();
            $output['my_info'] = $my_player_info ?: null;

            if ($state['current_question_id']) {
                $output['question_data'] = $db_sse->query("SELECT * FROM questions WHERE id = {$state['current_question_id']}")->fetch();
                // Seçenekleri decode et
                if($output['question_data']){
                    $output['question_data']['options'] = json_decode($output['question_data']['options'], true);
                }
            } else {
                $output['question_data'] = null;
            }
            
            // Zamanlayıcı için kalan süreyi hesapla
            if ($state['status'] === 'in_progress') {
                $output['time_left'] = QUESTION_TIME_LIMIT - ($now - $state['last_action_time']);
            } else if ($state['status'] === 'show_answer') {
                 $output['time_left'] = ANSWER_REVEAL_TIME - ($now - $state['last_action_time']);
            } else {
                $output['time_left'] = 0;
            }
            
            echo "data: " . json_encode($output) . "\n\n";
            ob_flush();
            flush();
            
            // Aktif olmayan oyuncuları temizle (60 saniye)
            $db_sse->exec("DELETE FROM players WHERE last_seen < " . (time() - 60));

            // Bağlantıyı güncel tut
            if($my_player_info){
                 $db_sse->exec("UPDATE players SET last_seen = ".time()." WHERE session_id = '{$userId}'");
            }

            sleep(1); // 1 saniyede bir güncelle
        }
    }
    exit;
}
?>

<!-- HTML CONTENT -->
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Takım Bilgi Yarışması</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .option-btn { transition: all 0.2s ease-in-out; }
        .option-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .correct { background-color: #28a745 !important; color: white !important; border-color: #28a745 !important; }
        .incorrect { background-color: #dc3545 !important; color: white !important; border-color: #dc3545 !important; }
        .disabled { opacity: 0.6; cursor: not-allowed; }
        #timer-bar { transition: width 1s linear; }
    </style>
     <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-800 flex items-center justify-center min-h-screen">

    <div id="app" class="w-full max-w-4xl mx-auto p-4 md:p-8">
        
        <!-- LOBBY SCREEN -->
        <div id="lobby-screen" class="text-center bg-white p-8 rounded-2xl shadow-lg">
            <h1 class="text-4xl font-bold mb-4 text-indigo-600">Bilgi Yarışması</h1>
            <p class="text-gray-600 mb-8">Takımını seç ve yarışmaya hazırlan!</p>
            
            <div class="grid md:grid-cols-2 gap-8 mb-8">
                <!-- TEAM A -->
                <div class="border-2 border-red-400 p-6 rounded-xl">
                    <h2 class="text-3xl font-bold text-red-500 mb-4">Takım A</h2>
                    <div id="team-a-players" class="text-lg mb-4">Oyuncular: 0/2</div>
                    <button id="join-a-btn" class="w-full bg-red-500 text-white font-bold py-3 px-4 rounded-lg hover:bg-red-600 transition-colors">Takım A'ya Katıl</button>
                </div>
                <!-- TEAM B -->
                <div class="border-2 border-blue-400 p-6 rounded-xl">
                    <h2 class="text-3xl font-bold text-blue-500 mb-4">Takım B</h2>
                    <div id="team-b-players" class="text-lg mb-4">Oyuncular: 0/2</div>
                    <button id="join-b-btn" class="w-full bg-blue-500 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-600 transition-colors">Takım B'ye Katıl</button>
                </div>
            </div>
             <p id="waiting-message" class="text-xl font-medium text-gray-700 hidden">Oyuncular bekleniyor... Lütfen bekleyin.</p>
        </div>

        <!-- GAME SCREEN -->
        <div id="game-screen" class="hidden">
            <!-- HEADER -->
            <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-md mb-6">
                <div class="text-xl font-bold"><span class="text-red-500">Takım A:</span> <span id="score-a">0</span></div>
                <div class="text-2xl font-bold text-center">Soru <span id="question-num">1</span> / <?php echo TOTAL_QUESTIONS; ?></div>
                <div class="text-xl font-bold"><span class="text-blue-500">Takım B:</span> <span id="score-b">0</span></div>
            </div>

            <!-- TURN INDICATOR -->
            <div id="turn-indicator" class="text-center text-2xl font-semibold mb-4 p-3 rounded-lg">Sıra Takım A'da</div>

            <!-- TIMER -->
            <div class="w-full bg-gray-200 rounded-full h-4 mb-6 shadow-inner">
                <div id="timer-bar" class="bg-indigo-500 h-4 rounded-full"></div>
            </div>
            <div id="timer-text" class="text-center text-4xl font-bold mb-6">30</div>

            <!-- QUESTION & OPTIONS -->
            <div class="bg-white p-8 rounded-2xl shadow-lg">
                <div id="question-container" class="mb-8 text-center">
                    <h2 id="question-text" class="text-3xl font-semibold">Soru yükleniyor...</h2>
                </div>
                <div id="options-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Options will be generated here by JS -->
                </div>
            </div>
        </div>

        <!-- FINISH SCREEN -->
        <div id="finish-screen" class="hidden text-center bg-white p-12 rounded-2xl shadow-lg">
            <h1 class="text-5xl font-bold mb-4 text-indigo-600">Yarışma Bitti!</h1>
            <p id="winner-text" class="text-3xl font-semibold mb-8">Kazanan Takım A!</p>
            <div class="text-2xl mb-10">
                <p class="mb-2"><span class="font-bold text-red-500">Takım A:</span> <span id="final-score-a">0</span> Puan</p>
                <p><span class="font-bold text-blue-500">Takım B:</span> <span id="final-score-b">0</span> Puan</p>
            </div>
            <button id="reset-btn" class="bg-gray-700 text-white font-bold py-3 px-8 rounded-lg hover:bg-gray-800 transition-colors">Yeni Oyun</button>
        </div>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const lobbyScreen = document.getElementById('lobby-screen');
        const gameScreen = document.getElementById('game-screen');
        const finishScreen = document.getElementById('finish-screen');
        
        const joinABtn = document.getElementById('join-a-btn');
        const joinBBtn = document.getElementById('join-b-btn');
        const resetBtn = document.getElementById('reset-btn');
        const waitingMessage = document.getElementById('waiting-message');

        let myInfo = null;
        let timerInterval;
        let isJoining = false; // Flag to prevent multiple join clicks

        function updateUI(data) {
            // LOBBY UI
            const teamAPlayers = data.players?.A || 0;
            const teamBPlayers = data.players?.B || 0;
            document.getElementById('team-a-players').textContent = `Oyuncular: ${teamAPlayers}/2`;
            document.getElementById('team-b-players').textContent = `Oyuncular: ${teamBPlayers}/2`;
            
            joinABtn.disabled = teamAPlayers >= 2 && (!myInfo || myInfo.team !== 'A');
            joinBBtn.disabled = teamBPlayers >= 2 && (!myInfo || myInfo.team !== 'B');
            joinABtn.classList.toggle('disabled', joinABtn.disabled);
            joinBBtn.classList.toggle('disabled', joinBBtn.disabled);

            // If player is in a team, hide buttons and show waiting message
            if (myInfo && data.state.status === 'waiting') {
                joinABtn.classList.add('hidden');
                joinBBtn.classList.add('hidden');
                waitingMessage.classList.remove('hidden');
            } else if (!myInfo) { // If player is not in a team (e.g., after reset)
                joinABtn.classList.remove('hidden');
                joinBBtn.classList.remove('hidden');
                waitingMessage.classList.add('hidden');
            }

            // Screen management
            if (data.state.status === 'waiting') {
                lobbyScreen.classList.remove('hidden');
                gameScreen.classList.add('hidden');
                finishScreen.classList.add('hidden');
            } else if (data.state.status === 'in_progress' || data.state.status === 'show_answer') {
                lobbyScreen.classList.add('hidden');
                gameScreen.classList.remove('hidden');
                finishScreen.classList.add('hidden');
                renderGame(data);
            } else if (data.state.status === 'finished') {
                lobbyScreen.classList.add('hidden');
                gameScreen.classList.add('hidden');
                finishScreen.classList.remove('hidden');
                renderFinish(data);
            }
        }
        
        function renderGame(data) {
            // Scores
            document.getElementById('score-a').textContent = data.state.team_a_score;
            document.getElementById('score-b').textContent = data.state.team_b_score;
            document.getElementById('question-num').textContent = data.state.question_number;
            
            // Turn indicator
            const turnIndicator = document.getElementById('turn-indicator');
            const currentTurn = data.state.current_turn;
            turnIndicator.textContent = `Sıra Takım ${currentTurn}'da`;
            turnIndicator.className = `text-center text-2xl font-semibold mb-4 p-3 rounded-lg ${currentTurn === 'A' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'}`;

            // Timer
            updateTimer(data.time_left, data.state.status === 'in_progress' ? QUESTION_TIME_LIMIT : ANSWER_REVEAL_TIME);
            
            // Question & Options
            const questionContainer = document.getElementById('question-container');
            const optionsContainer = document.getElementById('options-container');
            const questionText = document.getElementById('question-text');
            
            optionsContainer.innerHTML = '';
            
            if (data.question_data && myInfo) { 
                const isMyTurn = myInfo.team === currentTurn;
                const isMyTeamMate = (data.state.question_number + myInfo.player_number) % 2 === 0;

                let canSeeQuestion = !isMyTurn || isMyTeamMate || (data.state.status === 'show_answer');
                let canSeeOptions = !isMyTurn || !isMyTeamMate || (data.state.status === 'show_answer');
                
                if(myInfo.team !== currentTurn) {
                    canSeeQuestion = true;
                    canSeeOptions = true;
                }

                questionText.textContent = canSeeQuestion ? data.question_data.question : "Takım arkadaşın soruyu görüyor. Cevapları bekle!";
                
                const existingP = questionContainer.querySelector('p');
                if(existingP) existingP.remove();

                if (canSeeOptions) {
                    data.question_data.options.forEach(option => {
                        const button = document.createElement('button');
                        button.textContent = option;
                        button.classList.add('option-btn', 'w-full', 'text-left', 'p-4', 'border-2', 'rounded-lg', 'text-lg', 'font-medium');
                        button.dataset.answer = option;
                        
                        if (isMyTurn && !isMyTeamMate && data.state.status === 'in_progress') {
                            button.onclick = () => submitAnswer(option);
                        } else {
                            button.disabled = true;
                            button.classList.add('disabled');
                        }
                        
                        optionsContainer.appendChild(button);
                    });
                } else {
                    const p = document.createElement('p');
                    p.className = "text-xl mt-4 text-gray-600";
                    p.textContent = "Takım arkadaşın şıkları görüyor. Ona soruyu oku!";
                    questionContainer.appendChild(p);
                }
                
                if (data.state.status === 'show_answer') {
                     showCorrectAnswer(data.question_data.correct_answer);
                }
            }
        }
        
        function updateTimer(timeLeft, maxTime) {
            clearInterval(timerInterval);
            const timerText = document.getElementById('timer-text');
            const timerBar = document.getElementById('timer-bar');
            let time = Math.max(0, Math.floor(timeLeft));
            
            timerText.textContent = time;
            timerBar.style.width = maxTime > 0 ? `${(time / maxTime) * 100}%` : '0%';

            timerInterval = setInterval(() => {
                time--;
                if(time >= 0) {
                    timerText.textContent = time;
                    timerBar.style.width = maxTime > 0 ? `${(time / maxTime) * 100}%` : '0%';
                }
                if (time < 0) {
                    clearInterval(timerInterval);
                }
            }, 1000);
        }

        function showCorrectAnswer(correctAnswer) {
             const optionsContainer = document.getElementById('options-container');
             const buttons = optionsContainer.querySelectorAll('.option-btn');
             buttons.forEach(btn => {
                btn.disabled = true;
                btn.classList.add('disabled');
                if (btn.dataset.answer === correctAnswer) {
                    btn.classList.add('correct');
                } else {
                    btn.classList.add('incorrect');
                }
             });
        }
        
        function renderFinish(data) {
            const winnerText = document.getElementById('winner-text');
            const scoreA = data.state.team_a_score;
            const scoreB = data.state.team_b_score;
            document.getElementById('final-score-a').textContent = scoreA;
            document.getElementById('final-score-b').textContent = scoreB;
            
            if (scoreA > scoreB) {
                winnerText.textContent = 'Kazanan: Takım A!';
                winnerText.className = 'text-3xl font-semibold mb-8 text-red-500';
            } else if (scoreB > scoreA) {
                winnerText.textContent = 'Kazanan: Takım B!';
                 winnerText.className = 'text-3xl font-semibold mb-8 text-blue-500';
            } else {
                winnerText.textContent = 'Oyun Berabere!';
                winnerText.className = 'text-3xl font-semibold mb-8 text-gray-700';
            }
        }

        async function joinTeam(team) {
            if (isJoining) return;
            isJoining = true;
            joinABtn.disabled = true;
            joinBBtn.disabled = true;

            const formData = new FormData();
            formData.append('team', team);

            try {
                const response = await fetch('index.php?action=join', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (!result.success) {
                    alert(result.message);
                }
            } catch (error) {
                console.error('Error joining team:', error);
                alert('Takıma katılırken bir hata oluştu.');
            } finally {
                isJoining = false;
            }
        }
        
        async function submitAnswer(answer) {
             const optionsContainer = document.getElementById('options-container');
             const buttons = optionsContainer.querySelectorAll('.option-btn');
             buttons.forEach(btn => btn.disabled = true);
             
             const formData = new FormData();
             formData.append('answer', answer);
             
             await fetch('index.php?action=submit_answer', {
                method: 'POST',
                body: formData
             });
        }
        
        async function resetGame() {
            await fetch('index.php?action=reset', { method: 'POST' });
            myInfo = null;
        }

        joinABtn.addEventListener('click', () => joinTeam('A'));
        joinBBtn.addEventListener('click', () => joinTeam('B'));
        resetBtn.addEventListener('click', resetGame);

        const sse = new EventSource('index.php?action=sse');
        sse.onmessage = function(event) {
            const data = JSON.parse(event.data);
            myInfo = data.my_info; // Always trust the server's info about me
            updateUI(data);
        };
        sse.onerror = function() {
            // console.error("SSE connection error.");
        };
    });
    </script>
</body>
</html>

