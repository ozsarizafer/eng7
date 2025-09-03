class AudioConferenceClient {
    constructor() {
        this.peerId = this.generatePeerId();
        this.roomId = 'default';
        this.username = '';
        this.localStream = null;
        this.peerConnections = new Map(); // Store multiple peer connections
        this.remoteStreams = new Map(); // Store remote audio streams
        this.eventSource = null;
        this.isConnected = false;
        this.isMuted = false;
        this.existingPeers = [];
        this.connectionVersion = 0; // Track connection version for ICE candidate validation
        
        // Get the current host and port for API calls
        this.apiBase = this.getApiBase();
        
        // WebRTC configuration with local PHP STUN server
        this.rtcConfig = {
            iceServers: [
                // Primary: Local PHP STUN server
                { urls: `stun:${window.location.hostname}:${window.location.port || (window.location.protocol === 'https:' ? 443 : 80)}` },
                // Fallback: One external STUN server for redundancy
                { urls: 'stun:stun.l.google.com:19302' }
            ]
        };
        
        // Initialize local STUN server configuration
        this.initializeLocalStun();

        this.initializeElements();
        this.bindEvents();
        this.updateUI();
        this.displayConnectionInfo();
    }

    initializeElements() {
        // Input elements
        this.usernameInput = document.getElementById('usernameInput');
        this.roomInput = document.getElementById('roomInput');
        
        // Button elements
        this.joinBtn = document.getElementById('joinBtn');
        this.newRoomBtn = document.getElementById('newRoomBtn');
        this.leaveBtn = document.getElementById('leaveBtn');
        this.muteBtn = document.getElementById('muteBtn');
        this.unmuteBtn = document.getElementById('unmuteBtn');
        
        // Audio elements
        this.localAudio = document.getElementById('localAudio');
        this.participantsGrid = document.getElementById('participantsGrid');
        this.localParticipant = document.getElementById('localParticipant');
        
        // Status elements
        this.statusIndicator = document.getElementById('statusIndicator');
        this.statusText = document.getElementById('statusText');
        this.peerIdDisplay = document.getElementById('peerIdDisplay');
        this.peersList = document.getElementById('peersList');
        this.messageContainer = document.getElementById('messageContainer');
        this.roomCapacity = document.getElementById('roomCapacity');
        this.currentRoom = document.getElementById('currentRoom');
        
        // Available rooms elements
        this.refreshRoomsBtn = document.getElementById('refreshRoomsBtn');
        this.availableRoomsList = document.getElementById('availableRoomsList');
        this.roomCount = document.querySelector('.room-count');

        // Set peer ID display
        this.peerIdDisplay.textContent = this.peerId;
    }

    bindEvents() {
        this.joinBtn.addEventListener('click', () => this.joinRoom());
        this.newRoomBtn.addEventListener('click', () => this.createNewRoom());
        this.leaveBtn.addEventListener('click', () => this.leaveRoom());
        this.muteBtn.addEventListener('click', () => this.muteAudio());
        this.unmuteBtn.addEventListener('click', () => this.unmuteAudio());
        this.refreshRoomsBtn.addEventListener('click', () => this.loadAvailableRooms());

        // Enhanced disconnect detection system
        this.setupDisconnectHandlers();
        
        // Load available rooms on page load
        this.loadAvailableRooms();
    }

    generatePeerId() {
        return 'peer_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
    }

    setupDisconnectHandlers() {
        // 1. Page navigation/close detection
        window.addEventListener('beforeunload', (event) => {
            if (this.isConnected) {
                // Use sendBeacon for reliable leave notification
                const leaveData = JSON.stringify({
                    roomId: this.roomId,
                    peerId: this.peerId,
                    reason: 'page_unload'
                });
                
                try {
                    navigator.sendBeacon(
                        this.apiBase + 'api.php?action=leave',
                        new Blob([leaveData], { type: 'application/json' })
                    );
                } catch (error) {
                    console.log('Beacon failed, trying sync request');
                    // Fallback to synchronous request
                    this.syncLeaveRoom();
                }
            }
        });

        // 2. Page hide/show detection (tab switching, mobile background)
        window.addEventListener('pagehide', () => {
            if (this.isConnected) {
                const leaveData = JSON.stringify({
                    roomId: this.roomId,
                    peerId: this.peerId,
                    reason: 'page_hide'
                });
                
                navigator.sendBeacon(
                    this.apiBase + 'api.php?action=leave',
                    new Blob([leaveData], { type: 'application/json' })
                );
            }
        });

        // 3. Visibility change with inactivity timeout
        let inactivityTimer = null;
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Start inactivity timer when tab becomes hidden
                inactivityTimer = setTimeout(() => {
                    if (this.isConnected && document.hidden) {
                        console.log('🕐 Tab inactive for 30 seconds, leaving room');
                        this.leaveRoom('tab_inactive');
                    }
                }, 30000); // 30 seconds timeout
            } else {
                // Clear timer when tab becomes visible again
                if (inactivityTimer) {
                    clearTimeout(inactivityTimer);
                    inactivityTimer = null;
                }
            }
        });

        // 4. Network connectivity detection
        window.addEventListener('online', () => {
            console.log('🌐 Network reconnected');
            if (this.isConnected && this.eventSource?.readyState !== EventSource.OPEN) {
                console.log('🔄 Restarting SSE connection');
                this.startSignaling();
            }
        });

        window.addEventListener('offline', () => {
            console.log('📵 Network disconnected');
            // Don't leave room immediately, wait for reconnection
        });

        // 5. Heartbeat mechanism for connection monitoring
        this.startHeartbeat();
    }

    syncLeaveRoom() {
        // Synchronous leave request for beforeunload scenarios
        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.apiBase + 'api.php?action=leave', false); // false = synchronous
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(JSON.stringify({
                roomId: this.roomId,
                peerId: this.peerId,
                reason: 'sync_leave'
            }));
        } catch (error) {
            console.log('Sync leave failed:', error);
        }
    }

    startHeartbeat() {
        // Send heartbeat every 10 seconds when connected
        this.heartbeatInterval = setInterval(() => {
            if (this.isConnected && navigator.onLine) {
                fetch(this.apiBase + 'api.php?action=heartbeat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        peerId: this.peerId,
                        roomId: this.roomId
                    })
                }).catch(error => {
                    console.log('Heartbeat failed:', error);
                    // After 3 failed heartbeats, consider connection lost
                    this.heartbeatFailCount = (this.heartbeatFailCount || 0) + 1;
                    if (this.heartbeatFailCount >= 3) {
                        console.log('💔 Connection lost after 3 failed heartbeats');
                        this.handleConnectionLoss();
                    }
                });
            }
        }, 10000);
    }

    handleConnectionLoss() {
        console.log('🔌 Handling connection loss');
        this.isConnected = false;
        this.updateStatus('disconnected', 'Connection lost');
        this.resetConnectionState();
        this.showMessage('Connection lost. Please rejoin the room.', 'error');
        this.updateUI();
        
        // Stop heartbeat
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    }

    async initializeLocalStun() {
        try {
            // Test local STUN server configuration
            const stunConfigUrl = this.apiBase + 'stun_config.php?action=config';
            const response = await fetch(stunConfigUrl);
            
            if (response.ok) {
                const config = await response.json();
                
                if (config.success && config.webrtc_config) {
                    // Update RTCConfiguration with local STUN server
                    this.rtcConfig = {
                        iceServers: [
                            // Local STUN server
                            ...config.webrtc_config.iceServers,
                            // Keep one external fallback
                            { urls: 'stun:stun.l.google.com:19302' }
                        ]
                    };
                    
                    console.log('🏠 Local STUN server configured:', config.server_info.stun_endpoint);
                    console.log('🔗 WebRTC Config:', this.rtcConfig);
                } else {
                    console.warn('⚠️ Local STUN server config failed, using fallback');
                }
            } else {
                console.warn('⚠️ Could not reach local STUN server, using fallback');
            }
        } catch (error) {
            console.warn('⚠️ Local STUN server initialization failed:', error.message);
            console.log('📡 Using fallback STUN configuration');
        }
    }

    getApiBase() {
        // Get current protocol, hostname and port
        let protocol = window.location.protocol;
        const hostname = window.location.hostname;
        const port = window.location.port;
        
        // Force HTTPS for production (non-localhost) environments
        if (hostname !== 'localhost' && hostname !== '127.0.0.1' && protocol === 'http:') {
            protocol = 'https:';
        }
        
        // Construct base URL for API calls
        let apiBase = `${protocol}//${hostname}`;
        
        // Handle port configuration
        if (port) {
            // For HTTPS, include port if not 443
            // For HTTP, include port if not 80
            if ((protocol === 'https:' && port !== '443') || 
                (protocol === 'http:' && port !== '80')) {
                apiBase += `:${port}`;
            }
        }
        
        // Add the path to the eng7 directory
        const pathname = window.location.pathname;
        const basePath = pathname.substring(0, pathname.lastIndexOf('/') + 1);
        apiBase += basePath;
        
        return apiBase;
    }

    displayConnectionInfo() {
        // Display connection information for other devices
        let protocol = window.location.protocol;
        const hostname = window.location.hostname;
        const port = window.location.port;
        
        // Prefer HTTPS for network access URLs
        if (hostname !== 'localhost' && hostname !== '127.0.0.1' && protocol === 'http:') {
            protocol = 'https:';
        }
        
        let accessUrl = `${protocol}//${hostname}`;
        if (port) {
            if ((protocol === 'https:' && port !== '443') || 
                (protocol === 'http:' && port !== '80')) {
                accessUrl += `:${port}`;
            }
        }
        accessUrl += window.location.pathname;
        
        // Get local IP address hint
        this.getLocalIpHint();
        
        console.log('🌐 Audio Conference Access Info:');
        console.log(`📍 Current URL: ${accessUrl}`);
        console.log(`🔗 Share this URL with other participants on the same network`);
        console.log(`💡 For external access, replace ${hostname} with your computer's IP address`);
        
        // Check if HTTPS is being enforced
        if (protocol === 'https:') {
            console.log('🔒 HTTPS enabled for secure WebRTC communication');
        } else {
            console.log('⚠️ HTTP mode - HTTPS recommended for production');
        }
    }

    async getLocalIpHint() {
        try {
            // Try to get a hint about local IP using local STUN server
            const localStunUrl = `stun:${window.location.hostname}:${window.location.port || (window.location.protocol === 'https:' ? 443 : 80)}`;
            const pc = new RTCPeerConnection({ iceServers: [{ urls: localStunUrl }] });
            pc.createDataChannel('');
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            
            pc.onicecandidate = (event) => {
                if (event.candidate) {
                    const candidate = event.candidate.candidate;
                    const ipMatch = candidate.match(/([0-9]{1,3}(\.[0-9]{1,3}){3}|[a-f0-9]{1,4}(:[a-f0-9]{1,4}){7})/);
                    if (ipMatch && !ipMatch[1].startsWith('127.') && !ipMatch[1].startsWith('169.254.')) {
                        const localIp = ipMatch[1];
                        let protocol = window.location.protocol;
                        const port = window.location.port;
                        
                        // Use HTTPS for network URLs if not localhost
                        if (protocol === 'http:' && localIp !== '127.0.0.1') {
                            protocol = 'https:';
                        }
                        
                        let suggestedUrl = `${protocol}//${localIp}`;
                        if (port) {
                            if ((protocol === 'https:' && port !== '443') || 
                                (protocol === 'http:' && port !== '80')) {
                                suggestedUrl += `:${port}`;
                            }
                        }
                        suggestedUrl += window.location.pathname;
                        
                        console.log(`🔍 Detected local IP: ${localIp}`);
                        console.log(`📲 Suggested URL for other devices: ${suggestedUrl}`);
                        console.log(`🏠 Using local STUN server: ${localStunUrl}`);
                        
                        // Display this info in the UI
                        this.showNetworkInfo(localIp, suggestedUrl);
                        
                        pc.close();
                        return;
                    }
                }
            };
            
            // Close connection after 3 seconds if no suitable candidate found
            setTimeout(() => pc.close(), 3000);
            
        } catch (error) {
            console.log('Could not detect local IP automatically, trying fallback STUN...');
            // Fallback to external STUN if local fails
            try {
                const pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
                pc.createDataChannel('');
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                
                pc.onicecandidate = (event) => {
                    if (event.candidate) {
                        const candidate = event.candidate.candidate;
                        const ipMatch = candidate.match(/([0-9]{1,3}(\.[0-9]{1,3}){3})/);
                        if (ipMatch && !ipMatch[1].startsWith('127.') && !ipMatch[1].startsWith('169.254.')) {
                            console.log(`🔍 Detected local IP (fallback): ${ipMatch[1]}`);
                            pc.close();
                        }
                    }
                };
                
                setTimeout(() => pc.close(), 3000);
            } catch (fallbackError) {
                console.log('IP detection failed completely');
            }
        }
    }

    showNetworkInfo(localIp, suggestedUrl) {
        // Create or update network info display
        let networkInfo = document.getElementById('networkInfo');
        if (!networkInfo) {
            networkInfo = document.createElement('div');
            networkInfo.id = 'networkInfo';
            networkInfo.className = 'network-info';
            
            // Insert after the header
            const header = document.querySelector('.header');
            header.insertAdjacentElement('afterend', networkInfo);
        }
        
        networkInfo.innerHTML = `
            <h4>🌐 Network Access Information</h4>
            <p><strong>Local Access:</strong> <code>${window.location.href}</code></p>
            <p><strong>Network Access:</strong> <code>${suggestedUrl}</code></p>
            <p><small>💡 Share the network access URL with other devices on the same WiFi/network</small></p>
        `;
    }

    async joinRoom() {
        this.username = this.usernameInput.value.trim() || 'Anonymous';
        this.roomId = this.roomInput.value.trim() || 'default';

        try {
            // Ensure clean state before joining
            if (this.isConnected) {
                await this.leaveRoom();
                // Wait a bit for cleanup to complete
                await new Promise(resolve => setTimeout(resolve, 500));
            }
            
            const response = await fetch(this.apiBase + 'api.php?action=join', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    roomId: this.roomId,
                    peerId: this.peerId,
                    username: this.username
                })
            });

            const result = await response.json();
            
            if (result.success) {
                this.isConnected = true;
                this.existingPeers = result.data.existingPeers || [];
                this.updateStatus('connected', 'Connected to room: ' + this.roomId);
                this.currentRoom.textContent = this.roomId;
                this.updateLocalParticipant();
                
                // Start signaling before audio to ensure we can receive offers
                this.startSignaling();
                this.loadPeers();
                
                // Start heartbeat mechanism
                this.heartbeatFailCount = 0;
                this.startHeartbeat();
                
                // Start audio and wait for it to be ready
                await this.startAudio();
                
                // Create connections to existing peers with staggered timing
                for (let i = 0; i < this.existingPeers.length; i++) {
                    const peer = this.existingPeers[i];
                    console.log(`🔗 Creating connection to existing peer: ${peer.peer_id}`);
                    await this.createOfferToPeer(peer.peer_id);
                    
                    // Staggered connection creation for reliability
                    if (i < this.existingPeers.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, 100));
                    }
                }
                
                this.showMessage('Successfully joined audio conference!', 'success');
                
                // Refresh room list to update participant counts
                setTimeout(() => this.loadAvailableRooms(), 1000);
            } else {
                // Enhanced error handling for different error types
                if (result.error && result.error.includes('constraint')) {
                    console.log('🔧 Foreign key constraint detected, attempting repair...');
                    this.showMessage('Database synchronization issue detected. Attempting automatic repair...', 'warning');
                    
                    try {
                        await this.repairDatabaseAndRetry();
                    } catch (repairError) {
                        this.showMessage('Failed to repair database. Please refresh the page or contact support.', 'error');
                    }
                } else if (result.error && result.error.includes('locked')) {
                    console.log('🔒 Database locked detected, attempting unlock and retry...');
                    this.showMessage('Database is temporarily locked. Attempting to unlock...', 'warning');
                    
                    try {
                        await this.unlockDatabaseAndRetry();
                    } catch (unlockError) {
                        this.showMessage('Failed to unlock database. Please try the manual unlock tool.', 'error');
                        this.showDatabaseLockHelp();
                    }
                } else {
                    this.showMessage('Failed to join room: ' + result.error, 'error');
                }
            }
        } catch (error) {
            console.error('Join room error:', error);
            this.showMessage('Connection error: ' + error.message, 'error');
        }

        this.updateUI();
    }
    
    async repairDatabaseAndRetry() {
        console.log('🔧 Attempting database repair and retry...');
        
        try {
            // Force cleanup to resolve foreign key issues
            const cleanupResponse = await fetch(this.apiBase + 'api.php?action=cleanup', {
                method: 'GET'
            });
            
            if (cleanupResponse.ok) {
                const cleanupResult = await cleanupResponse.json();
                console.log('🧩 Cleanup completed:', cleanupResult);
                
                // Wait a bit for cleanup to complete
                await new Promise(resolve => setTimeout(resolve, 1000));
                
                // Try joining again
                this.showMessage('Database repaired. Retrying room join...', 'info');
                
                const retryResponse = await fetch(this.apiBase + 'api.php?action=join', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        roomId: this.roomId,
                        peerId: this.peerId,
                        username: this.username
                    })
                });
                
                const retryResult = await retryResponse.json();
                
                if (retryResult.success) {
                    this.showMessage('✅ Successfully joined after repair!', 'success');
                    // Process successful join
                    this.isConnected = true;
                    this.existingPeers = retryResult.data.existingPeers || [];
                    this.updateStatus('connected', 'Connected to room: ' + this.roomId);
                    this.currentRoom.textContent = this.roomId;
                    this.updateLocalParticipant();
                    
                    this.startSignaling();
                    this.loadPeers();
                    this.heartbeatFailCount = 0;
                    this.startHeartbeat();
                    
                    await this.startAudio();
                    
                    // Create connections to existing peers
                    for (let i = 0; i < this.existingPeers.length; i++) {
                        const peer = this.existingPeers[i];
                        await this.createOfferToPeer(peer.peer_id);
                        if (i < this.existingPeers.length - 1) {
                            await new Promise(resolve => setTimeout(resolve, 100));
                        }
                    }
                    
                    setTimeout(() => this.loadAvailableRooms(), 1000);
                } else {
                    throw new Error('Retry failed: ' + retryResult.error);
                }
            } else {
                throw new Error('Cleanup request failed');
            }
        } catch (error) {
            console.error('🚨 Repair failed:', error);
            throw error;
        }
    }
    
    async unlockDatabaseAndRetry() {
        console.log('🔓 Attempting database unlock and retry...');
        
        try {
            // First, try to unlock the database using the unlock endpoint
            const unlockResponse = await fetch(this.apiBase + 'api.php?action=unlock', {
                method: 'POST'
            });
            
            if (unlockResponse.ok) {
                console.log('🔓 Database unlock completed');
                
                // Wait a bit for unlock to complete
                await new Promise(resolve => setTimeout(resolve, 2000));
                
                // Try joining again with exponential backoff
                for (let attempt = 1; attempt <= 3; attempt++) {
                    this.showMessage(`Database unlocked. Retry attempt ${attempt}/3...`, 'info');
                    
                    const retryResponse = await fetch(this.apiBase + 'api.php?action=join', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            roomId: this.roomId,
                            peerId: this.peerId,
                            username: this.username
                        })
                    });
                    
                    const retryResult = await retryResponse.json();
                    
                    if (retryResult.success) {
                        this.showMessage('✅ Successfully joined after database unlock!', 'success');
                        // Process successful join
                        this.isConnected = true;
                        this.existingPeers = retryResult.data.existingPeers || [];
                        this.updateStatus('connected', 'Connected to room: ' + this.roomId);
                        this.currentRoom.textContent = this.roomId;
                        this.updateLocalParticipant();
                        
                        this.startSignaling();
                        this.loadPeers();
                        this.heartbeatFailCount = 0;
                        this.startHeartbeat();
                        
                        await this.startAudio();
                        
                        // Create connections to existing peers
                        for (let i = 0; i < this.existingPeers.length; i++) {
                            const peer = this.existingPeers[i];
                            await this.createOfferToPeer(peer.peer_id);
                            if (i < this.existingPeers.length - 1) {
                                await new Promise(resolve => setTimeout(resolve, 100));
                            }
                        }
                        
                        setTimeout(() => this.loadAvailableRooms(), 1000);
                        return; // Success, exit retry loop
                    } else if (!retryResult.error.includes('locked')) {
                        // Different error, stop retrying
                        throw new Error('Retry failed with different error: ' + retryResult.error);
                    }
                    
                    // If still locked, wait before next attempt
                    if (attempt < 3) {
                        await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
                    }
                }
                
                throw new Error('Database remains locked after multiple retry attempts');
            } else {
                throw new Error('Unlock request failed');
            }
        } catch (error) {
            console.error('🚨 Database unlock failed:', error);
            throw error;
        }
    }
    
    showDatabaseLockHelp() {
        // Create help dialog for database lock issues
        const helpDiv = document.createElement('div');
        helpDiv.className = 'database-lock-help';
        helpDiv.innerHTML = `
            <div class="help-overlay">
                <div class="help-content">
                    <h3>🔒 Database Lock Error</h3>
                    <p>The database is temporarily locked. Here are your options:</p>
                    <ul>
                        <li><strong>Option 1:</strong> <a href="unlock_database.php" target="_blank">Run Database Unlock Tool</a></li>
                        <li><strong>Option 2:</strong> Wait 30 seconds and try again</li>
                        <li><strong>Option 3:</strong> Restart XAMPP Apache service</li>
                    </ul>
                    <p><strong>Advanced Fix:</strong> If the problem persists:</p>
                    <ol>
                        <li>Stop XAMPP Apache service</li>
                        <li>Delete .db-wal and .db-shm files in /data/ folder</li>
                        <li>Restart Apache</li>
                    </ol>
                    <button onclick="this.parentElement.parentElement.remove()">Close</button>
                </div>
            </div>
        `;
        
        // Add CSS if not already present
        if (!document.querySelector('.database-lock-help-styles')) {
            const style = document.createElement('style');
            style.className = 'database-lock-help-styles';
            style.textContent = `
                .help-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.7);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 10000;
                }
                .help-content {
                    background: white;
                    padding: 20px;
                    border-radius: 8px;
                    max-width: 500px;
                    max-height: 80vh;
                    overflow-y: auto;
                }
                .help-content h3 {
                    margin-top: 0;
                    color: #d32f2f;
                }
                .help-content button {
                    background: #1976d2;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 4px;
                    cursor: pointer;
                    margin-top: 15px;
                }
                .help-content button:hover {
                    background: #1565c0;
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(helpDiv);
    }

    async leaveRoom(reason = 'manual') {
        if (!this.isConnected) return;

        try {
            // Complete connection state reset
            this.resetConnectionState();

            // Notify server with disconnect reason
            await fetch(this.apiBase + 'api.php?action=leave', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    roomId: this.roomId,
                    peerId: this.peerId,
                    reason: reason
                })
            });

            this.isConnected = false;
            this.updateStatus('disconnected', 'Disconnected');
            this.currentRoom.textContent = '-';
            this.updateLocalParticipant();
            this.clearRemoteParticipants();
            this.showMessage('Left the conference', 'success');
            
            // Stop heartbeat
            if (this.heartbeatInterval) {
                clearInterval(this.heartbeatInterval);
                this.heartbeatInterval = null;
            }
            
            // Reset heartbeat fail count
            this.heartbeatFailCount = 0;
            
            // Refresh room list to update participant counts
            setTimeout(() => this.loadAvailableRooms(), 500);
            
        } catch (error) {
            console.error('Leave room error:', error);
        }

        this.updateUI();
    }

    resetConnectionState() {
        console.log('🔄 Resetting connection state for clean rejoin');
        
        // Increment connection version to invalidate stale ICE candidates
        this.connectionVersion++;
        
        console.log(`🔢 Connection version incremented to ${this.connectionVersion} (prevents ufrag errors)`);
        
        // Stop and close all media streams
        this.stopAllMedia();

        // Close all peer connections properly
        this.peerConnections.forEach((pc, peerId) => {
            console.log(`Closing connection to peer: ${peerId}`);
            if (pc.connectionState !== 'closed') {
                pc.close();
            }
        });
        this.peerConnections.clear();
        this.remoteStreams.clear();

        // Stop signaling
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        
        // Clear peer list
        this.existingPeers = [];
        
        // Clear enhanced error tracking
        if (this.ufragErrorCounts) {
            this.ufragErrorCounts.clear();
        }
        
        // Clear queued candidates
        if (this.queuedCandidates) {
            this.queuedCandidates.clear();
        }
        
        // Reset legacy ufrag error counter (for compatibility)
        this.ufragErrorCount = 0;
        
        console.log('✅ Connection state reset complete - all tracking data cleared');
    }

    async createNewRoom() {
        if (this.isConnected) {
            this.showMessage('Please leave current room before creating a new one', 'error');
            return;
        }

        try {
            // Create new room with 3-digit + timestamp format
            const response = await fetch(this.apiBase + 'api.php?action=create_room', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    roomName: '' // Let server generate default name
                })
            });

            const result = await response.json();
            
            if (result.success) {
                // Set the new room ID in the input field
                this.roomInput.value = result.data.roomId;
                this.roomId = result.data.roomId;
                
                this.showMessage(`New room created: ${result.data.roomName} (ID: ${result.data.roomId})`, 'success');
                
                // According to specification: automatically join the generated room
                await this.joinRoom();
                
                // Refresh room list to show the new room
                setTimeout(() => this.loadAvailableRooms(), 1000);
            } else {
                this.showMessage('Failed to create room: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('Create room error:', error);
            this.showMessage('Connection error: ' + error.message, 'error');
        }
    }

    startSignaling() {
        // Use Server-Sent Events for real-time signaling
        const eventUrl = `${this.apiBase}api.php?action=events&peerId=${this.peerId}`;
        this.eventSource = new EventSource(eventUrl);
        
        this.eventSource.onmessage = (event) => {
            try {
                const message = JSON.parse(event.data);
                this.handleSignalingMessage(message);
            } catch (error) {
                console.error('Failed to parse signaling message:', error);
            }
        };

        this.eventSource.onerror = (error) => {
            console.error('SSE error:', error);
            // Attempt to reconnect after 3 seconds
            setTimeout(() => {
                if (this.isConnected && (!this.eventSource || this.eventSource.readyState === EventSource.CLOSED)) {
                    this.startSignaling();
                }
            }, 3000);
        };
    }

    async handleSignalingMessage(message) {
        console.log('Received signaling message:', message);

        switch (message.type) {
            case 'peer-joined':
                this.handlePeerJoined(message.data);
                break;
            case 'peer-left':
                this.handlePeerLeft(message.data);
                break;
            case 'offer':
                await this.handleOffer(message);
                break;
            case 'answer':
                await this.handleAnswer(message);
                break;
            case 'ice-candidate':
                await this.handleIceCandidate(message);
                break;
            case 'room-list-update':
                this.handleRoomListUpdate(message.data);
                break;
        }
    }

    async handlePeerJoined(data) {
        this.showMessage(`${data.username} joined the conference`, 'success');
        this.loadPeers();
        
        // Implement peer ID-based offer collision resolution
        // Only the peer with the lexicographically smaller ID creates the offer
        const shouldCreateOffer = this.peerId < data.peerId;
        
        if (shouldCreateOffer) {
            console.log(`📤 Creating offer to ${data.peerId} (peer ID collision resolution)`);
            
            // If we have audio, create connection to new peer
            if (this.localStream && this.localStream.getTracks().length > 0) {
                await this.createOfferToPeer(data.peerId);
            } else {
                console.log('🎤 Starting audio before creating offer to new peer');
                try {
                    await this.startAudio();
                    await this.createOfferToPeer(data.peerId);
                } catch (error) {
                    console.error('❌ Failed to start audio for new peer:', error);
                    // Still try to create connection without audio
                    await this.createOfferToPeer(data.peerId);
                }
            }
        } else {
            console.log(`📥 Waiting for offer from ${data.peerId} (peer ID collision resolution)`);
            
            // Make sure we have audio ready for when we receive the offer
            if (!this.localStream || this.localStream.getTracks().length === 0) {
                console.log('🎤 Preparing audio for incoming offer');
                try {
                    await this.startAudio();
                } catch (error) {
                    console.error('❌ Failed to prepare audio:', error);
                }
            }
        }
    }

    handlePeerLeft(data) {
        this.showMessage(`Peer left the conference`, 'success');
        
        // Close connection to departed peer
        if (this.peerConnections.has(data.peerId)) {
            this.peerConnections.get(data.peerId).close();
            this.peerConnections.delete(data.peerId);
        }
        
        // Remove remote stream
        if (this.remoteStreams.has(data.peerId)) {
            this.remoteStreams.delete(data.peerId);
        }
        
        // Update UI immediately
        this.updateRemoteParticipants();
        this.loadPeers(); // Refresh peer count
        
        // Refresh room list to update participant counts for other rooms too
        setTimeout(() => this.loadAvailableRooms(), 500);
    }

    handleRoomListUpdate(data) {
        console.log('📋 Received room list update:', data);
        
        // Update the room list immediately without delay
        if (data.rooms && Array.isArray(data.rooms)) {
            this.updateRoomList(data.rooms);
        } else {
            // If data format is different, fall back to API call
            this.loadAvailableRooms();
        }
    }

    async handleOffer(message) {
        console.log('Handling offer from:', message.from);
        
        // Check if we have audio - if not, start it automatically
        if (!this.localStream || this.localStream.getTracks().length === 0) {
            console.log('🎤 No local audio stream when handling offer - starting audio automatically');
            try {
                await this.startAudio();
                console.log('✅ Audio started successfully for offer handling');
            } catch (error) {
                console.error('❌ Failed to start audio for offer handling:', error);
                // Continue without audio - connection still possible
            }
        }
        
        const peerConnection = await this.createPeerConnection(message.from);
        
        const offer = new RTCSessionDescription(message.data);
        await peerConnection.setRemoteDescription(offer);
        
        const answer = await peerConnection.createAnswer();
        await peerConnection.setLocalDescription(answer);
        
        // Send answer back
        await this.sendSignal(message.from, 'answer', answer);
        
        console.log('📞 Answer sent to:', message.from);
    }

    async handleAnswer(message) {
        console.log('Handling answer from:', message.from);
        
        const peerConnection = this.peerConnections.get(message.from);
        if (peerConnection) {
            const answer = new RTCSessionDescription(message.data);
            await peerConnection.setRemoteDescription(answer);
        }
    }

    async handleIceCandidate(message) {
        console.log('Handling ICE candidate from:', message.from);
        
        const peerConnection = this.peerConnections.get(message.from);
        if (!peerConnection || !message.data.candidate) {
            console.log(`⚠️ No valid peer connection found for ${message.from} or no candidate data`);
            return;
        }
        
        // Validate connection state before processing candidate
        if (peerConnection.connectionState === 'closed' || peerConnection.connectionState === 'failed') {
            console.log(`⚠️ Ignoring ICE candidate for ${message.from} - connection state: ${peerConnection.connectionState}`);
            return;
        }
        
        // Validate connection version to prevent stale candidates (fixes 'Unknown ufrag' error)
        if (peerConnection.connectionVersion !== this.connectionVersion) {
            console.log(`⚠️ Ignoring stale ICE candidate for ${message.from} - version mismatch (expected: ${this.connectionVersion}, got: ${peerConnection.connectionVersion})`);
            return;
        }
        
        // Validate signaling state for safe candidate processing
        if (peerConnection.signalingState === 'closed') {
            console.log(`⚠️ Ignoring ICE candidate for ${message.from} - signaling state is closed`);
            return;
        }
        
        try {
            const candidate = new RTCIceCandidate(message.data);
            
            // Enhanced ICE candidate validation and stability
            if (!candidate.candidate || candidate.candidate.trim() === '') {
                console.warn(`⚠️ Empty ICE candidate received from ${message.from}`);
                return;
            }
            
            // Log candidate details for debugging
            console.log(`🧊 Processing ICE candidate from ${message.from}: ${candidate.candidate.substring(0, 80)}...`);
            
            // Validate remote description is set before adding candidates
            if (!peerConnection.remoteDescription) {
                console.log(`⚠️ Remote description not set yet, queuing candidate for ${message.from}`);
                this.queueIceCandidate(message.from, candidate);
                return;
            }
            
            await peerConnection.addIceCandidate(candidate);
            console.log(`✅ Added ICE candidate for ${message.from} (type: ${candidate.candidate.split(' ')[7] || 'unknown'})`);
            
            // Monitor audio stability after ICE candidate addition
            this.monitorAudioStabilityAfterIce(message.from);
            
        } catch (error) {
            // Enhanced error logging for debugging ICE issues
            if (error.message.includes('ufrag')) {
                console.warn(`⚠️ ICE candidate ufrag mismatch for ${message.from}:`, error.message);
                console.log(`🔍 Connection version: ${peerConnection.connectionVersion}, Global version: ${this.connectionVersion}`);
                console.log(`🔍 Connection state: ${peerConnection.connectionState}, Signaling state: ${peerConnection.signalingState}`);
                
                // Track ufrag errors per peer for targeted recovery
                if (!this.ufragErrorCounts) this.ufragErrorCounts = new Map();
                const errorCount = (this.ufragErrorCounts.get(message.from) || 0) + 1;
                this.ufragErrorCounts.set(message.from, errorCount);
                
                if (errorCount >= 3) {
                    console.log(`🔄 Multiple ufrag errors detected for ${message.from}, triggering recovery`);
                    this.handlePersistentUfragError(message.from);
                }
            } else if (error.message.includes('InvalidStateError')) {
                console.warn(`⚠️ ICE candidate state error for ${message.from}: connection not ready, queuing candidate`);
                this.queueIceCandidate(message.from, candidate);
            } else {
                console.warn(`⚠️ Failed to add ICE candidate for ${message.from}:`, error.message);
                console.log(`🔍 Candidate causing error: ${candidate.candidate}`);
            }
        }
    }
    
    handlePersistentUfragError(peerId) {
        console.log(`🔄 Handling persistent ufrag errors for ${peerId}`);
        
        // Reset error count for this specific peer
        if (this.ufragErrorCounts) {
            this.ufragErrorCounts.delete(peerId);
        }
        
        // Remove the problematic connection
        const existingConnection = this.peerConnections.get(peerId);
        if (existingConnection) {
            console.log(`🗑️ Closing connection with persistent ufrag errors to ${peerId}`);
            existingConnection.close();
            this.peerConnections.delete(peerId);
        }
        
        // Clear queued candidates for this peer
        if (this.queuedCandidates) {
            this.queuedCandidates.delete(peerId);
        }
        
        // Remove remote stream
        if (this.remoteStreams.has(peerId)) {
            this.remoteStreams.delete(peerId);
            this.updateRemoteParticipants();
        }
        
        // Wait a bit and attempt to recreate the connection
        setTimeout(async () => {
            console.log(`🔄 Attempting to recreate connection to ${peerId} after ufrag errors`);
            try {
                // Only recreate if we're still connected to the room
                if (this.isConnected && this.peerId < peerId) {
                    // Only if we should be the one creating the offer
                    await this.createOfferToPeer(peerId);
                }
            } catch (error) {
                console.error(`❌ Failed to recreate connection to ${peerId}:`, error);
            }
        }, 2000); // Wait 2 seconds before retry
    }
    
    // NEW: Queue ICE candidates when connection isn't ready
    queueIceCandidate(peerId, candidate) {
        if (!this.queuedCandidates) {
            this.queuedCandidates = new Map();
        }
        
        if (!this.queuedCandidates.has(peerId)) {
            this.queuedCandidates.set(peerId, []);
        }
        
        const queue = this.queuedCandidates.get(peerId);
        queue.push(candidate);
        
        // Limit queue size to prevent memory issues
        if (queue.length > 50) {
            queue.shift(); // Remove oldest candidate
            console.log(`⚠️ ICE candidate queue for ${peerId} exceeds limit, removing oldest`);
        }
        
        console.log(`📎 Queued ICE candidate for ${peerId} (queue size: ${queue.length})`);
        
        // Try to process queue after a delay
        setTimeout(() => this.processQueuedCandidates(peerId), 500);
    }
    
    // NEW: Process queued ICE candidates
    async processQueuedCandidates(peerId) {
        if (!this.queuedCandidates || !this.queuedCandidates.has(peerId)) {
            return;
        }
        
        const peerConnection = this.peerConnections.get(peerId);
        if (!peerConnection || !peerConnection.remoteDescription) {
            console.log(`⚠️ Cannot process queued candidates for ${peerId}: connection not ready`);
            return;
        }
        
        const queue = this.queuedCandidates.get(peerId);
        console.log(`🔄 Processing ${queue.length} queued ICE candidates for ${peerId}`);
        
        while (queue.length > 0) {
            const candidate = queue.shift();
            try {
                await peerConnection.addIceCandidate(candidate);
                console.log(`✅ Added queued ICE candidate for ${peerId}`);
            } catch (error) {
                console.warn(`⚠️ Failed to add queued ICE candidate for ${peerId}:`, error.message);
                // Don't continue processing if we're getting errors
                break;
            }
        }
        
        // Clear queue if empty
        if (queue.length === 0) {
            this.queuedCandidates.delete(peerId);
        }
    }
    
    // NEW: Monitor audio stability after ICE candidate changes
    monitorAudioStabilityAfterIce(peerId) {
        // Check audio quality after ICE processing
        setTimeout(() => {
            const remoteStream = this.remoteStreams.get(peerId);
            if (remoteStream) {
                const audioTracks = remoteStream.getAudioTracks();
                audioTracks.forEach((track, index) => {
                    if (track.readyState !== 'live' || track.muted) {
                        console.warn(`⚠️ Audio track ${index} for ${peerId} unstable after ICE: readyState=${track.readyState}, muted=${track.muted}`);
                        this.attemptAudioRecovery(peerId);
                    }
                });
            }
        }, 1000);
    }
    
    // NEW: Attempt to recover audio for specific peer
    async attemptAudioRecovery(peerId) {
        console.log(`🔄 Attempting audio recovery for ${peerId}`);
        
        const peerConnection = this.peerConnections.get(peerId);
        if (!peerConnection) return;
        
        // Trigger renegotiation to refresh audio
        try {
            if (peerConnection.signalingState === 'stable' && this.localStream) {
                // Re-add local tracks if missing
                const senders = peerConnection.getSenders();
                const localAudioTracks = this.localStream.getAudioTracks();
                
                if (senders.length === 0 && localAudioTracks.length > 0) {
                    console.log(`🎤 Re-adding local audio tracks for ${peerId}`);
                    localAudioTracks.forEach(track => {
                        peerConnection.addTrack(track, this.localStream);
                    });
                    
                    // Create new offer to renegotiate
                    const offer = await peerConnection.createOffer();
                    await peerConnection.setLocalDescription(offer);
                    await this.sendSignal(peerId, 'offer', offer);
                    console.log(`🔄 Renegotiation offer sent to ${peerId} for audio recovery`);
                }
            }
        } catch (error) {
            console.error(`❌ Audio recovery failed for ${peerId}:`, error);
        }
    }
    
    // NEW: Validate audio after connection establishment
    validateAudioAfterConnection(peerId) {
        setTimeout(() => {
            console.log(`🔍 Validating audio for ${peerId} after connection`);
            
            const remoteStream = this.remoteStreams.get(peerId);
            if (remoteStream) {
                const audioTracks = remoteStream.getAudioTracks();
                if (audioTracks.length === 0) {
                    console.warn(`⚠️ No audio tracks received from ${peerId}, requesting renegotiation`);
                    this.requestAudioRenegotiation(peerId);
                } else {
                    audioTracks.forEach((track, index) => {
                        console.log(`🎤 Audio track ${index} from ${peerId}: enabled=${track.enabled}, readyState=${track.readyState}, muted=${track.muted}`);
                        
                        if (track.readyState !== 'live') {
                            console.warn(`⚠️ Audio track ${index} from ${peerId} not live: ${track.readyState}`);
                        }
                    });
                }
            } else {
                console.warn(`⚠️ No remote stream received from ${peerId}`);
            }
            
            // Check local audio transmission
            const peerConnection = this.peerConnections.get(peerId);
            if (peerConnection) {
                const senders = peerConnection.getSenders();
                const audioSenders = senders.filter(sender => 
                    sender.track && sender.track.kind === 'audio'
                );
                
                if (audioSenders.length === 0) {
                    console.warn(`⚠️ No audio senders to ${peerId}, audio may not be transmitting`);
                    this.ensureAudioTransmission(peerId);
                } else {
                    console.log(`✅ Audio transmission to ${peerId} validated: ${audioSenders.length} sender(s)`);
                }
            }
        }, 2000); // Wait 2 seconds for stream establishment
    }
    
    // NEW: Check audio stability during connection issues
    checkAudioStability(peerId) {
        console.log(`🔍 Checking audio stability for ${peerId}`);
        
        const remoteStream = this.remoteStreams.get(peerId);
        if (remoteStream) {
            const audioTracks = remoteStream.getAudioTracks();
            let hasStableAudio = false;
            
            audioTracks.forEach((track, index) => {
                if (track.readyState === 'live' && !track.muted) {
                    hasStableAudio = true;
                    console.log(`✅ Audio track ${index} from ${peerId} is stable`);
                } else {
                    console.warn(`⚠️ Audio track ${index} from ${peerId} unstable: readyState=${track.readyState}, muted=${track.muted}`);
                }
            });
            
            if (!hasStableAudio) {
                console.log(`❌ No stable audio from ${peerId}, attempting recovery`);
                this.attemptAudioRecovery(peerId);
            }
        } else {
            console.warn(`⚠️ No remote stream from ${peerId} during stability check`);
        }
    }
    
    // NEW: Handle ICE connection failure
    handleIceConnectionFailure(peerId) {
        console.log(`🔄 Handling ICE connection failure for ${peerId}`);
        
        // Attempt ICE restart
        const peerConnection = this.peerConnections.get(peerId);
        if (peerConnection && peerConnection.restartIce) {
            try {
                console.log(`🔄 Attempting ICE restart for ${peerId}`);
                peerConnection.restartIce();
                
                // Create new offer with ICE restart
                if (this.peerId < peerId) {
                    setTimeout(async () => {
                        try {
                            const offer = await peerConnection.createOffer({ iceRestart: true });
                            await peerConnection.setLocalDescription(offer);
                            await this.sendSignal(peerId, 'offer', offer);
                            console.log(`✅ ICE restart offer sent to ${peerId}`);
                        } catch (error) {
                            console.error(`❌ Failed to create ICE restart offer for ${peerId}:`, error);
                        }
                    }, 1000);
                }
            } catch (error) {
                console.error(`❌ ICE restart failed for ${peerId}:`, error);
            }
        }
    }
    
    // NEW: Attempt connection recovery
    async attemptConnectionRecovery(peerId) {
        console.log(`🔄 Attempting connection recovery for ${peerId}`);
        
        const peerConnection = this.peerConnections.get(peerId);
        if (!peerConnection) {
            console.log(`⚠️ No connection to recover for ${peerId}`);
            return;
        }
        
        // Try different recovery strategies based on connection state
        switch (peerConnection.connectionState) {
            case 'disconnected':
                // Wait a bit more for automatic recovery
                console.log(`⏳ Waiting for automatic recovery for ${peerId}`);
                setTimeout(() => {
                    if (peerConnection.connectionState === 'disconnected') {
                        console.log(`🔄 Triggering manual reconnection for ${peerId}`);
                        this.handleIceConnectionFailure(peerId);
                    }
                }, 3000);
                break;
                
            case 'failed':
                console.log(`🔄 Connection failed, recreating for ${peerId}`);
                // Connection is already handled by connectionstatechange event
                break;
        }
    }
    
    // NEW: Request audio renegotiation
    async requestAudioRenegotiation(peerId) {
        console.log(`🔄 Requesting audio renegotiation for ${peerId}`);
        
        const peerConnection = this.peerConnections.get(peerId);
        if (!peerConnection || peerConnection.signalingState !== 'stable') {
            console.log(`⚠️ Cannot renegotiate with ${peerId}: connection not stable`);
            return;
        }
        
        try {
            // Ensure we have local audio
            if (!this.localStream || this.localStream.getAudioTracks().length === 0) {
                console.log(`🎤 Starting local audio for renegotiation with ${peerId}`);
                await this.startAudio();
            }
            
            // Check if we need to add tracks
            const senders = peerConnection.getSenders();
            const hasAudioSender = senders.some(sender => 
                sender.track && sender.track.kind === 'audio'
            );
            
            if (!hasAudioSender && this.localStream) {
                console.log(`🎤 Adding local audio tracks for renegotiation with ${peerId}`);
                this.localStream.getAudioTracks().forEach(track => {
                    peerConnection.addTrack(track, this.localStream);
                });
            }
            
            // Create renegotiation offer
            const offer = await peerConnection.createOffer();
            await peerConnection.setLocalDescription(offer);
            await this.sendSignal(peerId, 'offer', offer);
            console.log(`✅ Audio renegotiation offer sent to ${peerId}`);
            
        } catch (error) {
            console.error(`❌ Audio renegotiation failed for ${peerId}:`, error);
        }
    }
    
    // NEW: Ensure audio transmission to peer
    async ensureAudioTransmission(peerId) {
        console.log(`🔍 Ensuring audio transmission to ${peerId}`);
        
        const peerConnection = this.peerConnections.get(peerId);
        if (!peerConnection) return;
        
        // Check if we have local audio
        if (!this.localStream) {
            console.log(`🎤 No local stream, starting audio for ${peerId}`);
            try {
                await this.startAudio();
            } catch (error) {
                console.error(`❌ Failed to start audio for ${peerId}:`, error);
                return;
            }
        }
        
        const audioTracks = this.localStream.getAudioTracks();
        if (audioTracks.length === 0) {
            console.warn(`⚠️ No local audio tracks available for ${peerId}`);
            return;
        }
        
        // Check senders
        const senders = peerConnection.getSenders();
        const audioSenders = senders.filter(sender => 
            sender.track && sender.track.kind === 'audio'
        );
        
        if (audioSenders.length === 0) {
            console.log(`🎤 Adding audio tracks to existing connection with ${peerId}`);
            try {
                audioTracks.forEach(track => {
                    peerConnection.addTrack(track, this.localStream);
                });
                
                // Trigger renegotiation
                await this.requestAudioRenegotiation(peerId);
            } catch (error) {
                console.error(`❌ Failed to add audio tracks to ${peerId}:`, error);
            }
        }
    }

    async createPeerConnection(peerId) {
        // Check if we already have a valid connection
        const existingConnection = this.peerConnections.get(peerId);
        if (existingConnection && 
            (existingConnection.connectionState === 'connected' || 
             existingConnection.connectionState === 'connecting')) {
            console.log(`🔄 Reusing existing connection to ${peerId} (state: ${existingConnection.connectionState})`);
            return existingConnection;
        }
        
        // Close any existing connection that's in a bad state
        if (existingConnection) {
            console.log(`🗑️ Closing stale connection to ${peerId} (state: ${existingConnection.connectionState})`);
            existingConnection.close();
        }
        
        console.log(`🆕 Creating new peer connection to ${peerId}`);
        const peerConnection = new RTCPeerConnection(this.rtcConfig);
        this.peerConnections.set(peerId, peerConnection);
        
        // Store connection version for validation (prevents 'Unknown ufrag' errors)
        peerConnection.connectionVersion = this.connectionVersion;
        peerConnection.createdAt = Date.now();
        
        console.log(`🔢 Peer connection to ${peerId} created with version ${this.connectionVersion}`);

        // Handle ICE candidates with version validation
        peerConnection.onicecandidate = async (event) => {
            if (event.candidate && peerConnection.connectionVersion === this.connectionVersion) {
                console.log(`🧊 Sending ICE candidate to ${peerId} (version: ${this.connectionVersion})`);
                await this.sendSignal(peerId, 'ice-candidate', event.candidate);
            } else if (event.candidate) {
                console.log(`⚠️ Ignoring stale ICE candidate for ${peerId} (connection version: ${peerConnection.connectionVersion}, current: ${this.connectionVersion})`);
            }
        };

        // Handle remote stream
        peerConnection.ontrack = (event) => {
            console.log('🎵 Received remote stream from:', peerId);
            const remoteStream = event.streams[0];
            
            // Validate stream has tracks
            const audioTracks = remoteStream.getAudioTracks();
            console.log(`🔊 Remote stream from ${peerId}: ${audioTracks.length} audio tracks`);
            
            if (audioTracks.length === 0) {
                console.warn(`⚠️ Remote stream from ${peerId} has no audio tracks`);
            } else {
                audioTracks.forEach((track, index) => {
                    console.log(`🎤 Audio track ${index}: enabled=${track.enabled}, readyState=${track.readyState}`);
                });
            }
            
            this.remoteStreams.set(peerId, remoteStream);
            this.updateRemoteParticipants();
        };
        
        // Enhanced connection state monitoring for audio stability
        peerConnection.onconnectionstatechange = () => {
            console.log(`🔗 Connection to ${peerId} state: ${peerConnection.connectionState}`);
            
            switch (peerConnection.connectionState) {
                case 'connected':
                    console.log(`✅ Successfully connected to ${peerId}`);
                    // Reset error counts on successful connection
                    if (this.ufragErrorCounts) {
                        this.ufragErrorCounts.delete(peerId);
                    }
                    // Process any queued candidates
                    this.processQueuedCandidates(peerId);
                    // Validate audio after connection
                    this.validateAudioAfterConnection(peerId);
                    break;
                    
                case 'disconnected':
                    console.log(`⚠️ Connection to ${peerId} disconnected, monitoring for recovery`);
                    // Monitor for automatic reconnection
                    setTimeout(() => {
                        if (peerConnection.connectionState === 'disconnected') {
                            console.log(`🔄 Connection to ${peerId} still disconnected, attempting recovery`);
                            this.attemptConnectionRecovery(peerId);
                        }
                    }, 5000);
                    break;
                    
                case 'failed':
                    console.log(`❌ Connection to ${peerId} failed, cleaning up and attempting reconnection`);
                    // Clean up failed connection
                    this.peerConnections.delete(peerId);
                    this.remoteStreams.delete(peerId);
                    if (this.queuedCandidates) {
                        this.queuedCandidates.delete(peerId);
                    }
                    this.updateRemoteParticipants();
                    
                    // Attempt reconnection after delay
                    setTimeout(() => {
                        if (this.isConnected && this.peerId < peerId) {
                            this.createOfferToPeer(peerId).catch(error => {
                                console.error(`❌ Failed to reconnect to ${peerId}:`, error);
                            });
                        }
                    }, 3000);
                    break;
                    
                case 'connecting':
                    console.log(`🔄 Connecting to ${peerId}...`);
                    break;
                    
                case 'new':
                    console.log(`🆕 New connection created for ${peerId}`);
                    break;
                    
                case 'closed':
                    console.log(`🔒 Connection to ${peerId} closed`);
                    break;
            }
        };
        
        // Enhanced ICE connection state monitoring
        peerConnection.oniceconnectionstatechange = () => {
            console.log(`🧊 ICE connection to ${peerId} state: ${peerConnection.iceConnectionState}`);
            
            switch (peerConnection.iceConnectionState) {
                case 'connected':
                case 'completed':
                    console.log(`✅ ICE connection to ${peerId} established successfully`);
                    break;
                    
                case 'disconnected':
                    console.log(`⚠️ ICE connection to ${peerId} disconnected`);
                    // Monitor for recovery
                    setTimeout(() => {
                        if (peerConnection.iceConnectionState === 'disconnected') {
                            console.log(`🔄 ICE still disconnected for ${peerId}, checking audio`);
                            this.checkAudioStability(peerId);
                        }
                    }, 3000);
                    break;
                    
                case 'failed':
                    console.log(`❌ ICE connection to ${peerId} failed`);
                    this.handleIceConnectionFailure(peerId);
                    break;
            }
        };
        
        // Monitor ICE gathering state
        peerConnection.onicegatheringstatechange = () => {
            console.log(`📎 ICE gathering for ${peerId}: ${peerConnection.iceGatheringState}`);
            
            if (peerConnection.iceGatheringState === 'complete') {
                console.log(`✅ ICE gathering completed for ${peerId}`);
            }
        };
        
        // Enhanced signaling state monitoring
        peerConnection.onsignalingstatechange = () => {
            console.log(`📡 Signaling state for ${peerId}: ${peerConnection.signalingState}`);
            
            if (peerConnection.signalingState === 'stable') {
                console.log(`✅ Signaling stable for ${peerId}, processing queued candidates`);
                this.processQueuedCandidates(peerId);
            }
        };

        // Add local stream if available
        if (this.localStream) {
            console.log(`🎵 Adding local audio tracks to connection with ${peerId}`);
            const audioTracks = this.localStream.getAudioTracks();
            
            if (audioTracks.length === 0) {
                console.warn(`⚠️ Local stream has no audio tracks when creating connection to ${peerId}`);
                // Try to start audio if we don't have it
                this.startAudio().catch(error => {
                    console.error('❌ Failed to start audio for peer connection:', error);
                });
            } else {
                audioTracks.forEach((track, index) => {
                    console.log(`🎤 Adding local audio track ${index}: enabled=${track.enabled}, readyState=${track.readyState}`);
                });
                
                this.localStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, this.localStream);
                });
            }
        } else {
            console.warn(`⚠️ No local stream available when creating connection to ${peerId}`);
            // Try to start audio
            this.startAudio().catch(error => {
                console.error('❌ Failed to start audio for peer connection:', error);
            });
        }

        return peerConnection;
    }

    async createOfferToPeer(peerId) {
        try {
            // Validate that we have audio tracks before creating offer
            if (!this.localStream || this.localStream.getTracks().length === 0) {
                console.log('🎤 No audio tracks available, starting audio before creating offer');
                try {
                    await this.startAudio();
                } catch (error) {
                    console.error('❌ Failed to start audio for offer creation:', error);
                    // Continue without audio - peer connection still possible
                }
            }
            
            const peerConnection = await this.createPeerConnection(peerId);
            
            // Double-check connection state before creating offer
            if (peerConnection.signalingState !== 'stable') {
                console.log(`⚠️ Peer connection to ${peerId} not in stable state (${peerConnection.signalingState}), waiting...`);
                // Wait a bit and retry
                await new Promise(resolve => setTimeout(resolve, 100));
                if (peerConnection.signalingState !== 'stable') {
                    console.log(`❌ Peer connection to ${peerId} still not stable, skipping offer creation`);
                    return;
                }
            }
            
            const offer = await peerConnection.createOffer();
            await peerConnection.setLocalDescription(offer);
            
            await this.sendSignal(peerId, 'offer', offer);
            console.log('📤 Sent offer to peer:', peerId);
            
        } catch (error) {
            console.error('❌ Create offer error:', error);
            
            // If offer creation fails, try to recover the connection
            if (this.peerConnections.has(peerId)) {
                console.log('🔄 Removing failed connection, will retry on next attempt');
                this.peerConnections.get(peerId).close();
                this.peerConnections.delete(peerId);
            }
        }
    }

    async sendSignal(toPeerId, type, data) {
        try {
            const response = await fetch(this.apiBase + 'api.php?action=signal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    fromPeerId: this.peerId,
                    toPeerId: toPeerId,
                    roomId: this.roomId,
                    type: type,
                    data: data
                })
            });

            const result = await response.json();
            if (!result.success) {
                console.error('Failed to send signal:', result.error);
            }
        } catch (error) {
            console.error('Send signal error:', error);
        }
    }

    async startAudio() {
        try {
            // Stop any existing audio first
            if (this.localStream) {
                this.localStream.getTracks().forEach(track => track.stop());
            }
            
            console.log('🎵 Starting audio capture...');
            this.localStream = await navigator.mediaDevices.getUserMedia({ 
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                }
            });
            this.localAudio.srcObject = this.localStream;
            
            // Replace audio tracks in all existing peer connections
            this.peerConnections.forEach(async (pc, peerId) => {
                console.log(`🔄 Updating audio tracks for peer: ${peerId}`);
                
                // Remove existing audio tracks
                const senders = pc.getSenders();
                senders.forEach(sender => {
                    if (sender.track && sender.track.kind === 'audio') {
                        pc.removeTrack(sender);
                    }
                });
                
                // Add new audio tracks
                this.localStream.getTracks().forEach(track => {
                    console.log(`🎤 Adding track: ${track.kind} (enabled: ${track.enabled}, readyState: ${track.readyState})`);
                    pc.addTrack(track, this.localStream);
                });
                
                // Trigger renegotiation if we're the offerer (lexicographically smaller peer ID)
                if (this.peerId < peerId && pc.signalingState === 'stable') {
                    try {
                        console.log(`🔄 Triggering renegotiation for ${peerId}`);
                        const offer = await pc.createOffer();
                        await pc.setLocalDescription(offer);
                        await this.sendSignal(peerId, 'offer', offer);
                        console.log(`✅ Renegotiation offer sent to ${peerId}`);
                    } catch (error) {
                        console.error(`❌ Renegotiation failed for ${peerId}:`, error);
                    }
                }
            });
            
            this.updateLocalParticipant();
            this.showMessage('Audio started - you can now speak', 'success');
            console.log('✅ Audio capture started successfully');
            
            // Validate audio tracks
            this.validateAudioTracks();
        } catch (error) {
            console.error('Error accessing microphone:', error);
            this.showMessage('Failed to access microphone: ' + error.message, 'error');
            this.diagnoseAudioProblem(error);
        }
        this.updateUI();
    }
    
    validateAudioTracks() {
        if (!this.localStream) {
            console.error('❌ No local stream available');
            return false;
        }
        
        const audioTracks = this.localStream.getAudioTracks();
        console.log(`🔊 Local stream validation: ${audioTracks.length} audio tracks`);
        
        if (audioTracks.length === 0) {
            console.error('❌ Local stream has no audio tracks');
            return false;
        }
        
        audioTracks.forEach((track, index) => {
            console.log(`🎤 Local audio track ${index}:`);
            console.log(`  - Label: ${track.label}`);
            console.log(`  - Enabled: ${track.enabled}`);
            console.log(`  - Ready State: ${track.readyState}`);
            console.log(`  - Muted: ${track.muted}`);
            
            if (track.readyState !== 'live') {
                console.warn(`⚠️ Audio track ${index} is not live: ${track.readyState}`);
            }
        });
        
        return true;
    }
    
    diagnoseAudioProblem(error) {
        console.log('🔍 Diagnosing audio problem...');
        
        const diagnosis = [];
        
        // Check error type
        if (error.name === 'NotAllowedError') {
            diagnosis.push('❌ Microphone permission denied');
            diagnosis.push('💡 Solution: Allow microphone access in browser settings');
        } else if (error.name === 'NotFoundError') {
            diagnosis.push('❌ No microphone device found');
            diagnosis.push('💡 Solution: Connect a microphone and refresh the page');
        } else if (error.name === 'NotReadableError') {
            diagnosis.push('❌ Microphone is being used by another application');
            diagnosis.push('💡 Solution: Close other applications using the microphone');
        } else {
            diagnosis.push(`❌ Audio error: ${error.name} - ${error.message}`);
        }
        
        // Check HTTPS requirement
        if (window.location.protocol === 'http:' && window.location.hostname !== 'localhost') {
            diagnosis.push('⚠️ HTTPS required for microphone access');
            diagnosis.push('💡 Solution: Access via HTTPS or localhost');
        }
        
        // Display diagnosis
        console.log('🔍 Audio Diagnosis:');
        diagnosis.forEach(item => console.log(`  ${item}`));
        
        // Show user-friendly message
        const problemMsg = diagnosis.join('\n');
        setTimeout(() => {
            alert(`Audio Problem Detected:\n\n${problemMsg}`);
        }, 1000);
    }

    stopAudio() {
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
            this.localStream = null;
        }
        this.localAudio.srcObject = null;
        this.updateLocalParticipant();
        this.showMessage('Audio stopped', 'success');
        this.updateUI();
    }

    muteAudio() {
        if (this.localStream) {
            this.localStream.getAudioTracks().forEach(track => {
                track.enabled = false;
            });
            this.isMuted = true;
            this.updateLocalParticipant();
            this.showMessage('Microphone muted', 'success');
        }
        this.updateUI();
    }

    unmuteAudio() {
        if (this.localStream) {
            this.localStream.getAudioTracks().forEach(track => {
                track.enabled = true;
            });
            this.isMuted = false;
            this.updateLocalParticipant();
            this.showMessage('Microphone unmuted', 'success');
        }
        this.updateUI();
    }

    stopAllMedia() {
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
            this.localStream = null;
        }
        this.localAudio.srcObject = null;
        this.isMuted = false;
    }

    async loadPeers() {
        try {
            const response = await fetch(`${this.apiBase}api.php?action=peers&roomId=${this.roomId}`);
            const result = await response.json();
            
            if (result.success) {
                this.displayPeers(result.data.peers);
                this.updateRoomCapacity(result.data.peers.length);
            }
        } catch (error) {
            console.error('Failed to load peers:', error);
        }
    }

    displayPeers(peers) {
        const otherPeers = peers.filter(peer => peer.peer_id !== this.peerId);
        
        if (otherPeers.length === 0) {
            this.peersList.innerHTML = 'No other participants';
            return;
        }

        const peerItems = otherPeers.map(peer => `
            <div class="peer-item">
                <span>${peer.username || 'Anonymous'}</span>
                <span class="status-indicator status-connected"></span>
            </div>
        `).join('');

        this.peersList.innerHTML = peerItems;
    }

    updateRoomCapacity(currentCount) {
        this.roomCapacity.textContent = `${currentCount}/4`;
        
        if (currentCount >= 4) {
            this.roomCapacity.style.color = '#F44336';
        } else if (currentCount >= 3) {
            this.roomCapacity.style.color = '#FF9800';
        } else {
            this.roomCapacity.style.color = '#4CAF50';
        }
    }

    updateLocalParticipant() {
        const participantName = this.localParticipant.querySelector('.participant-name');
        const participantStatus = this.localParticipant.querySelector('.participant-status');
        
        participantName.textContent = this.username || 'You';
        
        if (!this.isConnected) {
            participantStatus.textContent = 'Disconnected';
            this.localParticipant.className = 'participant local';
        } else if (!this.localStream) {
            participantStatus.textContent = 'Connected (No Audio)';
            this.localParticipant.className = 'participant local connected';
        } else if (this.isMuted) {
            participantStatus.textContent = 'Muted';
            this.localParticipant.className = 'participant local connected';
        } else {
            participantStatus.textContent = 'Speaking';
            this.localParticipant.className = 'participant local connected speaking';
        }
    }

    updateRemoteParticipants() {
        // Remove old remote participants
        const existingRemote = this.participantsGrid.querySelectorAll('.participant:not(.local)');
        existingRemote.forEach(el => el.remove());
        
        // Add current remote participants
        this.remoteStreams.forEach((stream, peerId) => {
            console.log(`🎵 Creating UI for remote peer: ${peerId}, stream tracks:`, stream.getTracks().length);
            
            const participantDiv = document.createElement('div');
            participantDiv.className = 'participant connected';
            
            // Check if stream has audio tracks
            const audioTracks = stream.getAudioTracks();
            const hasAudio = audioTracks.length > 0;
            const isAudioEnabled = hasAudio && audioTracks[0].enabled;
            
            console.log(`🔊 Peer ${peerId} audio status: ${hasAudio ? 'has audio' : 'no audio'}, enabled: ${isAudioEnabled}`);
            
            participantDiv.innerHTML = `
                <div class="participant-avatar">${hasAudio ? '🎤' : '🔇'}</div>
                <div class="participant-name">${peerId.split('_')[1] || 'Peer'}</div>
                <div class="participant-status">${hasAudio && isAudioEnabled ? 'Connected' : 'No Audio'}</div>
                <audio autoplay></audio>
            `;
            
            const audio = participantDiv.querySelector('audio');
            audio.srcObject = stream;
            
            // Add audio level monitoring
            if (hasAudio) {
                this.monitorAudioLevel(audio, participantDiv);
            }
            
            this.participantsGrid.appendChild(participantDiv);
        });
        
        console.log(`👥 Updated UI: ${this.remoteStreams.size} remote participants displayed`);
    }
    
    monitorAudioLevel(audioElement, participantDiv) {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const source = audioContext.createMediaElementSource(audioElement);
            const analyser = audioContext.createAnalyser();
            
            source.connect(analyser);
            analyser.connect(audioContext.destination);
            
            analyser.fftSize = 256;
            const dataArray = new Uint8Array(analyser.frequencyBinCount);
            
            const statusElement = participantDiv.querySelector('.participant-status');
            
            const checkAudioLevel = () => {
                analyser.getByteFrequencyData(dataArray);
                const average = dataArray.reduce((a, b) => a + b) / dataArray.length;
                
                if (average > 10) {
                    statusElement.textContent = 'Speaking';
                    participantDiv.className = 'participant connected speaking';
                } else {
                    statusElement.textContent = 'Connected';
                    participantDiv.className = 'participant connected';
                }
                
                requestAnimationFrame(checkAudioLevel);
            };
            
            // Start monitoring after a short delay to ensure audio is ready
            setTimeout(() => {
                if (audioContext.state === 'suspended') {
                    audioContext.resume();
                }
                checkAudioLevel();
            }, 1000);
            
        } catch (error) {
            console.log('🔊 Audio monitoring not available:', error.message);
        }
    }

    clearRemoteParticipants() {
        const existingRemote = this.participantsGrid.querySelectorAll('.participant:not(.local)');
        existingRemote.forEach(el => el.remove());
    }

    updateStatus(status, text) {
        this.statusIndicator.className = `status-indicator status-${status}`;
        this.statusText.textContent = text;
    }

    updateUI() {
        const hasAudio = this.localStream && this.localStream.getAudioTracks().length > 0;

        // Enable/disable buttons based on connection state
        this.joinBtn.disabled = this.isConnected;
        this.newRoomBtn.disabled = this.isConnected;
        this.leaveBtn.disabled = !this.isConnected;
        this.muteBtn.disabled = !hasAudio || this.isMuted;
        this.unmuteBtn.disabled = !hasAudio || !this.isMuted;

        // Disable inputs when connected
        this.usernameInput.disabled = this.isConnected;
        this.roomInput.disabled = this.isConnected;
    }

    showMessage(message, type = 'info') {
        const messageDiv = document.createElement('div');
        messageDiv.className = type;
        messageDiv.textContent = message;
        
        this.messageContainer.appendChild(messageDiv);
        
        // Remove message after 5 seconds
        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.parentNode.removeChild(messageDiv);
            }
        }, 5000);
    }

    async loadAvailableRooms() {
        try {
            this.availableRoomsList.innerHTML = '<div class="loading-rooms">🔄 Loading available rooms...</div>';
            this.roomCount.textContent = 'Loading...';

            const response = await fetch(this.apiBase + 'api.php?action=list_rooms');
            const result = await response.json();

            if (result.success) {
                this.displayAvailableRooms(result.data.rooms);
                this.roomCount.textContent = `${result.data.count} room(s) available`;
            } else {
                this.availableRoomsList.innerHTML = '<div class="no-rooms">❌ Failed to load rooms</div>';
                this.roomCount.textContent = 'Error loading rooms';
            }
        } catch (error) {
            console.error('Failed to load rooms:', error);
            this.availableRoomsList.innerHTML = '<div class="no-rooms">❌ Connection error</div>';
            this.roomCount.textContent = 'Connection error';
        }
    }

    displayAvailableRooms(rooms) {
        if (!rooms || rooms.length === 0) {
            this.availableRoomsList.innerHTML = `
                <div class="no-rooms">
                    🏠 No rooms available yet<br>
                    <small>Click "New Room" to create the first room!</small>
                </div>
            `;
            return;
        }

        // Get participant counts for each room
        this.getRoomParticipantCounts(rooms).then(roomsWithCounts => {
            const roomsHtml = roomsWithCounts.map(room => this.createRoomCard(room)).join('');
            this.availableRoomsList.innerHTML = roomsHtml;
            
            // Add click event listeners to room cards
            this.bindRoomCardEvents();
        });
    }

    updateRoomList(rooms) {
        // Direct update method for real-time room list updates
        console.log('📋 Updating room list with real-time data');
        
        this.displayAvailableRooms(rooms);
        this.roomCount.textContent = `${rooms.length} room(s) available`;
    }

    async getRoomParticipantCounts(rooms) {
        const roomsWithCounts = [];
        
        for (const room of rooms) {
            try {
                const response = await fetch(`${this.apiBase}api.php?action=peers&roomId=${room.room_id}`);
                const result = await response.json();
                
                roomsWithCounts.push({
                    ...room,
                    participantCount: result.success ? result.data.peers.length : 0
                });
            } catch (error) {
                console.error(`Failed to get participant count for room ${room.room_id}:`, error);
                roomsWithCounts.push({
                    ...room,
                    participantCount: 0
                });
            }
        }
        
        return roomsWithCounts;
    }

    createRoomCard(room) {
        const participantCount = room.participantCount || 0;
        const isFull = participantCount >= 4;
        const isCurrentRoom = room.room_id === this.roomId && this.isConnected;
        
        const displayId = room.room_id.split('_')[0]; // Show only 3-digit part for clean display
        const timestamp = room.room_id.split('_')[1];
        const createdTime = timestamp ? new Date(parseInt(timestamp) * 1000).toLocaleString() : room.created_at;
        
        return `
            <div class="room-card ${isFull ? 'full' : ''} ${isCurrentRoom ? 'current' : ''}" 
                 data-room-id="${room.room_id}" 
                 data-room-name="${room.name}">
                <div class="room-id">#${displayId}</div>
                <div class="room-name">${room.name}</div>
                <div class="room-info">
                    <small>Created: ${createdTime}</small>
                    <span class="room-participants ${isFull ? 'full' : ''}">
                        ${participantCount}/4
                    </span>
                </div>
                ${isFull ? '<div style="font-size: 0.8rem; color: #dc3545; margin-top: 0.5rem;">Room Full</div>' : ''}
                ${isCurrentRoom ? '<div style="font-size: 0.8rem; color: #28a745; margin-top: 0.5rem;">Current Room</div>' : ''}
            </div>
        `;
    }

    bindRoomCardEvents() {
        const roomCards = document.querySelectorAll('.room-card');
        
        roomCards.forEach(card => {
            card.addEventListener('click', async () => {
                const roomId = card.dataset.roomId;
                const roomName = card.dataset.roomName;
                const isFull = card.classList.contains('full');
                const isCurrentRoom = card.classList.contains('current');
                
                if (isFull) {
                    this.showMessage('Room is full (4/4 participants)', 'error');
                    return;
                }
                
                if (isCurrentRoom) {
                    this.showMessage('You are already in this room', 'info');
                    return;
                }
                
                if (this.isConnected) {
                    const confirmJoin = confirm(`Leave current room and join "${roomName}" (#${roomId.split('_')[0]})?`);
                    if (!confirmJoin) return;
                    
                    console.log('🔄 Switching rooms - leaving current room first');
                    await this.leaveRoom();
                    // Wait for cleanup to complete
                    await new Promise(resolve => setTimeout(resolve, 750));
                }
                
                // Set username if not already set
                if (!this.usernameInput.value.trim()) {
                    this.usernameInput.value = 'User' + Math.floor(Math.random() * 1000);
                }
                
                // Set room info and join
                this.roomInput.value = roomId;
                this.roomId = roomId;
                
                this.showMessage(`Joining room "${roomName}" (#${roomId.split('_')[0]})...`, 'info');
                await this.joinRoom();
            });
        });
    }
}

// Initialize Audio Conference client when page loads
let audioConference;
document.addEventListener('DOMContentLoaded', () => {
    audioConference = new AudioConferenceClient();
});