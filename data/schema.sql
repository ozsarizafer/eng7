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

-- Competition games table for managing quiz competitions
CREATE TABLE IF NOT EXISTS competition_games (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    game_state TEXT DEFAULT 'waiting', -- 'waiting', 'ready_check', 'playing', 'finished'
    current_question_index INTEGER DEFAULT 0,
    current_team TEXT DEFAULT 'A', -- 'A' or 'B'
    team_a_score INTEGER DEFAULT 0,
    team_b_score INTEGER DEFAULT 0,
    question_start_time DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
);

-- Team assignments table
CREATE TABLE IF NOT EXISTS team_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id INTEGER NOT NULL,
    peer_id TEXT NOT NULL,
    team TEXT NOT NULL, -- 'A' or 'B'
    is_ready BOOLEAN DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES competition_games(id) ON DELETE CASCADE,
    FOREIGN KEY (peer_id) REFERENCES peers(peer_id) ON DELETE CASCADE
);

-- Question answers table for tracking responses
CREATE TABLE IF NOT EXISTS question_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id INTEGER NOT NULL,
    question_index INTEGER NOT NULL,
    team TEXT NOT NULL, -- 'A' or 'B'
    selected_answer TEXT,
    is_correct BOOLEAN DEFAULT 0,
    answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES competition_games(id) ON DELETE CASCADE
);

-- Create indexes for competition tables
CREATE INDEX IF NOT EXISTS idx_competition_games_room_id ON competition_games(room_id);
CREATE INDEX IF NOT EXISTS idx_team_assignments_game_id ON team_assignments(game_id);
CREATE INDEX IF NOT EXISTS idx_team_assignments_peer_id ON team_assignments(peer_id);
CREATE INDEX IF NOT EXISTS idx_question_answers_game_id ON question_answers(game_id);

-- Insert a default room for testing
INSERT OR IGNORE INTO rooms (room_id, name) VALUES ('default', 'Default Room');