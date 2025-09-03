<?php
require_once 'app/config/Database.php';
require_once 'app/models/Signal.php';

echo "=== FOREIGN KEY CONSTRAINT REPAIR TOOL ===\n";

$db = Database::getInstance();
$signal = new Signal();

try {
    echo "1. Checking database state...\n";
    
    // Get current counts
    $stmt = $db->query('SELECT COUNT(*) as count FROM rooms');
    $roomCount = $stmt->fetch()['count'];
    
    $stmt = $db->query('SELECT COUNT(*) as count FROM peers');
    $peerCount = $stmt->fetch()['count'];
    
    $stmt = $db->query('SELECT COUNT(*) as count FROM signaling_messages');
    $messageCount = $stmt->fetch()['count'];
    
    echo "   - Rooms: $roomCount\n";
    echo "   - Peers: $peerCount\n";
    echo "   - Messages: $messageCount\n";
    
    echo "\n2. Disabling foreign key constraints...\n";
    $db->query("PRAGMA foreign_keys = OFF");
    
    echo "3. Cleaning up orphaned signaling messages...\n";
    
    // Find and delete orphaned signaling messages (messages referencing non-existent peers)
    $sql = "DELETE FROM signaling_messages WHERE 
            from_peer_id NOT IN (SELECT peer_id FROM peers) 
            OR to_peer_id NOT IN (SELECT peer_id FROM peers)";
    $stmt = $db->query($sql);
    $orphanedMessages = $stmt->rowCount();
    echo "   - Removed $orphanedMessages orphaned messages\n";
    
    echo "4. Cleaning up orphaned peers (peers in non-existent rooms)...\n";
    
    // Find and delete peers in non-existent rooms
    $sql = "DELETE FROM peers WHERE room_id NOT IN (SELECT room_id FROM rooms)";
    $stmt = $db->query($sql);
    $orphanedPeers = $stmt->rowCount();
    echo "   - Removed $orphanedPeers orphaned peers\n";
    
    echo "5. Running aggressive cleanup for inactive peers...\n";
    
    // Clean up inactive peers and their related messages
    $inactiveCount = $signal->cleanupInactivePeers(0.1); // Very aggressive - 6 seconds
    echo "   - Removed $inactiveCount inactive peers\n";
    
    echo "6. Cleaning up empty rooms...\n";
    
    $emptyRoomsCount = $signal->cleanupEmptyRooms();
    echo "   - Removed $emptyRoomsCount empty rooms\n";
    
    echo "7. Re-enabling foreign key constraints...\n";
    $db->query("PRAGMA foreign_keys = ON");
    
    echo "8. Verifying database integrity...\n";
    
    // Check for any remaining constraint violations
    $stmt = $db->query("PRAGMA foreign_key_check");
    $violations = $stmt->fetchAll();
    
    if (empty($violations)) {
        echo "   ✅ No foreign key constraint violations found!\n";
    } else {
        echo "   ❌ Still have constraint violations:\n";
        foreach ($violations as $violation) {
            echo "      - Table: {$violation['table']}, Row: {$violation['rowid']}, Parent: {$violation['parent']}\n";
        }
    }
    
    // Get final counts
    $stmt = $db->query('SELECT COUNT(*) as count FROM rooms');
    $finalRoomCount = $stmt->fetch()['count'];
    
    $stmt = $db->query('SELECT COUNT(*) as count FROM peers');
    $finalPeerCount = $stmt->fetch()['count'];
    
    $stmt = $db->query('SELECT COUNT(*) as count FROM signaling_messages');
    $finalMessageCount = $stmt->fetch()['count'];
    
    echo "\n9. Final database state:\n";
    echo "   - Rooms: $finalRoomCount (was $roomCount)\n";
    echo "   - Peers: $finalPeerCount (was $peerCount)\n";
    echo "   - Messages: $finalMessageCount (was $messageCount)\n";
    
    echo "\n=== REPAIR COMPLETED SUCCESSFULLY ===\n";
    echo "You should now be able to rejoin rooms without foreign key constraint errors.\n";
    echo "\nTo test: Go to http://localhost/eng7 and try joining a room.\n";
    
} catch (Exception $e) {
    echo "❌ Error during repair: " . $e->getMessage() . "\n";
    echo "Re-enabling foreign keys...\n";
    $db->query("PRAGMA foreign_keys = ON");
    echo "Please check the database manually or reset it using setup_db.php\n";
}
?>