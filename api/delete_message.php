<?php
session_start();
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
$mode = $_POST['mode'] ?? 'me'; // 'me' or 'both'

if (!$message_id) {
    echo json_encode(['success' => false, 'message' => 'Message ID required']);
    exit();
}

$conn = getDBConnection();
// Get message
$stmt = $conn->prepare("SELECT sender_id, receiver_id FROM messages WHERE id = ?");
$stmt->bind_param("i", $message_id);
$stmt->execute();
$msg = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$msg) {
    echo json_encode(['success' => false, 'message' => 'Message not found']);
    closeDBConnection($conn);
    exit();
}

$is_sender = $msg['sender_id'] == $user_id;
$is_receiver = $msg['receiver_id'] == $user_id;

if (!$is_sender && !$is_receiver) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    closeDBConnection($conn);
    exit();
}

if ($mode === 'me') {
    if ($is_sender) {
        $u = $conn->prepare("UPDATE messages SET deleted_for_sender = 1 WHERE id = ?");
    } else {
        $u = $conn->prepare("UPDATE messages SET deleted_for_receiver = 1 WHERE id = ?");
    }
    $u->bind_param("i", $message_id);
    $ok = $u->execute();
    $u->close();
    if ($ok) echo json_encode(['success' => true]);
    else echo json_encode(['success' => false, 'message' => 'Failed to remove message']);
} else {
    // Unsend for both - delete the message row
    $del = $conn->prepare("DELETE FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)");
    $del->bind_param("iii", $message_id, $user_id, $user_id);
    $ok = $del->execute();
    $del->close();
    if ($ok) echo json_encode(['success' => true]);
    else echo json_encode(['success' => false, 'message' => 'Failed to unsend message']);
}

closeDBConnection($conn);
