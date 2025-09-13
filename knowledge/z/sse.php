<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
ob_end_flush();

$db = new PDO("sqlite:db.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$questions = json_decode(file_get_contents("questions.json"), true);
$total_questions = 14;

while (true) {
    $state = $db->query("SELECT * FROM game_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);

    // Oyun başlamamışsa
    if ($state['status'] === "waiting") {
        $countA = $db->query("SELECT COUNT(*) FROM teams WHERE team='A'")->fetchColumn();
        $countB = $db->query("SELECT COUNT(*) FROM teams WHERE team='B'")->fetchColumn();

        if ($countA >= 2 && $countB >= 2) {
            // Oyun başlasın
            $db->exec("UPDATE game_state SET status='playing', current_question=0, question_start=" . time() . " WHERE id=1");
            $state['status'] = "playing";
            $state['current_question'] = 0;
            $state['question_start'] = time();
        }
    }

    // Oyun oynanıyorsa
    if ($state['status'] === "playing") {
        $elapsed = time() - $state['question_start'];

        if ($elapsed >= 37) { 
            // 30 sn cevap + 7 sn bekleme bitti, sıradaki soruya geç
            $nextQ = $state['current_question'] + 1;

            if ($nextQ >= $total_questions) {
                // Oyun bitti
                $db->exec("UPDATE game_state SET status='finished' WHERE id=1");
                $state['status'] = "finished";
            } else {
                // Sıradaki takım (A ↔ B dönüşümlü)
                $nextTeam = ($state['current_team'] === 'A') ? 'B' : 'A';
                $db->exec("UPDATE game_state SET current_question=$nextQ, current_team='$nextTeam', question_start=" . time() . " WHERE id=1");
                $state['current_question'] = $nextQ;
                $state['current_team'] = $nextTeam;
                $state['question_start'] = time();
            }
        }
    }

    // Veriyi client’e gönder
    $state = $db->query("SELECT * FROM game_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    echo "data: " . json_encode($state) . "\n\n";
    ob_flush();
    flush();
    sleep(1);
}
