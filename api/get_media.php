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
if (!$partner_id) {
    echo json_encode(['success' => false, 'message' => 'Partner required']);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, sender_id, receiver_id, message_type, media_url, sent_at FROM messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND media_url <> '' ORDER BY sent_at DESC LIMIT 100");
$stmt->bind_param("iiii", $user_id, $partner_id, $partner_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
$video_map = ['mp4'=>'video/mp4','mov'=>'video/quicktime','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska'];
$image_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif'];
while ($row = $res->fetch_assoc()) {
    $ext = strtolower(pathinfo($row['media_url'], PATHINFO_EXTENSION));
    if (in_array($ext, array_keys($image_map))) {
        $row['mime_type'] = $image_map[$ext];
    } elseif (isset($video_map[$ext])) {
        $row['mime_type'] = $video_map[$ext];
    } else {
        $row['mime_type'] = '';
    }
    $items[] = $row;
}
$stmt->close();
closeDBConnection($conn);

echo json_encode(['success' => true, 'items' => $items]);
