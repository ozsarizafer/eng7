<?php
// sse.php
// Server-Sent Events stream that pushes game state when DB.last_change updates
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$dbFile = __DIR__ . '/game.db';
$questionsFile = __DIR__ . '/questions.json';
function getPDO($dbFile){
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}
$pdo = getPDO($dbFile);
$lastSent = 0;
while (!connection_aborted()) {
    try {
        $row = $pdo->query("SELECT last_change FROM game WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $lc = $row ? (int)$row['last_change'] : 0;
        if($lc !== $lastSent){
            $lastSent = $lc;
            // build payload: players + game
            $players = $pdo->query("SELECT id,name,team,joined_at FROM players")->fetchAll(PDO::FETCH_ASSOC);
            $game = $pdo->query("SELECT * FROM game WHERE id=1")->fetch(PDO::FETCH_ASSOC);
            if($game){
                $game['scores'] = json_decode($game['scores'], true);
            }
            $payload = ['players'=>$players, 'game'=>$game];
            echo "data: " . json_encode($payload) . "\n\n";
            @ob_flush();
            @flush();
        }
    } catch(Exception $e){
        // ignore
    }
    sleep(1);
}
