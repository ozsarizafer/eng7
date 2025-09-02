<?php
/**
 * Database Cleanup Utility
 * Clears all peers and messages from the database
 */

require_once 'app/config/Database.php';

echo "<h2>🧹 Database Cleanup Utility</h2>";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "<h3>Current Database State:</h3>";
    
    // Show current peers
    $stmt = $conn->query("SELECT COUNT(*) as count FROM peers");
    $peerCount = $stmt->fetch()['count'];
    echo "<p>📊 Current peers: <strong>$peerCount</strong></p>";
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM signaling_messages");
    $messageCount = $stmt->fetch()['count'];
    echo "<p>📊 Current messages: <strong>$messageCount</strong></p>";
    
    // Show peers by room
    $stmt = $conn->query("SELECT room_id, COUNT(*) as count FROM peers GROUP BY room_id");
    $roomStats = $stmt->fetchAll();
    
    echo "<h4>Peers by Room:</h4><ul>";
    foreach ($roomStats as $room) {
        echo "<li>Room '{$room['room_id']}': <strong>{$room['count']}</strong> peers</li>";
    }
    echo "</ul>";
    
    // Cleanup operations
    echo "<h3>🧹 Cleanup Operations:</h3>";
    
    // Disable foreign key constraints temporarily
    $conn->exec('PRAGMA foreign_keys = OFF');
    
    // Clear all signaling messages first
    $stmt = $conn->prepare("DELETE FROM signaling_messages");
    $stmt->execute();
    $deletedMessages = $stmt->rowCount();
    echo "<p>✅ Deleted <strong>$deletedMessages</strong> signaling messages</p>";
    
    // Clear all peers
    $stmt = $conn->prepare("DELETE FROM peers");
    $stmt->execute();
    $deletedPeers = $stmt->rowCount();
    echo "<p>✅ Deleted <strong>$deletedPeers</strong> peers</p>";
    
    // Re-enable foreign key constraints
    $conn->exec('PRAGMA foreign_keys = ON');
    
    // Verify cleanup
    $stmt = $conn->query("SELECT COUNT(*) as count FROM peers");
    $remainingPeers = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM signaling_messages");
    $remainingMessages = $stmt->fetch()['count'];
    
    echo "<h3>🎯 Post-Cleanup State:</h3>";
    echo "<p>📊 Remaining peers: <strong>$remainingPeers</strong></p>";
    echo "<p>📊 Remaining messages: <strong>$remainingMessages</strong></p>";
    
    if ($remainingPeers == 0 && $remainingMessages == 0) {
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;'>";
        echo "<h4 style='color: #155724; margin: 0 0 10px 0;'>🎉 Cleanup Complete!</h4>";
        echo "<p style='color: #155724; margin: 0;'>Database has been successfully cleaned. You can now join rooms normally.</p>";
        echo "</div>";
    }
    
    echo "<div style='margin: 20px 0;'>";
    echo "<a href='index.html' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>🎙️ Go to Audio Conference</a>";
    echo "<a href='connection_test.html' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔧 Test Connection</a>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #f5c6cb;'>";
    echo "<h4 style='color: #721c24; margin: 0 0 10px 0;'>❌ Cleanup Failed</h4>";
    echo "<p style='color: #721c24; margin: 0;'>Error: " . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<style>
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: #f8f9fa; }
h2, h3, h4 { color: #333; }
p { margin: 10px 0; }
ul { margin: 10px 0; }
li { margin: 5px 0; }
a { color: #007bff; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>";
?>