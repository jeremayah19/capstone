<?php
session_start();

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

// Handle login request
if (isset($_POST['action']) && $_POST['action'] == 'login') {
    $loginUser = trim($_POST['username']);
    $loginPass = trim($_POST['password']);
    
    try {
        // Get user info with proper joins
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   CASE 
                       WHEN u.role = 'patient' THEN p.first_name
                       ELSE s.first_name 
                   END as first_name,
                   CASE 
                       WHEN u.role = 'patient' THEN p.last_name
                       ELSE s.last_name 
                   END as last_name,
                   CASE 
                       WHEN u.role = 'patient' THEN p.patient_id
                       WHEN u.role IN ('rhu_admin', 'bhs_admin', 'pharmacy_admin', 'super_admin') THEN s.employee_id 
                       ELSE NULL
                   END as user_identifier,
                   CASE 
                       WHEN u.role = 'bhs_admin' THEN s.assigned_barangay_id
                       ELSE NULL
                   END as assigned_barangay_id
            FROM users u 
            LEFT JOIN patients p ON u.id = p.user_id AND u.role = 'patient'
            LEFT JOIN staff s ON u.id = s.user_id AND u.role IN ('rhu_admin', 'bhs_admin', 'pharmacy_admin', 'super_admin')
            WHERE u.username = ? AND u.is_active = 1
        ");
        $stmt->execute([$loginUser]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
            exit;
        }
        
        // Check if account is locked
        if ($user['locked_until'] && new DateTime() < new DateTime($user['locked_until'])) {
            $lockTime = new DateTime($user['locked_until']);
            $remaining = $lockTime->diff(new DateTime());
            echo json_encode([
                'success' => false, 
                'message' => 'Account locked. Try again in ' . $remaining->format('%i') . ' minutes.'
            ]);
            exit;
        }
        
        // Verify password
        if (password_verify($loginPass, $user['password'])) {
            // Reset login attempts on successful login
            $resetStmt = $pdo->prepare("
                UPDATE users 
                SET login_attempts = 0, locked_until = NULL, last_login = NOW() 
                WHERE id = ?
            ");
            $resetStmt->execute([$user['id']]);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = trim($user['first_name'] . ' ' . $user['last_name']);
            $_SESSION['user_identifier'] = $user['user_identifier'];
            $_SESSION['assigned_barangay_id'] = $user['assigned_barangay_id'];
            $_SESSION['login_time'] = date('Y-m-d H:i:s');
            
            // Create session record
            $sessionToken = bin2hex(random_bytes(32));
            $sessionStmt = $pdo->prepare("
                INSERT INTO user_sessions (user_id, session_token, expires_at, ip_address, user_agent) 
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?, ?)
            ");
            $sessionStmt->execute([
                $user['id'], 
                $sessionToken, 
                $_SERVER['REMOTE_ADDR'] ?? 'unknown', 
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            $_SESSION['session_token'] = $sessionToken;
            
            // Log successful login
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, ip_address, user_agent) 
                VALUES (?, 'LOGIN_SUCCESS', 'Authentication', ?, ?)
            ");
            $logStmt->execute([
                $user['id'], 
                $_SERVER['REMOTE_ADDR'] ?? 'unknown', 
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            // Determine redirect based on role
            $redirect = '';
            switch ($user['role']) {
                case 'patient':
                    $redirect = 'Patient/Dashboard.php';
                    break;
                case 'super_admin':
                    $redirect = 'Super_Admin/Dashboard.php';
                    break;
                case 'rhu_admin':
                    $redirect = 'Admin/Rural_Health_Unit/Dashboard.php';
                    break;
                case 'bhs_admin':
                    $redirect = 'Admin/Barangay_Health_Station/Dashboard.php';
                    break;
                case 'pharmacy_admin':
                    $redirect = 'Admin/Pharmacy_Admin/Dashboard.php';
                    break;
                default:
                    $redirect = 'login.php?error=invalid_role';
            }
            
            echo json_encode(['success' => true, 'redirect' => $redirect]);
        } else {
            // Increment login attempts
            $newAttempts = $user['login_attempts'] + 1;
            $lockUntil = null;
            
            // Lock account if too many failed attempts (5 attempts = 30 minute lock)
            if ($newAttempts >= 5) {
                $lockUntil = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            }
            
            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET login_attempts = ?, locked_until = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$newAttempts, $lockUntil, $user['id']]);
            
            // Log failed login attempt
            $logStmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, module, ip_address, user_agent) 
                VALUES (?, 'LOGIN_FAILED', 'Authentication', ?, ?)
            ");
            $logStmt->execute([
                $user['id'], 
                $_SERVER['REMOTE_ADDR'] ?? 'unknown', 
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            if ($lockUntil) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Too many failed attempts. Account locked for 30 minutes.'
                ]);
            } else {
                $remaining = 5 - $newAttempts;
                echo json_encode([
                    'success' => false, 
                    'message' => "Invalid username or password. $remaining attempts remaining."
                ]);
            }
        }
    } catch (Exception $e) {
        // Log the error
        error_log("Login error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Login error occurred. Please try again.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kawit RHU - Login Portal</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body {
      background-color: #FFA6BE;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .login-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 20px rgba(0,0,0,0.1);
      padding: 2rem;
      max-width: 400px;
      width: 100%;
    }
    .login-card h1 {
      font-size: 1.6rem;
      font-weight: bold;
      color: #d63384;
      text-align: center;
      margin-bottom: 1rem;
    }
    .form-control {
      border-radius: 10px;
      border: 1px solid #ddd;
      padding: 12px 15px;
    }
    .form-control:focus {
      border-color: #d63384;
      box-shadow: 0 0 0 0.2rem rgba(214, 51, 132, 0.25);
    }
    .btn-login {
      background-color: #d63384;
      border: none;
      color: #fff;
      font-weight: 600;
      border-radius: 10px;
      padding: 12px;
      width: 100%;
      transition: all 0.3s;
    }
    .btn-login:hover {
      background-color: #b52a6d;
      color: #fff;
    }
    .btn-login:disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }
    .forgot-password {
      font-size: 0.9rem;
      color: #d63384;
      text-decoration: none;
    }
    .forgot-password:hover {
      text-decoration: underline;
      color: #b52a6d;
    }
    .contact-info {
      text-align: center;
      margin-top: 1rem;
      font-size: 0.9rem;
    }
    .contact-info a {
      display: block;
      color: #333;
      text-decoration: none;
      margin: 2px 0;
      transition: color 0.3s;
    }
    .contact-info a:hover {
      color: #d63384;
    }
    .note {
      text-align: center;
      font-size: 0.9rem;
      color: #d63384;
      font-weight: bold;
      margin-top: 1rem;
    }
    .toggle-password-btn {
      background: none;
      border: none;
      color: #666;
      transition: color 0.3s;
    }
    .toggle-password-btn:hover {
      color: #d63384;
    }
    .alert {
      border-radius: 10px;
      margin-bottom: 1rem;
    }
    .logo-container {
      display: flex;
      justify-content: center;
      gap: 15px;
      margin-bottom: 1rem;
    }
    .logo-container img {
      max-width: 80px;
      height: auto;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
    }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="logo-container">
      <img src="Pictures/logo2.png" alt="Kawit Logo" onerror="this.style.display='none'">
      <img src="Pictures/logo1.png" alt="RHU Logo" onerror="this.style.display='none'">
      <img src="Pictures/logo3.png" alt="DOH Logo" onerror="this.style.display='none'">
    </div>

    <h1>Kawit RHU Portal</h1>
    <p class="text-center text-muted">Log in to access your account</p>

    <div id="alertMessage" style="display: none;"></div>

    <form id="loginForm">
      <div class="mb-3">
        <input type="text" id="username" class="form-control" placeholder="Username" required>
      </div>
      <div class="mb-3 position-relative">
        <input type="password" id="password" class="form-control" placeholder="Password" required>
        <button type="button" class="toggle-password-btn position-absolute top-50 end-0 translate-middle-y me-2" onclick="togglePassword()">
          <i class="fas fa-eye" id="passwordToggleIcon"></i>
        </button>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="rememberMe">
          <label class="form-check-label" for="rememberMe">Remember me</label>
        </div>
        <a href="#" class="forgot-password" onclick="showForgotPasswordInfo(); return false;">Forgot password?</a>
      </div>
      <button type="submit" class="btn-login" id="loginButton">Log In</button>
    </form>

    <div class="contact-info">
      <a href="#"><i class="fas fa-phone me-2"></i>431-9941</a>
      <a href="mailto:kawitrhu@gmail.com"><i class="fas fa-envelope me-2"></i>kawitrhu@gmail.com</a>
      <a href="https://www.facebook.com/kawitrhuofficial" target="_blank"><i class="fab fa-facebook me-2"></i>kawitrhuofficial</a>
    </div>

    <p class="note">Don't have an account?<br>You must register at the RHU front desk.</p>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script>
    function togglePassword() {
      const passwordField = document.getElementById('password');
      const toggleIcon = document.getElementById('passwordToggleIcon');
      if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
      } else {
        passwordField.type = 'password';
        toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
      }
    }

    function showAlert(message, type = 'danger') {
      const alertDiv = document.getElementById('alertMessage');
      alertDiv.className = `alert alert-${type}`;
      alertDiv.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
        ${message}
      `;
      alertDiv.style.display = 'block';
      
      if (type === 'success') {
        setTimeout(() => {
          alertDiv.style.display = 'none';
        }, 2000);
      }
    }

    function showForgotPasswordInfo() {
      alert("Password Reset Instructions:\n\n" +
            "For security reasons, password resets must be done through our staff.\n\n" +
            "Please contact Kawit RHU:\n" +
            "📧 Email: kawitrhu@gmail.com\n" +
            "🏥 Visit: RHU Front Desk\n\n" +
            "Please bring a valid ID.\n" +
            "Our staff will verify your identity and reset your password.");
    }

    document.getElementById('loginForm').addEventListener('submit', function(event) {
      event.preventDefault();

      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value.trim();
      const loginButton = document.getElementById('loginButton');

      if (!username || !password) {
        showAlert('Please enter both username and password');
        return;
      }

      // Show loading state
      loginButton.disabled = true;
      loginButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Logging in...';

      // Send AJAX request to PHP backend
      const formData = new FormData();
      formData.append('action', 'login');
      formData.append('username', username);
      formData.append('password', password);

      fetch('login.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          loginButton.innerHTML = '<i class="fas fa-check me-2"></i>Success! Redirecting...';
          showAlert('Login successful! Redirecting...', 'success');
          
          setTimeout(() => {
            window.location.href = data.redirect;
          }, 1500);
        } else {
          showAlert(data.message || 'Login failed. Please try again.');
          loginButton.disabled = false;
          loginButton.innerHTML = 'Log In';
        }
      })
      .catch(error => {
        console.error('Login error:', error);
        showAlert('An error occurred. Please try again.');
        loginButton.disabled = false;
        loginButton.innerHTML = 'Log In';
      });
    });
  </script>
</body>
</html>