<?php
require_once 'app/config/Database.php';

$db = Database::getInstance();

echo "=== TESTING CLEANUP QUERY ===\n";

// Test the cleanup query that should delete inactive peers
$minutesOld = 1;

echo "Testing with threshold: $minutesOld minutes\n\n";

// First, show which peers would be affected
$sql = "SELECT peer_id, username, last_seen, 
               (julianday('now') - julianday(last_seen)) * 24 * 60 as minutes_diff
        FROM peers WHERE 
        (julianday('now') - julianday(last_seen)) * 24 * 60 > ?";
        
$stmt = $db->query($sql, [$minutesOld]);
$peersToDelete = $stmt->fetchAll();

echo "Peers that should be deleted (>" . $minutesOld . " minutes old):\n";
foreach ($peersToDelete as $peer) {
    echo "- " . $peer['peer_id'] . " (" . $peer['username'] . ") - " . round($peer['minutes_diff'], 2) . " minutes old\n";
}

if (empty($peersToDelete)) {
    echo "No peers to delete.\n";
} else {
    // Actually delete them
    echo "\nExecuting cleanup...\n";
    
    // Disable foreign keys
    $db->query("PRAGMA foreign_keys = OFF");
    
    // Delete signaling messages first
    $sql = "DELETE FROM signaling_messages WHERE from_peer_id IN (
                SELECT peer_id FROM peers WHERE 
                (julianday('now') - julianday(last_seen)) * 24 * 60 > ?
            ) OR to_peer_id IN (
                SELECT peer_id FROM peers WHERE 
                (julianday('now') - julianday(last_seen)) * 24 * 60 > ?
            )";
    $stmt = $db->query($sql, [$minutesOld, $minutesOld]);
    $messagesDeleted = $stmt->rowCount();
    echo "Deleted $messagesDeleted signaling messages\n";
    
    // Delete the peers
    $sql = "DELETE FROM peers WHERE 
            (julianday('now') - julianday(last_seen)) * 24 * 60 > ?";
    $stmt = $db->query($sql, [$minutesOld]);
    $peersDeleted = $stmt->rowCount();
    echo "Deleted $peersDeleted peers\n";
    
    // Re-enable foreign keys
    $db->query("PRAGMA foreign_keys = ON");
    
    echo "Cleanup completed successfully!\n";
}

echo "\n=== END TEST ===\n";
?>