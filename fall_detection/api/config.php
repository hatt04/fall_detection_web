<?php
/**
 * Database Configuration untuk SAFE System
 * File: api/config.php
 */

// Pengaturan Database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'safe_system');

// Timezone Indonesia
date_default_timezone_set('Asia/Jakarta');

// CORS Headers untuk mengizinkan request dari frontend
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Fungsi untuk koneksi database
 */
function getDBConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            throw new Exception("Koneksi database gagal: " . $conn->connect_error);
        }
        
        $conn->set_charset("utf8mb4");
        return $conn;
        
    } catch (Exception $e) {
        error_log("Database Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Fungsi untuk response JSON standar
 */
function sendResponse($success, $message, $data = null, $http_code = 200) {
    http_response_code($http_code);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $data
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Fungsi untuk validasi input
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)));
}

/**
 * Fungsi untuk log aktivitas sistem
 */
function logSystemActivity($conn, $device_id, $level, $message) {
    $stmt = $conn->prepare("INSERT INTO system_logs (device_id, log_level, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $device_id, $level, $message);
    $stmt->execute();
    $stmt->close();
}

/**
 * Fungsi untuk mengecek apakah device aktif
 */
function isDeviceActive($conn, $device_id) {
    $stmt = $conn->prepare("SELECT is_active FROM elderly_data WHERE device_id = ?");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return (bool)$row['is_active'];
    }
    
    return false;
}