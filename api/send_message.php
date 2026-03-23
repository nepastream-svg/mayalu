<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$sender_id = $_SESSION['user_id'];
$receiver_id = $_POST['receiver_id'] ?? 0;
$message = $_POST['message'] ?? '';
$file = $_FILES['file'] ?? null;

if (!$receiver_id) {
    echo json_encode(['success' => false, 'message' => 'Receiver not specified']);
    exit();
}

// Previously we required users to be matched to send messages; per new requirements we allow messaging any user.
// We still perform basic validation like checking receiver existence and file limits.
$conn = getDBConnection();
// Ensure receiver exists
$checkUserStmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
$checkUserStmt->bind_param("i", $receiver_id);
$checkUserStmt->execute();
$exists = $checkUserStmt->get_result()->num_rows > 0;
$checkUserStmt->close();

if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'Receiver not found']);
    exit();
}

// Check message limit for free users
$user = getUserById($sender_id);
if ($user['subscription_type'] == 'Free') {
    // Check daily message limit
    $message_stmt = $conn->prepare("
        SELECT COUNT(*) as message_count FROM messages 
        WHERE sender_id = ? AND DATE(sent_at) = CURDATE()
    ");
    $message_stmt->bind_param("i", $sender_id);
    $message_stmt->execute();
    $result = $message_stmt->get_result()->fetch_assoc();
    $message_stmt->close();
    
    if ($result['message_count'] >= 50) {
        echo json_encode(['success' => false, 'message' => 'Daily message limit reached. Watch ad or upgrade to premium.']);
        exit();
    }
}

// Handle file upload
$media_url = '';
$message_type = 'text';
$mime_type = '';

if ($file && $file['error'] == 0) {
    $target_dir = "../uploads/chat/";
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $new_filename = "chat_" . $sender_id . "_" . $receiver_id . "_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    // Check file size (max 10MB)
    if ($file["size"] > 10000000) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 10MB)']);
        exit();
    }
    
    // Allow certain file formats
    $image_extensions = ["jpg", "jpeg", "png", "gif"];
    $video_extensions = ["mp4", "mov", "avi", "mkv"];
    
    if (in_array($file_extension, $image_extensions)) {
        $message_type = 'image';
        // set mime
        $mime_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif'];
        $mime_type = $mime_map[$file_extension] ?? 'image/*';
    } elseif (in_array($file_extension, $video_extensions)) {
        $message_type = 'video';
        $mime_map = ['mp4'=>'video/mp4','mov'=>'video/quicktime','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska'];
        $mime_type = $mime_map[$file_extension] ?? 'video/*';
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid file format']);
        exit();
    }
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        $media_url = $new_filename;
    }
}

// Insert message
$insert_stmt = $conn->prepare("
    INSERT INTO messages (sender_id, receiver_id, message, message_type, media_url, sent_at) 
    VALUES (?, ?, ?, ?, ?, NOW())
");
$insert_stmt->bind_param("iisss", $sender_id, $receiver_id, $message, $message_type, $media_url);

if ($insert_stmt->execute()) {
    $message_id = $conn->insert_id;
    
    // Update last activity
    $update_stmt = $conn->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
    $update_stmt->bind_param("i", $sender_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'message' => $message,
        'message_type' => $message_type,
        'media_url' => $media_url,
        'mime_type' => $mime_type
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send message']);
}

$insert_stmt->close();
closeDBConnection($conn);
?>