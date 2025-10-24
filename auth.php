<?php
/**
 * Go Swift - Authentication API
 * api/auth.php
 */

require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'signup':
        if ($method === 'POST') {
            handleSignup();
        }
        break;
    
    case 'login':
        if ($method === 'POST') {
            handleLogin();
        }
        break;
    
    case 'logout':
        if ($method === 'POST') {
            handleLogout();
        }
        break;
    
    case 'verify-email':
        if ($method === 'GET') {
            handleVerifyEmail();
        }
        break;
    
    case 'forgot-password':
        if ($method === 'POST') {
            handleForgotPassword();
        }
        break;
    
    case 'reset-password':
        if ($method === 'POST') {
            handleResetPassword();
        }
        break;
    
    case 'check-auth':
        if ($method === 'GET') {
            handleCheckAuth();
        }
        break;
    
    default:
        sendResponse(['error' => 'Invalid action'], 400);
}

/**
 * Handle user signup
 */
function handleSignup() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['name', 'email', 'phone', 'password', 'confirm_password', 'role'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            sendResponse(['error' => "Field '{$field}' is required"], 400);
        }
    }
    
    // Validate role
    if (!in_array($data['role'], ['owner', 'rider'])) {
        sendResponse(['error' => 'Invalid role'], 400);
    }
    
    // Validate email
    if (!validateEmail($data['email'])) {
        sendResponse(['error' => 'Invalid email address'], 400);
    }
    
    // Validate phone
    if (!validatePhone($data['phone'])) {
        sendResponse(['error' => 'Invalid phone number. Use format: 03001234567'], 400);
    }
    
    // Validate password
    if (strlen($data['password']) < 8) {
        sendResponse(['error' => 'Password must be at least 8 characters'], 400);
    }
    
    if ($data['password'] !== $data['confirm_password']) {
        sendResponse(['error' => 'Passwords do not match'], 400);
    }
    
    $db = getDB();
    
    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Email already registered'], 409);
    }
    
    // Check if phone already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->execute([$data['phone']]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Phone number already registered'], 409);
    }
    
    // Generate verification token
    $verificationToken = generateToken(32);
    
    // Insert user - AUTO VERIFY FOR TESTING (set is_verified = 1)
    $stmt = $db->prepare("
        INSERT INTO users (name, email, phone, password_hash, role, is_verified, verification_token) 
        VALUES (?, ?, ?, ?, ?, 1, ?)
    ");
    
    try {
        $stmt->execute([
            sanitize($data['name']),
            strtolower(trim($data['email'])),
            $data['phone'],
            hashPassword($data['password']),
            $data['role'],
            $verificationToken
        ]);
        
        $userId = $db->lastInsertId();
        
        // For production: Send verification email
        // sendVerificationEmail($userId, $data['email'], $verificationToken);
        
        // Log activity
        // logActivity($userId, 'user_registered', "Role: {$data['role']}");
        
        sendResponse([
            'success' => true,
            'message' => 'Account created successfully! You can now login.',
            'user_id' => $userId,
            'auto_verified' => true
        ], 201);
        
    } catch (PDOException $e) {
        sendResponse(['error' => 'Failed to create account: ' . $e->getMessage()], 500);
    }
}

/**
 * Handle user login
 */
function handleLogin() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['email']) || empty($data['password'])) {
        sendResponse(['error' => 'Email and password are required'], 400);
    }
    
    $db = getDB();
    
    // Get user by email or phone
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR phone = ?");
    $stmt->execute([$data['email'], $data['email']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendResponse(['error' => 'Invalid credentials. Please check your email/phone and password.'], 401);
    }
    
    // Verify password
    if (!verifyPassword($data['password'], $user['password_hash'])) {
        sendResponse(['error' => 'Invalid credentials. Please check your email/phone and password.'], 401);
    }
    
    // Check if email is verified (DISABLED FOR TESTING)
    // if (!$user['is_verified']) {
    //     sendResponse([
    //         'error' => 'Please verify your email before logging in',
    //         'needs_verification' => true
    //     ], 403);
    // }
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    
    // Update last login
    $stmt = $db->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // Log activity
    // logActivity($user['id'], 'user_login');
    
    sendResponse([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'is_verified' => (bool)$user['is_verified'],
            'profile_photo' => $user['profile_photo']
        ]
    ]);
}

