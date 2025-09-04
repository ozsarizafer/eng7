<?php
require_once 'app/config/Database.php';

$db = Database::getInstance();
$stmt = $db->query('SELECT peer_id, room_id, username FROM peers');
$peers = $stmt->fetchAll();

echo "Existing peers:\n";
print_r($peers);
?>