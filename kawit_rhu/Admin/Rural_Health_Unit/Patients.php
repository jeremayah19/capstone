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
    
    if ($_POST['action'] === 'add_patient') {
        try {
            // Validate required fields
            $required_fields = ['username', 'password', 'first_name', 'last_name', 'date_of_birth', 'gender', 'address', 'civil_status'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Please fill in all required fields.");
                }
            }
            
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$_POST['username']]);
            if ($stmt->fetch()) {
                throw new Exception("Username already exists. Please choose another.");
            }
            
            // Check if email exists (if provided)
            if (!empty($_POST['email'])) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$_POST['email']]);
                if ($stmt->fetch()) {
                    throw new Exception("Email address already exists.");
                }
            }
            
            $pdo->beginTransaction();
            
            // Generate new patient ID
            $year = date('Y');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE patient_id LIKE ?");
            $stmt->execute(["P-$year-%"]);
            $count = $stmt->fetchColumn();
            $patient_id = 'P-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
            
            // Create user account
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, email, role) 
                VALUES (?, ?, ?, 'patient')
            ");
            $stmt->execute([
                $_POST['username'], 
                $hashed_password, 
                $_POST['email'] ?: null
            ]);
            $user_id = $pdo->lastInsertId();
            
            // Create patient record
            $stmt = $pdo->prepare("
                INSERT INTO patients (
                    user_id, patient_id, first_name, middle_name, last_name, suffix,
                    date_of_birth, gender, civil_status, address, barangay_id, phone, email,
                    blood_type, allergies, philhealth_number, occupation, educational_attainment,
                    religion, emergency_contact_name, emergency_contact_phone, 
                    emergency_contact_relationship
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id, $patient_id, $_POST['first_name'], $_POST['middle_name'], $_POST['last_name'], $_POST['suffix'],
                $_POST['date_of_birth'], $_POST['gender'], $_POST['civil_status'], $_POST['address'], 
                $_POST['barangay_id'] ?: null, $_POST['phone'], $_POST['email'],
                $_POST['blood_type'], $_POST['allergies'], $_POST['philhealth_number'], $_POST['occupation'], 
                $_POST['educational_attainment'], $_POST['religion'], $_POST['emergency_contact_name'], 
                $_POST['emergency_contact_phone'], $_POST['emergency_contact_relationship']
            ]);
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
                VALUES (?, 'PATIENT_REGISTERED', 'Patients', ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['user_id'], 
                $pdo->lastInsertId(),
                json_encode(['patient_id' => $patient_id, 'registered_by' => $staff['first_name'] . ' ' . $staff['last_name']])
            ]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Patient registered successfully! Patient ID: ' . $patient_id]);
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'update_patient') {
        try {
            $patient_id = $_POST['patient_id'];
            
            $pdo->beginTransaction();
            
            // Update patient record
            $stmt = $pdo->prepare("
                UPDATE patients 
                SET civil_status = ?, address = ?, barangay_id = ?, phone = ?, email = ?,
                    blood_type = ?, allergies = ?, philhealth_number = ?, occupation = ?,
                    educational_attainment = ?, religion = ?, emergency_contact_name = ?,
                    emergency_contact_phone = ?, emergency_contact_relationship = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['civil_status'], $_POST['address'], $_POST['barangay_id'] ?: null,
                $_POST['phone'], $_POST['email'], $_POST['blood_type'], $_POST['allergies'],
                $_POST['philhealth_number'], $_POST['occupation'], $_POST['educational_attainment'],
                $_POST['religion'], $_POST['emergency_contact_name'], $_POST['emergency_contact_phone'],
                $_POST['emergency_contact_relationship'], $patient_id
            ]);
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
                VALUES (?, 'PATIENT_UPDATED', 'Patients', ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['user_id'], 
                $patient_id,
                json_encode(['updated_by' => $staff['first_name'] . ' ' . $staff['last_name']])
            ]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Patient information updated successfully!']);
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_patient') {
        $stmt = $pdo->prepare("
            SELECT p.*, b.barangay_name, u.username, u.email as user_email,
                   YEAR(CURDATE()) - YEAR(p.date_of_birth) as age
            FROM patients p 
            LEFT JOIN barangays b ON p.barangay_id = b.id
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$_POST['patient_id']]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($patient) {
            echo json_encode(['success' => true, 'patient' => $patient]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Patient not found']);
        }
        exit;
    }

    if ($_POST['action'] === 'deactivate_patient') {
    try {
        $patient_id = $_POST['patient_id'];
        
        $pdo->beginTransaction();
        
        // Deactivate patient record
        $stmt = $pdo->prepare("UPDATE patients SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$patient_id]);
        
        // Deactivate user account
        $stmt = $pdo->prepare("
            UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP 
            WHERE id = (SELECT user_id FROM patients WHERE id = ?)
        ");
        $stmt->execute([$patient_id]);
        
        // Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'PATIENT_DEACTIVATED', 'Patients', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $patient_id,
            json_encode(['deactivated_by' => $staff['first_name'] . ' ' . $staff['last_name']])
        ]);

        // Get user_id for notification
        $userStmt = $pdo->prepare("SELECT user_id FROM patients WHERE id = ?");
        $userStmt->execute([$patient_id]);
        $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userResult && $userResult['user_id']) {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, priority)
                VALUES (?, 'system', 'Account Deactivated', ?, ?, 'high')
            ");
            $notifStmt->execute([
                $userResult['user_id'],
                "Your account has been deactivated. Please contact RHU for assistance.",
                json_encode(['patient_id' => $patient_id])
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Patient account deactivated successfully.']);
        
    } catch (Exception $e) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

    if ($_POST['action'] === 'activate_patient') {
        try {
            $patient_id = $_POST['patient_id'];
            
            $pdo->beginTransaction();
            
            // Activate patient record
            $stmt = $pdo->prepare("UPDATE patients SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$patient_id]);
            
            // Activate user account
            $stmt = $pdo->prepare("
                UPDATE users SET is_active = 1, updated_at = CURRENT_TIMESTAMP 
                WHERE id = (SELECT user_id FROM patients WHERE id = ?)
            ");
            $stmt->execute([$patient_id]);
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
                VALUES (?, 'PATIENT_ACTIVATED', 'Patients', ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['user_id'], 
                $patient_id,
                json_encode(['activated_by' => $staff['first_name'] . ' ' . $staff['last_name']])
            ]);
            
            // Get user_id for notification
        $userStmt = $pdo->prepare("SELECT user_id FROM patients WHERE id = ?");
        $userStmt->execute([$patient_id]);
        $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userResult && $userResult['user_id']) {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, priority)
                VALUES (?, 'system', 'Account Activated', ?, ?, 'medium')
            ");
            $notifStmt->execute([
                $userResult['user_id'],
                "Your account has been activated. You can now log in.",
                json_encode(['patient_id' => $patient_id])
            ]);
        }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Patient account activated successfully.']);
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    if ($_POST['action'] === 'change_password') {
        try {
            $patient_id = $_POST['patient_id'];
            $new_password = $_POST['new_password'];
            
            // Validate password
            if (strlen($new_password) < 6) {
                throw new Exception("Password must be at least 6 characters long.");
            }
            
            $pdo->beginTransaction();
            
            // Get user_id
            $stmt = $pdo->prepare("SELECT user_id, patient_id, CONCAT(first_name, ' ', last_name) as patient_name FROM patients WHERE id = ?");
            $stmt->execute([$patient_id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$patient) {
                throw new Exception("Patient not found.");
            }
            
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$hashed_password, $patient['user_id']]);
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
                VALUES (?, 'PASSWORD_CHANGED', 'Patients', ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['user_id'], 
                $patient_id,
                json_encode([
                    'changed_by' => $staff['first_name'] . ' ' . $staff['last_name'],
                    'patient_name' => $patient['patient_name']
                ])
            ]);
            
            // Send notification to patient
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, priority)
                VALUES (?, 'system', 'Password Changed', ?, ?, 'high')
            ");
            $notifStmt->execute([
                $patient['user_id'],
                "Your password has been changed by RHU admin. If you did not request this change, please contact the RHU immediately.",
                json_encode(['patient_id' => $patient['patient_id']])
            ]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$barangay_filter = $_GET['barangay'] ?? '';
$gender_filter = $_GET['gender'] ?? '';
$age_filter = $_GET['age'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build query conditions
$conditions = ['1=1'];
$params = [];

if ($search) {
    $conditions[] = "(p.patient_id LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.phone LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($barangay_filter) {
    $conditions[] = "p.barangay_id = ?";
    $params[] = $barangay_filter;
}

if ($gender_filter) {
    $conditions[] = "p.gender = ?";
    $params[] = $gender_filter;
}

if ($age_filter) {
    switch ($age_filter) {
        case 'child':
            $conditions[] = "YEAR(CURDATE()) - YEAR(p.date_of_birth) < 18";
            break;
        case 'adult':
            $conditions[] = "YEAR(CURDATE()) - YEAR(p.date_of_birth) BETWEEN 18 AND 59";
            break;
        case 'senior':
            $conditions[] = "YEAR(CURDATE()) - YEAR(p.date_of_birth) >= 60";
            break;
    }
}

if ($status_filter) {
    if ($status_filter === 'active') {
        $conditions[] = "p.is_active = 1";
    } else {
        $conditions[] = "p.is_active = 0";
    }
}

$where_clause = implode(' AND ', $conditions);

// Get total count for pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM patients p 
    LEFT JOIN barangays b ON p.barangay_id = b.id
    WHERE $where_clause
");
$countStmt->execute($params);
$total_patients = $countStmt->fetchColumn();
$total_pages = ceil($total_patients / $limit);

// Get patients
$stmt = $pdo->prepare("
    SELECT p.*, b.barangay_name, u.username,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age,
           (SELECT COUNT(*) FROM appointments WHERE patient_id = p.id AND status IN ('confirmed', 'completed')) as total_appointments,
           (SELECT COUNT(*) FROM consultations WHERE patient_id = p.id AND status = 'completed') as total_consultations,
           (SELECT MAX(consultation_date) FROM consultations WHERE patient_id = p.id) as last_consultation
    FROM patients p 
    LEFT JOIN barangays b ON p.barangay_id = b.id
    LEFT JOIN users u ON p.user_id = u.id
    WHERE $where_clause
    ORDER BY p.created_at DESC
    LIMIT " . intval($limit) . " OFFSET " . intval($offset)
);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get barangays for filters and forms
$barangaysStmt = $pdo->prepare("SELECT * FROM barangays WHERE is_active = 1 ORDER BY barangay_name");
$barangaysStmt->execute();
$barangays = $barangaysStmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_patients,
        COUNT(CASE WHEN p.is_active = 1 THEN 1 END) as active_patients,
        COUNT(CASE WHEN YEAR(CURDATE()) - YEAR(p.date_of_birth) < 18 THEN 1 END) as pediatric_patients,
        COUNT(CASE WHEN YEAR(CURDATE()) - YEAR(p.date_of_birth) >= 60 THEN 1 END) as senior_patients,
        COUNT(CASE WHEN DATE(p.created_at) = CURDATE() THEN 1 END) as new_today
    FROM patients p
");
$statsStmt->execute();
$statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients Management - RHU Admin</title>
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
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
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

        /* Buttons */
        .btn-primary {
            background: var(--kawit-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--dark-pink);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 122, 154, 0.4);
        }

        .btn-secondary {
            border-radius: 10px;
            padding: 8px 16px;
            font-weight: 500;
        }

        /* Search and Filter */
        .search-filter-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .search-input {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
        }

        .search-input:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
        }

        .filter-select {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 10px 15px;
        }

        .filter-select:focus {
            border-color: var(--kawit-pink);
            box-shadow: 0 0 0 0.2rem rgba(255, 166, 190, 0.25);
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
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        /* Patient Cards for Mobile */
        .patient-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--kawit-pink);
            display: none;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .gender-male {
            color: #007bff;
        }

        .gender-female {
            color: #e83e8c;
        }

        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-top: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
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
            color: var(--text-dark);
        }

        .page-item.active .page-link {
            background: var(--kawit-gradient);
            border-color: var(--kawit-pink);
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

            .data-table {
                display: none;
            }

            .patient-card {
                display: block;
            }

            .section-header {
                flex-direction: column;
                align-items: stretch;
            }
        }

        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
            }

            .search-filter-section .row {
                flex-direction: column;
            }
        }

        /* Tab Navigation Styling */
        .nav-tabs {
            border-bottom: 2px solid var(--kawit-pink);
        }

        .nav-tabs .nav-link {
            color: var(--text-dark);
            font-weight: 500;
            border: 1px solid transparent;
            border-radius: 10px 10px 0 0;
            padding: 12px 20px;
            margin-right: 5px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .nav-tabs .nav-link:hover {
            background: var(--light-pink);
            color: var(--dark-pink);
            border-color: var(--kawit-pink) var(--kawit-pink) transparent;
        }

        .nav-tabs .nav-link.active {
            background: var(--kawit-gradient);
            color: white;
            border-color: var(--kawit-pink) var(--kawit-pink) transparent;
            font-weight: 600;
        }

        .nav-tabs .nav-link i {
            margin-right: 5px;
        }

        /* Tab Content Styling */
        .tab-content {
            padding: 20px;
            background: white;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 10px 10px;
        }

        .tab-pane {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Tabs for Mobile */
        @media (max-width: 768px) {
            .nav-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
            }

            .nav-tabs .nav-link {
                white-space: nowrap;
                padding: 10px 15px;
                font-size: 0.9rem;
            }

            .nav-tabs .nav-link i {
                display: block;
                margin: 0 auto 5px;
                font-size: 1.2rem;
            }
        }

        /* Statistics Cards in Modal */
        .modal-xl .card {
            transition: transform 0.2s ease;
        }

        .modal-xl .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
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
                    <a href="Patients.php" class="nav-link active">
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
                <h1 class="page-title">Patients Management</h1>
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

                <!-- Search and Filter Section -->
                <div class="content-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-search"></i>Search & Filter Patients
                        </h3>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPatientModal">
                            <i class="fas fa-plus me-2"></i>Add New Patient
                        </button>
                    </div>

                    <div class="search-filter-section">
                        <form method="GET" action="" id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="search" class="form-control search-input" 
                                           placeholder="Patient ID, name, or phone..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Barangay</label>
                                    <select name="barangay" class="form-select filter-select">
                                        <option value="">All Barangays</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>" 
                                                    <?php echo $barangay_filter == $barangay['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select filter-select">
                                        <option value="">All Genders</option>
                                        <option value="Male" <?php echo $gender_filter == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $gender_filter == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo $gender_filter == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Age Group</label>
                                    <select name="age" class="form-select filter-select">
                                        <option value="">All Ages</option>
                                        <option value="child" <?php echo $age_filter == 'child' ? 'selected' : ''; ?>>Under 18</option>
                                        <option value="adult" <?php echo $age_filter == 'adult' ? 'selected' : ''; ?>>18-59</option>
                                        <option value="senior" <?php echo $age_filter == 'senior' ? 'selected' : ''; ?>>60+</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select filter-select">
                                        <option value="">All Status</option>
                                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12 text-end">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="fas fa-filter me-1"></i>Apply Filters
                                    </button>
                                    <a href="Patients.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Patients List -->
                <div class="content-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="fas fa-list"></i>Patients List 
                            <span class="badge bg-secondary ms-2"><?php echo number_format($total_patients); ?> total</span>
                        </h3>
                    </div>

                    <?php if (!empty($patients)): ?>
                        <!-- Desktop Table View -->
                        <div class="table-responsive d-none d-md-block">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Patient ID</th>
                                        <th>Name</th>
                                        <th>Age/Gender</th>
                                        <th>Contact</th>
                                        <th>Barangay</th>
                                        <th>Last Visit</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($patients as $patient): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($patient['patient_id']); ?></strong>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></strong>
                                                    <?php if ($patient['middle_name']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($patient['middle_name']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="gender-<?php echo strtolower($patient['gender']); ?>">
                                                    <?php echo $patient['age']; ?> years / <?php echo $patient['gender']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($patient['phone']): ?>
                                                    <a href="tel:<?php echo $patient['phone']; ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($patient['phone']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">No phone</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($patient['barangay_name'] ?? 'Not specified'); ?></td>
                                            <td>
                                                <?php if ($patient['last_consultation']): ?>
                                                    <?php echo date('M j, Y', strtotime($patient['last_consultation'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No visits</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $patient['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $patient['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick="viewPatient(<?php echo $patient['id']; ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-secondary" 
                                                            onclick="editPatient(<?php echo $patient['id']; ?>)"
                                                            title="Edit Patient">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="Consultation.php?patient_id=<?php echo $patient['id']; ?>" 
                                                    class="btn btn-sm btn-outline-success"
                                                    title="New Consultation">
                                                        <i class="fas fa-user-md"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-warning" 
                                                            onclick="changePassword(<?php echo $patient['id']; ?>, '<?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>')"
                                                            title="Change Password">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <?php if ($patient['is_active']): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deactivatePatient(<?php echo $patient['id']; ?>)"
                                                                title="Deactivate Patient">
                                                            <i class="fas fa-user-slash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="activatePatient(<?php echo $patient['id']; ?>)"
                                                                title="Activate Patient">
                                                            <i class="fas fa-user-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View -->
                        <?php foreach ($patients as $patient): ?>
                            <div class="patient-card d-block d-md-none">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($patient['patient_id']); ?></strong>
                                        <span class="status-badge status-<?php echo $patient['is_active'] ? 'active' : 'inactive'; ?> ms-2">
                                            <?php echo $patient['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewPatient(<?php echo $patient['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="editPatient(<?php echo $patient['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-warning" 
                                                onclick="changePassword(<?php echo $patient['id']; ?>, '<?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>')">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <?php if ($patient['is_active']): ?>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deactivatePatient(<?php echo $patient['id']; ?>)"
                                                title="Deactivate Patient">
                                            <i class="fas fa-user-slash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success" 
                                                onclick="activatePatient(<?php echo $patient['id']; ?>)"
                                                title="Activate Patient">
                                            <i class="fas fa-user-check"></i>
                                        </button>
                                    <?php endif; ?>
                                    </div>
                                </div>
                                <h5 class="mb-2"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h5>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Age/Gender:</small><br>
                                        <span class="gender-<?php echo strtolower($patient['gender']); ?>">
                                            <?php echo $patient['age']; ?> / <?php echo $patient['gender']; ?>
                                        </span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Contact:</small><br>
                                        <?php echo htmlspecialchars($patient['phone'] ?? 'No phone'); ?>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <small class="text-muted">Barangay:</small><br>
                                        <?php echo htmlspecialchars($patient['barangay_name'] ?? 'Not specified'); ?>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Last Visit:</small><br>
                                        <?php if ($patient['last_consultation']): ?>
                                            <?php echo date('M j, Y', strtotime($patient['last_consultation'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">No visits</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-container">
                                <div>
                                    Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $total_patients); ?> 
                                    of <?php echo number_format($total_patients); ?> patients
                                </div>
                                <nav>
                                    <ul class="pagination">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $barangay_filter ? '&barangay=' . $barangay_filter : ''; ?><?php echo $gender_filter ? '&gender=' . $gender_filter : ''; ?><?php echo $age_filter ? '&age=' . $age_filter : ''; ?><?php echo $status_filter ? '&status=' . $status_filter : ''; ?>">First</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $barangay_filter ? '&barangay=' . $barangay_filter : ''; ?><?php echo $gender_filter ? '&gender=' . $gender_filter : ''; ?><?php echo $age_filter ? '&age=' . $age_filter : ''; ?><?php echo $status_filter ? '&status=' . $status_filter : ''; ?>">Previous</a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $page + 2);
                                        for ($i = $start_page; $i <= $end_page; $i++):
                                        ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $barangay_filter ? '&barangay=' . $barangay_filter : ''; ?><?php echo $gender_filter ? '&gender=' . $gender_filter : ''; ?><?php echo $age_filter ? '&age=' . $age_filter : ''; ?><?php echo $status_filter ? '&status=' . $status_filter : ''; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $barangay_filter ? '&barangay=' . $barangay_filter : ''; ?><?php echo $gender_filter ? '&gender=' . $gender_filter : ''; ?><?php echo $age_filter ? '&age=' . $age_filter : ''; ?><?php echo $status_filter ? '&status=' . $status_filter : ''; ?>">Next</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $barangay_filter ? '&barangay=' . $barangay_filter : ''; ?><?php echo $gender_filter ? '&gender=' . $gender_filter : ''; ?><?php echo $age_filter ? '&age=' . $age_filter : ''; ?><?php echo $status_filter ? '&status=' . $status_filter : ''; ?>">Last</a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <h5>No Patients Found</h5>
                            <p class="text-muted">
                                <?php if ($search || $barangay_filter || $gender_filter || $age_filter): ?>
                                    No patients match your search criteria. Try adjusting your filters.
                                <?php else: ?>
                                    There are no patients registered in the system yet.
                                <?php endif; ?>
                            </p>
                            <?php if (!$search && !$barangay_filter): ?>
                                <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addPatientModal">
                                    <i class="fas fa-plus me-2"></i>Add First Patient
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Patient Modal -->
    <div class="modal fade" id="addPatientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Add New Patient
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addPatientForm">
                        <!-- Account Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Account Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username *</label>
                                    <input type="text" class="form-control" name="username" required>
                                    <small class="text-muted">Used for patient login</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password *</label>
                                    <input type="password" class="form-control" name="password" required>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Personal Information</h6>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" class="form-control" name="first_name" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" name="middle_name">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" name="last_name" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Suffix</label>
                                    <input type="text" class="form-control" name="suffix" placeholder="Jr., Sr., III">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Date of Birth *</label>
                                    <input type="date" class="form-control" name="date_of_birth" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Gender *</label>
                                    <select class="form-select" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Civil Status *</label>
                                    <select class="form-select" name="civil_status" required>
                                        <option value="">Select Status</option>
                                        <option value="Single">Single</option>
                                        <option value="Married">Married</option>
                                        <option value="Widowed">Widowed</option>
                                        <option value="Divorced">Divorced</option>
                                        <option value="Separated">Separated</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Contact & Address Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone" placeholder="09XX-XXX-XXXX">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Barangay</label>
                                    <select class="form-select" name="barangay_id">
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>">
                                                <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Complete Address *</label>
                                    <textarea class="form-control" name="address" rows="2" required placeholder="House number, street, subdivision, etc."></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Medical Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Medical Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Blood Type</label>
                                    <select class="form-select" name="blood_type">
                                        <option value="">Select Blood Type</option>
                                        <option value="A+">A+</option>
                                        <option value="A-">A-</option>
                                        <option value="B+">B+</option>
                                        <option value="B-">B-</option>
                                        <option value="AB+">AB+</option>
                                        <option value="AB-">AB-</option>
                                        <option value="O+">O+</option>
                                        <option value="O-">O-</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">PhilHealth Number</label>
                                    <input type="text" class="form-control" name="philhealth_number" placeholder="XX-XXXXXXXXX-X">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Known Allergies</label>
                                <textarea class="form-control" name="allergies" rows="2" placeholder="List any known allergies or write 'None'"></textarea>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Additional Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Occupation</label>
                                    <input type="text" class="form-control" name="occupation">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Educational Attainment</label>
                                    <select class="form-select" name="educational_attainment">
                                        <option value="">Select Education Level</option>
                                        <option value="Elementary">Elementary</option>
                                        <option value="High School">High School</option>
                                        <option value="Senior High School">Senior High School</option>
                                        <option value="Vocational">Vocational/Technical</option>
                                        <option value="College Undergraduate">College Undergraduate</option>
                                        <option value="College Graduate">College Graduate</option>
                                        <option value="Masteral">Masteral</option>
                                        <option value="Doctoral">Doctoral</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Religion</label>
                                <input type="text" class="form-control" name="religion" placeholder="e.g. Roman Catholic, Protestant, Islam">
                            </div>
                        </div>

                        <!-- Emergency Contact -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Emergency Contact</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Contact Name</label>
                                    <input type="text" class="form-control" name="emergency_contact_name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Contact Phone</label>
                                    <input type="tel" class="form-control" name="emergency_contact_phone">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Relationship</label>
                                    <select class="form-select" name="emergency_contact_relationship">
                                        <option value="">Select Relationship</option>
                                        <option value="Spouse">Spouse</option>
                                        <option value="Parent">Parent</option>
                                        <option value="Child">Child</option>
                                        <option value="Sibling">Sibling</option>
                                        <option value="Guardian">Guardian</option>
                                        <option value="Friend">Friend</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="savePatient">
                        <i class="fas fa-save me-2"></i>Register Patient
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Patient Modal -->
    <div class="modal fade" id="editPatientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit me-2"></i>Edit Patient Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editPatientForm">
                        <input type="hidden" name="patient_id" id="edit_patient_id">
                        
                        <!-- Personal Information (Non-editable) -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Personal Information (System Record)</h6>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Basic personal information cannot be edited. Contact system administrator for changes.
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label text-muted">Patient ID</label>
                                    <input type="text" class="form-control" id="edit_patient_id_display" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label text-muted">First Name</label>
                                    <input type="text" class="form-control" id="edit_first_name" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label text-muted">Last Name</label>
                                    <input type="text" class="form-control" id="edit_last_name" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label text-muted">Date of Birth</label>
                                    <input type="text" class="form-control" id="edit_date_of_birth" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- Editable Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Contact & Address Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Civil Status</label>
                                    <select class="form-select" name="civil_status" id="edit_civil_status">
                                        <option value="Single">Single</option>
                                        <option value="Married">Married</option>
                                        <option value="Widowed">Widowed</option>
                                        <option value="Divorced">Divorced</option>
                                        <option value="Separated">Separated</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone" id="edit_phone">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" id="edit_email">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Barangay</label>
                                    <select class="form-select" name="barangay_id" id="edit_barangay_id">
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>">
                                                <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Complete Address</label>
                                <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                            </div>
                        </div>

                        <!-- Medical Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Medical Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Blood Type</label>
                                    <select class="form-select" name="blood_type" id="edit_blood_type">
                                        <option value="">Select Blood Type</option>
                                        <option value="A+">A+</option>
                                        <option value="A-">A-</option>
                                        <option value="B+">B+</option>
                                        <option value="B-">B-</option>
                                        <option value="AB+">AB+</option>
                                        <option value="AB-">AB-</option>
                                        <option value="O+">O+</option>
                                        <option value="O-">O-</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">PhilHealth Number</label>
                                    <input type="text" class="form-control" name="philhealth_number" id="edit_philhealth_number">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Known Allergies</label>
                                <textarea class="form-control" name="allergies" id="edit_allergies" rows="2"></textarea>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Additional Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Occupation</label>
                                    <input type="text" class="form-control" name="occupation" id="edit_occupation">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Educational Attainment</label>
                                    <select class="form-select" name="educational_attainment" id="edit_educational_attainment">
                                        <option value="">Select Education Level</option>
                                        <option value="Elementary">Elementary</option>
                                        <option value="High School">High School</option>
                                        <option value="Senior High School">Senior High School</option>
                                        <option value="Vocational">Vocational/Technical</option>
                                        <option value="College Undergraduate">College Undergraduate</option>
                                        <option value="College Graduate">College Graduate</option>
                                        <option value="Masteral">Masteral</option>
                                        <option value="Doctoral">Doctoral</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Religion</label>
                                <input type="text" class="form-control" name="religion" id="edit_religion">
                            </div>
                        </div>

                        <!-- Emergency Contact -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">Emergency Contact</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Contact Name</label>
                                    <input type="text" class="form-control" name="emergency_contact_name" id="edit_emergency_contact_name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Contact Phone</label>
                                    <input type="tel" class="form-control" name="emergency_contact_phone" id="edit_emergency_contact_phone">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Relationship</label>
                                    <select class="form-select" name="emergency_contact_relationship" id="edit_emergency_contact_relationship">
                                        <option value="">Select Relationship</option>
                                        <option value="Spouse">Spouse</option>
                                        <option value="Parent">Parent</option>
                                        <option value="Child">Child</option>
                                        <option value="Sibling">Sibling</option>
                                        <option value="Guardian">Guardian</option>
                                        <option value="Friend">Friend</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="updatePatient">
                        <i class="fas fa-save me-2"></i>Update Information
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Patient Modal -->
    <div class="modal fade" id="viewPatientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>Patient Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="viewPatientContent">
                        <!-- Content will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="editFromView">
                        <i class="fas fa-edit me-2"></i>Edit Patient
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-key me-2"></i>Change Patient Password
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Security Notice:</strong> The patient should change this password after logging in.
                    </div>
                    
                    <!-- Alert container for validation messages -->
                    <div id="passwordChangeAlert" style="display: none;"></div>
                    
                    <form id="changePasswordForm">
                        <input type="hidden" id="change_password_patient_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Patient Name</label>
                            <input type="text" class="form-control" id="change_password_patient_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" required minlength="6" placeholder="Minimum 6 characters">
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted">Minimum 6 characters required</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password *</label>
                            <input type="password" class="form-control" id="confirm_password" required minlength="6" placeholder="Re-enter password">
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="patientRequestedChange">
                            <label class="form-check-label" for="patientRequestedChange">
                                Patient requested this password change in person
                            </label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmChangePassword">
                        <i class="fas fa-check me-2"></i>Change Password
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Certificate Details Modal -->
    <div class="modal fade" id="certificateDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-certificate me-2"></i>Certificate Medical Findings
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="certificateDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="printCertificateFromDetails">
                        <i class="fas fa-print me-2"></i>Print Certificate
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Consultation Details Modal -->
    <div class="modal fade" id="consultationDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-stethoscope me-2"></i>Consultation Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="consultationDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Lab Result Details Modal -->
    <div class="modal fade" id="labDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-flask me-2"></i>Laboratory Result Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="labDetailsContent">
                    <!-- Content will be loaded here -->
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
                window.location.href = '../../login.php';
            <?php endif; ?>
        };

        function showAlert(message, type = 'danger') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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

        // Save new patient
        document.getElementById('savePatient').addEventListener('click', function() {
            const form = document.getElementById('addPatientForm');
            const formData = new FormData(form);
            formData.append('action', 'add_patient');

            // Validate required fields
            const requiredFields = ['username', 'password', 'first_name', 'last_name', 'date_of_birth', 'gender', 'civil_status', 'address'];
            let isValid = true;
            
            requiredFields.forEach(field => {
                const input = form.querySelector(`[name="${field}"]`);
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            if (!isValid) {
                showAlert('Please fill in all required fields marked with *');
                return;
            }

            // Validate password length
            if (formData.get('password').length < 6) {
                showAlert('Password must be at least 6 characters long');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Registering...';

            fetch('Patients.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('addPatientModal')).hide();
                    form.reset();
                    
                    // Reload page after short delay
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showAlert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while registering the patient. Please try again.');
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-save me-2"></i>Register Patient';
            });
        });

        // View patient with complete medical history
        function viewPatient(patientId) {
            const modal = new bootstrap.Modal(document.getElementById('viewPatientModal'));
            const content = document.getElementById('viewPatientContent');
            
            content.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-3x text-primary"></i><br><p class="mt-3">Loading patient information...</p></div>';
            modal.show();

            fetch('get_patient_complete_info.php?patient_id=' + patientId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const patient = data.patient;
                        const stats = data.statistics;
                        
                        content.innerHTML = `
                            <!-- Statistics Cards -->
                            <div class="row mb-4">
                                <div class="col-md-2">
                                    <div class="card text-center border-primary">
                                        <div class="card-body p-2">
                                            <h4 class="mb-0 text-primary">${stats.total_consultations}</h4>
                                            <small>Consultations</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="card text-center border-info">
                                        <div class="card-body p-2">
                                            <h4 class="mb-0 text-info">${stats.total_appointments}</h4>
                                            <small>Appointments</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="card text-center border-success">
                                        <div class="card-body p-2">
                                            <h4 class="mb-0 text-success">${stats.total_lab_tests}</h4>
                                            <small>Lab Tests</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card text-center border-warning">
                                        <div class="card-body p-2">
                                            <h4 class="mb-0 text-warning">${stats.total_prescriptions}</h4>
                                            <small>Prescriptions</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card text-center border-danger">
                                        <div class="card-body p-2">
                                            <h4 class="mb-0 text-danger">${stats.total_certificates}</h4>
                                            <small>Certificates</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab Navigation -->
                            <ul class="nav nav-tabs mb-3" id="patientInfoTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic-info" type="button">
                                        <i class="fas fa-user me-1"></i>Basic Info
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="consultations-tab" data-bs-toggle="tab" data-bs-target="#consultations-info" type="button">
                                        <i class="fas fa-stethoscope me-1"></i>Consultations
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments-info" type="button">
                                        <i class="fas fa-calendar-check me-1"></i>Appointments
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="labs-tab" data-bs-toggle="tab" data-bs-target="#labs-info" type="button">
                                        <i class="fas fa-flask me-1"></i>Lab Results
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="prescriptions-tab" data-bs-toggle="tab" data-bs-target="#prescriptions-info" type="button">
                                        <i class="fas fa-pills me-1"></i>Prescriptions
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="certificates-tab" data-bs-toggle="tab" data-bs-target="#certificates-info" type="button">
                                        <i class="fas fa-certificate me-1"></i>Certificates
                                    </button>
                                </li>
                            </ul>

                            <!-- Tab Content -->
                            <div class="tab-content" id="patientInfoTabsContent">
                                <!-- Basic Info Tab -->
                                <div class="tab-pane fade show active" id="basic-info" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="card border-0 mb-3">
                                                <div class="card-header bg-primary text-white">
                                                    <h6 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h6>
                                                </div>
                                                <div class="card-body">
                                                    <strong>Patient ID:</strong> ${patient.patient_id}<br>
                                                    <strong>Full Name:</strong> ${patient.first_name} ${patient.middle_name || ''} ${patient.last_name} ${patient.suffix || ''}<br>
                                                    <strong>Date of Birth:</strong> ${new Date(patient.date_of_birth).toLocaleDateString()}<br>
                                                    <strong>Age:</strong> ${patient.age} years old<br>
                                                    <strong>Gender:</strong> ${patient.gender}<br>
                                                    <strong>Civil Status:</strong> ${patient.civil_status}<br>
                                                    <strong>Blood Type:</strong> <span class="badge bg-danger">${patient.blood_type || 'Not specified'}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card border-0 mb-3">
                                                <div class="card-header bg-info text-white">
                                                    <h6 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Contact & Address</h6>
                                                </div>
                                                <div class="card-body">
                                                    <strong>Phone:</strong> ${patient.phone || 'Not provided'}<br>
                                                    <strong>Email:</strong> ${patient.email || 'Not provided'}<br>
                                                    <strong>Address:</strong> ${patient.address}<br>
                                                    <strong>Barangay:</strong> ${patient.barangay_name || 'Not specified'}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card border-0 mb-3">
                                                <div class="card-header bg-warning text-dark">
                                                    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Medical Alerts</h6>
                                                </div>
                                                <div class="card-body">
                                                    <strong>Known Allergies:</strong><br>
                                                    <div class="alert alert-warning mb-2">${patient.allergies || 'None specified'}</div>
                                                    <strong>Medical History:</strong><br>
                                                    ${patient.medical_history || 'No significant history'}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card border-0 mb-3">
                                                <div class="card-header bg-success text-white">
                                                    <h6 class="mb-0"><i class="fas fa-heartbeat me-2"></i>Medical Info</h6>
                                                </div>
                                                <div class="card-body">
                                                    <strong>PhilHealth:</strong> ${patient.philhealth_number || 'Not provided'}<br>
                                                    <strong>Occupation:</strong> ${patient.occupation || 'Not specified'}<br>
                                                    <strong>Education:</strong> ${patient.educational_attainment || 'Not specified'}<br>
                                                    <strong>Religion:</strong> ${patient.religion || 'Not specified'}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="card border-0">
                                                <div class="card-header bg-danger text-white">
                                                    <h6 class="mb-0"><i class="fas fa-phone me-2"></i>Emergency Contact</h6>
                                                </div>
                                                <div class="card-body">
                                                    <strong>Name:</strong> ${patient.emergency_contact_name || 'Not provided'}<br>
                                                    <strong>Phone:</strong> ${patient.emergency_contact_phone || 'Not provided'}<br>
                                                    <strong>Relationship:</strong> ${patient.emergency_contact_relationship || 'Not specified'}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Consultations Tab -->
                                <div class="tab-pane fade" id="consultations-info" role="tabpanel">
                                    ${data.consultations.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-primary">
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Type</th>
                                                        <th>Chief Complaint</th>
                                                        <th>Diagnosis</th>
                                                        <th>Doctor</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${data.consultations.map(c => `
                                                        <tr>
                                                            <td>${new Date(c.consultation_date).toLocaleDateString()}</td>
                                                            <td><span class="badge bg-info">${c.consultation_type}</span></td>
                                                            <td>${c.chief_complaint || '-'}</td>
                                                            <td>${c.diagnosis || '-'}</td>
                                                            <td>${c.doctor_name || '-'}</td>
                                                            <td><span class="badge bg-${c.status === 'completed' ? 'success' : 'warning'}">${c.status}</span></td>
                                                            <td>
                                                                ${c.id ? `
                                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewConsultationDetails(${c.id})" title="View Consultation Details">
                                                                        <i class="fas fa-stethoscope"></i> Details
                                                                    </button>
                                                                ` : '<span class="text-muted">-</span>'}
                                                            </td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<div class="alert alert-info">No consultation records found</div>'}
                                </div>

                                <!-- Appointments Tab -->
                                <div class="tab-pane fade" id="appointments-info" role="tabpanel">
                                    ${data.appointments.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-info">
                                                    <tr>
                                                        <th>Date & Time</th>
                                                        <th>Service</th>
                                                        <th>Location</th>
                                                        <th>Reason</th>
                                                        <th>Doctor</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${data.appointments.map(a => `
                                                        <tr>
                                                            <td>${new Date(a.appointment_date).toLocaleDateString()} ${a.appointment_time !== '00:00:00' ? new Date('2000-01-01 ' + a.appointment_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'TBA'}</td>
                                                            <td>${a.service_name || 'General'}</td>
                                                            <td><span class="badge bg-secondary">${a.appointment_location}</span></td>
                                                            <td>${a.reason_for_visit || '-'}</td>
                                                            <td>${a.doctor_name || 'Not assigned'}</td>
                                                            <td><span class="badge bg-${a.status === 'completed' ? 'success' : a.status === 'confirmed' ? 'primary' : a.status === 'pending' ? 'warning' : 'danger'}">${a.status}</span></td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<div class="alert alert-info">No appointment records found</div>'}
                                </div>

                                <!-- Lab Results Tab -->
                                <div class="tab-pane fade" id="labs-info" role="tabpanel">
                                    ${data.lab_results.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-success">
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Test Type</th>
                                                        <th>Category</th>
                                                        <th>Interpretation</th>
                                                        <th>Performed By</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${data.lab_results.map(l => `
                                                        <tr>
                                                            <td>${new Date(l.test_date).toLocaleDateString()}</td>
                                                            <td>${l.test_type}</td>
                                                            <td><span class="badge bg-info">${l.test_category}</span></td>
                                                            <td>${l.interpretation || '-'}</td>
                                                            <td>${l.performed_by_name || '-'}</td>
                                                            <td><span class="badge bg-${l.status === 'completed' ? 'success' : 'warning'}">${l.status}</span></td>
                                                            <td>
                                                                ${l.id && l.status === 'completed' ? `
                                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewLabDetails(${l.id})" title="View Lab Result Details">
                                                                        <i class="fas fa-flask"></i> Details
                                                                    </button>
                                                                ` : '<span class="text-muted">-</span>'}
                                                            </td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<div class="alert alert-info">No laboratory records found</div>'}
                                </div>

                                <!-- Prescriptions Tab -->
                                <div class="tab-pane fade" id="prescriptions-info" role="tabpanel">
                                    ${data.prescriptions.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-warning">
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Medication</th>
                                                        <th>Dosage</th>
                                                        <th>Instructions</th>
                                                        <th>Qty</th>
                                                        <th>Prescribed By</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${data.prescriptions.map(p => `
                                                        <tr>
                                                            <td>${new Date(p.prescription_date).toLocaleDateString()}</td>
                                                            <td><strong>${p.medication_name}</strong></td>
                                                            <td>${p.dosage_strength}</td>
                                                            <td><small>${p.dosage_instructions}</small></td>
                                                            <td>${p.quantity_prescribed}</td>
                                                            <td>${p.prescribed_by_name || '-'}</td>
                                                            <td><span class="badge bg-${p.status === 'fully_dispensed' ? 'success' : p.status === 'partially_dispensed' ? 'info' : 'warning'}">${p.status.replace('_', ' ')}</span></td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<div class="alert alert-info">No prescription records found</div>'}
                                </div>

                                <!-- Certificates Tab -->
                                <div class="tab-pane fade" id="certificates-info" role="tabpanel">
                                    ${data.certificates.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-danger">
                                                    <tr>
                                                        <th>Certificate #</th>
                                                        <th>Date Issued</th>
                                                        <th>Type</th>
                                                        <th>Purpose</th>
                                                        <th>Fitness Status</th>
                                                        <th>Valid Until</th>
                                                        <th>Issued By</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${data.certificates.map(cert => `
                                                        <tr>
                                                            <td><strong>${cert.certificate_number}</strong></td>
                                                            <td>${new Date(cert.date_issued).toLocaleDateString()}</td>
                                                            <td>${cert.certificate_type}</td>
                                                            <td><small>${cert.purpose}</small></td>
                                                            <td><span class="badge bg-${cert.fitness_status === 'Fit' ? 'success' : 'warning'}">${cert.fitness_status || '-'}</span></td>
                                                            <td>${cert.valid_until ? new Date(cert.valid_until).toLocaleDateString() : '-'}</td>
                                                            <td>${cert.issued_by_name || '-'}</td>
                                                            <td><span class="badge bg-${cert.status === 'ready_for_download' || cert.status === 'downloaded' ? 'success' : cert.status === 'pending' ? 'warning' : 'info'}">${cert.status.replace(/_/g, ' ')}</span></td>
                                                            <td>
                                                                ${(cert.status === 'ready_for_download' || cert.status === 'downloaded' || cert.status === 'completed_checkup') && (cert.impressions || cert.recommendations || cert.remarks) ? `
                                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewCertificateDetails(${cert.id}, '${cert.certificate_number}')" title="View Certificate Details">
                                                                        <i class="fas fa-file-medical"></i> Details
                                                                    </button>
                                                                ` : '<span class="text-muted">-</span>'}
                                                            </td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<div class="alert alert-info">No medical certificates found</div>'}
                                </div>
                            </div>
                        `;
                        
                        // Store patient ID for edit button
                        document.getElementById('editFromView').setAttribute('data-patient-id', patient.id);
                    } else {
                        content.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = '<div class="alert alert-danger">Error loading patient information.</div>';
                });
        }

        // Edit patient
        function editPatient(patientId) {
            const modal = new bootstrap.Modal(document.getElementById('editPatientModal'));
            
            const formData = new FormData();
            formData.append('action', 'get_patient');
            formData.append('patient_id', patientId);

            fetch('Patients.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const patient = data.patient;
                    
                    // Fill non-editable fields
                    document.getElementById('edit_patient_id').value = patient.id;
                    document.getElementById('edit_patient_id_display').value = patient.patient_id;
                    document.getElementById('edit_first_name').value = patient.first_name;
                    document.getElementById('edit_last_name').value = patient.last_name;
                    document.getElementById('edit_date_of_birth').value = new Date(patient.date_of_birth).toLocaleDateString();
                    
                    // Fill editable fields
                    document.getElementById('edit_civil_status').value = patient.civil_status || '';
                    document.getElementById('edit_phone').value = patient.phone || '';
                    document.getElementById('edit_email').value = patient.email || '';
                    document.getElementById('edit_barangay_id').value = patient.barangay_id || '';
                    document.getElementById('edit_address').value = patient.address || '';
                    document.getElementById('edit_blood_type').value = patient.blood_type || '';
                    document.getElementById('edit_philhealth_number').value = patient.philhealth_number || '';
                    document.getElementById('edit_allergies').value = patient.allergies || '';
                    document.getElementById('edit_occupation').value = patient.occupation || '';
                    document.getElementById('edit_educational_attainment').value = patient.educational_attainment || '';
                    document.getElementById('edit_religion').value = patient.religion || '';
                    document.getElementById('edit_emergency_contact_name').value = patient.emergency_contact_name || '';
                    document.getElementById('edit_emergency_contact_phone').value = patient.emergency_contact_phone || '';
                    document.getElementById('edit_emergency_contact_relationship').value = patient.emergency_contact_relationship || '';
                    
                    modal.show();
                } else {
                    showAlert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Error loading patient information.');
            });
        }

        // Edit from view modal
        document.getElementById('editFromView').addEventListener('click', function() {
            const patientId = this.getAttribute('data-patient-id');
            bootstrap.Modal.getInstance(document.getElementById('viewPatientModal')).hide();
            setTimeout(() => editPatient(patientId), 300);
        });

        // Update patient
        document.getElementById('updatePatient').addEventListener('click', function() {
            const form = document.getElementById('editPatientForm');
            const formData = new FormData(form);
            formData.append('action', 'update_patient');

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';

            fetch('Patients.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editPatientModal')).hide();
                    
                    // Reload page after short delay
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showAlert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while updating patient information.');
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-save me-2"></i>Update Information';
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

        // Add phone formatting to all phone inputs
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function() {
                formatPhoneNumber(this);
            });
        });

        // PhilHealth number formatting
        function formatPhilHealth(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length > 12) value = value.slice(0, 12);
            
            if (value.length >= 2) {
                value = value.slice(0, 2) + '-' + value.slice(2);
            }
            if (value.length >= 12) {
                value = value.slice(0, 12) + '-' + value.slice(12);
            }
            
            input.value = value;
        }

        // Add PhilHealth formatting
        document.querySelectorAll('input[name="philhealth_number"]').forEach(input => {
            input.addEventListener('input', function() {
                formatPhilHealth(this);
            });
        });

        // Clear form validation on input
        document.querySelectorAll('.form-control, .form-select').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });

        // Auto-submit filter form on change (only for select dropdowns)
        document.querySelectorAll('#filterForm select').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        });

        // Search requires manual submission (removed auto-search)

        // Username validation
        document.querySelector('input[name="username"]').addEventListener('input', function() {
            const username = this.value.toLowerCase().replace(/[^a-z0-9._-]/g, '');
            this.value = username;
        });


        function deactivatePatient(patientId) {
            if (!confirm('Are you sure you want to deactivate this patient account? They will not be able to log in.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'deactivate_patient');
            formData.append('patient_id', patientId);
            
            fetch('Patients.php', {
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
                showAlert('An error occurred. Please try again.');
            });
        }

        function activatePatient(patientId) {
            if (!confirm('Are you sure you want to activate this patient account?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'activate_patient');
            formData.append('patient_id', patientId);
            
            fetch('Patients.php', {
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
                showAlert('An error occurred. Please try again.');
            });
        }

                // Show alert inside modal
        function showModalAlert(message, type = 'danger') {
            const alertDiv = document.getElementById('passwordChangeAlert');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            alertDiv.style.display = 'block';
            
            // Scroll to top of modal to show alert
            document.querySelector('#changePasswordModal .modal-body').scrollTop = 0;
            
            if (type === 'success') {
                setTimeout(() => {
                    alertDiv.style.display = 'none';
                }, 5000);
            }
        }

        // Change Password Function
        function changePassword(patientId, patientName) {
            document.getElementById('change_password_patient_id').value = patientId;
            document.getElementById('change_password_patient_name').value = patientName;
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
            document.getElementById('patientRequestedChange').checked = false;
            
            const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            modal.show();
        }

        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('new_password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Confirm change password
        document.getElementById('confirmChangePassword').addEventListener('click', function() {
            const patientId = document.getElementById('change_password_patient_id').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const patientRequested = document.getElementById('patientRequestedChange').checked;
            
            // Clear previous alerts
            document.getElementById('passwordChangeAlert').style.display = 'none';
            
            // Validation
            if (!newPassword || !confirmPassword) {
                showModalAlert('Please fill in all password fields.');
                return;
            }
            
            if (newPassword.length < 6) {
                showModalAlert('Password must be at least 6 characters long.');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                showModalAlert('Passwords do not match. Please try again.');
                return;
            }
            
            if (!patientRequested) {
                showModalAlert('Please confirm that the patient requested this change in person.');
                return;
            }
            
            if (!confirm('Are you sure you want to change this patient\'s password? The patient will be notified of this change.')) {
                return;
            }
            
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Changing...';
            
            const formData = new FormData();
            formData.append('action', 'change_password');
            formData.append('patient_id', patientId);
            formData.append('new_password', newPassword);
            
            fetch('Patients.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showModalAlert(data.message, 'success');
                    
                    // Show success message with instructions after short delay
                    setTimeout(() => {
                        bootstrap.Modal.getInstance(document.getElementById('changePasswordModal')).hide();
                        alert('Password changed successfully!\n\nPlease inform the patient to:\n1. Use the new password to log in\n2. Change their password immediately after logging in\n3. Keep their password secure');
                        location.reload();
                    }, 1500);
                } else {
                    showModalAlert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showModalAlert('An error occurred while changing the password.');
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-check me-2"></i>Change Password';
            });
        });

        // Clear password fields when modal closes
        document.getElementById('changePasswordModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('changePasswordForm').reset();
            document.getElementById('new_password').type = 'password';
            document.getElementById('passwordChangeAlert').style.display = 'none';
            const icon = document.querySelector('#togglePassword i');
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        });

        // View Certificate Details Function
        function viewCertificateDetails(certificateId, certificateNumber) {
            const modal = new bootstrap.Modal(document.getElementById('certificateDetailsModal'));
            const content = document.getElementById('certificateDetailsContent');
            
            content.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br><p class="mt-3">Loading certificate details...</p></div>';
            modal.show();
            
            fetch('get_certificate_details.php?certificate_id=' + certificateId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cert = data.certificate;
                        
                        content.innerHTML = `
                            <div class="alert alert-info mb-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Certificate Number:</strong> ${cert.certificate_number}<br>
                                        <strong>Certificate Type:</strong> ${cert.certificate_type}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Date Issued:</strong> ${new Date(cert.date_issued).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}<br>
                                        <strong>Purpose:</strong> ${cert.purpose}
                                    </div>
                                </div>
                            </div>
                            
                            ${cert.valid_from && cert.valid_until ? `
                                <div class="alert alert-success mb-3">
                                    <strong><i class="fas fa-calendar-check me-2"></i>Validity Period:</strong><br>
                                    ${new Date(cert.valid_from).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} 
                                    to 
                                    ${new Date(cert.valid_until).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                                </div>
                            ` : ''}
                            
                            <h6 class="text-primary mb-3"><i class="fas fa-clipboard-check me-2"></i>Medical Findings (What appears on certificate)</h6>
                            
                            ${cert.impressions ? `
                                <div class="card mb-3 border-primary">
                                    <div class="card-header bg-primary text-white">
                                        <strong>IMPRESSIONS:</strong>
                                    </div>
                                    <div class="card-body">
                                        ${cert.impressions}
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${cert.recommendations ? `
                                <div class="card mb-3 border-success">
                                    <div class="card-header bg-success text-white">
                                        <strong>RECOMMENDATIONS:</strong>
                                    </div>
                                    <div class="card-body">
                                        ${cert.recommendations.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${cert.remarks ? `
                                <div class="card mb-3 border-warning">
                                    <div class="card-header bg-warning text-dark">
                                        <strong>REMARKS:</strong>
                                    </div>
                                    <div class="card-body">
                                        ${cert.remarks.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${cert.fitness_status ? `
                                <div class="alert alert-${cert.fitness_status.toLowerCase().includes('fit') ? 'success' : 'warning'} mb-3">
                                    <strong><i class="fas fa-heartbeat me-2"></i>Fitness Status:</strong> 
                                    <span class="badge bg-${cert.fitness_status.toLowerCase().includes('fit') ? 'success' : 'warning'}">${cert.fitness_status}</span>
                                </div>
                            ` : ''}
                            
                            ${cert.restrictions ? `
                                <div class="alert alert-danger mb-3">
                                    <strong><i class="fas fa-exclamation-triangle me-2"></i>Restrictions:</strong><br>
                                    ${cert.restrictions}
                                </div>
                            ` : ''}
                            
                            <div class="border-top pt-3 mt-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Patient Name:</strong> ${cert.patient_name}<br>
                                        <strong>Age/Gender:</strong> ${cert.patient_age} years / ${cert.patient_gender}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Examined By:</strong> Dr. ${cert.doctor_name}<br>
                                        ${cert.examination_date ? `<strong>Examination Date:</strong> ${new Date(cert.examination_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}` : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        // Store certificate ID for print button
                        document.getElementById('printCertificateFromDetails').setAttribute('data-certificate-id', certificateId);
                        
                    } else {
                        content.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = '<div class="alert alert-danger">Error loading certificate details.</div>';
                });
        }

        // Print certificate from details modal
        document.getElementById('printCertificateFromDetails').addEventListener('click', function() {
            const certificateId = this.getAttribute('data-certificate-id');
            if (certificateId) {
                window.open('print_certificate.php?id=' + certificateId, '_blank');
            }
        });

        // View Consultation Details Function
        function viewConsultationDetails(consultationId) {
            const modal = new bootstrap.Modal(document.getElementById('consultationDetailsModal'));
            const content = document.getElementById('consultationDetailsContent');
            
            content.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><br><p class="mt-3">Loading consultation details...</p></div>';
            modal.show();
            
            fetch('get_consultation_details.php?consultation_id=' + consultationId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const c = data.consultation;
                        
                        content.innerHTML = `
                            <!-- Consultation Header -->
                            <div class="alert alert-primary mb-3">
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>Consultation Number:</strong> ${c.consultation_number || 'N/A'}<br>
                                        <strong>Date:</strong> ${new Date(c.consultation_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Type:</strong> <span class="badge bg-info">${c.consultation_type}</span><br>
                                        <strong>Status:</strong> <span class="badge bg-${c.status === 'completed' ? 'success' : 'warning'}">${c.status}</span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Doctor:</strong> Dr. ${c.doctor_name || 'Not assigned'}<br>
                                        <strong>Patient:</strong> ${c.patient_name}
                                    </div>
                                </div>
                            </div>

                            <!-- Chief Complaint -->
                            ${c.chief_complaint ? `
                                <div class="card mb-3 border-warning">
                                    <div class="card-header bg-warning text-dark">
                                        <strong><i class="fas fa-exclamation-circle me-2"></i>Chief Complaint</strong>
                                    </div>
                                    <div class="card-body">
                                        ${c.chief_complaint}
                                    </div>
                                </div>
                            ` : ''}

                            <!-- Vital Signs -->
                            ${c.temperature || c.blood_pressure_systolic || c.heart_rate ? `
                                <h6 class="text-primary mb-3"><i class="fas fa-heartbeat me-2"></i>Vital Signs</h6>
                                <div class="row mb-3">
                                    ${c.temperature ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-danger">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-danger">${c.temperature}°C</h5>
                                                    <small>Temperature</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${c.blood_pressure_systolic && c.blood_pressure_diastolic ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-primary">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-primary">${c.blood_pressure_systolic}/${c.blood_pressure_diastolic}</h5>
                                                    <small>Blood Pressure</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${c.heart_rate ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-success">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-success">${c.heart_rate} bpm</h5>
                                                    <small>Heart Rate</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${c.respiratory_rate ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-info">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-info">${c.respiratory_rate}/min</h5>
                                                    <small>Respiratory Rate</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                                <div class="row mb-3">
                                    ${c.oxygen_saturation ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-primary">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-primary">${c.oxygen_saturation}%</h5>
                                                    <small>O₂ Saturation</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${c.weight ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-secondary">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-secondary">${c.weight} kg</h5>
                                                    <small>Weight</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${c.height ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-secondary">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-secondary">${c.height} cm</h5>
                                                    <small>Height</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${c.bmi ? `
                                        <div class="col-md-3">
                                            <div class="card text-center border-warning">
                                                <div class="card-body p-2">
                                                    <h5 class="mb-0 text-warning">${c.bmi}</h5>
                                                    <small>BMI</small>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}

                            <!-- Physical Examination -->
                            ${c.general_appearance || c.heent_exam || c.respiratory_exam || c.cardiovascular_exam || c.abdomen_exam || c.musculoskeletal_exam ? `
                                <h6 class="text-primary mb-3"><i class="fas fa-user-md me-2"></i>Physical Examination</h6>
                                <div class="card mb-3 border-info">
                                    <div class="card-body">
                                        ${c.general_appearance ? `<p><strong>General Appearance:</strong> ${c.general_appearance}</p>` : ''}
                                        ${c.heent_exam ? `<p><strong>HEENT:</strong> ${c.heent_exam}</p>` : ''}
                                        ${c.respiratory_exam ? `<p><strong>Respiratory:</strong> ${c.respiratory_exam}</p>` : ''}
                                        ${c.cardiovascular_exam ? `<p><strong>Cardiovascular:</strong> ${c.cardiovascular_exam}</p>` : ''}
                                        ${c.abdomen_exam ? `<p><strong>Abdomen:</strong> ${c.abdomen_exam}</p>` : ''}
                                        ${c.musculoskeletal_exam ? `<p class="mb-0"><strong>Musculoskeletal:</strong> ${c.musculoskeletal_exam}</p>` : ''}
                                    </div>
                                </div>
                            ` : ''}

                            <!-- Diagnosis & Assessment -->
                            <h6 class="text-primary mb-3"><i class="fas fa-clipboard-check me-2"></i>Diagnosis & Assessment</h6>
                            ${c.diagnosis ? `
                                <div class="card mb-3 border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <strong>Diagnosis / Medical Impressions</strong>
                                    </div>
                                    <div class="card-body">
                                        ${c.diagnosis}
                                    </div>
                                </div>
                            ` : ''}

                            ${c.impressions ? `
                                <div class="card mb-3 border-primary">
                                    <div class="card-header bg-primary text-white">
                                        <strong>Impressions (Certificate Summary)</strong>
                                    </div>
                                    <div class="card-body">
                                        ${c.impressions}
                                    </div>
                                </div>
                            ` : ''}

                            <!-- Treatment Plan -->
                            ${c.treatment_plan ? `
                                <h6 class="text-success mb-3"><i class="fas fa-notes-medical me-2"></i>Treatment Plan</h6>
                                <div class="card mb-3 border-success">
                                    <div class="card-body">
                                        ${c.treatment_plan.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            ` : ''}

                            <!-- Recommendations & Follow-up -->
                            <div class="row">
                                ${c.recommendations ? `
                                    <div class="col-md-6">
                                        <div class="card mb-3 border-success">
                                            <div class="card-header bg-success text-white">
                                                <strong>Recommendations</strong>
                                            </div>
                                            <div class="card-body">
                                                ${c.recommendations.replace(/\n/g, '<br>')}
                                            </div>
                                        </div>
                                    </div>
                                ` : ''}
                                ${c.follow_up_date ? `
                                    <div class="col-md-6">
                                        <div class="alert alert-info">
                                            <strong><i class="fas fa-calendar-check me-2"></i>Follow-up Date:</strong><br>
                                            ${new Date(c.follow_up_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                                        </div>
                                    </div>
                                ` : ''}
                            </div>

                            ${c.remarks ? `
                                <div class="card mb-3 border-warning">
                                    <div class="card-header bg-warning text-dark">
                                        <strong>Remarks / Additional Notes</strong>
                                    </div>
                                    <div class="card-body">
                                        ${c.remarks.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            ` : ''}

                            ${c.fitness_status || c.restrictions ? `
                                <div class="row">
                                    ${c.fitness_status ? `
                                        <div class="col-md-6">
                                            <div class="alert alert-success">
                                                <strong><i class="fas fa-heartbeat me-2"></i>Fitness Status:</strong>
                                                <span class="badge bg-success ms-2">${c.fitness_status}</span>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${c.restrictions ? `
                                        <div class="col-md-6">
                                            <div class="alert alert-danger">
                                                <strong><i class="fas fa-exclamation-triangle me-2"></i>Restrictions:</strong><br>
                                                ${c.restrictions}
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                        `;
                    } else {
                        content.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = '<div class="alert alert-danger">Error loading consultation details.</div>';
                });
        }

        // View Lab Result Details Function
        function viewLabDetails(labId) {
            const modal = new bootstrap.Modal(document.getElementById('labDetailsModal'));
            const content = document.getElementById('labDetailsContent');
            
            content.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-success"></i><br><p class="mt-3">Loading lab result details...</p></div>';
            modal.show();
            
            fetch('get_lab_details.php?lab_id=' + labId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const lab = data.lab_result;
                        const results = lab.test_results ? JSON.parse(lab.test_results) : {};
                        
                        content.innerHTML = `
                            <!-- Lab Header -->
                            <div class="alert alert-success mb-3">
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>Lab Number:</strong> ${lab.lab_number}<br>
                                        <strong>Test Date:</strong> ${new Date(lab.test_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Test Type:</strong> ${lab.test_type}<br>
                                        <strong>Category:</strong> <span class="badge bg-info">${lab.test_category}</span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Patient:</strong> ${lab.patient_name}<br>
                                        <strong>Status:</strong> <span class="badge bg-${lab.status === 'completed' ? 'success' : 'warning'}">${lab.status}</span>
                                    </div>
                                </div>
                            </div>

                            ${lab.specimen_type ? `
                                <div class="alert alert-info">
                                    <strong><i class="fas fa-vial me-2"></i>Specimen Type:</strong> ${lab.specimen_type}
                                </div>
                            ` : ''}

                            <!-- Test Results -->
                            <h6 class="text-success mb-3"><i class="fas fa-clipboard-list me-2"></i>Test Results</h6>
                            <div class="card mb-3 border-success">
                                <div class="card-body">
                                    <div class="row">
                                        ${Object.entries(results).map(([key, value]) => `
                                            <div class="col-md-4 mb-2">
                                                <strong>${key.replace(/_/g, ' ')}:</strong> ${value || '-'}
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            </div>

                            ${lab.normal_range ? `
                                <div class="card mb-3 border-info">
                                    <div class="card-header bg-info text-white">
                                        <strong>Normal Range / Reference Values</strong>
                                    </div>
                                    <div class="card-body">
                                        ${lab.normal_range.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            ` : ''}

                            ${lab.interpretation ? `
                                <div class="card mb-3 border-warning">
                                    <div class="card-header bg-warning text-dark">
                                        <strong>Interpretation</strong>
                                    </div>
                                    <div class="card-body">
                                        ${lab.interpretation.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            ` : ''}

                            ${lab.remarks ? `
                                <div class="card mb-3 border-secondary">
                                    <div class="card-header bg-secondary text-white">
                                        <strong>Remarks / Notes</strong>
                                    </div>
                                    <div class="card-body">
                                        ${lab.remarks.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            ` : ''}

                            <div class="border-top pt-3 mt-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Performed By:</strong> ${lab.performed_by_name || 'N/A'}<br>
                                        ${lab.result_date ? `<strong>Result Date:</strong> ${new Date(lab.result_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}` : ''}
                                    </div>
                                    <div class="col-md-6">
                                        ${lab.verified_by_name ? `<strong>Verified By:</strong> ${lab.verified_by_name}<br>` : ''}
                                        <strong>Patient Age/Sex:</strong> ${lab.patient_age}y/${lab.patient_gender}
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        content.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = '<div class="alert alert-danger">Error loading lab result details.</div>';
                });
        }
    </script>
</body>
</html>