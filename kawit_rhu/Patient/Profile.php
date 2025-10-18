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

// Get patient information with barangay and user data
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.password_changed_at, u.updated_at as user_updated_at, 
           b.barangay_name, YEAR(CURDATE()) - YEAR(p.date_of_birth) as age
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

// Get all barangays for dropdown
$barangaysStmt = $pdo->prepare("
    SELECT * FROM barangays 
    WHERE is_active = 1 
    ORDER BY barangay_name
");
$barangaysStmt->execute();
$barangays = $barangaysStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle profile update
if (isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $civil_status = $_POST['civil_status'];
    $address = $_POST['address'];
    $barangay_id = $_POST['barangay_id'] ?? null;
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $allergies = $_POST['allergies'] ?? null;
    $philhealth_number = $_POST['philhealth_number'] ?? null;
    $occupation = $_POST['occupation'] ?? null;
    $educational_attainment = $_POST['educational_attainment'] ?? null;
    $religion = $_POST['religion'] ?? null;
    $emergency_contact_name = $_POST['emergency_contact_name'];
    $emergency_contact_phone = $_POST['emergency_contact_phone'];
    $emergency_contact_relationship = $_POST['emergency_contact_relationship'] ?? null;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE patients 
            SET civil_status = ?, address = ?, barangay_id = ?, phone = ?, email = ?, 
                allergies = ?, philhealth_number = ?, occupation = ?,
                educational_attainment = ?, religion = ?, emergency_contact_name = ?, 
                emergency_contact_phone = ?, emergency_contact_relationship = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $civil_status, $address, $barangay_id, $phone, $email,
            $allergies, $philhealth_number, $occupation, $educational_attainment,
            $religion, $emergency_contact_name, $emergency_contact_phone, 
            $emergency_contact_relationship, $patient['id']
        ]);
        
        // Log the profile update
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'PROFILE_UPDATE', 'Patients', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $patient['id'],
            json_encode(['updated_fields' => array_keys($_POST)])
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating profile. Please try again.']);
    }
    exit;
}

