<?php
/**
 * API untuk mengambil data terbaru untuk dashboard
 * File: api/get_latest_data.php
 */

require_once 'config.php';

// Terima GET request dengan parameter device_id
$device_id = isset($_GET['device_id']) ? sanitizeInput($_GET['device_id']) : 'SAFE-001';

$conn = getDBConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', null, 500);
}

try {
    // Get device info dan status terbaru
    $device_info = getDeviceInfo($conn, $device_id);
    
    // Get GPS location terbaru
    $gps_data = getLatestGPS($conn, $device_id);
    
    // Get aktivitas saat ini
    $current_activity = getCurrentActivity($conn, $device_id);
    
    // Get fall events hari ini
    $today_falls = getTodayFalls($conn, $device_id);
    
    // Get activity summary hari ini
    $activity_summary = getActivitySummary($conn, $device_id);
    
    // Get sensor data terakhir (untuk monitoring)
    $latest_sensor = getLatestSensor($conn, $device_id);
    
    $response_data = [
        'device_info' => $device_info,
        'gps' => $gps_data,
        'current_activity' => $current_activity,
        'today_falls' => $today_falls,
        'activity_summary' => $activity_summary,
        'latest_sensor' => $latest_sensor
    ];
    
    sendResponse(true, 'Data retrieved successfully', $response_data);
    
} catch (Exception $e) {
    sendResponse(false, 'Error retrieving data: ' . $e->getMessage(), null, 500);
}

$conn->close();

// ==================== HELPER FUNCTIONS ====================

function getDeviceInfo($conn, $device_id) {
    $stmt = $conn->prepare("SELECT device_id, name, age, emergency_contact, medical_condition, battery_level, is_active, updated_at FROM elderly_data WHERE device_id = ?");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return null;
}

function getLatestGPS($conn, $device_id) {
    $stmt = $conn->prepare("SELECT latitude, longitude, accuracy, timestamp FROM gps_tracking WHERE device_id = ? ORDER BY timestamp DESC LIMIT 1");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return [
        'latitude' => -7.250445,
        'longitude' => 112.768845,
        'accuracy' => null,
        'timestamp' => null
    ];
}

function getCurrentActivity($conn, $device_id) {
    $stmt = $conn->prepare("SELECT activity_type, confidence, start_time, TIMESTAMPDIFF(MINUTE, start_time, NOW()) as duration_minutes FROM daily_activities WHERE device_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return [
        'activity_type' => 'unknown',
        'confidence' => 0,
        'start_time' => null,
        'duration_minutes' => 0
    ];
}

function getTodayFalls($conn, $device_id) {
    $stmt = $conn->prepare("
        SELECT 
            id, 
            latitude, 
            longitude, 
            confidence, 
            severity, 
            status, 
            detected_at 
        FROM fall_events 
        WHERE device_id = ? 
        AND DATE(detected_at) = CURDATE() 
        ORDER BY detected_at DESC
    ");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $falls = [];
    while ($row = $result->fetch_assoc()) {
        $falls[] = $row;
    }
    
    return [
        'count' => count($falls),
        'events' => $falls
    ];
}

function getActivitySummary($conn, $device_id) {
    $stmt = $conn->prepare("
        SELECT 
            activity_type,
            SUM(duration_seconds) as total_seconds,
            ROUND(SUM(duration_seconds) / 60.0, 0) as total_minutes,
            COUNT(*) as count
        FROM daily_activities 
        WHERE device_id = ? 
        AND DATE(start_time) = CURDATE()
        GROUP BY activity_type
    ");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $summary = [];
    $total_minutes = 0;
    
    while ($row = $result->fetch_assoc()) {
        $summary[$row['activity_type']] = [
            'minutes' => (int)$row['total_minutes'],
            'count' => (int)$row['count']
        ];
        $total_minutes += (int)$row['total_minutes'];
    }
    
    // Hitung persentase
    if ($total_minutes > 0) {
        foreach ($summary as $type => &$data) {
            $data['percentage'] = round(($data['minutes'] / $total_minutes) * 100, 1);
        }
    }
    
    return [
        'total_minutes' => $total_minutes,
        'activities' => $summary
    ];
}

function getLatestSensor($conn, $device_id) {
    $stmt = $conn->prepare("SELECT acc_x, acc_y, acc_z, gyro_x, gyro_y, gyro_z, timestamp FROM sensor_data WHERE device_id = ? ORDER BY timestamp DESC LIMIT 1");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return null;
}