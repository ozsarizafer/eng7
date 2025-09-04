<?php
// Test script to debug the competition creation error

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/app/controllers/SignalController.php';

echo "<h2>🔍 Competition API Debug Test</h2>";

try {
    // Simulate the exact API call that's failing
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET['action'] = 'create_competition';
    
    // Simulate POST data
    $testData = [
        'roomId' => 'test_room_123',
        'peerId' => 'test_peer_456',
        'selectedTeam' => 'A'
    ];
    
    // Mock the input stream
    $jsonData = json_encode($testData);
    
    echo "<h3>📝 Test Data:</h3>";
    echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";
    
    // Create controller instance
    $controller = new SignalController();
    
    echo "<h3>✅ Controller created successfully</h3>";
    
    // The error would occur in handleRequest() method
    // Let's test if we can reach the create competition handler
    
    echo "<h3>🧪 Testing competition creation...</h3>";
    
    // Direct method call to isolate the issue
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('handleCreateCompetition');
    $method->setAccessible(true);
    
    // Mock file_get_contents for POST data
    function mock_file_get_contents($filename) {
        if ($filename === 'php://input') {
            return json_encode([
                'roomId' => 'test_room_123',
                'peerId' => 'test_peer_456',
                'selectedTeam' => 'A'
            ]);
        }
        return file_get_contents($filename);
    }
    
    echo "<p>Calling handleCreateCompetition directly...</p>";
    
    ob_start();
    $method->invoke($controller);
    $output = ob_get_clean();
    
    echo "<h3>🎯 Method Output:</h3>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
    
} catch (Exception $e) {
    echo "<h3>❌ Error Found:</h3>";
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>Error:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "<strong>Stack Trace:</strong><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
} catch (Error $e) {
    echo "<h3>💥 Fatal Error Found:</h3>";
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>Error:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "</div>";
}

echo "<h3>📊 System Info:</h3>";
echo "<ul>";
echo "<li>PHP Version: " . phpversion() . "</li>";
echo "<li>Current Directory: " . __DIR__ . "</li>";
echo "<li>File Exists - SignalController: " . (file_exists(__DIR__ . '/app/controllers/SignalController.php') ? '✅' : '❌') . "</li>";
echo "<li>File Exists - Signal Model: " . (file_exists(__DIR__ . '/app/models/Signal.php') ? '✅' : '❌') . "</li>";
echo "<li>File Exists - Database: " . (file_exists(__DIR__ . '/app/config/Database.php') ? '✅' : '❌') . "</li>";
echo "</ul>";
?>