<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

// SQLite veritabanı bağlantısı
$db = new SQLite3('quiz.db');

$playerId = $_GET['player_id'] ?? '';

// Son etkinlik ID'sini takip et
$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0;
if ($lastEventId) {
    $lastEventId = intval($lastEventId);
}

// SSE döngüsü
while (true) {
    // Oyuncu bilgilerini al
    $player = $db->querySingle("SELECT * FROM players WHERE id = $playerId", true);
    
    // Oyun durumunu al
    $gameStatus = $db->querySingle("SELECT value FROM game_state WHERE key = 'game_status'");
    $currentQuestion = $db->querySingle("SELECT value FROM game_state WHERE key = 'current_question'");
    $currentTeam = $db->querySingle("SELECT value FROM game_state WHERE key = 'current_team'");
    $teamAScore = $db->querySingle("SELECT value FROM game_state WHERE key = 'team_a_score'");
    $teamBScore = $db->querySingle("SELECT value FROM game_state WHERE key = 'team_b_score'");
    $questionEndTime = $db->querySingle("SELECT value FROM game_state WHERE key = 'question_end_time'");
    
    // Takım bilgilerini al
    $teamA = [];
    $result = $db->query("SELECT id, name, role FROM players WHERE team = 'A'");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $teamA[] = $row;
    }
    
    $teamB = [];
    $result = $db->query("SELECT id, name, role FROM players WHERE team = 'B'");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $teamB[] = $row;
    }
    
    // Oyuncu durum güncellemesi
    $eventData = [
        'type' => 'player_joined',
        'teams' => [
            'A' => $teamA,
            'B' => $teamB
        ]
    ];
    
    echo "id: " . ++$lastEventId . "\n";
    echo "data: " . json_encode($eventData) . "\n\n";
    ob_flush();
    flush();
    
    // Oyun başladıysa
    if ($gameStatus == 'running') {
        // Zaman kontrolü
        $timeLeft = max(0, $questionEndTime - time());
        
        // Zaman güncellemesi
        $eventData = [
            'type' => 'time_update',
            'time_left' => $timeLeft
        ];
        
        echo "id: " . ++$lastEventId . "\n";
        echo "data: " . json_encode($eventData) . "\n\n";
        ob_flush();
        flush();
        
        // Zaman dolduysa
        if ($timeLeft == 0) {
            // Sonraki soruya geç veya oyunu bitir
            // Bu kısım daha karmaşık bir mantık gerektirir
        }
    }
    
    // 2 saniye bekle
    sleep(2);
}

$db->close();
?>