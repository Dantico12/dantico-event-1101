<?php
session_start();
require 'db.php';

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

// Get user role and committee role from session
$user_role = $_SESSION['user_role'] ?? '';
$committee_role = $_SESSION['committee_role'] ?? '';
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
/* Update the existing chat-related styles */
.chat-messages {
    flex-grow: 1;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.message-wrapper {
    display: flex;
    flex-direction: column;
    max-width: 70%;
}

.sender-name {
    font-size: 0.85em;
    color: #0ef;
    margin-bottom: 4px;
}

.message-timestamp {
    font-size: 0.75em;
    color: #7a7a7a;
    margin-top: 4px;
}

/* Message alignment and styling */
.message {
    display: flex;
    margin-bottom: 15px;
}

.message.received .message-wrapper {
    align-items: flex-start;
    margin-right: auto;
}

.message.sent .message-wrapper {
    align-items: flex-end;
    margin-left: auto;
}

/* Message content styling */
.message-content {
    padding: 12px 16px;
    border-radius: 15px;
    word-wrap: break-word;
    max-width: 100%;
    font-size: 0.95em;
}

/* Received message styling */
.message.received .message-content {
    background-color: rgba(0, 238, 255, 0.1);
    color: #fff;
    border: 1px solid rgba(0, 238, 255, 0.3);
    border-bottom-left-radius: 5px;
}

/* Sent message styling */
.message.sent .message-content {
    background-color: rgba(0, 238, 255, 0.2);
    color: #fff;
    border: 1px solid rgba(0, 238, 255, 0.4);
    border-bottom-right-radius: 5px;
}

/* Input area styling */
.chat-input {
    display: flex;
    padding: 20px;
    border-top: 2px solid #0ef;
    gap: 15px;
    align-items: center;
    background: rgba(8, 27, 41, 0.95);
}

.chat-input input {
    flex-grow: 1;
    padding: 12px 15px;
    background: rgba(0, 238, 255, 0.05);
    border: 1px solid rgba(0, 238, 255, 0.3);
    border-radius: 8px;
    color: #fff;
    font-size: 0.95em;
    transition: all 0.3s ease;
}

.chat-input input:focus {
    outline: none;
    border-color: #0ef;
    background: rgba(0, 238, 255, 0.1);
}

.chat-input input::placeholder {
    color: rgba(255, 255, 255, 0.5);
}

.chat-input button {
    padding: 12px 25px;
    background: #0ef;
    border: none;
    border-radius: 8px;
    color: #081b29;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.chat-input button:hover {
    background: rgba(0, 238, 255, 0.8);
    transform: translateY(-1px);
}

.chat-input button:active {
    transform: translateY(1px);
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
    // Create the chat system object using standard JavaScript object syntax
var ChatSystem = {
    eventId: null,
    userId: null,
    currentUsername: null,
    lastMessageId: 0,
    pollingInterval: 3000,
    chatMessages: null,
    messageInput: null,
    sendButton: null,

    init: function() {
        var urlParams = new URLSearchParams(window.location.search);
        this.eventId = urlParams.get('event_id');
        
        if (!this.eventId) {
            console.error('No event ID provided');
            return;
        }
        
        this.chatMessages = document.getElementById('chat-messages');
        this.messageInput = document.getElementById('message-input');
        this.sendButton = document.getElementById('send-btn');
        
        if (!this.chatMessages || !this.messageInput || !this.sendButton) {
            console.error('Required DOM elements not found');
            return;
        }
        
        var self = this;
        this.getUserInfo()
            .then(function() {
                self.setupEventListeners();
                self.loadMessages();
                self.startPolling();
            })
            .catch(function(error) {
                console.error('Initialization failed:', error);
            });
    },

    getUserInfo: function() {
        var self = this;
        return new Promise(function(resolve, reject) {
            fetch('get_user_info.php')
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data.success) {
                        reject(new Error(data.message || 'Failed to get user info'));
                        return;
                    }
                    
                    self.userId = data.user_id;
                    self.currentUsername = data.username;
                    
                    if (!self.userId || !self.currentUsername) {
                        reject(new Error('Invalid user data received'));
                        return;
                    }
                    resolve();
                })
                .catch(function(error) {
                    console.error('Error getting user info:', error);
                    reject(error);
                });
        });
    },

    setupEventListeners: function() {
        var self = this;
        this.sendButton.addEventListener('click', function() {
            self.sendMessage();
        });
        
        this.messageInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                self.sendMessage();
            }
        });
    },

    startPolling: function() {
        var self = this;
        setInterval(function() {
            self.checkNewMessages();
        }, this.pollingInterval);
    },

    loadMessages: function() {
        var self = this;
        fetch('chat_history.php?event_id=' + this.eventId)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load messages');
                }
                
                self.chatMessages.innerHTML = '';
                if (Array.isArray(data.messages)) {
                    data.messages.forEach(function(msg) {
                        self.appendMessage(msg);
                        if (parseInt(msg.id) > self.lastMessageId) {
                            self.lastMessageId = parseInt(msg.id);
                        }
                    });
                    self.scrollToBottom();
                }
            })
            .catch(function(error) {
                console.error('Error loading messages:', error);
            });
    },

    checkNewMessages: function() {
        var self = this;
        fetch('chat_history.php?event_id=' + this.eventId + '&last_id=' + this.lastMessageId)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && Array.isArray(data.messages) && data.messages.length > 0) {
                    data.messages.forEach(function(msg) {
                        var msgId = parseInt(msg.id);
                        if (msgId > self.lastMessageId) {
                            self.appendMessage(msg);
                            self.lastMessageId = msgId;
                        }
                    });
                    self.scrollToBottom();
                }
            })
            .catch(function(error) {
                console.error('Error checking new messages:', error);
            });
    },

    sendMessage: function() {
        var messageText = this.messageInput.value.trim();
        if (!messageText) return;

        var messageData = {
            event_id: this.eventId,
            message: messageText
        };

        var self = this;
        fetch('chat_history.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(messageData)
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data.success) {
                throw new Error(data.error || 'Failed to send message');
            }
            
            self.appendMessage(data.message);
            self.messageInput.value = '';
            self.scrollToBottom();
        })
        .catch(function(error) {
            console.error('Error sending message:', error);
            alert('Failed to send message. Please try again.');
        });
    },

    appendMessage: function(msg) {
        var messageDiv = document.createElement('div');
        messageDiv.className = 'message';
        messageDiv.classList.add(parseInt(msg.sender_id) === this.userId ? 'received' : 'sent');

        var wrapper = document.createElement('div');
        wrapper.className = 'message-wrapper';

        var sender = document.createElement('div');
        sender.className = 'sender-name';
        sender.textContent = msg.username;

        var content = document.createElement('div');
        content.className = 'message-content';
        content.textContent = msg.message;

        var time = document.createElement('div');
        time.className = 'message-timestamp';
        var date = new Date(msg.sent_at);
        time.textContent = date.toLocaleTimeString([], { 
            hour: '2-digit', 
            minute: '2-digit' 
        });

        wrapper.appendChild(sender);
        wrapper.appendChild(content);
        wrapper.appendChild(time);
        messageDiv.appendChild(wrapper);
        this.chatMessages.appendChild(messageDiv);
    },

    scrollToBottom: function() {
        this.chatMessages.scrollTop = this.chatMessages.scrollHeight;
    }
};

// Initialize the chat system when the DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    ChatSystem.init();
});
  </script>
</body>
</html>