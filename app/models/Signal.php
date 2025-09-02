<?php

require_once __DIR__ . '/../config/Database.php';

class Signal {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function createRoom($roomId, $name) {
        $sql = "INSERT INTO rooms (room_id, name) VALUES (?, ?)";
        return $this->db->query($sql, [$roomId, $name]);
    }

    public function generateUniqueRoomId() {
        do {
            // Generate 3-digit random number (100-999)
            $threeDigit = str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
            
            // Add timestamp-based suffix to ensure uniqueness
            $timestamp = time();
            $roomId = $threeDigit . '_' . $timestamp;
            
            // Check if this room ID already exists
            $sql = "SELECT COUNT(*) as count FROM rooms WHERE room_id = ?";
            $stmt = $this->db->query($sql, [$roomId]);
            $result = $stmt->fetch();
            
        } while ($result['count'] > 0); // Keep generating until we get a unique ID
        
        return $roomId;
    }
    
    public function getAllRooms() {
        // Only return rooms that have active participants
        $sql = "SELECT r.room_id, r.name, r.created_at, r.is_active 
                FROM rooms r 
                WHERE EXISTS (
                    SELECT 1 FROM peers p 
                    WHERE p.room_id = r.room_id AND p.is_connected = 1
                )
                ORDER BY r.created_at DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function getAllRoomsIncludingEmpty() {
        // Return all rooms regardless of participant count (for admin purposes)
        $sql = "SELECT room_id, name, created_at, is_active FROM rooms ORDER BY created_at DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function joinRoom($roomId, $peerId, $username = null) {
        // First, ensure room exists
        $this->ensureRoomExists($roomId);

        // Remove any existing peer connection
        $this->leavePeer($peerId);

        // Add new peer to room
        $sql = "INSERT INTO peers (peer_id, room_id, username) VALUES (?, ?, ?)";
        return $this->db->query($sql, [$peerId, $roomId, $username]);
    }

    public function leavePeer($peerId) {
        $sql = "DELETE FROM peers WHERE peer_id = ?";
        return $this->db->query($sql, [$peerId]);
    }

    public function getRoomPeers($roomId) {
        $sql = "SELECT peer_id, username, joined_at FROM peers WHERE room_id = ? AND is_connected = 1";
        $stmt = $this->db->query($sql, [$roomId]);
        return $stmt->fetchAll();
    }

    public function addSignalingMessage($fromPeerId, $toPeerId, $roomId, $messageType, $messageData) {
        $sql = "INSERT INTO signaling_messages (from_peer_id, to_peer_id, room_id, message_type, message_data) 
                VALUES (?, ?, ?, ?, ?)";
        return $this->db->query($sql, [$fromPeerId, $toPeerId, $roomId, $messageType, json_encode($messageData)]);
    }

    public function getUnprocessedMessages($peerId) {
        $sql = "SELECT id, from_peer_id, message_type, message_data, created_at 
                FROM signaling_messages 
                WHERE to_peer_id = ? AND processed = 0 
                ORDER BY created_at ASC";
        $stmt = $this->db->query($sql, [$peerId]);
        return $stmt->fetchAll();
    }

    public function markMessageAsProcessed($messageId) {
        $sql = "UPDATE signaling_messages SET processed = 1 WHERE id = ?";
        return $this->db->query($sql, [$messageId]);
    }

    public function getRoomMessages($roomId, $limit = 50) {
        $sql = "SELECT from_peer_id, message_type, message_data, created_at 
                FROM signaling_messages 
                WHERE room_id = ? AND processed = 0 
                ORDER BY created_at ASC 
                LIMIT ?";
        $stmt = $this->db->query($sql, [$roomId, $limit]);
        return $stmt->fetchAll();
    }

    public function updatePeerLastSeen($peerId) {
        $sql = "UPDATE peers SET last_seen = CURRENT_TIMESTAMP WHERE peer_id = ?";
        return $this->db->query($sql, [$peerId]);
    }

    public function cleanupOldMessages($hoursOld = 24) {
        $sql = "DELETE FROM signaling_messages WHERE created_at < datetime('now', '-" . intval($hoursOld) . " hours')";
        $stmt = $this->db->query($sql);
        return $stmt->rowCount(); // Return number of deleted rows
    }

    public function cleanupInactivePeers($minutesOld = 30) {
        $sql = "DELETE FROM peers WHERE last_seen < datetime('now', '-" . intval($minutesOld) . " minutes')";
        $stmt = $this->db->query($sql);
        return $stmt->rowCount(); // Return number of deleted rows
    }

    private function ensureRoomExists($roomId) {
        $sql = "SELECT COUNT(*) as count FROM rooms WHERE room_id = ?";
        $stmt = $this->db->query($sql, [$roomId]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            $this->createRoom($roomId, 'Room ' . $roomId);
        }
    }

    public function broadcastToRoom($roomId, $fromPeerId, $messageType, $messageData) {
        $peers = $this->getRoomPeers($roomId);
        $results = [];
        
        foreach ($peers as $peer) {
            if ($peer['peer_id'] !== $fromPeerId) {
                $this->addSignalingMessage($fromPeerId, $peer['peer_id'], $roomId, $messageType, $messageData);
                $results[] = $peer['peer_id'];
            }
        }
        
        return $results;
    }
}