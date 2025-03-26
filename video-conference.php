<?php
session_start();
require_once 'db.php';

// Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) . 
                    '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
}

$base_url = getEventContextURL();
$current_event_id = $_SESSION['current_event_id'] ?? null;

function getCurrentMeetingInfo($conn, $current_event_id) {
    if (!$current_event_id) {
        return [
            'has_meeting' => false,
            'message' => 'No event selected'
        ];
    }

    date_default_timezone_set('Africa/Nairobi');
    $current_time = date('Y-m-d H:i:s');

    // Get all upcoming meetings for this event
    $query = "SELECT 
        meeting_id,
        meeting_type,
        meeting_date,
        meeting_time,
        end_time,
        CONCAT(meeting_date, ' ', meeting_time) as start_datetime,
        CONCAT(meeting_date, ' ', end_time) as end_datetime
    FROM meetings 
    WHERE event_id = ? 
    AND CONCAT(meeting_date, ' ', end_time) >= ?
    ORDER BY CONCAT(meeting_date, ' ', meeting_time) ASC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $current_event_id, $current_time);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $meetings = [];
    while ($row = $result->fetch_assoc()) {
        $meetings[] = $row;
    }

    if (empty($meetings)) {
        return [
            'has_meeting' => false,
            'message' => 'No upcoming meetings scheduled',
            'meetings' => []
        ];
    }

    return [
        'has_meeting' => true,
        'meetings' => $meetings,
        'current_time' => $current_time
    ];
}

// Handle AJAX request
if(isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    echo json_encode(getCurrentMeetingInfo($conn, $current_event_id));
    exit;
}

