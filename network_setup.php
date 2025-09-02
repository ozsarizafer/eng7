<?php

echo "<h2>🌐 Network Configuration for Multi-Device Access</h2>";

// Get server information
$serverName = $_SERVER['SERVER_NAME'];
$serverPort = $_SERVER['SERVER_PORT'];
$requestScheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$currentUrl = $requestScheme . '://' . $serverName;
if ($serverPort != 80 && $serverPort != 443) {
    $currentUrl .= ':' . $serverPort;
}
$currentUrl .= dirname($_SERVER['REQUEST_URI']);

echo "<div class='info-section'>";
echo "<h3>📍 Current Access Information</h3>";
echo "<p><strong>Current URL:</strong> <code>$currentUrl</code></p>";
echo "<p><strong>Server:</strong> $serverName</p>";
echo "<p><strong>Port:</strong> $serverPort</p>";
echo "</div>";

echo "<div class='info-section'>";
echo "<h3>🔧 Network Setup Instructions</h3>";

echo "<h4>Step 1: Find Your Computer's IP Address</h4>";
echo "<p><strong>Windows:</strong></p>";
echo "<ul>";
echo "<li>Open Command Prompt (cmd)</li>";
echo "<li>Type: <code>ipconfig</code></li>";
echo "<li>Look for 'IPv4 Address' under your active network adapter</li>";
echo "<li>Usually looks like: 192.168.1.xxx or 10.0.0.xxx</li>";
echo "</ul>";

echo "<p><strong>Alternative method:</strong></p>";
echo "<ul>";
echo "<li>Go to Windows Settings > Network & Internet > Status</li>";
echo "<li>Click 'Properties' under your network connection</li>";
echo "<li>Find the IPv4 address</li>";
echo "</ul>";

echo "<h4>Step 2: Configure Windows Firewall</h4>";
echo "<p>Allow Apache through Windows Firewall:</p>";
echo "<ul>";
echo "<li>Open Windows Defender Firewall</li>";
echo "<li>Click 'Allow an app or feature through Windows Defender Firewall'</li>";
echo "<li>Look for 'Apache HTTP Server' and check both Private and Public</li>";
echo "<li>If not listed, click 'Allow another app' and browse to: <code>C:\\xampp\\apache\\bin\\httpd.exe</code></li>";
echo "</ul>";

echo "<h4>Step 3: Test Network Access</h4>";
echo "<p>Once you have your IP address (e.g., 192.168.1.100), other devices can access:</p>";
echo "<ul>";
echo "<li><strong>Audio Conference:</strong> <code>http://[YOUR-IP]:$serverPort/eng7/</code></li>";
echo "<li><strong>Database Setup:</strong> <code>http://[YOUR-IP]:$serverPort/eng7/setup_db.php</code></li>";
echo "<li><strong>Network Test:</strong> <code>http://[YOUR-IP]:$serverPort/eng7/network_setup.php</code></li>";
echo "</ul>";

echo "<h4>Step 4: Share with Other Devices</h4>";
echo "<p>Send the URL to other participants:</p>";
echo "<ul>";
echo "<li>Smartphones, tablets, laptops on the same WiFi network</li>";
echo "<li>Each device opens the URL in their web browser</li>";
echo "<li>Up to 4 participants can join the same audio conference room</li>";
echo "</ul>";
echo "</div>";

// Try to detect possible local IP addresses
echo "<div class='info-section'>";
echo "<h3>🔍 Detected Network Information</h3>";

// Get server's IP addresses
$localIps = [];

// Method 1: Try to get local IP from $_SERVER
if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1') {
    $localIps[] = $_SERVER['SERVER_ADDR'];
}

// Method 2: Try to get from hostname
$hostname = gethostname();
$hostIp = gethostbyname($hostname);
if ($hostIp !== $hostname && $hostIp !== '127.0.0.1') {
    $localIps[] = $hostIp;
}

