<?php
/**
 * Go Swift - Vehicles API
 * api/vehicles.php
 */

require_once '../config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        if ($method === 'POST') {
            handleRegisterVehicle();
        }
        break;
    
    case 'my-vehicles':
        if ($method === 'GET') {
            handleMyVehicles();
        }
        break;
    
    case 'get':
        if ($method === 'GET') {
            handleGetVehicle();
        }
        break;
    
    case 'update':
        if ($method === 'PUT') {
            handleUpdateVehicle();
        }
        break;
    
    case 'delete':
        if ($method === 'DELETE') {
            handleDeleteVehicle();
        }
        break;
    
    case 'verify':
        if ($method === 'POST') {
            handleVerifyVehicle();
        }
        break;
    
    default:
        sendResponse(['error' => 'Invalid action'], 400);
}

/**
 * Register a new vehicle
 */
function handleRegisterVehicle() {
    requireRole('owner');
    
    $userId = getCurrentUserId();
    
    // Validate required fields from POST data
    $required = ['type', 'make', 'model', 'year', 'plate_number', 'capacity', 'city_id'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            sendResponse(['error' => "Field '{$field}' is required"], 400);
        }
    }
    
    // Validate vehicle type
    $validTypes = ['Car', 'Bike', 'Van'];
    if (!in_array($_POST['type'], $validTypes)) {
        sendResponse(['error' => 'Invalid vehicle type'], 400);
    }
    
    // Validate year
    $currentYear = date('Y');
    if ($_POST['year'] < 1990 || $_POST['year'] > ($currentYear + 1)) {
        sendResponse(['error' => 'Invalid vehicle year'], 400);
    }
    
    // Validate capacity
    if ($_POST['capacity'] < 1 || $_POST['capacity'] > 20) {
        sendResponse(['error' => 'Invalid seating capacity'], 400);
    }
    
    $db = getDB();
    
    // Check if plate number already exists
    $stmt = $db->prepare("SELECT id FROM vehicles WHERE plate_number = ?");
    $stmt->execute([strtoupper(trim($_POST['plate_number']))]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Vehicle with this plate number already registered'], 409);
    }
    
    // Handle document upload (registration_doc is required)
    $docs = [];
    if (isset($_FILES['registration_doc']) && $_FILES['registration_doc']['error'] === UPLOAD_ERR_OK) {
        $result = uploadFile($_FILES['registration_doc']);
        if (isset($result['error'])) {
            sendResponse(['error' => 'Registration document: ' . $result['error']], 400);
        }
        $docs['registration_doc'] = $result['filename'];
    } else {
        sendResponse(['error' => 'Registration document is required'], 400);
    }
    
    // Handle vehicle photos (optional, multiple)
    $photos = [];
    if (isset($_FILES['vehicle_photos']) && is_array($_FILES['vehicle_photos']['name'])) {
        $fileCount = count($_FILES['vehicle_photos']['name']);
        for ($i = 0; $i < min($fileCount, 5); $i++) {
            if ($_FILES['vehicle_photos']['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['vehicle_photos']['name'][$i],
                    'type' => $_FILES['vehicle_photos']['type'][$i],
                    'tmp_name' => $_FILES['vehicle_photos']['tmp_name'][$i],
                    'error' => $_FILES['vehicle_photos']['error'][$i],
                    'size' => $_FILES['vehicle_photos']['size'][$i]
                ];
                $result = uploadFile($file, ['image/jpeg', 'image/png', 'image/jpg']);
                if (isset($result['filename'])) {
                    $photos[] = $result['filename'];
                }
            }
        }
    }
    
    // Handle area_ids (JSON array)
    $areaIds = [];
    if (isset($_POST['area_ids']) && !empty($_POST['area_ids'])) {
        $areaIds = json_decode($_POST['area_ids'], true);
        if (!is_array($areaIds)) {
            $areaIds = [];
        }
    }
    
    // Insert vehicle - AUTO VERIFY FOR TESTING
    $stmt = $db->prepare("
        INSERT INTO vehicles (
            user_id, type, make, model, year, plate_number, color, 
            capacity, city_id, area_ids, docs_json, vehicle_photos, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified')
    ");
    
    try {
        $stmt->execute([
            $userId,
            $_POST['type'],
            sanitize($_POST['make']),
            sanitize($_POST['model']),
            intval($_POST['year']),
            strtoupper(sanitize($_POST['plate_number'])),
            sanitize($_POST['color'] ?? ''),
            intval($_POST['capacity']),
            intval($_POST['city_id']),
            json_encode($areaIds),
            json_encode($docs),
            json_encode($photos)
        ]);
        
        $vehicleId = $db->lastInsertId();
        
        // Log activity (optional)
        // logActivity($userId, 'vehicle_registered', "Vehicle ID: {$vehicleId}");
        
        sendResponse([
            'success' => true,
            'message' => 'Vehicle registered and verified successfully! You can now post trips.',
            'vehicle_id' => $vehicleId,
            'status' => 'verified'
        ], 201);
        
    } catch (PDOException $e) {
        sendResponse(['error' => 'Failed to register vehicle: ' . $e->getMessage()], 500);
    }
}

/**
 * Get user's vehicles
 */
function handleMyVehicles() {
    requireAuth();
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    $query = "
        SELECT 
            v.*,
            c.name as city_name,
            COUNT(DISTINCT t.id) as total_trips
        FROM vehicles v
        INNER JOIN cities c ON v.city_id = c.id
        LEFT JOIN trips t ON v.id = t.vehicle_id
        WHERE v.user_id = ?
        GROUP BY v.id
        ORDER BY v.created_at DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$userId]);
    $vehicles = $stmt->fetchAll();
    
    // Decode JSON fields
    foreach ($vehicles as &$vehicle) {
        $vehicle['area_ids'] = json_decode($vehicle['area_ids'], true);
        $vehicle['docs_json'] = json_decode($vehicle['docs_json'], true);
        $vehicle['vehicle_photos'] = json_decode($vehicle['vehicle_photos'], true);
    }
    
    sendResponse([
        'success' => true,
        'vehicles' => $vehicles
    ]);
}

