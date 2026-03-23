<?php
session_start();
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $saved_user_id = $data['user_id'] ?? 0;
    $user_id = $_SESSION['user_id'];
    
    $conn = getDBConnection();
    
    // Check if already saved
    $checkStmt = $conn->prepare("SELECT id FROM saved_profiles WHERE user_id = ? AND saved_user_id = ?");
    $checkStmt->bind_param("ii", $user_id, $saved_user_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        // Unsave
        $deleteStmt = $conn->prepare("DELETE FROM saved_profiles WHERE user_id = ? AND saved_user_id = ?");
        $deleteStmt->bind_param("ii", $user_id, $saved_user_id);
        $deleteStmt->execute();
        $message = "Profile unsaved";
    } else {
        // Save
        $insertStmt = $conn->prepare("INSERT INTO saved_profiles (user_id, saved_user_id) VALUES (?, ?)");
        $insertStmt->bind_param("ii", $user_id, $saved_user_id);
        $insertStmt->execute();
        $message = "Profile saved";
    }
    
    echo json_encode(['success' => true, 'message' => $message]);
    closeDBConnection($conn);
}
?>