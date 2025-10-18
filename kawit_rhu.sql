-- =====================================================
-- Kawit RHU Health Information Management System
-- Updated Database Schema with Google Meet Link Support
-- =====================================================

DROP DATABASE IF EXISTS kawit_rhu;
CREATE DATABASE kawit_rhu;
USE kawit_rhu;

-- =====================================================
-- CORE TABLES
-- =====================================================

-- Users table (authentication for all user types)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    role ENUM('super_admin', 'rhu_admin', 'bhs_admin', 'pharmacy_admin', 'patient') NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    login_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME,
    password_changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_email (email)
);

-- Sessions table for better session management
CREATE TABLE user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_token (session_token),
    INDEX idx_user_active (user_id, is_active)
);

-- Barangays table
CREATE TABLE barangays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barangay_name VARCHAR(100) NOT NULL,
    bhs_address TEXT,
    contact_number VARCHAR(20),
    bhs_coordinator_id INT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_barangay_name (barangay_name)
);

-- =====================================================
-- USER PROFILE TABLES
-- =====================================================

-- Patients table
CREATE TABLE patients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    patient_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    suffix VARCHAR(10),
    date_of_birth DATE NOT NULL,
    gender ENUM('Male', 'Female', 'Other') NOT NULL,
    civil_status ENUM('Single', 'Married', 'Widowed', 'Divorced', 'Separated') DEFAULT 'Single',
    address TEXT NOT NULL,
    barangay_id INT,
    phone VARCHAR(15),
    email VARCHAR(100),
    blood_type VARCHAR(5),
    allergies TEXT,
    medical_history TEXT,
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(15),
    emergency_contact_relationship VARCHAR(50),
    philhealth_number VARCHAR(20),
    occupation VARCHAR(100),
    educational_attainment VARCHAR(50),
    religion VARCHAR(50),
    profile_picture VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    INDEX idx_patient_id (patient_id),
    INDEX idx_name (last_name, first_name),
    INDEX idx_barangay (barangay_id)
);

-- Staff table (for all admin types)
CREATE TABLE staff (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    suffix VARCHAR(10),
    position VARCHAR(100) NOT NULL,
    department ENUM('RHU', 'BHS', 'Pharmacy', 'Administration') NOT NULL,
    assigned_barangay_id INT,
    phone VARCHAR(15),
    email VARCHAR(100),
    date_hired DATE,
    date_terminated DATE,
    employment_status ENUM('Active', 'Inactive', 'Terminated', 'On Leave') DEFAULT 'Active',
    license_number VARCHAR(50),
    license_expiry DATE,
    specialization VARCHAR(100),
    salary DECIMAL(10,2),
    profile_picture VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_barangay_id) REFERENCES barangays(id),
    INDEX idx_employee_id (employee_id),
    INDEX idx_department (department)
);

-- =====================================================
-- MEDICAL SERVICES TABLES
-- =====================================================

-- Service Types table
CREATE TABLE service_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_name VARCHAR(100) NOT NULL,
    service_category ENUM('General', 'Maternal', 'Child Care', 'Family Planning', 'Dental', 'Laboratory', 'Vaccination', 'Emergency') NOT NULL,
    description TEXT,
    duration_minutes INT DEFAULT 30,
    fee DECIMAL(8,2) DEFAULT 0.00,
    requires_appointment BOOLEAN DEFAULT TRUE,
    available_at ENUM('RHU', 'BHS', 'Both') DEFAULT 'Both',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Appointments table
CREATE TABLE appointments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    service_type_id INT,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    appointment_location ENUM('RHU', 'BHS') DEFAULT 'RHU',
    barangay_id INT,
    status ENUM('pending', 'confirmed', 'completed', 'cancelled', 'no-show', 'rescheduled') DEFAULT 'pending',
    notes TEXT,
    reason_for_visit TEXT,
    assigned_staff INT,
    created_by INT,
    confirmed_by INT,
    confirmed_at DATETIME,
    cancelled_reason TEXT,
    rescheduled_from INT,
    reminder_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id),
    FOREIGN KEY (assigned_staff) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (confirmed_by) REFERENCES staff(id),
    FOREIGN KEY (rescheduled_from) REFERENCES appointments(id),
    INDEX idx_appointment_date (appointment_date),
    INDEX idx_patient_appointments (patient_id, appointment_date),
    INDEX idx_status (status)
);

