<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/session.php';

requireLogin();

header('Content-Type: application/json');

$user_id = getCurrentUserId();
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    if (!isset($_FILES['images'])) {
        throw new Exception('No images provided');
    }

    $conn = getDBConnection();
    $profile_images = [];
    $upload_dir = '../uploads/profiles/';
    
    // Ensure upload directory exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Process uploaded files
    $files = $_FILES['images'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    foreach ($files['tmp_name'] as $index => $tmp_file) {
        if ($files['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp_file);
        finfo_close($finfo);

        if (!in_array($mime, $allowed_types)) {
            throw new Exception('Invalid file type: ' . $mime);
        }

        // Generate unique filename
        $ext = pathinfo($files['name'][$index], PATHINFO_EXTENSION);
        $filename = $user_id . '_' . time() . '_' . uniqid() . '.' . $ext;
        $filepath = $upload_dir . $filename;

        // Resize and optimize image
        $image = null;
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($tmp_file);
                break;
            case 'image/png':
                $image = imagecreatefrompng($tmp_file);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($tmp_file);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($tmp_file);
                break;
        }

        if ($image) {
            $width = imagesx($image);
            $height = imagesy($image);
            $max_size = 1200;
            
            if ($width > $max_size || $height > $max_size) {
                $ratio = min($max_size / $width, $max_size / $height);
                $new_width = (int)($width * $ratio);
                $new_height = (int)($height * $ratio);
                
                $resized = imagecreatetruecolor($new_width, $new_height);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                
                imagejpeg($resized, $filepath, 85);
                imagedestroy($resized);
            } else {
                imagejpeg($image, $filepath, 85);
            }
            imagedestroy($image);
        }

        if (file_exists($filepath)) {
            $profile_images[] = [
                'filename' => $filename,
                'url' => '/uploads/profiles/' . $filename,
                'uploaded_at' => date('Y-m-d H:i:s')
            ];
        }
    }

    if (count($profile_images) > 0) {
        // Store in database
        $images_json = json_encode($profile_images);
        $stmt = $conn->prepare("UPDATE users SET profile_images = ? WHERE id = ?");
        $stmt->bind_param("si", $images_json, $user_id);
        $stmt->execute();
        $stmt->close();

        // If first image, set as profile picture
        if (count($profile_images) > 0) {
            $profile_pic = '/uploads/profiles/' . $profile_images[0]['filename'];
            $stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
            $stmt->bind_param("si", $profile_pic, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        $response = [
            'success' => true,
            'message' => 'Images uploaded successfully',
            'images' => $profile_images,
            'profile_pic' => $profile_images[0]['url'] ?? ''
        ];
    } else {
        throw new Exception('No images were successfully uploaded');
    }

    closeDBConnection($conn);

} catch (Exception $e) {
    http_response_code(400);
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response);
?>
