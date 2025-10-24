<?php
/**
 * Go Swift - Bookings API (FIXED VERSION)
 * api/bookings.php
 */

require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        if ($method === 'POST') {
            handleCreateBooking();
        }
        break;
    
    case 'confirm':
        if ($method === 'POST') {
            handleConfirmBooking();
        }
        break;
    
    case 'cancel':
        if ($method === 'POST') {
            handleCancelBooking();
        }
        break;
    
    case 'my-bookings':
        if ($method === 'GET') {
            handleMyBookings();
        }
        break;
    
    case 'booking-requests':
        if ($method === 'GET') {
            handleBookingRequests();
        }
        break;
    
    case 'complete':
        if ($method === 'POST') {
            handleCompleteBooking();
        }
        break;
    
    default:
        sendResponse(['error' => 'Invalid action'], 400);
}

/**
 * Create a new booking - FIXED VERSION
 */
function handleCreateBooking() {
    // Check if user is logged in
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Please login to book trips'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['trip_id']) || !isset($data['seats_booked'])) {
        sendResponse(['error' => 'Trip ID and seats are required'], 400);
    }
    
    $userId = getCurrentUserId();
    $user = getCurrentUser();
    
    $db = getDB();
    
    // FIXED: Get trip details with correct owner_id
    $stmt = $db->prepare("
        SELECT t.*, t.user_id as trip_owner_id 
        FROM trips t 
        WHERE t.id = ? AND t.status = 'active'
    ");
    $stmt->execute([$data['trip_id']]);
    $trip = $stmt->fetch();
    
    if (!$trip) {
        sendResponse(['error' => 'Trip not found or not available'], 404);
    }
    
    // FIXED: Check if user is trying to book their own trip
    if ($trip['trip_owner_id'] == $userId) {
        sendResponse(['error' => 'You cannot book your own trip'], 400);
    }
    
    // Check if enough seats available
    if ($data['seats_booked'] > $trip['seats_left']) {
        sendResponse(['error' => 'Not enough seats available'], 400);
    }
    
    // Check if trip is in the future
    if (strtotime($trip['depart_datetime']) <= time()) {
        sendResponse(['error' => 'Cannot book past trips'], 400);
    }
    
    // Check for existing booking
    $stmt = $db->prepare("
        SELECT id FROM bookings 
        WHERE trip_id = ? AND rider_user_id = ? AND status IN ('pending', 'confirmed')
    ");
    $stmt->execute([$data['trip_id'], $userId]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'You already have a booking for this trip'], 409);
    }
    
    // Calculate total price
    $totalPrice = $trip['price_per_seat'] * $data['seats_booked'];
    
    // Generate booking code
    $bookingCode = strtoupper(substr(uniqid(), -8));
    
    // Create booking
    $stmt = $db->prepare("
        INSERT INTO bookings (trip_id, rider_user_id, seats_booked, total_price, booking_code, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    
    try {
        $stmt->execute([
            $data['trip_id'],
            $userId,
            $data['seats_booked'],
            $totalPrice,
            $bookingCode
        ]);
        
        $bookingId = $db->lastInsertId();
        
        // Update available seats
        $stmt = $db->prepare("UPDATE trips SET seats_left = seats_left - ? WHERE id = ?");
        $stmt->execute([$data['seats_booked'], $data['trip_id']]);
        
        // Notify owner
        createNotification(
            $trip['trip_owner_id'],
            'booking_request',
            'New Booking Request',
            "{$user['name']} requested to book {$data['seats_booked']} seat(s) for your trip.",
            null
        );
        
        // Log activity
        logActivity($userId, 'booking_created', "Booking ID: {$bookingId}");
        
        sendResponse([
            'success' => true,
            'message' => 'Booking request sent successfully',
            'booking_id' => $bookingId,
            'booking_code' => $bookingCode,
            'status' => 'pending'
        ], 201);
        
    } catch (PDOException $e) {
        sendResponse(['error' => 'Failed to create booking: ' . $e->getMessage()], 500);
    }
}

/**
 * Confirm booking (owner action)
 */
function handleConfirmBooking() {
    requireAuth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $bookingId = $data['booking_id'] ?? null;
    
    if (!$bookingId) {
        sendResponse(['error' => 'Booking ID is required'], 400);
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Verify booking belongs to user's trip
    $stmt = $db->prepare("
        SELECT b.*, t.user_id as trip_owner_id, b.rider_user_id
        FROM bookings b
        INNER JOIN trips t ON b.trip_id = t.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        sendResponse(['error' => 'Booking not found'], 404);
    }
    
    if ($booking['trip_owner_id'] != $userId) {
        sendResponse(['error' => 'Unauthorized'], 403);
    }
    
    if ($booking['status'] !== 'pending') {
        sendResponse(['error' => 'Booking is not pending'], 400);
    }
    
    // Confirm booking
    $stmt = $db->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
    $stmt->execute([$bookingId]);
    
    // Notify rider
    createNotification(
        $booking['rider_user_id'],
        'booking_confirmed',
        'Booking Confirmed',
        'Your booking has been confirmed by the vehicle owner.',
        null
    );
    
    // Log activity
    logActivity($userId, 'booking_confirmed', "Booking ID: {$bookingId}");
    
    sendResponse([
        'success' => true,
        'message' => 'Booking confirmed successfully'
    ]);
}

/**
 * Cancel booking
 */
function handleCancelBooking() {
    requireAuth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $bookingId = $data['booking_id'] ?? null;
    $reason = $data['reason'] ?? 'No reason provided';
    
    if (!$bookingId) {
        sendResponse(['error' => 'Booking ID is required'], 400);
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Get booking details
    $stmt = $db->prepare("
        SELECT b.*, t.user_id as trip_owner_id, b.rider_user_id, b.seats_booked, b.trip_id
        FROM bookings b
        INNER JOIN trips t ON b.trip_id = t.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        sendResponse(['error' => 'Booking not found'], 404);
    }
    
    // Verify user is either the rider or trip owner
    if ($booking['rider_user_id'] != $userId && $booking['trip_owner_id'] != $userId) {
        sendResponse(['error' => 'Unauthorized'], 403);
    }
    
    if ($booking['status'] === 'cancelled' || $booking['status'] === 'completed') {
        sendResponse(['error' => 'Cannot cancel this booking'], 400);
    }
    
    // Cancel booking
    $stmt = $db->prepare("
        UPDATE bookings 
        SET status = 'cancelled', cancellation_reason = ? 
        WHERE id = ?
    ");
    $stmt->execute([$reason, $bookingId]);
    
    // Restore seats
    $stmt = $db->prepare("UPDATE trips SET seats_left = seats_left + ? WHERE id = ?");
    $stmt->execute([$booking['seats_booked'], $booking['trip_id']]);
    
    // Notify the other party
    $notifyUserId = ($userId == $booking['rider_user_id']) ? $booking['trip_owner_id'] : $booking['rider_user_id'];
    createNotification(
        $notifyUserId,
        'booking_cancelled',
        'Booking Cancelled',
        'A booking has been cancelled.',
        null
    );
    
    // Log activity
    logActivity($userId, 'booking_cancelled', "Booking ID: {$bookingId}");
    
    sendResponse([
        'success' => true,
        'message' => 'Booking cancelled successfully'
    ]);
}

/**
 * Get user's bookings (rider)
 */
function handleMyBookings() {
    requireAuth();
    
    $userId = getCurrentUserId();
    
    $db = getDB();
    
    $status = $_GET['status'] ?? 'all';
    $whereStatus = $status !== 'all' ? "AND b.status = ?" : "";
    
    $query = "
        SELECT 
            b.*,
            t.depart_datetime, t.price_per_seat, t.notes as trip_notes,
            v.type as vehicle_type, v.make, v.model, v.plate_number,
            dc.name as departure_city_name,
            da.name as departure_area_name,
            ac.name as arrival_city_name,
            aa.name as arrival_area_name,
            u.id as owner_id, u.name as owner_name, u.phone as owner_phone, 
            u.profile_photo as owner_photo
        FROM bookings b
        INNER JOIN trips t ON b.trip_id = t.id
        INNER JOIN vehicles v ON t.vehicle_id = v.id
        INNER JOIN cities dc ON t.departure_city_id = dc.id
        LEFT JOIN areas da ON t.departure_area_id = da.id
        INNER JOIN cities ac ON t.arrival_city_id = ac.id
        LEFT JOIN areas aa ON t.arrival_area_id = aa.id
        INNER JOIN users u ON t.user_id = u.id
        WHERE b.rider_user_id = ? {$whereStatus}
        ORDER BY b.created_at DESC
    ";
    
    $stmt = $db->prepare($query);
    if ($status !== 'all') {
        $stmt->execute([$userId, $status]);
    } else {
        $stmt->execute([$userId]);
    }
    
    $bookings = $stmt->fetchAll();
    
    sendResponse([
        'success' => true,
        'bookings' => $bookings
    ]);
}

/**
 * Get booking requests for owner's trips
 */
function handleBookingRequests() {
    requireAuth();
    
    $userId = getCurrentUserId();
    
    $db = getDB();
    
    $status = $_GET['status'] ?? 'pending';
    
    $query = "
        SELECT 
            b.*,
            t.id as trip_id, t.depart_datetime, t.price_per_seat,
            dc.name as departure_city_name,
            da.name as departure_area_name,
            ac.name as arrival_city_name,
            aa.name as arrival_area_name,
            u.id as rider_id, u.name as rider_name, u.phone as rider_phone, 
            u.profile_photo as rider_photo
        FROM bookings b
        INNER JOIN trips t ON b.trip_id = t.id
        INNER JOIN cities dc ON t.departure_city_id = dc.id
        LEFT JOIN areas da ON t.departure_area_id = da.id
        INNER JOIN cities ac ON t.arrival_city_id = ac.id
        LEFT JOIN areas aa ON t.arrival_area_id = aa.id
        INNER JOIN users u ON b.rider_user_id = u.id
        WHERE t.user_id = ? AND b.status = ?
        ORDER BY b.created_at DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$userId, $status]);
    $requests = $stmt->fetchAll();
    
    sendResponse([
        'success' => true,
        'requests' => $requests
    ]);
}

/**
 * Mark booking as completed
 */
function handleCompleteBooking() {
    requireAuth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $bookingId = $data['booking_id'] ?? null;
    
    if (!$bookingId) {
        sendResponse(['error' => 'Booking ID is required'], 400);
    }
    
    $userId = getCurrentUserId();
    $db = getDB();
    
    // Verify booking belongs to user's trip
    $stmt = $db->prepare("
        SELECT b.*, t.user_id as trip_owner_id, b.rider_user_id
        FROM bookings b
        INNER JOIN trips t ON b.trip_id = t.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        sendResponse(['error' => 'Booking not found'], 404);
    }
    
    if ($booking['trip_owner_id'] != $userId) {
        sendResponse(['error' => 'Unauthorized'], 403);
    }
    
    if ($booking['status'] !== 'confirmed') {
        sendResponse(['error' => 'Only confirmed bookings can be completed'], 400);
    }
    
    // Complete booking
    $stmt = $db->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
    $stmt->execute([$bookingId]);
    
    // Notify rider to leave review
    createNotification(
        $booking['rider_user_id'],
        'trip_completed',
        'Trip Completed',
        'Your trip has been completed. Please leave a review!',
        null
    );
    
    // Log activity
    logActivity($userId, 'booking_completed', "Booking ID: {$bookingId}");
    
    sendResponse([
        'success' => true,
        'message' => 'Booking marked as completed'
    ]);
}

?>