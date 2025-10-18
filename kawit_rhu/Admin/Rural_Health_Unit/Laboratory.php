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
$stmt = $pdo->prepare("SELECT * FROM staff WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all patients
$patientsStmt = $pdo->prepare("
    SELECT p.*, CONCAT(p.last_name, ', ', p.first_name, ' ', IFNULL(p.middle_name, '')) as full_name,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age
    FROM patients p 
    WHERE p.is_active = 1 
    ORDER BY p.last_name, p.first_name
");
$patientsStmt->execute();
$patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle lab result submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_lab_result') {
    $patient_id = $_POST['patient_id'];
    $test_type = $_POST['test_type'];
    $test_category = $_POST['test_category'];
    $test_date = $_POST['test_date'];
    $specimen_type = $_POST['specimen_type'] ?? '';
    $normal_range = $_POST['normal_range'] ?? '';
    $interpretation = $_POST['interpretation'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    $status = $_POST['status'];
    
    // Collect test results based on test type
    $test_results = [];
    
    if ($test_category === 'Hematology') {
        $test_results = [
            'WBC' => $_POST['wbc'] ?? '',
            'RBC' => $_POST['rbc'] ?? '',
            'Hemoglobin' => $_POST['hemoglobin'] ?? '',
            'Hematocrit' => $_POST['hematocrit'] ?? '',
            'Platelet' => $_POST['platelet'] ?? '',
            'Neutrophils' => $_POST['neutrophils'] ?? '',
            'Lymphocytes' => $_POST['lymphocytes'] ?? '',
            'Monocytes' => $_POST['monocytes'] ?? '',
            'Eosinophils' => $_POST['eosinophils'] ?? '',
            'Basophils' => $_POST['basophils'] ?? ''
        ];
    } elseif ($test_category === 'Urinalysis') {
        $test_results = [
            'Color' => $_POST['color'] ?? '',
            'Clarity' => $_POST['clarity'] ?? '',
            'pH' => $_POST['ph'] ?? '',
            'Specific_Gravity' => $_POST['specific_gravity'] ?? '',
            'Protein' => $_POST['protein'] ?? '',
            'Glucose' => $_POST['glucose'] ?? '',
            'RBC' => $_POST['urinalysis_rbc'] ?? '',
            'WBC' => $_POST['urinalysis_wbc'] ?? '',
            'Bacteria' => $_POST['bacteria'] ?? '',
            'Crystals' => $_POST['crystals'] ?? ''
        ];
    } elseif ($test_category === 'Chemistry') {
        $test_results = [
            'FBS' => $_POST['fbs'] ?? '',
            'Creatinine' => $_POST['creatinine'] ?? '',
            'BUN' => $_POST['bun'] ?? '',
            'Uric_Acid' => $_POST['uric_acid'] ?? '',
            'Cholesterol' => $_POST['cholesterol'] ?? '',
            'Triglycerides' => $_POST['triglycerides'] ?? '',
            'HDL' => $_POST['hdl'] ?? '',
            'LDL' => $_POST['ldl'] ?? '',
            'SGPT' => $_POST['sgpt'] ?? '',
            'SGOT' => $_POST['sgot'] ?? ''
        ];
    } else {
        // Other test types - generic result field
        $test_results = [
            'Result' => $_POST['generic_result'] ?? ''
        ];
    }
    
    try {
        // Generate lab number
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM laboratory_results WHERE lab_number LIKE ?");
        $stmt->execute(["LAB-$year-%"]);
        $count = $stmt->fetchColumn();
        $lab_number = 'LAB-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        
        $result_date = ($status === 'completed') ? date('Y-m-d') : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO laboratory_results (
                lab_number, patient_id, test_type, test_category, test_date, 
                result_date, specimen_type, test_results, normal_range, 
                interpretation, remarks, status, performed_by, verified_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $lab_number, $patient_id, $test_type, $test_category, $test_date,
            $result_date, $specimen_type, json_encode($test_results), $normal_range,
            $interpretation, $remarks, $status, $staff['id'], 
            ($status === 'completed' ? $staff['id'] : null)
        ]);
        
        $lab_id = $pdo->lastInsertId();
        
        // Log the lab result
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'LAB_RESULT_CREATED', 'Laboratory', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $lab_id,
            json_encode(['lab_number' => $lab_number, 'test_type' => $test_type])
        ]);
        
        // Create notification for patient if completed
        if ($status === 'completed') {
            $patientStmt = $pdo->prepare("SELECT user_id FROM patients WHERE id = ?");
            $patientStmt->execute([$patient_id]);
            $patient_user = $patientStmt->fetch();
            
            if ($patient_user) {
                $notificationStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, priority) 
                    VALUES (?, 'lab_result', 'Lab Results Available', ?, ?, 'medium')
                ");
                $notificationStmt->execute([
                    $patient_user['user_id'],
                    "Your $test_type results are now available. Lab Number: $lab_number",
                    json_encode(['lab_id' => $lab_id, 'lab_number' => $lab_number])
                ]);
            }
        }
        
        $_SESSION['success_message'] = "Lab result encoded successfully! Lab Number: $lab_number";
        header("Location: Laboratory.php");
        exit;
        
    } catch (Exception $e) {
        $error_message = "Error encoding lab result: " . $e->getMessage();
    }
}

