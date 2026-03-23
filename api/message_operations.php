<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/session.php';

requireLogin();

header('Content-Type: application/json');

$user_id = getCurrentUserId();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $conn = getDBConnection();

    switch ($action) {
        case 'edit_message':
            if (!isset($data['message_id'], $data['new_text'])) {
                throw new Exception('message_id and new_text are required');
            }

            // Verify ownership and time limit (15 minutes)
            $stmt = $conn->prepare("
                SELECT id, sender_id, sent_at FROM messages 
                WHERE id = ? AND sender_id = ?
            ");
            $stmt->bind_param("ii", $data['message_id'], $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $message = $result->fetch_assoc();
            $stmt->close();

            if (!$message) {
                throw new Exception('Message not found or unauthorized');
            }

            $time_diff = strtotime('now') - strtotime($message['sent_at']);
            if ($time_diff > 900) { // 15 minutes
                throw new Exception('Message can only be edited within 15 minutes');
            }

            // Update message
            $new_text = htmlspecialchars($data['new_text'], ENT_QUOTES, 'UTF-8');
            $stmt = $conn->prepare("
                UPDATE messages 
                SET message = ?, is_edited = 1, edited_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("si", $new_text, $data['message_id']);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Message edited successfully']);
            break;

        case 'unsend_message':
            if (!isset($data['message_id'])) {
                throw new Exception('message_id is required');
            }

            // Verify ownership
            $stmt = $conn->prepare("
                SELECT id, sender_id FROM messages WHERE id = ? AND sender_id = ?
            ");
            $stmt->bind_param("ii", $data['message_id'], $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $message = $result->fetch_assoc();
            $stmt->close();

            if (!$message) {
                throw new Exception('Message not found or unauthorized');
            }

            // Mark as unsent for both users
            $stmt = $conn->prepare("UPDATE messages SET is_unsent = 1 WHERE id = ?");
            $stmt->bind_param("i", $data['message_id']);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Message unsent']);
            break;

        case 'remove_message':
            if (!isset($data['message_id'])) {
                throw new Exception('message_id is required');
            }

            // Verify ownership (can remove received messages too)
            $stmt = $conn->prepare("
                SELECT id, receiver_id FROM messages WHERE id = ?
            ");
            $stmt->bind_param("i", $data['message_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $message = $result->fetch_assoc();
            $stmt->close();

            if (!$message || $message['receiver_id'] != $user_id) {
                throw new Exception('Message not found or unauthorized');
            }

            // Delete message only for this user (soft delete)
            $stmt = $conn->prepare("
                UPDATE messages SET is_removed_for_receiver = 1 WHERE id = ?
            ");
            $stmt->bind_param("i", $data['message_id']);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Message removed']);
            break;

        case 'mark_as_read':
            if (!isset($data['message_id'])) {
                throw new Exception('message_id is required');
            }

            $stmt = $conn->prepare("
                UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?
            ");
            $stmt->bind_param("ii", $data['message_id'], $user_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Message marked as read']);
            break;

        case 'get_messages':
            if (!isset($data['partner_id'])) {
                throw new Exception('partner_id is required');
            }

            $partner_id = $data['partner_id'];
            $limit = $data['limit'] ?? 50;
            $offset = $data['offset'] ?? 0;

            // Get messages between users (latest first)
            $stmt = $conn->prepare("
                SELECT m.*, 
                       u.first_name, u.last_name, u.profile_pic, u.is_verified
                FROM messages m
                JOIN users u ON u.id = m.sender_id
                WHERE (m.sender_id = ? AND m.receiver_id = ?) 
                   OR (m.sender_id = ? AND m.receiver_id = ?)
                AND (m.is_unsent = 0 OR m.sender_id = ?)
                AND (m.is_removed_for_receiver = 0 OR m.sender_id = ?)
                ORDER BY m.sent_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param("iiiiiiii", $user_id, $partner_id, $partner_id, $user_id, $user_id, $user_id, $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            $messages = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Mark all messages as read
            $readStmt = $conn->prepare("
                UPDATE messages SET is_read = 1 
                WHERE receiver_id = ? AND sender_id = ? AND is_read = 0
            ");
            $readStmt->bind_param("ii", $user_id, $partner_id);
            $readStmt->execute();
            $readStmt->close();

            echo json_encode([
                'success' => true,
                'messages' => array_reverse($messages)
            ]);
            break;

        default:
            throw new Exception('Unknown action');
    }

    closeDBConnection($conn);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
