<?php

class HttpsRedirect {
    
    /**
     * Force HTTPS redirect for WebRTC security requirements
     */
    public static function forceHttps($excludeLocalhost = true) {
        // Check if we're already on HTTPS
        if (self::isHttps()) {
            return;
        }
        
        // Skip redirect for localhost during development if specified
        if ($excludeLocalhost && self::isLocalhost()) {
            return;
        }
        
        // Skip redirect for API endpoints to avoid breaking AJAX calls
        if (self::isApiRequest()) {
            return;
        }
        
        // Perform HTTPS redirect
        $httpsUrl = self::getHttpsUrl();
        
        // Send security headers
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        
        // Redirect to HTTPS
        header("Location: $httpsUrl", true, 301);
        exit();
    }
    
    /**
     * Check if current request is HTTPS
     */
    public static function isHttps() {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            $_SERVER['SERVER_PORT'] == 443 ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        );
    }
    
    /**
     * Check if request is from localhost
     */
    public static function isLocalhost() {
        $localhost_ips = ['127.0.0.1', '::1', 'localhost'];
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        
        return in_array($server_name, $localhost_ips) || 
               in_array($remote_addr, $localhost_ips) ||
               $server_name === 'localhost';
    }
    
    /**
     * Get current URL with HTTPS protocol
     */
    public static function getHttpsUrl() {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Handle non-standard HTTPS ports
        $port = $_SERVER['SERVER_PORT'] ?? 443;
        if ($port != 443 && $port != 80) {
            // For XAMPP with custom HTTPS port
            if (strpos($host, ':') === false) {
                $host .= ':' . $port;
            }
        }
        
        return "https://$host$uri";
    }
    
    /**
     * Set secure headers for WebRTC
     */
    public static function setSecureHeaders() {
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // WebRTC specific security headers
        header('Permissions-Policy: camera=(), microphone=(self), geolocation=()');
    }
    
    /**
     * Check if request is for API endpoints
     */
    public static function isApiRequest() {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        // Check if it's an API file or has API parameters
        return strpos($requestUri, 'api.php') !== false ||
               strpos($requestUri, 'test_api.php') !== false ||
               strpos($scriptName, 'api.php') !== false ||
               strpos($scriptName, 'test_api.php') !== false ||
               isset($_GET['action']);
    }
    
    /**
     * Check if HTTPS is available and configured
     */
    public static function checkHttpsAvailability() {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        $port = 443;
        
        // Try to connect to HTTPS port
        $connection = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($connection) {
            fclose($connection);
            return true;
        }
        
        return false;
    }
}