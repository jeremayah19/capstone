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

// Get patient basic info
$patientStmt = $pdo->prepare("
    SELECT p.*, b.barangay_name, u.username, u.email as user_email,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age
    FROM patients p 
    LEFT JOIN barangays b ON p.barangay_id = b.id
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
");
$patientStmt->execute([$patient_id]);
$patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    echo json_encode(['success' => false, 'message' => 'Patient not found']);
    exit;
}

// Get consultations
$consultStmt = $pdo->prepare("
    SELECT c.id, c.consultation_date, c.consultation_type, c.chief_complaint, 
           c.diagnosis, c.treatment_plan, c.status,
           CONCAT(s.first_name, ' ', s.last_name) as doctor_name
    FROM consultations c
    LEFT JOIN staff s ON c.assigned_doctor = s.id
    WHERE c.patient_id = ?
    ORDER BY c.consultation_date DESC
    LIMIT 10
");
$consultStmt->execute([$patient_id]);
$consultations = $consultStmt->fetchAll(PDO::FETCH_ASSOC);

// Get appointments
$aptStmt = $pdo->prepare("
    SELECT a.appointment_date, a.appointment_time, a.status, a.reason_for_visit,
           st.service_name, a.appointment_location,
           CONCAT(s.first_name, ' ', s.last_name) as doctor_name
    FROM appointments a
    LEFT JOIN service_types st ON a.service_type_id = st.id
    LEFT JOIN staff s ON a.assigned_staff = s.id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT 10
");
$aptStmt->execute([$patient_id]);
$appointments = $aptStmt->fetchAll(PDO::FETCH_ASSOC);

// Get lab results
$labStmt = $pdo->prepare("
    SELECT l.id, l.test_date, l.test_type, l.test_category, l.interpretation, l.status,
           CONCAT(s.first_name, ' ', s.last_name) as performed_by_name
    FROM laboratory_results l
    LEFT JOIN staff s ON l.performed_by = s.id
    WHERE l.patient_id = ?
    ORDER BY l.test_date DESC
    LIMIT 10
");
$labStmt->execute([$patient_id]);
$lab_results = $labStmt->fetchAll(PDO::FETCH_ASSOC);

// Get prescriptions
$prescStmt = $pdo->prepare("
    SELECT prescription_date, medication_name, dosage_strength, 
           dosage_instructions, status, quantity_prescribed,
           CONCAT(s.first_name, ' ', s.last_name) as prescribed_by_name
    FROM prescriptions p
    LEFT JOIN staff s ON p.prescribed_by = s.id
    WHERE p.patient_id = ?
    ORDER BY p.prescription_date DESC
    LIMIT 10
");
$prescStmt->execute([$patient_id]);
$prescriptions = $prescStmt->fetchAll(PDO::FETCH_ASSOC);

// Get medical certificates with medical findings
$certStmt = $pdo->prepare("
    SELECT mc.id, mc.certificate_number, mc.certificate_type, mc.purpose, 
           mc.date_issued, mc.fitness_status, mc.status, mc.valid_until,
           mc.impressions, mc.recommendations, mc.remarks, mc.diagnosis, mc.restrictions,
           CONCAT(s.first_name, ' ', s.last_name) as issued_by_name
    FROM medical_certificates mc
    LEFT JOIN staff s ON mc.issued_by = s.id
    WHERE mc.patient_id = ?
    ORDER BY mc.date_issued DESC
    LIMIT 10
");
$certStmt->execute([$patient_id]);
$certificates = $certStmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM consultations WHERE patient_id = ? AND status = 'completed') as total_consultations,
        (SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status IN ('confirmed', 'completed')) as total_appointments,
        (SELECT COUNT(*) FROM laboratory_results WHERE patient_id = ? AND status = 'completed') as total_lab_tests,
        (SELECT COUNT(*) FROM prescriptions WHERE patient_id = ?) as total_prescriptions,
        (SELECT COUNT(*) FROM medical_certificates WHERE patient_id = ? AND status IN ('ready_for_download', 'downloaded', 'completed_checkup')) as total_certificates
");
$statsStmt->execute([$patient_id, $patient_id, $patient_id, $patient_id, $patient_id]);
$statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'patient' => $patient,
    'consultations' => $consultations,
    'appointments' => $appointments,
    'lab_results' => $lab_results,
    'prescriptions' => $prescriptions,
    'certificates' => $certificates,
    'statistics' => $statistics
]);