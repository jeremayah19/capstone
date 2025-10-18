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

// Accept both 'id' and 'certificate_id' parameters for flexibility
$certificate_id = $_GET['certificate_id'] ?? $_GET['id'] ?? null;

if (!$certificate_id) {
    echo json_encode(['success' => false, 'message' => 'No certificate ID provided']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT mc.*, 
               p.id as patient_db_id,
               p.patient_id,
               p.blood_type, 
               p.allergies, 
               p.medical_history, 
               p.philhealth_number,
               p.email, 
               p.phone, 
               p.address,
               p.date_of_birth,
               COALESCE(mc.patient_full_name_override, CONCAT(p.first_name, ' ', IFNULL(CONCAT(p.middle_name, ' '), ''), p.last_name, IFNULL(CONCAT(' ', p.suffix), ''))) as patient_name,
               COALESCE(mc.patient_age_override, YEAR(CURDATE()) - YEAR(p.date_of_birth)) as patient_age,
               COALESCE(mc.patient_gender_override, p.gender) as patient_gender,
               CONCAT(s.first_name, ' ', s.last_name) as doctor_name,
               s.license_number as doctor_license,
               c.consultation_date as examination_date
        FROM medical_certificates mc
        JOIN patients p ON mc.patient_id = p.id
        LEFT JOIN staff s ON mc.issued_by = s.id
        LEFT JOIN consultations c ON mc.consultation_id = c.id
        WHERE mc.id = ?
    ");
    $stmt->execute([$certificate_id]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($certificate) {
        echo json_encode(['success' => true, 'certificate' => $certificate]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Certificate not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error loading certificate: ' . $e->getMessage()]);
}
?>