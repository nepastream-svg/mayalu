<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/session.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
// Allow optional ?user_id= to debug another user (admins only)
$view_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user_id;

$viewer = getUserById($current_user_id);
if (!$viewer) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Viewer not found']);
    exit;
}

// Only allow debugging other users if viewer is admin
if ($view_user_id !== $current_user_id) {
    $viewer_is_admin = !empty($viewer['is_admin']);
    if (!$viewer_is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

$user = getUserById($view_user_id);
if (!$user) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE id != ? ORDER BY created_at DESC");
$stmt->bind_param('i', $view_user_id);
$stmt->execute();
$result = $stmt->get_result();
$candidates = [];
while ($row = $result->fetch_assoc()) {
    $candidates[] = $row;
}
$stmt->close();

$results = [];
$user_pref = trim($user['gender_preference'] ?? 'Everyone');
$user_gender = trim($user['gender'] ?? '');
$user_looking = trim($user['looking_for'] ?? '');

foreach ($candidates as $c) {
    $candidate = $c;
    $candidate_gender = trim($candidate['gender'] ?? '');
    $candidate_pref = trim($candidate['gender_preference'] ?? 'Everyone');
    $candidate_looking = trim($candidate['looking_for'] ?? '');

    $reasons = [];
    $match = true;

    // Gender filter from user's perspective
    if ($user_pref !== 'Everyone') {
        if ($candidate_gender !== $user_pref) {
            $match = false;
            $reasons[] = "candidate gender ({$candidate_gender}) != user pref ({$user_pref})";
        }
    } else {
        $reasons[] = "user pref is Everyone (no gender filter)";
    }

    // Candidate's preference must accept user
    if ($candidate_pref !== 'Everyone' && $candidate_pref !== $user_gender) {
        $match = false;
        $reasons[] = "candidate pref ({$candidate_pref}) does not accept user gender ({$user_gender})";
    } else {
        $reasons[] = "candidate pref accepts user";
    }

    // Looking for
    if (!empty($user_looking)) {
        if ($candidate_looking !== $user_looking) {
            $match = false;
            $reasons[] = "looking_for mismatch (user: {$user_looking}, candidate: {$candidate_looking})";
        } else {
            $reasons[] = "looking_for matches";
        }
    } else {
        $reasons[] = "user has no looking_for restriction";
    }

    $results[] = [
        'id' => $candidate['id'],
        'first_name' => $candidate['first_name'],
        'last_name' => $candidate['last_name'],
        'gender' => $candidate_gender,
        'gender_preference' => $candidate_pref,
        'created_at' => $candidate['created_at'],
        'match' => $match,
        'reasons' => $reasons,
    ];
}

closeDBConnection($conn);

echo json_encode(['success' => true, 'user' => ['id' => $user['id'], 'first_name' => $user['first_name'], 'gender' => $user_gender, 'gender_preference' => $user_pref, 'looking_for' => $user_looking], 'results' => $results]);
