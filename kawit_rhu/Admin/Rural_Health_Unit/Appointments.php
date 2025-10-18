<?php
session_start();

// Check if user is logged in and is RHU admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rhu_admin') {
    header('Location: ../../login.php');
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

// Get staff information
$stmt = $pdo->prepare("
    SELECT s.*, u.username, u.last_login 
    FROM staff s 
    JOIN users u ON s.user_id = u.id 
    WHERE u.id = ? AND s.department = 'RHU'
");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    header('Location: ../../login.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_status') {
        try {
            $appointment_id = $_POST['appointment_id'];
            $new_status = $_POST['status'];
            $appointment_time = $_POST['appointment_time'] ?? null;
            
            $pdo->beginTransaction();
            
            // Update appointment status and time if provided
            if ($appointment_time) {
                $stmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?, appointment_time = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$new_status, $appointment_time, $appointment_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$new_status, $appointment_id]);
            }
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
                VALUES (?, 'APPOINTMENT_STATUS_UPDATED', 'Appointments', ?, ?)
            ");
            $logData = ['status' => $new_status, 'updated_by' => $staff['first_name'] . ' ' . $staff['last_name']];
            if ($appointment_time) {
                $logData['appointment_time'] = $appointment_time;
            }
            $logStmt->execute([
                $_SESSION['user_id'],
                $appointment_id,
                json_encode($logData)
            ]);
            
            // Send notification to patient
            $aptStmt = $pdo->prepare("
                SELECT p.user_id, a.appointment_date
                FROM appointments a 
                JOIN patients p ON a.patient_id = p.id 
                WHERE a.id = ?
            ");
            $aptStmt->execute([$appointment_id]);
            $apt = $aptStmt->fetch(PDO::FETCH_ASSOC);

            if ($apt && $apt['user_id']) {
                $message = "Your appointment status has been updated to: " . ucfirst($new_status);
                if ($appointment_time && $new_status === 'confirmed') {
                    $time_formatted = date('g:i A', strtotime($appointment_time));
                    $date_formatted = date('F j, Y', strtotime($apt['appointment_date']));
                    $message = "Your appointment has been confirmed for {$date_formatted} at {$time_formatted}. Please arrive 15 minutes early.";
                }
                
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, priority)
                    VALUES (?, 'appointment_reminder', 'Appointment Confirmed', ?, ?, 'high')
                ");
                $notifStmt->execute([
                    $apt['user_id'],
                    $message,
                    json_encode(['appointment_id' => $appointment_id, 'status' => $new_status, 'time' => $appointment_time])
                ]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Appointment confirmed successfully!']);
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'cancel_appointment') {
        try {
            $appointment_id = $_POST['appointment_id'];
            $reason = $_POST['reason'] ?? 'Cancelled by admin';
            
            $pdo->beginTransaction();
            
            // Update appointment
            $stmt = $pdo->prepare("
                UPDATE appointments 
                SET status = 'cancelled', cancellation_reason = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$reason, $appointment_id]);
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
                VALUES (?, 'APPOINTMENT_CANCELLED', 'Appointments', ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['user_id'],
                $appointment_id,
                json_encode(['reason' => $reason, 'cancelled_by' => $staff['first_name'] . ' ' . $staff['last_name']])
            ]);
            
            // Send notification to patient
            $aptStmt = $pdo->prepare("
                SELECT p.user_id 
                FROM appointments a 
                JOIN patients p ON a.patient_id = p.id 
                WHERE a.id = ?
            ");
            $aptStmt->execute([$appointment_id]);
            $apt = $aptStmt->fetch(PDO::FETCH_ASSOC);

            if ($apt && $apt['user_id']) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, priority)
                    VALUES (?, 'system', 'Appointment Cancelled', ?, ?, 'high')
                ");
                $notifStmt->execute([
                    $apt['user_id'],
                    "Your appointment has been cancelled. Reason: " . $reason,
                    json_encode(['appointment_id' => $appointment_id])
                ]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Appointment cancelled successfully!']);
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'reschedule_appointment') {
    try {
        $appointment_id = $_POST['appointment_id'];
        $new_date = $_POST['new_date'];
        $new_time = $_POST['new_time'];
        $reason = $_POST['reason'] ?? 'Rescheduled by admin';
        
        $pdo->beginTransaction();
        
        // Update appointment
        $stmt = $pdo->prepare("
            UPDATE appointments 
            SET appointment_date = ?, appointment_time = ?, status = 'rescheduled', notes = CONCAT(COALESCE(notes, ''), '\n\nRescheduled: ', ?), updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$new_date, $new_time, $reason, $appointment_id]);
        
        // Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'APPOINTMENT_RESCHEDULED', 'Appointments', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            $appointment_id,
            json_encode(['new_date' => $new_date, 'new_time' => $new_time, 'reason' => $reason, 'rescheduled_by' => $staff['first_name'] . ' ' . $staff['last_name']])
        ]);
        
        // Send notification to patient
        $aptStmt = $pdo->prepare("
            SELECT p.user_id 
            FROM appointments a 
            JOIN patients p ON a.patient_id = p.id 
            WHERE a.id = ?
        ");
        $aptStmt->execute([$appointment_id]);
        $apt = $aptStmt->fetch(PDO::FETCH_ASSOC);

        if ($apt && $apt['user_id']) {
            $date_formatted = date('F j, Y', strtotime($new_date));
            $time_formatted = date('g:i A', strtotime($new_time));
            
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, priority)
                VALUES (?, 'appointment_reminder', 'Appointment Rescheduled', ?, ?, 'high')
            ");
            $notifStmt->execute([
                $apt['user_id'],
                "Your appointment has been rescheduled to {$date_formatted} at {$time_formatted}. " . ($reason ? "Reason: " . $reason : ""),
                json_encode(['appointment_id' => $appointment_id, 'new_date' => $new_date, 'new_time' => $new_time])
            ]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Appointment rescheduled successfully!']);
        
    } catch (Exception $e) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
    
    if ($_POST['action'] === 'get_appointment') {
        try {
            $stmt = $pdo->prepare("
                SELECT a.*, 
                       p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                       p.date_of_birth, p.gender, p.phone, p.email,
                       YEAR(CURDATE()) - YEAR(p.date_of_birth) as age,
                       st.service_name, st.description as service_description,
                       b.barangay_name,
                       CONCAT(s.first_name, ' ', s.last_name) as staff_name
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                LEFT JOIN service_types st ON a.service_type_id = st.id
                LEFT JOIN barangays b ON a.barangay_id = b.id
                LEFT JOIN staff s ON a.assigned_staff = s.id
                WHERE a.id = ?
            ");
            $stmt->execute([$_POST['appointment_id']]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appointment) {
                throw new Exception("Appointment not found");
            }
            
            echo json_encode(['success' => true, 'appointment' => $appointment]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Get parameters
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_status = $_GET['status'] ?? '';
$filter_service = $_GET['service'] ?? '';
$filter_location = $_GET['location'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query
$appointmentsQuery = "
    SELECT a.*, 
           p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           p.phone, YEAR(CURDATE()) - YEAR(p.date_of_birth) as age,
           st.service_name, b.barangay_name,
           CONCAT(s.first_name, ' ', s.last_name) as staff_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    LEFT JOIN service_types st ON a.service_type_id = st.id AND (st.available_at = 'RHU' OR st.available_at IS NULL)
    LEFT JOIN barangays b ON a.barangay_id = b.id
    LEFT JOIN staff s ON a.assigned_staff = s.id
    WHERE a.appointment_date = ?
    AND a.appointment_location = 'RHU'
";
$params = [$filter_date];

if ($filter_status) {
    $appointmentsQuery .= " AND a.status = ?";
    $params[] = $filter_status;
}

if ($filter_service) {
    $appointmentsQuery .= " AND a.service_type_id = ?";
    $params[] = $filter_service;
}

if ($filter_location) {
    $appointmentsQuery .= " AND a.appointment_location = ?";
    $params[] = $filter_location;
}

if ($search) {
    $appointmentsQuery .= " AND (p.patient_id LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Count total
$countQuery = str_replace("SELECT a.*, 
           p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           p.phone, YEAR(CURDATE()) - YEAR(p.date_of_birth) as age,
           st.service_name, b.barangay_name,
           CONCAT(s.first_name, ' ', s.last_name) as staff_name", "SELECT COUNT(*)", $appointmentsQuery);
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$total_appointments = $countStmt->fetchColumn();
$total_pages = ceil($total_appointments / $limit);

$appointmentsQuery .= " ORDER BY a.appointment_time ASC LIMIT " . intval($limit) . " OFFSET " . intval($offset);

$stmt = $pdo->prepare($appointmentsQuery);
$stmt->execute($params);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get service types
$servicesStmt = $pdo->prepare("SELECT * FROM service_types WHERE is_active = 1 AND available_at = 'RHU' ORDER BY service_name");
$servicesStmt->execute();
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$today = date('Y-m-d');
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN a.appointment_date = ? AND a.appointment_location = 'RHU' THEN 1 END) as today_total,
        COUNT(CASE WHEN a.appointment_date = ? AND a.appointment_location = 'RHU' AND a.status = 'pending' THEN 1 END) as today_pending,
        COUNT(CASE WHEN a.appointment_date = ? AND a.appointment_location = 'RHU' AND a.status = 'confirmed' THEN 1 END) as today_confirmed,
        COUNT(CASE WHEN a.appointment_date = ? AND a.appointment_location = 'RHU' AND a.status = 'completed' THEN 1 END) as today_completed,
        COUNT(CASE WHEN a.appointment_date = ? AND a.appointment_location = 'RHU' AND a.status = 'cancelled' THEN 1 END) as today_cancelled
    FROM appointments a
    LEFT JOIN service_types st ON a.service_type_id = st.id
    WHERE st.available_at = 'RHU'
");
$statsStmt->execute([$today, $today, $today, $today, $today]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - RHU Admin</title>
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

        * { box-sizing: border-box; }

        body {
            background: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }

        .main-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: var(--kawit-gradient);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
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

        .sidebar-nav { padding: 1rem 0; }
        .nav-item { margin: 0.5rem 1rem; }

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
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
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
        }

        .dashboard-content {
            padding: 2rem;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-left: 4px solid var(--kawit-pink);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.3rem;
            color: white;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-pink);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .content-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border-left: 5px solid var(--kawit-pink);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 10px;
            color: var(--dark-pink);
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
        }

        .btn-primary {
            background: var(--kawit-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 24px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: var(--dark-pink);
            transform: translateY(-2px);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: var(--light-pink);
            color: var(--text-dark);
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .status-rescheduled { background: #e2e3e5; color: #383d41; }

        .time-slot {
            background: var(--light-pink);
            padding: 8px 12px;
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
            color: var(--dark-pink);
        }

        .modal-header {
            background: var(--kawit-gradient);
            color: white;
            border-radius: 15px 15px 0 0;
        }

        .modal-content {
            border-radius: 15px;
            border: none;
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            border-radius: 8px;
            margin: 0 2px;
            border: 1px solid #dee2e6;
            color: var(--text-dark);
        }

        .page-link:hover {
            background: var(--light-pink);
            border-color: var(--kawit-pink);
        }

        .page-item.active .page-link {
            background: var(--kawit-gradient);
            border-color: var(--kawit-pink);
        }

        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <img src="../../Pictures/logo2.png" alt="Logo 1" onerror="this.style.display='none'">
                    <img src="../../Pictures/logo1.png" alt="Logo 2" onerror="this.style.display='none'">
                    <img src="../../Pictures/logo3.png" alt="Logo 3" onerror="this.style.display='none'">
                </div>
                <div class="logo-circle">
                    <i class="fas fa-hospital"></i>
                </div>
                <div class="logo-text">
                    RHU ADMIN<br>
                    <small style="font-size: 0.8rem; opacity: 0.8;">Kawit RHU</small>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-item">
                    <a href="Dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Patients.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Patients</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Consultation.php" class="nav-link">
                        <i class="fas fa-user-md"></i>
                        <span>Consultations</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Appointments.php" class="nav-link active">
                        <i class="fas fa-calendar-check"></i>
                        <span>Appointments</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Referral.php" class="nav-link">
                        <i class="fas fa-share-alt"></i>
                        <span>Referrals</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Laboratory.php" class="nav-link">
                        <i class="fas fa-flask"></i>
                        <span>Laboratory</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="Medical_Certificates.php" class="nav-link">
                        <i class="fas fa-certificate"></i>
                        <span>Medical Certificates</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="../../logout.php" class="nav-link">
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
                <h1 class="page-title">Appointments Management</h1>
            <div class="user-info">
                <div class="user-avatar">
                    <?php 
                    $initials = strtoupper(substr($staff['first_name'], 0, 1) . substr($staff['last_name'], 0, 1));
                    echo $initials;
                    ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($staff['position']); ?></div>
                </div>
                <!-- User Menu Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-link text-dark p-0 ms-2" type="button" data-bs-toggle="dropdown" style="text-decoration: none;">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
                        <li>
                            <a class="dropdown-item" href="Profile.php" style="border-radius: 8px; margin: 2px;">
                                <i class="fas fa-user-cog me-2" style="color: var(--kawit-pink);"></i>Profile & Settings
                            </a>
                        </li>
                        <li><hr class="dropdown-divider" style="margin: 8px 0;"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="../../logout.php" onclick="return confirm('Are you sure you want to log out?')" style="border-radius: 8px; margin: 2px;">
                                <i class="fas fa-sign-out-alt me-2"></i>Log Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            </nav>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Alert Messages -->
                <div id="alertMessage" style="display: none;"></div>

                <!-- Filters -->
                <div class="content-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-filter"></i>Filter Appointments
                        </h3>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" id="filterDate" value="<?php echo $filter_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="filterStatus">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $filter_status == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="rescheduled" <?php echo $filter_status == 'rescheduled' ? 'selected' : ''; ?>>Rescheduled</option>
                                <option value="no-show" <?php echo $filter_status == 'no-show' ? 'selected' : ''; ?>>No-Show</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Service Type</label>
                            <select class="form-select" id="filterService">
                                <option value="">All Services</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo $service['id']; ?>" <?php echo $filter_service == $service['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($service['service_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <button class="btn btn-primary w-100" onclick="applyFilters()">
                                <i class="fas fa-search me-1"></i>Apply
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Appointments List -->
                <div class="content-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-list"></i>Appointments for <?php echo date('F j, Y', strtotime($filter_date)); ?>
                        </h3>
                    </div>

                    <?php if (!empty($appointments)): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Patient</th>
                                    <th>Service</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $appointment): ?>
                                <tr>
                                    <td>
                                        <span class="time-slot">
                                            <?php 
                                            if ($appointment['appointment_time'] == '00:00:00') {
                                                echo '<span style="color: #856404;">Pending</span>';
                                            } else {
                                                echo date('g:i A', strtotime($appointment['appointment_time']));
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong><br>
                                        <small class="text-muted">
                                            ID: <?php echo htmlspecialchars($appointment['patient_id']); ?> | 
                                            <?php echo $appointment['age']; ?> yrs
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($appointment['service_name'] ?? 'General Consultation'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($appointment['appointment_location']); ?>
                                        <?php if ($appointment['barangay_name']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($appointment['barangay_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewAppointment(<?php echo $appointment['id']; ?>)"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <?php if ($appointment['status'] == 'pending'): ?>
                                                <button class="btn btn-sm btn-outline-success" 
                                                        onclick="confirmAppointment(<?php echo $appointment['id']; ?>, '<?php echo htmlspecialchars($appointment['patient_name']); ?>', '<?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?>', '<?php echo htmlspecialchars($appointment['service_name']); ?>')"
                                                        title="Confirm & Set Time">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning" 
                                                        onclick="rescheduleAppointment(<?php echo $appointment['id']; ?>)"
                                                        title="Reschedule">
                                                    <i class="fas fa-calendar-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($appointment['status'] == 'confirmed'): ?>
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="updateStatus(<?php echo $appointment['id']; ?>, 'completed')"
                                                        title="Mark Complete">
                                                    <i class="fas fa-check-double"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning" 
                                                        onclick="updateStatus(<?php echo $appointment['id']; ?>, 'no-show')"
                                                        title="Mark No-Show">
                                                    <i class="fas fa-user-slash"></i>
                                                </button>
                                                <a href="Consultation.php?appointment_id=<?php echo $appointment['id']; ?>&patient_id=<?php echo $appointment['patient_id']; ?>" 
                                                class="btn btn-sm btn-outline-primary"
                                                title="Start Consultation">
                                                    <i class="fas fa-stethoscope"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($appointment['status'], ['pending', 'confirmed'])): ?>
                                                <button class="btn btn-sm btn-outline-danger" 
                                                        onclick="cancelAppointment(<?php echo $appointment['id']; ?>)"
                                                        title="Cancel">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div>
                            Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $total_appointments); ?> 
                            of <?php echo number_format($total_appointments); ?> appointments
                        </div>
                        <nav>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&date=<?php echo $filter_date; ?>&status=<?php echo $filter_status; ?>&service=<?php echo $filter_service; ?>">Previous</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&date=<?php echo $filter_date; ?>&status=<?php echo $filter_status; ?>&service=<?php echo $filter_service; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&date=<?php echo $filter_date; ?>&status=<?php echo $filter_status; ?>&service=<?php echo $filter_service; ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No appointments for selected date and filters</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- View Appointment Modal -->
    <div class="modal fade" id="viewAppointmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>Appointment Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewAppointmentContent">
                    <div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div class="modal fade" id="cancelAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-times-circle me-2"></i>Cancel Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="cancelAppointmentId">
                    <div class="mb-3">
                        <label class="form-label">Cancellation Reason *</label>
                        <textarea class="form-control" id="cancellationReason" rows="3" 
                                  placeholder="Please provide a reason for cancellation..." required></textarea>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        The patient will be notified about this cancellation.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" id="confirmCancelAppointment">
                        <i class="fas fa-times me-2"></i>Cancel Appointment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reschedule Appointment Modal -->
    <div class="modal fade" id="rescheduleAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>Reschedule Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rescheduleAppointmentId">
                    <div class="mb-3">
                        <label class="form-label">New Date *</label>
                        <input type="date" class="form-control" id="rescheduleDate" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Time *</label>
                        <select class="form-select" id="rescheduleTime" required>
                            <option value="">Select time slot</option>
                            <option value="08:00:00">8:00 AM</option>
                            <option value="08:30:00">8:30 AM</option>
                            <option value="09:00:00">9:00 AM</option>
                            <option value="09:30:00">9:30 AM</option>
                            <option value="10:00:00">10:00 AM</option>
                            <option value="10:30:00">10:30 AM</option>
                            <option value="11:00:00">11:00 AM</option>
                            <option value="11:30:00">11:30 AM</option>
                            <option value="13:00:00">1:00 PM</option>
                            <option value="13:30:00">1:30 PM</option>
                            <option value="14:00:00">2:00 PM</option>
                            <option value="14:30:00">2:30 PM</option>
                            <option value="15:00:00">3:00 PM</option>
                            <option value="15:30:00">3:30 PM</option>
                            <option value="16:00:00">4:00 PM</option>
                            <option value="16:30:00">4:30 PM</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason for Rescheduling</label>
                        <textarea class="form-control" id="rescheduleReason" rows="3" 
                                placeholder="Optional: Reason for rescheduling..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmReschedule">
                        <i class="fas fa-calendar-check me-2"></i>Reschedule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Appointment with Time Modal -->
    <div class="modal fade" id="confirmAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Confirm Appointment & Set Time
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="confirmAppointmentId">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Patient Details:</strong>
                        <div id="confirmPatientInfo" class="mt-2"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Appointment Time *</label>
                        <select class="form-select" id="appointmentTime" required>
                            <option value="">Select time slot</option>
                            <option value="08:00:00">8:00 AM</option>
                            <option value="08:30:00">8:30 AM</option>
                            <option value="09:00:00">9:00 AM</option>
                            <option value="09:30:00">9:30 AM</option>
                            <option value="10:00:00">10:00 AM</option>
                            <option value="10:30:00">10:30 AM</option>
                            <option value="11:00:00">11:00 AM</option>
                            <option value="11:30:00">11:30 AM</option>
                            <option value="13:00:00">1:00 PM</option>
                            <option value="13:30:00">1:30 PM</option>
                            <option value="14:00:00">2:00 PM</option>
                            <option value="14:30:00">2:30 PM</option>
                            <option value="15:00:00">3:00 PM</option>
                            <option value="15:30:00">3:30 PM</option>
                            <option value="16:00:00">4:00 PM</option>
                            <option value="16:30:00">4:30 PM</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-success">
                        <i class="fas fa-bell me-2"></i>
                        The patient will be notified with the confirmed time.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmWithTime">
                        <i class="fas fa-check me-2"></i>Confirm Appointment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function showAlert(message, type = 'danger') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            alertDiv.style.display = 'block';
            window.scrollTo({top: 0, behavior: 'smooth'});
            
            if (type === 'success') {
                setTimeout(() => { alertDiv.style.display = 'none'; }, 5000);
            }
        }

        // Apply filters
        function applyFilters() {
            const date = document.getElementById('filterDate').value;
            const status = document.getElementById('filterStatus').value;
            const service = document.getElementById('filterService').value;
            
            window.location.href = `?date=${date}${status ? '&status=' + status : ''}${service ? '&service=' + service : ''}`;
        }

        // View appointment
        function viewAppointment(id) {
            const modal = new bootstrap.Modal(document.getElementById('viewAppointmentModal'));
            const content = document.getElementById('viewAppointmentContent');
            
            content.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading...</div>';
            modal.show();

            fetch('Appointments.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_appointment&appointment_id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const apt = data.appointment;
                    content.innerHTML = `
                        <div class="row">
                            <div class="col-md-12">
                                <div class="card border-0 mb-3">
                                    <div class="card-header bg-primary text-white">
                                        <h6 class="mb-0"><i class="fas fa-user me-2"></i>Patient Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Name:</strong> ${apt.patient_name}<br>
                                                <strong>Patient ID:</strong> ${apt.patient_id}<br>
                                                <strong>Age/Gender:</strong> ${apt.age} years / ${apt.gender}
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Phone:</strong> ${apt.phone || 'Not provided'}<br>
                                                <strong>Email:</strong> ${apt.email || 'Not provided'}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border-0 mb-3">
                                    <div class="card-header bg-info text-white">
                                        <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Appointment Details</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Date:</strong> ${new Date(apt.appointment_date).toLocaleDateString()}<br>
                                                <strong>Time:</strong> ${new Date('2000-01-01 ' + apt.appointment_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}<br>
                                                <strong>Service:</strong> ${apt.service_name || 'General Consultation'}
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Location:</strong> ${apt.appointment_location}<br>
                                                ${apt.barangay_name ? '<strong>Barangay:</strong> ' + apt.barangay_name + '<br>' : ''}
                                                <strong>Status:</strong> <span class="status-badge status-${apt.status}">${apt.status.charAt(0).toUpperCase() + apt.status.slice(1)}</span>
                                            </div>
                                        </div>
                                        ${apt.notes ? '<hr><strong>Notes:</strong><br>' + apt.notes : ''}
                                        ${apt.cancellation_reason ? '<hr><div class="alert alert-danger mb-0"><strong>Cancellation Reason:</strong><br>' + apt.cancellation_reason + '</div>' : ''}
                                    </div>
                                </div>

                                ${apt.staff_name ? `
                                <div class="card border-0">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0"><i class="fas fa-user-md me-2"></i>Assigned Staff</h6>
                                    </div>
                                    <div class="card-body">
                                        <strong>${apt.staff_name}</strong>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                } else {
                    content.innerHTML = '<div class="alert alert-danger">Error loading appointment details</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                content.innerHTML = '<div class="alert alert-danger">Error loading appointment details</div>';
            });
        }

        // Update status
        function updateStatus(id, newStatus) {
            const statusText = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            if (!confirm(`Are you sure you want to mark this appointment as ${statusText}?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('appointment_id', id);
            formData.append('status', newStatus);

            fetch('Appointments.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while updating the appointment.');
            });
        }

        // Cancel appointment
        function cancelAppointment(id) {
            document.getElementById('cancelAppointmentId').value = id;
            document.getElementById('cancellationReason').value = '';
            const modal = new bootstrap.Modal(document.getElementById('cancelAppointmentModal'));
            modal.show();
        }

        // Confirm cancellation
        document.getElementById('confirmCancelAppointment').addEventListener('click', function() {
            const id = document.getElementById('cancelAppointmentId').value;
            const reason = document.getElementById('cancellationReason').value.trim();

            if (!reason) {
                alert('Please provide a cancellation reason');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Cancelling...';

            const formData = new FormData();
            formData.append('action', 'cancel_appointment');
            formData.append('appointment_id', id);
            formData.append('reason', reason);

            fetch('Appointments.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('cancelAppointmentModal')).hide();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while cancelling the appointment.');
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-times me-2"></i>Cancel Appointment';
            });
        });

        // Confirm appointment with time
function confirmAppointment(id, patientName, appointmentDate, serviceName) {
    document.getElementById('confirmAppointmentId').value = id;
    document.getElementById('confirmPatientInfo').innerHTML = `
        <strong>Patient:</strong> ${patientName}<br>
        <strong>Date:</strong> ${appointmentDate}<br>
        <strong>Service:</strong> ${serviceName}
    `;
    document.getElementById('appointmentTime').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('confirmAppointmentModal'));
    modal.show();
}

    // Confirm with time button handler
    document.getElementById('confirmWithTime').addEventListener('click', function() {
        const id = document.getElementById('confirmAppointmentId').value;
        const appointmentTime = document.getElementById('appointmentTime').value;

        if (!appointmentTime) {
            alert('Please select an appointment time');
            return;
        }

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Confirming...';

        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('appointment_id', id);
        formData.append('status', 'confirmed');
        formData.append('appointment_time', appointmentTime);

        fetch('Appointments.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('confirmAppointmentModal')).hide();
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred while confirming the appointment.');
        })
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Appointment';
        });
    });

    // Reschedule appointment
function rescheduleAppointment(id) {
    document.getElementById('rescheduleAppointmentId').value = id;
    document.getElementById('rescheduleDate').value = '';
    document.getElementById('rescheduleTime').value = '';
    document.getElementById('rescheduleReason').value = '';
    
    // Set minimum date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('rescheduleDate').min = tomorrow.toISOString().split('T')[0];
    
    const modal = new bootstrap.Modal(document.getElementById('rescheduleAppointmentModal'));
    modal.show();
}

    // Confirm reschedule
    document.getElementById('confirmReschedule').addEventListener('click', function() {
        const id = document.getElementById('rescheduleAppointmentId').value;
        const newDate = document.getElementById('rescheduleDate').value;
        const newTime = document.getElementById('rescheduleTime').value;
        const reason = document.getElementById('rescheduleReason').value;

        if (!newDate || !newTime) {
            alert('Please select both date and time');
            return;
        }

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Rescheduling...';

        const formData = new FormData();
        formData.append('action', 'reschedule_appointment');
        formData.append('appointment_id', id);
        formData.append('new_date', newDate);
        formData.append('new_time', newTime);
        formData.append('reason', reason);

        fetch('Appointments.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('rescheduleAppointmentModal')).hide();
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred while rescheduling the appointment.');
        })
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-calendar-check me-2"></i>Reschedule';
        });
    });
    </script>
</body>
</html>