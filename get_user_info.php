<?php
session_start();
header('Content-Type: application/json');

$response = [
    'success' => true,
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null
];

echo json_encode($response);
?>