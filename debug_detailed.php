<?php
// More detailed debug script

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>🔍 Detailed Competition API Debug</h2>";

// Test 1: Check if we can read raw input
echo "<h3>📝 Raw Input Test:</h3>";
$rawInput = file_get_contents('php://input');
echo "<p>Raw input length: " . strlen($rawInput) . " bytes</p>";
echo "<p>Raw input content: '" . htmlspecialchars($rawInput) . "'</p>";

// Test 2: Try to decode JSON
echo "<h3>🔄 JSON Decode Test:</h3>";
$parsedData = json_decode($rawInput, true);
echo "<p>JSON decode result: " . (is_array($parsedData) ? 'Array' : 'Not an array') . "</p>";
if (is_array($parsedData)) {
    echo "<pre>" . json_encode($parsedData, JSON_PRETTY_PRINT) . "</pre>";
} else {
    echo "<p>JSON error: " . json_last_error_msg() . "</p>";
}

// Test 3: Check POST data
echo "<h3>📬 POST Data Test:</h3>";
echo "<pre>" . json_encode($_POST, JSON_PRETTY_PRINT) . "</pre>";

// Test 4: Check headers
echo "<h3>📡 Headers Test:</h3>";
echo "<pre>";
foreach (getallheaders() as $name => $value) {
    echo "$name: $value\n";
}
echo "</pre>";

// Test 5: Try to manually test the controller method
echo "<h3>🧪 Manual Method Test:</h3>";

require_once __DIR__ . '/app/models/Signal.php';
require_once __DIR__ . '/app/config/Database.php';

try {
    echo "<p>Creating Signal model...</p>";
    $signal = new Signal();
    echo "<p>✅ Signal model created successfully</p>";
    
    // Test database connection
    echo "<p>Testing database connection...</p>";
    $db = Database::getInstance();
    echo "<p>✅ Database connection successful</p>";
    
    // Test a simple query
    echo "<p>Testing simple query...</p>";
    $stmt = $db->query("SELECT COUNT(*) as count FROM rooms");
    $result = $stmt->fetch();
    echo "<p>✅ Simple query successful. Rooms count: " . $result['count'] . "</p>";
    
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