// Handle password change
if (isset($_POST['action']) && $_POST['action'] == 'change_password') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    try {
        // Get current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($current_password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit;
        }
        
        if ($new_password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
            exit;
        }
        
        if (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long']);
            exit;
        }
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password = ?, password_changed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$hashed_password, $_SESSION['user_id']]);
        
        // Log the password change
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id) 
            VALUES (?, 'PASSWORD_CHANGE', 'Users', ?)
        ");
        $logStmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        
        echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error changing password. Please try again.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile & Settings - Kawit RHU</title>
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

        .form-control {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
            margin-bottom: 1rem;
        }

        .form-control:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
        }

        .form-select {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
            margin-bottom: 1rem;
        }

        .form-select:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
        }

        .btn-save {
            background: var(--kawit-gradient);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 122, 154, 0.4);
            color: white;
        }

        .readonly-field {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            color: #6c757d;
        }

        .readonly-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-style: italic;
        }

        .editable-label {
            color: var(--text-dark);
            font-weight: 600;
        }

        .alert {
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        .password-requirements {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.9rem;
        }

        .password-requirements ul {
            margin-bottom: 0;
            padding-left: 1.2rem;
        }

        .password-requirements li {
            margin-bottom: 0.3rem;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-section-title {
            color: var(--text-dark);
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--kawit-pink);
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
                <h1 class="page-title">Profile & Settings</h1>
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
                <div id="alertMessage" style="display: none;"></div>

                <!-- Personal Information Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <h3 class="section-title">Personal Information</h3>
                    </div>
                    
                    <form id="profileForm">
                        <!-- Read-only Personal Details -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-lock me-2"></i>System Information (Cannot be changed)
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label readonly-label">Patient ID</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($patient['patient_id']); ?>" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label readonly-label">Username</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($patient['username']); ?>" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label readonly-label">First Name</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($patient['first_name']); ?>" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label readonly-label">Middle Name</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($patient['middle_name'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label readonly-label">Last Name</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($patient['last_name']); ?>" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label readonly-label">Suffix</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($patient['suffix'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label readonly-label">Date of Birth</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo date('F j, Y', strtotime($patient['date_of_birth'])); ?>" readonly>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label readonly-label">Age</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo $patient['age']; ?> years old" readonly>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label readonly-label">Gender</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($patient['gender']); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- Editable Personal Details -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-edit me-2"></i>Personal Details
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Civil Status *</label>
                                    <select class="form-select" id="civil_status" required>
                                        <option value="Single" <?php echo $patient['civil_status'] == 'Single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo $patient['civil_status'] == 'Married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="Widowed" <?php echo $patient['civil_status'] == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                        <option value="Divorced" <?php echo $patient['civil_status'] == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                        <option value="Separated" <?php echo $patient['civil_status'] == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Religion</label>
                                    <input type="text" class="form-control" id="religion" placeholder="e.g. Roman Catholic, Protestant, Islam" value="<?php echo htmlspecialchars($patient['religion'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Occupation</label>
                                    <input type="text" class="form-control" id="occupation" placeholder="e.g. Teacher, Engineer, Student" value="<?php echo htmlspecialchars($patient['occupation'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Educational Attainment</label>
                                    <select class="form-select" id="educational_attainment">
                                        <option value="">Select educational level</option>
                                        <option value="Elementary" <?php echo $patient['educational_attainment'] == 'Elementary' ? 'selected' : ''; ?>>Elementary</option>
                                        <option value="High School" <?php echo $patient['educational_attainment'] == 'High School' ? 'selected' : ''; ?>>High School</option>
                                        <option value="Senior High School" <?php echo $patient['educational_attainment'] == 'Senior High School' ? 'selected' : ''; ?>>Senior High School</option>
                                        <option value="Vocational" <?php echo $patient['educational_attainment'] == 'Vocational' ? 'selected' : ''; ?>>Vocational/Technical</option>
                                        <option value="College Undergraduate" <?php echo $patient['educational_attainment'] == 'College Undergraduate' ? 'selected' : ''; ?>>College Undergraduate</option>
                                        <option value="College Graduate" <?php echo $patient['educational_attainment'] == 'College Graduate' ? 'selected' : ''; ?>>College Graduate</option>
                                        <option value="Masteral" <?php echo $patient['educational_attainment'] == 'Masteral' ? 'selected' : ''; ?>>Masteral</option>
                                        <option value="Doctoral" <?php echo $patient['educational_attainment'] == 'Doctoral' ? 'selected' : ''; ?>>Doctoral</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-phone me-2"></i>Contact Information
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" placeholder="09XX-XXX-XXXX" value="<?php echo htmlspecialchars($patient['phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" placeholder="your.email@example.com" value="<?php echo htmlspecialchars($patient['email'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-map-marker-alt me-2"></i>Address Information
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Barangay</label>
                                    <select class="form-select" id="barangay_id">
                                        <option value="">Select barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>" <?php echo $patient['barangay_id'] == $barangay['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label editable-label">Complete Address *</label>
                                    <textarea class="form-control" id="address" rows="3" required><?php echo htmlspecialchars($patient['address']); ?></textarea>
                                    <small class="text-muted">Include house number, street, subdivision, etc.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Health Information -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-heartbeat me-2"></i>Medical & Health Information
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label readonly-label">Blood Type</label>
                                    <input type="text" class="form-control readonly-field" 
                                           value="<?php echo htmlspecialchars($patient['blood_type'] ?? 'Not specified'); ?>" readonly>
                                    <small class="text-muted">Contact RHU staff to update blood type with laboratory verification</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">PhilHealth Number</label>
                                    <input type="text" class="form-control" id="philhealth_number" placeholder="XX-XXXXXXXXX-X" value="<?php echo htmlspecialchars($patient['philhealth_number'] ?? ''); ?>">
                                    <small class="text-muted">Format: XX-XXXXXXXXX-X</small>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label editable-label">Known Allergies</label>
                                    <textarea class="form-control" id="allergies" rows="3" placeholder="List any known allergies (food, medicine, environmental). Write 'None' if no known allergies."><?php echo htmlspecialchars($patient['allergies'] ?? ''); ?></textarea>
                                    <small class="text-muted">Please be specific about allergic reactions (e.g., rash, difficulty breathing, swelling)</small>
                                </div>
                            </div>
                        </div>

                        <!-- Emergency Contact Information -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-phone me-2"></i>Emergency Contact Information
                            </div>
                            <div class="alert alert-info" style="font-size: 0.9rem; margin-bottom: 1rem;">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Important:</strong> Please provide contact information for a family member, friend, or trusted person (not yourself) who can be reached in case of medical emergencies when you cannot be contacted.
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label editable-label">Emergency Contact Name</label>
                                    <input type="text" class="form-control" id="emergency_contact_name" placeholder="Full name of emergency contact" value="<?php echo htmlspecialchars($patient['emergency_contact_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label editable-label">Emergency Contact Phone</label>
                                    <input type="tel" class="form-control" id="emergency_contact_phone" placeholder="09XX-XXX-XXXX" value="<?php echo htmlspecialchars($patient['emergency_contact_phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label editable-label">Relationship to You</label>
                                    <select class="form-select" id="emergency_contact_relationship">
                                        <option value="">Select relationship</option>
                                        <option value="Spouse" <?php echo $patient['emergency_contact_relationship'] == 'Spouse' ? 'selected' : ''; ?>>Spouse</option>
                                        <option value="Father" <?php echo $patient['emergency_contact_relationship'] == 'Father' ? 'selected' : ''; ?>>Father</option>
                                        <option value="Mother" <?php echo $patient['emergency_contact_relationship'] == 'Mother' ? 'selected' : ''; ?>>Mother</option>
                                        <option value="Son" <?php echo $patient['emergency_contact_relationship'] == 'Son' ? 'selected' : ''; ?>>Son</option>
                                        <option value="Daughter" <?php echo $patient['emergency_contact_relationship'] == 'Daughter' ? 'selected' : ''; ?>>Daughter</option>
                                        <option value="Brother" <?php echo $patient['emergency_contact_relationship'] == 'Brother' ? 'selected' : ''; ?>>Brother</option>
                                        <option value="Sister" <?php echo $patient['emergency_contact_relationship'] == 'Sister' ? 'selected' : ''; ?>>Sister</option>
                                        <option value="Guardian" <?php echo $patient['emergency_contact_relationship'] == 'Guardian' ? 'selected' : ''; ?>>Guardian</option>
                                        <option value="Friend" <?php echo $patient['emergency_contact_relationship'] == 'Friend' ? 'selected' : ''; ?>>Friend</option>
                                        <option value="Relative" <?php echo $patient['emergency_contact_relationship'] == 'Relative' ? 'selected' : ''; ?>>Relative</option>
                                        <option value="Other" <?php echo $patient['emergency_contact_relationship'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn-save" id="saveProfile">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <h3 class="section-title">Change Password</h3>
                    </div>
                    
                    <form id="passwordForm">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label editable-label">Current Password *</label>
                                <input type="password" class="form-control" id="current_password" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label editable-label">New Password *</label>
                                <input type="password" class="form-control" id="new_password" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label editable-label">Confirm New Password *</label>
                                <input type="password" class="form-control" id="confirm_password" required>
                            </div>
                        </div>

                        <div class="password-requirements">
                            <h6><i class="fas fa-info-circle me-2"></i>Password Requirements:</h6>
                            <ul>
                                <li>At least 6 characters long</li>
                                <li>Recommended: Include uppercase and lowercase letters</li>
                                <li>Recommended: Include at least one number</li>
                                <li>Recommended: Include at least one special character (!@#$%^&*)</li>
                                <li>Should not be the same as your username or easily guessable information</li>
                            </ul>
                        </div>

                        <div class="text-end mt-3">
                            <button type="submit" class="btn-save" id="changePassword">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Account Information Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h3 class="section-title">Account Information</h3>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-clock me-2"></i>Account Activity
                                </div>
                                <p><strong>Account Created:</strong> <?php echo date('F j, Y g:i A', strtotime($patient['created_at'])); ?></p>
                                <p><strong>Last Profile Update:</strong> <?php echo $patient['updated_at'] ? date('F j, Y g:i A', strtotime($patient['updated_at'])) : 'Never updated'; ?></p>
                                <p><strong>Last Password Change:</strong> <?php echo $patient['password_changed_at'] ? date('F j, Y g:i A', strtotime($patient['password_changed_at'])) : 'Never changed'; ?></p>
                                <p><strong>Account Status:</strong> 
                                    <span class="badge bg-success">Active</span>
                                </p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-shield-alt me-2"></i>Privacy & Security
                                </div>
                                <div class="alert alert-info" style="font-size: 0.9rem;">
                                    <i class="fas fa-lock me-2"></i>
                                    Your personal information is protected and used only for healthcare purposes within the Kawit RHU system.
                                </div>
                                <small class="text-muted">
                                    For questions about data privacy or to request data deletion, contact the RHU office at 046-434-0000.
                                </small>
                            </div>
                        </div>
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

        function showAlert(message, type = 'danger') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
            `;
            alertDiv.style.display = 'block';
            
            // Scroll to top to show alert
            window.scrollTo({top: 0, behavior: 'smooth'});
            
            if (type === 'success') {
                setTimeout(() => {
                    alertDiv.style.display = 'none';
                }, 5000);
            }
        }

        // Handle profile update
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const saveButton = document.getElementById('saveProfile');
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

            const formData = new FormData();
            formData.append('action', 'update_profile');
            formData.append('civil_status', document.getElementById('civil_status').value);
            formData.append('address', document.getElementById('address').value);
            formData.append('barangay_id', document.getElementById('barangay_id').value);
            formData.append('phone', document.getElementById('phone').value);
            formData.append('email', document.getElementById('email').value);
            formData.append('allergies', document.getElementById('allergies').value);
            formData.append('philhealth_number', document.getElementById('philhealth_number').value);
            formData.append('occupation', document.getElementById('occupation').value);
            formData.append('educational_attainment', document.getElementById('educational_attainment').value);
            formData.append('religion', document.getElementById('religion').value);
            formData.append('emergency_contact_name', document.getElementById('emergency_contact_name').value);
            formData.append('emergency_contact_phone', document.getElementById('emergency_contact_phone').value);
            formData.append('emergency_contact_relationship', document.getElementById('emergency_contact_relationship').value);

            fetch('Profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    
                    // Update the profile update timestamp display immediately
                    const now = new Date();
                    const options = { 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric', 
                        hour: 'numeric', 
                        minute: '2-digit', 
                        hour12: true 
                    };
                    const formattedDate = now.toLocaleDateString('en-US', options);
                    
                    // Find and update the profile update timestamp
                    const allParagraphs = document.querySelectorAll('.form-section p');
                    allParagraphs.forEach(p => {
                        if (p.innerHTML.includes('Last Profile Update:')) {
                            p.innerHTML = '<strong>Last Profile Update:</strong> ' + formattedDate;
                        }
                    });
                } else {
                    showAlert(data.message || 'Error updating profile');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred. Please try again.');
            })
            .finally(() => {
                saveButton.disabled = false;
                saveButton.innerHTML = '<i class="fas fa-save me-2"></i>Save Changes';
            });
        });

        // Handle password change
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const changeButton = document.getElementById('changePassword');

            if (newPassword !== confirmPassword) {
                showAlert('New passwords do not match');
                return;
            }

            if (newPassword.length < 6) {
                showAlert('Password must be at least 6 characters long');
                return;
            }

            changeButton.disabled = true;
            changeButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Changing...';

            const formData = new FormData();
            formData.append('action', 'change_password');
            formData.append('current_password', currentPassword);
            formData.append('new_password', newPassword);
            formData.append('confirm_password', confirmPassword);

            fetch('Profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    
                    // Update the password change timestamp display immediately
                    const now = new Date();
                    const options = { 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric', 
                        hour: 'numeric', 
                        minute: '2-digit', 
                        hour12: true 
                    };
                    const formattedDate = now.toLocaleDateString('en-US', options);
                    
                    // Find and update the password change timestamp
                    const passwordChangeElement = document.querySelector('p strong').parentNode;
                    const allParagraphs = document.querySelectorAll('.form-section p');
                    allParagraphs.forEach(p => {
                        if (p.innerHTML.includes('Last Password Change:')) {
                            p.innerHTML = '<strong>Last Password Change:</strong> ' + formattedDate;
                        }
                    });
                    
                    // Clear password fields on success
                    document.getElementById('current_password').value = '';
                    document.getElementById('new_password').value = '';
                    document.getElementById('confirm_password').value = '';
                } else {
                    showAlert(data.message || 'Error changing password');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred. Please try again.');
            })
            .finally(() => {
                changeButton.disabled = false;
                changeButton.innerHTML = '<i class="fas fa-key me-2"></i>Change Password';
            });
        });

        // Phone number formatting
        function formatPhoneNumber(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);
            
            if (value.length >= 4) {
                value = value.slice(0, 4) + '-' + value.slice(4);
            }
            if (value.length >= 9) {
                value = value.slice(0, 9) + '-' + value.slice(9);
            }
            
            input.value = value;
        }

        document.getElementById('phone').addEventListener('input', function() {
            formatPhoneNumber(this);
        });

        document.getElementById('emergency_contact_phone').addEventListener('input', function() {
            formatPhoneNumber(this);
        });

        // PhilHealth number formatting
        document.getElementById('philhealth_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 12) value = value.slice(0, 12);
            
            if (value.length >= 2) {
                value = value.slice(0, 2) + '-' + value.slice(2);
            }
            if (value.length >= 12) {
                value = value.slice(0, 12) + '-' + value.slice(12);
            }
            
            e.target.value = value;
        });

        // Auto-refresh session check every 5 minutes
        setInterval(function() {
            fetch('Profile.php')
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
</html>