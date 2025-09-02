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

            // Connect to SQLite database
            $this->connection = new PDO('sqlite:' . $this->dbPath);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Enable foreign key constraints
            $this->connection->exec('PRAGMA foreign_keys = ON');

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
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage());
            throw new Exception("Query execution failed");
        }
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollback() {
        return $this->connection->rollback();
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {}
}