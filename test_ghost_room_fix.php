<?php
require_once 'app/config/Database.php';
require_once 'app/models/Signal.php';

$signal = new Signal();

echo "=== SIMULATING GHOST ROOM SCENARIO ===\n";

// Step 1: Create a room and add a user (simulating room creation)
$roomId = '999_' . time();
$roomName = 'Test Room 999';
$peerId = 'test_creator_' . time();

echo "1. Creating room: $roomId\n";
$signal->createRoom($roomId, $roomName);

echo "2. Adding user to room...\n";
$signal->joinRoom($roomId, $peerId, 'TestCreator');

echo "3. Checking initial state...\n";
$visibleRooms = $signal->getAllRooms();
echo "   Visible rooms: " . count($visibleRooms) . "\n";
foreach ($visibleRooms as $room) {
    $peers = $signal->getRoomPeers($room['room_id']);
    echo "   - " . $room['room_id'] . " (" . $room['name'] . ") - " . count($peers) . " participants\n";
}

// Step 2: Simulate user disconnecting without clicking "Leave" 
// (Stop updating last_seen, simulating SSE connection loss)
echo "\n4. Simulating user disconnect (setting old last_seen)...\n";
$db = Database::getInstance();
$sql = "UPDATE peers SET last_seen = datetime('now', '-5 minutes') WHERE peer_id = ?";
$db->query($sql, [$peerId]);

echo "5. Checking room visibility after disconnect...\n";
$visibleRooms = $signal->getAllRooms();
echo "   Visible rooms: " . count($visibleRooms) . "\n";
foreach ($visibleRooms as $room) {
    $peers = $signal->getRoomPeers($room['room_id']);
    echo "   - " . $room['room_id'] . " (" . $room['name'] . ") - " . count($peers) . " participants\n";
}

// Step 3: Run cleanup to remove inactive peers
echo "\n6. Running cleanup to remove inactive peers...\n";
$deletedPeers = $signal->cleanupInactivePeers(2); // 2-minute threshold
echo "   Deleted $deletedPeers inactive peers\n";

$deletedRooms = $signal->cleanupEmptyRooms();
echo "   Deleted $deletedRooms empty rooms\n";

// Step 4: Check final state
echo "\n7. Checking final state after cleanup...\n";
$visibleRooms = $signal->getAllRooms();
echo "   Visible rooms: " . count($visibleRooms) . "\n";

if (count($visibleRooms) == 0) {
    echo "   ✅ SUCCESS: Ghost room was properly cleaned up!\n";
} else {
    echo "   ❌ ISSUE: Room is still visible:\n";
    foreach ($visibleRooms as $room) {
        $peers = $signal->getRoomPeers($room['room_id']);
        echo "   - " . $room['room_id'] . " (" . $room['name'] . ") - " . count($peers) . " participants\n";
    }
}

// Clean up any remaining test data
$db->query("DELETE FROM peers WHERE peer_id = ?", [$peerId]);
$db->query("DELETE FROM signaling_messages WHERE room_id = ?", [$roomId]);
$db->query("DELETE FROM rooms WHERE room_id = ?", [$roomId]);

echo "\n=== SIMULATION COMPLETE ===\n";
?>