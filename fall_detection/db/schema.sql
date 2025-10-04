-- =====================================================
-- SAFE FALL DETECTION DATABASE
-- Jalankan di phpMyAdmin atau MySQL CLI
-- =====================================================

CREATE DATABASE IF NOT EXISTS safe_system;
USE safe_system;

-- Tabel untuk menyimpan data lansia
CREATE TABLE IF NOT EXISTS elderly_data (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    age INT,
    emergency_contact VARCHAR(20),
    medical_condition TEXT,
    battery_level INT DEFAULT 100,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabel untuk sensor data real-time (IMU)
CREATE TABLE IF NOT EXISTS sensor_data (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(50) NOT NULL,
    sensor_id INT,
    acc_x FLOAT,
    acc_y FLOAT,
    acc_z FLOAT,
    gyro_x FLOAT,
    gyro_y FLOAT,
    gyro_z FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_time (device_id, timestamp)
);

-- Tabel untuk GPS tracking
CREATE TABLE IF NOT EXISTS gps_tracking (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(50) NOT NULL,
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    accuracy FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_time (device_id, timestamp)
);

-- Tabel untuk fall detection events
CREATE TABLE IF NOT EXISTS fall_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(50) NOT NULL,
    latitude DOUBLE,
    longitude DOUBLE,
    confidence FLOAT,
    severity ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('detected', 'confirmed', 'false_alarm', 'resolved') DEFAULT 'detected',
    notes TEXT,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    INDEX idx_device_status (device_id, status),
    INDEX idx_detected_at (detected_at)
);

-- Tabel untuk aktivitas harian (dari model ML)
CREATE TABLE IF NOT EXISTS daily_activities (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(50) NOT NULL,
    activity_type ENUM('standing', 'walking', 'sitting', 'sleeping', 'unknown') NOT NULL,
    confidence FLOAT,
    duration_seconds INT DEFAULT 0,
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP NULL,
    INDEX idx_device_activity (device_id, activity_type),
    INDEX idx_start_time (start_time)
);

-- Tabel untuk object detection (obstacles)
CREATE TABLE IF NOT EXISTS obstacle_detections (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(50) NOT NULL,
    object_class VARCHAR(50),
    confidence FLOAT,
    bbox_x1 INT,
    bbox_y1 INT,
    bbox_x2 INT,
    bbox_y2 INT,
    distance_estimate FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_time (device_id, timestamp)
);

-- Tabel untuk notification logs
CREATE TABLE IF NOT EXISTS notification_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fall_event_id INT,
    notification_type ENUM('email', 'telegram', 'sms', 'push') NOT NULL,
    recipient VARCHAR(255),
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fall_event_id) REFERENCES fall_events(id) ON DELETE CASCADE
);

-- Tabel untuk system logs
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(50),
    log_level ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_level (device_id, log_level)
);

-- Insert dummy data untuk testing
INSERT INTO elderly_data (device_id, name, age, emergency_contact, medical_condition, battery_level) VALUES
('SAFE-001', 'Siti Aminah', 72, '081234567890', 'Osteoporosis (RAWAN FRAKTUR)', 85),
('SAFE-002', 'Budi Santoso', 75, '081234567891', 'Diabetes Type 2', 42);

-- Insert dummy GPS location (Surabaya)
INSERT INTO gps_tracking (device_id, latitude, longitude, accuracy) VALUES
('SAFE-001', -7.250445, 112.768845, 10.5);

-- View untuk dashboard summary
CREATE OR REPLACE VIEW daily_activity_summary AS
SELECT 
    da.device_id,
    da.activity_type,
    COUNT(*) as activity_count,
    SUM(da.duration_seconds) as total_duration_seconds,
    ROUND(SUM(da.duration_seconds) / 60.0, 0) as total_duration_minutes,
    DATE(da.start_time) as activity_date
FROM daily_activities da
WHERE DATE(da.start_time) = CURDATE()
GROUP BY da.device_id, da.activity_type, DATE(da.start_time);

-- View untuk latest status
CREATE OR REPLACE VIEW device_latest_status AS
SELECT 
    ed.device_id,
    ed.name,
    ed.battery_level,
    ed.is_active,
    gt.latitude,
    gt.longitude,
    gt.timestamp as last_gps_update,
    da.activity_type as current_activity,
    da.start_time as activity_start_time
FROM elderly_data ed
LEFT JOIN gps_tracking gt ON ed.device_id = gt.device_id 
    AND gt.timestamp = (SELECT MAX(timestamp) FROM gps_tracking WHERE device_id = ed.device_id)
LEFT JOIN daily_activities da ON ed.device_id = da.device_id 
    AND da.end_time IS NULL
ORDER BY ed.device_id;