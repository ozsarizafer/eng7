<?php

require_once 'app/config/Database.php';

echo "<h2>🚀 WebRTC SQLite Database Setup</h2>";

try {
    // Initialize database (this will create the database file and tables)
    $db = Database::getInstance();
    
    echo "<p>✅ <strong>Database connection successful!</strong></p>";
    
    // Check if tables were created
    $conn = $db->getConnection();
    $tables = $conn->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
    
    echo "<h3>📋 Created Tables:</h3><ul>";
    foreach ($tables as $table) {
        echo "<li>✅ " . $table['name'] . "</li>";
    }
    echo "</ul>";
    
    // Check if default room exists
    $stmt = $conn->query("SELECT * FROM rooms WHERE room_id = 'default'");
    $defaultRoom = $stmt->fetch();
    
    if ($defaultRoom) {
        echo "<p>✅ <strong>Default room created:</strong> " . $defaultRoom['name'] . "</p>";
    }
    
    // Test database operations
    echo "<h3>🧪 Testing Database Operations:</h3>";
    
    // Test creating a room
    $testRoomId = 'test_' . time();
    $stmt = $conn->prepare("INSERT INTO rooms (room_id, name) VALUES (?, ?)");
    $result = $stmt->execute([$testRoomId, 'Test Room']);
    
    if ($result) {
        echo "<p>✅ Room creation test: SUCCESS</p>";
        
        // Test adding a peer
        $testPeerId = 'peer_test_' . time();
        $stmt = $conn->prepare("INSERT INTO peers (peer_id, room_id, username) VALUES (?, ?, ?)");
        $result = $stmt->execute([$testPeerId, $testRoomId, 'Test User']);
        
        if ($result) {
            echo "<p>✅ Peer creation test: SUCCESS</p>";
            
            // Test signaling message
            $stmt = $conn->prepare("INSERT INTO signaling_messages (from_peer_id, to_peer_id, room_id, message_type, message_data) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([$testPeerId, $testPeerId, $testRoomId, 'test', '{"test": true}']);
            
            if ($result) {
                echo "<p>✅ Signaling message test: SUCCESS</p>";
            } else {
                echo "<p>❌ Signaling message test: FAILED</p>";
            }
        } else {
            echo "<p>❌ Peer creation test: FAILED</p>";
        }
    } else {
        echo "<p>❌ Room creation test: FAILED</p>";
    }
    
    // Clean up test data
    $conn->prepare("DELETE FROM signaling_messages WHERE room_id = ?")->execute([$testRoomId]);
    $conn->prepare("DELETE FROM peers WHERE room_id = ?")->execute([$testRoomId]);
    $conn->prepare("DELETE FROM rooms WHERE room_id = ?")->execute([$testRoomId]);
    
    echo "<p>✅ <strong>Test data cleaned up</strong></p>";
    
    // Display database file location
    $dbPath = realpath(__DIR__ . '/data/webrtc.db');
    echo "<h3>📍 Database File Location:</h3>";
    echo "<p><code>" . $dbPath . "</code></p>";
    
    // Display file size
    if (file_exists($dbPath)) {
        $fileSize = filesize($dbPath);
        echo "<p><strong>Database file size:</strong> " . number_format($fileSize) . " bytes</p>";
    }
    
    echo "<h3>🎯 Next Steps:</h3>";
    echo "<ul>";
    echo "<li>✅ Database is ready!</li>";
    echo "<li>📝 Open <a href='public/index.html' target='_blank'>public/index.html</a> to test the WebRTC application</li>";
    echo "<li>🔧 Check <a href='public/api.php?action=peers&roomId=default' target='_blank'>API endpoint</a> (should return empty peers list)</li>";
    echo "<li>🎥 Start video calling between multiple browser tabs/windows</li>";
    echo "</ul>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;'>";
    echo "<h4 style='color: #155724; margin: 0 0 10px 0;'>🎉 Setup Complete!</h4>";
    echo "<p style='color: #155724; margin: 0;'>Your WebRTC application with SQLite signaling is ready to use!</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #f5c6cb;'>";
    echo "<h4 style='color: #721c24; margin: 0 0 10px 0;'>❌ Setup Failed</h4>";
    echo "<p style='color: #721c24; margin: 0;'>Error: " . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<style>
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: #f8f9fa; }
h2, h3 { color: #333; }
p { margin: 10px 0; }
ul { margin: 10px 0; }
li { margin: 5px 0; }
code { background: #f1f3f4; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
a { color: #007bff; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>";