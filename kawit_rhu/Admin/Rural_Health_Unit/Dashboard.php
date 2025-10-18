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

// Get today's statistics
$today = date('Y-m-d');

// Total patients registered today
$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = ?");
$stmt->execute([$today]);
$newPatientsToday = $stmt->fetchColumn();

// Total appointments today
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM appointments 
    WHERE appointment_date = ? AND status IN ('confirmed', 'completed', 'pending')
");
$stmt->execute([$today]);
$appointmentsToday = $stmt->fetchColumn();

// Consultations today
$stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = ?");
$stmt->execute([$today]);
$consultationsToday = $stmt->fetchColumn();

// Urgent referrals (today and pending)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM referrals 
    WHERE urgency_level IN ('urgent', 'emergency') 
    AND status IN ('pending', 'sent') 
    AND referral_date >= DATE_SUB(?, INTERVAL 7 DAY)
");
$stmt->execute([$today]);
$urgentReferrals = $stmt->fetchColumn();

// Pending lab results
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM laboratory_results 
    WHERE status IN ('pending', 'processing')
");
$stmt->execute();
$pendingLabResults = $stmt->fetchColumn();

// Pending prescriptions
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM prescriptions 
    WHERE status = 'pending' AND prescription_date >= DATE_SUB(?, INTERVAL 7 DAY)
");
$stmt->execute([$today]);
$pendingPrescriptions = $stmt->fetchColumn();

// Pending medical certificates
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM medical_certificates 
    WHERE status = 'pending' OR status = 'draft'
");
$stmt->execute();
$pendingCertificates = $stmt->fetchColumn();

