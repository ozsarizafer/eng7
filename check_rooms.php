<?php
require_once 'app/config/Database.php';

$db = Database::getInstance();
$stmt = $db->query('SELECT room_id, name FROM rooms');
$rooms = $stmt->fetchAll();

echo "Existing rooms:\n";
print_r($rooms);
?>