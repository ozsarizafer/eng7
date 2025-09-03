<?php
require_once 'app/config/Database.php';

$db = Database::getInstance();

echo "=== DEBUGGING JULIANDAY CALCULATION ===\n";

// Add a test peer with old timestamp
$testPeerId = 'debug_peer_' . time();
$sql = "INSERT INTO peers (peer_id, room_id, username, last_seen, is_connected) 
        VALUES (?, 'default', 'DebugUser', datetime('now', '-10 minutes'), 1)";
$db->query($sql, [$testPeerId]);

echo "Added test peer: $testPeerId\n";

// Check the peer details
$sql = "SELECT peer_id, last_seen, 
               julianday('now') as current_julian,
               julianday(last_seen) as peer_julian,
               (julianday('now') - julianday(last_seen)) as julian_diff,
               (julianday('now') - julianday(last_seen)) * 24 as hours_diff,
               (julianday('now') - julianday(last_seen)) * 24 * 60 as minutes_diff
        FROM peers WHERE peer_id = ?";
$stmt = $db->query($sql, [$testPeerId]);
$peer = $stmt->fetch();

if ($peer) {
    echo "Peer details:\n";
    echo "- Last seen: " . $peer['last_seen'] . "\n";
    echo "- Current julian: " . $peer['current_julian'] . "\n";
    echo "- Peer julian: " . $peer['peer_julian'] . "\n";
    echo "- Julian diff: " . $peer['julian_diff'] . "\n";
    echo "- Hours diff: " . $peer['hours_diff'] . "\n";
    echo "- Minutes diff: " . $peer['minutes_diff'] . "\n";
}

// Test the WHERE clause directly
$minutesThreshold = 5;
echo "\nTesting WHERE clause with threshold: $minutesThreshold minutes\n";

$sql = "SELECT peer_id, 
               (julianday('now') - julianday(last_seen)) * 24 * 60 as minutes_diff
        FROM peers 
        WHERE peer_id = ? AND (julianday('now') - julianday(last_seen)) * 24 * 60 > ?";
$stmt = $db->query($sql, [$testPeerId, $minutesThreshold]);
$result = $stmt->fetch();

if ($result) {
    echo "✅ Peer matches WHERE clause - should be deleted\n";
    echo "- Minutes difference: " . round($result['minutes_diff'], 2) . "\n";
} else {
    echo "❌ Peer does NOT match WHERE clause\n";
}

// Test the actual DELETE query
echo "\nTesting DELETE query...\n";
$sql = "DELETE FROM peers WHERE peer_id = ? AND (julianday('now') - julianday(last_seen)) * 24 * 60 > ?";
$stmt = $db->query($sql, [$testPeerId, $minutesThreshold]);
$deletedCount = $stmt->rowCount();

echo "DELETE query affected $deletedCount rows\n";

if ($deletedCount > 0) {
    echo "✅ DELETE query worked!\n";
} else {
    echo "❌ DELETE query failed\n";
    // Clean up manually
    $db->query("DELETE FROM peers WHERE peer_id = ?", [$testPeerId]);
}

echo "\n=== DEBUG COMPLETE ===\n";
?>