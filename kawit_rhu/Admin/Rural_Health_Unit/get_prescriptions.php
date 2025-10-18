<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rhu_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$host = 'localhost';
$dbname = 'kawit_rhu';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$consultation_id = $_GET['consultation_id'] ?? null;

if (!$consultation_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Consultation ID required']);
    exit;
}

// Get prescriptions
$stmt = $pdo->prepare("
    SELECT * FROM prescriptions 
    WHERE consultation_id = ?
    ORDER BY created_at
");
$stmt->execute([$consultation_id]);
$prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'prescriptions' => $prescriptions
]);
?>