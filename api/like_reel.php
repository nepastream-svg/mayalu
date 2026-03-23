<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$reel_id = $data['reel_id'] ?? 0;
$action = $data['action'] ?? 'like'; // like or unlike
$user_id = $_SESSION['user_id'];

if (!$reel_id) {
    echo json_encode(['success' => false, 'message' => 'Reel not specified']);
    exit();
}

$conn = getDBConnection();

if ($action == 'like') {
    // Check if already liked
    $check_stmt = $conn->prepare("SELECT id FROM reel_likes WHERE reel_id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $reel_id, $user_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows == 0) {
        $insert_stmt = $conn->prepare("INSERT INTO reel_likes (reel_id, user_id) VALUES (?, ?)");
        $insert_stmt->bind_param("ii", $reel_id, $user_id);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    $check_stmt->close();
} else {
    // Unlike
    $delete_stmt = $conn->prepare("DELETE FROM reel_likes WHERE reel_id = ? AND user_id = ?");
    $delete_stmt->bind_param("ii", $reel_id, $user_id);
    $delete_stmt->execute();
    $delete_stmt->close();
}

// Get updated like count
$count_stmt = $conn->prepare("SELECT COUNT(*) as likes_count FROM reel_likes WHERE reel_id = ?");
$count_stmt->bind_param("i", $reel_id);
$count_stmt->execute();
$likes_count = $count_stmt->get_result()->fetch_assoc()['likes_count'];
$count_stmt->close();

closeDBConnection($conn);

echo json_encode([
    'success' => true,
    'likes_count' => $likes_count,
    'action' => $action
]);
?>