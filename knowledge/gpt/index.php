<?php
// index.php
// Single-file UI + API for 2v2 timed quiz
// Requires: PHP + pdo_sqlite, questions.json in same folder
// Place this file and questions.json on a PHP server and open in browser.

// --- Config ---
$dbFile = __DIR__ . '/game.db';
$questionsFile = __DIR__ . '/questions.json';
$TOTAL_ROUNDS_PER_MATCH = 14; // toplam soru sayısı (takımlar arasında sırayla)
$ANSWER_SECONDS = 30;
$TRANSITION_SECONDS = 7;
$CYCLE_SECONDS = $ANSWER_SECONDS + $TRANSITION_SECONDS; // 37

// --- Helpers ---
function getPDO($dbFile) {
    $needInit = !file_exists($dbFile);
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($needInit) {
        // create tables
        $pdo->exec("CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT)");
        $pdo->exec("CREATE TABLE players (id TEXT PRIMARY KEY, name TEXT, team TEXT, joined_at INTEGER)");
        $pdo->exec("CREATE TABLE game (id INTEGER PRIMARY KEY, status TEXT, started_at INTEGER, start_time INTEGER, current_round INTEGER, scores TEXT, last_change INTEGER)");
        $pdo->exec("CREATE TABLE answers (id INTEGER PRIMARY KEY AUTOINCREMENT, round INTEGER, team TEXT, player_id TEXT, answer TEXT, correct INTEGER, timestamp INTEGER)");
        // init meta/game
        $now = time();
        $stmt = $pdo->prepare("INSERT INTO game (id, status, started_at, start_time, current_round, scores, last_change) VALUES (1, 'waiting', :started_at, 0, 0, :scores, :lc)");
        $stmt->execute([':started_at'=>$now, ':scores'=>json_encode(['A'=>0,'B'=>0]), ':lc'=>$now]);
    }
    return $pdo;
}

function loadQuestions($questionsFile){
    $raw = file_get_contents($questionsFile);
    $arr = json_decode($raw, true);
    if(!$arr) return [];
    return $arr;
}

