<?php
session_start();
$db = new PDO("sqlite:db.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!isset($_SESSION['player_id'])) {
    header("Location: index.php");
    exit;
}
$player_id = $_SESSION['player_id'];

// Oyuncunun bilgisi
$stmt = $db->prepare("SELECT * FROM teams WHERE id=?");
$stmt->execute([$player_id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Oyun Başladı</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <h1>Takım <?php echo htmlspecialchars($player['team']); ?> - <?php echo htmlspecialchars($player['player_name']); ?></h1>

    <div id="game_area">Yükleniyor...</div>

    <script>
        const evtSource = new EventSource("sse.php");

        evtSource.onmessage = function(event) {
            const state = JSON.parse(event.data);
            $("#game_area").html("");

            if (state.status === "waiting") {
                $("#game_area").html("<p>Oyuncular bekleniyor...</p>");
                return;
            }

            if (state.status === "finished") {
                $("#game_area").html("<h2>🏁 Oyun Bitti</h2><p>Skor A: " + state.score_a + " | Skor B: " + state.score_b + "</p>");
                return;
            }

            // Oyun devam ediyorsa
            fetch("questions.json")
                .then(r => r.json())
                .then(questions => {
                    const q = questions[state.current_question];
                    if (!q) return;

                    let html = "<h2>Soru: " + q.question + "</h2><ul>";
                    q.options.forEach(opt => {
                        html += `<li><button class="answer" data-opt="${opt}">${opt}</button></li>`;
                    });
                    html += "</ul>";

                    html += `<p>Kalan süre: ${30 - (Math.floor(Date.now() / 1000) - state.question_start)} sn</p>`;
                    $("#game_area").html(html);
                });
        };

        $(document).on("click", ".answer", function() {
            const answer = $(this).data("opt");
            $.post("action.php", {answer: answer}, function(res) {
                alert("Cevabın gönderildi!");
            });
        });
    </script>
</body>
</html>
