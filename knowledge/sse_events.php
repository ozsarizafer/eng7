<?php
require_once 'game.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Disable output buffering
if (ob_get_level()) ob_end_clean();

session_start();

$controller = new GameController();
$lastState = null;

while (true) {
    // Get current game state
    $currentState = $controller->getGameState();
    
    // Check if state has changed
    if ($currentState !== $lastState) {
        echo "data: " . json_encode([
            'type' => 'game_update',
            'data' => $currentState,
            'timestamp' => time()
        ]) . "\n\n";
        
        $lastState = $currentState;
        flush();
    }
    
    // Check for specific events
    $game = $currentState['game'];
    
    if ($game && $game['status'] === 'active') {
        // Check if question time is up
        $timeElapsed = time() - $game['question_start_time'];
        if ($timeElapsed >= 60) { // 60 seconds per question
            echo "data: " . json_encode([
                'type' => 'time_up',
                'data' => ['question_id' => $game['current_question']],
                'timestamp' => time()
            ]) . "\n\n";
            flush();
        }
        
        // Send timer update every second
        echo "data: " . json_encode([
            'type' => 'timer_update',
            'data' => ['time_remaining' => max(0, 60 - $timeElapsed)],
            'timestamp' => time()
        ]) . "\n\n";
        flush();
    }
    
    // Send heartbeat every 5 seconds
    echo "event: heartbeat\n";
    echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
    flush();
    
    sleep(1); // Check every second
    
    // Break if connection is closed
    if (connection_aborted()) {
        break;
    }
}
?>