-- Consultations table (UPDATED: Added google_meet_link, removed consultation_fee)
CREATE TABLE consultations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    consultation_number VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    appointment_id INT,
    consultation_type ENUM('online', 'walk-in', 'follow-up', 'emergency') NOT NULL,
    consultation_date DATETIME NOT NULL,
    chief_complaint TEXT,
    history_of_present_illness TEXT,
    symptoms TEXT,
    vital_signs JSON,
    physical_examination TEXT,
    assessment TEXT,
    diagnosis TEXT,
    treatment_plan TEXT,
    medications_prescribed TEXT,
    recommendations TEXT,
    follow_up_instructions TEXT,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    assigned_doctor INT,
    consultation_location ENUM('RHU', 'BHS') DEFAULT 'RHU',
    google_meet_link VARCHAR(500) NULL,
    barangay_id INT,
    follow_up_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    FOREIGN KEY (assigned_doctor) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    INDEX idx_consultation_date (consultation_date),
    INDEX idx_patient_consultations (patient_id, consultation_date),
    INDEX idx_consultation_number (consultation_number),
    INDEX idx_consultation_type_status (consultation_type, status)
);

-- Laboratory Results table
CREATE TABLE laboratory_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lab_number VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    consultation_id INT,
    test_type VARCHAR(100) NOT NULL,
    test_category ENUM('Hematology', 'Chemistry', 'Urinalysis', 'Microbiology', 'Serology', 'Parasitology', 'Other') NOT NULL,
    test_date DATE NOT NULL,
    result_date DATE,
    specimen_type VARCHAR(50),
    test_results JSON,
    normal_range TEXT,
    interpretation TEXT,
    remarks TEXT,
    status ENUM('pending', 'processing', 'completed', 'cancelled') DEFAULT 'pending',
    performed_by INT,
    verified_by INT,
    released_by INT,
    lab_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by) REFERENCES staff(id),
    FOREIGN KEY (verified_by) REFERENCES staff(id),
    FOREIGN KEY (released_by) REFERENCES staff(id),
    INDEX idx_patient_labs (patient_id, test_date),
    INDEX idx_lab_number (lab_number)
);

-- Prescriptions table
CREATE TABLE prescriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prescription_number VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    consultation_id INT,
    medication_name VARCHAR(100) NOT NULL,
    generic_name VARCHAR(100),
    brand_name VARCHAR(100),
    dosage_strength VARCHAR(50) NOT NULL,
    dosage_form VARCHAR(50) NOT NULL,
    quantity_prescribed INT NOT NULL,
    quantity_dispensed INT DEFAULT 0,
    dosage_instructions TEXT NOT NULL,
    frequency VARCHAR(50) NOT NULL,
    duration VARCHAR(50) NOT NULL,
    special_instructions TEXT,
    prescribed_by INT NOT NULL,
    prescription_date DATE NOT NULL,
    dispensed_by INT,
    dispensed_date DATETIME,
    pharmacy_notes TEXT,
    status ENUM('pending', 'partially_dispensed', 'fully_dispensed', 'cancelled', 'expired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE SET NULL,
    FOREIGN KEY (prescribed_by) REFERENCES staff(id),
    FOREIGN KEY (dispensed_by) REFERENCES staff(id),
    INDEX idx_patient_prescriptions (patient_id, prescription_date),
    INDEX idx_prescription_number (prescription_number)
);

-- Medical Certificates table
CREATE TABLE medical_certificates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    certificate_number VARCHAR(50) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    consultation_id INT,
    issued_by INT NOT NULL,
    certificate_type ENUM('Fit to Work', 'Medical Certificate', 'Health Certificate', 'Vaccination Certificate', 'Other') NOT NULL,
    purpose VARCHAR(200) NOT NULL,
    date_issued DATE NOT NULL,
    valid_from DATE,
    valid_until DATE,
    diagnosis TEXT,
    physical_findings TEXT,
    recommendations TEXT,
    restrictions TEXT,
    fitness_status ENUM('Fit', 'Unfit', 'Conditional', 'Pending Further Evaluation') DEFAULT 'Fit',
    template_used VARCHAR(100),
    qr_code VARCHAR(255),
    digital_signature VARCHAR(255),
    status ENUM('draft', 'issued', 'expired', 'revoked') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id),
    FOREIGN KEY (issued_by) REFERENCES staff(id),
    INDEX idx_certificate_number (certificate_number),
    INDEX idx_patient_certificates (patient_id, date_issued)
);

