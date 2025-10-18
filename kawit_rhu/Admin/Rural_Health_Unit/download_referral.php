<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
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

// Get referral ID
$referral_id = $_GET['id'] ?? null;

if (!$referral_id) {
    die("Referral ID not provided");
}

// Get referral details
$stmt = $pdo->prepare("
    SELECT r.*, 
           p.patient_id, p.first_name, p.middle_name, p.last_name, p.suffix,
           p.date_of_birth, p.gender, p.address,
           b.barangay_name,
           YEAR(CURDATE()) - YEAR(p.date_of_birth) as age,
           CONCAT(s.first_name, ' ', s.last_name) as referred_by_name,
           s.position, s.license_number
    FROM referrals r
    JOIN patients p ON r.patient_id = p.id
    LEFT JOIN barangays b ON p.barangay_id = b.id
    LEFT JOIN staff s ON r.referred_by = s.id
    WHERE r.id = ?
");
$stmt->execute([$referral_id]);
$referral = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$referral) {
    die("Referral not found");
}

// Get vital signs from database fields (new) or parse from clinical_summary (backward compatibility)
$vitals = [
    'temp' => $referral['temperature'] ?? '',
    'bp' => $referral['blood_pressure'] ?? '',
    'pr' => $referral['pulse_rate'] ?? '',
    'rr' => $referral['respiratory_rate'] ?? '',
    'o2' => $referral['oxygen_saturation'] ?? ''
];

// Fallback: Parse from clinical_summary if database fields are empty (backward compatibility)
if (empty($vitals['temp']) && $referral['clinical_summary']) {
    if (preg_match('/Temp:\s*([^\n,°C]+)/i', $referral['clinical_summary'], $match)) {
        $vitals['temp'] = trim($match[1]);
    }
}
if (empty($vitals['bp']) && $referral['clinical_summary']) {
    if (preg_match('/BP:\s*([^\n,]+)/i', $referral['clinical_summary'], $match)) {
        $vitals['bp'] = trim($match[1]);
    }
}
if (empty($vitals['pr']) && $referral['clinical_summary']) {
    if (preg_match('/PR:\s*([^\n,bpm]+)/i', $referral['clinical_summary'], $match)) {
        $vitals['pr'] = trim($match[1]);
    }
}
if (empty($vitals['rr']) && $referral['clinical_summary']) {
    if (preg_match('/RR:\s*([^\n,cpm]+)/i', $referral['clinical_summary'], $match)) {
        $vitals['rr'] = trim($match[1]);
    }
}
if (empty($vitals['o2']) && $referral['clinical_summary']) {
    if (preg_match('/O2\s*Sat:\s*([^\n,%]+)/i', $referral['clinical_summary'], $match)) {
        $vitals['o2'] = trim($match[1]);
    }
}

