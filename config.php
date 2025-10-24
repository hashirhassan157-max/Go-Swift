<?php
/**
 * Go Swift - Database Configuration & Helper Functions
 * config.php
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'goswift');
define('DB_USER', 'root');
define('DB_PASS', '');

// Application settings
define('SITE_URL', 'http://localhost/goswift');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB

// Email configuration (for verification)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-password');
define('FROM_EMAIL', 'noreply@goswift.com');
define('FROM_NAME', 'Go Swift');

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

/**
 * Database connection using PDO
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    
    return $pdo;
}

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate phone number (Pakistani format)
 */
function validatePhone($phone) {
    $pattern = '/^(\+92|0)?[0-9]{10}$/';
    return preg_match($pattern, $phone);
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Generate random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, email, phone, role, is_verified, profile_photo FROM users WHERE id = ?");
    $stmt->execute([getCurrentUserId()]);
    return $stmt->fetch();
}

/**
 * Require authentication
 */
function requireAuth() {
    if (!isLoggedIn()) {
        sendResponse(['error' => 'Authentication required'], 401);
    }
}

/**
 * Require specific role
 */
function requireRole($role) {
    requireAuth();
    $user = getCurrentUser();
    if ($user['role'] !== $role) {
        sendResponse(['error' => 'Insufficient permissions'], 403);
    }
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Upload file
 */
function uploadFile($file, $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf']) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'No file uploaded'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['error' => 'File size exceeds limit'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['error' => 'Invalid file type'];
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $destination = UPLOAD_DIR . $filename;
    
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => true, 'filename' => $filename, 'path' => $destination];
    }
    
    return ['error' => 'Failed to upload file'];
}

/**
 * Send email (basic implementation)
 */
function sendEmail($to, $subject, $body) {
    // In production, use PHPMailer or similar library
    $headers = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    return mail($to, $subject, $body, $headers);
}

/**
 * Send verification email
 */
function sendVerificationEmail($userId, $email, $token) {
    $verificationLink = SITE_URL . "/api/verify-email.php?token=" . $token;
    
    $subject = "Verify Your Go Swift Account";
    $body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Welcome to Go Swift!</h2>
            <p>Please verify your email address by clicking the link below:</p>
            <p><a href='{$verificationLink}' style='background: #1E90FF; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Verify Email</a></p>
            <p>Or copy this link: {$verificationLink}</p>
            <p>This link will expire in 24 hours.</p>
            <p>If you didn't create this account, please ignore this email.</p>
        </body>
        </html>
    ";
    
    return sendEmail($email, $subject, $body);
}

/**
 * Create notification
 */
function createNotification($userId, $type, $title, $message, $link = null) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, type, title, message, link) 
        VALUES (?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$userId, $type, $title, $message, $link]);
}

/**
 * Get user's unread notification count
 */
function getUnreadNotificationCount($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result['count'];
}

/**
 * Format date for display
 */
function formatDate($datetime, $format = 'M d, Y g:i A') {
    return date($format, strtotime($datetime));
}

/**
 * Calculate distance between two cities (placeholder)
 */
function calculateDistance($city1Id, $city2Id) {
    // In production, use actual coordinates and distance calculation
    $distances = [
        '1-2' => 1200, // Karachi to Lahore
        '2-1' => 1200,
        '2-3' => 375,  // Lahore to Islamabad
        '3-2' => 375,
        '3-4' => 15,   // Islamabad to Rawalpindi
        '4-3' => 15,
    ];
    
    $key = $city1Id . '-' . $city2Id;
    return $distances[$key] ?? 0;
}

/**
 * Log activity
 */
function logActivity($userId, $action, $details = '') {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO activity_log (user_id, action, details, ip_address) 
        VALUES (?, ?, ?, ?)
    ");
    return $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR']]);
}

// Set CORS headers for API requests
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

?>