<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/session.php';

requireLogin();

header('Content-Type: application/json');

$user_id = getCurrentUserId();
$target_user_id = $_GET['user_id'] ?? 0;

if (!$target_user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'user_id required']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Get user's timezone and last_active
    $stmt = $conn->prepare("
        SELECT last_active, timezone FROM users WHERE id = ?
    ");
    $stmt->bind_param("i", $target_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || !$user['last_active']) {
        echo json_encode([
            'status' => 'never',
            'text' => 'Never seen'
        ]);
    } else {
        $timezone = $user['timezone'] ?? 'UTC';
        $last_active = new DateTime($user['last_active'], new DateTimeZone($timezone));
        $now = new DateTime('now', new DateTimeZone($timezone));
        
        $diff = $now->diff($last_active);
        
        $text = '';
        if ($diff->d > 0) {
            $text = $diff->d == 1 ? '1 day ago' : $diff->d . ' days ago';
        } elseif ($diff->h > 0) {
            $text = $diff->h == 1 ? '1 hour ago' : $diff->h . ' hours ago';
        } elseif ($diff->i > 0) {
            $text = $diff->i == 1 ? '1 minute ago' : $diff->i . ' minutes ago';
        } else {
            $text = 'Just now';
        }

        echo json_encode([
            'status' => $diff->i < 5 ? 'online' : 'offline',
            'text' => $text,
            'last_active' => $user['last_active'],
            'timezone' => $timezone
        ]);
    }

    closeDBConnection($conn);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
