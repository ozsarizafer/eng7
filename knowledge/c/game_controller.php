<?php
require_once 'quiz_database.php';

class GameController {
    private $db;
    private $pdo;
    
    public function __construct() {
        $this->db = new Database();
        $this->pdo = $this->db->getConnection();
    }

    private function getNewQuestionId($used_questions_json) {
        $used_questions = json_decode($used_questions_json, true);
        $placeholders = implode(',', array_fill(0, count($used_questions), '?'));
        
        $sql = "SELECT id FROM questions";
        if (!empty($used_questions)) {
            $sql .= " WHERE id NOT IN ($placeholders)";
        }
        $sql .= " ORDER BY RANDOM() LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        if (!empty($used_questions)) {
            $stmt->execute($used_questions);
        }
        else {
            $stmt->execute();
        }
        
        return $stmt->fetchColumn();
    }
    
    public function joinGame($username, $team, $sessionId) {
        try {
            // Check if user already exists with this session
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE session_id = ?");
            $stmt->execute([$sessionId]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingUser) {
                return ['success' => true, 'user_id' => $existingUser['id'], 'team' => $existingUser['team']];
            }
            
            // Check team capacity (max 2 per team)
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE team = ?");
            $stmt->execute([$team]);
            $teamCount = $stmt->fetchColumn();
            
            if ($teamCount >= 2) {
                return ['success' => false, 'error' => 'Bu takım dolu! Diğer takımı seçiniz.'];
            }
            
            // Generate automatic username
            $autoUsername = 'Oyuncu_' . $team . '_' . ($teamCount + 1);
            
            // Insert new user
            $stmt = $this->pdo->prepare("INSERT INTO users (username, team, session_id) VALUES (?, ?, ?)");
            $stmt->execute([$autoUsername, $team, $sessionId]);
            $userId = $this->pdo->lastInsertId();
            
            return ['success' => true, 'user_id' => $userId, 'team' => $team, 'username' => $autoUsername];
            
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function setReady($userId) {
        try {
            $stmt = $this->pdo->prepare("UPDATE users SET status = 'ready' WHERE id = ?");
            $stmt->execute([$userId]);
            
            // Check if all players are ready
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE status = 'ready'");
            $readyCount = $stmt->fetchColumn();
            
            $playerCount = $this->getPlayerCount();
            
            if ($playerCount == 4 && $readyCount == 4) {
                $this->startGame();
            }
            
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function timeUp($gameId) {
        $this->pdo->prepare("UPDATE games SET show_answer = 1 WHERE id = ?")->execute([$gameId]);
    }
    
    public function getPlayerCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        return $stmt->fetchColumn();
    }
    
    public function getTeamDistribution() {
        $stmt = $this->pdo->query("SELECT team, COUNT(*) as count FROM users GROUP BY team");
        $teams = ['A' => 0, 'B' => 0];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $teams[$row['team']] = $row['count'];
        }
        
        return $teams;
    }
    
    public function startGame() {
        $game = $this->getCurrentGame();
        if ($game && $game['status'] === 'active') {
            return; // Game already started
        }

        $newQuestionId = $this->getNewQuestionId('[]');
        $usedQuestions = json_encode([$newQuestionId]);
        $startTime = time();

        if (!$game) {
            $sql = "INSERT INTO games (status, question_start_time, current_question_id, used_questions) VALUES ('active', ?, ?, ?)";
            $this->pdo->prepare($sql)->execute([$startTime, $newQuestionId, $usedQuestions]);
        } else {
            $sql = "UPDATE games SET status = 'active', question_start_time = ?, current_question_id = ?, used_questions = ? WHERE id = ?";
            $this->pdo->prepare($sql)->execute([$startTime, $newQuestionId, $usedQuestions, $game['id']]);
        }
    }
    
    public function getCurrentGame() {
        $stmt = $this->pdo->query("SELECT g.*, q.question, q.options, q.correct_answer FROM games g LEFT JOIN questions q ON g.current_question_id = q.id WHERE g.status = 'active' OR g.status = 'waiting' ORDER BY g.id DESC LIMIT 1");
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($game && $game['options']) {
            $game['options'] = json_decode($game['options'], true);
        }
        return $game;
    }
    
    public function submitAnswer($gameId, $questionId, $team, $userId, $answer) {
        try {
            // Check if user already answered this question
            $stmt = $this->pdo->prepare("SELECT * FROM game_answers WHERE game_id = ? AND question_id = ? AND user_id = ?");
            $stmt->execute([$gameId, $questionId, $userId]);
            
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Already answered'];
            }
            
            // Get correct answer
            $stmt = $this->pdo->prepare("SELECT correct_answer FROM questions WHERE id = ?");
            $stmt->execute([$questionId]);
            $correctAnswer = $stmt->fetchColumn();
            
            $isCorrect = ($answer === $correctAnswer);
            
            // Insert answer
            $stmt = $this->pdo->prepare("INSERT INTO game_answers (game_id, question_id, team, user_id, answer, is_correct) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$gameId, $questionId, $team, $userId, $answer, $isCorrect]);
            
            // Check if both team members answered
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM game_answers WHERE game_id = ? AND question_id = ? AND team = ?");
            $stmt->execute([$gameId, $questionId, $team]);
            $teamAnswers = $stmt->fetchColumn();
            
            // If both answered, check if both are correct
            if ($teamAnswers >= 2) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM game_answers WHERE game_id = ? AND question_id = ? AND team = ? AND is_correct = 1");
                $stmt->execute([$gameId, $questionId, $team]);
                $correctAnswers = $stmt->fetchColumn();
                
                if ($correctAnswers >= 2) {
                    // Update team score
                    if ($team === 'A') {
                        $this->pdo->prepare("UPDATE games SET team_a_score = team_a_score + 1 WHERE id = ?")->execute([$gameId]);
                    } else {
                        $this->pdo->prepare("UPDATE games SET team_b_score = team_b_score + 1 WHERE id = ?")->execute([$gameId]);
                    }
                }
                
                $this->pdo->prepare("UPDATE games SET show_answer = 1 WHERE id = ?")->execute([$gameId]);
                return ['success' => true, 'team_answered' => true, 'correct' => $correctAnswers >= 2];
            }
            
            return ['success' => true, 'team_answered' => false, 'correct' => $isCorrect];
            
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function nextQuestion($gameId) {
        $game = $this->getCurrentGame();
        if ($game) {
            $newQuestionId = $this->getNewQuestionId($game['used_questions']);
            if (!$newQuestionId) {
                // No more questions, end game
                $this->pdo->prepare("UPDATE games SET status = 'finished' WHERE id = ?")->execute([$gameId]);
                return ['game_ended' => true];
            }

            $used_questions_array = json_decode($game['used_questions'], true);
            $used_questions_array[] = $newQuestionId;
            $used_questions_json = json_encode($used_questions_array);

            $currentQuestion = $game['current_question'] + 1;
            $nextTeam = ($game['current_team'] === 'A') ? 'B' : 'A';
            
            // Check if game should end (e.g., 14 questions total)
            if ($currentQuestion >= 14) {
                $this->pdo->prepare("UPDATE games SET status = 'finished' WHERE id = ?")->execute([$gameId]);
                return ['game_ended' => true];
            }
            
            $sql = "UPDATE games SET current_question = ?, current_team = ?, question_start_time = ?, show_answer = 0, current_question_id = ?, used_questions = ? WHERE id = ?";
            $this->pdo->prepare($sql)->execute([$currentQuestion, $nextTeam, time(), $newQuestionId, $used_questions_json, $gameId]);
        }
        
        return ['success' => true];
    }
    
    public function resetGame() {
        $this->pdo->exec("DELETE FROM users");
        $this->pdo->exec("DELETE FROM games");
        $this->pdo->exec("DELETE FROM game_answers");
        return ['success' => true];
    }
    
    public function getGameState() {
        $game = $this->getCurrentGame();
        $teams = $this->getTeamDistribution();
        $playerCount = $this->getPlayerCount();
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE status = 'ready'");
        $readyPlayerCount = $stmt->fetchColumn();
        
        if ($game) {
            $users = [];
            $stmt = $this->pdo->query("SELECT * FROM users ORDER BY team, id");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $users[] = $row;
            }
            $game['users'] = $users;
        }
        
        return [
            'game' => $game,
            'teams' => $teams,
            'player_count' => $playerCount,
            'ready_player_count' => $readyPlayerCount
        ];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    $controller = new GameController();
    $input = json_decode(file_get_contents('php://input'), true);
    
    header('Content-Type: application/json');
    
    try {
        switch ($input['action']) {
            case 'join':
                $result = $controller->joinGame($input['username'], $input['team'], session_id());
                echo json_encode($result);
                break;
            
            case 'set_ready':
                $result = $controller->setReady($input['user_id']);
                echo json_encode($result);
                break;
                
            case 'submit_answer':
                $result = $controller->submitAnswer($input['game_id'], $input['question_id'], $input['team'], $input['user_id'], $input['answer']);
                echo json_encode($result);
                break;
                
            case 'next_question':
                $result = $controller->nextQuestion($input['game_id']);
                echo json_encode($result);
                break;
                
            case 'reset_game':
                $result = $controller->resetGame();
                echo json_encode($result);
                break;
                
            case 'get_state':
                $state = $controller->getGameState();
                echo json_encode($state);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                break;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
}
?>