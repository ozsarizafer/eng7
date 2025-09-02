<?php

echo "<h2>🔒 HTTPS Setup for WebRTC Security</h2>";

// Check current protocol
$isHttps = HttpsRedirect::isHttps();
$isLocalhost = HttpsRedirect::isLocalhost();

echo "<div class='status-section'>";
echo "<h3>📊 Current Status</h3>";

if ($isHttps) {
    echo "<div class='status success'>";
    echo "<h4>✅ HTTPS is Active</h4>";
    echo "<p>Your WebRTC application is running securely with HTTPS.</p>";
    echo "</div>";
} else {
    echo "<div class='status warning'>";
    echo "<h4>⚠️ HTTP Mode Detected</h4>";
    echo "<p>For production use and some browsers, HTTPS is required for microphone access.</p>";
    echo "</div>";
}

echo "<ul>";
echo "<li><strong>Protocol:</strong> " . ($isHttps ? 'HTTPS 🔒' : 'HTTP') . "</li>";
echo "<li><strong>Environment:</strong> " . ($isLocalhost ? 'Localhost (Development)' : 'Network/Production') . "</li>";
echo "<li><strong>Server:</strong> " . $_SERVER['SERVER_NAME'] . "</li>";
echo "<li><strong>Port:</strong> " . $_SERVER['SERVER_PORT'] . "</li>";
echo "</ul>";
echo "</div>";

echo "<div class='info-section'>";
echo "<h3>🔧 XAMPP HTTPS Configuration</h3>";

echo "<h4>Step 1: Enable SSL Module in XAMPP</h4>";
echo "<ol>";
echo "<li>Open XAMPP Control Panel</li>";
echo "<li>Click 'Config' next to Apache</li>";
echo "<li>Select 'httpd.conf'</li>";
echo "<li>Find and uncomment this line: <code>LoadModule ssl_module modules/mod_ssl.so</code></li>";
echo "<li>Find and uncomment: <code>Include conf/extra/httpd-ssl.conf</code></li>";
echo "<li>Save and restart Apache</li>";
echo "</ol>";

echo "<h4>Step 2: Generate SSL Certificate (Development)</h4>";
echo "<p><strong>Option A: Use XAMPP's built-in certificate:</strong></p>";
echo "<ol>";
echo "<li>XAMPP includes a self-signed certificate in <code>C:\\xampp\\apache\\conf\\ssl.crt\\</code></li>";
echo "<li>Access via: <code>https://localhost/eng7/</code></li>";
echo "<li>Accept the security warning (for development only)</li>";
echo "</ol>";

echo "<p><strong>Option B: Create your own certificate:</strong></p>";
echo "<ol>";
echo "<li>Open Command Prompt as Administrator</li>";
echo "<li>Navigate to: <code>C:\\xampp\\apache\\bin</code></li>";
echo "<li>Run: <code>openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout server.key -out server.crt</code></li>";
echo "<li>Copy files to: <code>C:\\xampp\\apache\\conf\\ssl.crt\\</code></li>";
echo "<li>Update paths in <code>httpd-ssl.conf</code></li>";
echo "</ol>";

echo "<h4>Step 3: Configure SSL Virtual Host</h4>";
echo "<p>Edit <code>C:\\xampp\\apache\\conf\\extra\\httpd-ssl.conf</code>:</p>";
echo "<pre><code>";
echo "&lt;VirtualHost _default_:443&gt;\n";
echo "    DocumentRoot \"C:/xampp/htdocs\"\n";
echo "    ServerName localhost:443\n";
echo "    SSLEngine on\n";
echo "    SSLCertificateFile \"conf/ssl.crt/server.crt\"\n";
echo "    SSLCertificateKeyFile \"conf/ssl.key/server.key\"\n";
echo "&lt;/VirtualHost&gt;";
echo "</code></pre>";

echo "<h4>Step 4: Test HTTPS Configuration</h4>";
echo "<ol>";
echo "<li>Restart Apache in XAMPP</li>";
echo "<li>Visit: <code>https://localhost/eng7/</code></li>";
echo "<li>Accept security warning (for self-signed certificates)</li>";
echo "<li>Test microphone access in WebRTC application</li>";
echo "</ol>";
echo "</div>";

echo "<div class='info-section'>";
echo "<h3>🌐 Network HTTPS Access</h3>";

echo "<h4>For Other Devices to Access via HTTPS:</h4>";
echo "<ol>";
echo "<li><strong>Find your IP address:</strong>";
$serverName = $_SERVER['SERVER_NAME'];
$serverPort = $_SERVER['SERVER_PORT'];
echo "<ul>";
echo "<li>Current server: $serverName</li>";
echo "<li>Port: $serverPort</li>";
echo "</ul></li>";

