<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$receiver_id = $data['receiver_id'] ?? 0;
$sender_id = $_SESSION['user_id'];

if (!$receiver_id) {
    echo json_encode(['success' => false, 'message' => 'Receiver not specified']);
    exit();
}

if ($sender_id == $receiver_id) {
    echo json_encode(['success' => false, 'message' => 'Cannot send request to yourself']);
    exit();
}

// Check if already sent
$conn = getDBConnection();
$check_stmt = $conn->prepare("
    SELECT id FROM match_requests 
    WHERE sender_id = ? AND receiver_id = ? AND status IN ('pending', 'accepted')
");
$check_stmt->bind_param("ii", $sender_id, $receiver_id);
$check_stmt->execute();

if ($check_stmt->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Request already sent']);
    $check_stmt->close();
    closeDBConnection($conn);
    exit();
}
$check_stmt->close();

// Check if blocked
$block_stmt = $conn->prepare("
    SELECT id FROM match_requests 
    WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
    AND status = 'blocked'
");
$block_stmt->bind_param("iiii", $sender_id, $receiver_id, $receiver_id, $sender_id);
$block_stmt->execute();

if ($block_stmt->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Cannot send request to blocked user']);
    $block_stmt->close();
    closeDBConnection($conn);
    exit();
}
$block_stmt->close();

// Check user subscription and limits
$user = getUserById($sender_id);
$today = date('Y-m-d');

if ($user['subscription_type'] == 'Free') {
    if ($user['last_request_date'] != $today) {
        // Reset for new day
        $reset_stmt = $conn->prepare("UPDATE users SET requests_sent_today = 0, last_request_date = ? WHERE id = ?");
        $reset_stmt->bind_param("si", $today, $sender_id);
        $reset_stmt->execute();
        $reset_stmt->close();
    }
    
    if ($user['requests_sent_today'] >= 5) {
        echo json_encode(['success' => false, 'message' => 'Daily limit reached. Watch ad or upgrade to premium.']);
        closeDBConnection($conn);
        exit();
    }
}

// Check if receiver's preferences match sender
$receiver = getUserById($receiver_id);
if ($receiver['gender_preference'] != 'Everyone' && $receiver['gender_preference'] != $user['gender']) {
    echo json_encode(['success' => false, 'message' => 'User preferences do not match']);
    closeDBConnection($conn);
    exit();
}

// Send request
$insert_stmt = $conn->prepare("
    INSERT INTO match_requests (sender_id, receiver_id, status, sent_at) 
    VALUES (?, ?, 'pending', NOW())
");
$insert_stmt->bind_param("ii", $sender_id, $receiver_id);

if ($insert_stmt->execute()) {
    // Update request count
    $update_stmt = $conn->prepare("
        UPDATE users 
        SET requests_sent_today = requests_sent_today + 1, 
            last_request_date = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $update_stmt->bind_param("si", $today, $sender_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    echo json_encode(['success' => true, 'message' => 'Request sent successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send request']);
}

$insert_stmt->close();
closeDBConnection($conn);
?>