-- WebRTC Signaling Database Schema for SQLite
-- This database handles peer signaling and room management

-- Rooms table for managing WebRTC sessions
CREATE TABLE IF NOT EXISTS rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT 1
);

-- Peers table for managing connected users
CREATE TABLE IF NOT EXISTS peers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    peer_id TEXT UNIQUE NOT NULL,
    room_id TEXT NOT NULL,
    username TEXT,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_connected BOOLEAN DEFAULT 1,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
);

-- Signaling messages table for WebRTC communication
CREATE TABLE IF NOT EXISTS signaling_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_peer_id TEXT NOT NULL,
    to_peer_id TEXT,
    room_id TEXT NOT NULL,
    message_type TEXT NOT NULL, -- 'offer', 'answer', 'ice-candidate', 'join', 'leave'
    message_data TEXT NOT NULL, -- JSON data
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed BOOLEAN DEFAULT 0,
    FOREIGN KEY (from_peer_id) REFERENCES peers(peer_id),
    FOREIGN KEY (to_peer_id) REFERENCES peers(peer_id),
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_rooms_room_id ON rooms(room_id);
CREATE INDEX IF NOT EXISTS idx_peers_peer_id ON peers(peer_id);
CREATE INDEX IF NOT EXISTS idx_peers_room_id ON peers(room_id);
CREATE INDEX IF NOT EXISTS idx_signaling_room_id ON signaling_messages(room_id);
CREATE INDEX IF NOT EXISTS idx_signaling_to_peer ON signaling_messages(to_peer_id);
CREATE INDEX IF NOT EXISTS idx_signaling_processed ON signaling_messages(processed);

-- Insert a default room for testing
INSERT OR IGNORE INTO rooms (room_id, name) VALUES ('default', 'Default Room');