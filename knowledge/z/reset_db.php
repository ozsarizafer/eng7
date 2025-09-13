<?php
try {
    $db = new PDO("sqlite:db.sqlite");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabloları sil
    $db->exec("DROP TABLE IF EXISTS teams;");
    $db->exec("DROP TABLE IF EXISTS game_state;");

    // teams tablosu
    $db->exec("
        CREATE TABLE teams (
            id TEXT PRIMARY KEY,
            player_name TEXT,
            team TEXT,
            role TEXT,
            joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // game_state tablosu
    $db->exec("
        CREATE TABLE game_state (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            current_question INTEGER,
            current_team TEXT,
            start_time INTEGER,
            status TEXT,
            score_a INTEGER DEFAULT 0,
            score_b INTEGER DEFAULT 0,
            question_start INTEGER
        );
    ");

    // Başlangıç kaydı ekle
    $db->exec("
        INSERT INTO game_state (id, current_question, current_team, start_time, status, score_a, score_b, question_start) 
        VALUES (1, 0, 'A', 0, 'waiting', 0, 0, 0);
    ");

    echo "✅ Veritabanı sıfırlandı ve hazır!";
} catch (Exception $e) {
    echo "❌ Hata: " . $e->getMessage();
}
