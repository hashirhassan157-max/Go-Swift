<?php
/**
 * Go Swift - Trips/Posts API
 * api/trips.php
 */

require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        if ($method === 'POST') {
            handleCreateTrip();
        }
        break;
    
    case 'search':
        if ($method === 'GET') {
            handleSearchTrips();
        }
        break;
    
    case 'get':
        if ($method === 'GET') {
            handleGetTrip();
        }
        break;
    
    case 'update':
        if ($method === 'PUT') {
            handleUpdateTrip();
        }
        break;
    
    case 'cancel':
        if ($method === 'POST') {
            handleCancelTrip();
        }
        break;
    
    case 'my-trips':
        if ($method === 'GET') {
            handleMyTrips();
        }
        break;
    
    default:
        sendResponse(['error' => 'Invalid action'], 400);
}

/**
 * Create a new trip
 */
function handleCreateTrip() {
    requireRole('owner');
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['vehicle_id', 'departure_city_id', 'arrival_city_id', 'depart_datetime', 'seats_total', 'price_per_seat'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            sendResponse(['error' => "Field '{$field}' is required"], 400);
        }
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Verify vehicle belongs to user and is verified
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ? AND status = 'verified'");
    $stmt->execute([$data['vehicle_id'], $userId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        sendResponse(['error' => 'Invalid or unverified vehicle'], 400);
    }
    
    // Validate seats
    if ($data['seats_total'] > $vehicle['capacity']) {
        sendResponse(['error' => 'Seats exceed vehicle capacity'], 400);
    }
    
    // Validate departure date is in the future
    $departDateTime = strtotime($data['depart_datetime']);
    if ($departDateTime <= time()) {
        sendResponse(['error' => 'Departure time must be in the future'], 400);
    }
    
    // Insert trip
    $stmt = $db->prepare("
        INSERT INTO trips (
            vehicle_id, user_id, departure_city_id, departure_area_id, 
            arrival_city_id, arrival_area_id, depart_datetime, 
            seats_total, seats_left, price_per_seat, luggage_allowance, 
            notes, allow_partial_booking, is_recurring, recurring_pattern
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    try {
        $stmt->execute([
            $data['vehicle_id'],
            $userId,
            $data['departure_city_id'],
            $data['departure_area_id'] ?? null,
            $data['arrival_city_id'],
            $data['arrival_area_id'] ?? null,
            $data['depart_datetime'],
            $data['seats_total'],
            $data['seats_total'], // seats_left initially equals seats_total
            $data['price_per_seat'],
            $data['luggage_allowance'] ?? null,
            $data['notes'] ?? null,
            $data['allow_partial_booking'] ?? 1,
            $data['is_recurring'] ?? 0,
            $data['recurring_pattern'] ?? null
        ]);
        
        $tripId = $db->lastInsertId();
        
        // Log activity
        logActivity($userId, 'trip_created', "Trip ID: {$tripId}");
        
        sendResponse([
            'success' => true,
            'message' => 'Trip posted successfully',
            'trip_id' => $tripId
        ], 201);
        
    } catch (PDOException $e) {
        sendResponse(['error' => 'Failed to create trip: ' . $e->getMessage()], 500);
    }
}

/**
 * Search trips
 */
function handleSearchTrips() {
    $db = getDB();
    
    // Build query based on filters
    $where = ["t.status = 'active'", "t.depart_datetime > NOW()"];
    $params = [];
    
    if (!empty($_GET['from_city'])) {
        $where[] = "t.departure_city_id = ?";
        $params[] = $_GET['from_city'];
    }
    
    if (!empty($_GET['from_area'])) {
        $where[] = "t.departure_area_id = ?";
        $params[] = $_GET['from_area'];
    }
    
    if (!empty($_GET['to_city'])) {
        $where[] = "t.arrival_city_id = ?";
        $params[] = $_GET['to_city'];
    }
    
    if (!empty($_GET['to_area'])) {
        $where[] = "t.arrival_area_id = ?";
        $params[] = $_GET['to_area'];
    }
    
    if (!empty($_GET['date'])) {
        $where[] = "DATE(t.depart_datetime) = ?";
        $params[] = $_GET['date'];
    }
    
    if (!empty($_GET['seats'])) {
        $where[] = "t.seats_left >= ?";
        $params[] = $_GET['seats'];
    }
    
    if (!empty($_GET['vehicle_type'])) {
        $where[] = "v.type = ?";
        $params[] = $_GET['vehicle_type'];
    }
    
    if (!empty($_GET['max_price'])) {
        $where[] = "t.price_per_seat <= ?";
        $params[] = $_GET['max_price'];
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Sorting
    $orderBy = "t.depart_datetime ASC";
    if (!empty($_GET['sort'])) {
        switch ($_GET['sort']) {
            case 'price_low':
                $orderBy = "t.price_per_seat ASC";
                break;
            case 'price_high':
                $orderBy = "t.price_per_seat DESC";
                break;
            case 'seats':
                $orderBy = "t.seats_left DESC";
                break;
            case 'time_late':
                $orderBy = "t.depart_datetime DESC";
                break;
        }
    }
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    
    $query = "
        SELECT 
            t.*,
            v.type as vehicle_type, v.make, v.model, v.year, v.color, v.capacity,
            dc.name as departure_city_name,
            da.name as departure_area_name,
            ac.name as arrival_city_name,
            aa.name as arrival_area_name,
            u.name as owner_name, u.profile_photo as owner_photo,
            COALESCE(AVG(r.rating), 0) as owner_rating,
            COUNT(DISTINCT b.id) as total_bookings
        FROM trips t
        INNER JOIN vehicles v ON t.vehicle_id = v.id
        INNER JOIN cities dc ON t.departure_city_id = dc.id
        LEFT JOIN areas da ON t.departure_area_id = da.id
        INNER JOIN cities ac ON t.arrival_city_id = ac.id
        LEFT JOIN areas aa ON t.arrival_area_id = aa.id
        INNER JOIN users u ON t.user_id = u.id
        LEFT JOIN bookings b ON t.id = b.trip_id
        LEFT JOIN reviews r ON u.id = r.reviewed_user_id
        WHERE {$whereClause}
        GROUP BY t.id
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $trips = $stmt->fetchAll();
    
    // Get total count
    $countQuery = "
        SELECT COUNT(DISTINCT t.id) as total
        FROM trips t
        INNER JOIN vehicles v ON t.vehicle_id = v.id
        WHERE {$whereClause}
    ";
    $stmt = $db->prepare($countQuery);
    $stmt->execute(array_slice($params, 0, -2)); // Exclude limit and offset
    $totalCount = $stmt->fetch()['total'];
    
    sendResponse([
        'success' => true,
        'trips' => $trips,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $perPage)
        ]
    ]);
}

/**
 * Get single trip details
 */
function handleGetTrip() {
    $tripId = $_GET['id'] ?? null;
    
    if (!$tripId) {
        sendResponse(['error' => 'Trip ID is required'], 400);
    }
    
    $db = getDB();
    
    $query = "
        SELECT 
            t.*,
            v.type as vehicle_type, v.make, v.model, v.year, v.color, v.capacity, v.plate_number,
            v.vehicle_photos,
            dc.name as departure_city_name,
            da.name as departure_area_name,
            ac.name as arrival_city_name,
            aa.name as arrival_area_name,
            u.id as owner_id, u.name as owner_name, u.phone as owner_phone, 
            u.profile_photo as owner_photo,
            COALESCE(AVG(r.rating), 0) as owner_rating,
            COUNT(DISTINCT r.id) as total_reviews,
            COUNT(DISTINCT completed_bookings.id) as completed_trips
        FROM trips t
        INNER JOIN vehicles v ON t.vehicle_id = v.id
        INNER JOIN cities dc ON t.departure_city_id = dc.id
        LEFT JOIN areas da ON t.departure_area_id = da.id
        INNER JOIN cities ac ON t.arrival_city_id = ac.id
        LEFT JOIN areas aa ON t.arrival_area_id = aa.id
        INNER JOIN users u ON t.user_id = u.id
        LEFT JOIN reviews r ON u.id = r.reviewed_user_id
        LEFT JOIN bookings completed_bookings ON u.id = completed_bookings.rider_user_id 
            AND completed_bookings.status = 'completed'
        WHERE t.id = ?
        GROUP BY t.id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch();
    
    if (!$trip) {
        sendResponse(['error' => 'Trip not found'], 404);
    }
    
    // Get recent reviews for owner
    $stmt = $db->prepare("
        SELECT r.rating, r.comment, r.created_at, u.name as reviewer_name
        FROM reviews r
        INNER JOIN users u ON r.reviewer_user_id = u.id
        WHERE r.reviewed_user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$trip['owner_id']]);
    $reviews = $stmt->fetchAll();
    
    $trip['reviews'] = $reviews;
    
    sendResponse([
        'success' => true,
        'trip' => $trip
    ]);
}

/**
 * Update trip
 */
function handleUpdateTrip() {
    requireAuth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $tripId = $data['trip_id'] ?? null;
    
    if (!$tripId) {
        sendResponse(['error' => 'Trip ID is required'], 400);
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Verify trip belongs to user
    $stmt = $db->prepare("SELECT * FROM trips WHERE id = ? AND user_id = ?");
    $stmt->execute([$tripId, $userId]);
    $trip = $stmt->fetch();
    
    if (!$trip) {
        sendResponse(['error' => 'Trip not found or unauthorized'], 403);
    }
    
    // Build update query
    $updates = [];
    $params = [];
    
    $allowedFields = ['depart_datetime', 'seats_total', 'price_per_seat', 'luggage_allowance', 'notes'];
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = $data[$field];
        }
    }
    
    if (empty($updates)) {
        sendResponse(['error' => 'No fields to update'], 400);
    }
    
    $params[] = $tripId;
    
    $query = "UPDATE trips SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    logActivity($userId, 'trip_updated', "Trip ID: {$tripId}");
    
    sendResponse([
        'success' => true,
        'message' => 'Trip updated successfully'
    ]);
}

