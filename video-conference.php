<?php
session_start();
require_once 'db.php';
require_once 'AgoraTokenBuilder.php';

// Set timezone
date_default_timezone_set('Africa/Nairobi');

// Function to generate base URL with event context
function getEventContextURL() {
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        return sprintf('?event_id=%d&event_code=%s',
            (int)$_SESSION['current_event_id'],
            urlencode($_SESSION['current_event_code'])
        );
    }
    return '';
}

// Function to get Agora credentials specifically for video conference
function getAgoraCredentials() {
    $channelName = isset($_SESSION['current_event_id']) ?
        'dantico-event-' . $_SESSION['current_event_id'] :
        'default-channel';
    
    $config = getAgoraConfig();
    
    return [
        'appId' => $config['appId'],
        'token' => generateAgoraToken($channelName),
        'channel' => $channelName
    ];
}

// Get current datetime
$now = new DateTime();
$currentDate = $now->format('Y-m-d');
$currentTime = $now->format('H:i:s');
$base_url = getEventContextURL();

try {
    // Update meeting statuses
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
        $agoraCredentials = getAgoraCredentials();
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
            $meeting = $result->fetch_assoc();
            $meetingStatus = ['status' => 'upcoming', 'meeting' => $meeting];
        } else {
            $meetingStatus = ['status' => 'none'];
        }
    }

    // Get all participants for the meeting if it's active
    if ($meetingStatus['status'] === 'active') {
        $participantsQuery = "
            SELECT u.id, u.name, u.role 
            FROM users u 
            JOIN meeting_participants mp ON u.id = mp.user_id 
            WHERE mp.meeting_id = ?";
            
        $stmt = $conn->prepare($participantsQuery);
        $stmt->bind_param("i", $meetingStatus['meeting']['id']);
        $stmt->execute();
        $participantsResult = $stmt->get_result();
        $participants = $participantsResult->fetch_all(MYSQLI_ASSOC);
        
        // Add participants to meeting data
        $meetingStatus['meeting']['participants'] = $participants;
    }

} catch (Exception $e) {
    error_log("Error in meeting status update: " . $e->getMessage());
    $meetingStatus = [
        'status' => 'error',
        'message' => 'An error occurred. Please try again later.',
        'debug' => defined('DEBUG') && DEBUG ? $e->getMessage() : null
    ];
}

// Function to format time for display
function formatMeetingTime($time) {
    return date('h:i A', strtotime($time));
}

// Function to check if user is a meeting participant
function isUserParticipant($userId, $participants) {
    if (!isset($participants) || !is_array($participants)) {
        return false;
    }
    return array_search($userId, array_column($participants, 'id')) !== false;
}

// Handle meeting access control
$canJoinMeeting = false;
$accessError = '';

if ($meetingStatus['status'] === 'active') {
    if (!isset($_SESSION['user_id'])) {
        $accessError = 'Please log in to join the meeting.';
    } else if (!isUserParticipant($_SESSION['user_id'], $meetingStatus['meeting']['participants'])) {
        $accessError = 'You are not authorized to join this meeting.';
    } else {
        $canJoinMeeting = true;
    }
}

