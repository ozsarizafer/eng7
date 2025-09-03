<?php
require_once 'app/config/Database.php';
require_once 'app/models/Signal.php';

$signal = new Signal();

echo "=== TESTING CLEANUP FUNCTION ===\n";

// First, let's add a test peer that should be cleaned up
$db = Database::getInstance();

// Add a test peer with old timestamp
$testPeerId = 'test_inactive_peer_' . time();
$sql = "INSERT INTO peers (peer_id, room_id, username, last_seen, is_connected) 
        VALUES (?, 'default', 'TestUser', datetime('now', '-10 minutes'), 1)";
$db->query($sql, [$testPeerId]);

echo "Added test peer: $testPeerId with 10-minute old timestamp\n";

// Check that it was added
$stmt = $db->query("SELECT peer_id, (julianday('now') - julianday(last_seen)) * 24 * 60 as minutes_diff 
                    FROM peers WHERE peer_id = ?", [$testPeerId]);
$testPeer = $stmt->fetch();
if ($testPeer) {
    echo "Test peer age: " . round($testPeer['minutes_diff'], 2) . " minutes\n";
}

// Now test the cleanup function
echo "\nTesting cleanup function with 5-minute threshold...\n";
try {
    $deletedCount = $signal->cleanupInactivePeers(5);
    echo "Cleanup function returned: $deletedCount peers deleted\n";
} catch (Exception $e) {
    echo "Cleanup function threw exception: " . $e->getMessage() . "\n";
}

// Check if the test peer was deleted
$stmt = $db->query("SELECT COUNT(*) as count FROM peers WHERE peer_id = ?", [$testPeerId]);
$result = $stmt->fetch();
if ($result['count'] == 0) {
    echo "✅ Test peer was successfully deleted\n";
} else {
    echo "❌ Test peer was NOT deleted\n";
    
    // Try manual deletion to clean up
    echo "Manually cleaning up test peer...\n";
    $db->query("DELETE FROM peers WHERE peer_id = ?", [$testPeerId]);
}

echo "\n=== TEST COMPLETE ===\n";
?>