/**
 * Cancel trip
 */
function handleCancelTrip() {
    requireAuth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $tripId = $data['trip_id'] ?? null;
    
    if (!$tripId) {
        sendResponse(['error' => 'Trip ID is required'], 400);
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Verify trip belongs to user
    $stmt = $db->prepare("SELECT * FROM trips WHERE id = ? AND user_id = ?");
    $stmt->execute([$tripId, $userId]);
    $trip = $stmt->fetch();
    
    if (!$trip) {
        sendResponse(['error' => 'Trip not found or unauthorized'], 403);
    }
    
    // Cancel trip
    $stmt = $db->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$tripId]);
    
    // Cancel all pending bookings
    $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled', cancellation_reason = 'Trip cancelled by owner' WHERE trip_id = ? AND status = 'pending'");
    $stmt->execute([$tripId]);
    
    // Notify all riders with bookings
    $stmt = $db->prepare("SELECT DISTINCT rider_user_id FROM bookings WHERE trip_id = ?");
    $stmt->execute([$tripId]);
    $riders = $stmt->fetchAll();
    
    foreach ($riders as $rider) {
        createNotification(
            $rider['rider_user_id'],
            'trip_cancelled',
            'Trip Cancelled',
            'A trip you booked has been cancelled by the owner.',
            'dashboard-rider.php'
        );
    }
    
    logActivity($userId, 'trip_cancelled', "Trip ID: {$tripId}");
    
    sendResponse([
        'success' => true,
        'message' => 'Trip cancelled successfully'
    ]);
}

/**
 * Get user's trips (owner)
 */
function handleMyTrips() {
    requireAuth();
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    $status = $_GET['status'] ?? 'active';
    
    $query = "
        SELECT 
            t.*,
            v.type as vehicle_type, v.make, v.model,
            dc.name as departure_city_name,
            da.name as departure_area_name,
            ac.name as arrival_city_name,
            aa.name as arrival_area_name,
            COUNT(DISTINCT b.id) as booking_count,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bookings
        FROM trips t
        INNER JOIN vehicles v ON t.vehicle_id = v.id
        INNER JOIN cities dc ON t.departure_city_id = dc.id
        LEFT JOIN areas da ON t.departure_area_id = da.id
        INNER JOIN cities ac ON t.arrival_city_id = ac.id
        LEFT JOIN areas aa ON t.arrival_area_id = aa.id
        LEFT JOIN bookings b ON t.id = b.trip_id
        WHERE t.user_id = ? AND t.status = ?
        GROUP BY t.id
        ORDER BY t.depart_datetime DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$userId, $status]);
    $trips = $stmt->fetchAll();
    
    sendResponse([
        'success' => true,
        'trips' => $trips
    ]);
}

?>