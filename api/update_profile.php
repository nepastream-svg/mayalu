<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = $_POST;

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // Update user basic info
    $stmt = $conn->prepare("
        UPDATE users SET 
            bio = ?, education = ?, occupation = ?, marital_status = ?,
            religion = ?, children_preference = ?, drinking = ?, smoking = ?,
            location = ?, looking_for = ?, gender_preference = ?,
            height_feet = ?, height_inches = ?, dob = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->bind_param(
        "ssssssssssssisi",
        $data['bio'], $data['education'], $data['occupation'], $data['marital_status'],
        $data['religion'], $data['children_preference'], $data['drinking'], $data['smoking'],
        $data['location'], $data['looking_for'], $data['gender_preference'],
        $data['height_feet'], $data['height_inches'], $data['dob'],
        $user_id
    );
    $stmt->execute();
    
    // Update hobbies
    $delete_stmt = $conn->prepare("DELETE FROM user_hobbies WHERE user_id = ?");
    $delete_stmt->bind_param("i", $user_id);
    $delete_stmt->execute();
    
    if (!empty($data['hobbies'])) {
        $insert_stmt = $conn->prepare("INSERT INTO user_hobbies (user_id, hobby_id) VALUES (?, ?)");
        foreach ($data['hobbies'] as $hobby_id) {
            $insert_stmt->bind_param("ii", $user_id, $hobby_id);
            $insert_stmt->execute();
        }
        $insert_stmt->close();
    }
    
    // Handle profile photo uploads
    if (!empty($_FILES)) {
        $photo_count = 0;
        foreach ($_FILES as $key => $file) {
            if (strpos($key, 'photo_') === 0 && $file['error'] == 0) {
                $target_dir = "../uploads/profiles/";
                $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
                $new_filename = "user_" . $user_id . "_" . $photo_count . "_" . time() . "." . $file_extension;
                $target_file = $target_dir . $new_filename;
                
                if (move_uploaded_file($file["tmp_name"], $target_file)) {
                    // Check if this is profile picture (first photo)
                    if ($photo_count == 0) {
                        $photo_stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                        $photo_stmt->bind_param("si", $new_filename, $user_id);
                        $photo_stmt->execute();
                        $photo_stmt->close();
                    }
                    
                    // Save to user_photos table
                    $insert_photo = $conn->prepare("
                        INSERT INTO user_photos (user_id, photo_url, is_profile_pic, display_order) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $is_profile = ($photo_count == 0) ? 1 : 0;
                    // Bind in correct order: user_id (int), photo_url (string), is_profile_pic (int), display_order (int)
                    $insert_photo->bind_param("isii", $user_id, $new_filename, $is_profile, $photo_count);
                    $insert_photo->execute();
                    $insert_photo->close();
                    
                    $photo_count++;
                }
            }
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
}

closeDBConnection($conn);
?>