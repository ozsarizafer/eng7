<?php
session_start();
$db = new PDO("sqlite:db.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Oyuncuya benzersiz ID ata
if (!isset($_SESSION['player_id'])) {
    $_SESSION['player_id'] = uniqid("p_");
}
$player_id = $_SESSION['player_id'];

// Takıma katılma
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['team'])) {
    $player_name = trim($_POST['player_name']);
    $team = $_POST['team'];

    // Oyuncuyu ekle (veya güncelle)
    $stmt = $db->prepare("REPLACE INTO teams (id, player_name, team, role) VALUES (?, ?, ?, '')");
    $stmt->execute([$player_id, $player_name, $team]);

    header("Location: game.php");
    exit;
}

// Cevap gönderme
if (isset($_POST['answer'])) {
    $answer = $_POST['answer'];

    // Burada cevap kontrol edilecek
    $state = $db->query("SELECT * FROM game_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $questions = json_decode(file_get_contents("questions.json"), true);
    $qIndex = $state['current_question'];
    $correct = $questions[$qIndex]['correct_answer'];

    if ($answer === $correct) {
        if ($state['current_team'] === 'A') {
            $db->exec("UPDATE game_state SET score_a = score_a + 1 WHERE id=1");
        } else {
            $db->exec("UPDATE game_state SET score_b = score_b + 1 WHERE id=1");
        }
    }

    echo "ok";
    exit;
}
