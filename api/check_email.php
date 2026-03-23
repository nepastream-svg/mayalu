<?php
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? '';
    
    $available = !checkEmailExists($email);
    
    echo json_encode([
        'available' => $available,
        'email' => $email
    ]);
}
?>