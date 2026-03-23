<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

$reel_id = $_GET['reel_id'] ?? 0;

if (!$reel_id) {
    echo json_encode(['success' => false, 'message' => 'Reel not specified']);
    exit();
}

$conn = getDBConnection();

$stmt = $conn->prepare("
    SELECT rc.*, u.first_name, u.last_name, u.profile_pic,
           TIMESTAMPDIFF(SECOND, rc.created_at, NOW()) as seconds_ago
    FROM reel_comments rc
    JOIN users u ON rc.user_id = u.id
    WHERE rc.reel_id = ?
    ORDER BY rc.created_at DESC
    LIMIT 50
");
$stmt->bind_param("i", $reel_id);
$stmt->execute();
$comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Format time ago
foreach ($comments as &$comment) {
    $seconds = $comment['seconds_ago'];
    
    if ($seconds < 60) {
        $comment['time_ago'] = $seconds . 's ago';
    } elseif ($seconds < 3600) {
        $comment['time_ago'] = floor($seconds / 60) . 'm ago';
    } elseif ($seconds < 86400) {
        $comment['time_ago'] = floor($seconds / 3600) . 'h ago';
    } elseif ($seconds < 604800) {
        $comment['time_ago'] = floor($seconds / 86400) . 'd ago';
    } elseif ($seconds < 2592000) {
        $comment['time_ago'] = floor($seconds / 604800) . 'w ago';
    } elseif ($seconds < 31536000) {
        $comment['time_ago'] = floor($seconds / 2592000) . 'mo ago';
    } else {
        $comment['time_ago'] = floor($seconds / 31536000) . 'y ago';
    }
}

closeDBConnection($conn);

echo json_encode([
    'success' => true,
    'comments' => $comments
]);
?>