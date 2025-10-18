<?php
session_start();

// Check if user is logged in (admin OR patient)
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Allow both admin and patient to print certificates
$allowed_roles = ['rhu_admin', 'patient'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    die("Unauthorized access");
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

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$query = "
    SELECT mc.*, p.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           p.date_of_birth, p.gender, p.phone,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age,
           CONCAT(s.first_name, ' ', s.last_name) as issued_by_name
    FROM medical_certificates mc
    JOIN patients p ON mc.patient_id = p.id
    LEFT JOIN staff s ON mc.issued_by = s.id
    WHERE 1=1
";

// Get list of available doctors
$doctorsStmt = $pdo->prepare("
    SELECT id, CONCAT(first_name, ' ', last_name) as doctor_name, position
    FROM staff 
    WHERE department = 'RHU' 
    AND (position LIKE '%doctor%' OR position LIKE '%physician%' OR position LIKE '%officer%')
    AND employment_status = 'Active'
    ORDER BY last_name, first_name
");
$doctorsStmt->execute();
$doctors = $doctorsStmt->fetchAll(PDO::FETCH_ASSOC);

$params = [];

if ($filter_status) {
    $query .= " AND mc.status = ?";
    $params[] = $filter_status;
}

if ($filter_type) {
    $query .= " AND mc.certificate_type = ?";
    $params[] = $filter_type;
}

if ($search) {
    $query .= " AND (p.patient_id LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR mc.certificate_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " ORDER BY 
    CASE mc.status 
        WHEN 'pending' THEN 1 
        WHEN 'approved_for_checkup' THEN 2
        WHEN 'completed_checkup' THEN 3
        WHEN 'ready_for_download' THEN 4
        WHEN 'downloaded' THEN 5
        ELSE 6 
    END,
    mc.date_issued DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'approved_for_checkup' THEN 1 END) as scheduled,
        COUNT(CASE WHEN status = 'completed_checkup' THEN 1 END) as for_processing,
        COUNT(CASE WHEN status = 'ready_for_download' THEN 1 END) as issued,
        COUNT(CASE WHEN DATE(date_issued) = CURDATE() THEN 1 END) as today
    FROM medical_certificates
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Certificates - RHU Admin</title>
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
        .status-approved_for_checkup { background: #d1ecf1; color: #0c5460; }
        .status-completed_checkup { background: #cce5ff; color: #004085; }
        .status-ready_for_download { background: #d4edda; color: #155724; }
        .status-downloaded { background: #e2e3e5; color: #383d41; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .status-expired { background: #f8d7da; color: #721c24; }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
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
                    <a href="Medical_Certificates.php" class="nav-link active">
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
                <h1 class="page-title">Medical Certificates</h1>
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

                <!-- Filters and Search -->
                <div class="content-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-filter"></i>Filter & Search
                        </h3>
                    </div>
                    
                    <form method="GET" action="">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" onchange="this.form.submit()">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending Verification</option>
                                    <option value="approved_for_checkup" <?php echo $filter_status == 'approved_for_checkup' ? 'selected' : ''; ?>>Approved (For Check-up)</option>
                                    <option value="completed_checkup" <?php echo $filter_status == 'completed_checkup' ? 'selected' : ''; ?>>Completed Check-up</option>
                                    <option value="ready_for_download" <?php echo $filter_status == 'ready_for_download' ? 'selected' : ''; ?>>Ready for Download</option>
                                    <option value="downloaded" <?php echo $filter_status == 'downloaded' ? 'selected' : ''; ?>>Downloaded</option>
                                    <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="expired" <?php echo $filter_status == 'expired' ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Certificate Type</label>
                                <select class="form-select" name="type" onchange="this.form.submit()">
                                    <option value="">All Types</option>
                                    <option value="Medical Certificate" <?php echo $filter_type == 'Medical Certificate' ? 'selected' : ''; ?>>Medical Certificate</option>
                                    <option value="Fit to Work Certificate" <?php echo $filter_type == 'Fit to Work Certificate' ? 'selected' : ''; ?>>Fit to Work</option>
                                    <option value="Health Certificate" <?php echo $filter_type == 'Health Certificate' ? 'selected' : ''; ?>>Health Certificate</option>
                                    <option value="Vaccination Certificate" <?php echo $filter_type == 'Vaccination Certificate' ? 'selected' : ''; ?>>Vaccination</option>
                                    <option value="Medical Clearance" <?php echo $filter_type == 'Medical Clearance' ? 'selected' : ''; ?>>Medical Clearance</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search" placeholder="Certificate #, Patient ID, or Name" value="<?php echo htmlspecialchars($search); ?>">
                                    <button class="btn btn-primary" type="submit">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                    <?php if ($filter_status || $filter_type || $search): ?>
                                        <a href="Medical_Certificates.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Clear
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Certificates List -->
                <div class="content-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-list"></i>All Medical Certificates
                        </h3>
                    </div>
                    
                    <?php if (!empty($certificates)): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Certificate #</th>
                                        <th>Date Requested</th>
                                        <th>Patient</th>
                                        <th>Type</th>
                                        <th>Purpose</th>
                                        <th>Fitness Status</th>
                                        <th>Status</th>
                                        <th>Issued By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($certificates as $cert): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($cert['certificate_number']); ?></strong></td>
                                            <td><?php echo date('M j, Y', strtotime($cert['date_issued'])); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($cert['patient_name']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($cert['patient_id']); ?></small><br>
                                                <small class="text-muted"><?php echo $cert['age']; ?> yrs, <?php echo $cert['gender']; ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($cert['certificate_type']); ?></td>
                                            <td><?php echo htmlspecialchars($cert['purpose']); ?></td>
                                            <td>
                                                <?php if ($cert['fitness_status']): ?>
                                                    <strong><?php echo htmlspecialchars($cert['fitness_status']); ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">Not evaluated</span>
                                                <?php endif; ?>
                                            </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $cert['status']; ?>">
                                                        <?php 
                                                        $status_labels = [
                                                            'pending' => 'Pending Verification',
                                                            'approved_for_checkup' => 'Approved - For Check-up',
                                                            'completed_checkup' => 'Check-up Completed',
                                                            'ready_for_download' => 'Ready for Download',
                                                            'downloaded' => 'Downloaded',
                                                            'cancelled' => 'Cancelled',
                                                            'expired' => 'Expired'
                                                        ];
                                                        echo $status_labels[$cert['status']] ?? ucfirst(str_replace('_', ' ', $cert['status']));
                                                        ?>
                                                    </span>
                                                </td>
                                            <td><?php echo $cert['issued_by_name'] ? 'Dr. ' . htmlspecialchars($cert['issued_by_name']) : 'Not yet issued'; ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewCertificate(<?php echo $cert['id']; ?>)" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <?php if ($cert['status'] == 'pending'): ?>
                                                        <button class="btn btn-sm btn-success" 
                                                                onclick="approveForCheckup(<?php echo $cert['id']; ?>, '<?php echo htmlspecialchars($cert['patient_name']); ?>', '<?php echo htmlspecialchars($cert['certificate_number']); ?>')" 
                                                                title="Approve & Schedule">
                                                            <i class="fas fa-calendar-check"></i> Schedule
                                                        </button>
                                                    <?php elseif ($cert['status'] == 'approved_for_checkup'): ?>
                                                        <button class="btn btn-sm btn-info text-white" 
                                                                onclick="markCheckupCompleted(<?php echo $cert['id']; ?>)" 
                                                                title="Mark Check-up as Completed">
                                                            <i class="fas fa-clipboard-check"></i> Complete
                                                        </button>
                                                    <?php elseif ($cert['status'] == 'completed_checkup'): ?>
                                                        <button class="btn btn-sm btn-warning" 
                                                                onclick="processCertificate(<?php echo $cert['id']; ?>, '<?php echo htmlspecialchars($cert['patient_name']); ?>', '<?php echo htmlspecialchars($cert['certificate_number']); ?>')" 
                                                                title="Issue Certificate">
                                                            <i class="fas fa-file-signature"></i> Issue
                                                        </button>
                                                    <?php elseif ($cert['status'] == 'ready_for_download' || $cert['status'] == 'downloaded'): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" onclick="printCertificate(<?php echo $cert['id']; ?>)" title="Print">
                                                            <i class="fas fa-print"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-certificate fa-3x mb-3"></i>
                            <p>No certificates found matching your filters</p>
                            <?php if ($filter_status || $filter_type || $search): ?>
                                <a href="Medical_Certificates.php" class="btn btn-primary">Clear Filters</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Process Medical Certificate Modal -->
    <div class="modal fade" id="processCertificateModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-certificate me-2"></i>Process Medical Certificate
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Modal Alert Area -->
                    <div id="modalAlertMessage" style="display: none;"></div>
                    
                    <form id="certificateForm">
                        <input type="hidden" id="cert_id">
                        <input type="hidden" id="cert_patient_id">
                        
                        <!-- Certificate Info Display -->
                        <div class="alert alert-info mb-3">
                            <strong>Certificate #:</strong> <span id="cert_number"></span><br>
                            <strong>Type:</strong> <span id="cert_type"></span><br>
                            <strong>Purpose:</strong> <span id="cert_purpose"></span>
                        </div>

                        <!-- Editable Patient Information -->
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-user-edit me-2"></i>Patient Information (Editable for Certificate)</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Patient Full Name *</label>
                                        <input type="text" class="form-control" id="patient_full_name" required>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Age *</label>
                                        <input type="number" class="form-control" id="patient_age" required>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Gender *</label>
                                        <select class="form-select" id="patient_gender" required>
                                            <option value="">Select</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Examination Date *</label>
                                        <input type="date" class="form-control" id="examination_date" required value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address *</label>
                                    <input type="text" class="form-control" id="patient_address" required>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 1: VITAL SIGNS -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-heartbeat me-2"></i>Vital Signs
                            </h6>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Temperature (°C)</label>
                                    <input type="number" step="0.1" class="form-control" id="temperature" placeholder="36.5">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Blood Pressure</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="bp_systolic" placeholder="120">
                                        <span class="input-group-text">/</span>
                                        <input type="number" class="form-control" id="bp_diastolic" placeholder="80">
                                    </div>
                                    <small class="text-muted">Systolic / Diastolic</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Heart Rate (bpm)</label>
                                    <input type="number" class="form-control" id="heart_rate" placeholder="72">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Respiratory Rate</label>
                                    <input type="number" class="form-control" id="respiratory_rate" placeholder="16">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Oxygen Saturation (%)</label>
                                    <input type="number" class="form-control" id="oxygen_saturation" placeholder="98">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Weight (kg)</label>
                                    <input type="number" step="0.1" class="form-control" id="weight" placeholder="65">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Height (cm)</label>
                                    <input type="number" step="0.1" class="form-control" id="height" placeholder="165">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">BMI</label>
                                    <input type="text" class="form-control" id="bmi" readonly placeholder="Auto-calculated">
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 2: PHYSICAL EXAMINATION -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-stethoscope me-2"></i>Physical Examination
                            </h6>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">General Appearance</label>
                                    <input type="text" class="form-control" id="general_appearance" placeholder="e.g., Alert, well-nourished, no distress">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">HEENT (Head, Eyes, Ears, Nose, Throat)</label>
                                    <textarea class="form-control" id="heent_exam" rows="2" placeholder="e.g., No abnormalities, pupils equal and reactive"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Respiratory (Lungs)</label>
                                    <textarea class="form-control" id="respiratory_exam" rows="2" placeholder="e.g., Clear breath sounds bilaterally, no wheezing"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Cardiovascular (Heart)</label>
                                    <textarea class="form-control" id="cardiovascular_exam" rows="2" placeholder="e.g., Regular rhythm, no murmurs"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Abdomen</label>
                                    <textarea class="form-control" id="abdomen_exam" rows="2" placeholder="e.g., Soft, non-tender, no masses"></textarea>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Musculoskeletal (Movement & Posture)</label>
                                    <input type="text" class="form-control" id="musculoskeletal_exam" placeholder="e.g., Full range of motion, no deformities">
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 3: ASSESSMENT & DIAGNOSIS -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-clipboard-check me-2"></i>Medical Assessment
                            </h6>
                            <div class="mb-3">
                                <label class="form-label">Chief Complaint / Reason for Certificate *</label>
                                <input type="text" class="form-control" id="chief_complaint" required placeholder="e.g., Fit-to-work clearance, Return to school">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Medical Findings / Diagnosis *</label>
                                <textarea class="form-control" id="diagnosis" rows="3" required placeholder="e.g., No acute illness detected, Healthy for work/school"></textarea>
                            </div>
                            
                            <!-- FOR CERTIFICATE DISPLAY -->
                            <hr style="margin: 2rem 0; border-color: var(--kawit-pink);">
                            <h6 class="text-success mb-3">
                                <i class="fas fa-file-medical me-2"></i>Certificate Content (What appears on printed certificate)
                            </h6>
                            
                            <div class="mb-3">
                                <label class="form-label">IMPRESSIONS (Summary for certificate) *</label>
                                <textarea class="form-control" id="impressions" rows="2" required placeholder="e.g., No significant findings. Patient is healthy and cleared for normal activities."></textarea>
                                <small class="text-muted">This will appear on the printed certificate</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">RECOMMENDATIONS (Suggestions) *</label>
                                <textarea class="form-control" id="recommendations" rows="2" required placeholder="e.g., Maintain healthy lifestyle. Follow-up if symptoms persist."></textarea>
                                <small class="text-muted">This will appear on the printed certificate</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fitness Status *</label>
                                    <select class="form-select" id="fitness_status" required>
                                        <option value="">Select fitness status</option>
                                        <option value="Fit">Fit - No restrictions</option>
                                        <option value="Fit with Restrictions">Fit with Restrictions</option>
                                        <option value="Unfit">Unfit - Not cleared</option>
                                        <option value="Pending Further Evaluation">Pending Further Evaluation</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Restrictions (if any)</label>
                                    <input type="text" class="form-control" id="restrictions" placeholder="e.g., No heavy lifting, Rest for 3 days">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">REMARKS (Additional notes for certificate)</label>
                                <textarea class="form-control" id="remarks" rows="2" placeholder="e.g., Patient cleared for normal activities with no restrictions"></textarea>
                                <small class="text-muted">This will appear on the printed certificate under REMARKS section</small>
                            </div>
                        </div>

                        <!-- SECTION 4: CERTIFICATE VALIDITY -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-calendar-alt me-2"></i>Certificate Validity
                            </h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Valid From *</label>
                                    <input type="date" class="form-control" id="valid_from" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Valid Until *</label>
                                    <input type="date" class="form-control" id="valid_until" required>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Fill Button -->
                        <div class="text-center mb-3">
                            <button type="button" class="btn btn-outline-success" onclick="markAsFit()">
                                <i class="fas fa-check-circle me-2"></i>Quick Fill: Mark as Fit (No Issues)
                            </button>
                            <small class="d-block text-muted mt-2">Fills all fields with "healthy/no abnormalities" findings</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="approveCertificate">
                        <i class="fas fa-check me-2"></i>Issue Certificate
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentCertificateId = null;

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

        // Modal-specific alert function
        function showModalAlert(message, type = 'success') {
            const modalAlertDiv = document.getElementById('modalAlertMessage');
            modalAlertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            modalAlertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            modalAlertDiv.style.display = 'block';
            
            // Scroll to top of modal
            document.querySelector('#processCertificateModal .modal-body').scrollTop = 0;
            
            // Auto-hide after 5 seconds
            setTimeout(() => { 
                modalAlertDiv.style.display = 'none'; 
            }, 5000);
        }

        // Process Medical Certificate
        function processCertificate(certId, patientName, certNumber) {
            fetch('get_certificate_details.php?id=' + certId)
                .then(response => response.json())
                .then(data => {
                if (data.success) {
                    const cert = data.certificate;
                    document.getElementById('cert_id').value = certId;
                    document.getElementById('cert_patient_id').value = cert.patient_db_id;
                    document.getElementById('cert_number').textContent = certNumber;
                    document.getElementById('cert_type').textContent = cert.certificate_type;
                    document.getElementById('cert_purpose').textContent = cert.purpose;
                    
                    // Fill editable patient info
                    document.getElementById('patient_full_name').value = cert.patient_name;
                    document.getElementById('patient_age').value = cert.age;
                    document.getElementById('patient_gender').value = cert.gender;
                    document.getElementById('patient_address').value = cert.address;
                    document.getElementById('examination_date').value = '<?php echo date('Y-m-d'); ?>';

                        // Store patient data
                        window.currentPatientData = cert;
                        
                        // Set default validity period (1 year for most certificates)
                        const today = new Date();
                        const nextYear = new Date(today);
                        nextYear.setFullYear(today.getFullYear() + 1);
                        document.getElementById('valid_until').value = nextYear.toISOString().split('T')[0];
                        
                        // Clear all form fields
                        document.getElementById('temperature').value = '';
                        document.getElementById('bp_systolic').value = '';
                        document.getElementById('bp_diastolic').value = '';
                        document.getElementById('heart_rate').value = '';
                        document.getElementById('respiratory_rate').value = '';
                        document.getElementById('oxygen_saturation').value = '';
                        document.getElementById('weight').value = '';
                        document.getElementById('height').value = '';
                        document.getElementById('bmi').value = '';
                        document.getElementById('general_appearance').value = '';
                        document.getElementById('heent_exam').value = '';
                        document.getElementById('respiratory_exam').value = '';
                        document.getElementById('cardiovascular_exam').value = '';
                        document.getElementById('abdomen_exam').value = '';
                        document.getElementById('musculoskeletal_exam').value = '';
                        document.getElementById('chief_complaint').value = '';
                        document.getElementById('diagnosis').value = '';
                        document.getElementById('fitness_status').value = '';
                        document.getElementById('restrictions').value = '';
                        document.getElementById('recommendations').value = '';
                        
                        // Clear any previous modal alerts
                        document.getElementById('modalAlertMessage').style.display = 'none';

                        const modal = new bootstrap.Modal(document.getElementById('processCertificateModal'));
                        modal.show();
                    } else {
                        showAlert('Error loading certificate details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Error loading certificate');
                });
        }

        // View Certificate Details
        function viewCertificate(certId) {
            currentCertificateId = certId;
            const modal = new bootstrap.Modal(document.getElementById('viewCertificateModal'));
            const content = document.getElementById('viewCertificateContent');
            
            content.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading...</div>';
            modal.show();

            fetch('get_certificate_details.php?id=' + certId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cert = data.certificate;
                        
                        content.innerHTML = `
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <strong>Certificate #:</strong> ${cert.certificate_number}<br>
                                        <strong>Status:</strong> <span class="status-badge status-${cert.status}">${cert.status}</span><br>
                                        <strong>Date Requested:</strong> ${new Date(cert.date_issued).toLocaleDateString()}
                                    </div>
                                    
                                    <h6><i class="fas fa-user me-2"></i>Patient Information</h6>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <strong>Name:</strong> ${cert.patient_name}<br>
                                            <strong>Patient ID:</strong> ${cert.patient_id}<br>
                                            <strong>Age/Gender:</strong> ${cert.age} years / ${cert.gender}<br>
                                            <strong>Address:</strong> ${cert.address}<br>
                                            <strong>Phone:</strong> ${cert.phone || 'Not provided'}
                                        </div>
                                    </div>
                                    
                                    <h6><i class="fas fa-certificate me-2"></i>Certificate Details</h6>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <strong>Type:</strong> ${cert.certificate_type}<br>
                                            <strong>Purpose:</strong> ${cert.purpose}<br>
                                            ${cert.valid_from ? `<strong>Valid From:</strong> ${new Date(cert.valid_from).toLocaleDateString()}<br>` : ''}
                                            ${cert.valid_until ? `<strong>Valid Until:</strong> ${new Date(cert.valid_until).toLocaleDateString()}<br>` : ''}
                                        </div>
                                    </div>
                                    
                                    ${cert.diagnosis || cert.physical_findings || cert.recommendations ? `
                                    <h6><i class="fas fa-notes-medical me-2"></i>Medical Findings</h6>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            ${cert.diagnosis ? `<strong>Diagnosis:</strong><br>${cert.diagnosis}<br><br>` : ''}
                                            ${cert.physical_findings ? `<strong>Physical Findings:</strong><br>${cert.physical_findings}<br><br>` : ''}
                                            ${cert.recommendations ? `<strong>Recommendations:</strong><br>${cert.recommendations}<br><br>` : ''}
                                            ${cert.restrictions ? `<strong>Restrictions:</strong><br>${cert.restrictions}<br><br>` : ''}
                                            ${cert.fitness_status ? `<strong>Fitness Status:</strong> <span class="badge bg-success">${cert.fitness_status}</span>` : ''}
                                        </div>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                    } else {
                        content.innerHTML = '<div class="alert alert-danger">Error loading certificate details</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = '<div class="alert alert-danger">Error loading certificate details</div>';
                });
        }

        // Print Certificate
        function printCertificate(certId) {
            window.open('print_certificate.php?id=' + certId, '_blank');
        }

        function printCertificateFromView() {
            if (currentCertificateId) {
                printCertificate(currentCertificateId);
            }
        }

        // Approve certificate and schedule check-up
        function approveForCheckup(certId, patientName, certNumber) {
            if (!confirm('Approve this certificate request and schedule check-up for ' + patientName + '?')) {
                return;
            }
            
            // Show date picker modal
            const datePickerHTML = `
                <div class="modal fade" id="scheduleModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-calendar-check me-2"></i>Schedule Check-up
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p><strong>Certificate:</strong> ${certNumber}</p>
                                <p><strong>Patient:</strong> ${patientName}</p>
                                <hr>
                                <div class="mb-3">
                                    <label class="form-label">Check-up Date *</label>
                                    <input type="date" class="form-control" id="checkup_date" min="${new Date().toISOString().split('T')[0]}" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Check-up Time</label>
                                    <input type="time" class="form-control" id="checkup_time" value="09:00">
                                    <small class="text-muted">Leave as is if flexible. Admin can adjust later.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Instructions for Patient</label>
                                    <textarea class="form-control" id="checkup_notes" rows="3" placeholder="e.g., Bring valid ID, wear comfortable clothing">Please bring a valid ID and arrive 10 minutes early.</textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Assign Doctor *</label>
                                    <select class="form-select" id="assigned_doctor" required>
                                        <option value="">Select Doctor</option>
                                        <?php foreach ($doctors as $doc): ?>
                                            <option value="<?php echo $doc['id']; ?>">
                                                Dr. <?php echo htmlspecialchars($doc['doctor_name']); ?> - <?php echo htmlspecialchars($doc['position']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-success" onclick="submitSchedule(${certId})">
                                    <i class="fas fa-check me-2"></i>Approve & Schedule
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('scheduleModal');
            if (existingModal) existingModal.remove();
            
            // Add new modal
            document.body.insertAdjacentHTML('beforeend', datePickerHTML);
            const modal = new bootstrap.Modal(document.getElementById('scheduleModal'));
            modal.show();
        }

        function submitSchedule(certId) {
            const checkupDate = document.getElementById('checkup_date').value;
            const checkupTime = document.getElementById('checkup_time').value;
            const checkupNotes = document.getElementById('checkup_notes').value;
            const assignedDoctor = document.getElementById('assigned_doctor').value;
            
            if (!checkupDate) {
                alert('Please select a check-up date');
                return;
            }
            
            if (!assignedDoctor) {
                alert('Please assign a doctor');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'approve_for_checkup');
            formData.append('certificate_id', certId);
            formData.append('checkup_date', checkupDate);
            formData.append('checkup_time', checkupTime);
            formData.append('checkup_notes', checkupNotes);
            formData.append('assigned_doctor_id', assignedDoctor);
            
            fetch('process_certificate_action.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('scheduleModal')).hide();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }

        // Mark check-up as completed
        function markCheckupCompleted(certId) {
            if (!confirm('Mark check-up as completed? This means the patient has visited and been examined.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'complete_checkup');
            formData.append('certificate_id', certId);
            
            fetch('process_certificate_action.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }

        // Auto-calculate BMI
        function calculateBMI() {
            const weight = parseFloat(document.getElementById('weight').value);
            const heightCm = parseFloat(document.getElementById('height').value);
            
            if (weight && heightCm) {
                const heightM = heightCm / 100;
                const bmi = (weight / (heightM * heightM)).toFixed(1);
                document.getElementById('bmi').value = bmi;
            } else {
                document.getElementById('bmi').value = '';
            }
        }

        // Approve and issue certificate
        document.addEventListener('DOMContentLoaded', function() {
            // Add BMI calculation listeners
            const weightInput = document.getElementById('weight');
            const heightInput = document.getElementById('height');
            if (weightInput) weightInput.addEventListener('input', calculateBMI);
            if (heightInput) heightInput.addEventListener('input', calculateBMI);

            const approveBtn = document.getElementById('approveCertificate');
            if (approveBtn) {
                approveBtn.addEventListener('click', function() {
                    const certId = document.getElementById('cert_id').value;
                    const patientId = document.getElementById('cert_patient_id').value;
                    
                    // Patient Info
                    const patientFullName = document.getElementById('patient_full_name').value;
                    const patientAge = document.getElementById('patient_age').value;
                    const patientGender = document.getElementById('patient_gender').value;
                    const patientAddress = document.getElementById('patient_address').value;
                    const examinationDate = document.getElementById('examination_date').value;
                    
                    // Vital Signs
                    const temperature = document.getElementById('temperature').value;
                    const bpSystolic = document.getElementById('bp_systolic').value;
                    const bpDiastolic = document.getElementById('bp_diastolic').value;
                    const heartRate = document.getElementById('heart_rate').value;
                    const respiratoryRate = document.getElementById('respiratory_rate').value;
                    const oxygenSaturation = document.getElementById('oxygen_saturation').value;
                    const weight = document.getElementById('weight').value;
                    const height = document.getElementById('height').value;
                    const bmi = document.getElementById('bmi').value;
                    
                    // Physical Examination
                    const generalAppearance = document.getElementById('general_appearance').value;
                    const heentExam = document.getElementById('heent_exam').value;
                    const respiratoryExam = document.getElementById('respiratory_exam').value;
                    const cardiovascularExam = document.getElementById('cardiovascular_exam').value;
                    const abdomenExam = document.getElementById('abdomen_exam').value;
                    const musculoskeletalExam = document.getElementById('musculoskeletal_exam').value;
                    
                    // Assessment
                    const chiefComplaint = document.getElementById('chief_complaint').value;
                    const diagnosis = document.getElementById('diagnosis').value;
                    const impressions = document.getElementById('impressions').value;
                    const fitnessStatus = document.getElementById('fitness_status').value;
                    const restrictions = document.getElementById('restrictions').value;
                    const recommendations = document.getElementById('recommendations').value;
                    const remarks = document.getElementById('remarks').value;
                    
                    // Certificate Validity
                    const validFrom = document.getElementById('valid_from').value;
                    const validUntil = document.getElementById('valid_until').value;

                    // Validation
                    if (!patientFullName || !patientAge || !patientGender || !patientAddress || !examinationDate) {
                        showModalAlert('Please fill in all patient information fields', 'danger');
                        return;
                    }
                    
                    if (!chiefComplaint || !diagnosis || !fitnessStatus || !impressions || !recommendations) {
                        showModalAlert('Please fill in all required fields including Impressions and Recommendations', 'danger');
                        return;
                    }
                    
                    if (!validFrom || !validUntil) {
                        showModalAlert('Please set certificate validity dates', 'danger');
                        return;
                    }
                    
                    this.disabled = true;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                    
                    const formData = new FormData();
                    formData.append('action', 'approve_certificate');
                    formData.append('certificate_id', certId);
                    formData.append('patient_id', patientId);
                    
                    // Patient Info
                    formData.append('patient_full_name', patientFullName);
                    formData.append('patient_age', patientAge);
                    formData.append('patient_gender', patientGender);
                    formData.append('patient_address', patientAddress);
                    formData.append('examination_date', examinationDate);
                    
                    // Vital Signs
                    formData.append('temperature', temperature);
                    formData.append('bp_systolic', bpSystolic);
                    formData.append('bp_diastolic', bpDiastolic);
                    formData.append('heart_rate', heartRate);
                    formData.append('respiratory_rate', respiratoryRate);
                    formData.append('oxygen_saturation', oxygenSaturation);
                    formData.append('weight', weight);
                    formData.append('height', height);
                    formData.append('bmi', bmi);
                    
                    // Physical Examination
                    formData.append('general_appearance', generalAppearance);
                    formData.append('heent_exam', heentExam);
                    formData.append('respiratory_exam', respiratoryExam);
                    formData.append('cardiovascular_exam', cardiovascularExam);
                    formData.append('abdomen_exam', abdomenExam);
                    formData.append('musculoskeletal_exam', musculoskeletalExam);
                    
                    // Assessment (for health records)
                    formData.append('chief_complaint', chiefComplaint);
                    formData.append('diagnosis', diagnosis);
                    formData.append('fitness_status', fitnessStatus);
                    formData.append('restrictions', restrictions);
                    
                    // Certificate Content (what appears on certificate)
                    formData.append('impressions', impressions);
                    formData.append('recommendations', recommendations);
                    formData.append('remarks', remarks);
                    
                    // Certificate Validity
                    formData.append('valid_from', validFrom);
                    formData.append('valid_until', validUntil);
                    
                    fetch('process_certificate.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showModalAlert(data.message, 'success');
                            setTimeout(() => {
                                bootstrap.Modal.getInstance(document.getElementById('processCertificateModal')).hide();
                                location.reload();
                            }, 1500);
                        } else {
                            showModalAlert('Error: ' + data.message, 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showModalAlert('An error occurred while processing', 'danger');
                    })
                    .finally(() => {
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-check me-2"></i>Issue Certificate';
                    });
                });
            }
        });


// Mark as fit (no issues)
        function markAsFit() {
            // Vital Signs
            document.getElementById('temperature').value = '36.5';
            document.getElementById('bp_systolic').value = '120';
            document.getElementById('bp_diastolic').value = '80';
            document.getElementById('heart_rate').value = '72';
            document.getElementById('respiratory_rate').value = '16';
            document.getElementById('oxygen_saturation').value = '98';
            
            // Physical Exam
            document.getElementById('general_appearance').value = 'Alert, well-nourished, no distress';
            document.getElementById('heent_exam').value = 'No abnormalities detected';
            document.getElementById('respiratory_exam').value = 'Clear breath sounds bilaterally';
            document.getElementById('cardiovascular_exam').value = 'Regular rhythm, no murmurs';
            document.getElementById('abdomen_exam').value = 'Soft, non-tender';
            document.getElementById('musculoskeletal_exam').value = 'Full range of motion, no deformities';
            
            // Assessment (for health records)
            const purpose = document.getElementById('cert_purpose').textContent;
            document.getElementById('chief_complaint').value = purpose;
            document.getElementById('diagnosis').value = 'No acute illness detected. Patient is healthy and cleared for normal activities.';
            
            // Certificate Content (what appears on printed cert)
            document.getElementById('impressions').value = 'No significant findings. Patient is healthy and cleared for normal activities.';
            document.getElementById('recommendations').value = 'Maintain healthy lifestyle. Follow-up if symptoms develop.';
            document.getElementById('fitness_status').value = 'Fit';
            document.getElementById('restrictions').value = '';
            document.getElementById('remarks').value = 'Patient cleared for normal activities with no restrictions';
            
            showModalAlert('All fields filled with "Fit" findings. Review and adjust as needed.', 'success');
        }
    </script>
</body>
</html>