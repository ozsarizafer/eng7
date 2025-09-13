<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// SQLite veritabanı bağlantısı
$db = new SQLite3('quiz.db');

// Tabloları oluştur
$db->exec('CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    team TEXT NOT NULL,
    role TEXT,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

$db->exec('CREATE TABLE IF NOT EXISTS game_state (
    key TEXT PRIMARY KEY,
    value TEXT
)');

$db->exec('CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question TEXT NOT NULL,
    options TEXT NOT NULL,
    correct_answer TEXT NOT NULL,
    category TEXT,
    difficulty TEXT
)');

// Örnek soruları ekle (eğer yoksa)
$questionCount = $db->querySingle('SELECT COUNT(*) FROM questions');
if ($questionCount == 0) {
    $questions = [
        [
            'question' => 'Which phrase appears at the beginning of almost every chapter (surah) in the Qur\'an?',
            'options' => ['Inna lillahi wa inna ilayhi raji\'un', 'Bismillahirrahmanirrahim', 'Allahu Akbar', 'La ilaha illallah'],
            'correct_answer' => 'Bismillahirrahmanirrahim',
            'category' => 'religion',
            'difficulty' => 'medium'
        ],
        // Diğer sorular buraya eklenecek
    ];
    
    $stmt = $db->prepare('INSERT INTO questions (question, options, correct_answer, category, difficulty) 
                         VALUES (:question, :options, :correct_answer, :category, :difficulty)');
    
    foreach ($questions as $q) {
        $stmt->bindValue(':question', $q['question'], SQLITE3_TEXT);
        $stmt->bindValue(':options', json_encode($q['options']), SQLITE3_TEXT);
        $stmt->bindValue(':correct_answer', $q['correct_answer'], SQLITE3_TEXT);
        $stmt->bindValue(':category', $q['category'], SQLITE3_TEXT);
        $stmt->bindValue(':difficulty', $q['difficulty'], SQLITE3_TEXT);
        $stmt->execute();
    }
}

// API isteklerini işle
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action == 'join') {
    $name = $input['player_name'];
    $team = $input['team'];
    
    // Takım doluluk kontrolü
    $teamCount = $db->querySingle("SELECT COUNT(*) FROM players WHERE team = '$team'");
    
    if ($teamCount >= 2) {
        echo json_encode(['success' => false, 'message' => 'Takım doldu']);
        exit;
    }
    
    // Oyuncuyu ekle
    $stmt = $db->prepare('INSERT INTO players (name, team) VALUES (:name, :team)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':team', $team, SQLITE3_TEXT);
    $stmt->execute();
    
    $playerId = $db->lastInsertRowID();
    
    // İlk oyuncuya soru, ikinci oyuncuya seçenekler rolü ver
    $role = $teamCount == 0 ? 'question' : 'options';
    
    $stmt = $db->prepare('UPDATE players SET role = :role WHERE id = :id');
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
    $stmt->bindValue(':id', $playerId, SQLITE3_INTEGER);
    $stmt->execute();
    
    // Oyun durumunu güncelle
    $teamACount = $db->querySingle("SELECT COUNT(*) FROM players WHERE team = 'A'");
    $teamBCount = $db->querySingle("SELECT COUNT(*) FROM players WHERE team = 'B'");
    
    if ($teamACount == 2 && $teamBCount == 2) {
        // Oyunu başlat
        $db->exec("INSERT OR REPLACE INTO game_state (key, value) VALUES ('game_status', 'running')");
        $db->exec("INSERT OR REPLACE INTO game_state (key, value) VALUES ('current_question', '1')");
        $db->exec("INSERT OR REPLACE INTO game_state (key, value) VALUES ('current_team', 'A')");
        $db->exec("INSERT OR REPLACE INTO game_state (key, value) VALUES ('team_a_score', '0')");
        $db->exec("INSERT OR REPLACE INTO game_state (key, value) VALUES ('team_b_score', '0')");
        
        // Zamanlayıcıyı başlat
        $db->exec("INSERT OR REPLACE INTO game_state (key, value) VALUES ('question_end_time', '" . (time() + 30) . "')");
    }
    
    echo json_encode(['success' => true, 'player_id' => $playerId, 'role' => $role]);
}
elseif ($action == 'reset') {
    // Oyunu sıfırla
    $db->exec('DELETE FROM players');
    $db->exec('DELETE FROM game_state');
    echo json_encode(['success' => true]);
}

$db->close();
?>