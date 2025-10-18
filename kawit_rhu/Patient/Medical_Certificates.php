<?php
session_start();

// Check if user is logged in and is a patient
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header('Location: ../login.php');
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
    die("Connection failed: " . $e->getMessage());
}

// Get patient information
$stmt = $pdo->prepare("
    SELECT p.*, u.username, b.barangay_name,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age
    FROM patients p 
    JOIN users u ON p.user_id = u.id 
    LEFT JOIN barangays b ON p.barangay_id = b.id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    header('Location: ../login.php');
    exit;
}

// Handle certificate request
if (isset($_POST['action']) && $_POST['action'] == 'request_certificate') {
    $certificate_type = $_POST['certificate_type'];
    $purpose = $_POST['purpose'];
    $other_purpose = $_POST['other_purpose'] ?? '';
    
    $final_purpose = ($purpose === 'Other') ? $other_purpose : $purpose;
    
    // Generate certificate number
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM medical_certificates WHERE certificate_number LIKE ?");
    $stmt->execute(["CERT-$year-%"]);
    $count = $stmt->fetchColumn();
    $cert_number = 'CERT-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    
try {
        // Start transaction
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO medical_certificates (certificate_number, patient_id, certificate_type, purpose, date_issued, status) 
            VALUES (?, ?, ?, ?, CURDATE(), 'pending')
        ");
        $stmt->execute([$cert_number, $patient['id'], $certificate_type, $final_purpose]);
        
        $certificate_id = $pdo->lastInsertId();
        
        // Create notification for admin (ONLY if notifications table exists)
        try {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, priority) 
                VALUES (
                    (SELECT user_id FROM staff WHERE department = 'RHU' AND position LIKE '%admin%' LIMIT 1),
                    'system',
                    'New Medical Certificate Request',
                    ?,
                    ?,
                    'medium'
                )
            ");
            $notifStmt->execute([
                'New certificate request from ' . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']),
                json_encode(['certificate_id' => $certificate_id, 'certificate_number' => $cert_number])
            ]);
        } catch (Exception $e) {
            // Skip notification if table doesn't exist
            error_log('Notification error: ' . $e->getMessage());
        }
        
        // Log the request (ONLY if system_logs table exists)
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
                VALUES (?, 'CERTIFICATE_REQUEST_SUBMITTED', 'Medical Certificates', ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['user_id'], 
                $certificate_id,
                json_encode(['certificate_type' => $certificate_type, 'purpose' => $final_purpose, 'certificate_number' => $cert_number])
            ]);
        } catch (Exception $e) {
            // Skip log if table doesn't exist
            error_log('System log error: ' . $e->getMessage());
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Certificate request submitted successfully! Certificate Number: ' . $cert_number . '. Please wait for admin to verify and schedule your check-up appointment.'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Certificate request error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle mark certificate as downloaded
if (isset($_POST['action']) && $_POST['action'] == 'mark_downloaded') {
    $certificate_id = $_POST['certificate_id'] ?? '';
    
    if (!$certificate_id) {
        echo json_encode(['success' => false, 'message' => 'Certificate ID required']);
        exit;
    }
    
    try {
        // Verify certificate belongs to this patient
        $checkStmt = $pdo->prepare("
            SELECT id, status FROM medical_certificates 
            WHERE id = ? AND patient_id = ?
        ");
        $checkStmt->execute([$certificate_id, $patient['id']]);
        $cert = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cert) {
            echo json_encode(['success' => false, 'message' => 'Certificate not found']);
            exit;
        }
        
        // Only update if status is ready_for_download
        if ($cert['status'] == 'ready_for_download') {
            $updateStmt = $pdo->prepare("
                UPDATE medical_certificates 
                SET status = 'downloaded', updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$certificate_id]);
            
            // Log the download
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id)
                VALUES (?, 'CERTIFICATE_DOWNLOADED', 'Medical Certificates', ?)
            ");
            $logStmt->execute([$_SESSION['user_id'], $certificate_id]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Download tracked']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error tracking download']);
    }
    exit;
}


