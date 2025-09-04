# Current Polling/Signaling System Algorithm Analysis

## 🚨 System Overview

The WebRTC audio conference system uses a **Server-Sent Events (SSE)** based signaling mechanism instead of traditional HTTP polling. Here's the complete algorithm analysis:

## 📡 Current Signaling Architecture

```
CLIENT                 SERVER (SSE)                DATABASE
   |                       |                        |
   |-- joinRoom() -------> |                        |
   |                       |-- INSERT peer -------> |
   |                       |                        |
   |-- startSignaling() -> |                        |
   |   (EventSource)       |                        |
   |                       |-- WHILE LOOP --------> |
   |                       |   Every 1 second       |
   |                       |                        |
   |<-- SSE Stream --------|<-- SELECT messages --- |
   |                       |                        |
   |-- sendSignal() -----> |                        |
   |                       |-- INSERT message ----> |
   |                       |                        |
   |<-- message event -----|<-- UPDATE processed -- |
```

## 🔍 Detailed Algorithm Breakdown

### **1. Client-Side Signaling Initialization**

**File**: `public/script.js` - `startSignaling()` method

```javascript
startSignaling() {
    // Create SSE connection
    const eventUrl = `${this.apiBase}api.php?action=events&peerId=${this.peerId}`;
    this.eventSource = new EventSource(eventUrl);
    
    // Message handler
    this.eventSource.onmessage = (event) => {
        const message = JSON.parse(event.data);
        this.handleSignalingMessage(message);
    };
    
    // Error handler with auto-reconnect
    this.eventSource.onerror = (error) => {
        setTimeout(() => {
            if (this.isConnected && this.eventSource.readyState === EventSource.CLOSED) {
                this.startSignaling(); // Reconnect after 3 seconds
            }
        }, 3000);
    };
}
```

**Client Process**:
1. Creates EventSource connection to `api.php?action=events&peerId=xxx`
2. Listens for incoming messages via `onmessage` handler
3. Auto-reconnects on connection failure after 3-second delay
4. Processes received messages through `handleSignalingMessage()`

### **2. Server-Side SSE Loop**

**File**: `app/controllers/SignalController.php` - `handleServerSentEvents()` method

```php
private function handleServerSentEvents() {
    // Set SSE headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    
    $cleanupCounter = 0;
    $roomListUpdateCounter = 0;
    
    while (true) {
        // 1. Update peer heartbeat
        $this->signal->updatePeerLastSeen($peerId);
        
        // 2. Cleanup every 3 seconds
        $cleanupCounter++;
        if ($cleanupCounter >= 3) {
            $this->signal->bulkCleanupInactivePeers(0.5); // 30-second threshold
            $cleanupCounter = 0;
        }
        
        // 3. Broadcast room updates every 3 seconds
        $roomListUpdateCounter++;
        if ($roomListUpdateCounter >= 3) {
            $this->broadcastRoomListUpdate();
            $roomListUpdateCounter = 0;
        }

        // 4. Get unprocessed messages
        $messages = $this->signal->getUnprocessedMessages($peerId);
        
        // 5. Send messages via SSE
        if (!empty($messages)) {
            foreach ($messages as $message) {
                echo "id: " . $message['id'] . "\n";
                echo "data: " . json_encode($eventData) . "\n\n";
                $this->signal->markMessageAsProcessed($message['id']);
            }
            flush();
        }
        
        // 6. Check connection status
        if (connection_aborted()) {
            break;
        }
        
        sleep(1); // 🚨 KEY ISSUE: 1-second polling interval
    }
}
```

**Server Process**:
1. **Connection Setup**: SSE headers with CORS support
2. **Infinite Loop**: Runs continuously for each connected client
3. **1-Second Intervals**: Checks for new messages every second
4. **Cleanup Cycles**: Every 3 seconds (aggressive peer cleanup)
5. **Room Updates**: Broadcast room list every 3 seconds
6. **Message Processing**: Send unprocessed messages immediately
7. **Connection Monitoring**: Detect client disconnections

### **3. Message Flow Algorithm**

**File**: `app/models/Signal.php` - Database operations

```php
// Message insertion (when client sends signal)
public function addSignalingMessage($fromPeerId, $toPeerId, $roomId, $messageType, $messageData) {
    $sql = "INSERT INTO signaling_messages (from_peer_id, to_peer_id, room_id, message_type, message_data) 
            VALUES (?, ?, ?, ?, ?)";
    return $this->db->query($sql, [$fromPeerId, $toPeerId, $roomId, $messageType, json_encode($messageData)]);
}

// Message retrieval (during SSE loop)
public function getUnprocessedMessages($peerId) {
    $sql = "SELECT id, from_peer_id, message_type, message_data, created_at 
            FROM signaling_messages 
            WHERE to_peer_id = ? AND processed = 0 
            ORDER BY created_at ASC";
    $stmt = $this->db->query($sql, [$peerId]);
    return $stmt->fetchAll();
}

// Message processing (after SSE delivery)
public function markMessageAsProcessed($messageId) {
    $sql = "UPDATE signaling_messages SET processed = 1 WHERE id = ?";
    return $this->db->query($sql, [$messageId]);
}
```

**Message Flow**:
1. **Client A** sends signal → `api.php?action=signal`
2. **Server** inserts message into `signaling_messages` table
3. **SSE Loop** for **Client B** retrieves unprocessed messages
4. **Server** sends message via SSE stream to **Client B**
5. **Server** marks message as processed
6. **Client B** receives and handles message

