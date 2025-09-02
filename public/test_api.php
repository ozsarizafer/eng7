<?php

// Simple API test endpoint for debugging
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Log the request
error_log("Test API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
error_log("Headers: " . json_encode(getallheaders()));

try {
    // Basic connectivity test
    $response = [
        'success' => true,
        'message' => 'API endpoint is reachable',
        'timestamp' => date('Y-m-d H:i:s'),
        'protocol' => isset($_SERVER['HTTPS']) ? 'HTTPS' : 'HTTP',
        'server' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'port' => $_SERVER['SERVER_PORT'] ?? 'unknown',
        'method' => $_SERVER['REQUEST_METHOD'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    // Test database connection
    try {
        require_once '../app/config/Database.php';
        $db = Database::getInstance();
        $stmt = $db->query("SELECT COUNT(*) as count FROM rooms");
        $result = $stmt->fetch();
        
        $response['database'] = [
            'status' => 'connected',
            'rooms_count' => $result['count']
        ];
    } catch (Exception $e) {
        $response['database'] = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
    
    // Return success response
    echo json_encode($response);
    
} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}