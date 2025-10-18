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

// Get patient information FIRST (needed for all actions)
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

// Handle cancel consultation request

// Handle cancel consultation request
if (isset($_POST['action']) && $_POST['action'] == 'cancel_consultation') {
    $consultation_id = intval($_POST['consultation_id']);
    
    try {
        // Verify consultation belongs to this patient and is still pending
        $checkStmt = $pdo->prepare("
            SELECT id, consultation_number, status 
            FROM consultations 
            WHERE id = ? AND patient_id = ? AND status = 'pending'
        ");
        $checkStmt->execute([$consultation_id, $patient['id']]);
        $consultation = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$consultation) {
            echo json_encode(['success' => false, 'message' => 'Consultation not found or cannot be cancelled']);
            exit;
        }
        
        // Update consultation status to cancelled
        $stmt = $pdo->prepare("
            UPDATE consultations 
            SET status = 'cancelled' 
            WHERE id = ?
        ");
        $stmt->execute([$consultation_id]);
        
        // Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'CONSULTATION_CANCELLED', 'Consultations', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $consultation_id,
            json_encode(['consultation_number' => $consultation['consultation_number'], 'cancelled_by' => 'patient'])
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Consultation request cancelled successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error cancelling consultation request']);
    }
    exit;
}


// Handle online consultation request
if (isset($_POST['action']) && $_POST['action'] == 'request_consultation') {
    $symptoms = $_POST['symptoms'];
    $history_of_illness = $_POST['history_of_illness'] ?? '';
    $message = $_POST['message'];
    $priority = $_POST['priority'];
    
    try {
        // Generate consultation number
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE consultation_number LIKE ?");
        $stmt->execute(["CONS-$year-%"]);
        $count = $stmt->fetchColumn();
        $consultation_number = 'CONS-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        
        // Insert as PENDING (admin needs to accept first)
        $stmt = $pdo->prepare("
            INSERT INTO consultations (
                consultation_number, patient_id, consultation_type, consultation_date, 
                chief_complaint, history_of_present_illness, symptoms, patient_notes, 
                priority, status, consultation_location
            ) 
            VALUES (?, ?, 'online', NOW(), ?, ?, ?, ?, ?, 'pending', 'RHU')
        ");
        $stmt->execute([
            $consultation_number, $patient['id'], $message, $history_of_illness, 
            $symptoms, $message, $priority
        ]);
                
        $consultationId = $pdo->lastInsertId();
        
        // Log the consultation request
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'CONSULTATION_REQUEST', 'Consultations', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $consultationId,
            json_encode(['consultation_number' => $consultation_number, 'type' => 'online', 'priority' => $priority])
        ]);
        
        // Create notification for patient
        $notificationStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, priority) 
            VALUES (?, 'system', 'Consultation Request Submitted', ?, ?, ?)
        ");
        $notificationStmt->execute([
            $_SESSION['user_id'],
            'Your online consultation request has been submitted. Please wait for admin approval. Consultation number: ' . $consultation_number,
            json_encode(['consultation_id' => $consultationId, 'consultation_number' => $consultation_number]),
            $priority
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Consultation request submitted! Please wait for admin to schedule your appointment. Consultation Number: ' . $consultation_number]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error submitting consultation request. Please try again.']);
    }
    exit;
}

