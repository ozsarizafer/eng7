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
        
        // Get the current host and port for API calls
        this.apiBase = this.getApiBase();
        
        // WebRTC configuration with STUN servers for NAT traversal
        this.rtcConfig = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
                { urls: 'stun:stun2.l.google.com:19302' },
                { urls: 'stun:stun3.l.google.com:19302' },
                { urls: 'stun:stun4.l.google.com:19302' }
            ]
        };

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
        this.leaveBtn = document.getElementById('leaveBtn');
        this.startAudioBtn = document.getElementById('startAudioBtn');
        this.stopAudioBtn = document.getElementById('stopAudioBtn');
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

        // Set peer ID display
        this.peerIdDisplay.textContent = this.peerId;
    }

    bindEvents() {
        this.joinBtn.addEventListener('click', () => this.joinRoom());
        this.leaveBtn.addEventListener('click', () => this.leaveRoom());
        this.startAudioBtn.addEventListener('click', () => this.startAudio());
        this.stopAudioBtn.addEventListener('click', () => this.stopAudio());
        this.muteBtn.addEventListener('click', () => this.muteAudio());
        this.unmuteBtn.addEventListener('click', () => this.unmuteAudio());

        // Handle page unload
        window.addEventListener('beforeunload', () => {
            this.leaveRoom();
        });
    }

    generatePeerId() {
        return 'peer_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
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
            // Try to get a hint about local IP using WebRTC
            const pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
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
            console.log('Could not detect local IP automatically');
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
                this.startSignaling();
                this.loadPeers();
                
                // Auto-start audio when joining
                await this.startAudio();
                
                // Create connections to existing peers
                for (const peer of this.existingPeers) {
                    await this.createOfferToPeer(peer.peer_id);
                }
                
                this.showMessage('Successfully joined audio conference!', 'success');
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
            // Stop media streams
            this.stopAllMedia();

            // Close all peer connections
            this.peerConnections.forEach((pc, peerId) => {
                pc.close();
            });
            this.peerConnections.clear();
            this.remoteStreams.clear();

            // Stop signaling
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }

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
            
        } catch (error) {
            console.error('Leave room error:', error);
        }

        this.updateUI();
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
        this.loadPeers();
        
        // Close connection to departed peer
        if (this.peerConnections.has(data.peerId)) {
            this.peerConnections.get(data.peerId).close();
            this.peerConnections.delete(data.peerId);
        }
        
        // Remove remote stream
        if (this.remoteStreams.has(data.peerId)) {
            this.remoteStreams.delete(data.peerId);
        }
        
        this.updateRemoteParticipants();
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
            const candidate = new RTCIceCandidate(message.data);
            await peerConnection.addIceCandidate(candidate);
        }
    }

    async createPeerConnection(peerId) {
        const peerConnection = new RTCPeerConnection(this.rtcConfig);
        this.peerConnections.set(peerId, peerConnection);

        // Handle ICE candidates
        peerConnection.onicecandidate = async (event) => {
            if (event.candidate) {
                await this.sendSignal(peerId, 'ice-candidate', event.candidate);
            }
        };

        // Handle remote stream
        peerConnection.ontrack = (event) => {
            console.log('Received remote stream from:', peerId);
            const remoteStream = event.streams[0];
            this.remoteStreams.set(peerId, remoteStream);
            this.updateRemoteParticipants();
        };

        // Add local stream if available
        if (this.localStream) {
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
            this.localStream = await navigator.mediaDevices.getUserMedia({ 
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                }
            });
            this.localAudio.srcObject = this.localStream;
            
            // Add audio track to all existing peer connections
            this.peerConnections.forEach((pc, peerId) => {
                this.localStream.getTracks().forEach(track => {
                    pc.addTrack(track, this.localStream);
                });
            });
            
            this.updateLocalParticipant();
            this.showMessage('Audio started - you can now speak', 'success');
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
        this.leaveBtn.disabled = !this.isConnected;
        this.startAudioBtn.disabled = !this.isConnected || hasAudio;
        this.stopAudioBtn.disabled = !hasAudio;
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
}

// Initialize Audio Conference client when page loads
let audioConference;
document.addEventListener('DOMContentLoaded', () => {
    audioConference = new AudioConferenceClient();
});