// --- API endpoints ---
$action = $_GET['action'] ?? null;
if($action){
    header('Content-Type: application/json');
    $pdo = getPDO($dbFile);
    if($action === 'join'){
        // param: name, team (A or B) or auto
        $name = trim($_POST['name'] ?? 'Guest');
        $team = strtoupper(trim($_POST['team'] ?? ''));
        if($team !== 'A' && $team !== 'B') {
            echo json_encode(['ok'=>false,'error'=>'team must be A or B']);
            exit;
        }
        $id = bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team = :team");
        $stmt->execute([':team'=>$team]);
        $count = (int)$stmt->fetchColumn();
        if($count >= 2) {
            echo json_encode(['ok'=>false,'error'=>'team full']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO players (id, name, team, joined_at) VALUES (:id,:name,:team,:t)");
        $stmt->execute([':id'=>$id,':name'=>$name,':team'=>$team,':t'=>time()]);
        // update last_change
        $pdo->prepare("UPDATE game SET last_change = :lc WHERE id=1")->execute([':lc'=>time()]);
        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    } elseif($action === 'leave'){
        $id = $_POST['id'] ?? '';
        if($id){
            $pdo->prepare("DELETE FROM players WHERE id=:id")->execute([':id'=>$id]);
            $pdo->prepare("UPDATE game SET status='waiting', last_change=:lc WHERE id=1")->execute([':lc'=>time()]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    } elseif($action === 'reset'){
        // clear players, answers, reset game
        $pdo->exec("DELETE FROM players");
        $pdo->exec("DELETE FROM answers");
        $now=time();
        $pdo->prepare("UPDATE game SET status='waiting', started_at=:sa, start_time=0, current_round=0, scores=:scores, last_change=:lc WHERE id=1")
            ->execute([':sa'=>$now,':scores'=>json_encode(['A'=>0,'B'=>0]), ':lc'=>$now]);
        echo json_encode(['ok'=>true]);
        exit;
    } elseif($action === 'start'){
        // start game if 4 players (2 per team)
        $stmt = $pdo->query("SELECT team, COUNT(*) c FROM players GROUP BY team");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['A'=>0,'B'=>0];
        foreach($rows as $r) $counts[$r['team']] = (int)$r['c'];
        if($counts['A'] < 2 || $counts['B'] < 2){
            echo json_encode(['ok'=>false,'error'=>'Not enough players']);
            exit;
        }
        $now = time();
        // record start_time
        $pdo->prepare("UPDATE game SET status='running', started_at=:sa, start_time=:st, current_round=0, last_change=:lc WHERE id=1")
            ->execute([':sa'=>$now, ':st'=>$now, ':lc'=>$now]);
        echo json_encode(['ok'=>true]);
        exit;
    } elseif($action === 'status'){
        // return players, game, time info
        $players = $pdo->query("SELECT id,name,team,joined_at FROM players")->fetchAll(PDO::FETCH_ASSOC);
        $game = $pdo->query("SELECT * FROM game WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $game['scores'] = json_decode($game['scores'], true);
        echo json_encode(['ok'=>true,'players'=>$players,'game'=>$game]);
        exit;
    } elseif($action === 'answer'){
        // player submits answer for current round
        $player_id = $_POST['id'] ?? '';
        $choice = $_POST['choice'] ?? '';
        $pdo->beginTransaction();
        $game = $pdo->query("SELECT * FROM game WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $start_time = (int)$game['start_time'];
        if($game['status'] !== 'running' || $start_time<=0){
            echo json_encode(['ok'=>false,'error'=>'game not running']);
            exit;
        }
        $elapsed = time() - $start_time;
        $round = floor($elapsed / $GLOBALS['CYCLE_SECONDS']); // current round index starting 0
        if($round >= $TOTAL_ROUNDS_PER_MATCH){
            echo json_encode(['ok'=>false,'error'=>'game finished']);
            exit;
        }
        // determine which team is answering this round: alternate starting with A (round 0 -> A)
        $teamTurn = ($round % 2 === 0) ? 'A' : 'B';
        // get player's team
        $stmt = $pdo->prepare("SELECT team FROM players WHERE id=:id");
        $stmt->execute([':id'=>$player_id]);
        $prow = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$prow) { echo json_encode(['ok'=>false,'error'=>'player not found']); $pdo->rollBack(); exit; }
        $pteam = $prow['team'];
        if($pteam !== $teamTurn){
            echo json_encode(['ok'=>false,'error'=>'not your team turn']);
            $pdo->rollBack();
            exit;
        }
        // get question for this round
        $questions = loadQuestions($questionsFile);
        $qIndex = selectQuestionIndex($questions, $start_time, $round);
        $q = $questions[$qIndex];
        $correct = ($choice === $q['correct_answer']) ? 1 : 0;
        // record answer (only first answer for team+round counts)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM answers WHERE round=:r AND team=:t");
        $stmt->execute([':r'=>$round,':t'=>$pteam]);
        $already = (int)$stmt->fetchColumn();
        if($already > 0){
            echo json_encode(['ok'=>false,'error'=>'already answered']);
            $pdo->rollBack();
            exit;
        }
        $pdo->prepare("INSERT INTO answers (round, team, player_id, answer, correct, timestamp) VALUES (:r,:t,:pid,:ans,:c,:ts)")
            ->execute([':r'=>$round,':t'=>$pteam,':pid'=>$player_id,':ans'=>$choice,':c'=>$correct,':ts'=>time()]);
        if($correct){
            // increment score
            $gameRow = $pdo->query("SELECT * FROM game WHERE id=1")->fetch(PDO::FETCH_ASSOC);
            $scores = json_decode($gameRow['scores'], true);
            $scores[$pteam] = ($scores[$pteam] ?? 0) + 1;
            $pdo->prepare("UPDATE game SET scores=:s, last_change=:lc WHERE id=1")->execute([':s'=>json_encode($scores),':lc'=>time()]);
        } else {
            $pdo->prepare("UPDATE game SET last_change=:lc WHERE id=1")->execute([':lc'=>time()]);
        }
        $pdo->commit();
        echo json_encode(['ok'=>true,'correct'=>$correct]);
        exit;
    }
    // default
    echo json_encode(['ok'=>false,'error'=>'unknown action']);
    exit;
}

// --- Utility: deterministic question selection per round using start_time + round
function selectQuestionIndex($questions, $start_time, $round){
    $pool = count($questions);
    if($pool === 0) return 0;
    // deterministic hash: crc32 of start_time:round
    $key = $start_time . ':' . $round;
    $h = crc32($key);
    $idx = intval($h % $pool);
    return $idx;
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>2v2 Quiz - Minimal</title>
<style>
    /* Minimal responsive styling */
    body{font-family:system-ui,Arial;margin:0;padding:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f5f7fb}
    .app{width:100%;max-width:980px;background:white;border-radius:12px;box-shadow:0 6px 24px rgba(10,10,40,0.08);padding:18px}
    header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    h1{font-size:18px;margin:0}
    .flex{display:flex;gap:8px;align-items:center}
    .col{display:flex;flex-direction:column;gap:8px}
    .teams{display:flex;gap:12px;flex-wrap:wrap}
    .team{flex:1;padding:8px;border:1px solid #eee;border-radius:8px}
    button{padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer}
    .primary{background:#0b74ff;color:white;border-color:#0b74ff}
    .player{background:#fafafa;padding:6px;border-radius:6px}
    .hud{display:flex;gap:12px;align-items:center}
    .question-box{margin-top:12px;padding:12px;border-radius:8px;background:#fcfdff;border:1px solid #eef2ff}
    .options{display:flex;flex-direction:column;gap:8px;margin-top:8px}
    .opt{padding:10px;border-radius:8px;border:1px solid #ddd;background:white;cursor:pointer}
    .opt.disabled{opacity:0.6;cursor:not-allowed}
    .center{text-align:center}
    @media (max-width:600px){ .teams{flex-direction:column} }
</style>
</head>
<body>
<div class="app">
    <header>
        <h1>2v2 Quiz — Minimal (PHP + SSE + SQLite)</h1>
        <div class="hud">
            <div id="timer">--</div>
            <button id="resetBtn">Reset Game</button>
        </div>
    </header>

    <div class="col">
        <div>
            <label>Name: <input id="name" value="Player"></label>
            <label>Team:
                <select id="teamSel">
                    <option value="A">Team A</option>
                    <option value="B">Team B</option>
                </select>
            </label>
            <button id="joinBtn">Join</button>
            <button id="leaveBtn">Leave</button>
            <button id="startBtn" class="primary">Start Game (manual)</button>
        </div>

        <div style="margin-top:12px" class="teams">
            <div class="team">
                <strong>Team A</strong>
                <div id="teamA"></div>
            </div>
            <div class="team">
                <strong>Team B</strong>
                <div id="teamB"></div>
            </div>
            <div class="team" style="min-width:160px;">
                <strong>Scores</strong>
                <div id="scores">A:0 - B:0</div>
            </div>
        </div>

        <div class="question-box" id="qbox">
            <div id="roundInfo" class="center">Oyun bekleniyor...</div>
            <div id="questionArea" style="display:none">
                <div id="roleInfo" class="center"></div>
                <div id="questionText" style="font-weight:600;margin-top:8px"></div>
                <div id="optionsArea" class="options"></div>
            </div>
        </div>
    </div>
</div>

<script>
const API = (a, data) => fetch('index.php?action='+a, {method:'POST', body: new URLSearchParams(data||{})}).then(r=>r.json());
let myId = null;
let myTeam = null;
let nameVal = null;

document.getElementById('joinBtn').addEventListener('click', async ()=>{
    nameVal = document.getElementById('name').value || 'Player';
    const team = document.getElementById('teamSel').value;
    const res = await API('join', {name:nameVal, team});
    if(res.ok){
        myId = res.id;
        myTeam = team;
        startSSE();
    } else {
        alert(res.error || 'join failed');
    }
});

document.getElementById('leaveBtn').addEventListener('click', async ()=>{
    if(!myId) return;
    await API('leave', {id:myId});
    myId = null;
});

document.getElementById('startBtn').addEventListener('click', async ()=>{
    const res = await API('start', {});
    if(!res.ok) alert(res.error || 'cannot start');
});

document.getElementById('resetBtn').addEventListener('click', async ()=>{
    await API('reset', {});
});

let es = null;
function startSSE(){
    if(es) es.close();
    es = new EventSource('sse.php');
    es.onmessage = (e) => {
        // payload is JSON game state
        const data = JSON.parse(e.data);
        renderState(data);
    };
    es.onerror = (e) => {
        console.log('SSE error', e);
    };
}

async function renderState(state){
    // players
    const teamA = document.getElementById('teamA');
    const teamB = document.getElementById('teamB');
    teamA.innerHTML = '';
    teamB.innerHTML = '';
    state.players.forEach(p=>{
        const el = document.createElement('div');
        el.className = 'player';
        el.textContent = (p.name||'') + (p.id === myId ? ' (you)' : '');
        if(p.team === 'A') teamA.appendChild(el); else teamB.appendChild(el);
    });
    // scores
    document.getElementById('scores').textContent = 'A:' + state.game.scores.A + ' - B:' + state.game.scores.B;

    // game running?
    if(state.game.status === 'running' && state.game.start_time>0){
        document.getElementById('roundInfo').textContent = 'Oyun başladı';
        document.getElementById('questionArea').style.display = 'block';
        // compute round and time left (client side display)
        const now = Math.floor(Date.now()/1000);
        const elapsed = now - state.game.start_time;
        const cycle = <?php echo $CYCLE_SECONDS; ?>;
        const round = Math.floor(elapsed / cycle);
        const inCycle = elapsed % cycle;
        const answerSeconds = <?php echo $ANSWER_SECONDS; ?>;
        let timeLeft = answerSeconds - inCycle;
        if(timeLeft < 0) timeLeft = 0;
        document.getElementById('timer').textContent = 'Time: ' + timeLeft + 's (round ' + (round+1) + '/' + <?php echo $TOTAL_ROUNDS_PER_MATCH; ?> + ')';

        // determine which team is answering
        const teamTurn = (round % 2 === 0) ? 'A' : 'B';
        // Determine role for each player in same team: we define deterministic role:
        // For a given team and round, pick which of the team's players sees question and which sees options.
        // The server uses deterministic question; here we simply show content depending on myTeam and role.
        // Request question data from server via endpoint? SSE already includes no question text (we decided question selection deterministic)
        // We'll call the server status to get question and correct answer visibility.
        // But to avoid extra call each SSE, we'll call a lightweight endpoint via fetch to get question for round.
        const qResp = await fetch('index.php?action=status').then(r=>r.json());
        const game = qResp.game;
        // determine selected question index using same algorithm as server:
        const start_time = game.start_time;
        // compute question index same as server hashing (we implement same function in JS)
        const qIndex = selectQuestionIndex(start_time, round, <?php echo count(json_decode(file_get_contents($questionsFile), true)); ?>);
        // load questions.json once (cache)
        if(!window._questions){
            window._questions = await fetch('questions.json').then(r=>r.json());
        }
        const q = window._questions[qIndex];
        // who sees what?
        // if myTeam is the teamTurn:
        //   - teamTurn players: one sees question only, other sees options only.
        //   - we don't track which client is which; to alternate every question we choose by player's join order.
        // get players of myTeam to decide index
        const myPlayers = state.players.filter(p=>p.team===myTeam);
        let myRole = 'spectator';
        if(myId && myPlayers.length>0){
            // sort by joined_at to have deterministic order
            myPlayers.sort((a,b)=>a.joined_at - b.joined_at);
            const myIndex = myPlayers.findIndex(p=>p.id===myId);
            if(myIndex >= 0){
                // alternate by round: if round even, player0 sees question, player1 sees options; flip if odd
                const toggle = round % 2 === 0 ? 0 : 1;
                if(myIndex === toggle) myRole = 'question';
                else myRole = 'options';
            }
        }

        // show content based on many cases:
        const roleInfo = document.getElementById('roleInfo');
        const questionText = document.getElementById('questionText');
        const optionsArea = document.getElementById('optionsArea');
        optionsArea.innerHTML = '';
        if(myTeam === teamTurn){
            roleInfo.textContent = 'It is your TEAM turn: Team ' + teamTurn;
            if(myRole === 'question'){
                // show question only
                questionText.textContent = q.question;
                // options hidden
                optionsArea.innerHTML = '<div class="center">Your teammate sees options.</div>';
            } else if(myRole === 'options'){
                questionText.textContent = 'Teammate sees the question. You see options.';
                // show clickable options
                q.options.forEach(opt=>{
                    const btn = document.createElement('div');
                    btn.className = 'opt';
                    btn.textContent = opt;
                    // disable if not in answer window
                    if(timeLeft<=0) btn.classList.add('disabled');
                    btn.addEventListener('click', async ()=>{
                        if(!myId){ alert('Join first'); return; }
                        if(timeLeft<=0){ alert('Time up'); return; }
                        // submit
                        const res = await API('answer', {id: myId, choice: opt});
                        if(res.ok){
                            if(res.correct) alert('Correct! +1 point');
                            else alert('Wrong');
                        } else {
                            alert(res.error || 'error');
                        }
                    });
                    optionsArea.appendChild(btn);
                });
            } else {
                roleInfo.textContent = 'Your team but unknown role';
                questionText.textContent = '';
            }
        } else {
            // spectator (other team) sees both question and options but cannot answer
            roleInfo.textContent = 'Watching — opponent team turn: ' + teamTurn;
            questionText.textContent = q.question;
            q.options.forEach(opt=>{
                const el = document.createElement('div');
                el.className = 'opt disabled';
                el.textContent = opt;
                optionsArea.appendChild(el);
            });
        }

        // if timeLeft === 0 show correct answer automatically
        if(timeLeft <= 0){
            // reveal correct answer visually
            const correct = q.correct_answer;
            Array.from(optionsArea.children).forEach(ch=>{
                if(ch.textContent === correct){
                    ch.style.borderColor = '#2ecc71';
                    ch.style.fontWeight = '700';
                } else {
                    ch.style.opacity = '0.6';
                }
            });
        }
    } else {
        document.getElementById('roundInfo').textContent = 'Waiting players / game not started';
        document.getElementById('questionArea').style.display = 'none';
        document.getElementById('timer').textContent = '--';
    }
}

// JS function to replicate server's question selection
function selectQuestionIndex(start_time, round, poolSize){
    const key = start_time + ':' + round;
    // simple crc32 imitation in JS using built-in function substitute: use djb2
    let hash = 5381;
    for(let i=0;i<key.length;i++){
        hash = ((hash << 5) + hash) + key.charCodeAt(i);
        hash = hash >>> 0;
    }
    return hash % poolSize;
}

// start an initial poll of status in case SSE not started
(async ()=>{
    const st = await fetch('index.php?action=status').then(r=>r.json());
    if(st.ok){
        renderState(st);
    }
})();
</script>
</body>
</html>
