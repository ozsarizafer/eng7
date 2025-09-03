<?php
require_once 'app/config/Database.php';
require_once 'app/models/Signal.php';

$signal = new Signal();

echo "=== MANUAL CLEANUP ===\n";

// Clean up inactive peers (more aggressive - 1 minute threshold)
$inactiveCount = $signal->cleanupInactivePeers(1);
echo "Cleaned up $inactiveCount inactive peers (>1 minute old)\n";

// Clean up empty rooms
$emptyRoomsCount = $signal->cleanupEmptyRooms();
echo "Cleaned up $emptyRoomsCount empty rooms\n";

// Check state after cleanup
$db = Database::getInstance();
$stmt = $db->query('SELECT COUNT(*) as count FROM rooms');
$roomCount = $stmt->fetch()['count'];

$stmt = $db->query('SELECT COUNT(*) as count FROM peers');
$peerCount = $stmt->fetch()['count'];

$visibleRooms = $signal->getAllRooms();
$visibleCount = count($visibleRooms);

echo "\nAfter cleanup:\n";
echo "- Total rooms in DB: $roomCount\n";
echo "- Total peers in DB: $peerCount\n";
echo "- Visible rooms: $visibleCount\n";

foreach ($visibleRooms as $room) {
    $roomPeers = $signal->getRoomPeers($room['room_id']);
    echo "  - " . $room['room_id'] . " (" . $room['name'] . ") - " . count($roomPeers) . " participants\n";
}

echo "\n=== CLEANUP COMPLETE ===\n";
?>