$certificatesStmt = $pdo->prepare("
    SELECT mc.*, 
           CONCAT(s.first_name, ' ', s.last_name) as issued_by_name,
           CONCAT(s2.first_name, ' ', s2.last_name) as assigned_doctor_name,
           c.consultation_number, a.appointment_date, a.appointment_time
    FROM medical_certificates mc 
    LEFT JOIN staff s ON mc.issued_by = s.id
    LEFT JOIN staff s2 ON mc.assigned_doctor_id = s2.id
    LEFT JOIN consultations c ON mc.consultation_id = c.id
    LEFT JOIN appointments a ON mc.id = a.reference_id AND a.reference_type = 'medical_certificate' AND a.status NOT IN ('cancelled', 'no-show')
    WHERE mc.patient_id = ? 
    ORDER BY 
        CASE mc.status 
            WHEN 'pending' THEN 1 
            WHEN 'approved_for_checkup' THEN 2
            WHEN 'completed_checkup' THEN 3
            WHEN 'ready_for_download' THEN 4 
            WHEN 'downloaded' THEN 5
            ELSE 6
        END,
        mc.date_issued DESC
");
$certificatesStmt->execute([$patient['id']]);
$certificates = $certificatesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Certificates - Kawit RHU</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --kawit-pink: #FFA6BE;
            --light-pink: #FFE4E6;
            --dark-pink: #FF7A9A;
            --text-dark: #2c3e50;
            --kawit-gradient: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%);
            --light-bg: #f8f9fc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }

        .main-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--kawit-gradient);
            color: white;
            padding: 0;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo-container {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 1rem;
        }

        .logo-container img {
            max-width: 50px;
            height: auto;
        }

        .logo-circle {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }

        .logo-text {
            font-weight: 700;
            font-size: 1.3rem;
            line-height: 1.2;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.5rem 1rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 15px;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(5px);
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .nav-link i {
            width: 24px;
            margin-right: 12px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .top-navbar {
            background: white;
            padding: 1.5rem 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--kawit-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 15px;
            font-size: 1.2rem;
        }

        .user-details {
            text-align: left;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 1.1rem;
            margin-bottom: 2px;
        }

        .user-role {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* Dashboard Content */
        .dashboard-content {
            padding: 2rem;
            flex: 1;
        }

        .content-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border-left: 5px solid var(--kawit-pink);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .section-header-left {
            display: flex;
            align-items: center;
        }

        .section-icon {
            width: 50px;
            height: 50px;
            background: var(--light-pink);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--dark-pink);
            font-size: 1.3rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }

        .btn-request {
            background: var(--kawit-gradient);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-request:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 122, 154, 0.4);
            color: white;
        }

        .certificate-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .certificate-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            border-left: 4px solid var(--kawit-pink);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .certificate-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .certificate-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .certificate-number {
            color: var(--dark-pink);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .certificate-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-approved_for_checkup { background-color: #d1ecf1; color: #0c5460; }
        .status-completed_checkup { background-color: #cce5ff; color: #004085; }
        .status-ready_for_download { background-color: #d4edda; color: #155724; }
        .status-downloaded { background-color: #e2e3e5; color: #383d41; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        .status-expired { background-color: #f8d7da; color: #721c24; }

        .certificate-content {
            margin-bottom: 1rem;
        }

        .certificate-label {
            color: #6c757d;
            font-weight: 500;
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
        }

        .certificate-value {
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .certificate-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-download {
            background: var(--kawit-gradient);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-download:hover {
            color: white;
            transform: translateY(-1px);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 15px;
            border: none;
        }

        .modal-header {
            background: var(--kawit-gradient);
            color: white;
            border-radius: 15px 15px 0 0;
        }

        .form-control {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
        }

        .form-control:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
        }

        .form-select {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
        }

        .form-select:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
        }

        /* Info Section */
        .info-section {
            background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .info-item {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .info-icon {
            width: 60px;
            height: 60px;
            background: var(--kawit-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .certificate-grid {
                grid-template-columns: 1fr;
            }
            
            .main-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
            }
            
            .sidebar-nav {
                display: flex;
                overflow-x: auto;
                padding: 1rem;
            }
            
            .nav-item {
                margin: 0 0.5rem;
                min-width: 140px;
            }
            
            .nav-link {
                white-space: nowrap;
                justify-content: center;
                flex-direction: column;
                padding: 1rem;
                text-align: center;
            }
            
            .nav-link i {
                margin: 0 0 0.5rem 0;
            }
            
            .dashboard-content {
                padding: 1rem;
            }
            
            .top-navbar {
                padding: 1rem 1.5rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .certificate-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .certificate-actions {
                flex-direction: column;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .content-section {
                padding: 1.5rem;
            }
            
            .section-title {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">

        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <img src="../Pictures/logo2.png" alt="Logo 1" onerror="this.style.display='none'">
                    <img src="../Pictures/logo1.png" alt="Logo 2" onerror="this.style.display='none'">
                    <img src="../Pictures/logo3.png" alt="Logo 3" onerror="this.style.display='none'">
                </div>
                <div class="logo-circle">
                    <i class="fas fa-heartbeat"></i>
                </div>
                <div class="logo-text">
                    KAWIT<br>
                    <small style="font-size: 0.8rem; opacity: 0.8;">RHU</small>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-item">
                    <a href="Dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Health_Record.php" class="nav-link">
                        <i class="fas fa-file-medical"></i>
                        <span>Health Records</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Medical_Certificates.php" class="nav-link active">
                        <i class="fas fa-certificate"></i>
                        <span>Medical Certificate</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Appointment.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Appointment</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Consultation.php" class="nav-link">
                        <i class="fas fa-video"></i>
                        <span>Online Consultation</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="../logout.php" class="nav-link" onclick="return confirm('Are you sure you want to log out?')">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Log out</span>
                    </a>
                </div>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation -->
            <nav class="top-navbar">
                <h1 class="page-title">Medical Certificates</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
                        echo $initials;
                        ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($patient['first_name'] . ' ' . substr($patient['last_name'], 0, 1) . '.'); ?></div>
                        <div class="user-role">Patient ID: <?php echo htmlspecialchars($patient['patient_id']); ?></div>
                    </div>
                    <!-- User Menu Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-link text-dark p-0 ms-2" type="button" data-bs-toggle="dropdown" style="text-decoration: none;">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
                            <li>
                                <a class="dropdown-item" href="Profile.php" style="border-radius: 8px; margin: 2px;">
                                    <i class="fas fa-user-edit me-2" style="color: var(--kawit-pink);"></i>Edit Profile
                                </a>
                            </li>
                            <li><hr class="dropdown-divider" style="margin: 8px 0;"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="../logout.php" onclick="return confirm('Are you sure you want to log out?')" style="border-radius: 8px; margin: 2px;">
                                    <i class="fas fa-sign-out-alt me-2"></i>Log Out
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Information Section -->
                <div class="info-section">
                    <h3 style="color: var(--text-dark); margin-bottom: 1rem;">
                        <i class="fas fa-certificate me-2"></i>Medical Certificate Services
                    </h3>
                    <p style="color: #6c757d; margin-bottom: 0;">Request official medical certificates for employment, school, travel, and other purposes.</p>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-file-medical"></i>
                            </div>
                            <h5>1. Submit Request</h5>
                            <p class="text-muted mb-0">Complete the certificate request form with your specific needs.</p>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-user-md"></i>
                            </div>
                            <h5>2. Medical Review</h5>
                            <p class="text-muted mb-0">Our medical staff will review and process your request.</p>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-certificate"></i>
                            </div>
                            <h5>3. Certificate Issued</h5>
                            <p class="text-muted mb-0">Download your official digital certificate or collect at RHU.</p>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-qrcode"></i>
                            </div>
                            <h5>4. Verification</h5>
                            <p class="text-muted mb-0">QR code verification ensures authenticity of your certificate.</p>
                        </div>
                    </div>
                </div>

                <!-- Medical Certificates Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-header-left">
                            <div class="section-icon">
                                <i class="fas fa-certificate"></i>
                            </div>
                            <h3 class="section-title">My Medical Certificates</h3>
                        </div>
                        <button class="btn-request" data-bs-toggle="modal" data-bs-target="#requestModal">
                            <i class="fas fa-plus me-2"></i>Request Certificate
                        </button>
                    </div>
                    
                    <?php if (!empty($certificates)): ?>
                        <div class="certificate-grid">
                        <?php foreach ($certificates as $certificate): 
                            // Get consultation details if linked
                            $consultation = null;
                            if ($certificate['consultation_id']) {
                                $consultStmt = $pdo->prepare("
                                    SELECT c.*, CONCAT(s.first_name, ' ', s.last_name) as doctor_name
                                    FROM consultations c
                                    LEFT JOIN staff s ON c.assigned_doctor = s.id
                                    WHERE c.id = ?
                                ");
                                $consultStmt->execute([$certificate['consultation_id']]);
                                $consultation = $consultStmt->fetch(PDO::FETCH_ASSOC);
                            }
                        ?>
                            <div class="certificate-card">
                                <div class="certificate-header">
                                    <div class="certificate-number"><?php echo htmlspecialchars($certificate['certificate_number']); ?></div>
                                    <span class="certificate-status status-<?php echo str_replace('_', '-', $certificate['status']); ?>">
                                        <?php 
                                        $status_labels = [
                                            'pending' => 'Pending',
                                            'approved_for_checkup' => 'Scheduled',
                                            'completed_checkup' => 'Checked',
                                            'ready_for_download' => 'Ready',
                                            'downloaded' => 'Downloaded',
                                            'cancelled' => 'Cancelled',
                                            'expired' => 'Expired'
                                        ];
                                        echo $status_labels[$certificate['status']] ?? ucfirst($certificate['status']);
                                        ?>
                                    </span>
                                </div>
                                <div class="certificate-content">
                                    <div class="certificate-label">Certificate Type</div>
                                    <div class="certificate-value"><?php echo htmlspecialchars($certificate['certificate_type']); ?></div>
                                    
                                    <div class="certificate-label">Purpose</div>
                                    <div class="certificate-value"><?php echo htmlspecialchars($certificate['purpose']); ?></div>
                                    
                                    <?php if ($certificate['status'] == 'approved_for_checkup' && $certificate['appointment_date']): ?>
                                        <div class="certificate-label">Check-up Schedule</div>
                                        <div class="certificate-value">
                                            <?php echo date('M j, Y', strtotime($certificate['appointment_date'])); ?>
                                            <?php if ($certificate['appointment_time'] && $certificate['appointment_time'] != '00:00:00'): ?>
                                                at <?php echo date('g:i A', strtotime($certificate['appointment_time'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (($certificate['status'] == 'ready_for_download' || $certificate['status'] == 'downloaded') && $certificate['valid_from'] && $certificate['valid_until']): ?>
                                        <div class="certificate-label">Valid Period</div>
                                        <div class="certificate-value">
                                            <?php echo date('M j, Y', strtotime($certificate['valid_from'])); ?> - 
                                            <?php echo date('M j, Y', strtotime($certificate['valid_until'])); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($certificate['status'] == 'ready_for_download' || $certificate['status'] == 'downloaded'): ?>
                                        <?php if ($certificate['diagnosis']): ?>
                                            <div class="certificate-label">Impressions</div>
                                            <div class="certificate-value"><?php echo htmlspecialchars($certificate['diagnosis']); ?></div>
                                        <?php endif; ?>

                                        <?php if ($certificate['fitness_status']): ?>
                                            <div class="certificate-label">Fitness Status</div>
                                            <div class="certificate-value">
                                                <span style="background: #28a745; color: white; padding: 0.3rem 0.8rem; border-radius: 15px; font-weight: 600; font-size: 0.9rem;">
                                                    <?php echo htmlspecialchars($certificate['fitness_status']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="certificate-actions">
                                    <?php if ($certificate['status'] == 'pending'): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-hourglass-half me-1"></i>
                                            Waiting for admin verification
                                        </small>
                                    <?php elseif ($certificate['status'] == 'approved_for_checkup'): ?>
                                        <?php if ($consultation): ?>
                                            <button onclick="viewExaminationDetails(<?php echo $certificate['id']; ?>)" 
                                                    style="flex: 1; background: #f8f9fa; color: #495057; border: 1px solid #dee2e6; padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem;">
                                                <i class="fas fa-info-circle me-1"></i>Details
                                            </button>
                                        <?php else: ?>
                                            <small class="text-info">
                                                <i class="fas fa-calendar-check me-1"></i>
                                                Check-up scheduled - Please visit RHU
                                            </small>
                                        <?php endif; ?>
                                    <?php elseif ($certificate['status'] == 'completed_checkup'): ?>
                                        <?php if ($consultation): ?>
                                            <button onclick="viewExaminationDetails(<?php echo $certificate['id']; ?>)" 
                                                    style="flex: 1; background: #f8f9fa; color: #495057; border: 1px solid #dee2e6; padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem;">
                                                <i class="fas fa-info-circle me-1"></i>Details
                                            </button>
                                        <?php endif; ?>
                                        <small class="text-muted d-block mt-2">
                                            <i class="fas fa-clipboard-check me-1"></i>
                                            Certificate being prepared
                                        </small>
                                    <?php elseif ($certificate['status'] == 'ready_for_download' || $certificate['status'] == 'downloaded'): ?>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <?php if ($consultation): ?>
                                                <button onclick="viewExaminationDetails(<?php echo $certificate['id']; ?>)" 
                                                        style="flex: 1; background: #f8f9fa; color: #495057; border: 1px solid #dee2e6; padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem;">
                                                    <i class="fas fa-info-circle me-1"></i>Details
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn-download" onclick="downloadCertificate(<?php echo $certificate['id']; ?>)" style="flex: 1;">
                                                <i class="fas fa-download me-1"></i>Download
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Hidden data for modal -->
                                <?php if ($consultation): ?>
                                    <div id="exam-data-<?php echo $certificate['id']; ?>" style="display: none;">
                                        <?php echo json_encode([
                                            'certificate_number' => $certificate['certificate_number'],
                                            'certificate_type' => $certificate['certificate_type'],
                                            'purpose' => $certificate['purpose'],
                                            'date_issued' => $certificate['date_issued'],
                                            'valid_from' => $certificate['valid_from'],
                                            'valid_until' => $certificate['valid_until'],
                                            'examination_date' => $consultation['consultation_date'] ?? $certificate['examination_date'],
                                            'doctor_name' => $consultation['doctor_name'],
                                            'temperature' => $consultation['temperature'],
                                            'bp_systolic' => $consultation['blood_pressure_systolic'],
                                            'bp_diastolic' => $consultation['blood_pressure_diastolic'],
                                            'heart_rate' => $consultation['heart_rate'],
                                            'respiratory_rate' => $consultation['respiratory_rate'],
                                            'oxygen_saturation' => $consultation['oxygen_saturation'],
                                            'weight' => $consultation['weight'],
                                            'height' => $consultation['height'],
                                            'bmi' => $consultation['bmi'],
                                            'general_appearance' => $consultation['general_appearance'],
                                            'heent_exam' => $consultation['heent_exam'],
                                            'respiratory_exam' => $consultation['respiratory_exam'],
                                            'cardiovascular_exam' => $consultation['cardiovascular_exam'],
                                            'abdomen_exam' => $consultation['abdomen_exam'],
                                            'musculoskeletal_exam' => $consultation['musculoskeletal_exam'],
                                            'chief_complaint' => $consultation['chief_complaint'],
                                            'diagnosis' => $consultation['diagnosis'],
                                            'fitness_status' => $consultation['fitness_status'],
                                            'restrictions' => $consultation['restrictions'],
                                            'recommendations' => $consultation['recommendations'],
                                            'impressions' => $certificate['impressions'],
                                            'remarks' => $certificate['remarks']
                                        ]); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="empty-state">
                            <i class="fas fa-certificate"></i>
                            <h5>No Medical Certificates</h5>
                            <p>You haven't requested any medical certificates yet.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal" style="background: var(--kawit-gradient); border: none; border-radius: 10px; margin-top: 1rem;">
                                Request Your First Certificate
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Request Certificate Modal -->
    <div class="modal fade" id="requestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-certificate me-2"></i>Request Medical Certificate
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="requestForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Certificate Type *</label>
                                <select class="form-select" id="certificate_type" required>
                                    <option value="">Select certificate type</option>
                                    <option value="Medical Certificate">General Medical Certificate</option>
                                    <option value="Fit to Work Certificate">Fit to Work Certificate</option>
                                    <option value="Health Certificate">Health Certificate</option>
                                    <option value="Vaccination Certificate">Vaccination Certificate</option>
                                    <option value="Medical Clearance">Medical Clearance</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Purpose *</label>
                                <select class="form-select" id="purpose" required>
                                    <option value="">Select purpose</option>
                                    <option value="Employment">Employment</option>
                                    <option value="School Requirements">School Requirements</option>
                                    <option value="Travel">Travel Requirements</option>
                                    <option value="Insurance">Insurance Claim</option>
                                    <option value="Government Requirements">Government Requirements</option>
                                    <option value="Visa Application">Visa Application</option>
                                    <option value="License Application">License Application</option>
                                    <option value="Other">Other (Please specify)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3" id="otherPurposeDiv" style="display: none;">
                            <label class="form-label">Please specify purpose *</label>
                            <input type="text" class="form-control" id="other_purpose" placeholder="Enter specific purpose">
                        </div>
                        
                        <div class="alert alert-info" style="border-radius: 10px;">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Processing Information:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Certificate requests are processed within 1-2 business days</li>
                                <li>You will receive a notification when your certificate is ready</li>
                                <li>Medical examination may be required for certain certificate types</li>
                                <li>Valid ID is required for certificate pickup</li>
                                <li>Digital certificates include QR code verification</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning" style="border-radius: 10px;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important Notes:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Ensure all personal information in your profile is accurate</li>
                                <li>Some certificates may require a recent consultation</li>
                                <li>Fees may apply for certain certificate types</li>
                                <li>Processing may take longer during peak periods</li>
                            </ul>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitRequest" style="background: var(--kawit-gradient); border: none;">
                        Submit Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Examination Details Modal -->
    <div class="modal fade" id="examinationDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-stethoscope me-2"></i>Medical Examination Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div id="examinationDetailsContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Check session on page load
        window.onload = function() {
            <?php if (!isset($_SESSION['user_id'])): ?>
                window.location.href = '../login.php';
            <?php endif; ?>
        };

        // Show/hide other purpose field
        document.getElementById('purpose').addEventListener('change', function() {
            const otherDiv = document.getElementById('otherPurposeDiv');
            const otherInput = document.getElementById('other_purpose');
            if (this.value === 'Other') {
                otherDiv.style.display = 'block';
                otherInput.required = true;
            } else {
                otherDiv.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        });

        // Submit certificate request
        document.getElementById('submitRequest').addEventListener('click', function() {
            const form = document.getElementById('requestForm');
            const certificateType = document.getElementById('certificate_type').value;
            const purpose = document.getElementById('purpose').value;
            const otherPurpose = document.getElementById('other_purpose').value;

            if (!certificateType || !purpose) {
                alert('Please fill in all required fields.');
                return;
            }

            if (purpose === 'Other' && !otherPurpose.trim()) {
                alert('Please specify the purpose.');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';

            const formData = new FormData();
            formData.append('action', 'request_certificate');
            formData.append('certificate_type', certificateType);
            formData.append('purpose', purpose);
            formData.append('other_purpose', otherPurpose);

            fetch('Medical_Certificates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error submitting request');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = 'Submit Request';
            });
        });

        // Certificate action functions
 
        function downloadCertificate(certificateId) {
            if (!certificateId) {
                console.error('Certificate ID is empty or undefined!');
                return false;
            }
            
            // Mark as downloaded first
            const formData = new FormData();
            formData.append('action', 'mark_downloaded');
            formData.append('certificate_id', certificateId);
            
            fetch('Medical_Certificates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Open PDF regardless of tracking result
                window.open('../generate_certificate_pdf.php?id=' + certificateId, '_blank');
                
                // Reload page to show updated status
                if (data.success) {
                    setTimeout(() => location.reload(), 1000);
                }
            })
            .catch(error => {
                console.error('Error tracking download:', error);
                // Still open PDF even if tracking fails
                window.open('../generate_certificate_pdf.php?id=' + certificateId, '_blank');
            });
            
            return false;
        }

        // View Examination Details Function
        function viewExaminationDetails(certId) {
            const dataElement = document.getElementById('exam-data-' + certId);
            if (!dataElement) {
                alert('Examination details not found');
                return;
            }

            const data = JSON.parse(dataElement.textContent);
            
            let html = `
                <div class="alert alert-info">
                    <strong>Certificate:</strong> ${data.certificate_number}<br>
                    <strong>Examination Date:</strong> ${data.examination_date ? new Date(data.examination_date).toLocaleString('en-US', { 
                        year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' 
                    }) : 'N/A'}
                </div>
            `;

            if (data.temperature || data.bp_systolic || data.heart_rate) {
                html += `<h6 class="text-primary mb-3"><i class="fas fa-heartbeat me-2"></i>Vital Signs</h6><div class="vital-signs mb-3"><div class="vital-signs-grid">`;
                if (data.temperature) html += `<div class="vital-item"><div class="vital-label">Temperature</div><div class="vital-value">${data.temperature}°C</div></div>`;
                if (data.bp_systolic && data.bp_diastolic) html += `<div class="vital-item"><div class="vital-label">Blood Pressure</div><div class="vital-value">${data.bp_systolic}/${data.bp_diastolic}</div></div>`;
                if (data.heart_rate) html += `<div class="vital-item"><div class="vital-label">Heart Rate</div><div class="vital-value">${data.heart_rate} bpm</div></div>`;
                if (data.respiratory_rate) html += `<div class="vital-item"><div class="vital-label">Respiratory Rate</div><div class="vital-value">${data.respiratory_rate}/min</div></div>`;
                if (data.oxygen_saturation) html += `<div class="vital-item"><div class="vital-label">O₂ Saturation</div><div class="vital-value">${data.oxygen_saturation}%</div></div>`;
                if (data.weight) html += `<div class="vital-item"><div class="vital-label">Weight</div><div class="vital-value">${data.weight} kg</div></div>`;
                if (data.height) html += `<div class="vital-item"><div class="vital-label">Height</div><div class="vital-value">${data.height} cm</div></div>`;
                if (data.bmi) html += `<div class="vital-item"><div class="vital-label">BMI</div><div class="vital-value">${data.bmi}</div></div>`;
                html += `</div></div>`;
            }

            if (data.general_appearance || data.heent_exam || data.respiratory_exam || data.cardiovascular_exam || data.abdomen_exam || data.musculoskeletal_exam) {
                html += `<h6 class="text-primary mb-3"><i class="fas fa-user-md me-2"></i>Physical Examination Findings</h6><div style="background: #f8f9fa; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">`;
                if (data.general_appearance) html += `<div class="mb-2"><strong>General Appearance:</strong> ${data.general_appearance}</div>`;
                if (data.heent_exam) html += `<div class="mb-2"><strong>HEENT:</strong> ${data.heent_exam}</div>`;
                if (data.respiratory_exam) html += `<div class="mb-2"><strong>Respiratory:</strong> ${data.respiratory_exam}</div>`;
                if (data.cardiovascular_exam) html += `<div class="mb-2"><strong>Cardiovascular:</strong> ${data.cardiovascular_exam}</div>`;
                if (data.abdomen_exam) html += `<div class="mb-2"><strong>Abdomen:</strong> ${data.abdomen_exam}</div>`;
                if (data.musculoskeletal_exam) html += `<div class="mb-2"><strong>Musculoskeletal:</strong> ${data.musculoskeletal_exam}</div>`;
                html += `</div>`;
            }

            html += `<h6 class="text-primary mb-3"><i class="fas fa-clipboard-check me-2"></i>Medical Assessment</h6>`;
            if (data.chief_complaint) html += `<div class="mb-2"><strong>Chief Complaint:</strong> ${data.chief_complaint}</div>`;
            if (data.diagnosis) html += `<div class="mb-2"><strong>Medical Impressions:</strong> ${data.diagnosis}</div>`;
            if (data.fitness_status) html += `<div class="mb-2"><strong>Fitness Status:</strong> <span style="background: #28a745; color: white; padding: 0.3rem 0.8rem; border-radius: 15px; font-weight: 600;">${data.fitness_status}</span></div>`;
            if (data.restrictions) html += `<div class="mb-2"><strong>Restrictions:</strong> ${data.restrictions}</div>`;
            if (data.recommendations) html += `<div class="mb-2"><strong>Recommendations:</strong> ${data.recommendations.replace(/\n/g, '<br>')}</div>`;
            if (data.doctor_name) html += `<div class="mt-3 pt-3" style="border-top: 1px solid #dee2e6;"><strong>Examined By:</strong> Dr. ${data.doctor_name}</div>`;

            document.getElementById('examinationDetailsContent').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('examinationDetailsModal'));
            modal.show();
        }

        // Auto-refresh session check every 5 minutes
        setInterval(function() {
            fetch('Medical_Certificates.php')
                .then(response => response.text())
                .then(data => {
                    if (data.includes('Location: ../login.php')) {
                        window.location.href = '../login.php';
                    }
                })
                .catch(error => {
                    console.log('Session check failed:', error);
                });
        }, 300000); // 5 minutes
    </script>
</body>
</html>'