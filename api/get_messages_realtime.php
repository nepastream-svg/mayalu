<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/session.php';

requireLogin();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$user_id = getCurrentUserId();
$partner_id = $_GET['partner_id'] ?? 0;
$last_message_id = $_GET['last_message_id'] ?? 0;

if (!$partner_id) {
    http_response_code(400);
    echo json_encode(['error' => 'partner_id required']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Get new messages since last check
    if ($last_message_id > 0) {
        $stmt = $conn->prepare("
            SELECT m.*, 
                   u.first_name, u.last_name, u.profile_pic, u.is_verified,
                   IF(m.message LIKE '%<10 chars%', SUBSTRING(m.message, 1, 10), m.message) as preview
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) 
                OR (m.sender_id = ? AND m.receiver_id = ?))
            AND m.id > ?
            AND (m.is_unsent = 0 OR m.sender_id = ?)
            AND (m.is_removed_for_receiver = 0 OR m.sender_id = ?)
            ORDER BY m.sent_at ASC
        ");
        $stmt->bind_param("iiiiiii", $user_id, $partner_id, $partner_id, $user_id, $last_message_id, $user_id, $user_id);
    } else {
        // Get all messages
        $limit = 50;
        $stmt = $conn->prepare("
            SELECT m.*, 
                   u.first_name, u.last_name, u.profile_pic, u.is_verified,
                   IF(m.message LIKE '%<10 chars%', SUBSTRING(m.message, 1, 10), m.message) as preview
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) 
                OR (m.sender_id = ? AND m.receiver_id = ?))
            AND (m.is_unsent = 0 OR m.sender_id = ?)
            AND (m.is_removed_for_receiver = 0 OR m.sender_id = ?)
            ORDER BY m.sent_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iiiiiii", $user_id, $partner_id, $partner_id, $user_id, $user_id, $user_id, $limit);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Mark received messages as read
    $readStmt = $conn->prepare("
        UPDATE messages SET is_read = 1 
        WHERE receiver_id = ? AND sender_id = ? AND is_read = 0
    ");
    $readStmt->bind_param("ii", $user_id, $partner_id);
    $readStmt->execute();
    $readStmt->close();

    // Format messages for display
    $formatted_messages = [];
    foreach ($messages as $msg) {
        $time_diff = time() - strtotime($msg['sent_at']);
        $time_text = '';
        
        if ($time_diff < 60) {
            $time_text = 'just now';
        } elseif ($time_diff < 3600) {
            $mins = floor($time_diff / 60);
            $time_text = $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($time_diff < 86400) {
            $hours = floor($time_diff / 3600);
            $time_text = $hours . ' h' . ($hours > 1 ? 's' : '') . ' ago';
        } else {
            $time_text = date('M d H:i', strtotime($msg['sent_at']));
        }

        $formatted_messages[] = [
            'id' => $msg['id'],
            'sender_id' => $msg['sender_id'],
            'message' => $msg['is_unsent'] ? '[Message unsent]' : $msg['message'],
            'sent_at' => $msg['sent_at'],
            'is_read' => (int)$msg['is_read'],
            'is_edited' => (int)$msg['is_edited'],
            'is_unsent' => (int)$msg['is_unsent'],
            'message_type' => $msg['message_type'] ?? 'text',
            'media_url' => $msg['media_url'] ?? null,
            'sender' => [
                'id' => $msg['sender_id'],
                'first_name' => $msg['first_name'],
                'last_name' => $msg['last_name'],
                'profile_pic' => $msg['profile_pic'],
                'is_verified' => $msg['is_verified']
            ],
            'time_text' => $msg['sender_id'] == $user_id 
                ? ($msg['is_read'] ? 'seen ' . $time_text : 'sent ' . $time_text)
                : $time_text,
            'display_prefix' => $msg['sender_id'] == $user_id ? 'You:' : '',
            'message_preview' => strlen($msg['message']) > 10 ? substr($msg['message'], 0, 10) . '...' : $msg['message']
        ];
    }

    echo json_encode([
        'success' => true,
        'messages' => array_reverse($formatted_messages),
        'unread_count' => 0
    ]);

    closeDBConnection($conn);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
