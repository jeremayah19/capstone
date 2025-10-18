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

// Get available service types
$servicesStmt = $pdo->prepare("
    SELECT * FROM service_types 
    WHERE is_active = 1 
    ORDER BY service_category, service_name
");
$servicesStmt->execute();
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get barangays for BHS appointments
$barangaysStmt = $pdo->prepare("
    SELECT * FROM barangays 
    WHERE is_active = 1 
    ORDER BY barangay_name
");
$barangaysStmt->execute();
$barangays = $barangaysStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle appointment booking
if (isset($_POST['action']) && $_POST['action'] == 'book_appointment') {
    $service_type_id = $_POST['service_type_id'];
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $appointment_location = $_POST['appointment_location'];
    $barangay_id = !empty($_POST['barangay_id']) ? $_POST['barangay_id'] : null;
    $reason_for_visit = $_POST['reason_for_visit'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    try {
        // Block RHU appointments - they should walk-in
        if ($appointment_location === 'RHU') {
            echo json_encode([
                'success' => false, 
                'message' => 'RHU services do not require appointments. Please visit the Rural Health Unit directly during operating hours (Monday-Friday, 8AM-5PM).'
            ]);
            exit;
        }
        
        // Validate BHS must have barangay
        if ($appointment_location === 'BHS' && empty($barangay_id)) {
            echo json_encode([
                'success' => false, 
                'message' => 'Please select a barangay for BHS appointment.'
            ]);
            exit;
        }
        
        // Validate appointment date (must be future date)
        if (strtotime($appointment_date) < strtotime('today')) {
            echo json_encode(['success' => false, 'message' => 'Appointment date must be today or in the future.']);
            exit;
        }

        // Check if patient already has an appointment on this date
        $checkDateStmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments 
            WHERE patient_id = ? 
            AND appointment_date = ? 
            AND status NOT IN ('cancelled', 'no-show', 'completed')
        ");
        $checkDateStmt->execute([$patient['id'], $appointment_date]);
        $existingOnDate = $checkDateStmt->fetchColumn();
        
        if ($existingOnDate > 0) {
            echo json_encode(['success' => false, 'message' => 'You already have an appointment scheduled on this date. Only one appointment per day is allowed.']);
            exit;
        }
        
        // Check if appointment slot is available
        // Skip time slot validation if time is placeholder (admin will assign time later)
        if ($appointment_time !== '00:00:00') {
            // Check if appointment slot is available
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM appointments 
                WHERE appointment_date = ? AND appointment_time = ? 
                AND appointment_location = ? AND status NOT IN ('cancelled', 'no-show')
            ");
            $checkStmt->execute([$appointment_date, $appointment_time, $appointment_location]);
            $existingCount = $checkStmt->fetchColumn();
            
            // Get max appointments per time slot (configurable)
            $maxAppointments = 3;
            if ($existingCount >= $maxAppointments) {
                echo json_encode(['success' => false, 'message' => 'This time slot is fully booked. Please choose another time.']);
                exit;
            }
        }
        
        // Insert appointment
        $stmt = $pdo->prepare("
            INSERT INTO appointments (
                patient_id, service_type_id, appointment_date, appointment_time, 
                appointment_location, barangay_id, reason_for_visit, notes, 
                status, created_by
            ) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $patient['id'], $service_type_id, $appointment_date, $appointment_time,
            $appointment_location, $barangay_id, $reason_for_visit, $notes,
            $_SESSION['user_id']
        ]);
        
        $appointmentId = $pdo->lastInsertId();
        
        // Log the appointment booking
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'APPOINTMENT_BOOKED', 'Appointments', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $appointmentId,
            json_encode(['date' => $appointment_date, 'time' => $appointment_time, 'location' => $appointment_location])
        ]);
        
        // Create notification for patient
        $notificationStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data) 
            VALUES (?, 'appointment_reminder', 'Appointment Booked', ?, ?)
        ");
        $notificationStmt->execute([
            $_SESSION['user_id'],
            'Your appointment request has been submitted for ' . date('F j, Y', strtotime($appointment_date)) . '. Status: Pending Admin Approval. You will be notified once the time slot is confirmed.',
            json_encode(['appointment_id' => $appointmentId])
        ]);
        
        echo json_encode([
        'success' => true, 
        'message' => 'Appointment booked successfully! Appointment ID: APT-' . str_pad($appointmentId, 6, '0', STR_PAD_LEFT) . '. Your appointment is pending admin approval. You will receive a notification once confirmed.',
        'appointment_id' => $appointmentId
    ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
}

// Handle appointment cancellation
if (isset($_POST['action']) && $_POST['action'] == 'cancel_appointment') {
    $appointment_id = $_POST['appointment_id'];
    $cancellation_reason = $_POST['cancellation_reason'] ?? 'Patient requested cancellation';
    
    try {
        // Check if appointment belongs to patient and can be cancelled
        $checkStmt = $pdo->prepare("
            SELECT * FROM appointments 
            WHERE id = ? AND patient_id = ? AND status IN ('pending', 'confirmed', 'rescheduled')
            AND appointment_date >= CURDATE()
        ");
        $checkStmt->execute([$appointment_id, $patient['id']]);
        $appointment = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            echo json_encode(['success' => false, 'message' => 'Appointment cannot be cancelled.']);
            exit;
        }
        
        // Update appointment status
        $stmt = $pdo->prepare("
            UPDATE appointments 
            SET status = 'cancelled', cancelled_reason = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$cancellation_reason, $appointment_id]);
        
        // Log the cancellation
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'APPOINTMENT_CANCELLED', 'Appointments', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $appointment_id,
            json_encode(['reason' => $cancellation_reason])
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Appointment cancelled successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error cancelling appointment. Please try again.']);
    }
    exit;
}

// Get appointments separated by status
$appointmentsStmt = $pdo->prepare("
    SELECT a.*, st.service_name, st.service_category,
           CONCAT(s.first_name, ' ', s.last_name) as doctor_name,
           b.barangay_name
    FROM appointments a 
    LEFT JOIN service_types st ON a.service_type_id = st.id
    LEFT JOIN staff s ON a.assigned_staff = s.id
    LEFT JOIN barangays b ON a.barangay_id = b.id
    WHERE a.patient_id = ? 
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$appointmentsStmt->execute([$patient['id']]);
$allAppointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Separate appointments into upcoming and past
$upcomingAppointments = [];
$pastAppointments = [];
$today = date('Y-m-d');

foreach ($allAppointments as $appointment) {
    if ($appointment['status'] == 'completed' || $appointment['status'] == 'cancelled' || $appointment['status'] == 'no-show') {
        $pastAppointments[] = $appointment;
    } else if ($appointment['appointment_date'] >= $today) {
        $upcomingAppointments[] = $appointment;
    } else {
        // Past appointments that weren't marked as completed
        $pastAppointments[] = $appointment;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - Kawit RHU</title>
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
            margin-bottom: 1.5rem;
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

        .btn-book {
            background: var(--kawit-gradient);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-book:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 122, 154, 0.4);
            color: white;
        }

        .service-icon {
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

        .service-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .service-category {
            color: var(--dark-pink);
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .service-details {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .service-fee {
            color: var(--dark-pink);
            font-weight: 600;
            margin-top: 0.5rem;
        }

        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 2rem;
        }

        .tab-btn {
            flex: 1;
            background: none;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            background: var(--kawit-gradient);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .appointment-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            border-left: 4px solid var(--kawit-pink);
        }

        .appointment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Grid layout for past appointments */
        .appointments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .appointments-grid .appointment-card {
            margin-bottom: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .appointment-content {
            flex: 1;
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .appointments-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .appointments-grid {
                grid-template-columns: 1fr;
            }
        }

        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .appointment-date {
            color: var(--dark-pink);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .appointment-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-confirmed { background-color: #d1ecf1; color: #0c5460; }
        .status-completed { background-color: #d4edda; color: #155724; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        .status-rescheduled { background-color: #e2e3e5; color: #383d41; }
        .status-no-show { background-color: #f8d7da; color: #721c24; }

        .appointment-content {
            margin-bottom: 1rem;
        }

        .appointment-label {
            color: #6c757d;
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .appointment-value {
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 0.8rem;
        }

        .appointment-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-cancel {
            background: #dc3545;
            border: none;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #c82333;
            color: white;
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

        /* Form Styles */
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

        /* Enhanced Dropdown Styling */
        .form-select {
            border-radius: 10px;
            border: 2px solid #ddd;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: white;
            position: relative;
            z-index: 1;
        }

        .form-select:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.3rem rgba(255, 166, 190, 0.25);
            outline: none;
        }

        .form-select:hover {
            border-color: var(--dark-pink);
        }

        /* Dropdown option groups styling */
        .form-select optgroup {
            font-weight: 700;
            color: var(--dark-pink);
            font-size: 0.95rem;
            padding: 8px 0;
            background-color: var(--light-pink);
        }

        .form-select option {
            padding: 10px 15px;
            font-size: 0.95rem;
            color: var(--text-dark);
        }

        .form-select option:disabled {
            color: var(--dark-pink);
            font-weight: 600;
            background-color: #f8f9fa;
            font-style: italic;
        }

        .form-select option:hover {
            background-color: var(--light-pink);
        }

        /* Service details card */
        #serviceDetailsCard {
            position: relative;
            z-index: 0;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
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

            .service-grid {
                grid-template-columns: 1fr;
            }

            .appointment-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .tab-navigation {
                flex-direction: column;
            }

            .appointment-actions {
                flex-direction: column;
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
                    <a href="Appointment.php" class="nav-link active">
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
                <h1 class="page-title">Appointments</h1>
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
                
            <!-- Book Appointment Section -->
            <div class="content-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <h3 class="section-title">Book Appointment</h3>
                </div>
                
                <!-- RHU Walk-in Information -->
                <div class="alert alert-info" style="border-left: 4px solid #0dcaf0; border-radius: 10px; margin-bottom: 1.5rem;">
                    <div class="d-flex align-items-start">
                        <div style="font-size: 2rem; margin-right: 1rem; color: #0dcaf0;">
                            <i class="fas fa-hospital"></i>
                        </div>
                        <div>
                            <h5 style="color: #055160; margin-bottom: 0.5rem;">
                                <i class="fas fa-info-circle me-2"></i>RHU Services - Walk-in Basis
                            </h5>
                            <p style="margin-bottom: 0.5rem; color: #055160;">
                                <strong>No appointment needed for RHU services!</strong> You can visit the Rural Health Unit directly during operating hours for:
                            </p>
                            <ul style="margin-bottom: 0.5rem; color: #055160;">
                                <li>General Consultations</li>
                                <li>Dental Services</li>
                                <li>Laboratory Tests</li>
                                <li>Vaccinations</li>
                                <li>And other RHU services</li>
                            </ul>
                            <p style="margin-bottom: 0; color: #055160;">
                                <strong>Operating Hours:</strong> Monday to Friday, 8:00 AM - 5:00 PM
                            </p>
                        </div>
                    </div>
                </div>

                <!-- BHS Appointment Information -->
                <div class="alert alert-primary" style="border-left: 4px solid var(--kawit-pink); border-radius: 10px; margin-bottom: 1.5rem;">
                    <div class="d-flex align-items-start">
                        <div style="font-size: 2rem; margin-right: 1rem; color: var(--dark-pink);">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <h5 style="color: var(--dark-pink); margin-bottom: 0.5rem;">
                                <i class="fas fa-calendar-plus me-2"></i>BHS Services - Appointment Required
                            </h5>
                            <p style="margin-bottom: 0; color: var(--text-dark);">
                                <strong>Barangay Health Station services require appointments</strong> to ensure proper scheduling and continuity of care, especially for:
                            </p>
                            <ul style="margin-bottom: 0; color: var(--text-dark);">
                                <li>Maternal Care & Prenatal Check-ups</li>
                                <li>Family Planning Services</li>
                                <li>Immunization Schedules</li>
                                <li>Follow-up Consultations</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <p style="color: #6c757d; margin-bottom: 1.5rem; font-weight: 600;">
                    <i class="fas fa-hand-point-down me-2"></i>Select a BHS service below to schedule your appointment
                </p>
                <div class="row" style="position: relative; z-index: 10;">
                    <div class="col-md-8 mb-3">
                        <label class="form-label" style="font-weight: 600; color: var(--text-dark);">Select Service *</label>
                            <select class="form-select" id="serviceSelector" style="padding: 12px; font-size: 1rem; position: relative; z-index: 1;">
                            <option value="">-- Choose a BHS service to book --</option>
                            
                            <optgroup label="BHS - Barangay Health Station Services (Appointment Required)">
                                <?php 
                                // Group maternal services
                                $maternalServices = [];
                                $otherBHSServices = [];
                                
                                foreach ($services as $service) {
                                    if ($service['available_at'] == 'BHS') {
                                        if ($service['service_category'] == 'Maternal') {
                                            $maternalServices[] = $service;
                                        } else {
                                            $otherBHSServices[] = $service;
                                        }
                                    }
                                }
                                ?>
                                
                                <?php if (!empty($maternalServices)): ?>
                                    <option disabled style="font-weight: bold; color: #000;">── Maternal Care</option>
                                    <?php foreach ($maternalServices as $service): ?>
                                        <option value="<?php echo $service['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($service['service_name']); ?>"
                                                data-category="<?php echo htmlspecialchars($service['service_category']); ?>"
                                                data-description="<?php echo htmlspecialchars($service['description'] ?? ''); ?>"
                                                data-available-at="<?php echo $service['available_at']; ?>">
                                            &nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($service['service_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <?php if (!empty($otherBHSServices)): ?>
                                    <option disabled style="font-weight: bold; color: #000;">── Other Services</option>
                                    <?php foreach ($otherBHSServices as $service): ?>
                                        <option value="<?php echo $service['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($service['service_name']); ?>"
                                                data-category="<?php echo htmlspecialchars($service['service_category']); ?>"
                                                data-description="<?php echo htmlspecialchars($service['description'] ?? ''); ?>"
                                                data-available-at="<?php echo $service['available_at']; ?>">
                                            &nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($service['service_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <!-- Service Details Card (Hidden by default) -->
                <div id="serviceDetailsCard" style="display: none; margin-top: 1.5rem;">
                    <div class="card" style="border-radius: 15px; border: 2px solid var(--kawit-pink); box-shadow: 0 5px 15px rgba(255, 166, 190, 0.2);">
                        <div class="card-body" style="padding: 2rem;">
                            <div class="row align-items-center">
                                <div class="col-md-2 text-center">
                                    <div class="service-icon" id="detailIcon">
                                        <i class="fas fa-stethoscope"></i>
                                    </div>
                                </div>
                                <div class="col-md-10">
                                    <h4 style="color: var(--dark-pink); font-weight: 700; margin-bottom: 0.5rem;" id="detailName">Service Name</h4>
                                    <p style="color: #6c757d; margin-bottom: 1rem;" id="detailCategoryBadge"></p>
                                    
                                    <p id="detailDescription" style="color: var(--text-dark); margin-bottom: 1rem;"></p>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <strong style="color: var(--text-dark);"><i class="fas fa-hospital me-2"></i>Service Location:</strong>
                                            <p id="detailLocation" style="margin-bottom: 0.5rem; font-size: 1.1rem; color: var(--dark-pink); font-weight: 600;">--</p>
                                        </div>
                                    </div>                                   
                                    <div class="text-end mt-3">
                                        <button class="btn-book" id="proceedBooking">
                                            <i class="fas fa-calendar-check me-2"></i>Book This Service
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- My Appointments Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <h3 class="section-title">My Appointments</h3>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <div class="tab-navigation">
                        <button class="tab-btn active" data-tab="upcoming">
                            <i class="fas fa-clock me-2"></i>Upcoming Appointments (<?php echo count($upcomingAppointments); ?>)
                        </button>
                        <button class="tab-btn" data-tab="past">
                            <i class="fas fa-history me-2"></i>Past Appointments (<?php echo count($pastAppointments); ?>)
                        </button>
                    </div>
                    
                    <!-- Upcoming Appointments Tab -->
                    <div class="tab-content active" id="upcoming">
                        <?php if (!empty($upcomingAppointments)): ?>
                            <div class="appointments-grid">
                            <?php foreach ($upcomingAppointments as $appointment): ?>
                                <div class="appointment-card">
                                    <div class="appointment-header">
                                        <div class="appointment-date">
                                            <?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?> - 
                                            <?php 
                                            if ($appointment['appointment_time'] == '00:00:00') {
                                                echo '<span style="color: #856404; font-style: italic;">Time: To be scheduled by admin</span>';
                                            } else {
                                                echo date('g:i A', strtotime($appointment['appointment_time']));
                                            }
                                            ?>
                                        </div>
                                        <span class="appointment-status status-<?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </div>
                                    <div class="appointment-content">
                                        <div class="appointment-label">Service</div>
                                        <div class="appointment-value">
                                            <?php echo htmlspecialchars($appointment['service_name']); ?>
                                            <?php if ($appointment['service_category']): ?>
                                                <span class="badge bg-secondary ms-2"><?php echo $appointment['service_category']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="appointment-label">Location</div>
                                        <div class="appointment-value">
                                            <?php echo htmlspecialchars($appointment['appointment_location']); ?>
                                            <?php if ($appointment['barangay_name']): ?>
                                                - <?php echo htmlspecialchars($appointment['barangay_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        
                                        <?php if ($appointment['doctor_name']): ?>
                                            <div class="appointment-label">Assigned Doctor</div>
                                            <div class="appointment-value">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($appointment['reason_for_visit']): ?>
                                            <div class="appointment-label">Reason for Visit</div>
                                            <div class="appointment-value"><?php echo htmlspecialchars($appointment['reason_for_visit']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($appointment['notes']): ?>
                                            <div class="appointment-label">Notes</div>
                                            <div class="appointment-value"><?php echo nl2br(htmlspecialchars($appointment['notes'])); ?></div>
                                        <?php endif; ?>
                                        <?php if ($appointment['status'] == 'pending'): ?>
                                        <div class="alert alert-warning" style="margin-top: 1rem; padding: 0.75rem; border-radius: 8px;">
                                            <i class="fas fa-clock me-2"></i>
                                            <strong>Pending Approval:</strong> Your appointment is awaiting confirmation. Our staff will review and assign a specific time slot within 24 hours.
                                        </div>
                                    <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($appointment['status'] == 'pending' || $appointment['status'] == 'confirmed' || $appointment['status'] == 'rescheduled'): ?>
                                        <div class="appointment-actions">
                                        <button class="btn-cancel" onclick="cancelAppointment(<?php echo $appointment['id']; ?>)">
                                            <i class="fas fa-times me-1"></i>Cancel Appointment
                                        </button>
                                        <small class="text-muted ms-2">Need to reschedule? Cancel this appointment and book a new one.</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-calendar-times"></i>
                                        <h5>No Upcoming Appointments</h5>
                                        <p>You don't have any BHS appointments scheduled.</p>
                                        <div style="margin-top: 1rem;">
                                            <button class="btn btn-primary" onclick="document.getElementById('serviceSelector').focus()" style="background: var(--kawit-gradient); border: none; border-radius: 10px; margin-bottom: 0.5rem;">
                                                <i class="fas fa-calendar-plus me-2"></i>Book BHS Appointment
                                            </button>
                                            <p class="text-muted" style="font-size: 0.9rem; margin-top: 1rem;">
                                                <i class="fas fa-info-circle me-2"></i>Need RHU services? Walk-in directly during operating hours!
                                            </p>
                                        </div>
                                    </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Past Appointments Tab -->
                    <div class="tab-content" id="past">
                        <?php if (!empty($pastAppointments)): ?>
                            <div class="appointments-grid">
                            <?php foreach ($pastAppointments as $appointment): ?>
                                <div class="appointment-card">
                                    <div class="appointment-header">
                                        <div class="appointment-date">
                                            <?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?> - 
                                            <?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?>
                                        </div>
                                        <span class="appointment-status status-<?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </div>
                                    <div class="appointment-content">
                                        <div class="appointment-label">Service</div>
                                        <div class="appointment-value">
                                            <?php echo htmlspecialchars($appointment['service_name']); ?>
                                            <?php if ($appointment['service_category']): ?>
                                                <span class="badge bg-secondary ms-2"><?php echo $appointment['service_category']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="appointment-label">Location</div>
                                        <div class="appointment-value">
                                            <?php echo htmlspecialchars($appointment['appointment_location']); ?>
                                            <?php if ($appointment['barangay_name']): ?>
                                                - <?php echo htmlspecialchars($appointment['barangay_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($appointment['doctor_name']): ?>
                                            <div class="appointment-label">Attended By</div>
                                            <div class="appointment-value">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($appointment['reason_for_visit']): ?>
                                            <div class="appointment-label">Reason for Visit</div>
                                            <div class="appointment-value"><?php echo htmlspecialchars($appointment['reason_for_visit']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($appointment['cancelled_reason'] && $appointment['status'] == 'cancelled'): ?>
                                            <div class="appointment-label">Cancellation Reason</div>
                                            <div class="appointment-value"><?php echo htmlspecialchars($appointment['cancelled_reason']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-check"></i>
                                <h5>No Past Appointments</h5>
                                <p>Your appointment history will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
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

        // Service card selection
        // Service dropdown selection
        let selectedService = null;
        let selectedServiceData = null;

        // Icon mapping
        const iconMap = {
            'General': 'fas fa-stethoscope',
            'Maternal': 'fas fa-baby-carriage',
            'Child Care': 'fas fa-child',
            'Family Planning': 'fas fa-users',
            'Dental': 'fas fa-tooth',
            'Laboratory': 'fas fa-flask',
            'Vaccination': 'fas fa-syringe',
            'Emergency': 'fas fa-ambulance'
        };

        document.getElementById('serviceSelector').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (this.value === '') {
                // Hide details card if nothing selected
                document.getElementById('serviceDetailsCard').style.display = 'none';
                selectedService = null;
                selectedServiceData = null;
                return;
            }
            
            // Get service data from selected option
            selectedService = this.value;
            selectedServiceData = {
                id: this.value,
                name: selectedOption.dataset.name,
                category: selectedOption.dataset.category,
                description: selectedOption.dataset.description,
                duration: selectedOption.dataset.duration,
                fee: selectedOption.dataset.fee,
                availableAt: selectedOption.dataset.availableAt
            };
            
            // Update details card
            const icon = iconMap[selectedServiceData.category] || 'fas fa-medkit';
            document.getElementById('detailIcon').innerHTML = '<i class="' + icon + '"></i>';
            document.getElementById('detailName').textContent = selectedServiceData.name;
            document.getElementById('detailCategoryBadge').innerHTML = '<span class="badge" style="background: var(--light-pink); color: var(--dark-pink);">' + selectedServiceData.category + '</span>';
            document.getElementById('detailDescription').textContent = selectedServiceData.description || 'Professional healthcare service provided by our medical staff at the Barangay Health Station.';

            // Show location - BHS only now
            document.getElementById('detailLocation').innerHTML = '<i class="fas fa-map-marker-alt me-2"></i>Barangay Health Station (BHS) - <span style="color: #0c5460; font-weight: 500;">Appointment Required</span>';
                                    
            // Show details card
            document.getElementById('serviceDetailsCard').style.display = 'block';
            
            // Scroll to details card
            document.getElementById('serviceDetailsCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        // Proceed to booking
        document.getElementById('proceedBooking').addEventListener('click', function() {
            if (selectedService && selectedServiceData) {
                showBookingModal(selectedServiceData);
            }
        });

        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                document.getElementById(tabName).classList.add('active');
            });
        });

        // Function to show booking modal
        function showBookingModal(serviceData) {
            // Create modal HTML
            const modalHTML = `
                <div class="modal fade" id="bookingModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content" style="border-radius: 15px; border: none;">
                            <div class="modal-header" style="background: var(--kawit-gradient); color: white; border-radius: 15px 15px 0 0;">
                                <h5 class="modal-title">
                                    <i class="fas fa-calendar-plus me-2"></i>Book Appointment - ${serviceData.name}
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="bookingForm">
                                    <div class="mb-3">
                                        <label class="form-label">Selected Service</label>
                                        <input type="text" class="form-control" value="${serviceData.name}" readonly style="background-color: #f8f9fa;">
                                    </div>                                  
                                    <div class="mb-3">
                                        <label class="form-label">Preferred Date *</label>
                                        <input type="date" class="form-control" id="appointment_date" required min="${new Date(new Date().getTime() + 24*60*60*1000).toISOString().split('T')[0]}">
                                        <small class="text-muted">Select your preferred date. Our staff will schedule the specific time for you.</small>
                                    </div>

                                    <input type="hidden" id="appointment_location" value="${serviceData.availableAt}">
                                    ${serviceData.availableAt === 'BHS' ? `
                                        <div class="mb-3">
                                            <label class="form-label">Select Barangay Health Station *</label>
                                            <select class="form-select" id="barangay_id" required>
                                                <option value="">Choose your barangay</option>
                                                <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo $barangay['id']; ?>"><?php echo htmlspecialchars($barangay['barangay_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    ` : '<input type="hidden" id="barangay_id" value="">'}                                 
                                    <div class="mb-3">
                                        <label class="form-label">Reason for Visit</label>
                                        <input type="text" class="form-control" id="reason_for_visit" placeholder="Brief description of your concern or symptoms">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Additional Notes (Optional)</label>
                                        <textarea class="form-control" id="notes" rows="3" placeholder="Any specific concerns, allergies, or special requirements..."></textarea>
                                    </div>
                                    
                                    <div class="alert alert-info" style="border-radius: 10px;">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Important Reminders:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li><strong>This is for Barangay Health Station (BHS) services only</strong></li>
                                            <li><strong>Only one appointment per day is allowed</strong></li>
                                            <li>Your appointment will be reviewed and confirmed within 24 hours</li>
                                            <li>Our staff will assign a specific time slot</li>
                                            <li>You will receive a notification with the confirmed schedule</li>
                                            <li>Please arrive 15 minutes early at your selected barangay health station</li>
                                            <li><strong>For RHU services, please walk-in directly (no appointment needed)</strong></li>
                                        </ul>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="submitBooking" style="background: var(--kawit-gradient); border: none;">
                                    Book Appointment
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('bookingModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('bookingModal'));
            modal.show();
            
            // Handle form submission
            document.getElementById('submitBooking').addEventListener('click', function() {
                const appointmentDate = document.getElementById('appointment_date').value;
                const appointmentLocation = document.getElementById('appointment_location').value;
                const barangayId = document.getElementById('barangay_id').value;
                const reasonForVisit = document.getElementById('reason_for_visit').value;
                const notes = document.getElementById('notes').value;

                if (!appointmentDate) {
                    alert('Please select a date for your appointment.');
                    return;
                }

                if (appointmentLocation === 'BHS' && !barangayId) {
                    alert('Please select a barangay for BHS appointment.');
                    return;
                }

                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Booking...';

                const formData = new FormData();
                formData.append('action', 'book_appointment');
                formData.append('service_type_id', serviceData.id);
                formData.append('appointment_date', appointmentDate);
                formData.append('appointment_time', '00:00:00'); // Placeholder - admin will assign
                formData.append('appointment_location', appointmentLocation);
                formData.append('barangay_id', barangayId);
                formData.append('reason_for_visit', reasonForVisit);
                formData.append('notes', notes);

                fetch('Appointment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        modal.hide();
                        location.reload();
                    } else {
                        alert(data.message || 'Error booking appointment');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = 'Book Appointment';
                });
            });
        }

        // Cancel appointment function
        function cancelAppointment(appointmentId) {
            const reason = prompt('Please provide a reason for cancellation (optional):');
            if (reason === null) return; // User clicked cancel
            
            const formData = new FormData();
            formData.append('action', 'cancel_appointment');
            formData.append('appointment_id', appointmentId);
            formData.append('cancellation_reason', reason || 'Patient requested cancellation');

            fetch('Appointment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error cancelling appointment');
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