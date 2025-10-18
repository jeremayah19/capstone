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
    
    // Log logout activity if user is logged in
    if (isset($_SESSION['user_id'])) {
        $logStmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, ip_address, user_agent) 
            VALUES (?, 'LOGOUT', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'], 
            $_SERVER['REMOTE_ADDR'], 
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }
} catch(PDOException $e) {
    // Continue with logout even if logging fails
}

// Clear all session variables
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-42000, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page with logout message
header('Location: login.php?message=logged_out');
exit;
?>