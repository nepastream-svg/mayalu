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
$receiver_id = $_POST['receiver_id'] ?? $input['receiver_id'] ?? 0;
$typing = isset($_POST['typing']) ? ($_POST['typing'] ? 1 : 0) : (isset($input['typing']) ? ($input['typing'] ? 1 : 0) : 0);

if (!$receiver_id) {
    echo json_encode(['success' => false, 'message' => 'Receiver required']);
    exit();
}

$conn = getDBConnection();
$conn->query("CREATE TABLE IF NOT EXISTS typing_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    typing TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (sender_id, receiver_id)
)");

$stmt = $conn->prepare("INSERT INTO typing_status (sender_id, receiver_id, typing) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE typing = VALUES(typing), updated_at = NOW()");
$stmt->bind_param("iii", $user_id, $receiver_id, $typing);
$ok = $stmt->execute();
$stmt->close();
closeDBConnection($conn);

if ($ok) echo json_encode(['success' => true]);
else echo json_encode(['success' => false, 'message' => 'Failed to update typing']);
