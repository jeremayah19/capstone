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

// Get consultation history
$consultationsStmt = $pdo->prepare("
    SELECT c.*, CONCAT(s.first_name, ' ', s.last_name) as doctor_name,
           b.barangay_name as consultation_location_name
    FROM consultations c 
    LEFT JOIN staff s ON c.assigned_doctor = s.id
    LEFT JOIN barangays b ON c.barangay_id = b.id
    WHERE c.patient_id = ? 
    ORDER BY c.consultation_date DESC
");
$consultationsStmt->execute([$patient['id']]);
$consultations = $consultationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get laboratory results
$labResultsStmt = $pdo->prepare("
    SELECT lr.*, CONCAT(s1.first_name, ' ', s1.last_name) as performed_by_name,
           CONCAT(s2.first_name, ' ', s2.last_name) as verified_by_name,
           c.consultation_number
    FROM laboratory_results lr 
    LEFT JOIN staff s1 ON lr.performed_by = s1.id
    LEFT JOIN staff s2 ON lr.verified_by = s2.id
    LEFT JOIN consultations c ON lr.consultation_id = c.id
    WHERE lr.patient_id = ? 
    ORDER BY lr.test_date DESC
");
$labResultsStmt->execute([$patient['id']]);
$labResults = $labResultsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get prescriptions
$prescriptionsStmt = $pdo->prepare("
    SELECT pr.*, CONCAT(s1.first_name, ' ', s1.last_name) as prescribed_by_name,
           CONCAT(s2.first_name, ' ', s2.last_name) as dispensed_by_name,
           c.consultation_number
    FROM prescriptions pr 
    LEFT JOIN staff s1 ON pr.prescribed_by = s1.id
    LEFT JOIN staff s2 ON pr.dispensed_by = s2.id
    LEFT JOIN consultations c ON pr.consultation_id = c.id
    WHERE pr.patient_id = ? 
    ORDER BY pr.prescription_date DESC
");
$prescriptionsStmt->execute([$patient['id']]);
$prescriptions = $prescriptionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get medical certificates
$certificatesStmt = $pdo->prepare("
    SELECT mc.*, CONCAT(s.first_name, ' ', s.last_name) as issued_by_name
    FROM medical_certificates mc 
    LEFT JOIN staff s ON mc.issued_by = s.id
    WHERE mc.patient_id = ? 
    ORDER BY mc.date_issued DESC
");
$certificatesStmt->execute([$patient['id']]);
$certificates = $certificatesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get referrals
$referralsStmt = $pdo->prepare("
    SELECT r.*, CONCAT(s.first_name, ' ', s.last_name) as referred_by_name,
           c.consultation_number
    FROM referrals r 
    LEFT JOIN staff s ON r.referred_by = s.id
    LEFT JOIN consultations c ON r.consultation_id = c.id
    WHERE r.patient_id = ? 
    ORDER BY r.referral_date DESC
");
$referralsStmt->execute([$patient['id']]);
$referrals = $referralsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Records - Kawit RHU</title>
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

        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 2rem;
            overflow-x: auto;
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
            white-space: nowrap;
            min-width: 150px;
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

        /* Filter Section */
        .filter-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-right: 0.5rem;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #dee2e6;
            background: white;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            border-color: var(--kawit-pink);
            color: var(--dark-pink);
        }

        .filter-btn.active {
            background: var(--kawit-gradient);
            border-color: var(--dark-pink);
            color: white;
        }

        .filter-btn.active:hover {
            background: var(--kawit-gradient);
            color: white;
        }

        /* Record Cards */
        .record-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        /* Grid layout for all tabs */
        .consultations-grid,
        .lab-results-grid,
        .prescriptions-grid,
        .certificates-grid,
        .referrals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .consultations-grid .record-card,
        .lab-results-grid .record-card,
        .prescriptions-grid .record-card,
        .certificates-grid .record-card,
        .referrals-grid .record-card {
            margin-bottom: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .record-content {
            flex: 1;
        }

        /* Responsive grid adjustments */
        @media (max-width: 1200px) {
            .consultations-grid,
            .lab-results-grid,
            .prescriptions-grid,
            .certificates-grid,
            .referrals-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .consultations-grid,
            .lab-results-grid,
            .prescriptions-grid,
            .certificates-grid,
            .referrals-grid {
                grid-template-columns: 1fr;
            }
        }

        .record-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .record-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .record-date {
            color: var(--dark-pink);
            font-weight: 700;
            font-size: 1rem;
        }

        .record-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Consistent Status Colors */
        /* GREEN - Completed/Success States */
        .status-completed { background-color: #d4edda; color: #155724; }
        .status-fully-dispensed { background-color: #d4edda; color: #155724; }
        .status-dispensed { background-color: #d4edda; color: #155724; }
        .status-issued { background-color: #d4edda; color: #155724; }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-accepted { background-color: #d4edda; color: #155724; }
        .status-sent { background-color: #d4edda; color: #155724; }
        .status-ready-for-download { background-color: #d4edda; color: #155724; }
        .status-downloaded { background-color: #d4edda; color: #155724; }
        .status-completed-checkup { background-color: #d4edda; color: #155724; }

        /* YELLOW - Pending/In Progress States */
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-processing { background-color: #fff3cd; color: #856404; }
        .status-in-progress { background-color: #fff3cd; color: #856404; }
        .status-partially-dispensed { background-color: #fff3cd; color: #856404; }
        .status-approved-for-checkup { background-color: #fff3cd; color: #856404; }

        /* RED - Cancelled/Danger States */
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        .status-expired { background-color: #f8d7da; color: #721c24; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }

        .record-content {
            margin-bottom: 1rem;
        }

        .record-label {
            color: #6c757d;
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .record-value {
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 0.8rem;
        }

        /* Consultation specific styles */
        .consultation-card {
            border-left: 4px solid #28a745;
        }

        .vital-signs {
            background: #e8f5e8;
            padding: 0.8rem;
            border-radius: 8px;
            margin: 0.5rem 0;
        }

        .vital-signs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.5rem;
        }

        .vital-item {
            text-align: center;
        }

        .vital-label {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .vital-value {
            font-weight: 700;
            color: #28a745;
        }

        /* Lab Results specific styles */
        .lab-card {
            border-left: 4px solid #007bff;
        }

        .lab-results-data {
            background: #e3f2fd;
            padding: 0.8rem;
            border-radius: 8px;
            margin: 0.5rem 0;
        }

        /* Lab Result Interpretation Colors */
        .lab-normal { 
            background-color: #d4edda; 
            color: #155724; 
            padding: 0.3rem 0.8rem; 
            border-radius: 15px; 
            font-weight: 600; 
            display: inline-block;
        }
        .lab-abnormal { 
            background-color: #f8d7da; 
            color: #721c24; 
            padding: 0.3rem 0.8rem; 
            border-radius: 15px; 
            font-weight: 600; 
            display: inline-block;
        }
        .lab-pending { 
            background-color: #fff3cd; 
            color: #856404; 
            padding: 0.3rem 0.8rem; 
            border-radius: 15px; 
            font-weight: 600; 
            display: inline-block;
        }

        /* Prescription specific styles */
        .prescription-card {
            border-left: 4px solid #6f42c1;
        }

        .medication-name {
            color: #6f42c1;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .dosage-info {
            background: #f3e5f5;
            padding: 0.8rem;
            border-radius: 8px;
            margin: 0.5rem 0;
        }

        /* Certificate specific styles */
        .certificate-card {
            border-left: 4px solid #fd7e14;
        }

        .certificate-number {
            background: var(--light-pink);
            color: var(--dark-pink);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-block;
        }

        /* Referral specific styles */
        .referral-card {
            border-left: 4px solid #e83e8c;
        }

        /* Referral Urgency Colors */
        .urgency-urgent { 
            border-left: 5px solid #dc3545 !important; 
            background: #fff5f5; 
        }
        .urgency-high { 
            border-left: 5px solid #ffc107 !important; 
            background: #fffbf0; 
        }
        .urgency-routine { 
            border-left: 5px solid #28a745 !important; 
            background: #f0fff4; 
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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

            .record-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .tab-navigation {
                flex-direction: column;
            }

            .tab-btn {
                min-width: auto;
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
                    <a href="Health_Record.php" class="nav-link active">
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
                <h1 class="page-title">Health Records</h1>
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
                
                <!-- Health Records Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-file-medical"></i>
                        </div>
                        <h3 class="section-title">Complete Health Records</h3>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <div class="tab-navigation">
                        <button class="tab-btn active" data-tab="consultations">
                            <i class="fas fa-stethoscope me-2"></i>Consultations
                        </button>
                        <button class="tab-btn" data-tab="lab-results">
                            <i class="fas fa-flask me-2"></i>Lab Results
                        </button>
                        <button class="tab-btn" data-tab="prescriptions">
                            <i class="fas fa-pills me-2"></i>Prescriptions
                        </button>
                        <button class="tab-btn" data-tab="certificates">
                            <i class="fas fa-certificate me-2"></i>Certificates
                        </button>
                        <button class="tab-btn" data-tab="referrals">
                            <i class="fas fa-share me-2"></i>Referrals
                        </button>
                    </div>
                    
                    <!-- Consultation History Tab -->
                    <div class="tab-content active" id="consultations">
                        <?php if (!empty($consultations)): ?>
                            <!-- Filter Section -->
                            <div class="filter-section" id="consultations-filters">
                                <span class="filter-label"><i class="fas fa-filter me-2"></i>Filter by Status:</span>
                                <button class="filter-btn active" data-filter="all" onclick="filterItems('consultations', 'all')">All</button>
                                <button class="filter-btn" data-filter="pending" onclick="filterItems('consultations', 'pending')">Pending</button>
                                <button class="filter-btn" data-filter="in_progress" onclick="filterItems('consultations', 'in_progress')">In Progress</button>
                                <button class="filter-btn" data-filter="completed" onclick="filterItems('consultations', 'completed')">Completed</button>
                                <button class="filter-btn" data-filter="cancelled" onclick="filterItems('consultations', 'cancelled')">Cancelled</button>
                            </div>
                            <div class="consultations-grid">
                            <?php foreach ($consultations as $consultation): ?>
                                <div class="record-card consultation-card" data-status="<?php echo $consultation['status']; ?>">
                                    <div class="record-header">
                                        <div class="record-date">
                                            <?php echo $consultation['consultation_date'] ? date('F j, Y g:i A', strtotime($consultation['consultation_date'])) : 'Date pending'; ?>
                                        </div>
                                        <span class="record-status status-<?php echo str_replace('_', '-', $consultation['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $consultation['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="record-content">
                                        <?php if ($consultation['consultation_number']): ?>
                                            <div class="record-label">Consultation Number</div>
                                            <div class="record-value"><?php echo htmlspecialchars($consultation['consultation_number']); ?></div>
                                        <?php endif; ?>
                                        
                                        <div class="record-label">Type</div>
                                        <div class="record-value"><?php echo ucfirst($consultation['consultation_type']); ?> Consultation</div>
                                        
                                        <?php if ($consultation['chief_complaint']): ?>
                                            <div class="record-label">Chief Complaint</div>
                                            <div class="record-value"><?php echo htmlspecialchars($consultation['chief_complaint']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($consultation['diagnosis']): ?>
                                            <div class="record-label">Diagnosis</div>
                                            <div class="record-value"><?php echo htmlspecialchars($consultation['diagnosis']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($consultation['doctor_name']): ?>
                                            <div class="record-label">Attending Doctor</div>
                                            <div class="record-value">Dr. <?php echo htmlspecialchars($consultation['doctor_name']); ?></div>
                                        <?php endif; ?>

                                        <!-- Details Button -->
                                        <?php if ($consultation['status'] == 'completed'): ?>
                                            <button onclick="viewConsultationDetails(<?php echo $consultation['id']; ?>)" 
                                                    style="width: 100%; margin-top: 1rem; background: #28a745; color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                                <i class="fas fa-stethoscope me-1"></i>View Full Details
                                            </button>
                                        <?php endif; ?>

                                        <!-- Hidden data for modal -->
                                        <div id="consultation-data-<?php echo $consultation['id']; ?>" style="display: none;">
                                            <?php echo htmlspecialchars(json_encode([
                                                'consultation_number' => $consultation['consultation_number'] ?? '',
                                                'consultation_date' => $consultation['consultation_date'] ?? '',
                                                'consultation_type' => $consultation['consultation_type'] ?? '',
                                                'consultation_location' => $consultation['consultation_location'] ?? '',
                                                'consultation_location_name' => $consultation['consultation_location_name'] ?? '',
                                                'chief_complaint' => $consultation['chief_complaint'] ?? '',
                                                'temperature' => $consultation['temperature'] ?? '',
                                                'bp_systolic' => $consultation['blood_pressure_systolic'] ?? '',
                                                'bp_diastolic' => $consultation['blood_pressure_diastolic'] ?? '',
                                                'heart_rate' => $consultation['heart_rate'] ?? '',
                                                'respiratory_rate' => $consultation['respiratory_rate'] ?? '',
                                                'oxygen_saturation' => $consultation['oxygen_saturation'] ?? '',
                                                'weight' => $consultation['weight'] ?? '',
                                                'height' => $consultation['height'] ?? '',
                                                'bmi' => $consultation['bmi'] ?? '',
                                                'general_appearance' => $consultation['general_appearance'] ?? '',
                                                'heent_exam' => $consultation['heent_exam'] ?? '',
                                                'respiratory_exam' => $consultation['respiratory_exam'] ?? '',
                                                'cardiovascular_exam' => $consultation['cardiovascular_exam'] ?? '',
                                                'abdomen_exam' => $consultation['abdomen_exam'] ?? '',
                                                'musculoskeletal_exam' => $consultation['musculoskeletal_exam'] ?? '',
                                                'diagnosis' => $consultation['diagnosis'] ?? '',
                                                'treatment_plan' => $consultation['treatment_plan'] ?? '',
                                                'recommendations' => $consultation['recommendations'] ?? '',
                                                'follow_up_date' => $consultation['follow_up_date'] ?? '',
                                                'remarks' => $consultation['remarks'] ?? '',
                                                'fitness_status' => $consultation['fitness_status'] ?? '',
                                                'restrictions' => $consultation['restrictions'] ?? '',
                                                'doctor_name' => $consultation['doctor_name'] ?? ''
                                            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <!-- Empty Filter State -->
                            <div class="empty-state empty-filter-state" style="display: none;">
                                <i class="fas fa-filter"></i>
                                <h5>No Results Found</h5>
                                <p>No consultations match the selected filter.</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-stethoscope"></i>
                                <h5>No Consultation Records</h5>
                                <p>Your consultation history will appear here after your visits.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Laboratory Results Tab -->
                    <div class="tab-content" id="lab-results">
                        <?php if (!empty($labResults)): ?>
                            <!-- Filter Section -->
                            <div class="filter-section" id="lab-results-filters">
                                <span class="filter-label"><i class="fas fa-filter me-2"></i>Filter by Status:</span>
                                <button class="filter-btn active" data-filter="all" onclick="filterItems('lab-results', 'all')">All</button>
                                <button class="filter-btn" data-filter="pending" onclick="filterItems('lab-results', 'pending')">Pending</button>
                                <button class="filter-btn" data-filter="processing" onclick="filterItems('lab-results', 'processing')">Processing</button>
                                <button class="filter-btn" data-filter="completed" onclick="filterItems('lab-results', 'completed')">Completed</button>
                                <button class="filter-btn" data-filter="cancelled" onclick="filterItems('lab-results', 'cancelled')">Cancelled</button>
                            </div>
                            <div class="lab-results-grid">
                        <?php foreach ($labResults as $result): ?>
                        <div class="record-card lab-card" data-status="<?php echo $result['status']; ?>">
                            <div class="record-header">
                                <div class="record-date">
                                    <?php echo date('F j, Y', strtotime($result['test_date'])); ?>
                                    <?php if ($result['result_date']): ?>
                                        - Results: <?php echo date('M j, Y', strtotime($result['result_date'])); ?>
                                    <?php endif; ?>
                                </div>
                                <span class="record-status status-<?php echo $result['status']; ?>">
                                    <?php echo ucfirst($result['status']); ?>
                                </span>
                            </div>
                            <div class="record-content">
                                <?php if ($result['lab_number']): ?>
                                    <div class="record-label">Lab Number</div>
                                    <div class="record-value"><?php echo htmlspecialchars($result['lab_number']); ?></div>
                                <?php endif; ?>
                                
                                <div class="record-label">Test Name</div>
                                <div class="record-value"><?php echo htmlspecialchars($result['test_type']); ?></div>
                                
                                <?php if ($result['test_category']): ?>
                                    <div class="record-label">Category</div>
                                    <div class="record-value"><?php echo htmlspecialchars($result['test_category']); ?></div>
                                <?php endif; ?>
                                
                                <?php if ($result['interpretation']): ?>
                                    <div class="record-label">Interpretation</div>
                                    <div class="record-value lab-normal"><?php echo htmlspecialchars($result['interpretation']); ?></div>
                                <?php endif; ?>
                                
                                <?php if ($result['performed_by_name']): ?>
                                    <div class="record-label">Performed By</div>
                                    <div class="record-value"><?php echo htmlspecialchars($result['performed_by_name']); ?></div>
                                <?php endif; ?>

                                <!-- Details Button -->
                                <?php if ($result['status'] == 'completed'): ?>
                                    <button onclick="viewLabDetails(<?php echo $result['id']; ?>)" 
                                            style="width: 100%; margin-top: 1rem; background: #007bff; color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                        <i class="fas fa-flask me-1"></i>View Full Details
                                    </button>
                                <?php endif; ?>

                                <!-- Hidden data for modal -->
                                <div id="lab-data-<?php echo $result['id']; ?>" style="display: none;">
                                    <?php echo json_encode([
                                        'lab_number' => $result['lab_number'],
                                        'test_type' => $result['test_type'],
                                        'test_category' => $result['test_category'],
                                        'test_date' => $result['test_date'],
                                        'result_date' => $result['result_date'],
                                        'specimen_type' => $result['specimen_type'],
                                        'test_results' => $result['test_results'],
                                        'normal_range' => $result['normal_range'],
                                        'interpretation' => $result['interpretation'],
                                        'remarks' => $result['remarks'],
                                        'performed_by_name' => $result['performed_by_name'],
                                        'verified_by_name' => $result['verified_by_name'],
                                        'consultation_number' => $result['consultation_number']
                                    ]); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                            </div>
                            <!-- Empty Filter State -->
                            <div class="empty-state empty-filter-state" style="display: none;">
                                <i class="fas fa-filter"></i>
                                <h5>No Results Found</h5>
                                <p>No lab results match the selected filter.</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-flask"></i>
                                <h5>No Laboratory Results</h5>
                                <p>Your lab test results will appear here when available.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Prescriptions Tab -->
                    <div class="tab-content" id="prescriptions">
                        <?php if (!empty($prescriptions)): ?>
                            <!-- Filter Section -->
                            <div class="filter-section" id="prescriptions-filters">
                                <span class="filter-label"><i class="fas fa-filter me-2"></i>Filter by Status:</span>
                                <button class="filter-btn active" data-filter="all" onclick="filterItems('prescriptions', 'all')">All</button>
                                <button class="filter-btn" data-filter="pending" onclick="filterItems('prescriptions', 'pending')">Pending</button>
                                <button class="filter-btn" data-filter="partially_dispensed" onclick="filterItems('prescriptions', 'partially_dispensed')">Partial</button>
                                <button class="filter-btn" data-filter="fully_dispensed" onclick="filterItems('prescriptions', 'fully_dispensed')">Dispensed</button>
                                <button class="filter-btn" data-filter="cancelled" onclick="filterItems('prescriptions', 'cancelled')">Cancelled</button>
                            </div>
                            <div class="prescriptions-grid">
                            <?php foreach ($prescriptions as $prescription): ?>
                                <div class="record-card prescription-card" data-status="<?php echo $prescription['status']; ?>">
                                    <div class="record-header">
                                        <div class="record-date">
                                            <?php echo date('F j, Y', strtotime($prescription['prescription_date'])); ?>
                                            <?php if ($prescription['dispensed_date']): ?>
                                                - Dispensed: <?php echo date('M j, Y', strtotime($prescription['dispensed_date'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        <span class="record-status status-<?php echo str_replace('_', '-', $prescription['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $prescription['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="record-content">
                                        <?php if ($prescription['prescription_number']): ?>
                                            <div class="record-label">Prescription Number</div>
                                            <div class="record-value"><?php echo htmlspecialchars($prescription['prescription_number']); ?></div>
                                        <?php endif; ?>
                                        
                                        <div class="medication-name"><?php echo htmlspecialchars($prescription['medication_name']); ?></div>
                                        
                                        <?php if ($prescription['generic_name']): ?>
                                            <div class="record-label">Generic Name</div>
                                            <div class="record-value"><?php echo htmlspecialchars($prescription['generic_name']); ?></div>
                                        <?php endif; ?>
                                        
                                        <div class="dosage-info">
                                            <div><strong>Strength:</strong> <?php echo htmlspecialchars($prescription['dosage_strength']); ?></div>
                                            <div><strong>Form:</strong> <?php echo htmlspecialchars($prescription['dosage_form']); ?></div>
                                            <div><strong>Frequency:</strong> <?php echo htmlspecialchars($prescription['frequency']); ?></div>
                                            <div><strong>Duration:</strong> <?php echo htmlspecialchars($prescription['duration']); ?></div>
                                            <div><strong>Quantity:</strong> <?php echo $prescription['quantity_prescribed']; ?> 
                                                <?php if ($prescription['status'] != 'pending'): ?>
                                                    (Dispensed: <?php echo $prescription['quantity_dispensed']; ?>)
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($prescription['dosage_instructions']): ?>
                                            <div class="record-label">Instructions</div>
                                            <div class="record-value"><?php echo nl2br(htmlspecialchars($prescription['dosage_instructions'])); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($prescription['special_instructions']): ?>
                                            <div class="record-label">Special Instructions</div>
                                            <div class="record-value"><?php echo nl2br(htmlspecialchars($prescription['special_instructions'])); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($prescription['prescribed_by_name']): ?>
                                            <div class="record-label">Prescribed By</div>
                                            <div class="record-value">Dr. <?php echo htmlspecialchars($prescription['prescribed_by_name']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($prescription['dispensed_by_name']): ?>
                                            <div class="record-label">Dispensed By</div>
                                            <div class="record-value"><?php echo htmlspecialchars($prescription['dispensed_by_name']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($prescription['consultation_number']): ?>
                                            <div class="record-label">Related Consultation</div>
                                            <div class="record-value"><?php echo htmlspecialchars($prescription['consultation_number']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <!-- Empty Filter State -->
                            <div class="empty-state empty-filter-state" style="display: none;">
                                <i class="fas fa-filter"></i>
                                <h5>No Results Found</h5>
                                <p>No prescriptions match the selected filter.</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-pills"></i>
                                <h5>No Prescriptions</h5>
                                <p>Your prescribed medications will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Medical Certificates Tab -->
                    <div class="tab-content" id="certificates">
                        <?php if (!empty($certificates)): ?>
                            <!-- Filter Section -->
                            <div class="filter-section" id="certificates-filters">
                                <span class="filter-label"><i class="fas fa-filter me-2"></i>Filter by Status:</span>
                                <button class="filter-btn active" data-filter="all" onclick="filterItems('certificates', 'all')">All</button>
                                <button class="filter-btn" data-filter="pending" onclick="filterItems('certificates', 'pending')">Pending</button>
                                <button class="filter-btn" data-filter="approved_for_checkup" onclick="filterItems('certificates', 'approved_for_checkup')">Scheduled</button>
                                <button class="filter-btn" data-filter="completed_checkup" onclick="filterItems('certificates', 'completed_checkup')">Checked</button>
                                <button class="filter-btn" data-filter="ready_for_download" onclick="filterItems('certificates', 'ready_for_download')">Ready</button>
                                <button class="filter-btn" data-filter="downloaded" onclick="filterItems('certificates', 'downloaded')">Downloaded</button>
                            </div>
                            <div class="certificates-grid">
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
                                <div class="record-card certificate-card" data-status="<?php echo $certificate['status']; ?>">
                                    <div class="record-header">
                                        <div class="record-date">
                                            <?php echo date('F j, Y', strtotime($certificate['date_issued'])); ?>
                                        </div>
                                        <span class="record-status status-<?php echo str_replace('_', '-', $certificate['status']); ?>">
                                            <?php 
                                            $status_labels = [
                                                'pending' => 'Pending Review',
                                                'approved_for_checkup' => 'For Check-up',
                                                'completed_checkup' => 'Processing',
                                                'ready_for_download' => 'Ready',
                                                'downloaded' => 'Completed'
                                            ];
                                            echo $status_labels[$certificate['status']] ?? ucfirst($certificate['status']); 
                                            ?>
                                        </span>
                                    </div>
                                    <div class="record-content">
                                        <span class="certificate-number"><?php echo htmlspecialchars($certificate['certificate_number']); ?></span>
                                        
                                        <div class="record-label">Certificate Type</div>
                                        <div class="record-value"><?php echo htmlspecialchars($certificate['certificate_type']); ?></div>
                                        
                                        <div class="record-label">Purpose</div>
                                        <div class="record-value"><?php echo htmlspecialchars($certificate['purpose']); ?></div>
                                        
                                        <?php if ($certificate['valid_from'] && $certificate['valid_until']): ?>
                                            <div class="record-label">Valid Period</div>
                                            <div class="record-value">
                                                <?php echo date('M j, Y', strtotime($certificate['valid_from'])); ?> - 
                                                <?php echo date('M j, Y', strtotime($certificate['valid_until'])); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($certificate['diagnosis']): ?>
                                            <div class="record-label">Impressions</div>
                                            <div class="record-value"><?php echo htmlspecialchars($certificate['diagnosis']); ?></div>
                                        <?php endif; ?>

                                        <?php if ($certificate['fitness_status']): ?>
                                            <div class="record-label">Fitness Status</div>
                                            <div class="record-value">
                                                <span style="background: #28a745; color: white; padding: 0.3rem 0.8rem; border-radius: 15px; font-weight: 600; font-size: 0.9rem;">
                                                    <?php echo htmlspecialchars($certificate['fitness_status']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Action Buttons Row -->
                                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                            <!-- View Examination Details Button (if consultation exists) -->
                                            <?php if ($consultation): ?>
                                                <button onclick="viewExaminationDetails(<?php echo $certificate['id']; ?>)" 
                                                        style="flex: 1; background: #f8f9fa; color: #495057; border: 1px solid #dee2e6; padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem;">
                                                    <i class="fas fa-info-circle me-1"></i>Details
                                                </button>
                                            <?php endif; ?>

                                            <!-- Download Button for Ready Certificates -->
                                            <?php if ($certificate['status'] == 'ready_for_download' || $certificate['status'] == 'downloaded'): ?>
                                                <button onclick="window.open('../generate_certificate_pdf.php?id=<?php echo $certificate['id']; ?>', '_blank')" 
                                                        style="flex: 1; background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%); color: white; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem;">
                                                    <i class="fas fa-download me-1"></i>Download
                                                </button>
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
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <!-- Empty Filter State -->
                            <div class="empty-state empty-filter-state" style="display: none;">
                                <i class="fas fa-filter"></i>
                                <h5>No Results Found</h5>
                                <p>No certificates match the selected filter.</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-certificate"></i>
                                <h5>No Medical Certificates</h5>
                                <p>Your medical certificates will appear here when requested.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Referrals Tab -->
                    <div class="tab-content" id="referrals">
                        <?php if (!empty($referrals)): ?>
                            <!-- Filter Section -->
                            <div class="filter-section" id="referrals-filters">
                                <span class="filter-label"><i class="fas fa-filter me-2"></i>Filter by Status:</span>
                                <button class="filter-btn active" data-filter="all" onclick="filterItems('referrals', 'all')">All</button>
                                <button class="filter-btn" data-filter="pending" onclick="filterItems('referrals', 'pending')">Pending</button>
                                <button class="filter-btn" data-filter="sent" onclick="filterItems('referrals', 'sent')">Sent</button>
                                <button class="filter-btn" data-filter="accepted" onclick="filterItems('referrals', 'accepted')">Accepted</button>
                                <button class="filter-btn" data-filter="completed" onclick="filterItems('referrals', 'completed')">Completed</button>
                                <button class="filter-btn" data-filter="cancelled" onclick="filterItems('referrals', 'cancelled')">Cancelled</button>
                            </div>
                            <div class="referrals-grid">
                            <?php foreach ($referrals as $referral): ?>
                                <div class="record-card referral-card urgency-<?php echo $referral['urgency_level']; ?>" data-status="<?php echo $referral['status']; ?>">
                                    <div class="record-header">
                                        <div class="record-date">
                                            <?php echo date('F j, Y', strtotime($referral['referral_date'])); ?>
                                        </div>
                                        <span class="record-status status-<?php echo $referral['status']; ?>">
                                            <?php echo ucfirst($referral['status']); ?>
                                        </span>
                                    </div>
                                    <div class="record-content">
                                        <?php if ($referral['referral_number']): ?>
                                            <div class="record-label">Referral Number</div>
                                            <div class="record-value"><?php echo htmlspecialchars($referral['referral_number']); ?></div>
                                        <?php endif; ?>
                                        
                                        <div class="record-label">Referred To</div>
                                        <div class="record-value"><?php echo htmlspecialchars($referral['referred_to_facility']); ?></div>
                                        
                                        <?php if ($referral['referred_to_doctor']): ?>
                                            <div class="record-label">Specialist</div>
                                            <div class="record-value"><?php echo htmlspecialchars($referral['referred_to_doctor']); ?></div>
                                        <?php endif; ?>
                                        
                                        <div class="record-label">Urgency Level</div>
                                        <div class="record-value">
                                            <?php 
                                            $urgency_colors = [
                                                'urgent' => 'danger',    // Red
                                                'high' => 'warning',     // Yellow
                                                'routine' => 'success'   // Green
                                            ];
                                            $badge_color = $urgency_colors[$referral['urgency_level']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_color; ?>" style="font-size: 0.9rem; padding: 0.4rem 0.8rem;">
                                                <?php echo strtoupper($referral['urgency_level']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="record-label">Reason for Referral</div>
                                        <div class="record-value"><?php echo nl2br(htmlspecialchars($referral['referral_reason'])); ?></div>
                                        
                                        <?php if ($referral['diagnosis']): ?>
                                            <div class="record-label">Diagnosis</div>
                                            <div class="record-value"><?php echo htmlspecialchars($referral['diagnosis']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($referral['clinical_summary']): ?>
                                            <div class="record-label">Clinical Summary</div>
                                            <div class="record-value"><?php echo nl2br(htmlspecialchars($referral['clinical_summary'])); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($referral['referred_by_name']): ?>
                                            <div class="record-label">Referred By</div>
                                            <div class="record-value">Dr. <?php echo htmlspecialchars($referral['referred_by_name']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($referral['consultation_number']): ?>
                                            <div class="record-label">Related Consultation</div>
                                            <div class="record-value"><?php echo htmlspecialchars($referral['consultation_number']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($referral['feedback']): ?>
                                            <div class="record-label">Feedback</div>
                                            <div class="record-value"><?php echo nl2br(htmlspecialchars($referral['feedback'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Empty Filter State -->
                            <div class="empty-state empty-filter-state" style="display: none;">
                                <i class="fas fa-filter"></i>
                                <h5>No Results Found</h5>
                                <p>No referrals match the selected filter.</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-share"></i>
                                <h5>No Referrals</h5>
                                <p>Your referral records will appear here when available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Examination Details Modal -->
    <div class="modal fade" id="examinationDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 15px; border: none;">
                <div class="modal-header" style="background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%); color: white; border-radius: 15px 15px 0 0;">
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

    <!-- Lab Result Details Modal -->
    <div class="modal fade" id="labDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 15px; border: none;">
                <div class="modal-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title">
                        <i class="fas fa-flask me-2"></i>Laboratory Result Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div id="labDetailsContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Consultation Details Modal -->
    <div class="modal fade" id="consultationDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content" style="border-radius: 15px; border: none;">
                <div class="modal-header" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); color: white; border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title">
                        <i class="fas fa-stethoscope me-2"></i>Consultation Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div id="consultationDetailsContent"></div>
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

        // Filter functionality
        function filterItems(tabId, status) {
            const items = document.querySelectorAll(`#${tabId} .record-card`);
            
            items.forEach(item => {
                if (status === 'all') {
                    item.style.display = '';
                } else {
                    const itemStatus = item.dataset.status;
                    item.style.display = itemStatus === status ? '' : 'none';
                }
            });

            // Update active filter button
            const filterButtons = document.querySelectorAll(`#${tabId}-filters .filter-btn`);
            filterButtons.forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.filter === status) {
                    btn.classList.add('active');
                }
            });

            // Check if any items are visible
            const visibleItems = Array.from(items).filter(item => item.style.display !== 'none');
            const emptyState = document.querySelector(`#${tabId} .empty-filter-state`);
            
            if (visibleItems.length === 0 && emptyState) {
                emptyState.style.display = 'block';
            } else if (emptyState) {
                emptyState.style.display = 'none';
            }
        }

        // Tab switching functionality
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

        // Auto-refresh session check every 5 minutes
        setInterval(function() {
            fetch('Health_Record.php')
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

            html += '<h6 class="text-primary mb-3"><i class="fas fa-clipboard-check me-2"></i>Medical Assessment</h6>';

            // Chief Complaint
            if (data.chief_complaint) {
                html += '<div style="background: #fff3cd; padding: 0.8rem; border-radius: 8px; margin-bottom: 0.8rem; border-left: 4px solid #ffc107;">';
                html += '<strong>Chief Complaint:</strong> ' + data.chief_complaint;
                html += '</div>';
            }

            // Diagnosis with Smart Color
            if (data.diagnosis) {
                const diagnosisLower = data.diagnosis.toLowerCase();
                const isHealthy = diagnosisLower.includes('healthy') || 
                                diagnosisLower.includes('no illness') || 
                                diagnosisLower.includes('cleared') || 
                                diagnosisLower.includes('no acute') ||
                                diagnosisLower.includes('normal') ||
                                diagnosisLower.includes('fit');
                
                const bgColor = isHealthy ? '#d4edda' : '#f8d7da';
                const borderColor = isHealthy ? '#28a745' : '#dc3545';
                
                html += '<div style="background: ' + bgColor + '; padding: 0.8rem; border-radius: 8px; margin-bottom: 0.8rem; border-left: 4px solid ' + borderColor + ';">';
                html += '<strong>Medical Diagnosis:</strong> ' + data.diagnosis;
                html += '</div>';
            }
            if (data.fitness_status) html += `<div class="mb-2"><strong>Fitness Status:</strong> <span style="background: #28a745; color: white; padding: 0.3rem 0.8rem; border-radius: 15px; font-weight: 600;">${data.fitness_status}</span></div>`;
            if (data.restrictions) html += `<div class="mb-2"><strong>Restrictions:</strong> ${data.restrictions}</div>`;
            if (data.recommendations) html += `<div class="mb-2"><strong>Recommendations:</strong> ${data.recommendations.replace(/\n/g, '<br>')}</div>`;

            // Certificate-Specific Information
            
            if (data.impressions) html += `<div class="mb-2"><strong>IMPRESSIONS:</strong> ${data.impressions}</div>`;
            if (data.remarks) html += `<div class="mb-2"><strong>REMARKS:</strong> ${data.remarks}</div>`;

            // Certificate Validity
            if (data.valid_from && data.valid_until) {
                html += `<div class="mb-2"><strong>Certificate Validity:</strong> ${new Date(data.valid_from).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} to ${new Date(data.valid_until).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>`;
            }

            if (data.doctor_name) html += `<div class="mt-3 pt-3" style="border-top: 1px solid #dee2e6;"><strong>Examined By:</strong> Dr. ${data.doctor_name}</div>`;
            document.getElementById('examinationDetailsContent').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('examinationDetailsModal'));
            modal.show();
        }

            function printCertificate(certId) {
                window.open('../generate_certificate_pdf.php?id=' + certId, '_blank');
            }

            // View Lab Result Details
            function viewLabDetails(labId) {
                const dataElement = document.getElementById('lab-data-' + labId);
                if (!dataElement) {
                    alert('Lab result details not found');
                    return;
                }

                const data = JSON.parse(dataElement.textContent);
                const results = data.test_results ? JSON.parse(data.test_results) : {};
                
                let html = `
                    <div class="alert alert-info">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Lab Number:</strong> ${data.lab_number}<br>
                                <strong>Test Date:</strong> ${new Date(data.test_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                            </div>
                            <div class="col-md-6">
                                <strong>Test Type:</strong> ${data.test_type}<br>
                                <strong>Category:</strong> <span class="badge bg-info">${data.test_category}</span>
                            </div>
                        </div>
                        ${data.result_date ? `<div class="mt-2"><strong>Result Date:</strong> ${new Date(data.result_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>` : ''}
                    </div>
                `;

                if (data.specimen_type) {
                    html += `<div class="alert alert-secondary"><strong><i class="fas fa-vial me-2"></i>Specimen Type:</strong> ${data.specimen_type}</div>`;
                }

                // Test Results
                html += `<h6 class="text-primary mb-3"><i class="fas fa-clipboard-list me-2"></i>Test Results</h6>`;
                html += `<div style="background: #e3f2fd; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">`;
                if (Object.keys(results).length > 0) {
                    html += `<div class="row">`;
                    for (const [key, value] of Object.entries(results)) {
                        html += `<div class="col-md-6 mb-2"><strong>${key.replace(/_/g, ' ')}:</strong> ${value || '-'}</div>`;
                    }
                    html += `</div>`;
                } else {
                    html += `<p>No detailed results available</p>`;
                }
                html += `</div>`;

                // Normal Range
                if (data.normal_range) {
                    html += `<div style="background: #f8f9fa; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">`;
                    html += `<strong>Normal Range / Reference Values:</strong><br>${data.normal_range.replace(/\n/g, '<br>')}`;
                    html += `</div>`;
                }

                // Interpretation - Smart Color based on result
                if (data.interpretation) {
                    const interpLower = data.interpretation.toLowerCase();
                    const isNormal = interpLower.includes('normal') || 
                                    interpLower.includes('within range') || 
                                    interpLower.includes('negative') ||
                                    interpLower.includes('no abnormal');
                    const isAbnormal = interpLower.includes('abnormal') || 
                                    interpLower.includes('positive') || 
                                    interpLower.includes('elevated') ||
                                    interpLower.includes('high') ||
                                    interpLower.includes('low');
                    
                    let bgColor, borderColor;
                    if (isNormal) {
                        bgColor = '#d4edda';
                        borderColor = '#28a745';
                    } else if (isAbnormal) {
                        bgColor = '#f8d7da';
                        borderColor = '#dc3545';
                    } else {
                        bgColor = '#fff3cd';
                        borderColor = '#ffc107';
                    }
                    
                    html += '<div style="background: ' + bgColor + '; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border-left: 4px solid ' + borderColor + ';">';
                    html += '<strong><i class="fas fa-info-circle me-2"></i>Interpretation:</strong><br>' + data.interpretation.replace(/\n/g, '<br>');
                    html += '</div>';
                }

                // Remarks
                if (data.remarks) {
                    html += `<div style="background: #f8f9fa; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">`;
                    html += `<strong>Remarks / Notes:</strong><br>${data.remarks.replace(/\n/g, '<br>')}`;
                    html += `</div>`;
                }

                // Staff Information
                html += `<div class="border-top pt-3 mt-3"><div class="row">`;
                if (data.performed_by_name) html += `<div class="col-md-6"><strong>Performed By:</strong> ${data.performed_by_name}</div>`;
                if (data.verified_by_name) html += `<div class="col-md-6"><strong>Verified By:</strong> ${data.verified_by_name}</div>`;
                if (data.consultation_number) html += `<div class="col-md-12 mt-2"><strong>Related Consultation:</strong> ${data.consultation_number}</div>`;
                html += `</div></div>`;

                document.getElementById('labDetailsContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('labDetailsModal'));
                modal.show();
            }

            // View Consultation Details
            function viewConsultationDetails(consultId) {
                const dataElement = document.getElementById('consultation-data-' + consultId);
                if (!dataElement) {
                    alert('Consultation details not found');
                    return;
                }

                try {
                    // Decode HTML entities first
                    const jsonString = dataElement.textContent;
                    const textarea = document.createElement('textarea');
                    textarea.innerHTML = jsonString;
                    const decodedJson = textarea.value;
                    
                    const data = JSON.parse(decodedJson);
                    
                    let html = '<div class="alert alert-success"><div class="row">';
                    html += '<div class="col-md-4">';
                    html += '<strong>Consultation #:</strong> ' + (data.consultation_number || 'N/A') + '<br>';
                    html += '<strong>Date:</strong> ' + (data.consultation_date ? new Date(data.consultation_date).toLocaleString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'N/A');
                    html += '</div>';
                    html += '<div class="col-md-4"><strong>Type:</strong> <span class="badge bg-info">' + (data.consultation_type || 'N/A') + '</span></div>';
                    html += '<div class="col-md-4"><strong>Doctor:</strong> Dr. ' + (data.doctor_name || 'Not assigned') + '</div>';
                    html += '</div>';
                    if (data.consultation_location) {
                        html += '<div class="mt-2"><strong>Location:</strong> ' + data.consultation_location;
                        if (data.consultation_location_name) html += ' - ' + data.consultation_location_name;
                        html += '</div>';
                    }
                    html += '</div>';

                    // Chief Complaint
                    if (data.chief_complaint) {
                        html += '<div style="background: #fff3cd; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border-left: 4px solid #ffc107;">';
                        html += '<strong><i class="fas fa-exclamation-circle me-2"></i>Chief Complaint:</strong><br>' + data.chief_complaint;
                        html += '</div>';
                    }

                    // Vital Signs
                    if (data.temperature || data.bp_systolic || data.heart_rate) {
                        html += '<h6 class="text-primary mb-3"><i class="fas fa-heartbeat me-2"></i>Vital Signs</h6>';
                        html += '<div class="vital-signs mb-3"><div class="vital-signs-grid">';
                        if (data.temperature) html += '<div class="vital-item"><div class="vital-label">Temperature</div><div class="vital-value">' + data.temperature + '&deg;C</div></div>';
                        if (data.bp_systolic && data.bp_diastolic) html += '<div class="vital-item"><div class="vital-label">Blood Pressure</div><div class="vital-value">' + data.bp_systolic + '/' + data.bp_diastolic + '</div></div>';
                        if (data.heart_rate) html += '<div class="vital-item"><div class="vital-label">Heart Rate</div><div class="vital-value">' + data.heart_rate + ' bpm</div></div>';
                        if (data.respiratory_rate) html += '<div class="vital-item"><div class="vital-label">Respiratory</div><div class="vital-value">' + data.respiratory_rate + '/min</div></div>';
                        if (data.oxygen_saturation) html += '<div class="vital-item"><div class="vital-label">O2 Sat</div><div class="vital-value">' + data.oxygen_saturation + '%</div></div>';
                        if (data.weight) html += '<div class="vital-item"><div class="vital-label">Weight</div><div class="vital-value">' + data.weight + ' kg</div></div>';
                        if (data.height) html += '<div class="vital-item"><div class="vital-label">Height</div><div class="vital-value">' + data.height + ' cm</div></div>';
                        if (data.bmi) html += '<div class="vital-item"><div class="vital-label">BMI</div><div class="vital-value">' + data.bmi + '</div></div>';
                        html += '</div></div>';
                    }

                    // Physical Examination
                    if (data.general_appearance || data.heent_exam || data.respiratory_exam || data.cardiovascular_exam || data.abdomen_exam || data.musculoskeletal_exam) {
                        html += '<h6 class="text-primary mb-3"><i class="fas fa-user-md me-2"></i>Physical Examination</h6>';
                        html += '<div style="background: #f8f9fa; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">';
                        if (data.general_appearance) html += '<div class="mb-2"><strong>General Appearance:</strong> ' + data.general_appearance + '</div>';
                        if (data.heent_exam) html += '<div class="mb-2"><strong>HEENT:</strong> ' + data.heent_exam + '</div>';
                        if (data.respiratory_exam) html += '<div class="mb-2"><strong>Respiratory:</strong> ' + data.respiratory_exam + '</div>';
                        if (data.cardiovascular_exam) html += '<div class="mb-2"><strong>Cardiovascular:</strong> ' + data.cardiovascular_exam + '</div>';
                        if (data.abdomen_exam) html += '<div class="mb-2"><strong>Abdomen:</strong> ' + data.abdomen_exam + '</div>';
                        if (data.musculoskeletal_exam) html += '<div class="mb-2"><strong>Musculoskeletal:</strong> ' + data.musculoskeletal_exam + '</div>';
                        html += '</div>';
                    }

                    // Diagnosis & Treatment
                    html += '<h6 class="text-primary mb-3"><i class="fas fa-clipboard-check me-2"></i>Diagnosis & Treatment</h6>';

                    // Smart Diagnosis Color - Green for healthy, Red for illness
                    if (data.diagnosis) {
                        const diagnosisLower = data.diagnosis.toLowerCase();
                        const isHealthy = diagnosisLower.includes('healthy') || 
                                        diagnosisLower.includes('no illness') || 
                                        diagnosisLower.includes('cleared') || 
                                        diagnosisLower.includes('no acute') ||
                                        diagnosisLower.includes('normal') ||
                                        diagnosisLower.includes('fit') ||
                                        diagnosisLower.includes('well');
                        
                        const bgColor = isHealthy ? '#d4edda' : '#f8d7da';
                        const borderColor = isHealthy ? '#28a745' : '#dc3545';
                        
                        html += '<div style="background: ' + bgColor + '; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border-left: 4px solid ' + borderColor + ';">';
                        html += '<strong>Diagnosis:</strong><br>' + data.diagnosis;
                        html += '</div>';
                    }

                    // Treatment Plan - Yellow if exists (needs attention), Green if no treatment needed
                    if (data.treatment_plan) {
                        const treatmentLower = data.treatment_plan.toLowerCase();
                        const noTreatment = treatmentLower.includes('no treatment') || 
                                            treatmentLower.includes('none required') ||
                                            treatmentLower.includes('maintain healthy');
                        
                        const bgColor = noTreatment ? '#d4edda' : '#fff3cd';
                        const borderColor = noTreatment ? '#28a745' : '#ffc107';
                        
                        html += '<div style="background: ' + bgColor + '; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border-left: 4px solid ' + borderColor + ';">';
                        html += '<strong>Treatment Plan:</strong><br>' + data.treatment_plan.replace(/\n/g, '<br>');
                        html += '</div>';
                    }

                    // Recommendations - Always Blue (informational)
                    if (data.recommendations) {
                        html += '<div style="background: #d1ecf1; padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border-left: 4px solid #17a2b8;">';
                        html += '<strong>Recommendations:</strong><br>' + data.recommendations.replace(/\n/g, '<br>');
                        html += '</div>';
                    }
                    if (data.follow_up_date) {
                        html += '<div class="alert alert-info"><strong><i class="fas fa-calendar-check me-2"></i>Follow-up Date:</strong> ' + new Date(data.follow_up_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + '</div>';
                    }
                    if (data.remarks) {
                        html += '<div style="background: #f8f9fa; padding: 1rem; border-radius: 10px;"><strong>Remarks:</strong><br>' + data.remarks.replace(/\n/g, '<br>') + '</div>';
                    }

                    // Fitness & Restrictions
                    if (data.fitness_status || data.restrictions) {
                        html += '<div class="row mt-3">';
                        if (data.fitness_status) html += '<div class="col-md-6"><div class="alert alert-success"><strong>Fitness Status:</strong> ' + data.fitness_status + '</div></div>';
                        if (data.restrictions) html += '<div class="col-md-6"><div class="alert alert-warning"><strong>Restrictions:</strong> ' + data.restrictions + '</div></div>';
                        html += '</div>';
                    }

                    document.getElementById('consultationDetailsContent').innerHTML = html;
                    const modal = new bootstrap.Modal(document.getElementById('consultationDetailsModal'));
                    modal.show();
                } catch (error) {
                    console.error('Error loading consultation details:', error);
                    alert('Error loading consultation details: ' + error.message);
                }
            }
    </script>
</body>
</html>