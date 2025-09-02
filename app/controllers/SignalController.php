<?php

require_once __DIR__ . '/../models/Signal.php';
require_once __DIR__ . '/../config/HttpsRedirect.php';

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
                default:
                    $this->sendError('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("SignalController error: " . $e->getMessage());
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

        // Check room capacity (max 4 peers)
        $currentPeers = $this->signal->getRoomPeers($roomId);
        if (count($currentPeers) >= 4) {
            $this->sendError('Room is full. Maximum 4 participants allowed.', 403);
            return;
        }

        $this->signal->joinRoom($roomId, $peerId, $username);
        
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

        if (empty($peerId)) {
            $this->sendError('Peer ID is required', 400);
            return;
        }

        // Notify other peers before leaving
        $this->signal->broadcastToRoom($roomId, $peerId, 'peer-left', [
            'peerId' => $peerId
        ]);

        $this->signal->leavePeer($peerId);

        $this->sendSuccess([
            'message' => 'Successfully left room',
            'peerId' => $peerId
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
        
        while (true) {
            // Update last seen
            $this->signal->updatePeerLastSeen($peerId);

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
}