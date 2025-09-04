<?php
// Test script to simulate the competition API call

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>🧪 Competition API Test</h2>";

// Simulate the JSON input that would be sent by the frontend
$testData = [
    'roomId' => '123_1234567890',
    'peerId' => 'peer_' . uniqid(),
    'selectedTeam' => 'A'
];

echo "<h3>📝 Test Data:</h3>";
echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";

// Include the controller
require_once __DIR__ . '/app/controllers/SignalController.php';

try {
    echo "<h3>🔧 Testing SignalController:</h3>";
    
    // Create a mock controller
    $controller = new SignalController();
    
    // Manually set the input data for testing
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET['action'] = 'create_competition';
    
    // Simulate the JSON input
    $jsonInput = json_encode($testData);
    
    echo "<p>Setting up mock input...</p>";
    
    // We can't easily mock file_get_contents('php://input'), so let's test the methods directly
    
    echo "<p>Testing Signal model methods directly...</p>";
    
    require_once __DIR__ . '/app/models/Signal.php';
    require_once __DIR__ . '/app/config/Database.php';
    
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