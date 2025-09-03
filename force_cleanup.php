<?php
require_once 'app/config/Database.php';

$db = Database::getInstance();

echo "=== FORCE CLEANUP INACTIVE PEER ===\n";

$inactivePeerId = 'peer_eyzfvk3k8_1756856126354';

echo "Manually removing inactive peer: $inactivePeerId\n";

try {
    // Disable foreign keys
    $db->query("PRAGMA foreign_keys = OFF");
    
    // Delete signaling messages for this peer
    $sql = "DELETE FROM signaling_messages WHERE from_peer_id = ? OR to_peer_id = ?";
    $stmt = $db->query($sql, [$inactivePeerId, $inactivePeerId]);
    $messagesDeleted = $stmt->rowCount();
    echo "Deleted $messagesDeleted signaling messages for this peer\n";
    
    // Delete the peer
    $sql = "DELETE FROM peers WHERE peer_id = ?";
    $stmt = $db->query($sql, [$inactivePeerId]);
    $peersDeleted = $stmt->rowCount();
    echo "Deleted $peersDeleted peer(s)\n";
    
    // Re-enable foreign keys
    $db->query("PRAGMA foreign_keys = ON");
    
    // Now check room status
    require_once 'app/models/Signal.php';
    $signal = new Signal();
    
    // Clean empty rooms
    $emptyRoomsCount = $signal->cleanupEmptyRooms();
    echo "Cleaned up $emptyRoomsCount empty rooms\n";
    
    // Check final state
    $visibleRooms = $signal->getAllRooms();
    echo "\nVisible rooms after cleanup: " . count($visibleRooms) . "\n";
    
    foreach ($visibleRooms as $room) {
        $roomPeers = $signal->getRoomPeers($room['room_id']);
        echo "- " . $room['room_id'] . " (" . $room['name'] . ") - " . count($roomPeers) . " participants\n";
    }
    
    if (count($visibleRooms) == 0) {
        echo "✅ SUCCESS: No rooms are showing as active anymore!\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $db->query("PRAGMA foreign_keys = ON");
}

echo "\n=== CLEANUP COMPLETE ===\n";
?>