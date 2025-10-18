<?php
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

// Get certificate number from QR code scan
$cert_number = $_GET['cert'] ?? '';
$certificate = null;
$error = '';

if ($cert_number) {
    // Fetch certificate details (minimal info for verification only)
    $stmt = $pdo->prepare("
        SELECT mc.certificate_number,
               mc.certificate_type,
               mc.date_issued,
               mc.valid_from,
               mc.valid_until,
               mc.fitness_status,
               COALESCE(mc.patient_full_name_override, CONCAT(p.first_name, ' ', p.last_name)) as patient_name,
               CONCAT(s.first_name, ' ', s.last_name) as doctor_name
        FROM medical_certificates mc
        JOIN patients p ON mc.patient_id = p.id
        LEFT JOIN staff s ON mc.issued_by = s.id
        WHERE mc.certificate_number = ? 
        AND mc.status IN ('ready_for_download', 'downloaded')
    ");
    $stmt->execute([$cert_number]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$certificate) {
        $error = 'Certificate not found or not yet issued.';
    } else {
        // Check if expired
        if ($certificate['valid_until'] && strtotime($certificate['valid_until']) < time()) {
            $certificate['is_expired'] = true;
        } else {
            $certificate['is_expired'] = false;
        }
    }
} else {
    $error = 'No certificate number provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Medical Certificate - Kawit RHU</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --kawit-pink: #FFA6BE;
            --dark-pink: #FF7A9A;
            --text-dark: #2c3e50;
            --kawit-gradient: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%);
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .verify-container {
            max-width: 600px;
            width: 100%;
        }

        .header-card {
            background: white;
            border-radius: 20px 20px 0 0;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .header-logo {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 1rem;
        }

        .header-logo img {
            max-width: 60px;
            height: auto;
        }

        .header-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .certificate-card {
            background: white;
            border-radius: 0 0 20px 20px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .verification-badge {
            text-align: center;
            padding: 2rem 1.5rem;
            background: var(--kawit-gradient);
            border-radius: 15px;
            color: white;
            margin-bottom: 2rem;
        }

        .verification-badge i {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .verification-badge h3 {
            margin: 0 0 0.5rem 0;
            font-weight: 700;
            font-size: 1.5rem;
        }

        .error-badge {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .info-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #dee2e6;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .info-value {
            color: var(--text-dark);
            font-weight: 600;
            text-align: right;
        }

        .validity-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .validity-valid {
            background: #d4edda;
            color: #155724;
        }

        .validity-expired {
            background: #f8d7da;
            color: #721c24;
        }

        .seal-section {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #dee2e6;
        }

        .seal-icon {
            width: 70px;
            height: 70px;
            background: var(--kawit-gradient);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .info-item {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .info-value {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <!-- Header -->
        <div class="header-card">
            <div class="header-logo">
                <img src="Pictures/logo2.png" alt="Logo 1" onerror="this.style.display='none'">
                <img src="Pictures/logo1.png" alt="Logo 2" onerror="this.style.display='none'">
                <img src="Pictures/logo3.png" alt="Logo 3" onerror="this.style.display='none'">
            </div>
            <h1 class="header-title">Kawit Rural Health Unit</h1>
            <p class="header-subtitle">Certificate Verification System</p>
        </div>

        <!-- Certificate Verification -->
        <div class="certificate-card">
            <?php if ($error): ?>
                <!-- Error State -->
                <div class="verification-badge error-badge">
                    <i class="fas fa-times-circle"></i>
                    <h3>Verification Failed</h3>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                </div>
                
                <div class="text-center">
                    <p class="text-muted mb-3">The certificate could not be verified. Please check the QR code and try again.</p>
                </div>
                
            <?php else: ?>
                <!-- Success State -->
                <div class="verification-badge">
                    <i class="fas fa-check-circle"></i>
                    <h3>✓ Certificate Verified</h3>
                    <p class="mb-0">This is an authentic medical certificate</p>
                </div>

                <!-- Essential Information Only -->
                <div class="info-card">
                    <div class="info-item">
                        <span class="info-label">Certificate No.</span>
                        <span class="info-value"><?php echo htmlspecialchars($certificate['certificate_number']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Type</span>
                        <span class="info-value"><?php echo htmlspecialchars($certificate['certificate_type']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Patient Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($certificate['patient_name']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Date Issued</span>
                        <span class="info-value"><?php echo date('M j, Y', strtotime($certificate['date_issued'])); ?></span>
                    </div>
                    
                    <?php if ($certificate['valid_from'] && $certificate['valid_until']): ?>
                    <div class="info-item">
                        <span class="info-label">Validity</span>
                        <span class="info-value">
                            <?php echo date('M j, Y', strtotime($certificate['valid_from'])); ?> - 
                            <?php echo date('M j, Y', strtotime($certificate['valid_until'])); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <?php if ($certificate['is_expired']): ?>
                                <span class="validity-badge validity-expired">
                                    <i class="fas fa-exclamation-triangle me-1"></i>EXPIRED
                                </span>
                            <?php else: ?>
                                <span class="validity-badge validity-valid">
                                    <i class="fas fa-check me-1"></i>VALID
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <?php if ($certificate['fitness_status']): ?>
                    <div class="info-item">
                        <span class="info-label">Fitness Status</span>
                        <span class="info-value"><?php echo htmlspecialchars($certificate['fitness_status']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <span class="info-label">Issued By</span>
                        <span class="info-value">Dr. <?php echo htmlspecialchars($certificate['doctor_name']); ?></span>
                    </div>
                </div>

                <!-- Official Seal -->
                <div class="seal-section">
                    <div class="seal-icon">
                        <i class="fas fa-stamp"></i>
                    </div>
                    <p class="mb-1" style="font-weight: 600; color: var(--text-dark);">
                        Official Medical Certificate
                    </p>
                    <p class="text-muted mb-0" style="font-size: 0.85rem;">
                        Kawit Rural Health Unit<br>
                        Municipality of Kawit, Cavite<br>
                        <small>Digitally Verified & Authenticated</small>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>