// Method 3: Try to parse ipconfig output (Windows)
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $output = shell_exec('ipconfig');
    if ($output) {
        preg_match_all('/IPv4 Address[.\s]*:\s*([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})/', $output, $matches);
        if (isset($matches[1])) {
            foreach ($matches[1] as $ip) {
                if ($ip !== '127.0.0.1' && !in_array($ip, $localIps)) {
                    $localIps[] = $ip;
                }
            }
        }
    }
}

$localIps = array_unique($localIps);

if (!empty($localIps)) {
    echo "<p><strong>Possible IP addresses for network access:</strong></p>";
    echo "<ul>";
    foreach ($localIps as $ip) {
        $networkUrl = "$requestScheme://$ip";
        if ($serverPort != 80 && $serverPort != 443) {
            $networkUrl .= ":$serverPort";
        }
        $networkUrl .= "/eng7/";
        
        echo "<li>";
        echo "<strong>$ip:</strong> ";
        echo "<a href='$networkUrl' target='_blank'><code>$networkUrl</code></a>";
        echo "</li>";
    }
    echo "</ul>";
} else {
    echo "<p><em>Could not auto-detect IP addresses. Please check manually using ipconfig command.</em></p>";
}
echo "</div>";

echo "<div class='info-section success'>";
echo "<h3>✅ Quick Network Test</h3>";
echo "<p>To test if network access is working:</p>";
echo "<ol>";
echo "<li>Find your IP address using the methods above</li>";
echo "<li>Open a web browser on another device (phone, tablet, etc.)</li>";
echo "<li>Go to: <code>http://[YOUR-IP]:$serverPort/eng7/network_setup.php</code></li>";
echo "<li>If you see this page, network access is working! 🎉</li>";
echo "<li>Then access the audio conference at: <code>http://[YOUR-IP]:$serverPort/eng7/</code></li>";
echo "</ol>";
echo "</div>";

echo "<div class='info-section warning'>";
echo "<h3>⚠️ Troubleshooting</h3>";
echo "<ul>";
echo "<li><strong>Connection refused:</strong> Check Windows Firewall settings</li>";
echo "<li><strong>Page not loading:</strong> Verify XAMPP Apache is running</li>";
echo "<li><strong>Wrong IP:</strong> Make sure you're using the correct network IP (not 127.0.0.1)</li>";
echo "<li><strong>Different network:</strong> All devices must be on the same WiFi/network</li>";
echo "</ul>";
echo "</div>";

?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    margin: 0;
}

.info-section {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    padding: 1.5rem;
    margin: 1rem 0;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    backdrop-filter: blur(10px);
}

.info-section.success {
    border-left: 5px solid #4CAF50;
    background: rgba(212, 237, 218, 0.95);
}

.info-section.warning {
    border-left: 5px solid #FF9800;
    background: rgba(255, 235, 187, 0.95);
}

h2 {
    color: white;
    text-align: center;
    margin-bottom: 2rem;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
}

h3 {
    color: #333;
    border-bottom: 2px solid #eee;
    padding-bottom: 0.5rem;
    margin-bottom: 1rem;
}

h4 {
    color: #555;
    margin-top: 1.5rem;
    margin-bottom: 0.5rem;
}

code {
    background: #f1f3f4;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-family: 'Consolas', 'Monaco', monospace;
    color: #1976d2;
    word-break: break-all;
}

ul, ol {
    padding-left: 1.5rem;
}

li {
    margin: 0.5rem 0;
    line-height: 1.4;
}

a {
    color: #1976d2;
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}

.back-link {
    position: fixed;
    top: 20px;
    right: 20px;
    background: rgba(255, 255, 255, 0.9);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    text-decoration: none;
    color: #333;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.back-link:hover {
    background: white;
    transform: translateY(-2px);
}
</style>

<a href="index.html" class="back-link">🎙️ Go to Audio Conference</a>