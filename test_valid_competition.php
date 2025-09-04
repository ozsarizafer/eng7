<?php
// Test creating a competition with valid data

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>🧪 Testing Valid Competition Creation</h2>";

require_once __DIR__ . '/app/models/Signal.php';
require_once __DIR__ . '/app/config/Database.php';

try {
    $signal = new Signal();
    
    // Use existing room and peer
    $roomId = '718_1757023070';
    $peerId = 'peer_l9b9fqv92_1757024626932';
    $selectedTeam = 'B';
    
    echo "<p>Using Room ID: $roomId</p>";
    echo "<p>Using Peer ID: $peerId</p>";
    echo "<p>Selected Team: $selectedTeam</p>";
    
    // Check if peer exists in room
    echo "<p>Checking if peer exists in room...</p>";
    $roomPeers = $signal->getRoomPeers($roomId);
    $peerExists = false;
    foreach ($roomPeers as $peer) {
        if ($peer['peer_id'] === $peerId) {
            $peerExists = true;
            echo "<p style='color: green;'>✅ Found peer: " . $peer['username'] . "</p>";
            break;
        }
    }
    
    if ($peerExists) {
        echo "<p style='color: green;'>✅ Peer exists in room - proceeding with competition creation</p>";
        
        // Get or create competition game for this room
        echo "<p>Getting or creating competition game...</p>";
        $existingGame = $signal->getCompetitionGame($roomId);
        
        if (!$existingGame || $existingGame['game_state'] === 'finished') {
            // Create new competition game
            $gameId = $signal->createCompetitionGame($roomId);
            echo "<p style='color: green;'>✅ Created new competition game with ID: $gameId</p>";
        } else {
            $gameId = $existingGame['id'];
            echo "<p style='color: green;'>✅ Using existing competition game with ID: $gameId</p>";
        }
        
        // Add this player to selected team
        echo "<p>Assigning player to team...</p>";
        $signal->assignPlayerToTeam($gameId, $peerId, $selectedTeam);
        echo "<p style='color: green;'>✅ Player assigned to team successfully</p>";
        
        // Get current team assignments
        echo "<p>Retrieving team assignments...</p>";
        $teams = $signal->getTeamAssignments($gameId);
        echo "<p style='color: green;'>✅ Teams retrieved successfully:</p>";
        echo "<pre>" . json_encode($teams, JSON_PRETTY_PRINT) . "</pre>";
        
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724; margin-top: 20px;'>";
        echo "<strong>🎉 Valid competition creation test passed!</strong><br>";
        echo "The competition system is working correctly with valid data.";
        echo "</div>";
    } else {
        echo "<p style='color: red;'>❌ Peer does not exist in room</p>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>Exception:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "</div>";
} catch (Error $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>Error:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "</div>";
}
?>