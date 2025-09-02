<?php
/**
 * Local PHP STUN Server Implementation
 * Provides basic STUN functionality for WebRTC NAT traversal
 * Simplified implementation for local network usage
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

class LocalStunServer {
    private $publicIp;
    private $localIp;
    
    public function __construct() {
        $this->detectAddresses();
    }
    
    /**
     * Detect local and public IP addresses
     */
    private function detectAddresses() {
        // Get client's IP address
        $this->publicIp = $this->getClientIp();
        
        // Get server's local IP
        $this->localIp = $this->getLocalIp();
    }
    
    /**
     * Get client's IP address
     */
    private function getClientIp() {
        // Check for IP from various headers
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                
                // If not public, still return it for local networks
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '127.0.0.1';
    }
    
    /**
     * Get server's local IP address
     */
    private function getLocalIp() {
        // Try various methods to get local IP without sockets extension
        
        // Method 1: Use SERVER_ADDR if available
        if (!empty($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1') {
            return $_SERVER['SERVER_ADDR'];
        }
        
        // Method 2: Use gethostbyname
        $hostname = gethostname();
        if ($hostname) {
            $ip = gethostbyname($hostname);
            if ($ip && $ip !== $hostname && $ip !== '127.0.0.1') {
                return $ip;
            }
        }
        
        // Method 3: Try to get from HTTP_HOST
        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
            // Remove port if present
            $host = explode(':', $host)[0];
            
            // If it's an IP address, use it
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return $host;
            }
            
            // Try to resolve hostname to IP
            $ip = gethostbyname($host);
            if ($ip && $ip !== $host) {
                return $ip;
            }
        }
        
        // Method 4: Get local network IP using PHP streams (fallback)
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'ignore_errors' => true
                ]
            ]);
            
            // Try to connect to a local service to determine local IP
            $localIp = '192.168.1.1'; // Common router IP
            
            // This won't actually connect but might help determine routing
            $sock = @fsockopen($localIp, 80, $errno, $errstr, 1);
            if ($sock) {
                $socketInfo = stream_get_meta_data($sock);
                fclose($sock);
                // This method is limited but safe
            }
        } catch (Exception $e) {
            // Ignore stream errors
        }
        
        // Default fallback
        return '127.0.0.1';
    }
    
    /**
     * Generate STUN binding response
     */
    public function getBindingResponse() {
        $action = $_GET['action'] ?? 'binding';
        
        switch ($action) {
            case 'binding':
                return $this->generateBindingResponse();
            case 'discover':
                return $this->generateDiscoveryResponse();
            case 'candidates':
                return $this->generateCandidatesResponse();
            default:
                return $this->generateErrorResponse('Unknown action');
        }
    }
    
    /**
     * Generate binding response (main STUN functionality)
     */
    private function generateBindingResponse() {
        $response = [
            'success' => true,
            'type' => 'binding_response',
            'mapped_address' => [
                'ip' => $this->publicIp,
                'port' => $_SERVER['REMOTE_PORT'] ?? 0,
                'family' => 'IPv4'
            ],
            'source_address' => [
                'ip' => $this->localIp,
                'port' => $_SERVER['SERVER_PORT'] ?? 80,
                'family' => 'IPv4'
            ],
            'server' => [
                'software' => 'PHP-STUN-Server/1.0',
                'timestamp' => time()
            ]
        ];
        
        return $response;
    }
    
    /**
     * Generate discovery response for network topology
     */
    private function generateDiscoveryResponse() {
        $response = [
            'success' => true,
            'type' => 'discovery_response',
            'network_info' => [
                'client_ip' => $this->publicIp,
                'server_ip' => $this->localIp,
                'server_port' => $_SERVER['SERVER_PORT'] ?? 80,
                'protocol' => $_SERVER['REQUEST_SCHEME'] ?? 'http',
                'nat_type' => $this->detectNatType(),
                'local_candidates' => $this->generateLocalCandidates()
            ],
            'timestamp' => time()
        ];
        
        return $response;
    }
    
    /**
     * Generate candidates for WebRTC
     */
    private function generateCandidatesResponse() {
        $candidates = $this->generateLocalCandidates();
        
        $response = [
            'success' => true,
            'type' => 'candidates_response',
            'candidates' => $candidates,
            'timestamp' => time()
        ];
        
        return $response;
    }
    
    /**
     * Detect NAT type (simplified)
     */
    private function detectNatType() {
        if ($this->publicIp === $this->localIp) {
            return 'No NAT';
        }
        
        if ($this->isPrivateIp($this->publicIp)) {
            return 'Symmetric NAT';
        }
        
        return 'Cone NAT';
    }
    
    /**
     * Check if IP is private
     */
    private function isPrivateIp($ip) {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    
    /**
     * Generate local ICE candidates
     */
    private function generateLocalCandidates() {
        $candidates = [];
        
        // Host candidate (local IP)
        $candidates[] = [
            'type' => 'host',
            'ip' => $this->localIp,
            'port' => $_SERVER['SERVER_PORT'] ?? 80,
            'priority' => 2130706431,
            'foundation' => 'host_' . md5($this->localIp),
            'component' => 1
        ];
        
        // Server reflexive candidate (if different from local)
        if ($this->publicIp !== $this->localIp) {
            $candidates[] = [
                'type' => 'srflx',
                'ip' => $this->publicIp,
                'port' => $_SERVER['REMOTE_PORT'] ?? 0,
                'priority' => 1694498815,
                'foundation' => 'srflx_' . md5($this->publicIp),
                'component' => 1,
                'related_address' => $this->localIp,
                'related_port' => $_SERVER['SERVER_PORT'] ?? 80
            ];
        }
        
        return $candidates;
    }
    
    /**
     * Generate error response
     */
    private function generateErrorResponse($message) {
        return [
            'success' => false,
            'error' => $message,
            'timestamp' => time()
        ];
    }
}

// Handle the request
try {
    $stunServer = new LocalStunServer();
    $response = $stunServer->getBindingResponse();
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'STUN server error: ' . $e->getMessage(),
        'timestamp' => time()
    ], JSON_PRETTY_PRINT);
}
?>