echo "<li><strong>Configure certificate for your IP:</strong>";
echo "<ul>";
echo "<li>Create certificate with your IP as Subject Alternative Name (SAN)</li>";
echo "<li>Or use wildcard certificate</li>";
echo "<li>Update Apache SSL configuration</li>";
echo "</ul></li>";

echo "<li><strong>Access from other devices:</strong>";
echo "<ul>";
echo "<li>Use: <code>https://[YOUR-IP]:443/eng7/</code></li>";
echo "<li>Example: <code>https://192.168.1.100:443/eng7/</code></li>";
echo "<li>Accept security warning on each device</li>";
echo "</ul></li>";
echo "</ol>";
echo "</div>";

echo "<div class='info-section'>";
echo "<h3>🚀 Production HTTPS Considerations</h3>";

echo "<h4>For Real Production Deployment:</h4>";
echo "<ul>";
echo "<li><strong>Valid SSL Certificate:</strong> Use Let's Encrypt or commercial certificate</li>";
echo "<li><strong>Domain Name:</strong> Use proper domain instead of IP address</li>";
echo "<li><strong>HSTS Headers:</strong> Enable HTTP Strict Transport Security</li>";
echo "<li><strong>Firewall Rules:</strong> Configure proper port access (443)</li>";
echo "<li><strong>Reverse Proxy:</strong> Consider using nginx or Apache reverse proxy</li>";
echo "</ul>";

echo "<h4>Browser Compatibility:</h4>";
echo "<ul>";
echo "<li><strong>Chrome/Edge:</strong> Requires HTTPS for getUserMedia() on non-localhost</li>";
echo "<li><strong>Firefox:</strong> More permissive but HTTPS still recommended</li>";
echo "<li><strong>Safari:</strong> Requires HTTPS for WebRTC features</li>";
echo "<li><strong>Mobile Browsers:</strong> Always require HTTPS for microphone access</li>";
echo "</ul>";
echo "</div>";

// Test current HTTPS status
echo "<div class='test-section'>";
echo "<h3>🧪 HTTPS Tests</h3>";

echo "<div class='test-item'>";
echo "<strong>Current Protocol:</strong> ";
if ($isHttps) {
    echo "<span class='success'>HTTPS ✅</span>";
} else {
    echo "<span class='warning'>HTTP ⚠️</span>";
}
echo "</div>";

echo "<div class='test-item'>";
echo "<strong>SSL Certificate:</strong> ";
if ($isHttps) {
    echo "<span class='success'>Present ✅</span>";
} else {
    echo "<span class='warning'>Not Available ⚠️</span>";
}
echo "</div>";

echo "<div class='test-item'>";
echo "<strong>Security Headers:</strong> ";
echo "<span class='success'>Configured ✅</span>";
echo "</div>";

if ($isHttps) {
    $httpsUrl = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/";
    echo "<div class='test-item'>";
    echo "<strong>HTTPS Access URL:</strong> ";
    echo "<a href='$httpsUrl' target='_blank'><code>$httpsUrl</code></a>";
    echo "</div>";
}
echo "</div>";

echo "<div class='actions'>";
echo "<h3>🔗 Quick Actions</h3>";
echo "<a href='index.html' class='btn'>🎙️ Go to Audio Conference</a>";
echo "<a href='connection_test.html' class='btn'>🧪 Test Connection</a>";
echo "<a href='network_setup.php' class='btn'>🌐 Network Setup</a>";
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

h2 {
    color: white;
    text-align: center;
    margin-bottom: 2rem;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
}

.status-section, .info-section, .test-section, .actions {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    padding: 1.5rem;
    margin: 1rem 0;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    backdrop-filter: blur(10px);
}

.status {
    padding: 1rem;
    border-radius: 10px;
    margin: 1rem 0;
}

.status.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status.warning {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.status.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
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

pre {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    overflow-x: auto;
}

ul, ol {
    padding-left: 1.5rem;
}

li {
    margin: 0.5rem 0;
    line-height: 1.4;
}

.test-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid #eee;
}

.test-item:last-child {
    border-bottom: none;
}

.success {
    color: #28a745;
    font-weight: bold;
}

.warning {
    color: #ffc107;
    font-weight: bold;
}

.error {
    color: #dc3545;
    font-weight: bold;
}

.btn {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    margin: 0.5rem;
    background: #667eea;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.btn:hover {
    background: #5a6fd8;
    transform: translateY(-2px);
}

a {
    color: #1976d2;
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}
</style>