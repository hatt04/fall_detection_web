<?php
/**
 * API untuk menerima data dari Python Script
 * File: api/receive_data.php
 * 
 * Endpoint ini menerima berbagai jenis data:
 * 1. Sensor data (IMU)
 * 2. GPS location
 * 3. Fall detection events
 * 4. Activity classification
 * 5. Object detection (obstacles)
 */

require_once 'config.php';

// Hanya terima POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed. Use POST.', null, 405);
}

// Ambil data dari request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validasi JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    sendResponse(false, 'Invalid JSON format: ' . json_last_error_msg(), null, 400);
}

// Validasi data type
if (!isset($data['data_type']) || !isset($data['device_id'])) {
    sendResponse(false, 'Missing required fields: data_type and device_id', null, 400);
}

$conn = getDBConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', null, 500);
}

$data_type = sanitizeInput($data['data_type']);
$device_id = sanitizeInput($data['device_id']);

// Proses berdasarkan tipe data
try {
    switch ($data_type) {
        
        case 'sensor':
            // Data dari IMU sensor
            handleSensorData($conn, $device_id, $data);
            break;
            
        case 'gps':
            // Data GPS location
            handleGPSData($conn, $device_id, $data);
            break;
            
        case 'fall_detection':
            // Event jatuh terdeteksi
            handleFallDetection($conn, $device_id, $data);
            break;
            
        case 'activity':
            // Klasifikasi aktivitas
            handleActivityData($conn, $device_id, $data);
            break;
            
        case 'obstacle':
            // Object detection
            handleObstacleDetection($conn, $device_id, $data);
            break;
            
        case 'battery':
            // Update battery level
            handleBatteryUpdate($conn, $device_id, $data);
            break;
            
        default:
            sendResponse(false, 'Unknown data_type: ' . $data_type, null, 400);
    }
    
} catch (Exception $e) {
    logSystemActivity($conn, $device_id, 'error', 'Error processing ' . $data_type . ': ' . $e->getMessage());
    sendResponse(false, 'Error processing data: ' . $e->getMessage(), null, 500);
}

$conn->close();

// ==================== HANDLER FUNCTIONS ====================

/**
 * Handle sensor data (IMU)
 */
function handleSensorData($conn, $device_id, $data) {
    $sensor_id = $data['sensor_id'] ?? 1;
    $acc_x = $data['acc_x'] ?? 0;
    $acc_y = $data['acc_y'] ?? 0;
    $acc_z = $data['acc_z'] ?? 0;
    $gyro_x = $data['gyro_x'] ?? 0;
    $gyro_y = $data['gyro_y'] ?? 0;
    $gyro_z = $data['gyro_z'] ?? 0;
    
    $stmt = $conn->prepare("INSERT INTO sensor_data (device_id, sensor_id, acc_x, acc_y, acc_z, gyro_x, gyro_y, gyro_z) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sidddddd", $device_id, $sensor_id, $acc_x, $acc_y, $acc_z, $gyro_x, $gyro_y, $gyro_z);
    
    if ($stmt->execute()) {
        sendResponse(true, 'Sensor data saved successfully', ['id' => $conn->insert_id]);
    } else {
        throw new Exception('Failed to save sensor data: ' . $stmt->error);
    }
}

/**
 * Handle GPS tracking data
 */
function handleGPSData($conn, $device_id, $data) {
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;
    $accuracy = $data['accuracy'] ?? null;
    
    if ($latitude === null || $longitude === null) {
        sendResponse(false, 'Missing GPS coordinates', null, 400);
    }
    
    $stmt = $conn->prepare("INSERT INTO gps_tracking (device_id, latitude, longitude, accuracy) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sddd", $device_id, $latitude, $longitude, $accuracy);
    
    if ($stmt->execute()) {
        sendResponse(true, 'GPS data saved successfully', [
            'id' => $conn->insert_id,
            'latitude' => $latitude,
            'longitude' => $longitude
        ]);
    } else {
        throw new Exception('Failed to save GPS data: ' . $stmt->error);
    }
}

/**
 * Handle fall detection event
 */
function handleFallDetection($conn, $device_id, $data) {
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;
    $confidence = $data['confidence'] ?? 0.0;
    $severity = determineSeverity($confidence);
    
    // Insert fall event
    $stmt = $conn->prepare("INSERT INTO fall_events (device_id, latitude, longitude, confidence, severity, status) VALUES (?, ?, ?, ?, ?, 'detected')");
    $stmt->bind_param("sddds", $device_id, $latitude, $longitude, $confidence, $severity);
    
    if ($stmt->execute()) {
        $fall_id = $conn->insert_id;
        
        // Log system
        logSystemActivity($conn, $device_id, 'critical', "FALL DETECTED! Confidence: {$confidence}%");
        
        // Trigger notification (akan dipanggil otomatis via webhook/cron)
        sendFallNotification($conn, $fall_id, $device_id, $latitude, $longitude, $confidence);
        
        sendResponse(true, 'Fall event recorded successfully', [
            'fall_id' => $fall_id,
            'severity' => $severity,
            'notification_sent' => true
        ]);
    } else {
        throw new Exception('Failed to record fall event: ' . $stmt->error);
    }
}

/**
 * Handle activity classification
 */
