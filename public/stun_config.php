<?php
/**
 * Local STUN Service Configuration
 * Provides STUN server endpoints for WebRTC
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class StunConfig {
    
    /**
     * Get STUN server configuration for WebRTC
     */
    public static function getStunServers() {
        $protocol = $_SERVER['REQUEST_SCHEME'] ?? 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = dirname($_SERVER['REQUEST_URI']);
        
        // Ensure basePath ends with /
        if (substr($basePath, -1) !== '/') {
            $basePath .= '/';
        }
        
        $stunUrl = $protocol . '://' . $host . $basePath . 'stun_server.php';
        
        return [
            'success' => true,
            'stun_servers' => [
                [
                    'urls' => $stunUrl . '?action=binding',
                    'type' => 'stun',
                    'description' => 'Local PHP STUN Server - Binding'
                ],
                [
                    'urls' => $stunUrl . '?action=discover',
                    'type' => 'stun',
                    'description' => 'Local PHP STUN Server - Discovery'
                ],
                [
                    'urls' => $stunUrl . '?action=candidates',
                    'type' => 'stun',
                    'description' => 'Local PHP STUN Server - Candidates'
                ]
            ],
            'webrtc_config' => [
                'iceServers' => [
                    [
                        'urls' => 'stun:' . $host
                    ]
                ]
            ],
            'fallback_config' => [
                'iceServers' => [
                    // Keep one external STUN server as fallback
                    ['urls' => 'stun:stun.l.google.com:19302']
                ]
            ],
            'server_info' => [
                'host' => $host,
                'protocol' => $protocol,
                'stun_endpoint' => $stunUrl,
                'timestamp' => time()
            ]
        ];
    }
    
    /**
     * Test STUN server connectivity
     */
    public static function testStunServer() {
        $config = self::getStunServers();
        $stunUrl = $config['server_info']['stun_endpoint'];
        
        $testResults = [];
        
        // Test each STUN action
        $actions = ['binding', 'discover', 'candidates'];
        
        foreach ($actions as $action) {
            $testUrl = $stunUrl . '?action=' . $action;
            
            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'method' => 'GET'
                    ]
                ]);
                
                $response = file_get_contents($testUrl, false, $context);
                $data = json_decode($response, true);
                
                $testResults[$action] = [
                    'success' => $data['success'] ?? false,
                    'response_time' => 'N/A',
                    'url' => $testUrl
                ];
                
            } catch (Exception $e) {
                $testResults[$action] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'url' => $testUrl
                ];
            }
        }
        
        return [
            'success' => true,
            'test_results' => $testResults,
            'timestamp' => time()
        ];
    }
}

// Handle the request
$action = $_GET['action'] ?? 'config';

try {
    switch ($action) {
        case 'config':
            $response = StunConfig::getStunServers();
            break;
            
        case 'test':
            $response = StunConfig::testStunServer();
            break;
            
        default:
            $response = [
                'success' => false,
                'error' => 'Unknown action. Use: config, test'
            ];
            break;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Configuration error: ' . $e->getMessage(),
        'timestamp' => time()
    ], JSON_PRETTY_PRINT);
}
?>