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

// Get staff information
$stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as staff_name FROM staff WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    echo json_encode(['success' => false, 'message' => 'Staff not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_certificate') {
    try {
        $certificate_id = intval($_POST['certificate_id']);
        $patient_id = intval($_POST['patient_id']);
        
        // Patient Information (for certificate display)
        $patient_full_name = $_POST['patient_full_name'];
        $patient_age = intval($_POST['patient_age']);
        $patient_gender = $_POST['patient_gender'];
        $patient_address = $_POST['patient_address'];
        $examination_date = $_POST['examination_date'];
        
        // Vital Signs
        $temperature = $_POST['temperature'] ?: null;
        $bp_systolic = $_POST['bp_systolic'] ?: null;
        $bp_diastolic = $_POST['bp_diastolic'] ?: null;
        $heart_rate = $_POST['heart_rate'] ?: null;
        $respiratory_rate = $_POST['respiratory_rate'] ?: null;
        $oxygen_saturation = $_POST['oxygen_saturation'] ?: null;
        $weight = $_POST['weight'] ?: null;
        $height = $_POST['height'] ?: null;
        $bmi = $_POST['bmi'] ?: null;
        
        // Physical Examination
        $general_appearance = $_POST['general_appearance'] ?: null;
        $heent_exam = $_POST['heent_exam'] ?: null;
        $respiratory_exam = $_POST['respiratory_exam'] ?: null;
        $cardiovascular_exam = $_POST['cardiovascular_exam'] ?: null;
        $abdomen_exam = $_POST['abdomen_exam'] ?: null;
        $musculoskeletal_exam = $_POST['musculoskeletal_exam'] ?: null;
        
        // Assessment (for health records)
        $chief_complaint = $_POST['chief_complaint'];
        $diagnosis = $_POST['diagnosis'];
        $fitness_status = $_POST['fitness_status'];
        $restrictions = $_POST['restrictions'] ?: null;
        
        // Certificate Content (what appears on printed certificate)
        $impressions = $_POST['impressions'];
        $recommendations = $_POST['recommendations'];
        $remarks = $_POST['remarks'] ?: null;
        
        // Certificate Validity
        $valid_from = $_POST['valid_from'];
        $valid_until = $_POST['valid_until'];
        
        // Validate required fields
        if (!$patient_full_name || !$patient_age || !$patient_gender || !$patient_address || !$examination_date) {
            throw new Exception("Please fill in all patient information fields");
        }
        
        if (!$chief_complaint || !$diagnosis || !$fitness_status || !$impressions || !$recommendations) {
            throw new Exception("Chief complaint, diagnosis, fitness status, impressions, and recommendations are required");
        }
        
        if (!$valid_from || !$valid_until) {
            throw new Exception("Certificate validity dates are required");
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Check certificate status
        $checkStmt = $pdo->prepare("SELECT status, certificate_number FROM medical_certificates WHERE id = ?");
        $checkStmt->execute([$certificate_id]);
        $certData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$certData) {
            throw new Exception("Certificate not found");
        }

        if ($certData['status'] != 'completed_checkup') {
            throw new Exception("Certificate must be in 'completed_checkup' status to be issued. Current status: " . $certData['status']);
        }

        // Generate unique consultation number
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE consultation_number LIKE ?");
        $stmt->execute(["CONS-$year-%"]);
        $count = $stmt->fetchColumn();
        $consultation_number = 'CONS-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

        // STEP 1: Create consultation record (health record) with examination details
        $consultationStmt = $pdo->prepare("
            INSERT INTO consultations (
                patient_id,
                consultation_number,
                consultation_date,
                consultation_type,
                chief_complaint,
                temperature,
                blood_pressure_systolic,
                blood_pressure_diastolic,
                heart_rate,
                respiratory_rate,
                oxygen_saturation,
                weight,
                height,
                bmi,
                general_appearance,
                heent_exam,
                respiratory_exam,
                cardiovascular_exam,
                abdomen_exam,
                musculoskeletal_exam,
                diagnosis,
                assessment_notes,
                fitness_status,
                recommendations,
                restrictions,
                assigned_doctor,
                status
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?
            )
        ");
        
        $consultationStmt->execute([
            $patient_id,
            $consultation_number,
            $examination_date,
            'Medical Certificate Examination',
            $chief_complaint,
            $temperature,
            $bp_systolic,
            $bp_diastolic,
            $heart_rate,
            $respiratory_rate,
            $oxygen_saturation,
            $weight,
            $height,
            $bmi,
            $general_appearance,
            $heent_exam,
            $respiratory_exam,
            $cardiovascular_exam,
            $abdomen_exam,
            $musculoskeletal_exam,
            $diagnosis,
            'Medical certificate examination completed',
            $fitness_status,
            $recommendations,
            $restrictions,
            $staff['id'],
            'completed'
        ]);
        
        $consultation_id = $pdo->lastInsertId();

        // STEP 2: Update medical certificate with consultation link and details
        $certStmt = $pdo->prepare("
            UPDATE medical_certificates SET
                consultation_id = ?,
                issued_by = ?,
                patient_full_name_override = ?,
                patient_age_override = ?,
                patient_gender_override = ?,
                patient_address_override = ?,
                examination_date = ?,
                valid_from = ?,
                valid_until = ?,
                diagnosis = ?,
                physical_findings = ?,
                impressions = ?,
                recommendations = ?,
                remarks = ?,
                restrictions = ?,
                fitness_status = ?,
                status = 'ready_for_download',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        // Combine physical findings for certificate
        $physical_findings = [];
        if ($general_appearance) $physical_findings[] = "General: $general_appearance";
        if ($heent_exam) $physical_findings[] = "HEENT: $heent_exam";
        if ($respiratory_exam) $physical_findings[] = "Respiratory: $respiratory_exam";
        if ($cardiovascular_exam) $physical_findings[] = "Cardiovascular: $cardiovascular_exam";
        if ($abdomen_exam) $physical_findings[] = "Abdomen: $abdomen_exam";
        if ($musculoskeletal_exam) $physical_findings[] = "Musculoskeletal: $musculoskeletal_exam";
        
        $physical_findings_text = !empty($physical_findings) ? implode("\n", $physical_findings) : null;

        $certStmt->execute([
            $consultation_id,
            $staff['id'],
            $patient_full_name,
            $patient_age,
            $patient_gender,
            $patient_address,
            $examination_date,
            $valid_from,
            $valid_until,
            $diagnosis,
            $physical_findings_text,
            $impressions,
            $recommendations,
            $remarks,
            $restrictions,
            $fitness_status,
            $certificate_id
        ]);
        
        // STEP 3: Get patient info for notification
        $patientStmt = $pdo->prepare("
            SELECT user_id, CONCAT(first_name, ' ', last_name) as patient_name
            FROM patients
            WHERE id = ?
        ");
        $patientStmt->execute([$patient_id]);
        $patientInfo = $patientStmt->fetch(PDO::FETCH_ASSOC);
        
        // STEP 4: Create notification for patient
        if ($patientInfo && $patientInfo['user_id']) {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, priority)
                VALUES (?, 'system', 'Medical Certificate Ready for Download', ?, ?, 'high')
            ");
            $notifStmt->execute([
                $patientInfo['user_id'],
                'Your medical certificate (' . $certData['certificate_number'] . ') has been approved and is now ready for download. You can download it from your Medical Certificates page.',
                json_encode([
                    'certificate_id' => $certificate_id, 
                    'certificate_number' => $certData['certificate_number'],
                    'consultation_id' => $consultation_id
                ])
            ]);
        }
        
        // STEP 5: Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values)
            VALUES (?, 'CERTIFICATE_ISSUED', 'Medical Certificates', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            $certificate_id,
            json_encode([
                'certificate_number' => $certData['certificate_number'],
                'consultation_id' => $consultation_id,
                'fitness_status' => $fitness_status,
                'patient' => $patientInfo['patient_name'],
                'issued_by' => $staff['staff_name']
            ])
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Certificate issued successfully! Health record created and patient notified.',
            'consultation_id' => $consultation_id
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>