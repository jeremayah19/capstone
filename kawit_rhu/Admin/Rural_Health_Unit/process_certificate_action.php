<?php
session_start();

// Check if user is logged in and is RHU admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rhu_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
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
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get staff information
$stmt = $pdo->prepare("SELECT * FROM staff WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    echo json_encode(['success' => false, 'message' => 'Staff not found']);
    exit;
}

$action = $_POST['action'] ?? '';

// =====================================================
// ACTION 1: APPROVE FOR CHECK-UP
// =====================================================
if ($action == 'approve_for_checkup') {
    $certificate_id = $_POST['certificate_id'] ?? '';
    $checkup_date = $_POST['checkup_date'] ?? '';
    $checkup_time = $_POST['checkup_time'] ?? '09:00:00';
    $checkup_notes = $_POST['checkup_notes'] ?? '';
    $assigned_doctor_id = $_POST['assigned_doctor_id'] ?? '';

    if (!$certificate_id || !$checkup_date) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    if (!$assigned_doctor_id) {
        echo json_encode(['success' => false, 'message' => 'Please assign a doctor']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get certificate details
        $certStmt = $pdo->prepare("
            SELECT mc.*, p.patient_id as patient_code, p.user_id,
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name
            FROM medical_certificates mc
            JOIN patients p ON mc.patient_id = p.id
            WHERE mc.id = ?
        ");
        $certStmt->execute([$certificate_id]);
        $cert = $certStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cert) {
            throw new Exception('Certificate not found');
        }
        
        if ($cert['status'] != 'pending') {
            throw new Exception('Certificate is not in pending status');
        }
        
        // Update certificate status to approved_for_checkup, save instructions and assign doctor
        $updateCertStmt = $pdo->prepare("
            UPDATE medical_certificates 
            SET status = 'approved_for_checkup',
                checkup_instructions = ?,
                assigned_doctor_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateCertStmt->execute([$checkup_notes, $assigned_doctor_id, $certificate_id]);
        
        // Create appointment record for the check-up
        $appointmentStmt = $pdo->prepare("
            INSERT INTO appointments (
                patient_id, appointment_date, appointment_time,
                appointment_location, status, notes, reason_for_visit,
                reference_id, reference_type, created_by
            ) VALUES (?, ?, ?, 'RHU', 'confirmed', ?, 'Medical Certificate Check-up', ?, 'medical_certificate', ?)
        ");
        $appointmentStmt->execute([
            $cert['patient_id'],
            $checkup_date,
            $checkup_time,
            $checkup_notes,
            $certificate_id,
            $_SESSION['user_id']
        ]);
        
        // Create notification for patient
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, priority)
            VALUES (?, 'appointment_reminder', 'Certificate Check-up Scheduled', ?, ?, 'high')
        ");
        $notifMessage = 'Your medical certificate request (' . $cert['certificate_number'] . ') has been approved. Your check-up is scheduled on ' . date('F j, Y', strtotime($checkup_date)) . ' at ' . date('g:i A', strtotime($checkup_time)) . '. Please arrive 10 minutes early.';
        $notifData = json_encode([
            'certificate_id' => $certificate_id,
            'certificate_number' => $cert['certificate_number'],
            'appointment_date' => $checkup_date,
            'appointment_time' => $checkup_time
        ]);
        $notifStmt->execute([$cert['user_id'], $notifMessage, $notifData]);
        
        // Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values)
            VALUES (?, 'CERTIFICATE_APPROVED_FOR_CHECKUP', 'Medical Certificates', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            $certificate_id,
            json_encode([
                'certificate_number' => $cert['certificate_number'],
                'patient_name' => $cert['patient_name'],
                'checkup_date' => $checkup_date,
                'checkup_time' => $checkup_time,
                'approved_by' => $staff['first_name'] . ' ' . $staff['last_name']
            ])
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Certificate approved and check-up scheduled for ' . date('F j, Y', strtotime($checkup_date)) . '. Patient has been notified.'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Approve for checkup error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =====================================================
// ACTION 2: MARK CHECK-UP AS COMPLETED
// =====================================================
if ($action == 'complete_checkup') {
    $certificate_id = $_POST['certificate_id'] ?? '';
    
    if (!$certificate_id) {
        echo json_encode(['success' => false, 'message' => 'Certificate ID is required']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get certificate details
        $certStmt = $pdo->prepare("
            SELECT mc.*, p.patient_id as patient_code, p.user_id,
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name
            FROM medical_certificates mc
            JOIN patients p ON mc.patient_id = p.id
            WHERE mc.id = ?
        ");
        $certStmt->execute([$certificate_id]);
        $cert = $certStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cert) {
            throw new Exception('Certificate not found');
        }
        
        if ($cert['status'] != 'approved_for_checkup') {
            throw new Exception('Certificate is not in approved_for_checkup status');
        }
        
        // Update certificate status to completed_checkup
        $updateCertStmt = $pdo->prepare("
            UPDATE medical_certificates 
            SET status = 'completed_checkup',
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateCertStmt->execute([$certificate_id]);
        
        // Update related appointment to completed
        $updateApptStmt = $pdo->prepare("
            UPDATE appointments 
            SET status = 'completed'
            WHERE reference_id = ? AND reference_type = 'medical_certificate'
        ");
        $updateApptStmt->execute([$certificate_id]);
        
        // Create notification for patient
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, priority)
            VALUES (?, 'system', 'Check-up Completed', ?, ?, 'medium')
        ");
        $notifMessage = 'Your check-up for medical certificate (' . $cert['certificate_number'] . ') has been completed. Your certificate is being prepared by the doctor.';
        $notifData = json_encode([
            'certificate_id' => $certificate_id,
            'certificate_number' => $cert['certificate_number']
        ]);
        $notifStmt->execute([$cert['user_id'], $notifMessage, $notifData]);
        
        // Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values)
            VALUES (?, 'CHECKUP_COMPLETED', 'Medical Certificates', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            $certificate_id,
            json_encode([
                'certificate_number' => $cert['certificate_number'],
                'patient_name' => $cert['patient_name'],
                'completed_by' => $staff['first_name'] . ' ' . $staff['last_name']
            ])
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Check-up marked as completed. Certificate is now ready for processing.'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Complete checkup error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =====================================================
// Invalid action
// =====================================================
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
?>