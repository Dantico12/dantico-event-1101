<?php
require 'vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class ChatServer implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;
    protected $db;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->userConnections = [];
        $this->initDatabase();
        echo "Chat Server initialized\n";
    }

    protected function initDatabase() {
        try {
            $servername = "127.0.0.1";
            $username = "root";
            $password = "";
            $dbname = "event";
            $port = 3306;
            
            $this->db = mysqli_init();
            
            if (!$this->db) {
                throw new Exception("mysqli_init failed");
            }
            
            mysqli_options($this->db, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
            
            if (!mysqli_real_connect($this->db, $servername, $username, $password, $dbname, $port)) {
                throw new Exception("Connect Error: " . mysqli_connect_error());
            }
            
            $this->db->set_charset("utf8mb4");
            echo "Database connection established\n";
        } catch (Exception $e) {
            die("Database connection failed: " . $e->getMessage() . "\n");
        }
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
        
        // Send connection confirmation
        $conn->send(json_encode([
            'type' => 'connection_status',
            'status' => 'connected',
            'connection_id' => $conn->resourceId
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            $data = json_decode($msg, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON received');
            }

            switch ($data['type']) {
                case 'init':
                    $this->userConnections[$from->resourceId] = [
                        'user_id' => (int)$data['user_id'],
                        'event_id' => (int)$data['event_id']
                    ];
                    
                    // Send initialization confirmation
                    $from->send(json_encode([
                        'type' => 'init_confirmation',
                        'status' => 'success',
                        'user_id' => $data['user_id'],
                        'event_id' => $data['event_id']
                    ]));
                    
                    echo "User {$data['user_id']} initialized for event {$data['event_id']}\n";
                    break;

                case 'chat':
                    if (!isset($this->userConnections[$from->resourceId])) {
                        throw new Exception('User not initialized');
                    }

                    $stmt = $this->db->prepare(
                        "INSERT INTO chat_messages (event_id, sender_id, message, created_at) 
                         VALUES (?, ?, ?, NOW())"
                    );
                    
                    $eventId = $this->userConnections[$from->resourceId]['event_id'];
                    $senderId = $this->userConnections[$from->resourceId]['user_id'];
                    $message = $data['message'];
                    
                    $stmt->bind_param("iis", $eventId, $senderId, $message);
                    
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to save message: ' . $stmt->error);
                    }

                    $messageId = $stmt->insert_id;
                    $timestamp = date('Y-m-d H:i:s');

                    // Broadcast to all users in the same event
                    foreach ($this->clients as $client) {
                        if (isset($this->userConnections[$client->resourceId]) && 
                            $this->userConnections[$client->resourceId]['event_id'] === $eventId) {
                            
                            $client->send(json_encode([
                                'type' => 'message',
                                'id' => $messageId,
                                'sender_id' => $senderId,
                                'message' => $message,
                                'timestamp' => $timestamp
                            ]));
                        }
                    }
                    break;

                default:
                    echo "Unknown message type received\n";
                    break;
            }
        } catch (Exception $e) {
            echo "Error processing message: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to process message'
            ]));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        unset($this->userConnections[$conn->resourceId]);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Create and run the server
try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new ChatServer()
            )
        ),
        8080
    );

    echo "WebSocket server started on port 8080\n";
    $server->run();
    
} catch (Exception $e) {
    echo "Failed to start server: " . $e->getMessage() . "\n";
}