<?php
require_once 'database.php';

class GameController {
    private $db;
    private $pdo;
    
    public function __construct() {
        $this->db = new Database();
        $this->pdo = $this->db->getConnection();
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
            
            // Insert new user
            $stmt = $this->pdo->prepare("INSERT INTO users (username, team, session_id) VALUES (?, ?, ?)");
            $stmt->execute([$username, $team, $sessionId]);
            $userId = $this->pdo->lastInsertId();
            
            // Check player count
            $playerCount = $this->getPlayerCount();
            
            if ($playerCount >= 4) {
                $this->startGame();
            }
            
            return ['success' => true, 'user_id' => $userId, 'team' => $team, 'player_count' => $playerCount];
            
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
        // Check if game already exists
        $stmt = $this->pdo->query("SELECT * FROM games WHERE status = 'active' OR status = 'waiting'");
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$game) {
            $this->pdo->exec("INSERT INTO games (status) VALUES ('active')");
        } else {
            $this->pdo->prepare("UPDATE games SET status = 'active' WHERE id = ?")->execute([$game['id']]);
        }
    }
    
    public function getCurrentGame() {
        $stmt = $this->pdo->query("SELECT * FROM games WHERE status = 'active' OR status = 'waiting' ORDER BY id DESC LIMIT 1");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getRandomQuestion() {
        // Time-based random selection using microseconds
        $microtime = microtime(true);
        $seed = (int)(($microtime * 1000000) % 1000000);
        
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM questions");
        $totalQuestions = $stmt->fetchColumn();
        
        $randomIndex = ($seed % $totalQuestions) + 1;
        
        $stmt = $this->pdo->prepare("SELECT * FROM questions WHERE id = ?");
        $stmt->execute([$randomIndex]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($question) {
            $question['options'] = json_decode($question['options'], true);
        }
        
        return $question;
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
            $currentQuestion = $game['current_question'] + 1;
            $nextTeam = ($game['current_team'] === 'A') ? 'B' : 'A';
            
            // Check if game should end (7 questions per team = 14 total)
            if ($currentQuestion >= 14) {
                $this->pdo->prepare("UPDATE games SET status = 'finished' WHERE id = ?")->execute([$gameId]);
                return ['game_ended' => true];
            }
            
            $this->pdo->prepare("UPDATE games SET current_question = ?, current_team = ?, question_start_time = ? WHERE id = ?")
                ->execute([$currentQuestion, $nextTeam, time(), $gameId]);
        }
        
        return ['success' => true];
    }
    
    public function resetGame() {
        $this->pdo->exec("DELETE FROM users");
        $this->pdo->exec("DELETE FROM games");
        $this->pdo->exec("DELETE FROM game_answers");
        $this->pdo->exec("DELETE FROM game_state");
        return ['success' => true];
    }
    
    public function getGameState() {
        $game = $this->getCurrentGame();
        $teams = $this->getTeamDistribution();
        $playerCount = $this->getPlayerCount();
        
        $users = [];
        $stmt = $this->pdo->query("SELECT * FROM users ORDER BY team, id");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = $row;
        }
        
        return [
            'game' => $game,
            'teams' => $teams,
            'player_count' => $playerCount,
            'users' => $users
        ];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    $controller = new GameController();
    $input = json_decode(file_get_contents('php://input'), true);
    
    header('Content-Type: application/json');
    
    switch ($input['action']) {
        case 'join':
            $result = $controller->joinGame($input['username'], $input['team'], session_id());
            echo json_encode($result);
            break;
            
        case 'get_question':
            $question = $controller->getRandomQuestion();
            echo json_encode($question);
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
    }
}
?>