<?php
require_once 'db.php';

// Function to check user's role access
function hasAccess($required_roles, $user_role, $committee_role) {
    // Admin or Organizer has access to everything
    if ($user_role === 'admin' || $user_role === 'organizer') {
        return true;
    }

    // For members with specific committee roles
    if ($user_role === 'member' && !empty($committee_role)) {
        $committee_role = strtolower($committee_role);  // Convert to lowercase
        $required_roles = array_map('strtolower', $required_roles);  // Make required roles lowercase
        return in_array($committee_role, $required_roles);
    }

    // Additional roles (e.g., Chairman, Secretary, Treasurer)
    if ($committee_role === 'Chairman' && in_array(strtolower($committee_role), $required_roles)) {
        return true;
    }

    if ($committee_role === 'Secretary' && in_array(strtolower($committee_role), $required_roles)) {
        return true;
    }

    if ($committee_role === 'Treasurer' && in_array(strtolower($committee_role), $required_roles)) {
        return true;
    }

    // If none of the above matched, return false (user has no access)
    return false;
}

// Set timezone to match your requirements
date_default_timezone_set('Africa/Nairobi');

//Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) . 
                    '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
}

// Get the event context URL
$base_url = getEventContextURL();

// Get current datetime
$now = new DateTime();
$currentDate = $now->format('Y-m-d');
$currentTime = $now->format('H:i:s');

try {
    // First, update meeting statuses based on current time
    $updateStatusQuery = "
        UPDATE meetings 
        SET status = CASE
            WHEN meeting_date = ? AND meeting_time <= ? AND end_time > ? 
                THEN 'In Progress'
            WHEN (meeting_date < ? OR (meeting_date = ? AND end_time <= ?)) 
                THEN 'Ended'
            ELSE 'Scheduled'
        END
        WHERE status != 'Cancelled'";
        
    $stmt = $conn->prepare($updateStatusQuery);
    $stmt->bind_param("ssssss", 
        $currentDate, $currentTime, $currentTime,
        $currentDate, $currentDate, $currentTime
    );
    $stmt->execute();

    // Check for active meeting
    $activeQuery = "
        SELECT * FROM meetings 
        WHERE meeting_date = ? 
        AND meeting_time <= ? 
        AND end_time > ? 
        AND status = 'In Progress'
        ORDER BY meeting_time ASC 
        LIMIT 1";
        
    $stmt = $conn->prepare($activeQuery);
    $stmt->bind_param("sss", $currentDate, $currentTime, $currentTime);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $meeting = $result->fetch_assoc();
        $meetingStatus = ['status' => 'active', 'meeting' => $meeting];
    } else {
        // Check for next upcoming meeting
        $upcomingQuery = "
            SELECT * FROM meetings 
            WHERE (meeting_date > ? OR (meeting_date = ? AND meeting_time > ?))
            AND status = 'Scheduled'
            ORDER BY meeting_date ASC, meeting_time ASC 
            LIMIT 1";
            
        $stmt = $conn->prepare($upcomingQuery);
        $stmt->bind_param("sss", $currentDate, $currentDate, $currentTime);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $meetingStatus = ['status' => 'upcoming', 'meeting' => $result->fetch_assoc()];
        } else {
            $meetingStatus = ['status' => 'none'];
        }
    }
} catch (Exception $e) {
    error_log("Error in meeting status update: " . $e->getMessage());
    $meetingStatus = ['status' => 'error', 'message' => $e->getMessage()];
}

// Add this debugging section
error_log("Current Date: " . $currentDate);
error_log("Current Time: " . $currentTime);
error_log("Meeting Status: " . print_r($meetingStatus, true));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management Dashboard</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <style>
      * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

:root {
    --sidebar-width: 280px;
    --collapsed-width: 70px;
    --header-height: 70px;
    --primary-color: #0ef;
    --dark-bg: #081b29;
    --card-bg: rgba(8, 27, 41, 0.95);
    --hover-bg: rgba(0, 238, 255, 0.1);
    --border-color: rgba(0, 238, 255, 0.3);
    --text-primary: #ffffff;
    --text-secondary: rgba(255, 255, 255, 0.7);
}