// Get pending certificate requests
$stmt = $pdo->prepare("
    SELECT mc.*, p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           p.date_of_birth, p.gender, p.phone, p.address,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age
    FROM medical_certificates mc
    JOIN patients p ON mc.patient_id = p.id
    WHERE mc.status IN ('pending', 'draft')
    ORDER BY mc.date_issued DESC
    LIMIT 10
");
$stmt->execute();
$pendingCertificatesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming appointments
$stmt = $pdo->prepare("
    SELECT a.*, p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           st.service_name, CONCAT(s.first_name, ' ', s.last_name) as staff_name,
           b.barangay_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    LEFT JOIN service_types st ON a.service_type_id = st.id
    LEFT JOIN staff s ON a.assigned_staff = s.id
    LEFT JOIN barangays b ON a.barangay_id = b.id
    WHERE a.appointment_date >= ? AND a.status IN ('pending', 'confirmed')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 10
");
$stmt->execute([$today]);
$upcomingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent consultations
$stmt = $pdo->prepare("
    SELECT c.*, p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           CONCAT(s.first_name, ' ', s.last_name) as doctor_name
    FROM consultations c
    JOIN patients p ON c.patient_id = p.id
    LEFT JOIN staff s ON c.assigned_doctor = s.id
    WHERE DATE(c.consultation_date) = ?
    ORDER BY c.consultation_date DESC
    LIMIT 8
");
$stmt->execute([$today]);
$recentConsultations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get urgent referrals details
$stmt = $pdo->prepare("
    SELECT r.*, p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           CONCAT(s.first_name, ' ', s.last_name) as referred_by_name
    FROM referrals r
    JOIN patients p ON r.patient_id = p.id
    LEFT JOIN staff s ON r.referred_by = s.id
    WHERE r.urgency_level IN ('urgent', 'emergency') 
    AND r.status IN ('pending', 'sent')
    ORDER BY r.referral_date DESC, 
             CASE r.urgency_level WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END
    LIMIT 5
");
$stmt->execute();
$urgentReferralsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get low stock medicines
$stmt = $pdo->prepare("
    SELECT m.*, mc.category_name,
           CASE 
               WHEN m.stock_quantity <= 0 THEN 'Out of Stock'
               WHEN m.stock_quantity <= m.reorder_level THEN 'Critical Low'
               ELSE 'Low Stock'
           END as stock_status
    FROM medicines m
    LEFT JOIN medicine_categories mc ON m.category_id = mc.id
    WHERE m.stock_quantity <= m.reorder_level AND m.is_active = 1
    ORDER BY m.stock_quantity ASC
    LIMIT 5
");
$stmt->execute();
$lowStockMedicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent system activities
$stmt = $pdo->prepare("
    SELECT sl.*, u.username, CONCAT(s.first_name, ' ', s.last_name) as staff_name
    FROM system_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    LEFT JOIN staff s ON u.id = s.user_id
    WHERE sl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    AND sl.action IN ('APPOINTMENT_BOOKED', 'CONSULTATION_REQUEST', 'PATIENT_REGISTERED', 'PRESCRIPTION_CREATED')
    ORDER BY sl.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============= DATA ANALYTICS QUERIES =============

// Total Patients Overview
$totalPatientsStmt = $pdo->query("SELECT COUNT(*) FROM patients");
$totalPatients = $totalPatientsStmt->fetchColumn();

// Patients registered this month
$patientsThisMonthStmt = $pdo->prepare("
    SELECT COUNT(*) FROM patients 
    WHERE MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
");
$patientsThisMonthStmt->execute();
$patientsThisMonth = $patientsThisMonthStmt->fetchColumn();

// Consultations this month
$consultationsThisMonthStmt = $pdo->prepare("
    SELECT COUNT(*) FROM consultations 
    WHERE MONTH(consultation_date) = MONTH(CURDATE()) 
    AND YEAR(consultation_date) = YEAR(CURDATE())
");
$consultationsThisMonthStmt->execute();
$consultationsThisMonth = $consultationsThisMonthStmt->fetchColumn();

// Total appointments this month
$appointmentsThisMonthStmt = $pdo->prepare("
    SELECT COUNT(*) FROM appointments 
    WHERE MONTH(appointment_date) = MONTH(CURDATE()) 
    AND YEAR(appointment_date) = YEAR(CURDATE())
");
$appointmentsThisMonthStmt->execute();
$appointmentsThisMonth = $appointmentsThisMonthStmt->fetchColumn();

// Certificates issued this month
$certificatesIssuedStmt = $pdo->prepare("
    SELECT COUNT(*) FROM medical_certificates 
    WHERE status IN ('ready_for_download', 'downloaded')
    AND MONTH(date_issued) = MONTH(CURDATE()) 
    AND YEAR(date_issued) = YEAR(CURDATE())
");
$certificatesIssuedStmt->execute();
$certificatesIssued = $certificatesIssuedStmt->fetchColumn();

// Get patient demographics
$maleCountStmt = $pdo->query("SELECT COUNT(*) FROM patients WHERE gender = 'Male'");
$maleCount = $maleCountStmt->fetchColumn();

$femaleCountStmt = $pdo->query("SELECT COUNT(*) FROM patients WHERE gender = 'Female'");
$femaleCount = $femaleCountStmt->fetchColumn();

// Get top diagnoses
$topDiagnosesStmt = $pdo->prepare("
    SELECT diagnosis, COUNT(*) as count 
    FROM consultations 
    WHERE diagnosis IS NOT NULL AND diagnosis != ''
    AND MONTH(consultation_date) = MONTH(CURDATE())
    AND YEAR(consultation_date) = YEAR(CURDATE())
    GROUP BY diagnosis 
    ORDER BY count DESC 
    LIMIT 5
");
$topDiagnosesStmt->execute();
$topDiagnoses = $topDiagnosesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get weekly consultation trends (last 7 days)
$weeklyTrendsStmt = $pdo->prepare("
    SELECT DATE(consultation_date) as date, COUNT(*) as count
    FROM consultations
    WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(consultation_date)
    ORDER BY date ASC
");
$weeklyTrendsStmt->execute();
$weeklyTrends = $weeklyTrendsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get barangay distribution
$barangayStatsStmt = $pdo->prepare("
    SELECT b.barangay_name, COUNT(p.id) as patient_count
    FROM barangays b
    LEFT JOIN patients p ON b.id = p.barangay_id
    GROUP BY b.id, b.barangay_name
    ORDER BY patient_count DESC
    LIMIT 5
");
$barangayStatsStmt->execute();
$barangayStats = $barangayStatsStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly Trends (Last 6 Months) - For Line Graph
$monthlyTrendsStmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(consultation_date, '%b %Y') as month,
        COUNT(*) as consultations,
        MONTH(consultation_date) as month_num,
        YEAR(consultation_date) as year_num
    FROM consultations
    WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY YEAR(consultation_date), MONTH(consultation_date)
    ORDER BY year_num ASC, month_num ASC
");
$monthlyTrendsStmt->execute();
$monthlyTrends = $monthlyTrendsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get appointments for the same period
$monthlyAppointmentsStmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(appointment_date, '%b %Y') as month,
        COUNT(*) as appointments,
        MONTH(appointment_date) as month_num,
        YEAR(appointment_date) as year_num
    FROM appointments
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    AND status IN ('confirmed', 'completed')
    GROUP BY YEAR(appointment_date), MONTH(appointment_date)
    ORDER BY year_num ASC, month_num ASC
");
$monthlyAppointmentsStmt->execute();
$monthlyAppointments = $monthlyAppointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Merge the data for the graph
$monthlyData = [];
foreach ($monthlyTrends as $trend) {
    $key = $trend['month'];
    $monthlyData[$key] = [
        'month' => $key,
        'consultations' => $trend['consultations'],
        'appointments' => 0
    ];
}

foreach ($monthlyAppointments as $appt) {
    $key = $appt['month'];
    if (isset($monthlyData[$key])) {
        $monthlyData[$key]['appointments'] = $appt['appointments'];
    } else {
        $monthlyData[$key] = [
            'month' => $key,
            'consultations' => 0,
            'appointments' => $appt['appointments']
        ];
    }
}

// Age Distribution Data
$ageDistributionStmt = $pdo->query("
    SELECT 
        CASE 
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) < 18 THEN '0-17 (Pediatric)'
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 18 AND 30 THEN '18-30 (Young Adult)'
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 31 AND 45 THEN '31-45 (Adult)'
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 46 AND 60 THEN '46-60 (Middle Age)'
            ELSE '60+ (Senior)'
        END as age_group,
        COUNT(*) as count
    FROM patients
    GROUP BY age_group
    ORDER BY 
        CASE 
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) < 18 THEN 1
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 18 AND 30 THEN 2
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 31 AND 45 THEN 3
            WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 46 AND 60 THEN 4
            ELSE 5
        END
");
$ageDistribution = $ageDistributionStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RHU Admin Dashboard - Kawit RHU</title>
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
            margin-left: 280px;
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

        /* Statistics Cards */
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
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.8rem;
            font-weight: 600;
        }

        .stat-increase {
            color: #28a745;
        }

        .stat-decrease {
            color: #dc3545;
        }

        /* Content Sections */
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
            align-items: center;
            justify-content: between;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 10px;
            color: var(--dark-pink);
        }

        /* Tables */
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
            border-bottom: 2px solid var(--kawit-pink);
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-confirmed {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-urgent {
            background: #f8d7da;
            color: #721c24;
        }

        .status-emergency {
            background: #721c24;
            color: white;
        }

        .priority-high {
            background: #ffebee;
            color: #c62828;
        }

        .priority-urgent {
            background: #c62828;
            color: white;
        }

        .priority-medium {
            background: #fff3e0;
            color: #f57c00;
        }

        .priority-low {
            background: #e8f5e8;
            color: #2e7d32;
        }

        /* Alert Cards */
        .alert-card {
            border-left: 4px solid #dc3545;
            background: #fff5f5;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-card.warning {
            border-left-color: #ffc107;
            background: #fffbf0;
        }

        .alert-card.info {
            border-left-color: #17a2b8;
            background: #f0f9ff;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .quick-action-btn {
            background: var(--kawit-gradient);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }

        .quick-action-btn:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 122, 154, 0.4);
        }

        .quick-action-btn i {
            margin-right: 8px;
        }

        /* Charts placeholder */
        .chart-container {
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 10px;
            color: #6c757d;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
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
                flex-direction: column;
                text-align: center;
                padding: 1rem;
            }

            .nav-link i {
                margin: 0 0 0.5rem 0;
            }

            .dashboard-content {
                padding: 1rem;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .quick-actions {
                flex-direction: column;
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
                    <a href="Dashboard.php" class="nav-link active">
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
                    <a href="Appointments.php" class="nav-link">
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
                    <a href="../../logout.php" class="nav-link" onclick="return confirm('Are you sure you want to log out?')">
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
                <h1 class="page-title">RHU Dashboard</h1>
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
                <!-- Data Analytics Dashboard -->
                <div class="content-section" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-left: none;">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 style="color: white; margin: 0; font-size: 2rem; font-weight: 700;">
                                <i class="fas fa-chart-line me-3"></i>Health Analytics Dashboard
                            </h2>
                            <p style="margin: 0; opacity: 0.9; font-size: 1.1rem;">Kawit Rural Health Unit - Real-time Data Insights</p>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.9rem; opacity: 0.9;">Current Period</div>
                            <div style="font-size: 1.3rem; font-weight: 700;"><?php echo date('F Y'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Key Performance Metrics -->
                <div class="stats-row">
                    <!-- Total Patients -->
                    <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-left: none;">
                        <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number" style="color: white;"><?php echo number_format($totalPatients); ?></div>
                        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Total Registered Patients</div>
                        <div class="stat-change stat-increase">
                            <i class="fas fa-arrow-up me-1"></i><?php echo $patientsThisMonth; ?> this month
                        </div>
                    </div>

                    <!-- Consultations This Month -->
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border-left: none;">
                        <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                            <i class="fas fa-stethoscope"></i>
                        </div>
                        <div class="stat-number" style="color: white;"><?php echo number_format($consultationsThisMonth); ?></div>
                        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Consultations This Month</div>
                        <div class="stat-change" style="color: rgba(255,255,255,0.9);">
                            <i class="fas fa-calendar me-1"></i>Average: <?php echo $consultationsThisMonth > 0 ? round($consultationsThisMonth / date('j')) : 0; ?> per day
                        </div>
                    </div>

                    <!-- Appointments -->
                    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border-left: none;">
                        <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-number" style="color: white;"><?php echo number_format($appointmentsThisMonth); ?></div>
                        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Appointments This Month</div>
                        <div class="stat-change" style="color: rgba(255,255,255,0.9);">
                            <i class="fas fa-clock me-1"></i><?php echo $appointmentsToday; ?> scheduled today
                        </div>
                    </div>

                    <!-- Certificates Issued -->
                    <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; border-left: none;">
                        <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <div class="stat-number" style="color: white;"><?php echo number_format($certificatesIssued); ?></div>
                        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Certificates Issued</div>
                        <div class="stat-change stat-increase" style="color: rgba(255,255,255,0.9);">
                            <i class="fas fa-check-circle me-1"></i><?php echo $pendingCertificates; ?> pending approval
                        </div>
                    </div>
                </div>

                <!-- Analytics Sections -->
                <div class="row mb-4">
                    <!-- Patient Demographics -->
                    <div class="col-lg-4">
                        <div class="content-section">
                            <h5 style="color: var(--dark-pink); font-weight: 700; margin-bottom: 1.5rem;">
                                <i class="fas fa-users me-2"></i>Patient Demographics
                            </h5>
                            <div style="position: relative; height: 200px;">
                                <canvas id="genderChart"></canvas>
                            </div>
                            <div class="mt-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span><i class="fas fa-square text-primary me-2"></i>Male</span>
                                    <strong><?php echo number_format($maleCount); ?> (<?php echo $totalPatients > 0 ? round(($maleCount/$totalPatients)*100) : 0; ?>%)</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span><i class="fas fa-square text-danger me-2"></i>Female</span>
                                    <strong><?php echo number_format($femaleCount); ?> (<?php echo $totalPatients > 0 ? round(($femaleCount/$totalPatients)*100) : 0; ?>%)</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Diagnoses -->
                    <div class="col-lg-4">
                        <div class="content-section">
                            <h5 style="color: var(--dark-pink); font-weight: 700; margin-bottom: 1.5rem;">
                                <i class="fas fa-notes-medical me-2"></i>Top Diagnoses (This Month)
                            </h5>
                            <?php if (!empty($topDiagnoses)): ?>
                                <?php foreach ($topDiagnoses as $index => $diagnosis): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-truncate" style="max-width: 70%;">
                                                <?php echo ($index + 1) . '. ' . htmlspecialchars($diagnosis['diagnosis']); ?>
                                            </span>
                                            <strong style="color: var(--dark-pink);"><?php echo $diagnosis['count']; ?></strong>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar" style="width: <?php echo min(100, ($diagnosis['count'] / $topDiagnoses[0]['count']) * 100); ?>%; background: var(--kawit-gradient);"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-chart-bar fa-2x mb-2"></i>
                                    <p>No data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Weekly Consultation Trend -->
                    <div class="col-lg-4">
                        <div class="content-section">
                            <h5 style="color: var(--dark-pink); font-weight: 700; margin-bottom: 1.5rem;">
                                <i class="fas fa-chart-line me-2"></i>Weekly Consultation Trend
                            </h5>
                            <div style="position: relative; height: 200px;">
                                <canvas id="weeklyTrendChart"></canvas>
                            </div>
                            <div class="text-center mt-3">
                                <small class="text-muted">Last 7 days consultation activity</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Trends Graph (6 Months) -->
                <div class="content-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="color: var(--dark-pink); font-weight: 700;">
                            <i class="fas fa-chart-area me-2"></i>6-Month Health Service Trends
                        </h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary active" onclick="showTrendChart('combined')">Combined View</button>
                            <button type="button" class="btn btn-outline-primary" onclick="showTrendChart('consultations')">Consultations Only</button>
                            <button type="button" class="btn btn-outline-primary" onclick="showTrendChart('appointments')">Appointments Only</button>
                        </div>
                    </div>
                    <div style="position: relative; height: 350px;">
                        <canvas id="monthlyTrendsChart"></canvas>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div style="width: 20px; height: 20px; background: #FFA6BE; border-radius: 3px; margin-right: 10px;"></div>
                                <span><strong>Consultations:</strong> <?php echo array_sum(array_column($monthlyTrends, 'consultations')); ?> total in 6 months</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div style="width: 20px; height: 20px; background: #667eea; border-radius: 3px; margin-right: 10px;"></div>
                                <span><strong>Appointments:</strong> <?php echo array_sum(array_column($monthlyAppointments, 'appointments')); ?> total in 6 months</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Age Distribution Graph -->
                <div class="content-section">
                    <h5 style="color: var(--dark-pink); font-weight: 700; margin-bottom: 1.5rem;">
                        <i class="fas fa-users me-2"></i>Patient Age Distribution
                    </h5>
                    <div class="row">
                        <div class="col-lg-8">
                            <div style="position: relative; height: 300px;">
                                <canvas id="ageDistributionChart"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="mt-3">
                                <?php foreach ($ageDistribution as $index => $ageGroup): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span style="font-weight: 600;"><?php echo htmlspecialchars($ageGroup['age_group']); ?></span>
                                            <span style="font-size: 1.3rem; font-weight: 700; color: var(--dark-pink);">
                                                <?php echo number_format($ageGroup['count']); ?>
                                            </span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <?php 
                                            $maxCount = max(array_column($ageDistribution, 'count'));
                                            $percentage = ($ageGroup['count'] / $maxCount) * 100;
                                            $colors = ['#667eea', '#f093fb', '#4facfe', '#43e97b', '#FFA6BE'];
                                            ?>
                                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%; background: <?php echo $colors[$index % 5]; ?>;"></div>
                                        </div>
                                        <small class="text-muted"><?php echo round(($ageGroup['count'] / $totalPatients) * 100, 1); ?>% of total patients</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <!-- END Dashboard Content -->
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gender Distribution Chart
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?php echo $maleCount; ?>, <?php echo $femaleCount; ?>],
                    backgroundColor: ['#667eea', '#f093fb'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Weekly Trend Chart
        const weeklyCtx = document.getElementById('weeklyTrendChart').getContext('2d');
        new Chart(weeklyCtx, {
            type: 'line',
            data: {
                labels: [
                    <?php 
                    foreach ($weeklyTrends as $trend) {
                        echo "'" . date('M j', strtotime($trend['date'])) . "',";
                    }
                    ?>
                ],
                datasets: [{
                    label: 'Consultations',
                    data: [<?php foreach ($weeklyTrends as $trend) { echo $trend['count'] . ','; } ?>],
                    borderColor: '#FFA6BE',
                    backgroundColor: 'rgba(255, 166, 190, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Monthly Trends Chart (6 Months)
        let monthlyTrendsChart;
        const monthlyCtx = document.getElementById('monthlyTrendsChart').getContext('2d');

        const monthlyLabels = [
            <?php foreach ($monthlyData as $data): ?>
                '<?php echo $data['month']; ?>',
            <?php endforeach; ?>
        ];

        const consultationsData = [
            <?php foreach ($monthlyData as $data): ?>
                <?php echo $data['consultations']; ?>,
            <?php endforeach; ?>
        ];

        const appointmentsData = [
            <?php foreach ($monthlyData as $data): ?>
                <?php echo $data['appointments']; ?>,
            <?php endforeach; ?>
        ];

        monthlyTrendsChart = new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [
                    {
                        label: 'Consultations',
                        data: consultationsData,
                        borderColor: '#FFA6BE',
                        backgroundColor: 'rgba(255, 166, 190, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 3
                    },
                    {
                        label: 'Appointments',
                        data: appointmentsData,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Function to toggle chart views
        function showTrendChart(view) {
            // Update button states
            document.querySelectorAll('.btn-group button').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');

            // Update chart visibility
            if (view === 'combined') {
                monthlyTrendsChart.data.datasets[0].hidden = false;
                monthlyTrendsChart.data.datasets[1].hidden = false;
            } else if (view === 'consultations') {
                monthlyTrendsChart.data.datasets[0].hidden = false;
                monthlyTrendsChart.data.datasets[1].hidden = true;
            } else if (view === 'appointments') {
                monthlyTrendsChart.data.datasets[0].hidden = true;
                monthlyTrendsChart.data.datasets[1].hidden = false;
            }
            monthlyTrendsChart.update();
        }

        // Age Distribution Chart
        const ageCtx = document.getElementById('ageDistributionChart').getContext('2d');
        new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: [
                    <?php foreach ($ageDistribution as $age): ?>
                        '<?php echo $age['age_group']; ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Number of Patients',
                    data: [
                        <?php foreach ($ageDistribution as $age): ?>
                            <?php echo $age['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        '#667eea',
                        '#f093fb',
                        '#4facfe',
                        '#43e97b',
                        '#FFA6BE'
                    ],
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Session check
        window.onload = function() {
            <?php if (!isset($_SESSION['user_id'])): ?>
                window.location.href = '../../login.php';
            <?php endif; ?>
        };
    </script>
</body>
</html>