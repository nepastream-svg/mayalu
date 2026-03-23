<?php
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? '';
    
    $suggestions = suggestUsernames($username);
    
    echo json_encode([
        'suggestions' => $suggestions
    ]);
}
?>