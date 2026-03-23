<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$request_id = $data['request_id'] ?? 0;
$action = $data['action'] ?? ''; // 'accept' or 'decline'

if (!$request_id || !in_array($action, ['accept', 'decline'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$user_id = $_SESSION['user_id'];

$conn = getDBConnection();

// Check if user is the receiver of this request
$check_stmt = $conn->prepare("
    SELECT id, sender_id, receiver_id FROM match_requests 
    WHERE id = ? AND receiver_id = ? AND status = 'pending'
");
$check_stmt->bind_param("ii", $request_id, $user_id);
$check_stmt->execute();
$request = $check_stmt->get_result()->fetch_assoc();
$check_stmt->close();

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
    exit();
}

// Update request status
$status = $action == 'accept' ? 'accepted' : 'rejected';
$update_stmt = $conn->prepare("
    UPDATE match_requests 
    SET status = ?, responded_at = NOW() 
    WHERE id = ?
");
$update_stmt->bind_param("si", $status, $request_id);

if ($update_stmt->execute()) {
    // If accepted, create a notification or do additional processing
    if ($action == 'accept') {
        // You can add notification logic here
    }
    
    echo json_encode([
        'success' => true,
        'message' => $action == 'accept' ? 'Request accepted!' : 'Request declined.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to process request']);
}

$update_stmt->close();
closeDBConnection($conn);
?>