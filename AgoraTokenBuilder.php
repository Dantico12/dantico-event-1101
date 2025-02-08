<?php
// File: AgoraTokenBuilder.php

class AgoraTokenBuilder {
    const ROLE_PUBLISHER = 1;
    const ROLE_SUBSCRIBER = 2;
    
    private $appId;
    private $appCertificate;
    private $channelName;
    private $uid;
    private $role;
    private $privilegeExpiredTs;
    
    public function __construct($appId, $appCertificate, $channelName, $uid = 0) {
        $this->appId = $appId;
        $this->appCertificate = $appCertificate;
        $this->channelName = $channelName;
        $this->uid = $uid;
        $this->role = self::ROLE_PUBLISHER;
        $this->privilegeExpiredTs = time() + (24 * 3600); // Token expires in 24 hours
    }
    
    public function buildTokenWithUid() {
        return $this->buildToken();
    }
    
    private function buildToken() {
        $message = pack("C*", 0x1) .
            pack("P", $this->privilegeExpiredTs) .
            pack("C*", strlen($this->appId)) . $this->appId .
            pack("C*", strlen($this->channelName)) . $this->channelName .
            pack("P", $this->uid);
        
        $salt = random_bytes(32);
        $signature = hash_hmac('sha256', $message, $this->appCertificate . $salt, true);
        
        return base64_encode($salt . $signature . $message);
    }
}

// Function to get Agora configuration
function getAgoraConfig() {
    return [
        'appId' => '85fd346d881b42b39d7a3fd84c178ea4',
        'appCertificate' => '18d1db68fd3747e79bf99904cb61155a', 
    ];
}

// Function to generate token
function generateAgoraToken($channelName, $uid = 0) {
    $config = getAgoraConfig();
    $tokenBuilder = new AgoraTokenBuilder(
        $config['appId'],
        $config['appCertificate'],
        $channelName,
        $uid
    );
    return $tokenBuilder->buildTokenWithUid();
}