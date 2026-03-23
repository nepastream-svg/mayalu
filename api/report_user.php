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
$target_id = $_POST['user_id'] ?? $input['user_id'] ?? 0;
$reason = $_POST['reason'] ?? $input['reason'] ?? '';

if (!$target_id || !$reason) {
    echo json_encode(['success' => false, 'message' => 'Target and reason required']);
    exit();
}

$conn = getDBConnection();
$conn->query("CREATE TABLE IF NOT EXISTS user_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    target_id INT NOT NULL,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$stmt = $conn->prepare("INSERT INTO user_reports (reporter_id, target_id, reason) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $user_id, $target_id, $reason);
$ok = $stmt->execute();
$stmt->close();
closeDBConnection($conn);

if ($ok) echo json_encode(['success' => true]);
else echo json_encode(['success' => false, 'message' => 'Failed to submit report']);
