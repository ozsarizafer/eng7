<?php
require_once 'app/config/Database.php';

echo "=== DATABASE UNLOCK UTILITY ===\n";

try {
    $db = Database::getInstance();
    
    echo "1. Checking database lock status...\n";
    $isLocked = $db->isDatabaseLocked();
    
    if ($isLocked) {
        echo "   ❌ Database is currently locked\n";
    } else {
        echo "   ✅ Database is accessible\n";
    }
    
    echo "\n2. Running database optimization and unlock procedures...\n";
    $optimized = $db->optimizeAndUnlock();
    
    if ($optimized) {
        echo "   ✅ Database optimization completed successfully\n";
    } else {
        echo "   ⚠️ Database optimization completed with warnings (check error log)\n";
    }
    
    echo "\n3. Verifying database access...\n";
    
    // Test basic operations
    $stmt = $db->query("SELECT COUNT(*) as room_count FROM rooms");
    $result = $stmt->fetch();
    echo "   ✅ Room count: " . $result['room_count'] . "\n";
    
    $stmt = $db->query("SELECT COUNT(*) as peer_count FROM peers");
    $result = $stmt->fetch();
    echo "   ✅ Active peers: " . $result['peer_count'] . "\n";
    
    $stmt = $db->query("SELECT COUNT(*) as message_count FROM signaling_messages");
    $result = $stmt->fetch();
    echo "   ✅ Signaling messages: " . $result['message_count'] . "\n";
    
    echo "\n4. Running additional cleanup to prevent future locks...\n";
    
    // Clean up old messages that might be causing locks
    $stmt = $db->query("DELETE FROM signaling_messages WHERE created_at < datetime('now', '-1 hour')");
    $deletedMessages = $stmt->rowCount();
    echo "   🧹 Deleted $deletedMessages old signaling messages\n";
    
    // Clean up inactive peers
    $stmt = $db->query("DELETE FROM peers WHERE last_seen < datetime('now', '-5 minutes')");
    $deletedPeers = $stmt->rowCount();
    echo "   🧹 Deleted $deletedPeers inactive peers\n";
    
    echo "\n5. Final lock status check...\n";
    $finalLockStatus = $db->isDatabaseLocked();
    
    if ($finalLockStatus) {
        echo "   ❌ Database is still locked - manual intervention may be required\n";
        echo "\n💡 ADVANCED TROUBLESHOOTING:\n";
        echo "   1. Stop Apache service in XAMPP\n";
        echo "   2. Wait 10 seconds\n";
        echo "   3. Delete any .db-wal and .db-shm files in /data/ folder\n";
        echo "   4. Restart Apache\n";
        echo "   5. Run this script again\n";
    } else {
        echo "   ✅ Database is now unlocked and ready for use\n";
    }
    
    echo "\n=== UNLOCK COMPLETED ===\n";
    echo "You can now try joining rooms again.\n";
    echo "Visit: http://localhost/eng7/public/index.html\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\n🔧 EMERGENCY RECOVERY STEPS:\n";
    echo "1. Stop XAMPP Apache service\n";
    echo "2. Navigate to: " . __DIR__ . "/data/\n";
    echo "3. Delete files: webrtc.db-wal, webrtc.db-shm (if they exist)\n";
    echo "4. Restart Apache\n";
    echo "5. Visit: http://localhost/eng7/setup_db.php\n";
}

// Add some styling for better readability
echo "\n<style>
body { font-family: 'Courier New', monospace; padding: 20px; background: #f0f0f0; }
</style>";
?>