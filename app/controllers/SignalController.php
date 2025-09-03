<?php

require_once __DIR__ . '/../models/Signal.php';
require_once __DIR__ . '/../config/HttpsRedirect.php';
require_once __DIR__ . '/../config/Database.php';

class SignalController {
    private $signal;

    public function __construct() {
        $this->signal = new Signal();
        
        // Set headers for CORS and JSON response - Allow all origins for local network access
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Origin, X-Requested-With');
        header('Access-Control-Allow-Credentials: false');
        
        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'join':
                    $this->handleJoin();
                    break;
                case 'leave':
                    $this->handleLeave();
                    break;
                case 'signal':
                    $this->handleSignal();
                    break;
                case 'poll':
                    $this->handlePoll();
                    break;
                case 'events':
                    $this->handleServerSentEvents();
                    break;
                case 'peers':
                    $this->handleGetPeers();
                    break;
                case 'cleanup':
                    $this->handleCleanup();
                    break;
                case 'create_room':
                    $this->handleCreateRoom();
                    break;
                case 'list_rooms':
                    $this->handleListRooms();
                    break;
                case 'heartbeat':
                    $this->handleHeartbeat();
                    break;
                case 'stats':
                    $this->handleStats();
                    break;
                case 'unlock':
                    $this->handleDatabaseUnlock();
                    break;
                default:
                    $this->sendError('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("SignalController error in action '$action': " . $e->getMessage());
            $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }

    private function handleJoin() {
        $input = $this->getJsonInput();
        $roomId = $input['roomId'] ?? 'default';
        $peerId = $input['peerId'] ?? '';
        $username = $input['username'] ?? 'Anonymous';

        if (empty($peerId)) {
            $this->sendError('Peer ID is required', 400);
            return;
        }

        try {
            // Clean up inactive peers before checking capacity (more aggressive - 2 minutes)
            try {
                $this->signal->cleanupInactivePeers(2);
            } catch (Exception $cleanupError) {
                error_log("Cleanup failed, continuing with join: " . $cleanupError->getMessage());
                // Don't let cleanup failure prevent joining
            }
            
            // Also clean up old signaling messages
            try {
                $this->signal->cleanupOldMessages(1); // Clean messages older than 1 hour
            } catch (Exception $cleanupError) {
                error_log("Message cleanup failed, continuing with join: " . $cleanupError->getMessage());
                // Don't let cleanup failure prevent joining
            }
            // Remove any existing entry for this peer (rejoin scenario)
            $this->signal->leavePeer($peerId);

            // Check room capacity (max 4 peers) after cleanup
            $currentPeers = $this->signal->getRoomPeers($roomId);
            
            if (count($currentPeers) >= 4) {
                $this->sendError('Room is full. Maximum 4 participants allowed.', 403);
                return;
            }

            $this->signal->joinRoom($roomId, $peerId, $username);
        } catch (Exception $e) {
            error_log("Error in handleJoin: " . $e->getMessage());
            throw $e; // Re-throw to be caught by main exception handler
        }
        
        // Get all current peers for the new joiner
        $allPeers = $this->signal->getRoomPeers($roomId);
        
        // Notify other peers about new joiner
        $this->signal->broadcastToRoom($roomId, $peerId, 'peer-joined', [
            'peerId' => $peerId,
            'username' => $username
        ]);

        $this->sendSuccess([
            'message' => 'Successfully joined room',
            'roomId' => $roomId,
            'peerId' => $peerId,
            'existingPeers' => array_filter($allPeers, function($peer) use ($peerId) {
                return $peer['peer_id'] !== $peerId;
            })
        ]);
    }

    private function handleLeave() {
        $input = $this->getJsonInput();
        $peerId = $input['peerId'] ?? '';
        $roomId = $input['roomId'] ?? 'default';
        $reason = $input['reason'] ?? 'manual';

        if (empty($peerId)) {
            $this->sendError('Peer ID is required', 400);
            return;
        }

        // Log disconnect reason for monitoring
        error_log("Peer disconnect - ID: $peerId, Room: $roomId, Reason: $reason");

        // Notify other peers before leaving
        $this->signal->broadcastToRoom($roomId, $peerId, 'peer-left', [
            'peerId' => $peerId,
            'reason' => $reason
        ]);

        $this->signal->leavePeer($peerId);
        
        // Trigger immediate cleanup for performance
        try {
            $this->signal->cleanupInactivePeers(0.5); // Very aggressive - 30 seconds
            $this->signal->cleanupEmptyRooms();
        } catch (Exception $cleanupError) {
            error_log("Cleanup during leave failed: " . $cleanupError->getMessage());
        }

        $this->sendSuccess([
            'message' => 'Successfully left room',
            'peerId' => $peerId,
            'reason' => $reason
        ]);
    }

    private function handleSignal() {
        $input = $this->getJsonInput();
        $fromPeerId = $input['fromPeerId'] ?? '';
        $toPeerId = $input['toPeerId'] ?? '';
        $roomId = $input['roomId'] ?? 'default';
        $messageType = $input['type'] ?? '';
        $messageData = $input['data'] ?? [];

        if (empty($fromPeerId) || empty($messageType)) {
            $this->sendError('From peer ID and message type are required', 400);
            return;
        }

        // If toPeerId is empty, broadcast to all peers in room
        if (empty($toPeerId)) {
            $sentTo = $this->signal->broadcastToRoom($roomId, $fromPeerId, $messageType, $messageData);
            $this->sendSuccess([
                'message' => 'Signal broadcasted',
                'sentTo' => $sentTo
            ]);
        } else {
            $this->signal->addSignalingMessage($fromPeerId, $toPeerId, $roomId, $messageType, $messageData);
            $this->sendSuccess([
                'message' => 'Signal sent',
                'to' => $toPeerId
            ]);
        }
    }

    private function handlePoll() {
        $peerId = $_GET['peerId'] ?? '';
        
        if (empty($peerId)) {
            $this->sendError('Peer ID is required', 400);
            return;
        }

        // Update last seen
        $this->signal->updatePeerLastSeen($peerId);

        // Get unprocessed messages
        $messages = $this->signal->getUnprocessedMessages($peerId);

        // Mark messages as processed
        foreach ($messages as $message) {
            $this->signal->markMessageAsProcessed($message['id']);
        }

        $this->sendSuccess(['messages' => $messages]);
    }

    private function handleServerSentEvents() {
        $peerId = $_GET['peerId'] ?? '';
        
        if (empty($peerId)) {
            $this->sendError('Peer ID is required', 400);
            return;
        }

        // Set SSE headers with CORS support
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Cache-Control');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Keep connection alive and send events
        $lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0;
        $cleanupCounter = 0; // Counter to periodically run cleanup
        $roomListUpdateCounter = 0; // Counter to refresh room list
        
        while (true) {
            // Update last seen
            $this->signal->updatePeerLastSeen($peerId);
            
            // More frequent cleanup - every 3 seconds (increased frequency)
            $cleanupCounter++;
            if ($cleanupCounter >= 3) {
                $this->signal->bulkCleanupInactivePeers(0.5); // Very aggressive - Clean peers inactive for 30+ seconds
                $cleanupCounter = 0;
            }
            
            // Update room list more frequently - every 3 seconds
            $roomListUpdateCounter++;
            if ($roomListUpdateCounter >= 3) {
                // Send room list update to all connected peers
                $this->broadcastRoomListUpdate();
                $roomListUpdateCounter = 0;
            }

            // Get new messages
            $messages = $this->signal->getUnprocessedMessages($peerId);

            if (!empty($messages)) {
                foreach ($messages as $message) {
                    $eventData = json_encode([
                        'type' => $message['message_type'],
                        'from' => $message['from_peer_id'],
                        'data' => json_decode($message['message_data'], true),
                        'timestamp' => $message['created_at']
                    ]);

                    echo "id: " . $message['id'] . "\n";
                    echo "data: " . $eventData . "\n\n";
                    
                    $this->signal->markMessageAsProcessed($message['id']);
                }
                flush();
            }

            // Check if connection is still alive
            if (connection_aborted()) {
                break;
            }

            sleep(1); // Check every second
        }
    }

    private function handleGetPeers() {
        $roomId = $_GET['roomId'] ?? 'default';
        $peers = $this->signal->getRoomPeers($roomId);
        
        $this->sendSuccess(['peers' => $peers]);
    }
    
    private function handleCleanup() {
        // Clean up inactive peers (older than 1 minute for immediate cleanup)
        $inactiveCount = $this->signal->cleanupInactivePeers(1);
        
        // Clean up old messages (older than 1 hour)
        $messageCount = $this->signal->cleanupOldMessages(1);
        
        // Clean up empty rooms (rooms with no active participants)
        $emptyRoomsCount = $this->signal->cleanupEmptyRooms();
        
        $this->sendSuccess([
            'message' => 'Cleanup completed',
            'inactive_peers_removed' => $inactiveCount,
            'old_messages_removed' => $messageCount,
            'empty_rooms_removed' => $emptyRoomsCount
        ]);
    }
    
    private function handleCreateRoom() {
        $input = $this->getJsonInput();
        $roomName = $input['roomName'] ?? '';
        
        // Generate unique room ID with 3-digit format + timestamp
        $roomId = $this->signal->generateUniqueRoomId();
        
        // Create room name based on input or default format
        if (empty($roomName)) {
            $roomName = 'Room ' . explode('_', $roomId)[0]; // Use just the 3-digit part for display
        }
        
        try {
            // Create the room in database
            $this->signal->createRoom($roomId, $roomName);
            
            $this->sendSuccess([
                'message' => 'Room created successfully',
                'roomId' => $roomId,
                'roomName' => $roomName,
                'timestamp' => time()
            ]);
        } catch (Exception $e) {
            $this->sendError('Failed to create room: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleListRooms() {
        try {
            // Clean up inactive peers and empty rooms before listing
            try {
                $this->signal->cleanupInactivePeers(1); // Clean peers inactive for 1+ minutes (more aggressive)
                $this->signal->cleanupEmptyRooms(); // Clean empty rooms
            } catch (Exception $cleanupError) {
                error_log("Cleanup during room listing failed: " . $cleanupError->getMessage());
                // Don't let cleanup failure prevent room listing
            }
            
            $rooms = $this->signal->getAllRooms();
            
            $this->sendSuccess([
                'message' => 'Rooms retrieved successfully',
                'rooms' => $rooms,
                'count' => count($rooms)
            ]);
        } catch (Exception $e) {
            $this->sendError('Failed to retrieve rooms: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleHeartbeat() {
        $input = $this->getJsonInput();
        $peerId = $input['peerId'] ?? '';
        $roomId = $input['roomId'] ?? '';
        
        if (empty($peerId)) {
            $this->sendError('Peer ID is required', 400);
            return;
        }
        
        try {
            // Update peer's last seen timestamp
            $this->signal->updatePeerLastSeen($peerId);
            
            // Get current room info for the peer
            $peers = $roomId ? $this->signal->getRoomPeers($roomId) : [];
            $peerExists = false;
            foreach ($peers as $peer) {
                if ($peer['peer_id'] === $peerId) {
                    $peerExists = true;
                    break;
                }
            }
            
            if (!$peerExists && !empty($roomId)) {
                // Peer not found in room, might have been cleaned up
                $this->sendError('Peer not found in room, please rejoin', 404);
                return;
            }
            
            $this->sendSuccess([
                'message' => 'Heartbeat received',
                'peerId' => $peerId,
                'timestamp' => time(),
                'room_peers_count' => count($peers)
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Heartbeat failed: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleStats() {
        try {
            $stats = $this->signal->getSystemStats();
            
            $this->sendSuccess([
                'message' => 'System statistics retrieved',
                'stats' => $stats,
                'timestamp' => time()
            ]);
        } catch (Exception $e) {
            $this->sendError('Failed to get stats: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleDatabaseUnlock() {
        try {
            $db = Database::getInstance();
            
            // Check current lock status
            $wasLocked = $db->isDatabaseLocked();
            
            // Perform unlock and optimization
            $optimized = $db->optimizeAndUnlock();
            
            // Check final lock status
            $isStillLocked = $db->isDatabaseLocked();
            
            // Perform additional cleanup to prevent future locks
            $inactiveCount = $this->signal->cleanupInactivePeers(0.1); // Very aggressive
            $messageCount = $this->signal->cleanupOldMessages(0.5); // Clean messages older than 30 minutes
            $emptyRoomsCount = $this->signal->cleanupEmptyRooms();
            
            $this->sendSuccess([
                'message' => 'Database unlock completed',
                'was_locked' => $wasLocked,
                'optimization_success' => $optimized,
                'is_still_locked' => $isStillLocked,
                'cleanup_results' => [
                    'inactive_peers_removed' => $inactiveCount,
                    'old_messages_removed' => $messageCount,
                    'empty_rooms_removed' => $emptyRoomsCount
                ],
                'timestamp' => time()
            ]);
            
        } catch (Exception $e) {
            error_log("Database unlock failed: " . $e->getMessage());
            $this->sendError('Database unlock failed: ' . $e->getMessage(), 500);
        }
    }

    private function getJsonInput() {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    private function sendSuccess($data) {
        echo json_encode(['success' => true, 'data' => $data]);
        exit();
    }

    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message]);
        exit();
    }
    
    private function broadcastRoomListUpdate() {
        try {
            // Get current room list
            $rooms = $this->signal->getAllRooms();
            
            // Get all active peers to broadcast to
            $sql = "SELECT DISTINCT peer_id FROM peers WHERE is_connected = 1";
            $db = Database::getInstance();
            $stmt = $db->query($sql);
            $activePeers = $stmt->fetchAll();
            
            // Broadcast room list update to all active peers
            foreach ($activePeers as $peer) {
                $this->signal->addSignalingMessage(
                    'system', 
                    $peer['peer_id'], 
                    'system', 
                    'room-list-update', 
                    ['rooms' => $rooms, 'count' => count($rooms)]
                );
            }
        } catch (Exception $e) {
            error_log("Failed to broadcast room list update: " . $e->getMessage());
        }
    }
}