-- Referrals table
CREATE TABLE referrals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    referral_number VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    consultation_id INT,
    referred_by INT NOT NULL,
    referred_to_facility VARCHAR(200) NOT NULL,
    referred_to_doctor VARCHAR(200),
    referral_reason TEXT NOT NULL,
    clinical_summary TEXT,
    diagnosis TEXT,
    treatment_given TEXT,
    urgency_level ENUM('routine', 'urgent', 'emergency') DEFAULT 'routine',
    referral_date DATE NOT NULL,
    expected_return_date DATE,
    transportation_needed BOOLEAN DEFAULT FALSE,
    status ENUM('pending', 'sent', 'accepted', 'completed', 'cancelled') DEFAULT 'pending',
    feedback TEXT,
    return_summary TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE SET NULL,
    FOREIGN KEY (referred_by) REFERENCES staff(id),
    INDEX idx_patient_referrals (patient_id, referral_date),
    INDEX idx_referral_number (referral_number)
);

-- =====================================================
-- PHARMACY TABLES
-- =====================================================

-- Medicine Categories table
CREATE TABLE medicine_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_controlled BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Medicine Inventory table
CREATE TABLE medicines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    medicine_code VARCHAR(50) UNIQUE NOT NULL,
    medicine_name VARCHAR(100) NOT NULL,
    generic_name VARCHAR(100) NOT NULL,
    brand VARCHAR(100),
    category_id INT,
    dosage_strength VARCHAR(50),
    dosage_form ENUM('Tablet', 'Capsule', 'Syrup', 'Injection', 'Drops', 'Cream', 'Ointment', 'Suppository', 'Other'),
    stock_quantity INT NOT NULL DEFAULT 0,
    unit_of_measure VARCHAR(20) DEFAULT 'piece',
    reorder_level INT NOT NULL DEFAULT 10,
    maximum_level INT NOT NULL DEFAULT 1000,
    expiry_date DATE,
    batch_number VARCHAR(50),
    lot_number VARCHAR(50),
    supplier VARCHAR(100),
    unit_cost DECIMAL(10,2) DEFAULT 0.00,
    selling_price DECIMAL(10,2) DEFAULT 0.00,
    storage_location VARCHAR(100),
    storage_temperature VARCHAR(50),
    requires_prescription BOOLEAN DEFAULT FALSE,
    is_controlled_substance BOOLEAN DEFAULT FALSE,
    barcode VARCHAR(100),
    manufacturer VARCHAR(100),
    date_received DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES medicine_categories(id),
    INDEX idx_medicine_name (medicine_name),
    INDEX idx_generic_name (generic_name),
    INDEX idx_expiry (expiry_date),
    INDEX idx_stock_level (stock_quantity, reorder_level)
);

-- Medicine Transactions table
CREATE TABLE medicine_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_number VARCHAR(20) UNIQUE NOT NULL,
    medicine_id INT NOT NULL,
    transaction_type ENUM('stock_in', 'dispensed', 'expired', 'damaged', 'returned', 'adjustment', 'transfer') NOT NULL,
    quantity_before INT NOT NULL,
    quantity_transacted INT NOT NULL,
    quantity_after INT NOT NULL,
    reference_id INT,
    reference_type VARCHAR(50),
    unit_cost DECIMAL(10,2),
    total_cost DECIMAL(10,2),
    transaction_date DATETIME NOT NULL,
    performed_by INT NOT NULL,
    approved_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id),
    FOREIGN KEY (performed_by) REFERENCES staff(id),
    FOREIGN KEY (approved_by) REFERENCES staff(id),
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_medicine_transactions (medicine_id, transaction_date)
);

-- =====================================================
-- ADMINISTRATIVE TABLES
-- =====================================================

-- Announcements table
CREATE TABLE announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    author_id INT NOT NULL,
    target_audience ENUM('all', 'patients', 'staff', 'rhu_staff', 'bhs_staff', 'pharmacy_staff') DEFAULT 'all',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    featured BOOLEAN DEFAULT FALSE,
    publish_date DATETIME,
    expiry_date DATETIME,
    attachment_path VARCHAR(255),
    view_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES staff(id),
    INDEX idx_status_publish (status, publish_date),
    INDEX idx_target_audience (target_audience)
);

-- Reports table
CREATE TABLE reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_number VARCHAR(20) UNIQUE NOT NULL,
    report_type ENUM('monthly_summary', 'consultation_report', 'medicine_inventory', 'patient_demographics', 'service_statistics', 'financial_report', 'other') NOT NULL,
    report_title VARCHAR(200) NOT NULL,
    report_period_start DATE NOT NULL,
    report_period_end DATE NOT NULL,
    barangay_id INT,
    department ENUM('RHU', 'BHS', 'Pharmacy', 'Administration'),
    generated_by INT NOT NULL,
    report_data JSON,
    summary_data JSON,
    file_path VARCHAR(255),
    report_date DATE NOT NULL,
    status ENUM('draft', 'submitted', 'approved', 'published') DEFAULT 'draft',
    approved_by INT,
    approved_date DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    FOREIGN KEY (generated_by) REFERENCES staff(id),
    FOREIGN KEY (approved_by) REFERENCES staff(id),
    INDEX idx_report_date (report_date),
    INDEX idx_report_type (report_type)
);

