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

if ($target_user_id == $user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot check status with yourself']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Check if already friends (match request accepted)
    $stmt = $conn->prepare("
        SELECT id, status FROM match_requests 
        WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
    ");
    $stmt->bind_param("iiii", $user_id, $target_user_id, $target_user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $match_request = $result->fetch_assoc();
    $stmt->close();

    $response = [
        'status' => 'no_request', // no_request, request_sent, request_received, friends
        'request_id' => null,
        'is_friend' => false
    ];

    if ($match_request) {
        if ($match_request['status'] === 'accepted') {
            $response['status'] = 'friends';
            $response['is_friend'] = true;
        } elseif ($match_request['sender_id'] == $user_id) {
            $response['status'] = 'request_sent';
        } else {
            $response['status'] = 'request_received';
        }
        $response['request_id'] = $match_request['id'];
    }

    closeDBConnection($conn);
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
