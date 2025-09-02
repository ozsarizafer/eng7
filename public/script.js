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

        // Handle page unload
        window.addEventListener('beforeunload', () => {
            this.leaveRoom();
        });
        
        // Load available rooms on page load
        this.loadAvailableRooms();
    }

    generatePeerId() {
        return 'peer_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
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
                this.showMessage('Failed to join room: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('Join room error:', error);
            this.showMessage('Connection error: ' + error.message, 'error');
        }

        this.updateUI();
    }

    async leaveRoom() {
        if (!this.isConnected) return;

        try {
            // Complete connection state reset
            this.resetConnectionState();

            // Notify server
            await fetch(this.apiBase + 'api.php?action=leave', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    roomId: this.roomId,
                    peerId: this.peerId
                })
            });

            this.isConnected = false;
            this.updateStatus('disconnected', 'Disconnected');
            this.currentRoom.textContent = '-';
            this.updateLocalParticipant();
            this.clearRemoteParticipants();
            this.showMessage('Left the conference', 'success');
            
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
        
        console.log('✅ Connection state reset complete');
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
        }
    }

    async handlePeerJoined(data) {
        this.showMessage(`${data.username} joined the conference`, 'success');
        this.loadPeers();
        
        // If we have audio, create connection to new peer
        if (this.localStream) {
            await this.createOfferToPeer(data.peerId);
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

    async handleOffer(message) {
        console.log('Handling offer from:', message.from);
        
        const peerConnection = await this.createPeerConnection(message.from);
        
        const offer = new RTCSessionDescription(message.data);
        await peerConnection.setRemoteDescription(offer);
        
        const answer = await peerConnection.createAnswer();
        await peerConnection.setLocalDescription(answer);
        
        // Send answer back
        await this.sendSignal(message.from, 'answer', answer);
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
        if (peerConnection && message.data.candidate) {
            // Validate connection state before processing candidate
            if (peerConnection.connectionState === 'closed' || peerConnection.connectionState === 'failed') {
                console.log(`⚠️ Ignoring ICE candidate for ${message.from} - connection state: ${peerConnection.connectionState}`);
                return;
            }
            
            try {
                const candidate = new RTCIceCandidate(message.data);
                await peerConnection.addIceCandidate(candidate);
                console.log(`✅ Added ICE candidate for ${message.from}`);
            } catch (error) {
                console.warn(`⚠️ Failed to add ICE candidate for ${message.from}:`, error.message);
            }
        } else {
            console.log(`⚠️ No valid peer connection found for ${message.from} or no candidate data`);
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
        
        // Store connection version for validation
        peerConnection.connectionVersion = this.connectionVersion;

        // Handle ICE candidates
        peerConnection.onicecandidate = async (event) => {
            if (event.candidate && peerConnection.connectionVersion === this.connectionVersion) {
                console.log(`🧊 Sending ICE candidate to ${peerId}`);
                await this.sendSignal(peerId, 'ice-candidate', event.candidate);
            } else if (event.candidate) {
                console.log(`⚠️ Ignoring stale ICE candidate for ${peerId} (version mismatch)`);
            }
        };

        // Handle remote stream
        peerConnection.ontrack = (event) => {
            console.log('🎵 Received remote stream from:', peerId);
            const remoteStream = event.streams[0];
            this.remoteStreams.set(peerId, remoteStream);
            this.updateRemoteParticipants();
        };
        
        // Monitor connection state
        peerConnection.onconnectionstatechange = () => {
            console.log(`🔗 Connection to ${peerId} state: ${peerConnection.connectionState}`);
            
            if (peerConnection.connectionState === 'failed') {
                console.log(`❌ Connection to ${peerId} failed, attempting reconnection`);
                // Remove failed connection and let it be recreated on next offer
                this.peerConnections.delete(peerId);
                this.remoteStreams.delete(peerId);
                this.updateRemoteParticipants();
            }
        };

        // Add local stream if available
        if (this.localStream) {
            console.log(`🎵 Adding local audio tracks to connection with ${peerId}`);
            this.localStream.getTracks().forEach(track => {
                peerConnection.addTrack(track, this.localStream);
            });
        }

        return peerConnection;
    }

    async createOfferToPeer(peerId) {
        try {
            const peerConnection = await this.createPeerConnection(peerId);
            
            const offer = await peerConnection.createOffer();
            await peerConnection.setLocalDescription(offer);
            
            await this.sendSignal(peerId, 'offer', offer);
            console.log('Sent offer to peer:', peerId);
            
        } catch (error) {
            console.error('Create offer error:', error);
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
            this.peerConnections.forEach((pc, peerId) => {
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
                    pc.addTrack(track, this.localStream);
                });
            });
            
            this.updateLocalParticipant();
            this.showMessage('Audio started - you can now speak', 'success');
            console.log('✅ Audio capture started successfully');
        } catch (error) {
            console.error('Error accessing microphone:', error);
            this.showMessage('Failed to access microphone: ' + error.message, 'error');
        }
        this.updateUI();
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
            const participantDiv = document.createElement('div');
            participantDiv.className = 'participant connected';
            participantDiv.innerHTML = `
                <div class="participant-avatar">🎤</div>
                <div class="participant-name">${peerId.split('_')[1] || 'Peer'}</div>
                <div class="participant-status">Connected</div>
                <audio autoplay></audio>
            `;
            
            const audio = participantDiv.querySelector('audio');
            audio.srcObject = stream;
            
            this.participantsGrid.appendChild(participantDiv);
        });
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