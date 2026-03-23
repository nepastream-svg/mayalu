<?php
session_start();
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
// support both JSON and form POST
$input = json_decode(file_get_contents('php://input'), true);
$target_id = $_POST['user_id'] ?? $input['user_id'] ?? 0;
nickname = $_POST['nickname'] ?? $input['nickname'] ?? '';

if (!$target_id || !$nickname) {
    echo json_encode(['success' => false, 'message' => 'Target user and nickname required']);
    exit();
}

$conn = getDBConnection();
// table for storing nicknames per user
$conn->query("CREATE TABLE IF NOT EXISTS nicknames (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    target_id INT NOT NULL,
    nickname VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (owner_id, target_id)
)");

// upsert
$stmt = $conn->prepare("INSERT INTO nicknames (owner_id,target_id,nickname) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nickname = VALUES(nickname), updated_at = NOW()");
$stmt->bind_param("iis", $user_id, $target_id, $nickname);
$ok = $stmt->execute();
$stmt->close();
closeDBConnection($conn);

if ($ok) echo json_encode(['success' => true]);
else echo json_encode(['success' => false, 'message' => 'Failed to save nickname']);