body {
    min-height: 100vh;
    background: var(--dark-bg);
    overflow-x: hidden;
    color: var(--text-primary);
}

/* Sidebar Styles */
.sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: var(--sidebar-width);
            background: var(--card-bg);
            border-right: 1px solid var(--border-color);
            transition: all 0.3s ease;
            z-index: 100;
            backdrop-filter: blur(10px);
        }

        .sidebar.collapse {
            width: var(--collapsed-width);
        }

        .sidebar-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-header h2 {
            color: var(--text-primary);
            font-size: 1.25rem;
            margin-left: 15px;
            font-weight: 600;
            white-space: nowrap;
            transition: all 0.3s ease;
        }

        .sidebar.collapse .sidebar-header h2 {
            opacity: 0;
        }

        .sidebar-menu {
            padding: 15px 0;
            height: calc(100% - var(--header-height));
            overflow-y: auto;
        }
                 /* Hide sidebar by default for unauthorized users */
       .sidebar.hidden {
        display: none;
             }

           /* Show sidebar for authorized users */
       .sidebar.visible {
          display: block;
         }

        .menu-category {
            margin: 15px 0;
        }

        .category-title {
            color: var(--primary-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            padding: 10px 25px;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .sidebar.collapse .category-title {
            opacity: 0;
        }

        .menu-item {
            padding: 12px 25px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            margin: 4px 0;
        }

        .menu-item a {
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .menu-item:hover {
            background: var(--hover-bg);
        }

        .menu-item.active {
            background: var(--hover-bg);
        }

        .menu-item i {
            font-size: 1.5rem;
            min-width: 35px;
            color: var(--primary-color);
            transition: all 0.3s ease;
        }

        .menu-item span {
            color: var(--text-secondary);
            white-space: nowrap;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .menu-item:hover span {
            color: var(--text-primary);
        }

        .sidebar.collapse .menu-item span {
            opacity: 0;
        }

        .menu-item::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 3px;
            height: 100%;
            background: var(--primary-color);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .menu-item:hover::after,
        .menu-item.active::after {
            opacity: 1;
        }


/* Main Container */
.main-container {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding: 20px;
    margin-left: var(--sidebar-width);
    transition: margin-left 0.3s ease;
    position: relative;
}

.sidebar.collapse + .main-container {
    margin-left: var(--collapsed-width);
}

/* Content Section */
.content-section {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 25px;
    margin: 20px auto;
    max-width: 1200px;
    width: calc(100% - 40px);
    backdrop-filter: blur(10px);
    position: relative;
}
.popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(8, 27, 41, 0.95);
            backdrop-filter: blur(8px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .popup-content {
            background: linear-gradient(145deg, rgba(8, 27, 41, 0.95), rgba(13, 41, 62, 0.95));
            border: 2px solid rgba(0, 238, 255, 0.3);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 500px;
            width: 90%;
            animation: popup-appear 0.4s ease-out;
            box-shadow: 0 0 30px rgba(0, 238, 255, 0.15);
        }

        .meeting-card {
            background: rgba(8, 27, 41, 0.9);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .meeting-status {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 15px;
        }

        .status-scheduled {
            background: rgba(0, 238, 255, 0.1);
            color: var(--primary-color);
        }

        .status-active {
            background: rgba(46, 213, 115, 0.1);
            color: #2ed573;
        }

        .status-ended {
            background: rgba(255, 71, 87, 0.1);
            color: #ff4757;
        }

        .meeting-timer {
            font-family: 'Courier New', monospace;
            font-size: 2.8rem;
            color: var(--primary-color);
            text-shadow: 0 0 15px rgba(0, 238, 255, 0.3);
            margin: 25px 0;
            font-weight: 700;
        }

        /* Video Conference Styles */
        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .video-container {
            position: relative;
            border-radius: 15px;
            overflow: hidden;
            background: rgba(8, 27, 41, 0.9);
            aspect-ratio: 16/9;
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease;
        }

        .video-container:hover {
            transform: scale(1.02);
        }

        .controls-container {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 15px;
            padding: 15px 25px;
            background: rgba(8, 27, 41, 0.9);
            border-radius: 50px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .control-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            background: var(--primary-color);
            color: var(--dark-bg);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .control-btn i {
            font-size: 1.5rem;
        }

        .control-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 0 15px rgba(0, 238, 255, 0.3);
        }

        .control-btn.active {
            background: #ff4757;
            color: white;
        }

/* Animation */
@keyframes popup-appear {
    0% { 
        opacity: 0; 
        transform: scale(0.9) translateY(-20px); 
    }
    100% { 
        opacity: 1; 
        transform: scale(1) translateY(0); 
    }
}

/* Responsive Styles */
@media screen and (max-width: 768px) {
    .main-container {
        margin-left: var(--collapsed-width);
        padding: 10px;
    }

    .content-section {
        margin: 10px auto;
        padding: 15px;
        width: calc(100% - 20px);
    }

    .popup-content {
        width: 95%;
        padding: 20px;
    }
}
    </style>
</head>
<body>
<!-- Sidebar Navigation -->
<nav class="sidebar">
    <div class="sidebar-header">
        <i class='bx bx-calendar-event' style="color: #0ef; font-size: 24px;"></i>
        <h2>Dantico Events</h2>
    </div>
    <div class="sidebar-menu">
        <!-- Dashboard -->
        <div class="menu-category">
            <div class="menu-item active">
                <a href="./dashboard.php<?= $base_url ?>">
                    <i class='bx bx-home-alt'></i>
                    <span>Dashboard</span>
                </a>
            </div>
        </div>

        <!-- Committees Section -->
        <div class="menu-category">
            
            <div class="menu-item">
                <a href="./committee-list.php<?= $base_url ?>">
                    <i class='bx bx-group'></i>
                    <span>Committee List</span>
                </a>
            </div>
        </div>

        <!-- Communication Section -->
        <div class="menu-category">
            <div class="category-title">Communication</div>
            <div class="menu-item">
                <a href="./chat.php<?= $base_url ?>">
                    <i class='bx bx-message-rounded-dots'></i>
                    <span>Chat System</span>
                    <div class="notification-badge">3</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="./video-conference.php<?= $base_url ?>">
                    <i class='bx bx-video'></i>
                    <span>Video Conference</span>
                </a>
            </div>
        </div>

        <!-- Contributions Section -->
        <div class="menu-category">
            <div class="category-title">Contributions</div>
            <div class="menu-item">
                <a href="./make_contribution.php<?= $base_url ?>">
                    <i class='bx bx-plus-circle'></i>
                    <span>Make Contributions</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./contributions.php<?= $base_url ?>">
                    <i class='bx bx-money'></i>
                    <span>Contributions</span>
                </a>
            </div>
        </div>

        <!-- Reviews Section -->
        <div class="menu-category">
            
            <div class="menu-item">
                <a href="./tasks.php<?= $base_url ?>">
                    <i class='bx bx-task'></i>
                    <span>Tasks</span>
                </a>
            </div>
            
        </div>

        <!-- Other Tools -->
        <div class="menu-category">
            <div class="category-title">Tools</div>
            <div class="menu-item">
                <a href="./schedule.php<?= $base_url ?>">
                    <i class='bx bx-calendar'></i>
                    <span>Schedule</span>
                </a>
            </div>
          
        </div>
    </div>
</nav>

<div class="main-container">
        <div class="content-section">
            <?php if ($meetingStatus['status'] === 'active' && isset($meetingStatus['meeting'])): ?>
                <!-- Active Meeting Interface -->
                <div class="meeting-info">
                    <h2><?= htmlspecialchars($meetingStatus['meeting']['meeting_type']) ?> Meeting</h2>
                    <p>Started: <?= formatMeetingTime($meetingStatus['meeting']['meeting_time']) ?></p>
                    <p>Ends: <?= formatMeetingTime($meetingStatus['meeting']['end_time']) ?></p>
                    <button id="join-btn" class="btn">Join Meeting</button>
                </div>
                
                <!-- Video Conference Interface -->
                <div id="video-streams"></div>
                <div id="stream-controls">
                    <button id="leave-btn" class="control-btn">Leave</button>
                    <button id="mic-btn" class="control-btn">Mic On</button>
                    <button id="camera-btn" class="control-btn">Camera On</button>
                </div>

                <!-- Auto-end check script -->
                <script>
                    function checkMeetingEnd() {
                        const endTime = new Date("<?= $meetingStatus['meeting']['meeting_date'] ?>T<?= $meetingStatus['meeting']['end_time'] ?>").getTime();
                        if (Date.now() >= endTime) {
                            window.location.reload();
                        }
                    }
                    setInterval(checkMeetingEnd, 30000); // Check every 30 seconds
                </script>

            <?php elseif ($meetingStatus['status'] === 'upcoming' && isset($meetingStatus['meeting'])): ?>
                <!-- Upcoming Meeting Popup -->
                <div class="popup-overlay">
                    <div class="popup-content">
                        <h2>Meeting Starts Soon</h2>
                        <p><?= htmlspecialchars($meetingStatus['meeting']['meeting_type']) ?> Meeting</p>
                        <p>Begins at <?= formatMeetingTime($meetingStatus['meeting']['meeting_time']) ?></p>
                        <div class="popup-timer" id="countdown"></div>
                        <script>
                            // Auto-refresh when meeting time arrives
                            const meetingStart = new Date("<?= $meetingStatus['meeting']['meeting_date'] ?>T<?= $meetingStatus['meeting']['meeting_time'] ?>").getTime();
                            
                            function updateTimer() {
                                const now = Date.now();
                                const diff = meetingStart - now;
                                
                                if (diff <= 0) {
                                    window.location.reload();
                                    return;
                                }
                                
                                const hours = Math.floor(diff / (1000 * 60 * 60));
                                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                                const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                                
                                document.getElementById('countdown').textContent = 
                                    `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                            }
                            
                            setInterval(updateTimer, 1000);
                            updateTimer();
                        </script>
                    </div>
                </div>

            <?php else: ?>
                <!-- No Active Meetings -->
                <div class="popup-overlay">
                    <div class="popup-content">
                        <h2>No Active Meetings</h2>
                        <p>There are currently no active meetings scheduled.</p>
                        <div class="btn-container">
                            <a href="schedule.php" class="btn">View Schedule</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($meetingStatus['status'] === 'active'): ?>
    <script src="https://cdn.agora.io/sdk/release/AgoraRTC_N-4.18.0.js"></script>
<script>

    // Global variables for video conference
const APP_ID = "85fd346d881b42b39d7a3fd84c178ea4";
const TOKEN = "007eJxTYJiXPOnYFv0qda3m5m0bbp/dfm9f6HfpLSr35p3e8CniwwdWBQYL07QUYxOzFAsLwyQToyRjyxTzROO0FAuTZENzi9REE8+aBekNgYwMhQr/mRgZIBDE52VIScwryUzO100tS80rYWAAAJPlJik=";
const CHANNEL = "dantico-event";

const client = AgoraRTC.createClient({mode:'rtc', codec:'vp8'});
let localTracks = [];
let remoteUsers = {};

// Meeting Manager
const MeetingManager = {
    meetingStatus: '<?php echo $meetingStatus['status']; ?>',
    
    init() {
        this.setupEventListeners();
        if (this.meetingStatus === 'upcoming') {
            this.startCountdown();
        }
    },

    setupEventListeners() {
        // Join button
        const joinBtn = document.getElementById('join-btn');
        if (joinBtn) {
            joinBtn.addEventListener('click', async () => {
                await this.joinMeeting();
            });
        }

        // Controls
        const micBtn = document.getElementById('mic-btn');
        const cameraBtn = document.getElementById('camera-btn');
        const leaveBtn = document.getElementById('leave-btn');

        if (micBtn) {
            micBtn.addEventListener('click', async () => {
                await this.toggleMic(micBtn);
            });
        }

        if (cameraBtn) {
            cameraBtn.addEventListener('click', async () => {
                await this.toggleCamera(cameraBtn);
            });
        }

        if (leaveBtn) {
            leaveBtn.addEventListener('click', async () => {
                await this.leaveMeeting();
            });
        }
    },

    startCountdown() {
        const updateCountdown = () => {
            const now = new Date();
            const meetingTime = new Date('<?php echo isset($meetingData) ? $meetingData['meeting_date'] . ' ' . $meetingData['meeting_time'] : ''; ?>');
            const timeDiff = meetingTime - now;

            if (timeDiff <= 0) {
                // Meeting should start
                window.location.reload();
                return;
            }

            const hours = Math.floor(timeDiff / (1000 * 60 * 60));
            const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((timeDiff % (1000 * 60)) / 1000);

            const countdownElement = document.getElementById('countdown');
            if (countdownElement) {
                countdownElement.textContent = 
                    `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }
        };

        // Update immediately and then every second
        updateCountdown();
        setInterval(updateCountdown, 1000);
    },

    async joinMeeting() {
        try {
            // Hide join button and show controls
            document.getElementById('join-btn').style.display = 'none';
            document.getElementById('stream-controls').style.display = 'flex';

            // Join the channel
            const uid = await client.join(APP_ID, CHANNEL, TOKEN, null);
            
            // Create and publish local tracks
            localTracks = await AgoraRTC.createMicrophoneAndCameraTracks();
            
            // Add local video player
            let player = `<div class="video-container" id="user-container-${uid}">
                            <div class="video-player" id="user-${uid}"></div>
                         </div>`;
            document.getElementById('video-streams').insertAdjacentHTML('beforeend', player);
            
            // Play local video
            localTracks[1].play(`user-${uid}`);
            
            // Publish tracks
            await client.publish(localTracks);
        } catch (error) {
            console.error('Error joining meeting:', error);
            // Reset UI
            document.getElementById('join-btn').style.display = 'block';
            document.getElementById('stream-controls').style.display = 'none';
        }
    },

    async leaveMeeting() {
        try {
            // Stop and close local tracks
            for (let track of localTracks) {
                track.stop();
                track.close();
            }
            
            // Leave the channel
            await client.leave();
            
            // Reset UI
            document.getElementById('video-streams').innerHTML = '';
            document.getElementById('join-btn').style.display = 'block';
            document.getElementById('stream-controls').style.display = 'none';
        } catch (error) {
            console.error('Error leaving meeting:', error);
        }
    },

    async toggleMic(button) {
        if (!localTracks[0]) return;
        
        try {
            if (localTracks[0].muted) {
                await localTracks[0].setMuted(false);
                button.innerHTML = '<i class="bx bx-microphone"></i>';
                button.classList.remove('active');
            } else {
                await localTracks[0].setMuted(true);
                button.innerHTML = '<i class="bx bx-microphone-off"></i>';
                button.classList.add('active');
            }
        } catch (error) {
            console.error('Error toggling microphone:', error);
        }
    },

    async toggleCamera(button) {
        if (!localTracks[1]) return;
        
        try {
            if (localTracks[1].muted) {
                await localTracks[1].setMuted(false);
                button.innerHTML = '<i class="bx bx-video"></i>';
                button.classList.remove('active');
            } else {
                await localTracks[1].setMuted(true);
                button.innerHTML = '<i class="bx bx-video-off"></i>';
                button.classList.add('active');
            }
        } catch (error) {
            console.error('Error toggling camera:', error);
        }
    }
};

// Handle remote users
client.on('user-published', async (user, mediaType) => {
    await client.subscribe(user, mediaType);

    if (mediaType === 'video') {
        // Add remote video container if it doesn't exist
        let player = document.getElementById(`user-container-${user.uid}`);
        if (!player) {
            player = `<div class="video-container" id="user-container-${user.uid}">
                        <div class="video-player" id="user-${user.uid}"></div>
                     </div>`;
            document.getElementById('video-streams').insertAdjacentHTML('beforeend', player);
        }

        // Play remote video
        user.videoTrack.play(`user-${user.uid}`);
    }

    if (mediaType === 'audio') {
        user.audioTrack.play();
    }
});

// Handle user left
client.on('user-left', user => {
    const container = document.getElementById(`user-container-${user.uid}`);
    if (container) {
        container.remove();
    }
});

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', () => {
    MeetingManager.init();
});
<?php endif; ?>
</script>
</body>
</html>