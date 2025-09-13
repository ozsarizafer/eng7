<?php
class Database {
    private $pdo;
    
    public function __construct() {
        try {
            $this->pdo = new PDO('sqlite:quiz_game.db');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->createTables();
            $this->insertQuestions();
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    
    private function createTables() {
        // Users table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            team TEXT NOT NULL,
            session_id TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Games table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            status TEXT DEFAULT 'waiting',
            current_question INTEGER DEFAULT 0,
            current_team TEXT DEFAULT 'A',
            team_a_score INTEGER DEFAULT 0,
            team_b_score INTEGER DEFAULT 0,
            question_start_time INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Questions table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question TEXT NOT NULL,
            options TEXT NOT NULL,
            correct_answer TEXT NOT NULL,
            category TEXT NOT NULL,
            difficulty TEXT NOT NULL
        )");
        
        // Game answers table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS game_answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            team TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            answer TEXT,
            is_correct BOOLEAN DEFAULT 0,
            answered_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Game state table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS game_state (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id INTEGER NOT NULL,
            state_data TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
    
    private function insertQuestions() {
        // Check if questions already exist
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM questions");
        if ($stmt->fetchColumn() > 0) {
            return;
        }
        
        $questions = [
            [
                "question" => "Which phrase appears at the beginning of almost every chapter (surah) in the Qur'an?",
                "options" => json_encode(["Inna lillahi wa inna ilayhi raji'un", "Bismillahirrahmanirrahim", "Allahu Akbar", "La ilaha illallah"]),
                "correct_answer" => "Bismillahirrahmanirrahim",
                "category" => "religion",
                "difficulty" => "medium"
            ],
            [
                "question" => "Which animal is known as the fastest land animal?",
                "options" => json_encode(["Lion", "Tiger", "Cheetah", "Leopard"]),
                "correct_answer" => "Cheetah",
                "category" => "animals",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which is the largest desert in the world?",
                "options" => json_encode(["Gobi Desert", "Sahara Desert", "Antarctic Desert", "Kalahari Desert"]),
                "correct_answer" => "Antarctic Desert",
                "category" => "geography",
                "difficulty" => "medium"
            ],
            [
                "question" => "Which organ in the human body pumps blood throughout the body?",
                "options" => json_encode(["Brain", "Lungs", "Heart", "Kidneys"]),
                "correct_answer" => "Heart",
                "category" => "biology",
                "difficulty" => "easy"
            ],
            [
                "question" => "What symbol is often used to represent justice in many cultures?",
                "options" => json_encode(["A sword", "A scale", "A crown", "A book"]),
                "correct_answer" => "A scale",
                "category" => "culture",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which country is famous for inventing pizza?",
                "options" => json_encode(["Spain", "Italy", "France", "Turkey"]),
                "correct_answer" => "Italy",
                "category" => "food",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which planet is known as the 'Red Planet'?",
                "options" => json_encode(["Jupiter", "Venus", "Mars", "Saturn"]),
                "correct_answer" => "Mars",
                "category" => "space",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which animal is known as the king of the jungle?",
                "options" => json_encode(["Tiger", "Lion", "Elephant", "Leopard"]),
                "correct_answer" => "Lion",
                "category" => "animals",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which bird is famous for its ability to mimic human speech?",
                "options" => json_encode(["Crow", "Parrot", "Owl", "Sparrow"]),
                "correct_answer" => "Parrot",
                "category" => "animals",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which animal lays the largest eggs?",
                "options" => json_encode(["Ostrich", "Chicken", "Turtle", "Eagle"]),
                "correct_answer" => "Ostrich",
                "category" => "animals",
                "difficulty" => "medium"
            ],
            [
                "question" => "Which sea creature has eight arms?",
                "options" => json_encode(["Squid", "Shark", "Octopus", "Seal"]),
                "correct_answer" => "Octopus",
                "category" => "animals",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which is the largest continent on Earth?",
                "options" => json_encode(["Africa", "Asia", "Europe", "South America"]),
                "correct_answer" => "Asia",
                "category" => "geography",
                "difficulty" => "easy"
            ],
            [
                "question" => "Which country has the longest coastline in the world?",
                "options" => json_encode(["Australia", "Canada", "Russia", "USA"]),
                "correct_answer" => "Canada",
                "category" => "geography",
                "difficulty" => "medium"
            ],
            [
                "question" => "Mount Everest is located in which mountain range?",
                "options" => json_encode(["Andes", "Himalayas", "Rockies", "Alps"]),
                "correct_answer" => "Himalayas",
                "category" => "geography",
                "difficulty" => "medium"
            ],
            [
                "question" => "Which ocean is the smallest by area?",
                "options" => json_encode(["Indian Ocean", "Arctic Ocean", "Atlantic Ocean", "Pacific Ocean"]),
                "correct_answer" => "Arctic Ocean",
                "category" => "geography",
                "difficulty" => "medium"
            ],
            [
                "question" => "Which organ helps humans breathe?",
                "options" => json_encode(["Heart", "Brain", "Lungs", "Kidneys"]),
                "correct_answer" => "Lungs",
                "category" => "biology",
                "difficulty" => "easy"
            ],
            [
                "question" => "What is the strongest muscle in the human body based on size?",
                "options" => json_encode(["Tongue", "Heart", "Jaw (Masseter)", "Leg (Quadriceps)"]),
                "correct_answer" => "Jaw (Masseter)",
                "category" => "biology",
                "difficulty" => "hard"
            ],
            [
                "question" => "Which part of the human body contains the most bones?",
                "options" => json_encode(["Hand", "Foot", "Spine", "Skull"]),
                "correct_answer" => "Hand",
                "category" => "biology",
                "difficulty" => "medium"
            ],
            [
                "question" => "Which blood type is known as the universal donor?",
                "options" => json_encode(["A", "B", "AB", "O negative"]),
                "correct_answer" => "O negative",
                "category" => "biology",
                "difficulty" => "hard"
            ],
            [
                "question" => "Which planet has the most moons?",
                "options" => json_encode(["Jupiter", "Saturn", "Mars", "Neptune"]),
                "correct_answer" => "Saturn",
                "category" => "space",
                "difficulty" => "medium"
            ]
        ];
        
        $stmt = $this->pdo->prepare("INSERT INTO questions (question, options, correct_answer, category, difficulty) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($questions as $q) {
            $stmt->execute([$q['question'], $q['options'], $q['correct_answer'], $q['category'], $q['difficulty']]);
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}
?>