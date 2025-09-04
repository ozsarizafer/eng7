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
                // Competition endpoints
                case 'create_competition':
                    $this->handleCreateCompetition();
                    break;
                case 'player_ready':
                    $this->handlePlayerReady();
                    break;
                case 'get_game_state':
                    $this->handleGetGameState();
                    break;
                case 'submit_answer':
                    $this->handleSubmitAnswer();
                    break;
                case 'next_question':
                    $this->handleNextQuestion();
                    break;
                case 'get_questions':
                    $this->handleGetQuestions();
                    break;
                case 'get_results':
                    $this->handleGetResults();
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
            // Clean up inactive peers before checking capacity (more aggressive - 1 minute)
            try {
                $cleanupCount = $this->signal->cleanupInactivePeers(1);
                if ($cleanupCount > 0) {
                    error_log("Cleaned up $cleanupCount inactive peers before join");
                }
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
            
            // Atomic join with capacity validation
            $this->signal->joinRoom($roomId, $peerId, $username);
            
        } catch (Exception $e) {
            // Handle capacity exceeded error specifically
            if (strpos($e->getMessage(), 'capacity exceeded') !== false) {
                error_log("Room capacity exceeded for peer $peerId in room $roomId");
                $this->sendError('Room is full. Maximum 4 participants allowed.', 403);
                return;
            }
            
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
        
        // Immediate capacity update broadcast
        $this->broadcastCapacityUpdate($roomId);

        $this->sendSuccess([
            'message' => 'Successfully joined room',
            'roomId' => $roomId,
            'peerId' => $peerId,
            'currentCapacity' => count($allPeers),
            'maxCapacity' => 4,
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
        
        // Immediate capacity update after leave
        $this->broadcastCapacityUpdate($roomId);
        
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
            
            // More conservative cleanup - every 10 seconds (reduced frequency)
            $cleanupCounter++;
            if ($cleanupCounter >= 10) {
                $this->signal->bulkCleanupInactivePeers(2); // Less aggressive - Clean peers inactive for 2+ minutes
                $cleanupCounter = 0;
            }
            
            // Update room list less frequently - every 15 seconds
            $roomListUpdateCounter++;
            if ($roomListUpdateCounter >= 15) {
                // Send room list update to all connected peers
                $this->broadcastRoomListUpdate();
                $roomListUpdateCounter = 0;
            }

            // Get new messages
            $messages = $this->signal->getUnprocessedMessages($peerId);

            if (!empty($messages)) {
                // Batch messages for better performance
                $batchedEvents = [];
                
                foreach ($messages as $message) {
                    $batchedEvents[] = [
                        'id' => $message['id'],
                        'type' => $message['message_type'],
                        'from' => $message['from_peer_id'],
                        'data' => json_decode($message['message_data'], true),
                        'timestamp' => $message['created_at']
                    ];
                    
                    $this->signal->markMessageAsProcessed($message['id']);
                }
                
                // Send batched events
                if (count($batchedEvents) === 1) {
                    // Single message
                    echo "id: " . $batchedEvents[0]['id'] . "\n";
                    echo "data: " . json_encode($batchedEvents[0]) . "\n\n";
                } else {
                    // Multiple messages - send as batch
                    echo "id: batch_" . time() . "\n";
                    echo "data: " . json_encode(['type' => 'batch', 'messages' => $batchedEvents]) . "\n\n";
                }
                
                flush();
            }

            // Check if connection is still alive
            if (connection_aborted()) {
                break;
            }

            sleep(3); // Check every 3 seconds for better performance
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
    
    private function broadcastCapacityUpdate($roomId) {
        try {
            $currentPeers = $this->signal->getRoomPeers($roomId);
            $capacity = count($currentPeers);
            
            // Get all active peers to broadcast to
            $sql = "SELECT DISTINCT peer_id FROM peers WHERE is_connected = 1";
            $db = Database::getInstance();
            $stmt = $db->query($sql);
            $activePeers = $stmt->fetchAll();
            
            // Broadcast capacity update to all connected clients
            foreach ($activePeers as $peer) {
                $this->signal->addSignalingMessage(
                    'system', 
                    $peer['peer_id'], 
                    'system', 
                    'capacity-update', 
                    [
                        'roomId' => $roomId,
                        'currentCapacity' => $capacity,
                        'maxCapacity' => 4,
                        'isFull' => $capacity >= 4,
                        'timestamp' => time()
                    ]
                );
            }
            
            error_log("Broadcasted capacity update for room $roomId: $capacity/4 participants");
            
        } catch (Exception $e) {
            error_log("Failed to broadcast capacity update: " . $e->getMessage());
        }
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
    
    // Competition handler methods
    
    private function handleCreateCompetition() {
        $input = $this->getJsonInput();
        $roomId = $input['roomId'] ?? '';
        $peerId = $input['peerId'] ?? '';
        $selectedTeam = $input['selectedTeam'] ?? '';
        
        if (empty($roomId) || empty($peerId) || empty($selectedTeam)) {
            $this->sendError('Room ID, Peer ID, and selected team are required', 400);
            return;
        }
        
        try {
            // Check if peer exists in the room
            $roomPeers = $this->signal->getRoomPeers($roomId);
            $peerExists = false;
            foreach ($roomPeers as $peer) {
                if ($peer['peer_id'] === $peerId) {
                    $peerExists = true;
                    break;
                }
            }
            
            if (!$peerExists) {
                $this->sendError('Peer not found in room. Please join the room first.', 400);
                return;
            }
            
            // Get or create competition game for this room
            $existingGame = $this->signal->getCompetitionGame($roomId);
            
            if (!$existingGame || $existingGame['game_state'] === 'finished') {
                // Create new competition game
                $gameId = $this->signal->createCompetitionGame($roomId);
            } else {
                $gameId = $existingGame['id'];
            }
            
            // Add this player to selected team
            $this->signal->assignPlayerToTeam($gameId, $peerId, $selectedTeam);
            
            // Get current team assignments
            $teams = $this->signal->getTeamAssignments($gameId);
            
            // Broadcast competition state to all room members
            $this->signal->broadcastToRoom($roomId, 'system', 'competition-updated', [
                'gameId' => $gameId,
                'teams' => $teams,
                'message' => 'Player joined competition. Select your team and click "I\'m Ready"!'
            ]);
            
            $this->sendSuccess([
                'message' => 'Joined competition successfully',
                'gameId' => $gameId,
                'teams' => $teams
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Failed to create competition: ' . $e->getMessage(), 500);
        }
    }
    
    private function handlePlayerReady() {
        $input = $this->getJsonInput();
        $gameId = $input['gameId'] ?? '';
        $peerId = $input['peerId'] ?? '';
        $roomId = $input['roomId'] ?? '';
        
        if (empty($gameId) || empty($peerId)) {
            $this->sendError('Game ID and Peer ID are required', 400);
            return;
        }
        
        try {
            // Set player as ready
            $this->signal->setPlayerReady($gameId, $peerId);
            
            // Check if all players are ready
            $allReady = $this->signal->checkAllPlayersReady($gameId);
            
            if ($allReady) {
                // Start the competition
                $this->signal->startCompetition($gameId);
                
                // Broadcast game start
                $this->signal->broadcastToRoom($roomId, 'system', 'competition-started', [
                    'gameId' => $gameId,
                    'message' => 'All players ready! Competition starting...',
                    'currentTeam' => 'A',
                    'questionIndex' => 0
                ]);
            } else {
                // Broadcast ready status update
                $teams = $this->signal->getTeamAssignments($gameId);
                $this->signal->broadcastToRoom($roomId, 'system', 'player-ready', [
                    'peerId' => $peerId,
                    'teams' => $teams
                ]);
            }
            
            $this->sendSuccess([
                'message' => 'Player marked as ready',
                'allReady' => $allReady
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Failed to mark player ready: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleGetGameState() {
        $gameId = $_GET['gameId'] ?? '';
        
        if (empty($gameId)) {
            $this->sendError('Game ID is required', 400);
            return;
        }
        
        try {
            $gameState = $this->signal->getCurrentGameState($gameId);
            $teams = $this->signal->getTeamAssignments($gameId);
            
            $this->sendSuccess([
                'gameState' => $gameState,
                'teams' => $teams
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Failed to get game state: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleSubmitAnswer() {
        $input = $this->getJsonInput();
        $gameId = $input['gameId'] ?? '';
        $peerId = $input['peerId'] ?? '';
        $roomId = $input['roomId'] ?? '';
        $selectedAnswer = $input['selectedAnswer'] ?? '';
        $questionIndex = $input['questionIndex'] ?? 0;
        $correctAnswer = $input['correctAnswer'] ?? '';
        
        if (empty($gameId) || empty($peerId) || empty($selectedAnswer)) {
            $this->sendError('Game ID, Peer ID, and selected answer are required', 400);
            return;
        }
        
        try {
            // Get team assignment for this peer
            $teams = $this->signal->getTeamAssignments($gameId);
            $currentTeam = null;
            
            foreach ($teams as $team) {
                if ($team['peer_id'] === $peerId) {
                    $currentTeam = $team['team'];
                    break;
                }
            }
            
            if (!$currentTeam) {
                $this->sendError('Player not found in any team', 400);
                return;
            }
            
            // Check if answer is correct
            $isCorrect = $selectedAnswer === $correctAnswer;
            
            // Submit answer
            $this->signal->submitAnswer($gameId, $questionIndex, $currentTeam, $selectedAnswer, $isCorrect);
            
            // Update score if correct
            if ($isCorrect) {
                $this->signal->updateGameScore($gameId, $currentTeam);
            }
            
            // Broadcast answer submission
            $this->signal->broadcastToRoom($roomId, 'system', 'answer-submitted', [
                'team' => $currentTeam,
                'selectedAnswer' => $selectedAnswer,
                'correctAnswer' => $correctAnswer,
                'isCorrect' => $isCorrect,
                'questionIndex' => $questionIndex
            ]);
            
            $this->sendSuccess([
                'message' => 'Answer submitted successfully',
                'isCorrect' => $isCorrect
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Failed to submit answer: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleNextQuestion() {
        $input = $this->getJsonInput();
        $gameId = $input['gameId'] ?? '';
        $roomId = $input['roomId'] ?? '';
        
        if (empty($gameId)) {
            $this->sendError('Game ID is required', 400);
            return;
        }
        
        try {
            $gameState = $this->signal->getCurrentGameState($gameId);
            
            if ($gameState['current_question_index'] >= 13) {
                // Game finished (14 questions total: 0-13)
                $this->signal->nextQuestion($gameId);
                
                $results = $this->signal->getGameResults($gameId);
                
                $this->signal->broadcastToRoom($roomId, 'system', 'competition-finished', [
                    'results' => $results,
                    'message' => 'Competition finished! Check the final scores.'
                ]);
                
                $this->sendSuccess([
                    'message' => 'Competition finished',
                    'results' => $results
                ]);
            } else {
                // Next question
                $this->signal->nextQuestion($gameId);
                $newGameState = $this->signal->getCurrentGameState($gameId);
                
                $this->signal->broadcastToRoom($roomId, 'system', 'next-question', [
                    'currentTeam' => $newGameState['current_team'],
                    'questionIndex' => $newGameState['current_question_index'],
                    'teamAScore' => $newGameState['team_a_score'],
                    'teamBScore' => $newGameState['team_b_score']
                ]);
                
                $this->sendSuccess([
                    'message' => 'Next question ready',
                    'gameState' => $newGameState
                ]);
            }
            
        } catch (Exception $e) {
            $this->sendError('Failed to proceed to next question: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleGetQuestions() {
        try {
            $questions = $this->signal->loadQuestions();
            
            $this->sendSuccess([
                'questions' => $questions,
                'total' => count($questions)
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Failed to load questions: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleGetResults() {
        $gameId = $_GET['gameId'] ?? '';
        
        if (empty($gameId)) {
            $this->sendError('Game ID is required', 400);
            return;
        }
        
        try {
            $results = $this->signal->getGameResults($gameId);
            
            $this->sendSuccess([
                'results' => $results
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Failed to get results: ' . $e->getMessage(), 500);
        }
    }
}