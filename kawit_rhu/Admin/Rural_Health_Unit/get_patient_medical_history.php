<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rhu_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$host = 'localhost';
$dbname = 'kawit_rhu';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$patient_id = $_GET['patient_id'] ?? null;

if (!$patient_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Patient ID required']);
    exit;
}

// Get recent consultations
$consultStmt = $pdo->prepare("
    SELECT consultation_date, consultation_type, chief_complaint, diagnosis, 
           CONCAT(s.first_name, ' ', s.last_name) as doctor_name
    FROM consultations c
    LEFT JOIN staff s ON c.assigned_doctor = s.id
    WHERE c.patient_id = ? AND c.status = 'completed'
    ORDER BY c.consultation_date DESC
    LIMIT 5
");
$consultStmt->execute([$patient_id]);
$consultations = $consultStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent lab results
$labStmt = $pdo->prepare("
    SELECT test_date, test_type, test_category, interpretation, status
    FROM laboratory_results
    WHERE patient_id = ? AND status = 'completed'
    ORDER BY test_date DESC
    LIMIT 3
");
$labStmt->execute([$patient_id]);
$lab_results = $labStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent prescriptions
$prescStmt = $pdo->prepare("
    SELECT prescription_date, medication_name, dosage_strength, status
    FROM prescriptions
    WHERE patient_id = ? 
    ORDER BY prescription_date DESC
    LIMIT 3
");
$prescStmt->execute([$patient_id]);
$prescriptions = $prescStmt->fetchAll(PDO::FETCH_ASSOC);

// Get issued certificates count
$certStmt = $pdo->prepare("
    SELECT COUNT(*) as cert_count
    FROM medical_certificates
    WHERE patient_id = ? AND status = 'issued'
");
$certStmt->execute([$patient_id]);
$cert_count = $certStmt->fetchColumn();

// Get last fitness status from previous certificate
$lastFitnessStmt = $pdo->prepare("
    SELECT fitness_status
    FROM medical_certificates
    WHERE patient_id = ? AND status = 'issued' AND fitness_status IS NOT NULL
    ORDER BY date_issued DESC
    LIMIT 1
");
$lastFitnessStmt->execute([$patient_id]);
$last_fitness = $lastFitnessStmt->fetchColumn();

echo json_encode([
    'success' => true,
    'consultations' => $consultations,
    'lab_results' => $lab_results,
    'prescriptions' => $prescriptions,
    'certificate_count' => $cert_count,
    'last_fitness_status' => $last_fitness
]);