// Initial meeting info for page load
$initial_meeting_info = getCurrentMeetingInfo($conn, $current_event_id);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <meta http-equiv='X-UA-Compatible' content='IE=edge'>
    <title>Video Conference - Dantico Events</title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: #081b29;
            display: flex;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: 260px;
            background: rgba(8, 27, 41, 0.9);
            border-right: 2px solid #0ef;
            transition: all 0.5s ease;
            z-index: 100;
        }

        .sidebar-header {
            height: 60px;
            display: flex;
            align-items: center;
            padding: 0 15px;
            border-bottom: 2px solid #0ef;
        }

        .sidebar-header h2 {
            color: #fff;
            font-size: 20px;
            margin-left: 15px;
        }

        .menu-category {
            margin: 10px 0;
        }

        .category-title {
            color: #0ef;
            font-size: 12px;
            text-transform: uppercase;
            padding: 10px 20px;
            letter-spacing: 1px;
            opacity: 0.7;
        }

        .menu-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .menu-item a {
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .menu-item i {
            font-size: 24px;
            min-width: 40px;
            color: #0ef;
        }

        .menu-item span {
            color: #fff;
            margin-left: 10px;
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            width: calc(100% - 260px);
        }

        .video-container {
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            border-radius: 15px;
            padding: 30px;
            margin-top: 20px;
            box-shadow: 0 0 25px rgba(0, 238, 255, 0.1);
        }

        #video-streams {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .video-player {
            aspect-ratio: 16/9;
            background: rgba(8, 27, 41, 0.9);
            border: 1px solid rgba(0, 238, 255, 0.2);
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .video-player:hover {
            box-shadow: 0 5px 15px rgba(0, 238, 255, 0.1);
            transform: translateY(-5px);
        }

        #join-btn {
            background: #0ef;
            color: #081b29;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin: 0 auto;
        }

        #join-btn.disabled {
            background: #4a4a4a;
            cursor: not-allowed;
            opacity: 0.6;
            pointer-events: none;
        }

        .meeting-status {
            text-align: center;
            color: #fff;
            margin: 10px 0;
            font-size: 14px;
        }

        .meeting-status.no-meeting {
            color: #ff3333;
        }

        .meeting-status.scheduled {
            color: #00C851;
        }

        .meeting-status.in-progress {
            color: #ffa500;
        }

        #join-btn:hover {
            background: #00c6f0;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 238, 255, 0.2);
        }

        #stream-controls {
            display: none;
            justify-content: center;
            gap: 15px;
            margin-top: 25px;
        }

        .control-btn {
            background: rgba(0, 238, 255, 0.1);
            border: 1px solid #0ef;
            color: #fff;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .control-btn:hover {
            background: rgba(0, 238, 255, 0.2);
            transform: translateY(-2px);
        }

        .control-btn.danger {
            border-color: #ff3333;
            background: rgba(255, 51, 51, 0.1);
        }

        .control-btn.danger:hover {
            background: rgba(255, 51, 51, 0.2);
        }

        .control-btn.muted {
            background: #ff3333;
            border-color: #ff3333;
            color: #fff;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }

            .sidebar-header h2,
            .category-title,
            .menu-item span {
                display: none;
            }

            .main-content {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            #video-streams {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">
            <i class='bx bx-calendar-event' style="color: #0ef; font-size: 24px;"></i>
            <h2>Dantico Events</h2>
        </div>
        <div class="sidebar-menu">
            <div class="menu-category">
                <div class="menu-item">
                    <a href="./dashboard.php<?= $base_url ?>">
                        <i class='bx bx-home-alt'></i>
                        <span>Dashboard</span>
                    </a>
                </div>
            </div>

            <div class="menu-category">
                <div class="menu-item">
                    <a href="./committee-list.php<?= $base_url ?>">
                        <i class='bx bx-group'></i>
                        <span>Committee List</span>
                    </a>
                </div>
            </div>

            <div class="menu-category">
                <div class="category-title">Communication</div>
                <div class="menu-item">
                    <a href="./chat.php<?= $base_url ?>">
                        <i class='bx bx-message-rounded-dots'></i>
                        <span>Chat System</span>
                    </a>
                </div>
                <div class="menu-item active">
                    <a href="./video-conference.php<?= $base_url ?>">
                        <i class='bx bx-video'></i>
                        <span>Video Conference</span>
                    </a>
                </div>
            </div>

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

    <main class="main-content">
        <div class="video-container">
            <div id="meeting-status" class="meeting-status"></div>
            <button id="join-btn" class="disabled">
                <i class='bx bx-video-plus'></i>
                Join Conference
            </button>

            <div id="video-streams"></div>

            <div id="stream-controls">
                <button id="leave-btn" class="control-btn danger">
                    <i class='bx bx-exit'></i>
                    Leave Conference
                </button>
                <button id="mic-btn" class="control-btn">
                    <i class='bx bx-microphone'></i>
                    Mic On
                </button>
                <button id="camera-btn" class="control-btn">
                    <i class='bx bx-camera'></i>
                    Camera On
                </button>
            </div>
        </div>
    </main>

    <script src="https://download.agora.io/sdk/release/AgoraRTC_N.js"></script>
<script>
    // UI Elements with null checks
    const initializeUIElements = () => {
        const elements = {
            joinBtn: document.getElementById('join-btn'),
            leaveBtn: document.getElementById('leave-btn'),
            micBtn: document.getElementById('mic-btn'),
            cameraBtn: document.getElementById('camera-btn'),
            streamControls: document.getElementById('stream-controls'),
            videoStreams: document.getElementById('video-streams'),
            meetingStatusDiv: document.getElementById('meeting-status')
        };

        // Check if any element is missing
        const missingElements = Object.entries(elements)
            .filter(([key, value]) => !value)
            .map(([key]) => key);

        if (missingElements.length > 0) {
            console.error('Missing UI elements:', missingElements);
            return null;
        }

        return elements;
    };

    // Initialize Agora configuration
    const AGORA_CONFIG = {
        APP_ID: "85fd346d881b42b39d7a3fd84c178ea4",
        TOKEN: "007eJxTYGg7dH5bDPP8UvmlPqyqIZOdnVnso79cMf3PrC14/VeiTZYCg4VpWoqxiVmKhYVhkolRkrFlinmicVqKhUmyoblFaqLJxan70hsCGRk0860YGRkgEMTnZXBJzCvJTM7XTS1LzSthYAAAy5ggzg==",
        CHANNEL: "Dantico-event"
    };

    // State management class
    class VideoConferenceState {
        constructor() {
            this.localTracks = [];
            this.remoteUsers = {};
            this.isJoined = false;
            this.currentMeetings = [];
            this.statusCheckInterval = null;
            this.client = AgoraRTC.createClient({ mode: 'rtc', codec: 'vp8' });
        }
    }

    // Time utilities
    const TimeUtils = {
        formatTime(date) {
            return new Date(date).toLocaleTimeString([], { 
                hour: '2-digit', 
                minute: '2-digit'
            });
        },

        getTimeUntil(targetDate) {
            const now = new Date();
            const target = new Date(targetDate);
            let diff = target - now;

            if (diff < 0) return null;

            const hours = Math.floor(diff / (1000 * 60 * 60));
            diff -= hours * (1000 * 60 * 60);
            const minutes = Math.floor(diff / (1000 * 60));
            diff -= minutes * (1000 * 60);
            const seconds = Math.floor(diff / 1000);

            return `${hours}h ${minutes}m ${seconds}s`;
        }
    };

    // Meeting manager class
    class MeetingManager {
        static isActiveMeeting(meeting) {
            const now = new Date();
            const startTime = new Date(meeting.start_datetime);
            const endTime = new Date(meeting.end_datetime);
            return now >= startTime && now <= endTime;
        }

        static findNextMeeting(meetings) {
            const now = new Date();
            return meetings.find(meeting => {
                const startTime = new Date(meeting.start_datetime);
                return startTime > now;
            });
        }

        static async checkMeetingStatus(UI, state) {
            try {
                const response = await fetch('?check_status=1');
                const data = await response.json();
                
                state.currentMeetings = data.has_meeting ? data.meetings : [];
                MeetingManager.updateMeetingInfo(state.currentMeetings, UI, state);
            } catch (error) {
                console.error('Error checking meeting status:', error);
                UI.meetingStatusDiv.textContent = 'Error checking meeting status';
                UI.meetingStatusDiv.className = 'meeting-status error';
            }
        }

        static updateMeetingInfo(meetings, UI, state) {
            const activeMeeting = meetings.find(this.isActiveMeeting);
            const nextMeeting = this.findNextMeeting(meetings);

            if (activeMeeting) {
                this.handleActiveMeeting(activeMeeting, UI);
            } else if (nextMeeting) {
                this.handleNextMeeting(nextMeeting, UI);
            } else {
                this.handleNoMeeting(UI);
            }

            // Force leave if meeting has ended
            if (state.isJoined && !activeMeeting) {
                VideoManager.leaveAndRemoveLocalStream(UI, state);
            }
        }

        static handleActiveMeeting(meeting, UI) {
            const endTime = new Date(meeting.end_datetime);
            const timeRemaining = TimeUtils.getTimeUntil(endTime);
            
            UI.meetingStatusDiv.innerHTML = `
                <div class="meeting-info">
                    <h3>Current Meeting</h3>
                    <p>${meeting.meeting_type}</p>
                    <div class="countdown">Time remaining: ${timeRemaining}</div>
                    <p>Ends at ${TimeUtils.formatTime(endTime)}</p>
                </div>
            `;

            UI.joinBtn.classList.remove('disabled');
            UI.meetingStatusDiv.className = 'meeting-status in-progress';
        }

        static handleNextMeeting(meeting, UI) {
            const startTime = new Date(meeting.start_datetime);
            const timeUntilStart = TimeUtils.getTimeUntil(startTime);

            UI.meetingStatusDiv.innerHTML = `
                <div class="meeting-info">
                    <h3>Next Meeting</h3>
                    <p>${meeting.meeting_type}</p>
                    <div class="countdown">Starts in: ${timeUntilStart}</div>
                    <p>Starts at ${TimeUtils.formatTime(startTime)}</p>
                </div>
            `;

            UI.joinBtn.classList.add('disabled');
            UI.meetingStatusDiv.className = 'meeting-status scheduled';
        }

        static handleNoMeeting(UI) {
            UI.meetingStatusDiv.innerHTML = `
                <div class="meeting-info">
                    <p>No upcoming meetings scheduled</p>
                </div>
            `;
            UI.joinBtn.classList.add('disabled');
            UI.meetingStatusDiv.className = 'meeting-status no-meeting';
        }
    }

    // Video manager class
    class VideoManager {
        static async joinAndDisplayLocalStream(UI, state) {
            try {
                if (!state.currentMeetings.some(MeetingManager.isActiveMeeting)) {
                    console.error('No active meeting');
                    return;
                }

                const UID = await state.client.join(
                    AGORA_CONFIG.APP_ID, 
                    AGORA_CONFIG.CHANNEL, 
                    AGORA_CONFIG.TOKEN, 
                    null
                );

                state.localTracks = await AgoraRTC.createMicrophoneAndCameraTracks();
                const player = `<div class="video-player" id="user-${UID}"></div>`;
                UI.videoStreams.insertAdjacentHTML('beforeend', player);
                state.localTracks[1].play(`user-${UID}`);

                await state.client.publish(state.localTracks);

                UI.joinBtn.style.display = 'none';
                UI.streamControls.style.display = 'flex';
                state.isJoined = true;
            } catch (error) {
                console.error('Error joining stream:', error);
            }
        }

        static async leaveAndRemoveLocalStream(UI, state) {
            for (let track of state.localTracks) {
                track.stop();
                track.close();
            }

            await state.client.leave();
            UI.videoStreams.innerHTML = '';
            UI.joinBtn.style.display = 'block';
            UI.streamControls.style.display = 'none';
            state.isJoined = false;
        }

        static async toggleMic(e, state) {
            if (!state.localTracks[0]) return;

            await state.localTracks[0].setMuted(!state.localTracks[0].muted);
            e.target.innerHTML = state.localTracks[0].muted ?
                '<i class="bx bx-microphone-off"></i> Mic Off' :
                '<i class="bx bx-microphone"></i> Mic On';
            e.target.className = state.localTracks[0].muted ?
                'control-btn muted' : 'control-btn';
        }

        static async toggleCamera(e, state) {
            if (!state.localTracks[1]) return;

            await state.localTracks[1].setMuted(!state.localTracks[1].muted);
            e.target.innerHTML = state.localTracks[1].muted ?
                '<i class="bx bx-camera-off"></i> Camera Off' :
                '<i class="bx bx-camera"></i> Camera On';
            e.target.className = state.localTracks[1].muted ?
                'control-btn muted' : 'control-btn';
        }
    }

    // Initialize everything when DOM is loaded
    document.addEventListener('DOMContentLoaded', () => {
        const UI = initializeUIElements();
        
        if (!UI) {
            console.error('Failed to initialize UI elements. Some elements are missing from the DOM.');
            return;
        }

        const state = new VideoConferenceState();

        // Set up remote user handling
        state.client.on('user-published', async (user, mediaType) => {
            await state.client.subscribe(user, mediaType);
            
            if (mediaType === 'video') {
                const player = `<div class="video-player" id="user-${user.uid}"></div>`;
                UI.videoStreams.insertAdjacentHTML('beforeend', player);
                user.videoTrack.play(`user-${user.uid}`);
            }
            
            if (mediaType === 'audio') {
                user.audioTrack.play();
            }
        });

        state.client.on('user-unpublished', (user) => {
            const player = document.getElementById(`user-${user.uid}`);
            if (player) player.remove();
        });

        // Handle join, leave, and stream controls
        UI.joinBtn.addEventListener('click', async () => {
            await MeetingManager.checkMeetingStatus(UI, state);
            if (state.isJoined) return;
            await VideoManager.joinAndDisplayLocalStream(UI, state);
        });

        UI.leaveBtn.addEventListener('click', async () => {
            await VideoManager.leaveAndRemoveLocalStream(UI, state);
        });

        UI.micBtn.addEventListener('click', (e) => {
            VideoManager.toggleMic(e, state);
        });

        UI.cameraBtn.addEventListener('click', (e) => {
            VideoManager.toggleCamera(e, state);
        });

        // Check meeting status periodically
        state.statusCheckInterval = setInterval(() => {
            MeetingManager.checkMeetingStatus(UI, state);
        }, 5000); // Check every 5 seconds
    });
</script>

</body>
</html>