// Get COVID vaccination status
$covid_vaccination = $referral['covid_vaccination_status'] ?? 'unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Referral Form - <?php echo $referral['referral_number']; ?></title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        
        @media print {
            @page {
                margin: 0;
                size: A4;
            }
            body {
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none !important;
            }
            .page {
                margin: 0;
                padding: 8mm;
                box-shadow: none;
                border: none;
                page-break-after: avoid;
                page-break-inside: avoid;
                height: 297mm;
                max-height: 297mm;
                overflow: hidden;
            }
            /* Remove browser default header/footer */
            html, body {
                margin: 0 !important;
                padding: 0 !important;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background: white;
            font-size: 11px;
            line-height: 1.3;
            margin: 0;
            padding: 0;
        }
        
        .page {
            width: 210mm;
            height: 297mm;
            max-height: 297mm;
            padding: 8mm;
            margin: 10mm auto;
            background: white;
            box-shadow: none;
            overflow: hidden;
            page-break-after: avoid;
        }
        
        /* Header */
        .form-header {
            border: 2px solid #000;
            padding: 8px;
            margin-bottom: 10px;
            box-shadow: none;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #000;
        }
        
        .logo-section {
            display: flex;
            gap: 10px;
        }
        
        .logo-section img {
            width: 50px;
            height: 50px;
        }
        
        .header-text {
            flex: 1;
            text-align: center;
        }
        
        .header-text h3 {
            font-size: 9px;
            margin: 1px 0;
            font-weight: normal;
        }
        
        .header-text h1 {
            font-size: 14px;
            margin: 2px 0;
            font-weight: bold;
        }
        
        .header-text h2 {
            font-size: 11px;
            margin: 1px 0;
            font-weight: bold;
        }
        
        .header-bottom {
            display: flex;
            justify-content: space-around;
            padding-top: 6px;
            font-size: 8px;
        }
        
        .form-title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 8px 0;
            padding: 6px;
            background: #000;
            color: white;
            letter-spacing: 2px;
        }
        
        /* Form sections */
        .form-section {
            border: 1px solid #000;
            margin-bottom: 6px;
            padding: 6px;
        }
        
        .section-title {
            background: #000;
            color: white;
            padding: 4px 8px;
            font-weight: bold;
            font-size: 11px;
            margin: -6px -6px 8px -6px;
        }
        
        .form-row {
            display: flex;
            gap: 8px;
            margin-bottom: 6px;
        }
        
        .form-field {
            flex: 1;
        }
        
        .form-field label {
            display: block;
            font-weight: bold;
            margin-bottom: 2px;
            font-size: 9px;
        }
        
        .form-field .value {
            border-bottom: 1px solid #000;
            padding: 3px 5px;
            min-height: 20px;
            font-size: 11px;
        }
        
        .checkbox-group {
            display: flex;
            gap: 10px;
            margin-top: 3px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
        }
        
        .checkbox {
            width: 14px;
            height: 14px;
            border: 2px solid #000;
            display: inline-block;
            text-align: center;
            line-height: 14px;
            font-weight: bold;
            font-size: 10px;
        }
        
        .checked {
            background: #000;
            color: white;
        }
        
        .text-area {
            border: 1px solid #000;
            padding: 4px;
            min-height: 30px;
            white-space: pre-line;
            font-size: 9px;
            line-height: 1.3;
        }
        
        .signature-section {
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            margin-top: 20px;
            padding-top: 3px;
            font-weight: bold;
            font-size: 9px;
        }
        
        .signature-label {
            font-size: 8px;
            color: #666;
            margin-top: 2px;
        }
        
        .buttons {
            text-align: center;
            margin: 20px 0;
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: white;
            padding: 15px 30px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        
        .btn {
            background: linear-gradient(135deg, #FFA6BE 0%, #FF7A9A 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            margin: 0 5px;
            font-weight: bold;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
    </style>
</head>
<body>
<!-- Print Buttons -->
    <div class="buttons no-print">
        <button class="btn" onclick="window.print()">🖨️ PRINT REFERRAL</button>
        <button class="btn btn-secondary" onclick="window.close()">✖️ CLOSE</button>
    </div>
    
    <div class="page">
        <!-- Header -->
        <div class="form-header">
                <div class="header-top">
                <div class="logo-section">
                    <img src="../../Pictures/logo1.png" alt="Kawit Logo">
                    <img src="../../Pictures/logo2.png" alt="RHU Logo">
                </div>
                <div class="header-text">
                    <h3>Republic of the Philippines</h3>
                    <h3>Province of Cavite</h3>
                    <h3>Municipality of Kawit</h3>
                    <h1>RURAL HEALTH UNIT</h1>
                    <h2>HEALTH INFORMATION MANAGEMENT SYSTEM</h2>
                </div>
                <div class="logo-section">
                    <img src="../../Pictures/logo3.png" alt="DOH Logo">
                    <img src="../../Pictures/logo4.png" alt="Logo 4">
                </div>
            </div>
            <div class="header-bottom">
                <span>📍 Kawit, Cavite</span>
                <span>📞 (046) 434-0000</span>
                <span>✉️ kawitrhu@gov.ph</span>
            </div>
        </div>
        
        <!-- Form Title -->
        <div class="form-title">PATIENT REFERRAL FORM</div>
        
        <!-- Referral Info -->
        <div class="form-section">
            <div class="section-title">REFERRAL INFORMATION</div>
            <div class="form-row">
                <div class="form-field" style="flex: 1.5;">
                    <label>REFERRAL NUMBER:</label>
                    <div class="value" style="font-weight: bold;">
                        <?php echo htmlspecialchars($referral['referral_number']); ?>
                    </div>
                </div>
                <div class="form-field">
                    <label>DATE:</label>
                    <div class="value"><?php echo date('F d, Y', strtotime($referral['referral_date'])); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Patient Information -->
        <div class="form-section">
            <div class="section-title">PATIENT INFORMATION</div>
            
            <div class="form-row">
                <div class="form-field" style="flex: 3;">
                    <label>NAME:</label>
                    <div class="value" style="font-weight: bold;">
                        <?php echo strtoupper(htmlspecialchars($referral['first_name'] . ' ' . ($referral['middle_name'] ? $referral['middle_name'] . ' ' : '') . $referral['last_name'] . ($referral['suffix'] ? ' ' . $referral['suffix'] : ''))); ?>
                    </div>
                </div>
                <div class="form-field">
                    <label>AGE:</label>
                    <div class="value"><?php echo $referral['age']; ?></div>
                </div>
                <div class="form-field">
                    <label>SEX:</label>
                    <div class="value"><?php echo htmlspecialchars($referral['gender']); ?></div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-field">
                    <label>ADDRESS:</label>
                    <div class="value"><?php echo htmlspecialchars($referral['address'] . ', ' . ($referral['barangay_name'] ?: '')); ?></div>
                </div>
            </div>
        </div>
        
        <!-- COVID-19 Vaccination -->
        <div class="form-section">
            <div class="section-title">COVID-19 VACCINATION STATUS</div>
            <div class="checkbox-group">
                <div class="checkbox-item">
                    <span class="checkbox <?php echo $covid_vaccination === 'primary_series' ? 'checked' : ''; ?>">
                        <?php echo $covid_vaccination === 'primary_series' ? '✓' : ''; ?>
                    </span>
                    <span>PRIMARY SERIES</span>
                </div>
                <div class="checkbox-item">
                    <span class="checkbox <?php echo $covid_vaccination === 'booster' ? 'checked' : ''; ?>">
                        <?php echo $covid_vaccination === 'booster' ? '✓' : ''; ?>
                    </span>
                    <span>BOOSTER</span>
                </div>
                <div class="checkbox-item">
                    <span class="checkbox <?php echo $covid_vaccination === 'unvaccinated' ? 'checked' : ''; ?>">
                        <?php echo $covid_vaccination === 'unvaccinated' ? '✓' : ''; ?>
                    </span>
                    <span>UNVACCINATED</span>
                </div>
            </div>
        </div>
        
        <!-- Vital Signs -->
        <div class="form-section">
            <div class="section-title">VITAL SIGNS</div>
            <div class="form-row">
                <div class="form-field">
                    <label>TEMPERATURE:</label>
                    <div class="value"><?php echo htmlspecialchars($vitals['temp']); ?><?php echo $vitals['temp'] && !strpos($vitals['temp'], '°') ? '°C' : ''; ?></div>
                </div>
                <div class="form-field">
                    <label>BLOOD PRESSURE:</label>
                    <div class="value"><?php echo htmlspecialchars($vitals['bp']); ?></div>
                </div>
                <div class="form-field">
                    <label>PULSE RATE:</label>
                    <div class="value"><?php echo htmlspecialchars($vitals['pr']); ?><?php echo $vitals['pr'] && !strpos($vitals['pr'], 'bpm') ? ' bpm' : ''; ?></div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>RESPIRATORY RATE:</label>
                    <div class="value"><?php echo htmlspecialchars($vitals['rr']); ?><?php echo $vitals['rr'] && !strpos($vitals['rr'], 'cpm') ? ' cpm' : ''; ?></div>
                </div>
                <div class="form-field">
                    <label>OXYGEN SATURATION:</label>
                    <div class="value"><?php echo htmlspecialchars($vitals['o2']); ?><?php echo $vitals['o2'] && !strpos($vitals['o2'], '%') ? '%' : ''; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Referred To -->
        <div class="form-section">
            <div class="section-title">REFERRED TO</div>
            <div class="form-row">
                <div class="form-field">
                    <label>FACILITY/HOSPITAL:</label>
                    <div class="value" style="font-weight: bold;">
                        <?php echo strtoupper(htmlspecialchars($referral['referred_to_facility'])); ?>
                    </div>
                </div>
            </div>
            <?php if ($referral['referred_to_doctor']): ?>
            <div class="form-row">
                <div class="form-field">
                    <label>DOCTOR / DEPARTMENT:</label>
                    <div class="value"><?php echo htmlspecialchars($referral['referred_to_doctor']); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Clinical Information -->
        <div class="form-section">
            <div class="section-title">CLINICAL INFORMATION</div>
            
            <div class="form-field" style="margin-bottom: 8px;">
                <label>CHIEF COMPLAINT:</label>
                <div class="text-area" style="min-height: 40px;">
                    <?php echo htmlspecialchars($referral['referral_reason']); ?>
                </div>
            </div>
            
            <div class="form-field" style="margin-bottom: 8px;">
                <label>INITIAL DIAGNOSIS:</label>
                <div class="text-area" style="min-height: 40px;">
                    <?php echo htmlspecialchars($referral['diagnosis']); ?>
                </div>
            </div>
            
            <div class="form-field">
                <label>MANAGEMENT PROVIDED:</label>
                <div class="text-area" style="min-height: 40px;">
                    <?php echo htmlspecialchars($referral['treatment_given'] ?: 'N/A'); ?>
                </div>
            </div>
        </div>
        
        <!-- Physician Signature -->
        <div class="signature-section">
            <div class="signature-box">
                <div style="text-align: center; margin-top: 15px;">
                    <div style="font-weight: bold; font-size: 10px; margin-bottom: 2px;">
                        <?php echo strtoupper(htmlspecialchars($referral['referred_by_name'])); ?>
                    </div>
                    <div style="border-top: 2px solid #000; padding-top: 3px; font-size: 8px; color: #666;">
                        <?php echo htmlspecialchars($referral['position']); ?>
                    </div>
                </div>
            </div>
            
            <div class="signature-box">
                <div style="text-align: center; margin-top: 15px;">
                    <div style="font-weight: bold; font-size: 10px; margin-bottom: 2px;">
                        <?php echo date('F d, Y', strtotime($referral['referral_date'])); ?>
                    </div>
                    <div style="border-top: 2px solid #000; padding-top: 3px; font-size: 8px; color: #666;">
                        DATE
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
</body>
</html>