/**
 * Handle user logout
 */
function handleLogout() {
    requireAuth();
    
    $userId = getCurrentUserId();
    
    // Log activity
    // logActivity($userId, 'user_logout');
    
    // Destroy session
    session_destroy();
    
    sendResponse([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
}

/**
 * Handle email verification
 */
function handleVerifyEmail() {
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        sendResponse(['error' => 'Verification token is required'], 400);
    }
    
    $db = getDB();
    
    // Find user with token
    $stmt = $db->prepare("SELECT id, email FROM users WHERE verification_token = ? AND is_verified = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendResponse(['error' => 'Invalid or expired verification token'], 400);
    }
    
    // Update user as verified
    $stmt = $db->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // Log activity
    // logActivity($user['id'], 'email_verified');
    
    // Redirect to login page or send success response
    header('Location: ' . SITE_URL . '/auth.html?verified=1');
    exit;
}

/**
 * Handle forgot password
 */
function handleForgotPassword() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email'])) {
        sendResponse(['error' => 'Email is required'], 400);
    }
    
    $db = getDB();
    
    // Check if user exists
    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Generate reset token
        $resetToken = generateToken(32);
        
        // Store token in database
        $stmt = $db->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
        $stmt->execute([$resetToken, $user['id']]);
        
        // Send reset email (disabled for testing)
        /*
        $resetLink = SITE_URL . "/reset-password.html?token=" . $resetToken;
        $subject = "Reset Your Go Swift Password";
        $body = "
            <html>
            <body style='font-family: Arial, sans-serif;'>
                <h2>Password Reset Request</h2>
                <p>Hi {$user['name']},</p>
                <p>Click the link below to reset your password:</p>
                <p><a href='{$resetLink}' style='background: #1E90FF; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Reset Password</a></p>
                <p>Or copy this link: {$resetLink}</p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn't request this, please ignore this email.</p>
            </body>
            </html>
        ";
        
        sendEmail($user['email'], $subject, $body);
        */
    }
    
    // Always return success to prevent email enumeration
    sendResponse([
        'success' => true,
        'message' => 'If the email exists, a password reset link has been sent'
    ]);
}

/**
 * Handle password reset
 */
function handleResetPassword() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['token']) || empty($data['password']) || empty($data['confirm_password'])) {
        sendResponse(['error' => 'All fields are required'], 400);
    }
    
    if ($data['password'] !== $data['confirm_password']) {
        sendResponse(['error' => 'Passwords do not match'], 400);
    }
    
    if (strlen($data['password']) < 8) {
        sendResponse(['error' => 'Password must be at least 8 characters'], 400);
    }
    
    $db = getDB();
    
    // Find user with reset token
    $stmt = $db->prepare("SELECT id FROM users WHERE verification_token = ?");
    $stmt->execute([$data['token']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendResponse(['error' => 'Invalid or expired reset token'], 400);
    }
    
    // Update password
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, verification_token = NULL WHERE id = ?");
    $stmt->execute([
        hashPassword($data['password']),
        $user['id']
    ]);
    
    // Log activity
    // logActivity($user['id'], 'password_reset');
    
    sendResponse([
        'success' => true,
        'message' => 'Password reset successfully. You can now login with your new password.'
    ]);
}

/**
 * Check authentication status
 */
function handleCheckAuth() {
    if (!isLoggedIn()) {
        sendResponse(['authenticated' => false]);
    }
    
    $user = getCurrentUser();
    
    sendResponse([
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'is_verified' => (bool)$user['is_verified'],
            'profile_photo' => $user['profile_photo']
        ]
    ]);
}

?>