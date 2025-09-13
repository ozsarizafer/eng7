DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS game_state;

-- Oyuncular tablosu
CREATE TABLE teams (
    id TEXT PRIMARY KEY,  -- benzersiz oyuncu ID (session id)
    player_name TEXT,
    team TEXT,            -- 'A' veya 'B'
    role TEXT,            -- 'question' veya 'options'
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Oyun durumu tablosu
CREATE TABLE game_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    current_question INTEGER,
    current_team TEXT,
    start_time INTEGER,
    status TEXT,          -- waiting, playing, finished
    score_a INTEGER DEFAULT 0,
    score_b INTEGER DEFAULT 0,
    question_start INTEGER
);

-- Başlangıç kaydı
INSERT INTO game_state (id, current_question, current_team, start_time, status, score_a, score_b, question_start) 
VALUES (1, 0, 'A', 0, 'waiting', 0, 0, 0);
