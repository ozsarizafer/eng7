<?php
require_once 'app/config/Database.php';

$db = Database::getInstance();

echo "=== DETAILED TIME ANALYSIS ===\n";

// Check current time in different formats
echo "Current SQLite time: ";
$stmt = $db->query("SELECT datetime('now') as current_time");
$result = $stmt->fetch();
echo $result['current_time'] . "\n";

echo "Current SQLite julianday: ";
$stmt = $db->query("SELECT julianday('now') as current_julian");
$result = $stmt->fetch();
echo $result['current_julian'] . "\n";

// Check each peer's time details
$stmt = $db->query("SELECT peer_id, room_id, username, last_seen, is_connected,
                           julianday('now') as current_julian,
                           julianday(last_seen) as peer_julian,
                           (julianday('now') - julianday(last_seen)) * 24 * 60 as minutes_diff
                    FROM peers ORDER BY last_seen DESC");
$peers = $stmt->fetchAll();

echo "\nPeer time analysis:\n";
foreach ($peers as $peer) {
    echo "- Peer: " . $peer['peer_id'] . "\n";
    echo "  Room: " . $peer['room_id'] . "\n";
    echo "  Username: " . $peer['username'] . "\n";
    echo "  Last seen: " . $peer['last_seen'] . "\n";
    echo "  Connected: " . ($peer['is_connected'] ? 'Yes' : 'No') . "\n";
    echo "  Minutes difference: " . round($peer['minutes_diff'], 2) . " minutes\n";
    echo "  Should be cleaned (>1 min): " . ($peer['minutes_diff'] > 1 ? 'YES' : 'NO') . "\n";
    echo "  Should be cleaned (>2 min): " . ($peer['minutes_diff'] > 2 ? 'YES' : 'NO') . "\n";
    echo "\n";
}

echo "=== END ANALYSIS ===\n";
?>