function handleActivityData($conn, $device_id, $data) {
    $activity_type = $data['activity_type'] ?? 'unknown';
    $confidence = $data['confidence'] ?? 0.0;
    
    // Validasi activity type
    $valid_activities = ['standing', 'walking', 'sitting', 'sleeping', 'unknown'];
    if (!in_array($activity_type, $valid_activities)) {
        sendResponse(false, 'Invalid activity type', null, 400);
    }
    
    // Cek apakah ada aktivitas yang sedang berlangsung
    $stmt = $conn->prepare("SELECT id, activity_type FROM daily_activities WHERE device_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Ada aktivitas yang sedang berlangsung
        if ($row['activity_type'] !== $activity_type) {
            // Aktivitas berubah, tutup yang lama
            $current_id = $row['id'];
            $stmt2 = $conn->prepare("UPDATE daily_activities SET end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW()) WHERE id = ?");
            $stmt2->bind_param("i", $current_id);
            $stmt2->execute();
            
            // Buat aktivitas baru
            $stmt3 = $conn->prepare("INSERT INTO daily_activities (device_id, activity_type, confidence) VALUES (?, ?, ?)");
            $stmt3->bind_param("ssd", $device_id, $activity_type, $confidence);
            $stmt3->execute();
            
            sendResponse(true, 'Activity changed successfully', [
                'previous_activity' => $row['activity_type'],
                'new_activity' => $activity_type,
                'new_id' => $conn->insert_id
            ]);
        } else {
            // Aktivitas sama, hanya update confidence
            sendResponse(true, 'Activity continues', [
                'current_activity' => $activity_type,
                'id' => $row['id']
            ]);
        }
    } else {
        // Tidak ada aktivitas, buat baru
        $stmt = $conn->prepare("INSERT INTO daily_activities (device_id, activity_type, confidence) VALUES (?, ?, ?)");
        $stmt->bind_param("ssd", $device_id, $activity_type, $confidence);
        
        if ($stmt->execute()) {
            sendResponse(true, 'New activity started', [
                'activity_type' => $activity_type,
                'id' => $conn->insert_id
            ]);
        } else {
            throw new Exception('Failed to save activity data: ' . $stmt->error);
        }
    }
}

/**
 * Handle obstacle detection
 */
function handleObstacleDetection($conn, $device_id, $data) {
    $object_class = $data['object_class'] ?? 'unknown';
    $confidence = $data['confidence'] ?? 0.0;
    $bbox = $data['bbox'] ?? null;
    $distance = $data['distance'] ?? null;
    
    $bbox_x1 = $bbox[0] ?? 0;
    $bbox_y1 = $bbox[1] ?? 0;
    $bbox_x2 = $bbox[2] ?? 0;
    $bbox_y2 = $bbox[3] ?? 0;
    
    $stmt = $conn->prepare("INSERT INTO obstacle_detections (device_id, object_class, confidence, bbox_x1, bbox_y1, bbox_x2, bbox_y2, distance_estimate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdiiiii", $device_id, $object_class, $confidence, $bbox_x1, $bbox_y1, $bbox_x2, $bbox_y2, $distance);
    
    if ($stmt->execute()) {
        // Jika obstacle berbahaya dan dekat, log warning
        if ($distance !== null && $distance < 1.0) {
            logSystemActivity($conn, $device_id, 'warning', "Obstacle detected nearby: {$object_class} at {$distance}m");
        }
        
        sendResponse(true, 'Obstacle detection saved', ['id' => $conn->insert_id]);
    } else {
        throw new Exception('Failed to save obstacle data: ' . $stmt->error);
    }
}

/**
 * Handle battery update
 */
function handleBatteryUpdate($conn, $device_id, $data) {
    $battery_level = $data['battery_level'] ?? 100;
    
    $stmt = $conn->prepare("UPDATE elderly_data SET battery_level = ?, updated_at = NOW() WHERE device_id = ?");
    $stmt->bind_param("is", $battery_level, $device_id);
    
    if ($stmt->execute()) {
        // Log jika baterai rendah
        if ($battery_level < 20) {
            logSystemActivity($conn, $device_id, 'warning', "Low battery: {$battery_level}%");
        }
        
        sendResponse(true, 'Battery level updated', ['battery_level' => $battery_level]);
    } else {
        throw new Exception('Failed to update battery: ' . $stmt->error);
    }
}

/**
 * Helper: Determine fall severity based on confidence
 */
function determineSeverity($confidence) {
    if ($confidence >= 0.9) return 'high';
    if ($confidence >= 0.7) return 'medium';
    return 'low';
}

/**
 * Helper: Send fall notification
 */
function sendFallNotification($conn, $fall_id, $device_id, $lat, $lon, $confidence) {
    // Get elderly info
    $stmt = $conn->prepare("SELECT name, emergency_contact FROM elderly_data WHERE device_id = ?");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $elderly = $result->fetch_assoc();
    
    // Log notification attempt
    $stmt = $conn->prepare("INSERT INTO notification_logs (fall_event_id, notification_type, recipient, status) VALUES (?, 'push', ?, 'sent')");
    $recipient = $elderly['emergency_contact'] ?? 'unknown';
    $stmt->bind_param("is", $fall_id, $recipient);
    $stmt->execute();
    
    // Dalam implementasi nyata, kirim ke Telegram/Email/SMS di sini
    // Untuk sekarang hanya log saja
}