-- System Logs table
CREATE TABLE system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    log_level ENUM('INFO', 'WARNING', 'ERROR', 'CRITICAL') DEFAULT 'INFO',
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50),
    table_affected VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_logs (user_id, created_at),
    INDEX idx_log_level (log_level),
    INDEX idx_action (action)
);

-- Notifications table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type ENUM('appointment_reminder', 'lab_result', 'prescription_ready', 'announcement', 'system', 'referral_update', 'consultation_ready') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    data JSON,
    is_read BOOLEAN DEFAULT FALSE,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read),
    INDEX idx_created_at (created_at)
);

-- System Configuration table
CREATE TABLE system_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT,
    config_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key)
);

-- =====================================================
-- INSERT SAMPLE DATA
-- =====================================================

-- Insert Barangays
INSERT INTO barangays (barangay_name, bhs_address, contact_number) VALUES
('Binakayan-Kanluran', 'BHS Binakayan-Kanluran, Kawit, Cavite', '046-434-0001'),
('Binakayan-Silangan', 'BHS Binakayan-Silangan, Kawit, Cavite', '046-434-0002'),
('Kaingen', 'BHS Kaingen, Kawit, Cavite', '046-434-0003'),
('Gahak', 'BHS Gahak, Kawit, Cavite', '046-434-0004'),
('Panamitan', 'BHS Panamitan, Kawit, Cavite', '046-434-0005'),
('Marulas', 'BHS Marulas, Kawit, Cavite', '046-434-0006'),
('Putik', 'BHS Putik, Kawit, Cavite', '046-434-0007'),
('Tabon I', 'BHS Tabon I, Kawit, Cavite', '046-434-0008'),
('Tabon II', 'BHS Tabon II, Kawit, Cavite', '046-434-0009'),
('Tabon III', 'BHS Tabon III, Kawit, Cavite', '046-434-0010');

-- Insert Service Types
INSERT INTO service_types (service_name, service_category, description, duration_minutes, fee, available_at) VALUES
('General Consultation', 'General', 'Basic medical consultation', 30, 0.00, 'Both'),
('Prenatal Care', 'Maternal', 'Pregnancy monitoring and care', 45, 0.00, 'Both'),
('Child Immunization', 'Child Care', 'Vaccination for children', 20, 0.00, 'Both'),
('Family Planning', 'Family Planning', 'Contraceptive counseling and services', 30, 0.00, 'Both'),
('Dental Consultation', 'Dental', 'Dental examination and treatment', 60, 50.00, 'RHU'),
('Laboratory Tests', 'Laboratory', 'Various laboratory examinations', 15, 100.00, 'RHU'),
('Blood Pressure Monitoring', 'General', 'Hypertension monitoring', 15, 0.00, 'Both'),
('Diabetes Monitoring', 'General', 'Blood sugar monitoring', 20, 0.00, 'Both'),
('Emergency Consultation', 'Emergency', 'Emergency medical care', 60, 0.00, 'RHU');

-- Insert Medicine Categories
INSERT INTO medicine_categories (category_name, description, is_controlled) VALUES
('Analgesics/Antipyretics', 'Pain relievers and fever reducers', FALSE),
('Antibiotics', 'Antimicrobial medications', TRUE),
('Antihistamines', 'Allergy medications', FALSE),
('Antihypertensives', 'Blood pressure medications', TRUE),
('Antidiabetics', 'Diabetes medications', TRUE),
('Bronchodilators', 'Respiratory medications', TRUE),
('Anti-inflammatory', 'Inflammation reducers', FALSE),
('Gastrointestinal', 'Stomach and digestive medications', FALSE),
('Vitamins/Supplements', 'Nutritional supplements', FALSE),
('Topical Medications', 'Creams and ointments', FALSE);

