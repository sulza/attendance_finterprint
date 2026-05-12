-- ============================================
-- Student Biometric Attendance System Database
-- ============================================

CREATE DATABASE IF NOT EXISTS biometric_attendance;
USE biometric_attendance;

-- Classes Table
CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(50) NOT NULL,
    class_level VARCHAR(20) NOT NULL,
    section VARCHAR(10) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('director','admission_officer','class_master','admin_officer') NOT NULL,
    assigned_class_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_class_id) REFERENCES classes(id) ON DELETE SET NULL
);

-- Students Table
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_number VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    nin VARCHAR(20) NOT NULL UNIQUE,
    date_of_birth DATE NOT NULL,
    gender ENUM('male','female','other') NOT NULL,
    phone_number VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    guardian_name VARCHAR(150) DEFAULT NULL,
    guardian_phone VARCHAR(20) DEFAULT NULL,
    -- Primary school academic history
    primary_school_name VARCHAR(200) DEFAULT NULL,
    primary_school_start DATE DEFAULT NULL,
    primary_school_end DATE DEFAULT NULL,
    -- Junior secondary academic history
    junior_secondary_name VARCHAR(200) DEFAULT NULL,
    junior_secondary_start DATE DEFAULT NULL,
    junior_secondary_end DATE DEFAULT NULL,
    year_of_admission YEAR NOT NULL,
    class_id INT DEFAULT NULL,
    -- Biometric: stores raw template (TEXT for large SDK templates) + SHA-256 lookup hash
    fingerprint_template TEXT DEFAULT NULL,
    fingerprint_hash VARCHAR(64) DEFAULT NULL,
    -- Device metadata captured at enrollment
    fp_device_model VARCHAR(100) DEFAULT NULL,
    fp_device_serial VARCHAR(100) DEFAULT NULL,
    fp_enrolled_at TIMESTAMP NULL DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive','graduated','transferred') DEFAULT 'active',
    registered_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Migration script for existing installations (run if upgrading):
-- ALTER TABLE students ADD COLUMN primary_school_name VARCHAR(200) DEFAULT NULL AFTER guardian_phone;
-- ALTER TABLE students ADD COLUMN junior_secondary_name VARCHAR(200) DEFAULT NULL AFTER primary_school_end;
-- ALTER TABLE students MODIFY COLUMN fingerprint_template TEXT DEFAULT NULL;
-- ALTER TABLE students ADD COLUMN fp_device_model VARCHAR(100) DEFAULT NULL AFTER fingerprint_hash;
-- ALTER TABLE students ADD COLUMN fp_device_serial VARCHAR(100) DEFAULT NULL AFTER fp_device_model;
-- ALTER TABLE students ADD COLUMN fp_enrolled_at TIMESTAMP NULL DEFAULT NULL AFTER fp_device_serial;

-- Student Documents Table
CREATE TABLE student_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    document_type ENUM('JI','NIN','primary_certificate','junior_certificate','medical_report','result','others') NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Attendance Table
CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    status ENUM('present','absent','late','excused') DEFAULT 'present',
    method ENUM('fingerprint','id_card','manual') DEFAULT 'manual',
    marked_by INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_attendance (student_id, attendance_date),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ========================
-- Default Data
-- ========================

INSERT INTO classes (class_name, class_level, section) VALUES
('JSS 1A','JSS 1','A'),
('JSS 1B','JSS 1','B'),
('JSS 2A','JSS 2','A'),
('JSS 2B','JSS 2','B'),
('JSS 3A','JSS 3','A'),
('JSS 3B','JSS 3','B'),
('SSS 1A','SSS 1','A'),
('SSS 1B','SSS 1','B'),
('SSS 2A','SSS 2','A'),
('SSS 2B','SSS 2','B'),
('SSS 3A','SSS 3','A'),
('SSS 3B','SSS 3','B');

-- Default Director account (password: password)
INSERT INTO users (full_name, username, email, password, role) VALUES
('System Director','director','director@school.edu.ng', '$2y$10$TKh8H1.PfbuNz0HvWLa5muiUpb2xjN0sTTkgqg76t7eJqMNMDvH.2', 'director'),
('Admission Officer','admission','admission@school.edu.ng', '$2y$10$TKh8H1.PfbuNz0HvWLa5muiUpb2xjN0sTTkgqg76t7eJqMNMDvH.2', 'admission_officer'),
('Class Master One','classmaster','classmaster@school.edu.ng', '$2y$10$TKh8H1.PfbuNz0HvWLa5muiUpb2xjN0sTTkgqg76t7eJqMNMDvH.2', 'class_master'),
('Admin Officer','adminofficer','admin@school.edu.ng', '$2y$10$TKh8H1.PfbuNz0HvWLa5muiUpb2xjN0sTTkgqg76t7eJqMNMDvH.2', 'admin_officer');

-- NOTE: Default password for all users is: password
