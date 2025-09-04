<?php
// Test script to simulate the competition API call with existing room

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>🧪 Competition API Test (Fixed)</h2>";

// Use an existing room ID
$testData = [
    'roomId' => '718_1757023070',  // Use existing room
    'peerId' => 'peer_' . uniqid(),
    'selectedTeam' => 'A'
];

echo "<h3>📝 Test Data:</h3>";
echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";

require_once __DIR__ . '/app/models/Signal.php';
require_once __DIR__ . '/app/config/Database.php';

try {
    echo "<h3>🔧 Testing Signal model methods:</h3>";
    
    $signal = new Signal();
    
    // Test createCompetitionGame
    echo "<p>1. Testing createCompetitionGame...</p>";
    $gameId = $signal->createCompetitionGame($testData['roomId']);
    echo "<p>✅ Game created with ID: $gameId</p>";
    
    // Test assignPlayerToTeam
    echo "<p>2. Testing assignPlayerToTeam...</p>";
    $signal->assignPlayerToTeam($gameId, $testData['peerId'], $testData['selectedTeam']);
    echo "<p>✅ Player assigned to team</p>";
    
    // Test getTeamAssignments
    echo "<p>3. Testing getTeamAssignments...</p>";
    $teams = $signal->getTeamAssignments($gameId);
    echo "<p>✅ Teams retrieved:</p>";
    echo "<pre>" . json_encode($teams, JSON_PRETTY_PRINT) . "</pre>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724;'>";
    echo "<strong>🎉 All tests passed! The competition API methods are working correctly.</strong>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>Exception:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "</div>";
    
    // Print stack trace
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
} catch (Error $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>Error:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "</div>";
    
    // Print stack trace
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>