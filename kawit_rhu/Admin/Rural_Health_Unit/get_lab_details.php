<?php
session_start();

// Check if user is logged in and is RHU admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rhu_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Database configuration
$host = 'localhost';
$dbname = 'kawit_rhu';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Connection failed']);
    exit;
}

$lab_id = $_GET['lab_id'] ?? null;

if (!$lab_id) {
    echo json_encode(['success' => false, 'message' => 'Lab ID required']);
    exit;
}

try {
    // Get complete lab result details
    $stmt = $pdo->prepare("
        SELECT lr.*,
               CONCAT(p.first_name, ' ', IFNULL(CONCAT(p.middle_name, ' '), ''), p.last_name) as patient_name,
               YEAR(CURDATE()) - YEAR(p.date_of_birth) as patient_age,
               p.gender as patient_gender,
               CONCAT(s1.first_name, ' ', s1.last_name) as performed_by_name,
               CONCAT(s2.first_name, ' ', s2.last_name) as verified_by_name
        FROM laboratory_results lr
        JOIN patients p ON lr.patient_id = p.id
        LEFT JOIN staff s1 ON lr.performed_by = s1.id
        LEFT JOIN staff s2 ON lr.verified_by = s2.id
        WHERE lr.id = ?
    ");
    $stmt->execute([$lab_id]);
    $lab_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lab_result) {
        echo json_encode(['success' => false, 'message' => 'Lab result not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'lab_result' => $lab_result]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error retrieving lab result details']);
}
?>