<?php
session_start();
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$blocked_id = $_POST['user_id'] ?? $input['user_id'] ?? 0;

if (!$blocked_id) {
    echo json_encode(['success' => false, 'message' => 'User id required']);
    exit();
}

$conn = getDBConnection();
$conn->query("CREATE TABLE IF NOT EXISTS blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blocker_id INT NOT NULL,
    blocked_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (blocker_id, blocked_id)
)");

$stmt = $conn->prepare("INSERT INTO blocks (blocker_id, blocked_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE created_at = NOW()");
$stmt->bind_param("ii", $user_id, $blocked_id);
$ok = $stmt->execute();
$stmt->close();

// optional: remove any pending match requests from/to this user
$rm = $conn->prepare("DELETE FROM match_requests WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
$rm->bind_param("iiii", $user_id, $blocked_id, $blocked_id, $user_id);
$rm->execute();
$rm->close();

closeDBConnection($conn);

if ($ok) echo json_encode(['success' => true]);
else echo json_encode(['success' => false, 'message' => 'Failed to block user']);
