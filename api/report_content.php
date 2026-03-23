<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/session.php';

requireLogin();

header('Content-Type: application/json');

$user_id = getCurrentUserId();
$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    if (!isset($data['content_type'], $data['content_id'], $data['reason'])) {
        throw new Exception('content_type, content_id, and reason are required');
    }

    $conn = getDBConnection();

    // Create reports table if not exists
    $tableSQL = "CREATE TABLE IF NOT EXISTS content_reports (
        id INT PRIMARY KEY AUTO_INCREMENT,
        reporter_id INT NOT NULL,
        content_type VARCHAR(50) NOT NULL,
        content_id INT NOT NULL,
        reason VARCHAR(255) NOT NULL,
        description TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $conn->query($tableSQL);

    $content_type = htmlspecialchars($data['content_type'], ENT_QUOTES, 'UTF-8');
    $content_id = (int)$data['content_id'];
    $reason = htmlspecialchars($data['reason'], ENT_QUOTES, 'UTF-8');
    $description = isset($data['description']) ? htmlspecialchars($data['description'], ENT_QUOTES, 'UTF-8') : null;

    // Check if already reported
    $stmt = $conn->prepare("
        SELECT id FROM content_reports 
        WHERE reporter_id = ? AND content_type = ? AND content_id = ? AND status = 'pending'
    ");
    $stmt->bind_param("isi", $user_id, $content_type, $content_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    if ($existing) {
        throw new Exception('You have already reported this content');
    }

    // Insert report
    $stmt = $conn->prepare("
        INSERT INTO content_reports (reporter_id, content_type, content_id, reason, description)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isiss", $user_id, $content_type, $content_id, $reason, $description);
    $stmt->execute();
    $report_id = $conn->insert_id;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Report submitted successfully. Our team will review it shortly.',
        'report_id' => $report_id
    ]);

    closeDBConnection($conn);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
