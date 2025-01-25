<?php
session_start();

// Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) . 
                    '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
}

// Get the event context URL to be used across navigation
$base_url = getEventContextURL();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dantico Events - Chat System</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'poppins', sans-serif;
        }

        :root {
            --sidebar-width: 260px;
            --header-height: 60px;
        }

        body {
            min-height: 100vh;
            background: #081b29;
            overflow-x: hidden;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: var(--sidebar-width);
            background: rgba(8, 27, 41, 0.9);
            border-right: 2px solid #0ef;
            transition: all 0.5s ease;
            z-index: 100;
        }

        .sidebar-header {
            height: var(--header-height);
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

        .sidebar-menu {
            padding: 10px 0;
            height: calc(100% - var(--header-height));
            overflow-y: auto;
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
            padding: 12px 20px 12px 30px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .menu-item a {
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .menu-item:hover {
            background: rgba(0, 238, 255, 0.1);
        }

        .menu-item.active {
            background: rgba(0, 238, 255, 0.15);
        }

        .menu-item i {
            font-size: 24px;
            min-width: 40px;
            color: #0ef;
            transition: all 0.3s ease;
        }

        .menu-item span {
            color: #fff;
            white-space: nowrap;
            transition: all 0.3s ease;
            margin-left: 10px;
        }

        .main-content {
            position: relative;
            left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            min-height: 100vh;
            transition: all 0.5s ease;
            padding: 20px;
        }

        .header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 20px;
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .header-title {
            color: #fff;
            margin-left: 20px;
            font-size: 20px;
        }

        .header-actions {
            margin-left: auto;
            display: flex;
            gap: 15px;
        }

        .header-actions i {
            font-size: 24px;
            color: #0ef;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .chat-container {
    background: rgba(8, 27, 41, 0.9);
    border: 2px solid #0ef;
    border-radius: 10px;
    height: calc(100vh - 120px);
    display: flex;
    flex-direction: column;
    position: fixed;
    bottom: 20px;
    left: calc(var(--sidebar-width) + 20px);
    right: 20px;
}

.chat-messages {
    flex-grow: 1;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.chat-input {
    display: flex;
    padding: 20px;
    border-top: 2px solid #0ef;
    gap: 10px;
    align-items: center;
}

.chat-input input {
    flex-grow: 1;
    padding: 10px;
    background: rgba(0, 238, 255, 0.1);
    border: 1px solid #0ef;
    border-radius: 5px;
    color: #fff;
}

.chat-input button {
    padding: 10px 20px;
    background: #0ef;
    border: none;
    border-radius: 5px;
    color: #081b29;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-left: auto;
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
            <div class="category-title">Committees</div>
            <div class="menu-item">
                <a href="./add-committee.php<?= $base_url ?>">
                    <i class='bx bx-plus-circle'></i>
                    <span>Add Committee</span>
                </a>
            </div>
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
            <div class="category-title">Reviews</div>
            <div class="menu-item">
                <a href="./minutes.php<?= $base_url ?>">
                    <i class='bx bxs-timer'></i>
                    <span>Minutes</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./tasks.php<?= $base_url ?>">
                    <i class='bx bx-task'></i>
                    <span>Tasks</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./reports.php<?= $base_url ?>">
                    <i class='bx bx-line-chart'></i>
                    <span>Reports</span>
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
            <div class="menu-item">
                <a href="./settings.php<?= $base_url ?>">
                    <i class='bx bx-cog'></i>
                    <span>Settings</span>
                </a>
            </div>
        </div>
    </div>
</nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h2 class="header-title">Chat System</h2>
            <div class="header-actions">
                <i class='bx bx-bell'></i>
                <i class='bx bx-user-circle'></i>
            </div>
        </div>

        <div class="chat-container">
            <div class="chat-messages" id="chat-messages"></div>
            <div class="chat-input">
                <input type="text" id="message-input" placeholder="Type your message...">
                <button id="send-btn">Send</button>
            </div>
        </div>
    </div>

    <script>
    class EventChatSystem {
        constructor(wsUrl) {
            this.wsUrl = wsUrl;
            this.websocket = null;
            this.userId = null;
            this.eventId = null;
            this.messagesContainer = document.getElementById('chat-messages');
            this.messageInput = document.getElementById('message-input');
            this.sendButton = document.getElementById('send-btn');

            this.initEventListeners();
           
        }

        initEventListeners() {
            this.sendButton.addEventListener('click', () => this.sendMessage());
            this.messageInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') this.sendMessage();
            });
        }


        connectWebSocket() {
            this.websocket = new WebSocket(this.wsUrl);

            this.websocket.onopen = () => {
                console.log('WebSocket connected');
                this.sendInitMessage();
            };

            this.websocket.onmessage = (event) => {
                const data = JSON.parse(event.data);
                if (data.type === 'message' && data.event_id === this.eventId) {
                    this.displayMessage(data);
                }
            };

            this.websocket.onerror = (error) => {
                console.error('WebSocket error:', error);
            };
        }

        sendInitMessage() {
            this.websocket.send(JSON.stringify({
                type: 'init',
                user_id: this.userId,
                event_id: this.eventId
            }));
        }

        sendMessage() {
            const message = this.messageInput.value.trim();
            if (!message) return;

            const messageData = {
                type: 'chat',
                event_id: this.eventId,
                user_id: this.userId,
                message: message
            };

            this.websocket.send(JSON.stringify(messageData));
            this.messageInput.value = '';
        }

        fetchChatHistory() {
            fetch(`chat_history.php?event_id=${this.eventId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        data.messages.forEach(msg => this.displayMessage({
                            sender_id: msg.sender_id,
                            message: msg.message,
                            timestamp: msg.sent_at
                        }));
                    }
                })
                .catch(error => console.error('Error fetching chat history:', error));
        }

        displayMessage(msgData) {
            const messageElement = document.createElement('div');
            messageElement.classList.add('message');
            messageElement.classList.add(msgData.sender_id === this.userId ? 'sent' : 'received');

            messageElement.innerHTML = `
                <div class="message-content">
                    ${msgData.message}
                </div>
            `;

            this.messagesContainer.appendChild(messageElement);
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        }
    }

    // Initialize chat system
    document.addEventListener('DOMContentLoaded', () => {
        new EventChatSystem('ws://localhost:8080');
    });
    </script>
</body>
</html>