// Log meeting access attempts
if ($meetingStatus['status'] === 'active') {
    $logQuery = "
        INSERT INTO meeting_access_logs 
        (meeting_id, user_id, access_time, status) 
        VALUES (?, ?, NOW(), ?)";
    
    try {
        $stmt = $conn->prepare($logQuery);
        $status = $canJoinMeeting ? 'success' : 'denied';
        $stmt->bind_param("iis", 
            $meetingStatus['meeting']['id'], 
            $_SESSION['user_id'], 
            $status
        );
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Error logging meeting access: " . $e->getMessage());
    }
}

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
 <!-- Main container -->
 <main class="main-container">
        <div class="content-section">
            <?php if ($meetingStatus['status'] === 'active'): ?>
                <?php if ($canJoinMeeting): ?>
                    <div class="meeting-card">
                        <div class="meeting-status status-active">In Progress</div>
                        <h2><?= htmlspecialchars($meetingStatus['meeting']['meeting_type']) ?> Meeting</h2>
                        <p>Started at: <?= formatMeetingTime($meetingStatus['meeting']['meeting_time']) ?></p>
                        <p>Ends at: <?= formatMeetingTime($meetingStatus['meeting']['end_time']) ?></p>
                        
                        <div id="video-conference" class="video-conference-container">
                            <div id="video-streams" class="video-grid"></div>
                            <div class="controls-container">
                                <button id="join-btn" class="control-btn">Join Meeting</button>
                                <div id="stream-controls" style="display: none;">
                                    <button id="mic-btn" class="control-btn"><i class='bx bx-microphone'></i></button>
                                    <button id="camera-btn" class="control-btn"><i class='bx bx-video'></i></button>
                                    <button id="leave-btn" class="control-btn"><i class='bx bx-log-out'></i></button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="participants-list">
                            <h3>Participants</h3>
                            <ul>
                                <?php foreach ($meetingStatus['meeting']['participants'] as $participant): ?>
                                    <li><?= htmlspecialchars($participant['name']) ?> (<?= htmlspecialchars($participant['role']) ?>)</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="error-message">
                        <?= htmlspecialchars($accessError) ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($meetingStatus['status'] === 'upcoming'): ?>
                <div class="popup-overlay">
                    <div class="popup-content">
                        <h2>Upcoming Meeting</h2>
                        <div class="meeting-status status-scheduled">Scheduled</div>
                        <h3><?= htmlspecialchars($meetingStatus['meeting']['meeting_type']) ?> Meeting</h3>
                        <p>Date: <?= htmlspecialchars($meetingStatus['meeting']['meeting_date']) ?></p>
                        <p>Time: <?= formatMeetingTime($meetingStatus['meeting']['meeting_time']) ?></p>
                        <div class="meeting-timer" id="countdown"></div>
                    </div>
                </div>

            <?php else: ?>
                <div class="popup-overlay">
                    <div class="popup-content">
                        <h2>No Active Meetings</h2>
                        <p>There are currently no active or upcoming meetings scheduled.</p>
                        <a href="schedule.php<?= $base_url ?>" class="btn">View Schedule</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Loading and error overlays -->
    <div id="loading-overlay" class="overlay" hidden>
        <div class="spinner"></div>
    </div>

    <div id="error-overlay" class="overlay" hidden>
        <div class="error-content">
            <h3>Error</h3>
            <p id="error-message"></p>
            <button onclick="hideError()">Close</button>
        </div>
    </div>


    <?php if ($meetingStatus['status'] === 'active'): ?>
        <script src="https://cdn.agora.io/sdk/release/AgoraRTC_N-4.18.0.js"></script>
        <script>
            // Initialize with server-provided credentials
            const agoraConfig = <?= json_encode($agoraCredentials) ?>;
            
            const client = AgoraRTC.createClient({mode:'rtc', codec:'vp8'});
            let localTracks = [];
            let remoteUsers = {};

            const MeetingManager = {
                async init() {
                    this.setupEventListeners();
                    this.setupErrorHandling();
                },

                showLoading() {
                    document.getElementById('loading-overlay').hidden = false;
                },

                hideLoading() {
                    document.getElementById('loading-overlay').hidden = true;
                },

                showError(message) {
                    const errorOverlay = document.getElementById('error-overlay');
                    document.getElementById('error-message').textContent = message;
                    errorOverlay.hidden = false;
                },

                hideError() {
                    document.getElementById('error-overlay').hidden = true;
                },

                async checkDevicePermissions() {
                    try {
                        await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
                        return true;
                    } catch (error) {
                        this.showError('Please enable camera and microphone access');
                        return false;
                    }
                },

                setupEventListeners() {
                    const joinBtn = document.getElementById('join-btn');
                    const micBtn = document.getElementById('mic-btn');
                    const cameraBtn = document.getElementById('camera-btn');
                    const leaveBtn = document.getElementById('leave-btn');

                    if (joinBtn) {
                        joinBtn.addEventListener('click', () => this.joinMeeting());
                    }
                    if (micBtn) {
                        micBtn.addEventListener('click', () => this.toggleMic(micBtn));
                    }
                    if (cameraBtn) {
                        cameraBtn.addEventListener('click', () => this.toggleCamera(cameraBtn));
                    }
                    if (leaveBtn) {
                        leaveBtn.addEventListener('click', () => this.leaveMeeting());
                    }
                },

                setupErrorHandling() {
                    window.onerror = (msg, url, line) => {
                        this.showError(`An error occurred: ${msg}`);
                        return false;
                    };

                    client.on('connection-state-change', (state) => {
                        if (state === 'DISCONNECTED') {
                            this.showError('Connection lost. Please rejoin the meeting.');
                        }
                    });
                },

                async joinMeeting() {
                    try {
                        this.showLoading();

                        if (!await this.checkDevicePermissions()) {
                            return;
                        }

                        document.getElementById('join-btn').style.display = 'none';
                        document.getElementById('stream-controls').style.display = 'flex';

                        const uid = await client.join(
                            agoraConfig.appId,
                            agoraConfig.channel,
                            agoraConfig.token,
                            null
                        );
                        
                        localTracks = await AgoraRTC.createMicrophoneAndCameraTracks();
                        
                        let player = `<div class="video-container" id="user-container-${uid}">
                                        <div class="video-player" id="user-${uid}"></div>
                                     </div>`;
                        document.getElementById('video-streams').insertAdjacentHTML('beforeend', player);
                        
                        localTracks[1].play(`user-${uid}`);
                        await client.publish(localTracks);
                    } catch (error) {
                        console.error('Error joining meeting:', error);
                        this.showError('Failed to join meeting: ' + error.message);
                        document.getElementById('join-btn').style.display = 'block';
                        document.getElementById('stream-controls').style.display = 'none';
                    } finally {
                        this.hideLoading();
                    }
                },

                async leaveMeeting() {
                    try {
                        this.showLoading();
                        for (let track of localTracks) {
                            track.stop();
                            track.close();
                        }
                        await client.leave();
                        document.getElementById('video-streams').innerHTML = '';
                        document.getElementById('join-btn').style.display = 'block';
                        document.getElementById('stream-controls').style.display = 'none';
                    } catch (error) {
                        this.showError('Error leaving meeting: ' + error.message);
                    } finally {
                        this.hideLoading();
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
                        this.showError('Error toggling microphone: ' + error.message);
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
                        this.showError('Error toggling camera: ' + error.message);
                    }
                }
            };

            // Handle remote users
            client.on('user-published', async (user, mediaType) => {
                await client.subscribe(user, mediaType);

                if (mediaType === 'video') {
                    let player = document.getElementById(`user-container-${user.uid}`);
                    if (!player) {
                        player = `<div class="video-container" id="user-container-${user.uid}">
                                    <div class="video-player" id="user-${user.uid}"></div>
                                 </div>`;
                        document.getElementById('video-streams').insertAdjacentHTML('beforeend', player);
                    }
                    user.videoTrack.play(`user-${user.uid}`);
                }

                if (mediaType === 'audio') {
                    user.audioTrack.play();
                }
            });

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

           // Countdown timer for upcoming meetings
           <?php if ($meetingStatus['status'] === 'upcoming'): ?>
                const updateCountdown = () => {
                    const now = new Date();
                    const meetingTime = new Date('<?= $meetingStatus['meeting']['meeting_date'] ?> <?= $meetingStatus['meeting']['meeting_time'] ?>');
                    const timeDiff = meetingTime - now;

                    if (timeDiff <= 0) {
                        // Meeting should start - reload page
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

                // Update countdown immediately and then every second
                updateCountdown();
                const countdownInterval = setInterval(updateCountdown, 1000);

                // Cleanup interval when leaving page
                window.addEventListener('beforeunload', () => {
                    clearInterval(countdownInterval);
                });
                <?php endif; ?>

                // Add connection status indicator
                let connectionStatus = 'disconnected';
                const updateConnectionStatus = (status) => {
                    connectionStatus = status;
                    const statusIndicator = document.createElement('div');
                    statusIndicator.className = `connection-status ${status}`;
                    statusIndicator.textContent = `Connection: ${status}`;
                    
                    const existingStatus = document.querySelector('.connection-status');
                    if (existingStatus) {
                        existingStatus.remove();
                    }
                    
                    document.querySelector('.controls-container').prepend(statusIndicator);
                };

                // Add network quality monitoring
                client.on('network-quality', (stats) => {
                    const qualityIndicator = document.createElement('div');
                    qualityIndicator.className = 'network-quality';
                    qualityIndicator.textContent = `Network Quality: ${stats.downlinkNetworkQuality}`;
                    
                    const existingQuality = document.querySelector('.network-quality');
                    if (existingQuality) {
                        existingQuality.remove();
                    }
                    
                    document.querySelector('.controls-container').prepend(qualityIndicator);
                });

                // Add error handling for common scenarios
                client.on('exception', (event) => {
                    let message = 'An error occurred';
                    switch (event.code) {
                        case 'OPERATION_ABORTED':
                            message = 'The operation was aborted';
                            break;
                        case 'INVALID_PARAMS':
                            message = 'Invalid parameters provided';
                            break;
                        case 'NETWORK_ERROR':
                            message = 'Network connectivity issues detected';
                            break;
                        case 'NETWORK_TIMEOUT':
                            message = 'Network timeout - please check your connection';
                            break;
                    }
                    MeetingManager.showError(message);
                });

                // Add automatic reconnection logic
                let reconnectAttempts = 0;
                const maxReconnectAttempts = 3;

                client.on('connection-state-change', (curState, prevState) => {
                    updateConnectionStatus(curState.toLowerCase());
                    
                    if (curState === 'DISCONNECTED' && prevState === 'CONNECTED') {
                        if (reconnectAttempts < maxReconnectAttempts) {
                            setTimeout(async () => {
                                try {
                                    reconnectAttempts++;
                                    await client.join(
                                        agoraConfig.appId,
                                        agoraConfig.channel,
                                        agoraConfig.token,
                                        null
                                    );
                                    reconnectAttempts = 0;
                                } catch (error) {
                                    MeetingManager.showError('Failed to reconnect: ' + error.message);
                                }
                            }, 2000 * Math.pow(2, reconnectAttempts)); // Exponential backoff
                        } else {
                            MeetingManager.showError('Unable to reconnect after multiple attempts. Please rejoin the meeting.');
                        }
                    }
                });

                // Add device hot-plugging support
                AgoraRTC.onMicrophoneChanged = async (info) => {
                    if (info.state === 'ACTIVE') {
                        try {
                            const audio = await AgoraRTC.createMicrophoneAudioTrack();
                            await client.unpublish(localTracks[0]);
                            localTracks[0] = audio;
                            await client.publish(audio);
                        } catch (error) {
                            MeetingManager.showError('Error switching microphone: ' + error.message);
                        }
                    }
                };

                AgoraRTC.onCameraChanged = async (info) => {
                    if (info.state === 'ACTIVE') {
                        try {
                            const video = await AgoraRTC.createCameraVideoTrack();
                            await client.unpublish(localTracks[1]);
                            localTracks[1] = video;
                            await client.publish(video);
                        } catch (error) {
                            MeetingManager.showError('Error switching camera: ' + error.message);
                        }
                    }
                };
        </script>
    <?php endif; ?>
</body>
</html>