/**
 * Get single vehicle details
 */
function handleGetVehicle() {
    $vehicleId = $_GET['id'] ?? null;
    
    if (!$vehicleId) {
        sendResponse(['error' => 'Vehicle ID is required'], 400);
    }
    
    $db = getDB();
    
    $query = "
        SELECT 
            v.*,
            c.name as city_name,
            u.name as owner_name
        FROM vehicles v
        INNER JOIN cities c ON v.city_id = c.id
        INNER JOIN users u ON v.user_id = u.id
        WHERE v.id = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$vehicleId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        sendResponse(['error' => 'Vehicle not found'], 404);
    }
    
    // Decode JSON fields
    $vehicle['area_ids'] = json_decode($vehicle['area_ids'], true);
    $vehicle['docs_json'] = json_decode($vehicle['docs_json'], true);
    $vehicle['vehicle_photos'] = json_decode($vehicle['vehicle_photos'], true);
    
    // Check if user is owner or admin
    $userId = getCurrentUserId();
    if ($vehicle['user_id'] != $userId && !isset($_SESSION['admin_id'])) {
        // Hide sensitive info for non-owners
        unset($vehicle['docs_json']);
        unset($vehicle['plate_number']);
    }
    
    sendResponse([
        'success' => true,
        'vehicle' => $vehicle
    ]);
}

/**
 * Update vehicle
 */
function handleUpdateVehicle() {
    requireAuth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $vehicleId = $data['vehicle_id'] ?? null;
    
    if (!$vehicleId) {
        sendResponse(['error' => 'Vehicle ID is required'], 400);
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Verify vehicle belongs to user
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
    $stmt->execute([$vehicleId, $userId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        sendResponse(['error' => 'Vehicle not found or unauthorized'], 403);
    }
    
    // Build update query
    $updates = [];
    $params = [];
    
    $allowedFields = ['color', 'capacity', 'city_id', 'area_ids'];
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            if ($field === 'area_ids') {
                $updates[] = "{$field} = ?";
                $params[] = json_encode($data[$field]);
            } else {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
    }
    
    if (empty($updates)) {
        sendResponse(['error' => 'No fields to update'], 400);
    }
    
    $params[] = $vehicleId;
    
    $query = "UPDATE vehicles SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    // logActivity($userId, 'vehicle_updated', "Vehicle ID: {$vehicleId}");
    
    sendResponse([
        'success' => true,
        'message' => 'Vehicle updated successfully'
    ]);
}

/**
 * Delete vehicle
 */
function handleDeleteVehicle() {
    requireAuth();
    
    $vehicleId = $_GET['id'] ?? null;
    
    if (!$vehicleId) {
        sendResponse(['error' => 'Vehicle ID is required'], 400);
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Verify vehicle belongs to user
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
    $stmt->execute([$vehicleId, $userId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        sendResponse(['error' => 'Vehicle not found or unauthorized'], 403);
    }
    
    // Check if vehicle has active trips
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM trips WHERE vehicle_id = ? AND status = 'active'");
    $stmt->execute([$vehicleId]);
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        sendResponse(['error' => 'Cannot delete vehicle with active trips'], 400);
    }
    
    // Delete vehicle
    $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicleId]);
    
    // logActivity($userId, 'vehicle_deleted', "Vehicle ID: {$vehicleId}");
    
    sendResponse([
        'success' => true,
        'message' => 'Vehicle deleted successfully'
    ]);
}

/**
 * Verify vehicle (Admin only)
 */
function handleVerifyVehicle() {
    // Check admin authentication
    if (!isset($_SESSION['admin_id'])) {
        sendResponse(['error' => 'Admin access required'], 403);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $vehicleId = $data['vehicle_id'] ?? null;
    $status = $data['status'] ?? null; // 'verified' or 'rejected'
    $notes = $data['notes'] ?? '';
    
    if (!$vehicleId || !in_array($status, ['verified', 'rejected'])) {
        sendResponse(['error' => 'Invalid request'], 400);
    }
    
    $db = getDB();
    
    // Get vehicle details
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicleId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        sendResponse(['error' => 'Vehicle not found'], 404);
    }
    
    // Update vehicle status
    $stmt = $db->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
    $stmt->execute([$status, $vehicleId]);
    
    // Notify owner
    $message = $status === 'verified' 
        ? "Your vehicle has been verified! You can now post trips."
        : "Your vehicle registration was rejected. Reason: {$notes}";
    
    // createNotification($vehicle['user_id'], 'vehicle_' . $status, 'Vehicle ' . ucfirst($status), $message, 'dashboard-owner.php');
    
    sendResponse([
        'success' => true,
        'message' => 'Vehicle ' . $status . ' successfully'
    ]);
}

?>