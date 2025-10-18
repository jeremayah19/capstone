<?php
session_start();

// Check if user is logged in (admin OR patient)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Allow both admin and patient to generate certificates
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

// Get certificate ID
$certificate_id = $_GET['id'] ?? null;

if (!$certificate_id) {
    die("No certificate ID provided");
}

// Fetch certificate details with security check
$query = "
    SELECT mc.*, 
           p.patient_id, p.first_name, p.middle_name, p.last_name, p.suffix,
           p.date_of_birth, p.gender, p.address, p.phone, p.user_id as patient_user_id,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age,
           b.barangay_name,
           COALESCE(s_assigned.first_name, s_issued.first_name) as doctor_first_name,
           COALESCE(s_assigned.last_name, s_issued.last_name) as doctor_last_name,
           COALESCE(s_assigned.position, s_issued.position) as position,
           COALESCE(s_assigned.license_number, s_issued.license_number) as license_number
    FROM medical_certificates mc
    JOIN patients p ON mc.patient_id = p.id
    LEFT JOIN barangays b ON p.barangay_id = b.id
    LEFT JOIN staff s_assigned ON mc.assigned_doctor_id = s_assigned.id
    LEFT JOIN staff s_issued ON mc.issued_by = s_issued.id
    WHERE mc.id = ?
";

// SECURITY: If user is a patient, only allow their own certificates
if ($_SESSION['role'] === 'patient') {
    $query .= " AND p.user_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$certificate_id, $_SESSION['user_id']]);
} else {
    // Admin can view any certificate
    $stmt = $pdo->prepare($query);
    $stmt->execute([$certificate_id]);
}

$certificate = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$certificate) {
    die("Certificate not found or you don't have permission to view it");
}

// SECURITY: Only allow download if status is ready or downloaded
if (!in_array($certificate['status'], ['ready_for_download', 'downloaded'])) {
    die("Certificate is not yet available for download. Current status: " . $certificate['status']);
}

// Use override values if they exist
$patient_full_name = $certificate['patient_full_name_override'] 
    ? $certificate['patient_full_name_override']
    : trim($certificate['first_name'] . ' ' . 
           ($certificate['middle_name'] ? $certificate['middle_name'] . ' ' : '') . 
           $certificate['last_name'] . 
           ($certificate['suffix'] ? ' ' . $certificate['suffix'] : ''));

$patient_age = $certificate['patient_age_override'] ?? $certificate['age'];
$patient_gender = $certificate['patient_gender_override'] ?? $certificate['gender'];
$patient_address = $certificate['patient_address_override'] ?? 
    ($certificate['address'] . ', ' . ($certificate['barangay_name'] ?? ''));
$examination_date = $certificate['examination_date'] ?? $certificate['valid_from'] ?? $certificate['date_issued'];

// Build doctor name
$doctor_full_name = trim($certificate['doctor_first_name'] . ' ' . $certificate['doctor_last_name']);

// Generate QR Code with proper URL
// Get current domain and protocol
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$base_url = $protocol . "://" . $domain . dirname($_SERVER['PHP_SELF']);
$verification_url = $protocol . "://" . $domain . "/kawit_rhu/verify_certificate.php?cert=" . $certificate['certificate_number'];

$qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($verification_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Certificate - <?php echo htmlspecialchars($certificate['certificate_number']); ?></title>
    <style>
        @page {
            size: A5 portrait;
            margin: 0;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .certificate-container {
                box-shadow: none !important;
                page-break-after: avoid;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #000;
            background: #f0f0f0;
            padding: 20px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .certificate-container {
            width: 14.85cm;
            height: auto;
            max-height: 21cm;
            margin: 0 auto;
            background: white;
            padding: 1cm;
            position: relative;
            overflow: hidden;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .header-logos {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .logo-left, .logo-right {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .logo-left img, .logo-right img {
            height: 50px;
            width: auto;
        }

        .header-text {
            flex: 1;
            padding: 0 15px;
        }
        
        .header .line1 {
            font-size: 8pt;
            font-weight: bold;
        }
        
        .header .line2 {
            font-size: 8pt;
            font-weight: bold;
        }
        
        .header .line3 {
            font-size: 9pt;
            font-weight: bold;
            margin-top: 2px;
        }
        
        .header .line4 {
            font-size: 8pt;
            margin-top: 2px;
        }
        
        .title {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            margin: 10px 0 8px 0;
            letter-spacing: 1px;
        }

        .control-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 9pt;
        }

        .control-number {
            text-align: left;
        }

        .date-field {
            text-align: right;
        }
        
        .content {
            font-size: 9pt;
            line-height: 1.6;
            margin-bottom: 8px;
        }

        .content p {
            margin-bottom: 8px;
            text-indent: 30px;
        }

        .underline {
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 120px;
            padding: 0 5px;
        }

        .section-row {
            display: flex;
            align-items: flex-start;
            margin-top: 8px;
            gap: 10px;
        }

        .section-label {
            font-weight: bold;
            min-width: 120px;
            flex-shrink: 0;
        }

        .section-content {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 20px;
            padding: 0 5px;
        }
        
        .signature-section {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .qr-code-area {
            width: 100px;
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-code-area img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .signature-box {
            text-align: center;
            width: 220px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 3px;
        }
        
        .doctor-name {
            font-weight: bold;
            font-size: 9pt;
        }
        
        .doctor-title {
            font-size: 8pt;
        }
        
        .license {
            font-size: 7pt;
            margin-top: 2px;
        }

        .footer-note {
            position: absolute;
            bottom: 0.5cm;
            left: 1cm;
            right: 1cm;
            font-size: 7pt;
            text-align: center;
            font-style: italic;
            color: #666;
        }
        
        .print-button, .close-button {
            position: fixed;
            top: 20px;
            padding: 10px 20px;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12pt;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            z-index: 1000;
        }

        .print-button {
            right: 140px;
            background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%);
        }

        .print-button:hover {
            background: linear-gradient(135deg, #FF7A9A 0%, #FF6B8A 100%);
        }

        .close-button {
            right: 20px;
            background: #f44336;
        }

        .close-button:hover {
            background: #da190b;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-button" onclick="window.print()">
            <i class="fas fa-download"></i> Download as PDF
        </button>
        <button class="close-button" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <div class="certificate-container">
        <!-- Header with Logos -->
        <div class="header">
            <div class="header-logos">
                <div class="logo-left">
                    <img src="Pictures/logo1.png" alt="DOH Logo" onerror="this.style.display='none'">
                    <img src="Pictures/logo2.png" alt="Kawit Logo" onerror="this.style.display='none'">
                </div>
                
                <div class="header-text">
                    <div class="line1">REPUBLIC OF THE PHILIPPINES</div>
                    <div class="line2">PROVINCE OF CAVITE</div>
                    <div class="line2">MUNICIPALITY OF KAWIT</div>
                    <div class="line3">OFFICE OF THE MUNICIPAL HEALTH OFFICER</div>
                    <div class="line4">Brgy. Tabon II, Kawit, Cavite</div>
                </div>

                <div class="logo-right">
                    <img src="Pictures/logo3.png" alt="RHU Logo" onerror="this.style.display='none'">
                    <img src="Pictures/logo4.png" alt="Buong Puso Logo" onerror="this.style.display='none'">
                </div>
            </div>
        </div>

        <!-- Title -->
        <div class="title">MEDICAL CERTIFICATE</div>

        <!-- Control Number and Date -->
        <div class="control-section">
            <div class="control-number">
                <strong>CONTROL NO.:</strong> <span class="underline"><?php echo htmlspecialchars($certificate['certificate_number']); ?></span>
            </div>
            <div class="date-field">
                <strong>Date:</strong> <span class="underline"><?php echo date('F j, Y', strtotime($certificate['date_issued'])); ?></span>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <p>
                This is to certify that Mr./Ms. <strong><?php echo htmlspecialchars($patient_full_name); ?></strong>,
                <?php echo $patient_age; ?> years of age, <?php echo $patient_gender; ?>, and a 
                resident of <?php echo htmlspecialchars($patient_address); ?>,
                Kawit, Cavite, was seen and examined in this facility on 
                <strong><?php echo date('F j, Y', strtotime($examination_date)); ?></strong> 
                for the purpose of <strong><?php echo htmlspecialchars($certificate['purpose'] ?? 'Medical Evaluation'); ?></strong>.
            </p>

            <!-- Impressions Section (Medical Findings) -->
            <div class="section-row">
                <div class="section-label">IMPRESSIONS:</div>
                <div class="section-content">
                    <?php echo $certificate['impressions'] ? htmlspecialchars($certificate['impressions']) : 'No significant findings'; ?>
                </div>
            </div>

            <!-- Recommendations Section -->
            <div class="section-row">
                <div class="section-label">RECOMMENDATIONS:</div>
                <div class="section-content">
                    <?php echo $certificate['recommendations'] ? htmlspecialchars($certificate['recommendations']) : 'None'; ?>
                </div>
            </div>

            <!-- Remarks Section -->
            <div class="section-row">
                <div class="section-label">REMARKS:</div>
                <div class="section-content">
                    <?php 
                    // Use custom remarks if provided, otherwise combine fitness status and restrictions
                    if ($certificate['remarks']) {
                        echo htmlspecialchars($certificate['remarks']);
                    } else {
                        $remarks = [];
                        if ($certificate['fitness_status']) {
                            $remarks[] = 'Fitness Status: ' . htmlspecialchars($certificate['fitness_status']);
                        }
                        if ($certificate['restrictions']) {
                            $remarks[] = 'Restrictions: ' . htmlspecialchars($certificate['restrictions']);
                        }
                        echo !empty($remarks) ? implode(' | ', $remarks) : 'Patient cleared for normal activities';
                    }
                    ?>
                </div>
            </div>

            <!-- Validity Period -->
            <?php if ($certificate['valid_from'] && $certificate['valid_until']): ?>
            <div class="section-row">
                <div class="section-label">Valid Period:</div>
                <div class="section-content">
                    <?php echo date('M j, Y', strtotime($certificate['valid_from'])); ?> to <?php echo date('M j, Y', strtotime($certificate['valid_until'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Signature Section -->
        <div class="signature-section">
            <!-- QR Code instead of Dry Seal -->
            <div class="qr-code-area">
                <img src="<?php echo $qr_code_url; ?>" alt="QR Code">
            </div>

            <div class="signature-box">
                <div class="signature-line">
                    <div class="doctor-name"><?php echo strtoupper(htmlspecialchars($doctor_full_name)); ?>, MD</div>
                    <div class="doctor-title">Municipal Health Officer - Kawit, Cavite</div>
                    <?php if ($certificate['license_number']): ?>
                    <div class="license">PRC License No. <?php echo htmlspecialchars($certificate['license_number']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer Note -->
        <div class="footer-note">
            *Scan QR code to verify authenticity.*
        </div>
    </div>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</body>
</html>