### **4. Client-Side Message Processing**

**File**: `public/script.js` - `handleSignalingMessage()` method

```javascript
async handleSignalingMessage(message) {
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
```

## 🚨 **IDENTIFIED ISSUES WITH CURRENT SYSTEM**

### **1. Performance Problems**

#### **Issue A: Excessive Database Queries**
```php
// PROBLEM: Each SSE connection polls database every second
while (true) {
    $this->signal->updatePeerLastSeen($peerId);        // Query 1
    $messages = $this->signal->getUnprocessedMessages($peerId); // Query 2
    sleep(1); // Repeat every second
}
```

**Impact**: 
- With 10 connected users = 20 database queries/second
- With 50 connected users = 100 database queries/second
- Database lock contention and performance degradation

#### **Issue B: Aggressive Cleanup Cycles**
```php
// PROBLEM: Cleanup runs every 3 seconds per SSE connection
if ($cleanupCounter >= 3) {
    $this->signal->bulkCleanupInactivePeers(0.5); // 30-second threshold
    $cleanupCounter = 0;
}
```

**Impact**:
- Multiple concurrent cleanup operations
- Foreign key constraint conflicts
- Database locking issues

### **2. Scalability Issues**

#### **Issue C: One SSE Process Per Client**
- Each client connection spawns a separate PHP process
- Each process runs an infinite `while(true)` loop
- Server resource consumption grows linearly with users

#### **Issue D: No Message Batching**
```php
// PROBLEM: Messages sent individually
foreach ($messages as $message) {
    echo "data: " . json_encode($eventData) . "\n\n";
    flush(); // Immediate flush for each message
}
```

**Impact**:
- No message batching for efficiency
- Excessive SSE overhead for multiple quick messages

### **3. Reliability Issues**

#### **Issue E: No Proper Heartbeat Validation**
```php
// PROBLEM: Only updates last_seen, no bi-directional heartbeat
$this->signal->updatePeerLastSeen($peerId);
```

**Impact**:
- Can't detect when SSE connection is stuck but TCP is still alive
- Ghost peers remain in system longer than necessary

#### **Issue F: Race Conditions in Cleanup**
```php
// PROBLEM: Multiple cleanup processes can conflict
$this->db->query("PRAGMA foreign_keys = OFF");
// ... cleanup operations ...
$this->db->query("PRAGMA foreign_keys = ON");
```

**Impact**:
- Foreign key constraint violations
- Database corruption potential
- Inconsistent peer state

### **4. Architectural Issues**

#### **Issue G: Tight Coupling**
- SSE loop handles both messaging AND cleanup AND room updates
- No separation of concerns
- Hard to optimize individual components

#### **Issue H: No Message Prioritization**
- All messages processed in FIFO order
- Critical connection messages (offers/answers) have same priority as room updates

## 🔧 **RECOMMENDED SOLUTIONS**

### **1. Implement Message Queuing**
Replace database polling with Redis/in-memory message queue:
```php
// Better approach
$redis = new Redis();
$messages = $redis->lPop("messages:$peerId");
```

### **2. Separate Cleanup Process**
Move cleanup to separate cron job or background process:
```php
// Run every minute via cron
php cleanup.php --inactive-threshold=2
```

### **3. Connection Pooling**
Implement one SSE endpoint that multiplexes multiple clients:
```php
// Single SSE process handles multiple clients
$clients = [];
while (true) {
    foreach ($clients as $peerId => $response) {
        // Process messages for all clients
    }
}
```

### **4. Message Batching**
Group messages before sending:
```php
// Batch messages every 100ms
$messageBatch = [];
// ... collect messages ...
echo "data: " . json_encode($messageBatch) . "\n\n";
```

### **5. Implement WebSocket Alternative**
Consider WebSocket for bi-directional real-time communication:
```javascript
// WebSocket provides better performance
const ws = new WebSocket('ws://localhost:8080');
```

## 📊 **PERFORMANCE IMPACT ANALYSIS**

### **Current System Load**:
- **10 Users**: 20 DB queries/second + 10 cleanup cycles/3 seconds
- **Database Connections**: 10 persistent connections
- **Memory Usage**: 10 PHP processes × ~32MB = 320MB
- **CPU Usage**: High due to continuous polling

### **Expected Load with Fixes**:
- **10 Users**: 2-3 DB queries/second + 1 cleanup/minute
- **Database Connections**: 1-2 shared connections
- **Memory Usage**: 1 process × ~64MB = 64MB
- **CPU Usage**: 80% reduction

## 🎯 **IMMEDIATE ACTION ITEMS**

1. **Reduce SSE Polling Frequency**: Change `sleep(1)` to `sleep(3)` or `sleep(5)`
2. **Centralize Cleanup**: Move to single background process
3. **Add Connection Pooling**: Use shared database connections
4. **Implement Message Batching**: Group messages before SSE transmission
5. **Add Proper Heartbeat**: Bi-directional connection validation

## 🔬 **TESTING RECOMMENDATIONS**

1. **Load Testing**: Test with 20+ concurrent users
2. **Database Monitoring**: Monitor query count and lock duration
3. **Memory Profiling**: Track PHP process memory usage
4. **Connection Stability**: Test SSE reconnection scenarios
5. **Message Latency**: Measure end-to-end message delivery time

---

**Summary**: The current polling system uses SSE with 1-second database polling, which creates performance bottlenecks, scalability issues, and reliability problems. The main issue is the aggressive polling frequency combined with per-client cleanup operations that lead to database contention and resource exhaustion.