// Get consultations with proper joins
$consultationsStmt = $pdo->prepare("
    SELECT c.*, CONCAT(s.first_name, ' ', s.last_name) as doctor_name,
           b.barangay_name as consultation_location_name
    FROM consultations c 
    LEFT JOIN staff s ON c.assigned_doctor = s.id
    LEFT JOIN barangays b ON c.barangay_id = b.id
    WHERE c.patient_id = ? AND c.consultation_type = 'online'
    ORDER BY c.consultation_date DESC, c.created_at DESC
");
$consultationsStmt->execute([$patient['id']]);
$consultations = $consultationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get available barangays for reference
$barangaysStmt = $pdo->prepare("
    SELECT * FROM barangays 
    WHERE is_active = 1 
    ORDER BY barangay_name
");
$barangaysStmt->execute();
$barangays = $barangaysStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Consultation - Kawit RHU</title>
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
            font-size: 16px;
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

        .consultation-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .consultation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Grid layout for consultations */
        .consultations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .consultations-grid .consultation-card {
            margin-bottom: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .consultation-content {
            flex: 1;
        }

        /* Responsive grid adjustments */
        @media (max-width: 1200px) {
            .consultations-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .consultations-grid {
                grid-template-columns: 1fr;
            }
        }

        .consultation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .consultation-date {
            color: var(--dark-pink);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .consultation-number {
            background: var(--light-pink);
            color: var(--dark-pink);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-block;
            margin-bottom: 0.5rem;
        }

        .consultation-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-in-progress { background-color: #d1ecf1; color: #0c5460; }
        .status-completed { background-color: #d4edda; color: #155724; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }

        .consultation-content {
            margin-bottom: 1rem;
        }

        .consultation-label {
            color: #6c757d;
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .consultation-value {
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 0.8rem;
        }

        .priority-high { border-left: 4px solid #dc3545; }
        .priority-urgent { border-left: 4px solid #dc3545; background: #fff5f5; }
        .priority-medium { border-left: 4px solid #ffc107; }
        .priority-low { border-left: 4px solid #28a745; }

        .gmeet-link {
            background: #34a853;
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .gmeet-link:hover {
            background: #2d8f47;
            color: white;
            transform: translateY(-1px);
        }

        .gmeet-link:disabled {
            background: #6c757d;
            cursor: not-allowed;
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

        /* Information Section */
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

        .scheduled-time-box {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 10px;
            border-left: 4px solid #2196F3;
            margin: 1rem 0;
        }

        .scheduled-time-box i {
            color: #2196F3;
            font-size: 1.5rem;
            margin-right: 10px;
        }

        @media (max-width: 768px) {
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

            .consultation-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
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
                    <a href="Medical_Certificates.php" class="nav-link">
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
                    <a href="Consultation.php" class="nav-link active">
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
                <h1 class="page-title">Online Consultation</h1>
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
                        <i class="fas fa-video me-2"></i>How Online Consultation Works
                    </h3>
                    <p style="color: #6c757d; margin-bottom: 0;">Connect with our healthcare professionals from the comfort of your home through secure video calls.</p>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <h5>1. Submit Request</h5>
                            <p class="text-muted mb-0">Fill out the consultation form with your symptoms and concerns.</p>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <h5>2. Wait for Approval</h5>
                            <p class="text-muted mb-0">Admin will review and schedule your consultation time.</p>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <h5>3. Get Scheduled</h5>
                            <p class="text-muted mb-0">You'll receive your consultation time. Be online 5 minutes early!</p>
                        </div>
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-video"></i>
                            </div>
                            <h5>4. Join Video Call</h5>
                            <p class="text-muted mb-0">Click the Google Meet link when it arrives at your scheduled time.</p>
                        </div>
                    </div>
                </div>

                <!-- Consultation Requests Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-header-left">
                            <div class="section-icon">
                                <i class="fas fa-video"></i>
                            </div>
                            <h3 class="section-title">My Online Consultations</h3>
                        </div>
                        <button class="btn-request" data-bs-toggle="modal" data-bs-target="#consultationModal">
                            <i class="fas fa-plus me-2"></i>Request Consultation
                        </button>
                    </div>
                    
                    <?php if (!empty($consultations)): ?>
                        <div class="consultations-grid">
                        <?php foreach ($consultations as $consultation): ?>
                            <div class="consultation-card priority-<?php echo $consultation['priority']; ?>">
                                <div class="consultation-number"><?php echo htmlspecialchars($consultation['consultation_number']); ?></div>
                                <div class="consultation-header">
                                    <div class="consultation-date">
                                        Requested: <?php echo date('F j, Y g:i A', strtotime($consultation['created_at'])); ?>
                                    </div>
                                    <span class="consultation-status status-<?php echo str_replace('_', '-', $consultation['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $consultation['status'])); ?>
                                    </span>
                                </div>
                                <div class="consultation-content">
                                    <div class="consultation-label">Priority Level</div>
                                    <div class="consultation-value"><?php echo ucfirst($consultation['priority']); ?> Priority</div>
                                    
                                    <?php if ($consultation['chief_complaint']): ?>
                                        <div class="consultation-label">Chief Complaint</div>
                                        <div class="consultation-value"><?php echo htmlspecialchars($consultation['chief_complaint']); ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if ($consultation['symptoms']): ?>
                                        <div class="consultation-label">Symptoms</div>
                                        <div class="consultation-value"><?php echo htmlspecialchars($consultation['symptoms']); ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if ($consultation['history_of_present_illness']): ?>
                                        <div class="consultation-label">History of Present Illness</div>
                                        <div class="consultation-value"><?php echo nl2br(htmlspecialchars($consultation['history_of_present_illness'])); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                        <?php if ($consultation['status'] == 'pending'): ?>
                                    <div class="mt-3">
                                        <div class="alert alert-warning mb-0">
                                            <i class="fas fa-hourglass-half me-2"></i>
                                            <strong>Waiting for Approval:</strong> Your consultation request is pending. Admin will review and schedule your appointment soon.
                                        </div>
                                        <button class="btn btn-danger mt-2" onclick="cancelConsultation(<?php echo $consultation['id']; ?>, '<?php echo htmlspecialchars($consultation['consultation_number']); ?>')" style="width: 100%; border-radius: 10px;">
                                            <i class="fas fa-times-circle me-2"></i>Cancel Consultation Request
                                        </button>
                                    </div>
                                
                                <?php elseif ($consultation['status'] == 'in_progress'): ?>
                                    <div class="mt-3">
                                        <div class="alert alert-success mb-3">
                                            <i class="fas fa-check-circle me-2"></i>
                                            <strong>Consultation Approved!</strong> Your request has been reviewed and scheduled.
                                        </div>
                                        
                                        <?php if ($consultation['assigned_doctor']): ?>
                                            <div class="consultation-label">Assigned Doctor</div>
                                            <div class="consultation-value">
                                                <i class="fas fa-user-md me-2"></i><?php echo htmlspecialchars($consultation['doctor_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($consultation['consultation_date'] && $consultation['consultation_date'] != '0000-00-00 00:00:00'): ?>
                                            <div class="scheduled-time-box">
                                                <i class="fas fa-calendar-check"></i>
                                                <strong>Your Scheduled Time:</strong><br>
                                                <span style="font-size: 1.2rem; color: #2196F3; font-weight: 700;">
                                                    <?php echo date('F j, Y @ g:i A', strtotime($consultation['consultation_date'])); ?>
                                                </span>
                                                <br>
                                                <small class="text-muted">Please be online 5 minutes before your scheduled time.</small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($consultation['google_meet_link'])): ?>
                                            <a href="<?php echo htmlspecialchars($consultation['google_meet_link']); ?>" target="_blank" class="gmeet-link">
                                                <i class="fab fa-google me-2"></i>Join Video Consultation
                                            </a>
                                            <small class="text-success d-block mt-2">
                                                <i class="fas fa-check-circle me-1"></i>
                                                Meeting link is ready! Click above to join the consultation.
                                            </small>
                                        <?php else: ?>
                                            <button class="gmeet-link" disabled>
                                                <i class="fab fa-google me-2"></i>Waiting for Meeting Link
                                            </button>
                                            <small class="text-muted d-block mt-2">
                                                <i class="fas fa-clock me-1"></i>
                                                The doctor will send the Google Meet link at your scheduled time. Stay online!
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                
                                <?php elseif ($consultation['status'] == 'completed'): ?>
                                    <div class="mt-3">
                                        <div class="alert alert-success mb-3">
                                            <i class="fas fa-check-circle me-2"></i>
                                            <strong>Consultation Completed</strong>
                                            <?php if ($consultation['doctor_name']): ?>
                                                <br><small>Attended by: <?php echo htmlspecialchars($consultation['doctor_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($consultation['diagnosis']): ?>
                                            <div class="consultation-label">Diagnosis</div>
                                            <div class="consultation-value"><?php echo htmlspecialchars($consultation['diagnosis']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($consultation['treatment_plan']): ?>
                                            <div class="consultation-label">Treatment Plan</div>
                                            <div class="consultation-value"><?php echo nl2br(htmlspecialchars($consultation['treatment_plan'])); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($consultation['recommendations']): ?>
                                            <div class="consultation-label">Recommendations</div>
                                            <div class="consultation-value"><?php echo nl2br(htmlspecialchars($consultation['recommendations'])); ?></div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex gap-2 mt-3">
                                            <a href="Health_Record.php" class="btn btn-outline-primary" style="border-radius: 10px; flex: 1;">
                                                <i class="fas fa-file-medical me-2"></i>View Full Health Record
                                            </a>
                                            <?php
                                            // Check if there are prescriptions
                                            $rxCheck = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE consultation_id = ?");
                                            $rxCheck->execute([$consultation['id']]);
                                            if ($rxCheck->fetchColumn() > 0):
                                            ?>
                                            <button class="btn btn-outline-success" style="border-radius: 10px; flex: 1;" 
                                                    onclick="viewPrescriptions(<?php echo $consultation['id']; ?>)">
                                                <i class="fas fa-pills me-2"></i>View Prescriptions
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-success d-block mt-2">
                                            <i class="fas fa-check-circle me-1"></i>
                                            Consultation completed. Check your health records for detailed information.
                                        </small>
                                    </div>
                                
                                <?php elseif ($consultation['status'] == 'cancelled'): ?>
                                    <div class="mt-3">
                                        <div class="alert alert-danger mb-0">
                                            <i class="fas fa-times-circle me-2"></i>
                                            <strong>Cancelled:</strong> This consultation has been cancelled.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-video"></i>
                            <h5>No Online Consultations</h5>
                            <p>You haven't requested any online consultations yet.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#consultationModal" style="background: var(--kawit-gradient); border: none; border-radius: 10px; margin-top: 1rem;">
                                Request Your First Consultation
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Request Consultation Modal -->
    <div class="modal fade" id="consultationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-video me-2"></i>Request Online Consultation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="consultationForm">
                        <div class="row">
                        <!-- Priority will be set automatically to medium -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Primary Symptoms *</label>
                                <input type="text" class="form-control" id="symptoms" placeholder="e.g., Fever, headache, cough, stomach pain" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Chief Complaint / Main Concern *</label>
                            <textarea class="form-control" id="message" rows="3" placeholder="Please describe your main health concern or reason for consultation..." required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">History of Present Illness (Optional)</label>
                            <textarea class="form-control" id="history_of_illness" rows="3" placeholder="When did symptoms start? How have they progressed? What makes them better or worse? Any treatments tried?"></textarea>
                        </div>
                        
                        <div class="alert alert-info" style="border-radius: 10px;">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>What happens next:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Your request will be reviewed by our medical staff</li>
                                <li>Admin will schedule a specific time for your consultation</li>
                                <li>You'll receive a notification with your scheduled time</li>
                                <li>Be online 5 minutes before your scheduled time</li>
                                <li>Google Meet link will be sent at your scheduled time</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning" style="border-radius: 10px;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Emergency Situations:</strong><br>
                            For life-threatening emergencies, please call <strong>911</strong> or visit the nearest emergency room immediately. 
                            Do not wait for online consultation for severe chest pain, difficulty breathing, severe bleeding, or loss of consciousness.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitConsultation" style="background: var(--kawit-gradient); border: none;">
                        Submit Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Prescriptions Modal -->
    <div class="modal fade" id="prescriptionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-pills me-2"></i>Prescriptions
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="prescriptionsContent">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                        <p>Loading prescriptions...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Check session on page load
        window.onload = function() {
            <?php if (!isset($_SESSION['user_id'])): ?>
                window.location.href = '../login.php';
            <?php endif; ?>
        };

        // Submit consultation request
        document.getElementById('submitConsultation').addEventListener('click', function() {
            const symptoms = document.getElementById('symptoms').value;
            const message = document.getElementById('message').value;
            const historyOfIllness = document.getElementById('history_of_illness').value;

            if (!symptoms || !message) {
                alert('Please fill in all required fields.');
                return;
            }
            
            // Auto-set priority to medium (admin will review and adjust if needed)
            const priority = 'medium';

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';

            const formData = new FormData();
            formData.append('action', 'request_consultation');
            formData.append('priority', priority);
            formData.append('symptoms', symptoms);
            formData.append('message', message);
            formData.append('history_of_illness', historyOfIllness);

            fetch('Consultation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error submitting consultation request');
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

        // Auto-refresh session check every 5 minutes
        setInterval(function() {
            fetch('Consultation.php')
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

                // Cancel consultation function
        function cancelConsultation(consultationId, consultationNumber) {
            if (!confirm('Are you sure you want to cancel consultation request ' + consultationNumber + '?\n\nThis action cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'cancel_consultation');
            formData.append('consultation_id', consultationId);

            fetch('Consultation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error cancelling consultation');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    </script>
</body>
</html>