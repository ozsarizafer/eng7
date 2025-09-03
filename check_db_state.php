<?php
require_once 'app/config/Database.php';
require_once 'app/models/Signal.php';

$db = Database::getInstance();
$signal = new Signal();

echo "=== CURRENT DATABASE STATE ===\n";

// Check rooms
$stmt = $db->query('SELECT room_id, name, created_at FROM rooms ORDER BY created_at DESC');
$rooms = $stmt->fetchAll();
echo "Total Rooms: " . count($rooms) . "\n";
foreach ($rooms as $room) {
    echo "- Room: " . $room['room_id'] . " (" . $room['name'] . ") - Created: " . $room['created_at'] . "\n";
}

// Check peers
$stmt = $db->query('SELECT peer_id, room_id, username, last_seen, is_connected FROM peers ORDER BY last_seen DESC');
$peers = $stmt->fetchAll();
echo "\nTotal Peers: " . count($peers) . "\n";
foreach ($peers as $peer) {
    echo "- Peer: " . $peer['peer_id'] . " in room " . $peer['room_id'] . " (" . $peer['username'] . ") - Last seen: " . $peer['last_seen'] . " - Connected: " . ($peer['is_connected'] ? 'Yes' : 'No') . "\n";
}

// Check what getAllRooms() returns (this is what the interface shows)
$visibleRooms = $signal->getAllRooms();
echo "\nVisible Rooms (what interface shows): " . count($visibleRooms) . "\n";
foreach ($visibleRooms as $room) {
    echo "- Visible: " . $room['room_id'] . " (" . $room['name'] . ") - Created: " . $room['created_at'] . "\n";
}

echo "\n=== END ===\n";
?>