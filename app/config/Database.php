<?php

class Database {
    private static $instance = null;
    private $connection;
    private $dbPath;

    private function __construct() {
        // SQLite database file path
        $this->dbPath = __DIR__ . '/../../data/webrtc.db';
        $this->initializeDatabase();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    private function initializeDatabase() {
        try {
            // Create data directory if it doesn't exist
            $dataDir = dirname($this->dbPath);
            if (!file_exists($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            // Connect to SQLite database with optimized settings
            $this->connection = new PDO('sqlite:' . $this->dbPath);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Configure SQLite for better concurrency and reliability
            $this->connection->exec('PRAGMA foreign_keys = ON');
            $this->connection->exec('PRAGMA busy_timeout = 30000'); // 30 second timeout
            $this->connection->exec('PRAGMA journal_mode = WAL'); // Write-Ahead Logging for better concurrency
            $this->connection->exec('PRAGMA synchronous = NORMAL'); // Balance between safety and speed
            $this->connection->exec('PRAGMA temp_store = MEMORY'); // Use memory for temporary data
            $this->connection->exec('PRAGMA cache_size = 10000'); // Increase cache size

            // Initialize schema if database is new
            $this->createTablesIfNotExist();

        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }

    private function createTablesIfNotExist() {
        $schemaFile = __DIR__ . '/../../data/schema.sql';
        if (file_exists($schemaFile)) {
            $schema = file_get_contents($schemaFile);
            $this->connection->exec($schema);
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    public function query($sql, $params = []) {
        $maxRetries = 3;
        $retryDelay = 100000; // 100ms in microseconds
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                // Check if it's a database locked error
                if (strpos($e->getMessage(), 'database is locked') !== false && $attempt < $maxRetries) {
                    error_log("Database locked on attempt $attempt, retrying... SQL: $sql");
                    usleep($retryDelay * $attempt); // Exponential backoff
                    continue;
                }
                
                error_log("Query failed: " . $e->getMessage() . " - SQL: $sql");
                throw new Exception("Query execution failed: " . $e->getMessage());
            }
        }
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction() {
        $maxRetries = 3;
        $retryDelay = 100000; // 100ms
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->connection->beginTransaction();
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'database is locked') !== false && $attempt < $maxRetries) {
                    error_log("Database locked during transaction start on attempt $attempt, retrying...");
                    usleep($retryDelay * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollback() {
        return $this->connection->rollback();
    }

    /**
     * Force unlock database and optimize for better concurrency
     */
    public function optimizeAndUnlock() {
        try {
            // Check if database is accessible
            $this->connection->exec('PRAGMA busy_timeout = 30000');
            
            // Force WAL checkpoint to reduce lock contention
            $this->connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            
            // Optimize database
            $this->connection->exec('PRAGMA optimize');
            
            // Vacuum if needed (helps with lock issues)
            $result = $this->connection->query('PRAGMA integrity_check')->fetch();
            if ($result && $result[0] === 'ok') {
                $this->connection->exec('VACUUM');
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Database optimization failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if database is currently locked
     */
    public function isDatabaseLocked() {
        try {
            $this->connection->exec('BEGIN IMMEDIATE');
            $this->connection->exec('ROLLBACK');
            return false;
        } catch (PDOException $e) {
            return strpos($e->getMessage(), 'locked') !== false;
        }
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {}
}