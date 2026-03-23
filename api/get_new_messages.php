<?php
session_start();
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$partner_id = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 0;
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

if (!$partner_id) {
    echo json_encode(['success' => false, 'message' => 'Partner not specified']);
    exit();
}

$conn = getDBConnection();

// Fetch messages newer than last_id and not deleted for this user
$stmt = $conn->prepare("SELECT m.*, u.profile_pic as sender_pic FROM messages m LEFT JOIN users u ON u.id = m.sender_id
    WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
      AND m.id > ?
      AND NOT (m.sender_id = ? AND m.deleted_for_sender = 1)
      AND NOT (m.receiver_id = ? AND m.deleted_for_receiver = 1)
    ORDER BY m.sent_at ASC");

$stmt->bind_param("iiiiiii", $partner_id, $user_id, $user_id, $partner_id, $last_id, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while ($row = $result->fetch_assoc()) {
    if (!empty($row['media_url'])) {
        $ext = strtolower(pathinfo($row['media_url'], PATHINFO_EXTENSION));
        $image_ext = ['jpg','jpeg','png','gif'];
        $video_map = ['mp4'=>'video/mp4','mov'=>'video/quicktime','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska'];
        $image_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif'];
        if (in_array($ext, $image_ext)) {
            $row['mime_type'] = $image_map[$ext] ?? 'image/*';
        } elseif (isset($video_map[$ext])) {
            $row['mime_type'] = $video_map[$ext];
        } else {
            $row['mime_type'] = '';
        }
    } else {
        $row['mime_type'] = '';
    }
    $messages[] = $row;
}
$stmt->close();

// Typing indicator - check typing_status table
$typing = false;
$ts_stmt = $conn->prepare("SELECT typing, UNIX_TIMESTAMP(updated_at) as ts FROM typing_status WHERE sender_id = ? AND receiver_id = ? LIMIT 1");
$ts_stmt->bind_param("ii", $partner_id, $user_id);
if ($ts_stmt->execute()) {
    $ts_res = $ts_stmt->get_result()->fetch_assoc();
    if ($ts_res && $ts_res['typing'] == 1 && (time() - intval($ts_res['ts'])) <= 5) {
        $typing = true;
    }
}
$ts_stmt->close();

// Read-updates: messages sent by current user to partner that have become read since last_check
$read_updates = [];
$last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;
if ($last_check > 0) {
    $r_stmt = $conn->prepare("SELECT id FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 1 AND UNIX_TIMESTAMP(read_at) > ?");
    $r_stmt->bind_param("iii", $user_id, $partner_id, $last_check);
    if ($r_stmt->execute()) {
        $r_res = $r_stmt->get_result();
        while ($r_row = $r_res->fetch_assoc()) {
            $read_updates[] = $r_row['id'];
        }
    }
    $r_stmt->close();
}

// add mime_type for messages (already added earlier for fetched messages)

echo json_encode(['success' => true, 'messages' => $messages, 'typing' => $typing, 'read_updates' => $read_updates]);
closeDBConnection($conn);
