<?php
// index.php
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Bilgi Yarışması - Takım Seçimi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h1>🏆 Bilgi Yarışması</h1>
    <p>Takımını seç ve oyuna katıl!</p>

    <form action="action.php" method="post">
        <input type="text" name="player_name" placeholder="Adınız" required>
        <select name="team" required>
            <option value="A">Takım A</option>
            <option value="B">Takım B</option>
        </select>
        <button type="submit">Katıl</button>
    </form>

    <hr>

    <h2>Oyuncu Durumu</h2>
    <div id="status">Yükleniyor...</div>

    <hr>

    <!-- 🔄 Reset Butonu -->
    <form action="reset_db.php" method="get">
        <button type="submit" style="background:red;color:white;padding:10px;border:none;">
            🔄 Oyunu Resetle
        </button>
    </form>

    <script>
        // SSE ile oyuncu durumu göster
        const evtSource = new EventSource("sse.php");
        evtSource.onmessage = function(event) {
            const data = JSON.parse(event.data);
            document.getElementById("status").innerHTML = `
                <p>Durum: <b>${data.status}</b></p>
                <p>Takım A Skor: ${data.score_a} | Takım B Skor: ${data.score_b}</p>
                <p>Şu anki takım: ${data.current_team}</p>
            `;
        };
    </script>
</body>
</html>
