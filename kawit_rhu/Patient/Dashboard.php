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

// Get patient information with calculated age
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

// Get upcoming appointments
$upcomingAppointmentsStmt = $pdo->prepare("
    SELECT a.*, st.service_name, st.service_category,
           CONCAT(s.first_name, ' ', s.last_name) as doctor_name,
           b.barangay_name
    FROM appointments a 
    LEFT JOIN service_types st ON a.service_type_id = st.id
    LEFT JOIN staff s ON a.assigned_staff = s.id
    LEFT JOIN barangays b ON a.barangay_id = b.id
    WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() 
    AND a.status IN ('pending', 'confirmed')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 5
");
$upcomingAppointmentsStmt->execute([$patient['id']]);
$upcomingAppointments = $upcomingAppointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent consultations
$consultationsStmt = $pdo->prepare("
    SELECT c.*, CONCAT(s.first_name, ' ', s.last_name) as doctor_name
    FROM consultations c 
    LEFT JOIN staff s ON c.assigned_doctor = s.id
    WHERE c.patient_id = ? AND c.status = 'completed'
    ORDER BY c.consultation_date DESC
    LIMIT 5
");
$consultationsStmt->execute([$patient['id']]);
$recentConsultations = $consultationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent lab results
$labResultsStmt = $pdo->prepare("
    SELECT lr.*, CONCAT(s.first_name, ' ', s.last_name) as performed_by_name
    FROM laboratory_results lr 
    LEFT JOIN staff s ON lr.performed_by = s.id
    WHERE lr.patient_id = ? AND lr.status = 'completed'
    ORDER BY lr.test_date DESC
    LIMIT 3
");
$labResultsStmt->execute([$patient['id']]);
$recentLabResults = $labResultsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent prescriptions
$prescriptionsStmt = $pdo->prepare("
    SELECT pr.*, CONCAT(s.first_name, ' ', s.last_name) as prescribed_by_name
    FROM prescriptions pr 
    LEFT JOIN staff s ON pr.prescribed_by = s.id
    WHERE pr.patient_id = ? 
    ORDER BY pr.prescription_date DESC
    LIMIT 3
");
$prescriptionsStmt->execute([$patient['id']]);
$recentPrescriptions = $prescriptionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get announcements
$announcementsStmt = $pdo->prepare("
    SELECT a.*, CONCAT(s.first_name, ' ', s.last_name) as author_name
    FROM announcements a 
    JOIN staff s ON a.author_id = s.id
    WHERE a.status = 'published' 
    AND (a.expiry_date IS NULL OR a.expiry_date > NOW())
    AND (a.target_audience IN ('all', 'patients'))
    ORDER BY a.priority DESC, a.publish_date DESC 
    LIMIT 5
");
$announcementsStmt->execute();
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending notifications count
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) as unread_count 
    FROM notifications 
    WHERE user_id = ? AND is_read = 0 AND (expires_at IS NULL OR expires_at > NOW())
");
$notificationsStmt->execute([$_SESSION['user_id']]);
$notificationCount = $notificationsStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kawit RHU - Patient Dashboard</title>
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

        .notification-badge {
            position: relative;
            margin-left: 10px;
        }

        .notification-badge .badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 4px 6px;
            font-size: 0.7rem;
            min-width: 18px;
            text-align: center;
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
            position: relative;
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

        .view-all-link {
            color: var(--dark-pink);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .view-all-link:hover {
            color: var(--kawit-pink);
            transform: translateX(3px);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .quick-action-btn {
            background: var(--kawit-gradient);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 1rem;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .quick-action-btn:hover {
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(255, 166, 190, 0.4);
        }

        .quick-action-btn i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        /* Cards */
        .item-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--kawit-pink);
            transition: all 0.3s ease;
        }

        .item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .item-date {
            color: var(--dark-pink);
            font-weight: 700;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .item-title {
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .item-status {
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-confirmed { background-color: #d1ecf1; color: #0c5460; }
        .status-completed { background-color: #d4edda; color: #155724; }

        .priority-low { background-color: #e8f5e8; color: #2e7d32; }
        .priority-medium { background-color: #fff3e0; color: #f57c00; }
        .priority-high { background-color: #ffebee; color: #c62828; }
        .priority-urgent { background-color: #ffebee; color: #c62828; }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Welcome Section Styling */
        .welcome-info {
            font-size: 0.9rem;
            color: #6c757d;
            margin: 0.5rem 0;
        }

        .patient-id {
            background: var(--light-pink);
            color: var(--dark-pink);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-block;
            margin-top: 0.5rem;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--kawit-pink);
            transition: all 0.3s ease;
        }

        /* Grid layouts for dashboard cards */
        .appointments-grid,
        .consultations-grid,
        .lab-results-grid,
        .prescriptions-grid,
        .announcements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }

        .appointments-grid .item-card,
        .consultations-grid .item-card,
        .lab-results-grid .item-card,
        .prescriptions-grid .item-card,
        .announcements-grid .item-card {
            margin-bottom: 0;
            height: 100%;
        }

        /* Compact welcome section */
        .welcome-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem;
        }

        .welcome-section .section-icon {
            margin-right: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-pink);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-container { flex-direction: column; }
            .sidebar { width: 100%; height: auto; }
            .sidebar-nav { display: flex; overflow-x: auto; padding: 1rem; }
            .nav-item { margin: 0 0.5rem; min-width: 140px; }
            .nav-link { white-space: nowrap; justify-content: center; flex-direction: column; padding: 1rem; text-align: center; }
            .nav-link i { margin: 0 0 0.5rem 0; }
            .dashboard-content { padding: 1rem; }
            .top-navbar { padding: 1rem 1.5rem; flex-direction: column; align-items: flex-start; gap: 1rem; }
            .page-title { font-size: 1.5rem; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .quick-actions { grid-template-columns: 1fr; }
            .view-all-link { position: static !important; margin-top: 1rem; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .appointments-grid,
            .consultations-grid,
            .lab-results-grid,
            .prescriptions-grid,
            .announcements-grid {
                grid-template-columns: 1fr;
            }
            .welcome-section {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 1rem;
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
                    <a href="Dashboard.php" class="nav-link active">
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
                <h1 class="page-title">Dashboard</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
                        echo $initials;
                        ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($patient['first_name'] . ' ' . substr($patient['last_name'], 0, 1) . '.'); ?></div>
                        <div class="user-role">Patient</div>
                    </div>
                    <?php if ($notificationCount > 0): ?>
                    <div class="notification-badge">
                        <i class="fas fa-bell" style="color: var(--dark-pink); font-size: 1.2rem;"></i>
                        <span class="badge"><?php echo $notificationCount; ?></span>
                    </div>
                    <?php endif; ?>
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
                <!-- Welcome Section -->
                <div class="content-section" style="padding: 1.5rem; margin-bottom: 1.5rem;">
                    <div class="welcome-section">
                        <div style="display: flex; align-items: center;">
                            <div class="section-icon">
                                <i class="fas fa-home"></i>
                            </div>
                            <div>
                                <h3 class="section-title" style="margin-bottom: 0.3rem;">Welcome back, <?php echo htmlspecialchars($patient['first_name']); ?>!</h3>
                                <div class="welcome-info">
                                    <?php echo htmlspecialchars($patient['age']); ?> years old • 
                                    <?php echo htmlspecialchars($patient['barangay_name'] ?? 'No barangay assigned'); ?>
                                </div>
                            </div>
                        </div>
                        <span class="patient-id">ID: <?php echo htmlspecialchars($patient['patient_id']); ?></span>
                    </div>
                </div>

                <!-- Stats Overview -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($upcomingAppointments); ?></div>
                        <div class="stat-label">Upcoming Appointments</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($recentConsultations); ?></div>
                        <div class="stat-label">Recent Consultations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($recentPrescriptions); ?></div>
                        <div class="stat-label">Active Prescriptions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($recentLabResults); ?></div>
                        <div class="stat-label">Recent Lab Results</div>
                    </div>
                </div>

                <!-- Two Column Layout for Appointments and Consultations -->
                <div class="row">
                    <!-- Upcoming Appointments -->
                    <div class="col-lg-6">
                        <div class="content-section">
                            <div class="section-header">
                                <div class="section-header-left">
                                    <div class="section-icon">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <h3 class="section-title">Upcoming Appointments</h3>
                                </div>
                            </div>
                            <a href="Appointment.php" class="view-all-link" style="position: absolute; top: 2rem; right: 2rem;">
                                View All <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                            
                            <?php if (!empty($upcomingAppointments)): ?>
                                <div class="appointments-grid">
                                <?php foreach ($upcomingAppointments as $appointment): ?>
                                    <div class="item-card">
                                        <div class="item-date">
                                            <?php echo date('M j, Y', strtotime($appointment['appointment_date'])); ?> at 
                                            <?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?>
                                        </div>
                                        <div class="item-title">
                                            <?php echo htmlspecialchars($appointment['service_name'] ?? 'General Service'); ?>
                                        </div>
                                        <?php if ($appointment['appointment_location']): ?>
                                            <small style="color: #6c757d;">
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                <?php echo htmlspecialchars($appointment['appointment_location']); ?>
                                                <?php if ($appointment['barangay_name']): ?>
                                                    - <?php echo htmlspecialchars($appointment['barangay_name']); ?>
                                                <?php endif; ?>
                                            </small><br>
                                        <?php endif; ?>
                                        <span class="item-status status-<?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    <h6>No Upcoming Appointments</h6>
                                    <p>You don't have any appointments scheduled.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Consultations -->
                    <div class="col-lg-6">
                        <div class="content-section">
                            <div class="section-header">
                                <div class="section-header-left">
                                    <div class="section-icon">
                                        <i class="fas fa-stethoscope"></i>
                                    </div>
                                    <h3 class="section-title">Recent Consultations</h3>
                                </div>
                            </div>
                            <a href="Health_Record.php" class="view-all-link" style="position: absolute; top: 2rem; right: 2rem;">
                                View All <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                            
                            <?php if (!empty($recentConsultations)): ?>
                                <div class="consultations-grid">
                                <?php foreach ($recentConsultations as $consultation): ?>
                                    <div class="item-card" style="border-left-color: #28a745;">
                                        <div class="item-date">
                                            <?php echo date('M j, Y', strtotime($consultation['consultation_date'])); ?>
                                        </div>
                                        <div class="item-title"><?php echo ucfirst($consultation['consultation_type']); ?> Consultation</div>
                                        <?php if ($consultation['doctor_name']): ?>
                                            <small style="color: #6c757d;">with Dr. <?php echo htmlspecialchars($consultation['doctor_name']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($consultation['diagnosis']): ?>
                                            <br><small style="color: #28a745;"><?php echo htmlspecialchars($consultation['diagnosis']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-stethoscope"></i>
                                    <h6>No Consultation History</h6>
                                    <p>Your consultation history will appear here.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Lab Results and Prescriptions Row -->
                <div class="row">
                    <!-- Recent Lab Results -->
                    <div class="col-lg-6">
                        <div class="content-section">
                            <div class="section-header">
                                <div class="section-header-left">
                                    <div class="section-icon">
                                        <i class="fas fa-flask"></i>
                                    </div>
                                    <h3 class="section-title">Recent Lab Results</h3>
                                </div>
                            </div>
                            <a href="Health_Record.php" class="view-all-link" style="position: absolute; top: 2rem; right: 2rem;">
                                View All <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                            
                            <?php if (!empty($recentLabResults)): ?>
                                <div class="lab-results-grid">
                                <?php foreach ($recentLabResults as $labResult): ?>
                                    <div class="item-card" style="border-left-color: #007bff;">
                                        <div class="item-date">
                                            <?php echo date('M j, Y', strtotime($labResult['test_date'])); ?>
                                        </div>
                                        <div class="item-title"><?php echo htmlspecialchars($labResult['test_type']); ?></div>
                                        <?php if ($labResult['test_category']): ?>
                                            <small style="color: #6c757d;"><?php echo htmlspecialchars($labResult['test_category']); ?></small><br>
                                        <?php endif; ?>
                                        <span class="item-status status-<?php echo $labResult['status']; ?>">
                                            <?php echo ucfirst($labResult['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-flask"></i>
                                    <h6>No Lab Results</h6>
                                    <p>Your lab test results will appear here.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Prescriptions -->
                    <div class="col-lg-6">
                        <div class="content-section">
                            <div class="section-header">
                                <div class="section-header-left">
                                    <div class="section-icon">
                                        <i class="fas fa-pills"></i>
                                    </div>
                                    <h3 class="section-title">Recent Prescriptions</h3>
                                </div>
                            </div>
                            <a href="Health_Record.php" class="view-all-link" style="position: absolute; top: 2rem; right: 2rem;">
                                View All <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                            
                            <?php if (!empty($recentPrescriptions)): ?>
                                <div class="prescriptions-grid">
                                <?php foreach ($recentPrescriptions as $prescription): ?>
                                    <div class="item-card" style="border-left-color: #6f42c1;">
                                        <div class="item-date">
                                            <?php echo date('M j, Y', strtotime($prescription['prescription_date'])); ?>
                                        </div>
                                        <div class="item-title"><?php echo htmlspecialchars($prescription['medication_name']); ?></div>
                                        <small style="color: #6c757d;">
                                            <?php echo htmlspecialchars($prescription['dosage_strength'] . ' - ' . $prescription['frequency']); ?>
                                        </small><br>
                                        <span class="item-status status-<?php echo str_replace('_', '-', $prescription['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $prescription['status'])); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-pills"></i>
                                    <h6>No Prescriptions</h6>
                                    <p>Your prescribed medications will appear here.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Announcements -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-header-left">
                            <div class="section-icon">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <h3 class="section-title">Announcements & Updates</h3>
                        </div>
                    </div>
                    
                    <?php if (!empty($announcements)): ?>
                        <div class="announcements-grid">
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="item-card" style="border-left-color: #ffc107;">
                                <div class="item-date">
                                    <?php echo date('M j, Y', strtotime($announcement['publish_date'])); ?>
                                    <?php if ($announcement['featured']): ?>
                                        <span class="badge bg-warning text-dark ms-2">Featured</span>
                                    <?php endif; ?>
                                </div>
                                <div class="item-title"><?php echo htmlspecialchars($announcement['title']); ?></div>
                                <p style="color: #6c757d; margin: 0.5rem 0; font-size: 0.9rem;">
                                    <?php echo htmlspecialchars(substr($announcement['content'], 0, 120)); ?>
                                    <?php if (strlen($announcement['content']) > 120): ?>...<?php endif; ?>
                                </p>
                                <span class="item-status priority-<?php echo $announcement['priority']; ?>">
                                    <?php echo ucfirst($announcement['priority']); ?> Priority
                                </span>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <h6>No Announcements</h6>
                            <p>Check back later for updates from the RHU.</p>
                        </div>
                    <?php endif; ?>
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

        // Auto-refresh notifications every 5 minutes
        setInterval(function() {
            fetch('Dashboard.php')
                .then(response => response.text())
                .then(data => {
                    // Check if user is still logged in
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
</html>