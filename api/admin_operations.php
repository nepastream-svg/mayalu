<?php
session_start();
require_once '../includes/functions.php';

// Check if user is admin
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$conn = getDBConnection();

try {
    switch ($action) {
        case 'toggle_verified':
            if (!isset($_POST['user_id'])) {
                throw new Exception('user_id is required');
            }

            $user_id = $_POST['user_id'];

            // Get current verified status
            $stmt = $conn->prepare("SELECT is_verified FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                throw new Exception('User not found');
            }

            $new_status = $user['is_verified'] ? 0 : 1;
            $stmt = $conn->prepare("UPDATE users SET is_verified = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $user_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => $new_status ? 'User verified' : 'Verification removed',
                'is_verified' => (bool)$new_status
            ]);
            break;

        case 'ban_user':
            if (!isset($_POST['user_id'])) {
                throw new Exception('user_id is required');
            }

            $user_id = $_POST['user_id'];

            $stmt = $conn->prepare("UPDATE users SET is_banned = 1 WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'User banned']);
            break;

        case 'unban_user':
            if (!isset($_POST['user_id'])) {
                throw new Exception('user_id is required');
            }

            $user_id = $_POST['user_id'];

            $stmt = $conn->prepare("UPDATE users SET is_banned = 0 WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'User unbanned']);
            break;

        case 'cancel_request':
            if (!isset($_POST['request_id'])) {
                throw new Exception('request_id is required');
            }

            $request_id = $_POST['request_id'];

            $stmt = $conn->prepare("DELETE FROM match_requests WHERE id = ?");
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Request cancelled']);
            break;

        case 'delete_reel':
            if (!isset($_POST['reel_id'])) {
                throw new Exception('reel_id is required');
            }

            $reel_id = $_POST['reel_id'];

            // Get reel details
            $stmt = $conn->prepare("SELECT video_url, thumbnail_url FROM reels WHERE id = ?");
            $stmt->bind_param("i", $reel_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $reel = $result->fetch_assoc();
            $stmt->close();

            if ($reel) {
                // Delete files
                @unlink($_SERVER['DOCUMENT_ROOT'] . $reel['video_url']);
                @unlink($_SERVER['DOCUMENT_ROOT'] . $reel['thumbnail_url']);

                // Delete from database
                $stmt = $conn->prepare("DELETE FROM reels WHERE id = ?");
                $stmt->bind_param("i", $reel_id);
                $stmt->execute();
                $stmt->close();
            }

            echo json_encode(['success' => true, 'message' => 'Reel deleted']);
            break;

        case 'give_premium':
            if (!isset($_POST['user_id'], $_POST['days'])) {
                throw new Exception('user_id and days are required');
            }

            $user_id = $_POST['user_id'];
            $days = (int)$_POST['days'];

            $end_date = date('Y-m-d', strtotime("+$days days"));

            $stmt = $conn->prepare("
                UPDATE users 
                SET subscription_type = 'Premium', subscription_end = ?, is_verified = 1
                WHERE id = ?
            ");
            $stmt->bind_param("si", $end_date, $user_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => "Premium given for $days days",
                'end_date' => $end_date
            ]);
            break;

        case 'remove_premium':
            if (!isset($_POST['user_id'])) {
                throw new Exception('user_id is required');
            }

            $user_id = $_POST['user_id'];

            $stmt = $conn->prepare("
                UPDATE users 
                SET subscription_type = 'Free', subscription_end = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Premium removed']);
            break;

        case 'get_user_stats':
            if (!isset($_GET['user_id'])) {
                throw new Exception('user_id is required');
            }

            $user_id = $_GET['user_id'];

            // Get various stats
            $stmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM messages WHERE sender_id = ? OR receiver_id = ?) as total_messages,
                    (SELECT COUNT(*) FROM match_requests WHERE sender_id = ? OR receiver_id = ?) as total_requests,
                    (SELECT COUNT(*) FROM reels WHERE user_id = ?) as total_reels,
                    (SELECT COUNT(*) FROM profile_views WHERE viewed_user_id = ?) as profile_views
            ");
            $stmt->bind_param("iiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $stmt->close();

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        case 'update_report_status':
            if (!isset($data['report_id'], $data['status'])) {
                throw new Exception('report_id and status are required');
            }

            $report_id = $data['report_id'];
            $status = htmlspecialchars($data['status'], ENT_QUOTES, 'UTF-8');
            $allowed_statuses = ['pending', 'reviewed', 'resolved', 'rejected'];

            if (!in_array($status, $allowed_statuses)) {
                throw new Exception('Invalid status');
            }

            $stmt = $conn->prepare("UPDATE content_reports SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $report_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Report status updated']);
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
