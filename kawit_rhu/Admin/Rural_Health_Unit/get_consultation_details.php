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

$consultation_id = $_GET['consultation_id'] ?? null;

if (!$consultation_id) {
    echo json_encode(['success' => false, 'message' => 'Consultation ID required']);
    exit;
}

try {
    // Get complete consultation details
    $stmt = $pdo->prepare("
        SELECT c.*,
               CONCAT(p.first_name, ' ', IFNULL(CONCAT(p.middle_name, ' '), ''), p.last_name) as patient_name,
               CONCAT(s.first_name, ' ', s.last_name) as doctor_name
        FROM consultations c
        JOIN patients p ON c.patient_id = p.id
        LEFT JOIN staff s ON c.assigned_doctor = s.id
        WHERE c.id = ?
    ");
    $stmt->execute([$consultation_id]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$consultation) {
        echo json_encode(['success' => false, 'message' => 'Consultation not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'consultation' => $consultation]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error retrieving consultation details']);
}
?>