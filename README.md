# 🎙️ 4-Person Audio Conference WebRTC Application

## Features
- **4-Person Audio Conference**: Maximum 4 participants per room
- **Smart Room Management**: 3-digit + timestamp room IDs prevent duplicates
- **Auto-Join New Rooms**: Click "New Room" to create and automatically join
- **Multi-Device Support**: Works across different devices on the same network
- **Network Access**: Automatic IP detection and sharing URLs
- **Audio-Only Communication**: Optimized for voice chat
- **Real-time Signaling**: Server-Sent Events (SSE) with SQLite
- **Mute/Unmute Controls**: Individual microphone control
- **Room Management**: Automatic capacity limiting
- **Cross-Platform**: Works on any modern browser

## Quick Start

### 1. Start XAMPP
- Start Apache service in XAMPP Control Panel

### 2. Setup Database
- Open: `http://localhost/eng7/setup_db.php`
- Verify database creation and tables

### 3. HTTPS Configuration (Optional but Recommended)
- **Automatic**: HTTPS is enforced via `.htaccess` for production environments
- **Development**: HTTP allowed for localhost testing
- **Setup Guide**: Visit `http://localhost/eng7/https_setup.php` for detailed SSL configuration
- **Note**: Microphone access requires HTTPS on most browsers for network access

### 4. Network Setup (For Multi-Device Access)
- **Important**: For different devices to connect, configure network access
- Open: `http://localhost/eng7/network_setup.php`
- Follow the instructions to:
  - Find your computer's IP address
  - Configure Windows Firewall
  - Get the network access URL for other devices

### 5. Test Application
- Open: `http://localhost/eng7` (or `https://localhost/eng7` if HTTPS is configured)
- Enter your username
- Click "Join Room" 
- Click "Join Audio" to start microphone
- Open multiple browser tabs/windows to test 4-person conference

## Usage Instructions

### Joining a Conference
1. Enter your username (optional)
2. **Option A**: Enter existing room ID (3-digit format like "123_1756845123")
3. **Option B**: Click "New Room" to create a unique room with automatic join
4. **Option C**: Browse and click on available rooms in the "Available Rooms" section
5. Click "Join Room" (if entering existing room ID manually)
6. You'll automatically connect with audio enabled

### Clickable Room Interface
- **Visual Room Cards**: See all available rooms displayed as clickable cards
- **Real-time Participant Count**: Each room shows current participants (e.g., "2/4")
- **Click-to-Join**: Simply click on any room card to join that room instantly
- **Room Status Indicators**: Full rooms (4/4) are visually disabled
- **Current Room Highlighting**: Your current room is highlighted in green
- **Automatic Refresh**: Room list updates when joining/leaving rooms
- **Auto-generated Usernames**: System generates usernames if none provided
- **Smart Room Filtering**: Empty rooms are automatically hidden from the interface
  - Rooms with no participants don't appear in the room list
  - When all participants leave a room, it disappears from the interface
  - Room data is preserved in database for potential future use
  - Keeps the interface clean and shows only joinable rooms

### Room ID Format
- **New Format**: 3-digit number + timestamp (e.g., "456_1756845123")
- **Benefits**: Prevents duplicate rooms, easy to share, timestamp-based uniqueness
- **Generation**: Automatic via "New Room" button or manual entry

### Audio Controls
- **Join Audio**: Enable microphone and join voice chat
- **Leave Audio**: Disable microphone
- **Mute**: Temporarily mute your microphone
- **Unmute**: Unmute your microphone

### Room Limits
- Maximum 4 participants per room
- New users cannot join when room is full
- Real-time capacity display (0/4, 1/4, etc.)

## Technical Details

### Architecture
```
Browser (JS) ↔ PHP SSE Signaling ↔ SQLite Database
     ↓
WebRTC P2P Audio Connections
```

### Room Capacity Logic
- Server checks participant count before allowing joins
- Returns error 403 if room is full (4 people)
- Real-time updates show current capacity

### Audio Features
- Echo cancellation enabled
- Noise suppression enabled
- Auto gain control enabled
- Optimized for voice communication

## API Endpoints
- `POST /api.php?action=join` - Join room (with capacity check)
- `POST /api.php?action=leave` - Leave room
- `POST /api.php?action=signal` - Send WebRTC signals
- `GET /api.php?action=events` - SSE stream for real-time updates
- `GET /api.php?action=peers` - Get room participants
- `POST /api.php?action=create_room` - Create new room with unique 3-digit + timestamp ID
- `GET /api.php?action=list_rooms` - Get all available rooms for clickable interface
- `GET /api.php?action=cleanup` - Manual cleanup of inactive peers and old messages

## Database Schema
- **rooms**: Conference room management
- **peers**: Active participants (max 4 per room)
- **signaling_messages**: WebRTC signaling data

## Multi-Device Testing

### Same Computer (Local Testing)
1. Open `http://localhost/eng7` in 4 different browser tabs
2. Use different usernames in each tab
3. Join the same room ID
4. Enable audio in each tab
5. Test voice communication between all participants

### Different Devices (Network Testing)
1. **Setup Host Computer**:
   - Run `http://localhost/eng7/network_setup.php` to get network configuration
   - Note your computer's IP address (e.g., 192.168.1.100)
   - Configure Windows Firewall to allow Apache

2. **Connect Other Devices**:
   - Phones, tablets, laptops on the same WiFi network
   - Open `http://[HOST-IP]:80/eng7/` (replace [HOST-IP] with actual IP)
   - Example: `http://192.168.1.100/eng7/`

3. **Test Conference**:
   - Each device joins with different username
   - All join the same room ID
   - Enable audio on each device
   - Test voice communication between all participants

## Browser Compatibility
- Chrome/Chromium (recommended)
- Firefox
- Safari
- Edge

**Note**: Microphone access requires HTTPS in production environments.

## Troubleshooting

### Network Access Issues
- **Connection Refused**: Check Windows Firewall settings for Apache
- **Page Not Loading**: Verify XAMPP Apache service is running
- **Wrong IP Address**: Use network IP (192.168.x.x), not localhost (127.0.0.1)
- **Cross-Device Issues**: Ensure all devices are on the same WiFi network

### Audio Issues
- **No Audio**: Check microphone permissions in browser
- **Echo/Feedback**: Use headphones or ensure proper microphone placement
- **Poor Quality**: Check network connection and reduce background noise

### Browser Compatibility Issues
- **Microphone Access**: Some browsers require HTTPS for microphone access
- **For Production**: Use HTTPS and proper SSL certificates
- **Local Testing**: Most browsers allow microphone access on localhost

### General Issues
- Check microphone permissions in browser
- Ensure browser allows media access for localhost
- Verify audio devices are working

### Connection Issues
- Check XAMPP Apache is running
- Verify database setup completed successfully
- Check browser console for errors

### Room Full Error
- Only 4 people can join the same room
- Try a different room ID
- Wait for someone to leave the current room
- **Quick Fix**: Run `http://localhost/eng7/cleanup_db.php` to clear all inactive participants

### Database Cleanup
- **Automatic**: Inactive peers are automatically removed after 5 minutes
- **Manual**: Visit `http://localhost/eng7/cleanup_db.php` to force cleanup all rooms
- **API**: Call `http://localhost/eng7/public/api.php?action=cleanup` for programmatic cleanup

Enjoy your 4-person audio conference! 🎙️