// Get laboratory results
$labResultsStmt = $pdo->prepare("
    SELECT lr.*, 
           CONCAT(p.last_name, ', ', p.first_name) as patient_name,
           p.patient_id,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as patient_age,
           p.gender,
           CONCAT(s1.first_name, ' ', s1.last_name) as performed_by_name,
           CONCAT(s2.first_name, ' ', s2.last_name) as verified_by_name
    FROM laboratory_results lr
    JOIN patients p ON lr.patient_id = p.id
    LEFT JOIN staff s1 ON lr.performed_by = s1.id
    LEFT JOIN staff s2 ON lr.verified_by = s2.id
    ORDER BY lr.test_date DESC, lr.created_at DESC
    LIMIT 50
");
$labResultsStmt->execute();
$labResults = $labResultsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Results - RHU Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --kawit-pink: #FFA6BE;
            --light-pink: #FFE4E6;
            --dark-pink: #FF7A9A;
            --text-dark: #2c3e50;
        }

        body {
            background: #f8f9fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
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
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
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
            background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%);
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

        .dashboard-content {
            padding: 2rem;
            flex: 1;
        }

        .content-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%);
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #FF7A9A 0%, #FFA6BE 100%);
            transform: translateY(-2px);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 0.6rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
        }

        .table th {
            background: var(--light-pink);
            color: var(--text-dark);
            font-weight: 600;
            border: none;
        }

        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-weight: 600;
        }

        /* Consistent Status Colors */
        .status-completed { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-processing { background: #fff3cd; color: #856404; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        .section-divider {
            border-top: 2px solid var(--light-pink);
            margin: 2rem 0 1.5rem 0;
            padding-top: 1rem;
        }

        .section-title {
            color: var(--dark-pink);
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }

        .required {
            color: #dc3545;
        }

        .test-fields {
            display: none;
        }

        .test-fields.active {
            display: block;
        }

        .nav-tabs .nav-link {
            color: var(--text-dark);
            border: none;
            border-bottom: 3px solid transparent;
            margin: 0;
            padding: 1rem 1.5rem;
            border-radius: 0;
        }

        .nav-tabs .nav-link.active {
            background: transparent;
            color: var(--dark-pink);
            border-bottom-color: var(--dark-pink);
            font-weight: 600;
        }

        .patient-info-card {
            background: var(--light-pink);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: none;
        }

        .patient-info-card.show {
            display: block;
        }

        /* Patient Search Styles */
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: none;
            margin-top: 5px;
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item:hover {
            background: var(--light-pink);
        }

        .search-result-name {
            font-weight: 600;
            color: var(--text-dark);
            display: block;
        }

        .search-result-details {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 3px;
        }

        .no-results {
            padding: 15px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
        }

        .selected-patient {
            background: var(--light-pink);
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
            display: none;
        }

        .selected-patient.show {
            display: block;
        }

        .selected-patient-name {
            font-weight: 600;
            color: var(--dark-pink);
            font-size: 1.1rem;
        }

        .selected-patient-info {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 5px;
        }

        .clear-selection {
            color: #dc3545;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
            float: right;
        }

        .clear-selection:hover {
            text-decoration: underline;
        }

        /* Make parent container position relative */
        .col-md-6:has(#patientSearch) {
            position: relative;
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
                <a href="Laboratory.php" class="nav-link active">
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
            <h1 class="page-title">Laboratory Results</h1>
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

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link active" data-bs-toggle="tab" href="#encodeTab">
                        <i class="fas fa-plus-circle me-2"></i>Encode Lab Result
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#viewTab">
                        <i class="fas fa-list me-2"></i>View Lab Results
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Encode Lab Result Tab -->
                <div class="tab-pane fade show active" id="encodeTab">
                    <div class="content-card">
                        <h5 class="mb-4">
                            <i class="fas fa-microscope me-2"></i>Encode New Lab Result
                        </h5>

                        <form method="POST" id="labForm">
                            <input type="hidden" name="action" value="create_lab_result">

                            <!-- Patient & Test Info -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Search Patient <span class="required">*</span></label>
                                    <input type="text" 
                                        class="form-control" 
                                        id="patientSearch" 
                                        placeholder="Type patient name or ID to search..." 
                                        autocomplete="off">
                                    <input type="hidden" name="patient_id" id="patientId" required>
                                    
                                    <!-- Search Results Dropdown -->
                                    <div id="patientSearchResults" class="search-results"></div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Test Date <span class="required">*</span></label>
                                    <input type="date" class="form-control" name="test_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status <span class="required">*</span></label>
                                    <select class="form-select" name="status" required>
                                        <option value="pending">Pending</option>
                                        <option value="processing">Processing</option>
                                        <option value="completed" selected>Completed</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Selected Patient Display -->
                            <div class="selected-patient" id="selectedPatientCard">
                                <a href="#" class="clear-selection" onclick="clearPatientSelection(); return false;">
                                    <i class="fas fa-times-circle"></i> Clear
                                </a>
                                <div class="selected-patient-name" id="selectedPatientName"></div>
                                <div class="selected-patient-info">
                                    <span id="selectedPatientId"></span> • 
                                    <span id="selectedPatientAge"></span> • 
                                    <span id="selectedPatientGender"></span>
                                </div>
                            </div>

                            <!-- Test Category & Type -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">Test Category <span class="required">*</span></label>
                                    <select class="form-select" name="test_category" id="testCategory" required>
                                        <option value="">-- Select Category --</option>
                                        <option value="Hematology">Hematology</option>
                                        <option value="Chemistry">Chemistry</option>
                                        <option value="Urinalysis">Urinalysis</option>
                                        <option value="Microbiology">Microbiology</option>
                                        <option value="Serology">Serology</option>
                                        <option value="Parasitology">Parasitology</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Test Type/Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="test_type" placeholder="e.g., Complete Blood Count" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Specimen Type</label>
                                    <input type="text" class="form-control" name="specimen_type" placeholder="e.g., Blood, Urine">
                                </div>
                            </div>

                            <div class="section-divider"></div>

                            <!-- Hematology Fields -->
                            <div id="hematologyFields" class="test-fields">
                                <div class="section-title">
                                    <i class="fas fa-tint me-2"></i>Hematology Test Results
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">WBC (x10⁹/L)</label>
                                        <input type="text" class="form-control" name="wbc" placeholder="4.5-11.0">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">RBC (x10¹²/L)</label>
                                        <input type="text" class="form-control" name="rbc" placeholder="4.0-5.5">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Hemoglobin (g/dL)</label>
                                        <input type="text" class="form-control" name="hemoglobin" placeholder="12-16">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Hematocrit (%)</label>
                                        <input type="text" class="form-control" name="hematocrit" placeholder="37-47">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Platelet (x10⁹/L)</label>
                                        <input type="text" class="form-control" name="platelet" placeholder="150-400">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Neutrophils (%)</label>
                                        <input type="text" class="form-control" name="neutrophils" placeholder="40-75">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Lymphocytes (%)</label>
                                        <input type="text" class="form-control" name="lymphocytes" placeholder="20-45">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Monocytes (%)</label>
                                        <input type="text" class="form-control" name="monocytes" placeholder="2-10">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Eosinophils (%)</label>
                                        <input type="text" class="form-control" name="eosinophils" placeholder="1-6">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">Basophils (%)</label>
                                        <input type="text" class="form-control" name="basophils" placeholder="0-1">
                                    </div>
                                </div>
                            </div>

                            <!-- Chemistry Fields -->
                            <div id="chemistryFields" class="test-fields">
                                <div class="section-title">
                                    <i class="fas fa-vial me-2"></i>Chemistry Test Results
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">FBS (mg/dL)</label>
                                        <input type="text" class="form-control" name="fbs" placeholder="70-100">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Creatinine (mg/dL)</label>
                                        <input type="text" class="form-control" name="creatinine" placeholder="0.6-1.2">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">BUN (mg/dL)</label>
                                        <input type="text" class="form-control" name="bun" placeholder="7-20">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Uric Acid (mg/dL)</label>
                                        <input type="text" class="form-control" name="uric_acid" placeholder="3.5-7.2">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Cholesterol (mg/dL)</label>
                                        <input type="text" class="form-control" name="cholesterol" placeholder="<200">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Triglycerides (mg/dL)</label>
                                        <input type="text" class="form-control" name="triglycerides" placeholder="<150">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">HDL (mg/dL)</label>
                                        <input type="text" class="form-control" name="hdl" placeholder=">40">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">LDL (mg/dL)</label>
                                        <input type="text" class="form-control" name="ldl" placeholder="<100">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">SGPT (U/L)</label>
                                        <input type="text" class="form-control" name="sgpt" placeholder="<40">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">SGOT (U/L)</label>
                                        <input type="text" class="form-control" name="sgot" placeholder="<40">
                                    </div>
                                </div>
                            </div>

                            <!-- Urinalysis Fields -->
                            <div id="urinalysisFields" class="test-fields">
                                <div class="section-title">
                                    <i class="fas fa-flask me-2"></i>Urinalysis Results
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Color</label>
                                        <input type="text" class="form-control" name="color" placeholder="Yellow">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Clarity</label>
                                        <input type="text" class="form-control" name="clarity" placeholder="Clear">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">pH</label>
                                        <input type="text" class="form-control" name="ph" placeholder="5.0-8.0">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Specific Gravity</label>
                                        <input type="text" class="form-control" name="specific_gravity" placeholder="1.005-1.030">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Protein</label>
                                        <input type="text" class="form-control" name="protein" placeholder="Negative">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Glucose</label>
                                        <input type="text" class="form-control" name="glucose" placeholder="Negative">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">RBC</label>
                                        <input type="text" class="form-control" name="urinalysis_rbc" placeholder="0-2/hpf">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">WBC</label>
                                        <input type="text" class="form-control" name="urinalysis_wbc" placeholder="0-5/hpf">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Bacteria</label>
                                        <input type="text" class="form-control" name="bacteria" placeholder="None/Few">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Crystals</label>
                                        <input type="text" class="form-control" name="crystals" placeholder="None">
                                    </div>
                                </div>
                            </div>

                            <!-- Other Tests Fields -->
                            <div id="otherFields" class="test-fields">
                                <div class="section-title">
                                    <i class="fas fa-clipboard-list me-2"></i>Test Results
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Result/Findings</label>
                                        <textarea class="form-control" name="generic_result" rows="4" placeholder="Enter test results or findings"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="section-divider"></div>

                            <!-- Interpretation & Remarks -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Normal Range/Reference Values</label>
                                    <textarea class="form-control" name="normal_range" rows="3" placeholder="Enter normal range for reference"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Interpretation</label>
                                    <textarea class="form-control" name="interpretation" rows="3" placeholder="Normal, High, Low, etc."></textarea>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label">Remarks/Notes</label>
                                    <textarea class="form-control" name="remarks" rows="3" placeholder="Additional notes, recommendations, or observations"></textarea>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="text-end mt-4">
                                <button type="reset" class="btn btn-secondary me-2">
                                    <i class="fas fa-redo me-2"></i>Clear Form
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Lab Result
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- View Lab Results Tab -->
                <div class="tab-pane fade" id="viewTab">
                    <div class="content-card">
                        <h5 class="mb-4">
                            <i class="fas fa-list me-2"></i>Laboratory Results
                        </h5>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Lab #</th>
                                        <th>Date</th>
                                        <th>Patient</th>
                                        <th>Test Type</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($labResults)): ?>
                                        <?php foreach ($labResults as $result): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($result['lab_number']); ?></strong></td>
                                                <td><?php echo date('M j, Y', strtotime($result['test_date'])); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($result['patient_name']); ?><br>
                                                    <small class="text-muted"><?php echo $result['patient_id']; ?> • <?php echo $result['patient_age']; ?>y/o • <?php echo $result['gender']; ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($result['test_type']); ?></td>
                                                <td><span class="badge bg-info"><?php echo $result['test_category']; ?></span></td>
                                                <td><span class="badge status-<?php echo $result['status']; ?>"><?php echo ucfirst($result['status']); ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info" onclick="viewLabDetails(<?php echo $result['id']; ?>)" title="View Details">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    
                                                    <!-- Hidden data for modal -->
                                                    <div id="lab-data-<?php echo $result['id']; ?>" style="display: none;">
                                                        <?php echo htmlspecialchars(json_encode([
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
                                                            'patient_name' => $result['patient_name'],
                                                            'patient_id' => $result['patient_id'],
                                                            'patient_age' => $result['patient_age'],
                                                            'gender' => $result['gender']
                                                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                                No laboratory results found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
</div>
            </div>
        </div>
    </div>
</div>
<!-- End Dashboard Content -->

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


    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<div class="main-content">

    
    <div class="dashboard-content">
</div>
    <script>
    // Patient Search Functionality
    const patientSearch = document.getElementById('patientSearch');
    const patientSearchResults = document.getElementById('patientSearchResults');
    const patientIdInput = document.getElementById('patientId');
    const selectedPatientCard = document.getElementById('selectedPatientCard');

    // All patients data
    const allPatients = <?php echo json_encode($patients); ?>;

    // Search patients
    patientSearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        if (searchTerm.length < 2) {
            patientSearchResults.classList.remove('show');
            return;
        }
        
        // Filter patients
        const filteredPatients = allPatients.filter(patient => {
            const fullName = patient.full_name.toLowerCase();
            const patientId = patient.patient_id.toLowerCase();
            return fullName.includes(searchTerm) || patientId.includes(searchTerm);
        });
        
        // Display results
        if (filteredPatients.length > 0) {
            let html = '';
            filteredPatients.forEach(patient => {
                html += `
                    <div class="search-result-item" onclick="selectPatient(${patient.id}, '${patient.full_name}', '${patient.patient_id}', ${patient.age}, '${patient.gender}')">
                        <div class="search-result-name">${patient.full_name}</div>
                        <div class="search-result-details">
                            ${patient.patient_id} • ${patient.age} y/o • ${patient.gender}
                        </div>
                    </div>
                `;
            });
            patientSearchResults.innerHTML = html;
            patientSearchResults.classList.add('show');
        } else {
            patientSearchResults.innerHTML = '<div class="no-results">No patients found</div>';
            patientSearchResults.classList.add('show');
        }
    });

    // Select patient
    function selectPatient(id, name, patientId, age, gender) {
        // Set hidden input
        patientIdInput.value = id;
        
        // Update search input
        patientSearch.value = name;
        
        // Hide search results
        patientSearchResults.classList.remove('show');
        
        // Show selected patient card
        document.getElementById('selectedPatientName').textContent = name;
        document.getElementById('selectedPatientId').textContent = patientId;
        document.getElementById('selectedPatientAge').textContent = age + ' years old';
        document.getElementById('selectedPatientGender').textContent = gender;
        selectedPatientCard.classList.add('show');
    }

    // Clear patient selection
    function clearPatientSelection() {
        patientIdInput.value = '';
        patientSearch.value = '';
        selectedPatientCard.classList.remove('show');
        patientSearchResults.classList.remove('show');
    }

    // Close search results when clicking outside
    document.addEventListener('click', function(e) {
        if (!patientSearch.contains(e.target) && !patientSearchResults.contains(e.target)) {
            patientSearchResults.classList.remove('show');
        }
    });

        // Test category change handler
        document.getElementById('testCategory').addEventListener('change', function() {
            // Hide all test fields
            document.querySelectorAll('.test-fields').forEach(field => {
                field.classList.remove('active');
            });
            
            // Show relevant fields based on category
            const category = this.value;
            if (category === 'Hematology') {
                document.getElementById('hematologyFields').classList.add('active');
            } else if (category === 'Chemistry') {
                document.getElementById('chemistryFields').classList.add('active');
            } else if (category === 'Urinalysis') {
                document.getElementById('urinalysisFields').classList.add('active');
            } else if (category) {
                document.getElementById('otherFields').classList.add('active');
            }
        });

        // View Lab Result Details with Smart Colors
        function viewLabDetails(labId) {
            const dataElement = document.getElementById('lab-data-' + labId);
            if (!dataElement) {
                alert('Lab result details not found');
                return;
            }

            try {
                // Decode HTML entities first
                const jsonString = dataElement.textContent;
                const textarea = document.createElement('textarea');
                textarea.innerHTML = jsonString;
                const decodedJson = textarea.value;
                
                const data = JSON.parse(decodedJson);
                const results = data.test_results ? JSON.parse(data.test_results) : {};
                
                let html = '<div class="alert alert-info"><div class="row">';
                html += '<div class="col-md-6">';
                html += '<strong>Lab Number:</strong> ' + data.lab_number + '<br>';
                html += '<strong>Test Date:</strong> ' + new Date(data.test_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                html += '</div>';
                html += '<div class="col-md-6">';
                html += '<strong>Test Type:</strong> ' + data.test_type + '<br>';
                html += '<strong>Category:</strong> <span class="badge bg-info">' + data.test_category + '</span>';
                html += '</div></div>';
                
                // Patient Info
                html += '<div class="mt-2"><strong>Patient:</strong> ' + data.patient_name + ' (' + data.patient_id + ') - ' + data.patient_age + 'y/o ' + data.gender + '</div>';
                
                if (data.result_date) {
                    html += '<div class="mt-2"><strong>Result Date:</strong> ' + new Date(data.result_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + '</div>';
                }
                html += '</div>';

                if (data.specimen_type) {
                    html += '<div class="alert alert-secondary"><strong><i class="fas fa-vial me-2"></i>Specimen Type:</strong> ' + data.specimen_type + '</div>';
                }

                // Test Results
                html += '<h6 class="text-primary mb-3"><i class="fas fa-clipboard-list me-2"></i>Test Results</h6>';
                html += '<div style="background: #e3f2fd; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">';
                if (Object.keys(results).length > 0) {
                    html += '<div class="row">';
                    for (const [key, value] of Object.entries(results)) {
                        if (value) {  // Only show non-empty values
                            html += '<div class="col-md-6 mb-2"><strong>' + key.replace(/_/g, ' ') + ':</strong> ' + value + '</div>';
                        }
                    }
                    html += '</div>';
                } else {
                    html += '<p class="mb-0">No detailed results available</p>';
                }
                html += '</div>';

                // Normal Range
                if (data.normal_range) {
                    html += '<div style="background: #f8f9fa; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">';
                    html += '<strong>Normal Range / Reference Values:</strong><br>' + data.normal_range.replace(/\n/g, '<br>');
                    html += '</div>';
                }

                // Interpretation with Smart Colors
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
                    html += '<div style="background: #f8f9fa; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">';
                    html += '<strong>Remarks / Notes:</strong><br>' + data.remarks.replace(/\n/g, '<br>');
                    html += '</div>';
                }

                // Staff Information
                html += '<div class="border-top pt-3 mt-3"><div class="row">';
                if (data.performed_by_name) html += '<div class="col-md-6"><strong>Performed By:</strong> ' + data.performed_by_name + '</div>';
                if (data.verified_by_name) html += '<div class="col-md-6"><strong>Verified By:</strong> ' + data.verified_by_name + '</div>';
                html += '</div></div>';

                document.getElementById('labDetailsContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('labDetailsModal'));
                modal.show();
            } catch (error) {
                console.error('Error loading lab details:', error);
                alert('Error loading lab details: ' + error.message + '\n\nCheck console for details.');
            }
        }

        // Form validation
        document.getElementById('labForm').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields marked with *');
            }
        });

        // Clear invalid state on input
        document.querySelectorAll('.form-control, .form-select').forEach(field => {
            field.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });
    </script>
</body>
</html>