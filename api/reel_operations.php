<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/session.php';

requireLogin();

header('Content-Type: application/json');

$user_id = getCurrentUserId();
$action = $_GET['action'] ?? '';

try {
    $conn = getDBConnection();

    // Ensure reels table exists
    $createTableSQL = "CREATE TABLE IF NOT EXISTS reels (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        title VARCHAR(500),
        caption TEXT,
        video_url VARCHAR(500),
        thumbnail_url VARCHAR(500),
        music_url VARCHAR(500),
        music_name VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        views INT DEFAULT 0,
        likes INT DEFAULT 0,
        comments INT DEFAULT 0,
        shares INT DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $conn->query($createTableSQL);

    // Ensure reel_likes table exists
    $createLikesSQL = "CREATE TABLE IF NOT EXISTS reel_likes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        reel_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_like (reel_id, user_id),
        FOREIGN KEY (reel_id) REFERENCES reels(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $conn->query($createLikesSQL);

    // Ensure reel_comments table exists
    $createCommentsSQL = "CREATE TABLE IF NOT EXISTS reel_comments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        reel_id INT NOT NULL,
        user_id INT NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reel_id) REFERENCES reels(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $conn->query($createCommentsSQL);

    switch ($action) {
        case 'get_reels':
            $limit = $_GET['limit'] ?? 10;
            $offset = $_GET['offset'] ?? 0;
            $user_filter = $_GET['user_id'] ?? 0;

            if ($user_filter > 0) {
                $stmt = $conn->prepare("
                    SELECT r.*, 
                           u.first_name, u.last_name, u.username, u.profile_pic, u.is_verified,
                           (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id AND user_id = ?) as is_liked
                    FROM reels r
                    JOIN users u ON r.user_id = u.id
                    WHERE r.user_id = ?
                    ORDER BY r.created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->bind_param("iiii", $user_id, $user_filter, $limit, $offset);
            } else {
                $stmt = $conn->prepare("
                    SELECT r.*, 
                           u.first_name, u.last_name, u.username, u.profile_pic, u.is_verified,
                           (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id AND user_id = ?) as is_liked
                    FROM reels r
                    JOIN users u ON r.user_id = u.id
                    ORDER BY r.created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->bind_param("iii", $user_id, $limit, $offset);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $reels = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            echo json_encode(['success' => true, 'reels' => $reels]);
            break;

        case 'upload_reel':
            if (!isset($_FILES['video']) || !isset($_POST['caption'])) {
                throw new Exception('Video and caption are required');
            }

            $upload_dir = '../uploads/reels/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $video_file = $_FILES['video'];
            $caption = htmlspecialchars($_POST['caption'], ENT_QUOTES, 'UTF-8');
            $music_name = $_POST['music_name'] ?? '';
            $music_url = $_POST['music_url'] ?? '';
            $title = $_POST['title'] ?? '';

            // Validate video file
            $allowed_types = ['video/mp4', 'video/quicktime', 'video/x-msvideo'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $video_file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed_types)) {
                throw new Exception('Invalid video format');
            }

            // Save video
            $video_name = $user_id . '_' . time() . '_' . uniqid() . '.mp4';
            $video_path = $upload_dir . $video_name;
            move_uploaded_file($video_file['tmp_name'], $video_path);

            // Create thumbnail (this would need ffmpeg in production)
            $thumbnail_name = $user_id . '_' . time() . '_' . uniqid() . '.jpg';
            $thumbnail_path = $upload_dir . $thumbnail_name;

            $stmt = $conn->prepare("
                INSERT INTO reels (user_id, title, caption, video_url, thumbnail_url, music_name, music_url)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $video_url = '/uploads/reels/' . $video_name;
            $thumbnail_url = '/uploads/reels/' . $thumbnail_name;
            $stmt->bind_param("issssss", $user_id, $title, $caption, $video_url, $thumbnail_url, $music_name, $music_url);
            $stmt->execute();
            $reel_id = $conn->insert_id;
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Reel uploaded successfully',
                'reel_id' => $reel_id
            ]);
            break;

        case 'like_reel':
            if (!isset($_POST['reel_id'])) {
                throw new Exception('reel_id is required');
            }

            $reel_id = $_POST['reel_id'];

            // Check if already liked
            $stmt = $conn->prepare("SELECT id FROM reel_likes WHERE reel_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $reel_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $liked = $result->fetch_assoc();
            $stmt->close();

            if ($liked) {
                // Unlike
                $stmt = $conn->prepare("DELETE FROM reel_likes WHERE reel_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $reel_id, $user_id);
                $stmt->execute();
                $stmt->close();
                $action_taken = 'unliked';
            } else {
                // Like
                $stmt = $conn->prepare("INSERT INTO reel_likes (reel_id, user_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $reel_id, $user_id);
                $stmt->execute();
                $stmt->close();
                $action_taken = 'liked';
            }

            // Get updated like count
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM reel_likes WHERE reel_id = ?");
            $stmt->bind_param("i", $reel_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            $stmt->close();

            echo json_encode([
                'success' => true,
                'action' => $action_taken,
                'like_count' => $count
            ]);
            break;

        case 'add_comment':
            if (!isset($_POST['reel_id'], $_POST['comment'])) {
                throw new Exception('reel_id and comment are required');
            }

            $reel_id = $_POST['reel_id'];
            $comment = htmlspecialchars($_POST['comment'], ENT_QUOTES, 'UTF-8');

            $stmt = $conn->prepare("
                INSERT INTO reel_comments (reel_id, user_id, comment)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param("iis", $reel_id, $user_id, $comment);
            $stmt->execute();
            $comment_id = $conn->insert_id;
            $stmt->close();

            // Get user info for response
            $user = getUserById($user_id);

            echo json_encode([
                'success' => true,
                'comment_id' => $comment_id,
                'comment' => [
                    'id' => $comment_id,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'profile_pic' => $user['profile_pic']
                    ],
                    'comment' => $comment,
                    'created_at' => date('Y-m-d H:i:s')
                ]
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
