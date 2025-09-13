<?php
require_once 'game_controller.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Disable output buffering
if (ob_get_level()) ob_end_clean();

session_start();
session_write_close();

$controller = new GameController();
$lastState = null;

while (true) {
    if (connection_aborted()) {
        break;
    }

    // Get current game state
    $currentState = $controller->getGameState();
    
    // Check if state has changed
    if (json_encode($currentState) !== json_encode($lastState)) {
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
    
    if ($game && $game['status'] === 'active' && $game['question_start_time'] > 0 && $game['show_answer'] == 0) {
        $timeElapsed = time() - $game['question_start_time'];
        
        // Send timer update every second
        echo "data: " . json_encode([
            'type' => 'timer_update',
            'data' => ['time_remaining' => max(0, 60 - $timeElapsed)],
            'timestamp' => time()
        ]) . "\n\n";
        flush();

        if ($timeElapsed >= 60) { // 60 seconds per question
            $controller->timeUp($game['id']);
        }
    }
    
    sleep(1); // Check every second
}
?>
