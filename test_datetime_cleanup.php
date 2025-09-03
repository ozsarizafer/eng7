<?php
require_once 'app/config/Database.php';

$db = Database::getInstance();

echo "=== TESTING DATETIME COMPARISON ===\n";

// Add a test peer with old timestamp
$testPeerId = 'datetime_test_' . time();
$sql = "INSERT INTO peers (peer_id, room_id, username, last_seen, is_connected) 
        VALUES (?, 'default', 'DateTimeUser', datetime('now', '-10 minutes'), 1)";
$db->query($sql, [$testPeerId]);

echo "Added test peer: $testPeerId with 10-minute old timestamp\n";

// Test the new datetime-based WHERE clause
$minutesThreshold = 5;
echo "Testing datetime WHERE clause with threshold: $minutesThreshold minutes\n";

$sql = "SELECT peer_id, last_seen,
               datetime('now') as current_time,
               datetime('now', '-' || ? || ' minutes') as threshold_time
        FROM peers 
        WHERE peer_id = ?";
$stmt = $db->query($sql, [$minutesThreshold, $testPeerId]);
$result = $stmt->fetch();

if ($result) {
    echo "Peer details:\n";
    echo "- Last seen: " . $result['last_seen'] . "\n";
    echo "- Current time: " . $result['current_time'] . "\n";
    echo "- Threshold time: " . $result['threshold_time'] . "\n";
    echo "- Should be deleted: " . ($result['last_seen'] < $result['threshold_time'] ? 'YES' : 'NO') . "\n";
}

// Test the actual WHERE clause
$sql = "SELECT peer_id FROM peers 
        WHERE peer_id = ? AND last_seen < datetime('now', '-' || ? || ' minutes')";
$stmt = $db->query($sql, [$testPeerId, $minutesThreshold]);
$matchResult = $stmt->fetch();

if ($matchResult) {
    echo "✅ Peer matches datetime WHERE clause - should be deleted\n";
} else {
    echo "❌ Peer does NOT match datetime WHERE clause\n";
}

// Now test the improved cleanup function
echo "\nTesting improved cleanup function...\n";
require_once 'app/models/Signal.php';
$signal = new Signal();

try {
    $deletedCount = $signal->cleanupInactivePeers($minutesThreshold);
    echo "Cleanup function returned: $deletedCount peers deleted\n";
    
    if ($deletedCount > 0) {
        echo "✅ Cleanup function worked!\n";
    } else {
        echo "❌ Cleanup function still not working\n";
        // Clean up manually
        $db->query("DELETE FROM peers WHERE peer_id = ?", [$testPeerId]);
    }
} catch (Exception $e) {
    echo "Cleanup function threw exception: " . $e->getMessage() . "\n";
    // Clean up manually
    $db->query("DELETE FROM peers WHERE peer_id = ?", [$testPeerId]);
}

echo "\n=== TEST COMPLETE ===\n";
?>