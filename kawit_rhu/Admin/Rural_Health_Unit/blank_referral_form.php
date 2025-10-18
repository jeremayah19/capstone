<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Referral Form - Blank</title>
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
            margin: -8px -8px 8px -8px;
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
        
        .input-line {
            border-bottom: 1px solid #000;
            height: 22px;
            width: 100%;
        }
        
        .input-box {
            border: 1px solid #000;
            min-height: 30px;
            padding: 4px;
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
        }
        
        .checkbox {
            width: 14px;
            height: 14px;
            border: 2px solid #000;
            display: inline-block;
        }
        
        .signature-section {
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 45%;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            margin-top: 20px;
            padding-top: 3px;
            font-weight: bold;
            font-size: 9px;
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
    <!-- Print Button -->
    <div class="buttons no-print">
        <button class="btn" onclick="window.print()">🖨️ PRINT BLANK FORM</button>
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
                <span>Kawit, Cavite</span>
                <span>Tel: (046) 434-0000</span>
                <span>Email: kawitrhu@gov.ph</span>
            </div>
        </div>
        
        <!-- Form Title -->
        <div class="form-title">PATIENT REFERRAL FORM</div>
        
        <!-- Referral Information -->
        <div class="form-section">
            <div class="section-title">REFERRAL INFORMATION</div>
            <div class="form-row">
                <div class="form-field" style="flex: 1.5;">
                    <label>REFERRAL NUMBER:</label>
                    <div class="input-line"></div>
                </div>
                <div class="form-field">
                    <label>DATE:</label>
                    <div class="input-line"></div>
                </div>
            </div>
        </div>
        
        <!-- Patient Information -->
        <div class="form-section">
            <div class="section-title">PATIENT INFORMATION</div>
            
            <div class="form-row">
                <div class="form-field" style="flex: 3;">
                    <label>NAME:</label>
                    <div class="input-line"></div>
                </div>
                <div class="form-field">
                    <label>AGE:</label>
                    <div class="input-line"></div>
                </div>
                <div class="form-field">
                    <label>SEX:</label>
                    <div class="input-line"></div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-field">
                    <label>ADDRESS:</label>
                    <div class="input-line"></div>
                </div>
            </div>
        </div>
        
        <!-- COVID-19 Vaccination -->
        <div class="form-section">
            <div class="section-title">COVID-19 VACCINATION STATUS</div>
            <div class="checkbox-group" style="font-size: 10px;">
                <div class="checkbox-item">
                    <span class="checkbox"></span>
                    <span>PRIMARY SERIES</span>
                </div>
                <div class="checkbox-item">
                    <span class="checkbox"></span>
                    <span>BOOSTER</span>
                </div>
                <div class="checkbox-item">
                    <span class="checkbox"></span>
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
                    <div class="input-line"></div>
                </div>
                <div class="form-field">
                    <label>BLOOD PRESSURE:</label>
                    <div class="input-line"></div>
                </div>
                <div class="form-field">
                    <label>PULSE RATE:</label>
                    <div class="input-line"></div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>RESPIRATORY RATE:</label>
                    <div class="input-line"></div>
                </div>
                <div class="form-field">
                    <label>OXYGEN SATURATION:</label>
                    <div class="input-line"></div>
                </div>
            </div>
        </div>
        
        <!-- Referred To -->
        <div class="form-section">
            <div class="section-title">REFERRED TO</div>
            <div class="form-row">
                <div class="form-field">
                    <label>FACILITY/HOSPITAL:</label>
                    <div class="input-line"></div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>DOCTOR / DEPARTMENT / SPECIALTY:</label>
                    <div class="input-line"></div>
                </div>
            </div>
        </div>
        
        <!-- Clinical Information -->
        <div class="form-section">
            <div class="section-title">CLINICAL INFORMATION</div>
            
        <div class="form-field" style="margin-bottom: 6px;">
                <label>CHIEF COMPLAINT:</label>
                <div class="input-box"></div>
            </div>
            
            <div class="form-field" style="margin-bottom: 6px;">
                <label>INITIAL DIAGNOSIS:</label>
                <div class="input-box"></div>
            </div>
            
            <div class="form-field">
                <label>MANAGEMENT PROVIDED:</label>
                <div class="input-box"></div>
            </div>
        </div>
        
        <!-- Physician Signature -->
        <div class="signature-section">
            <div class="signature-box">
                <div style="text-align: center; margin-top: 15px;">
                    <div style="height: 30px; border-bottom: 2px solid #000;"></div>
                    <div style="padding-top: 3px; font-size: 8px;">
                        Signature over Printed Name<br>
                        Referring Physician / Position<br>
                    </div>
                </div>
            </div>
            
            <div class="signature-box">
                <div style="text-align: center; margin-top: 15px;">
                    <div style="height: 30px; border-bottom: 2px solid #000;"></div>
                    <div style="padding-top: 3px; font-size: 8px;">
                        Date
                </div>
            </div>
        </div>
        
    </div>
</body>
</html>