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
    SELECT s.*, u.username, u.password_changed_at, u.last_login, u.created_at as user_created_at
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

// Handle contact information update
if (isset($_POST['action']) && $_POST['action'] == 'update_contact') {
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    
    try {
        $stmt = $pdo->prepare("
            UPDATE staff 
            SET phone = ?, email = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$phone, $email, $staff['id']]);
        
        // Log the update
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, module, record_id, new_values) 
            VALUES (?, 'CONTACT_UPDATE', 'Staff', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $staff['id'],
            json_encode(['phone' => $phone, 'email' => $email])
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Contact information updated successfully!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating contact information.']);
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
        echo json_encode(['success' => false, 'message' => 'Error changing password.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile & Settings - RHU Admin</title>
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

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px 15px;
            margin-bottom: 1rem;
        }

        .form-control:focus, .form-select:focus {
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

        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
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
                <h1 class="page-title">Profile & Settings</h1>
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
                </div>
            </nav>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div id="alertMessage" style="display: none;"></div>

                <!-- Staff Information Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3 class="section-title">Staff Information</h3>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-lock me-2"></i>System Information (Read Only)
                        </div>
                        <div class="alert alert-info" style="font-size: 0.9rem;">
                            <i class="fas fa-info-circle me-2"></i>
                            These details can only be modified by the Super Administrator. Contact them if any information needs to be updated.
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label readonly-label">Staff ID</label>
                                <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($staff['employee_id']); ?>" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label readonly-label">Username</label>
                                <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($staff['username']); ?>" readonly>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label readonly-label">First Name</label>
                                <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($staff['first_name']); ?>" readonly>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label readonly-label">Middle Name</label>
                                <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($staff['middle_name'] ?? ''); ?>" readonly>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label readonly-label">Last Name</label>
                                <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($staff['last_name']); ?>" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label readonly-label">Position</label>
                                <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($staff['position']); ?>" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label readonly-label">Department</label>
                                <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($staff['department']); ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Editable Contact Information -->
                    <form id="contactForm">
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-edit me-2"></i>Contact Information
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" placeholder="09XX-XXX-XXXX" value="<?php echo htmlspecialchars($staff['phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label editable-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" placeholder="your.email@example.com" value="<?php echo htmlspecialchars($staff['email'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn-save" id="saveContact">
                                <i class="fas fa-save me-2"></i>Save Contact Info
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
                            </ul>
                        </div>

                        <div class="text-end mt-3">
                            <button type="submit" class="btn-save" id="changePassword">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Account Activity Section -->
                <div class="content-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h3 class="section-title">Account Activity</h3>
                    </div>
                    
                    <div class="form-section">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Account Created:</strong> <?php echo date('F j, Y g:i A', strtotime($staff['user_created_at'])); ?></p>
                                <p><strong>Last Login:</strong> <?php echo $staff['last_login'] ? date('F j, Y g:i A', strtotime($staff['last_login'])) : 'Never'; ?></p>
                            </div>
                            <div class="col-md-6">
                                <p id="lastContactUpdate"><strong>Last Contact Update:</strong> <?php echo $staff['updated_at'] ? date('F j, Y g:i A', strtotime($staff['updated_at'])) : 'Never updated'; ?></p>
                                <p id="lastPasswordChange"><strong>Last Password Change:</strong> <?php echo $staff['password_changed_at'] ? date('F j, Y g:i A', strtotime($staff['password_changed_at'])) : 'Never changed'; ?></p>
                            </div>
                        </div>
                        <div class="alert alert-success mt-3">
                            <i class="fas fa-shield-alt me-2"></i>
                            <strong>Account Status:</strong> Active | <strong>Role:</strong> RHU Administrator
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function showAlert(message, type = 'danger') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
            `;
            alertDiv.style.display = 'block';
            window.scrollTo({top: 0, behavior: 'smooth'});
            
            if (type === 'success') {
                setTimeout(() => { alertDiv.style.display = 'none'; }, 5000);
            }
        }

        // Handle contact information update
        document.getElementById('contactForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const saveButton = document.getElementById('saveContact');
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

            const formData = new FormData();
            formData.append('action', 'update_contact');
            formData.append('phone', document.getElementById('phone').value);
            formData.append('email', document.getElementById('email').value);

            fetch('Profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    
                    // Update timestamp
                    const now = new Date();
                    const formattedDate = now.toLocaleDateString('en-US', { 
                        year: 'numeric', month: 'long', day: 'numeric', 
                        hour: 'numeric', minute: '2-digit', hour12: true 
                    });
                    document.getElementById('lastContactUpdate').innerHTML = '<strong>Last Contact Update:</strong> ' + formattedDate;
                } else {
                    showAlert(data.message || 'Error updating contact information');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred. Please try again.');
            })
            .finally(() => {
                saveButton.disabled = false;
                saveButton.innerHTML = '<i class="fas fa-save me-2"></i>Save Contact Info';
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
                    
                    // Update timestamp
                    const now = new Date();
                    const formattedDate = now.toLocaleDateString('en-US', { 
                        year: 'numeric', month: 'long', day: 'numeric', 
                        hour: 'numeric', minute: '2-digit', hour12: true 
                    });
                    document.getElementById('lastPasswordChange').innerHTML = '<strong>Last Password Change:</strong> ' + formattedDate;
                    
                    // Clear password fields
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
        document.getElementById('phone').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);
            
            if (value.length >= 4) {
                value = value.slice(0, 4) + '-' + value.slice(4);
            }
            if (value.length >= 9) {
                value = value.slice(0, 9) + '-' + value.slice(9);
            }
            
            this.value = value;
        });
    </script>
</body>
</html>