-- Insert Sample Users (Password: 'password123')
INSERT INTO users (username, password, email, role) VALUES
('superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin@kawitrhu.gov.ph', 'super_admin'),
('rhu_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rhuadmin@kawitrhu.gov.ph', 'rhu_admin'),
('dr.reyes', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'dr.reyes@kawitrhu.gov.ph', 'rhu_admin'),
('bhs_binakayan', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'bhs.binakayan@kawitrhu.gov.ph', 'bhs_admin'),
('bhs_kaingen', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'bhs.kaingen@kawitrhu.gov.ph', 'bhs_admin'),
('bhs_gahak', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'bhs.gahak@kawitrhu.gov.ph', 'bhs_admin'),
('pharmacy_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pharmacy@kawitrhu.gov.ph', 'pharmacy_admin'),
('pharmacist_rose', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rose.tan@kawitrhu.gov.ph', 'pharmacy_admin'),
('juan.delacruz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'juan.delacruz@email.com', 'patient'),
('maria.santos', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'maria.santos@email.com', 'patient'),
('pedro.garcia', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pedro.garcia@email.com', 'patient'),
('ana.reyes.patient', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ana.reyes.patient@email.com', 'patient'),
('carlos.mendoza', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'carlos.mendoza@email.com', 'patient');

-- Insert Staff Records
INSERT INTO staff (user_id, employee_id, first_name, middle_name, last_name, position, department, assigned_barangay_id, phone, email, date_hired, license_number, specialization) VALUES
(1, 'SA-001', 'System', '', 'Administrator', 'System Administrator', 'Administration', NULL, '09171234567', 'superadmin@kawitrhu.gov.ph', '2024-01-01', NULL, 'System Administration'),
(2, 'RHU-001', 'Roberto', 'M', 'Santos', 'RHU Administrator', 'RHU', NULL, '09171234568', 'rhuadmin@kawitrhu.gov.ph', '2024-01-15', NULL, 'Health Administration'),
(3, 'RHU-002', 'Dr. Ana', 'M', 'Reyes', 'Municipal Health Officer', 'RHU', NULL, '09171234569', 'dr.reyes@kawitrhu.gov.ph', '2024-01-15', 'PRC-12345', 'General Medicine'),
(4, 'BHS-001', 'Nurse Joy', 'L', 'Mendoza', 'BHS Coordinator', 'BHS', 1, '09171234570', 'joy.mendoza@kawitrhu.gov.ph', '2024-02-01', 'PRC-67890', 'Community Health'),
(5, 'BHS-002', 'Nurse Mark', 'D', 'Lopez', 'BHS Coordinator', 'BHS', 3, '09171234571', 'mark.lopez@kawitrhu.gov.ph', '2024-02-01', 'PRC-67891', 'Maternal and Child Health'),
(6, 'BHS-003', 'Nurse Lisa', 'C', 'Torres', 'BHS Coordinator', 'BHS', 4, '09171234572', 'lisa.torres@kawitrhu.gov.ph', '2024-02-01', 'PRC-67892', 'Family Planning'),
(7, 'PH-001', 'Pharmacist Rose', 'T', 'Tan', 'Chief Pharmacist', 'Pharmacy', NULL, '09171234573', 'pharmacy@kawitrhu.gov.ph', '2024-01-20', 'PRC-54321', 'Clinical Pharmacy'),
(8, 'PH-002', 'Pharmacist Michael', 'A', 'Cruz', 'Staff Pharmacist', 'Pharmacy', NULL, '09171234574', 'rose.tan@kawitrhu.gov.ph', '2024-01-25', 'PRC-54322', 'Hospital Pharmacy');

-- Insert Patient Records
INSERT INTO patients (user_id, patient_id, first_name, middle_name, last_name, date_of_birth, gender, civil_status, address, barangay_id, phone, email, blood_type, philhealth_number, emergency_contact_name, emergency_contact_phone, emergency_contact_relationship) VALUES
(9, 'P-2024-0001', 'Juan', 'Dela', 'Cruz', '1990-05-15', 'Male', 'Married', '123 Rizal St., Binakayan-Kanluran', 1, '09181234567', 'juan.delacruz@email.com', 'O+', '12-345678901-2', 'Juana Dela Cruz', '09181234568', 'Wife'),
(10, 'P-2024-0002', 'Maria', 'Clara', 'Santos', '1985-08-20', 'Female', 'Single', '456 Bonifacio St., Kaingen', 3, '09191234567', 'maria.santos@email.com', 'A+', '12-345678902-3', 'Jose Santos', '09191234568', 'Father'),
(11, 'P-2024-0003', 'Pedro', 'Manuel', 'Garcia', '1995-03-10', 'Male', 'Single', '789 Aguinaldo St., Gahak', 4, '09201234567', 'pedro.garcia@email.com', 'B+', '12-345678903-4', 'Ana Garcia', '09201234568', 'Mother'),
(12, 'P-2024-0004', 'Ana', 'Marie', 'Reyes', '1992-11-22', 'Female', 'Married', '321 Luna St., Panamitan', 5, '09211234567', 'ana.reyes.patient@email.com', 'AB+', '12-345678904-5', 'John Reyes', '09211234568', 'Husband'),
(13, 'P-2024-0005', 'Carlos', 'Jose', 'Mendoza', '1988-07-08', 'Male', 'Married', '654 Mabini St., Marulas', 6, '09221234567', 'carlos.mendoza@email.com', 'O-', '12-345678905-6', 'Carmen Mendoza', '09221234568', 'Wife');

-- Insert Sample Medicines
INSERT INTO medicines (medicine_code, medicine_name, generic_name, brand, category_id, dosage_strength, dosage_form, stock_quantity, unit_of_measure, reorder_level, maximum_level, expiry_date, supplier, unit_cost, selling_price, requires_prescription) VALUES
('MED-001', 'Paracetamol 500mg', 'Acetaminophen', 'Biogesic', 1, '500mg', 'Tablet', 1000, 'tablet', 100, 2000, '2025-12-31', 'Mercury Drug Corporation', 2.50, 3.00, FALSE),
('MED-002', 'Amoxicillin 500mg', 'Amoxicillin', 'Amoxil', 2, '500mg', 'Capsule', 500, 'capsule', 50, 1000, '2025-06-30', 'Mercury Drug Corporation', 8.75, 10.00, TRUE),
('MED-003', 'Cetirizine 10mg', 'Cetirizine HCl', 'Zyrtec', 3, '10mg', 'Tablet', 300, 'tablet', 30, 500, '2025-09-30', 'Mercury Drug Corporation', 5.00, 6.00, FALSE),
('MED-004', 'Losartan 50mg', 'Losartan Potassium', 'Cozaar', 4, '50mg', 'Tablet', 400, 'tablet', 40, 800, '2025-08-31', 'TGP Pharma', 12.00, 15.00, TRUE),
('MED-005', 'Metformin 500mg', 'Metformin HCl', 'Glucophage', 5, '500mg', 'Tablet', 600, 'tablet', 60, 1200, '2025-10-31', 'TGP Pharma', 7.50, 9.00, TRUE),
('MED-006', 'Salbutamol 2mg', 'Salbutamol', 'Ventolin', 6, '2mg', 'Tablet', 200, 'tablet', 20, 400, '2025-11-30', 'Mercury Drug Corporation', 6.00, 7.50, TRUE),
('MED-007', 'Mefenamic Acid 500mg', 'Mefenamic Acid', 'Ponstan', 7, '500mg', 'Capsule', 400, 'capsule', 40, 800, '2025-07-31', 'Mercury Drug Corporation', 8.00, 10.00, FALSE),
('MED-008', 'Omeprazole 20mg', 'Omeprazole', 'Losec', 8, '20mg', 'Capsule', 350, 'capsule', 35, 700, '2025-09-30', 'TGP Pharma', 10.00, 12.50, TRUE),
('MED-009', 'Ascorbic Acid 500mg', 'Vitamin C', 'Cecon', 9, '500mg', 'Tablet', 800, 'tablet', 80, 1500, '2025-12-31', 'Mercury Drug Corporation', 3.00, 4.00, FALSE),
('MED-010', 'Betadine Solution', 'Povidone Iodine', 'Betadine', 10, '10%', 'Solution', 50, 'bottle', 10, 100, '2025-08-31', 'Mercury Drug Corporation', 85.00, 100.00, FALSE);

-- Insert Sample Appointments
INSERT INTO appointments (patient_id, service_type_id, appointment_date, appointment_time, appointment_location, barangay_id, status, notes, reason_for_visit, assigned_staff, created_by) VALUES
(1, 1, CURDATE() + INTERVAL 1 DAY, '09:00:00', 'RHU', NULL, 'confirmed', 'Regular check-up', 'Annual physical examination', 3, 9),
(2, 2, CURDATE() + INTERVAL 2 DAY, '10:00:00', 'BHS', 3, 'pending', 'First prenatal visit', 'First prenatal consultation', 5, 10),
(3, 5, CURDATE() + INTERVAL 3 DAY, '14:00:00', 'RHU', NULL, 'confirmed', 'Tooth extraction', 'Dental problem - tooth pain', 3, 11),
(4, 6, CURDATE() + INTERVAL 4 DAY, '11:00:00', 'RHU', NULL, 'pending', 'Blood test - CBC', 'Laboratory examination', NULL, 12),
(5, 3, CURDATE() + INTERVAL 5 DAY, '08:30:00', 'BHS', 6, 'confirmed', 'Child immunization', 'BCG vaccination for child', 4, 13);

-- Insert Sample Consultations (with google_meet_link)
INSERT INTO consultations (consultation_number, patient_id, consultation_type, consultation_date, chief_complaint, history_of_present_illness, symptoms, vital_signs, diagnosis, treatment_plan, status, assigned_doctor, consultation_location, google_meet_link) VALUES
('CONS-2024-0001', 1, 'walk-in', NOW() - INTERVAL 5 DAY, 'Fever and headache', 'Patient complains of fever for 2 days with associated headache and body malaise', 'Fever, headache, body pain, weakness', '{"temperature": "38.5", "blood_pressure": "120/80", "pulse_rate": "85", "respiratory_rate": "18", "weight": "70", "height": "170"}', 'Acute Viral Syndrome', 'Rest, increase fluid intake, paracetamol for fever', 'completed', 3, 'RHU', NULL),
('CONS-2024-0002', 2, 'online', NOW() - INTERVAL 3 DAY, 'Cough and colds', 'Patient has productive cough and runny nose for 3 days', 'Productive cough, runny nose, nasal congestion', '{"temperature": "37.2", "blood_pressure": "110/70", "pulse_rate": "78"}', 'Upper Respiratory Tract Infection', 'Antibiotics prescribed, rest advised, increase fluid intake', 'completed', 3, 'RHU', 'https://meet.google.com/abc-defg-hij'),
('CONS-2024-0003', 3, 'walk-in', NOW() - INTERVAL 1 DAY, 'Stomach pain', 'Epigastric pain for 1 week, worse after meals', 'Epigastric pain, bloating, nausea', '{"temperature": "36.8", "blood_pressure": "125/85", "pulse_rate": "82", "weight": "68"}', 'Gastritis', 'Omeprazole prescribed, dietary modifications advised', 'completed', 3, 'RHU', NULL);

-- Insert Sample Prescriptions
INSERT INTO prescriptions (prescription_number, patient_id, consultation_id, medication_name, generic_name, dosage_strength, dosage_form, quantity_prescribed, dosage_instructions, frequency, duration, prescribed_by, prescription_date, status) VALUES
('RX-2024-0001', 1, 1, 'Paracetamol', 'Acetaminophen', '500mg', 'Tablet', 12, 'Take 1 tablet every 6 hours as needed for fever', 'Every 6 hours', '3 days', 3, CURDATE() - INTERVAL 5 DAY, 'fully_dispensed'),
('RX-2024-0002', 2, 2, 'Amoxicillin', 'Amoxicillin', '500mg', 'Capsule', 21, 'Take 1 capsule three times a day after meals', 'Three times a day', '7 days', 3, CURDATE() - INTERVAL 3 DAY, 'fully_dispensed'),
('RX-2024-0003', 2, 2, 'Cetirizine', 'Cetirizine HCl', '10mg', 'Tablet', 5, 'Take 1 tablet once daily at bedtime', 'Once daily', '5 days', 3, CURDATE() - INTERVAL 3 DAY, 'fully_dispensed'),
('RX-2024-0004', 3, 3, 'Omeprazole', 'Omeprazole', '20mg', 'Capsule', 14, 'Take 1 capsule once daily 30 minutes before breakfast', 'Once daily before breakfast', '14 days', 3, CURDATE() - INTERVAL 1 DAY, 'pending');

-- Insert Sample Medical Certificates
INSERT INTO medical_certificates (certificate_number, patient_id, consultation_id, issued_by, certificate_type, purpose, date_issued, valid_from, valid_until, diagnosis, physical_findings, fitness_status, status) VALUES
('CERT-2024-0001', 1, 1, 3, 'Medical Certificate', 'Sick Leave', CURDATE() - INTERVAL 5 DAY, CURDATE() - INTERVAL 5 DAY, CURDATE() - INTERVAL 2 DAY, 'Acute Viral Syndrome', 'Febrile, appears weak but stable', 'Unfit', 'issued'),
('CERT-2024-0002', 2, NULL, 3, 'Fit to Work Certificate', 'Employment', CURDATE() - INTERVAL 10 DAY, CURDATE() - INTERVAL 10 DAY, CURDATE() + INTERVAL 355 DAY, 'No significant findings', 'Normal physical examination', 'Fit', 'issued'),
('CERT-2024-0003', 3, 3, 3, 'Medical Certificate', 'School Requirements', CURDATE() - INTERVAL 1 DAY, CURDATE() - INTERVAL 1 DAY, CURDATE(), 'Gastritis', 'Tender epigastrium, otherwise normal', 'Conditional', 'issued');

-- Insert Sample Announcements
INSERT INTO announcements (title, content, author_id, target_audience, priority, status, publish_date, featured) VALUES
('Welcome to Kawit RHU Online Portal', 'We are pleased to announce the launch of our new online health portal. Patients can now book appointments, request medical certificates, and access their health records online.', 1, 'all', 'high', 'published', NOW() - INTERVAL 7 DAY, TRUE),
('Free Flu Vaccination Program', 'The Rural Health Unit will be conducting free flu vaccinations for senior citizens and children under 5 years old starting next Monday.', 3, 'patients', 'high', 'published', NOW() - INTERVAL 3 DAY, TRUE),
('Online Consultation Now Available', 'You can now request online consultations through our portal. Submit your request and our doctors will schedule a Google Meet session with you.', 3, 'patients', 'high', 'published', NOW() - INTERVAL 1 DAY, TRUE);

-- Insert Sample Laboratory Results
INSERT INTO laboratory_results (lab_number, patient_id, consultation_id, test_type, test_category, test_date, result_date, test_results, normal_range, interpretation, status, performed_by, verified_by) VALUES
('LAB-2024-0001', 1, 1, 'Complete Blood Count', 'Hematology', CURDATE() - INTERVAL 5 DAY, CURDATE() - INTERVAL 4 DAY, 
'{"WBC": "11.5", "RBC": "4.8", "Hemoglobin": "14.2", "Hematocrit": "42.1", "Platelet": "250"}',
'WBC: 4.5-11.0 x10^9/L, RBC: 4.0-5.5 x10^12/L', 'Mild leukocytosis', 'completed', 3, 3);

-- Insert Sample Referrals
INSERT INTO referrals (referral_number, patient_id, consultation_id, referred_by, referred_to_facility, referred_to_doctor, referral_reason, clinical_summary, diagnosis, urgency_level, referral_date, status) VALUES
('REF-2024-0001', 1, 1, 3, 'Cavite Provincial Hospital', 'Internal Medicine Department', 'Further evaluation for persistent fever', 'Patient with 5-day history of high-grade fever', 'Acute Febrile Illness', 'urgent', CURDATE() - INTERVAL 4 DAY, 'completed');

-- Insert Sample Medicine Transactions
INSERT INTO medicine_transactions (transaction_number, medicine_id, transaction_type, quantity_before, quantity_transacted, quantity_after, reference_id, reference_type, transaction_date, performed_by) VALUES
('TXN-2024-0001', 1, 'dispensed', 1012, 12, 1000, 1, 'prescription', NOW() - INTERVAL 5 DAY, 7),
('TXN-2024-0002', 2, 'dispensed', 521, 21, 500, 2, 'prescription', NOW() - INTERVAL 3 DAY, 7);

-- Insert Sample Notifications
INSERT INTO notifications (user_id, type, title, message, data, priority) VALUES
(9, 'appointment_reminder', 'Appointment Reminder', 'You have an appointment tomorrow at 9:00 AM', '{"appointment_id": 1}', 'medium'),
(10, 'consultation_ready', 'Online Consultation Ready', 'Your doctor is ready for your consultation. Click to join the Google Meet session.', '{"consultation_id": 2}', 'high');

-- Insert System Configuration
INSERT INTO system_config (config_key, config_value, config_type, description, is_public) VALUES
('system_name', 'Kawit RHU Health Information Management System', 'string', 'System name', TRUE),
('system_version', '1.0.0', 'string', 'Current system version', TRUE),
('enable_online_consultation', 'true', 'boolean', 'Enable online consultation feature', TRUE),
('office_hours_start', '08:00', 'string', 'RHU office hours start', TRUE),
('office_hours_end', '17:00', 'string', 'RHU office hours end', TRUE);

-- =====================================================
-- CREATE INDEXES FOR BETTER PERFORMANCE
-- =====================================================

CREATE INDEX idx_users_login ON users(username, password);
CREATE INDEX idx_consultations_online ON consultations(consultation_type, status);

/*
================================================
UPDATED FEATURES:
================================================
1. ✅ Added google_meet_link column to consultations table
2. ✅ Removed consultation_fee from consultations table  
3. ✅ Added 'consultation_ready' notification type
4. ✅ Sample online consultation with Google Meet link

ALL PASSWORDS: password123

TEST ACCOUNTS:
- RHU Admin: rhu_admin / dr.reyes
- Patient: juan.delacruz / maria.santos
*/