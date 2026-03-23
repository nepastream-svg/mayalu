<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $ad_type = $data['type'] ?? 'general';
    $duration = $data['duration'] ?? 6;
    
    trackAdWatch($_SESSION['user_id'], $ad_type, $duration);
    
    echo json_encode(['success' => true, 'message' => 'Ad tracked successfully']);
}
?>