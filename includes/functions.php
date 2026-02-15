<?php
// ============================================
// HELPER FUNCTIONS
// includes/functions.php
// ============================================

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Function to validate email
function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Function to validate phone number
function isValidPhone($phone)
{
    return preg_match('/^[0-9]{10,15}$/', $phone);
}

// Function to validate password strength
function isStrongPassword($password)
{
    // At least 8 characters, one uppercase, one lowercase, one number
    return strlen($password) >= 8 &&
        preg_match('/[A-Z]/', $password) &&
        preg_match('/[a-z]/', $password) &&
        preg_match('/[0-9]/', $password);
}

// Function to hash password
function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

// Function to verify password
function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

// Function to generate random token
function generateToken($length = 32)
{
    return bin2hex(random_bytes($length));
}

// Function to register a new user
function registerUser($full_name, $email, $phone, $password)
{
    global $conn;

    // Validate inputs
    if (empty($full_name) || empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    if (!isValidEmail($email)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }
    // Check if email domain exists (basic validation)
    list($user, $domain) = explode('@', $email);
    if (!checkdnsrr($domain, 'MX')) {
        return ['success' => false, 'message' => 'Email domain does not exist. Please use a valid email address.'];
    }

    if (!empty($phone) && !isValidPhone($phone)) {
        return ['success' => false, 'message' => 'Invalid phone number format'];
    }

    if (!isStrongPassword($password)) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters with uppercase, lowercase, and numbers'];
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Email already registered'];
    }

    // Hash password
    $hashed_password = hashPassword($password);

    // Insert user
    // Insert user with pending approval status
$approval_status = 'pending'; // Users need approval
$stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, password, role, approval_status) VALUES (?, ?, ?, ?, 'user', ?)");
$stmt->bind_param("sssss", $full_name, $email, $phone, $hashed_password, $approval_status);

    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Registration successful'];
    } else {
        return ['success' => false, 'message' => 'Registration failed. Please try again'];
    }
}

// Function to login user
function loginUser($email, $password)
{
    global $conn;

    if (empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Email and password are required'];
    }

    $ip_address = getClientIP();

    // Check if account is locked
    $lock_status = isAccountLocked($email);
    if ($lock_status['locked']) {
        return [
            'success' => false,
            'message' => 'Account is locked due to too many failed login attempts. Please try again in ' . $lock_status['remaining_minutes'] . ' minute(s).',
            'locked' => true,
            'unlock_time' => $lock_status['unlock_time']
        ];
    }

    // Fetch user from database
    $stmt = $conn->prepare("SELECT user_id, full_name, email, password, role, admin_level, status, approval_status, failed_login_attempts FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Record failed attempt
        recordFailedLogin($email, $ip_address);
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    $user = $result->fetch_assoc();

    // Verify password
    if (!verifyPassword($password, $user['password'])) {
        // Record failed attempt
        $attempt_result = recordFailedLogin($email, $ip_address);

        if ($attempt_result['locked']) {
            return [
                'success' => false,
                'message' => 'Too many failed login attempts! Your account has been locked for ' . LOCKOUT_DURATION . ' minutes.',
                'locked' => true
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Invalid email or password. ' . $attempt_result['remaining'] . ' attempt(s) remaining before account lockout.',
                'attempts_remaining' => $attempt_result['remaining']
            ];
        }
    }

    // Check if account is active
    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'Your account is inactive. Contact administrator'];
    }

    // Check if account is approved
    if (isset($user['approval_status']) && $user['approval_status'] === 'pending') {
        return ['success' => false, 'message' => 'Your account is pending approval. You will receive an email once approved.'];
    }

    if (isset($user['approval_status']) && $user['approval_status'] === 'rejected') {
        return ['success' => false, 'message' => 'Your account has been rejected. Please contact administrator.'];
    }

    // Successful login - record it and reset attempts
    recordSuccessfulLogin($email, $ip_address);

    // Set session variables
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    // Set admin_level if user is admin
    if ($user['role'] === 'admin') {
        $_SESSION['admin_level'] = $user['admin_level'] ?? 'admin';
    }

    return ['success' => true, 'message' => 'Login successful', 'role' => $user['role']];
}



// Function to check if user is super admin
function isSuperAdmin()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === ROLE_ADMIN &&
        isset($_SESSION['admin_level']) && $_SESSION['admin_level'] === 'super_admin';
}

// Function to require super admin access
function requireSuperAdmin()
{
    requireLogin();
    if (!isSuperAdmin()) {
        header("Location: " . SITE_URL . "admin/index.php");
        exit();
    }
}

// Function to logout user
function logoutUser()
{
    session_unset();
    session_destroy();
    header("Location: " . SITE_URL . "auth/login.php");
    exit();
}

// Function to get user by ID
function getUserById($user_id)
{
    global $conn;

    $stmt = $conn->prepare("SELECT user_id, full_name, email, phone, role, status, profile_picture, created_at FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_assoc();
}

// Function to update user profile
function updateUserProfile($user_id, $full_name, $email, $phone)
{
    global $conn;

    if (empty($full_name) || empty($email)) {
        return ['success' => false, 'message' => 'Name and email are required'];
    }

    if (!isValidEmail($email)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }

    if (!empty($phone) && !isValidPhone($phone)) {
        return ['success' => false, 'message' => 'Invalid phone number format'];
    }

    // Check if email is already used by another user
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $stmt->bind_param("si", $email, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Email already used by another account'];
    }

    // Update user
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
    $stmt->bind_param("sssi", $full_name, $email, $phone, $user_id);

    if ($stmt->execute()) {
        // Update session
        $_SESSION['full_name'] = $full_name;
        $_SESSION['email'] = $email;
        return ['success' => true, 'message' => 'Profile updated successfully'];
    } else {
        return ['success' => false, 'message' => 'Update failed. Please try again'];
    }
}


// Function to change password
function changePassword($user_id, $current_password, $new_password)
{
    global $conn;

    if (empty($current_password) || empty($new_password)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    if (!isStrongPassword($new_password)) {
        return ['success' => false, 'message' => 'New password must be at least 8 characters with uppercase, lowercase, and numbers'];
    }

    // Get current password hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // Verify current password
    if (!verifyPassword($current_password, $user['password'])) {
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }

    // Hash new password
    $hashed_password = hashPassword($new_password);

    // Update password
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);

    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Password changed successfully'];
    } else {
        return ['success' => false, 'message' => 'Password change failed. Please try again'];
    }
}

// Function to get all categories
function getAllCategories()
{
    global $conn;

    $result = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY category_name ASC");
    $categories = [];

    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    return $categories;
}

// Function to show alert message
function showAlert($message, $type = 'info')
{
    $alertClass = 'alert-' . $type;
    echo "<div class='alert $alertClass alert-dismissible fade show' role='alert'>
            $message
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
          </div>";
}
// Function to create notification
function createNotification($user_id, $title, $message, $type = 'info', $complaint_id = null)
{
    global $conn;

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, complaint_id, title, message, type) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $user_id, $complaint_id, $title, $message, $type);

    return $stmt->execute();
}

// Function to get unread notification count
function getUnreadNotificationCount($user_id)
{
    global $conn;

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc()['count'];
}

// Function to get recent notifications
function getRecentNotifications($user_id, $limit = 5)
{
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();

    return $stmt->get_result();
}

// Function to mark notification as read
function markNotificationAsRead($notification_id)
{
    global $conn;

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ?");
    $stmt->bind_param("i", $notification_id);

    return $stmt->execute();
}

// Function to mark all notifications as read
function markAllNotificationsAsRead($user_id)
{
    global $conn;

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);

    return $stmt->execute();
}

// Function to add comment
function addComment($complaint_id, $user_id, $comment)
{
    global $conn;

    if (empty($comment)) {
        return ['success' => false, 'message' => 'Comment cannot be empty'];
    }

    $stmt = $conn->prepare("INSERT INTO complaint_comments (complaint_id, user_id, comment) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $complaint_id, $user_id, $comment);

    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Comment added successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to add comment'];
    }
}

// Function to get comments for a complaint
function getComplaintComments($complaint_id)
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT c.*, u.full_name, u.role 
        FROM complaint_comments c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.complaint_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();

    return $stmt->get_result();
}

// Function to get comment count
function getCommentCount($complaint_id)
{
    global $conn;

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM complaint_comments WHERE complaint_id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc()['count'];
}

// Function to send email using PHPMailer
function sendEmail($to, $subject, $message)
{
    $mail = new PHPMailer(true);

    try {
    
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME']; 
        $mail->Password   = $_ENV['MAIL_PASSWORD']; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['MAIL_PORT'];

        // Recipients
        $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']); 
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log error for debugging
        error_log("Email Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Function to send approval email
function sendApprovalEmail($user_email, $user_name, $status)
{
    if ($status === 'approved') {
        $subject = "Account Approved - " . SITE_NAME;
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 20px auto; background: white; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; }
                .content { padding: 30px; }
                .content h2 { color: #667eea; margin-top: 0; }
                .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; background: #f8f9fa; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 " . SITE_NAME . "</h1>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($user_name) . ",</h2>
                    <p style='font-size: 16px;'>Great news! Your account has been <strong style='color: #28a745;'>approved</strong> by our administrator.</p>
                    <p>You can now login and start using our complaint management system.</p>
                    <div style='text-align: center;'>
                        <a href='" . SITE_URL . "auth/login.php' class='button'>Login to Your Account</a>
                    </div>
                    <p>If you have any questions, feel free to contact us at <a href='mailto:" . ADMIN_EMAIL . "'>" . ADMIN_EMAIL . "</a></p>
                    <p style='margin-top: 30px;'>Best regards,<br><strong>" . SITE_NAME . " Team</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                    <p>This is an automated email. Please do not reply directly to this message.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    } else {
        $subject = "Account Registration Status - " . SITE_NAME;
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 20px auto; background: white; }
                .header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; }
                .content { padding: 30px; }
                .content h2 { color: #dc3545; margin-top: 0; }
                .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; background: #f8f9fa; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>" . SITE_NAME . "</h1>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($user_name) . ",</h2>
                    <p style='font-size: 16px;'>We regret to inform you that your account registration has been <strong style='color: #dc3545;'>not approved</strong> at this time.</p>
                    <p>If you believe this is a mistake or need more information, please contact our support team:</p>
                    <p><strong>Email:</strong> <a href='mailto:" . ADMIN_EMAIL . "'>" . ADMIN_EMAIL . "</a></p>
                    <p style='margin-top: 30px;'>Best regards,<br><strong>" . SITE_NAME . " Team</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    return sendEmail($user_email, $subject, $message);
}
// Function to check daily complaint limit
function checkDailyComplaintLimit($user_id)
{
    global $conn;

    $today = date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM complaints 
        WHERE user_id = ? 
        AND DATE(submitted_date) = ?
    ");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return [
        'count' => (int)$result['count'],
        'limit' => DAILY_COMPLAINT_LIMIT,
        'remaining' => DAILY_COMPLAINT_LIMIT - (int)$result['count'],
        'can_submit' => (int)$result['count'] < DAILY_COMPLAINT_LIMIT
    ];
}

// Function to get user's complaints today
function getTodayComplaintsCount($user_id)
{
    global $conn;

    $today = date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM complaints 
        WHERE user_id = ? 
        AND DATE(submitted_date) = ?
    ");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();

    return (int)$stmt->get_result()->fetch_assoc()['count'];
}
// Function to check if account is locked
function isAccountLocked($email)
{
    global $conn;

    $stmt = $conn->prepare("SELECT account_locked_until FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return ['locked' => false];
    }

    $user = $result->fetch_assoc();

    if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
        $unlock_time = strtotime($user['account_locked_until']);
        $remaining_minutes = ceil(($unlock_time - time()) / 60);

        return [
            'locked' => true,
            'unlock_time' => $user['account_locked_until'],
            'remaining_minutes' => $remaining_minutes
        ];
    }

    // If lockout expired, unlock account
    if ($user['account_locked_until']) {
        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
    }

    return ['locked' => false];
}

// Function to record failed login attempt
function recordFailedLogin($email, $ip_address)
{
    global $conn;

    // Log the attempt
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 0)");
    $stmt->bind_param("ss", $email, $ip_address);
    $stmt->execute();

    // Update user's failed attempts
    $stmt = $conn->prepare("
        UPDATE users 
        SET failed_login_attempts = failed_login_attempts + 1,
            last_failed_login = NOW()
        WHERE email = ?
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    // Check if lockout needed
    $stmt = $conn->prepare("SELECT failed_login_attempts FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $attempts = $user['failed_login_attempts'];

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            // Lock the account
            $lockout_until = date('Y-m-d H:i:s', strtotime('+' . LOCKOUT_DURATION . ' minutes'));

            $stmt = $conn->prepare("UPDATE users SET account_locked_until = ? WHERE email = ?");
            $stmt->bind_param("ss", $lockout_until, $email);
            $stmt->execute();

            return [
                'locked' => true,
                'attempts' => $attempts,
                'lockout_duration' => LOCKOUT_DURATION
            ];
        }

        return [
            'locked' => false,
            'attempts' => $attempts,
            'remaining' => MAX_LOGIN_ATTEMPTS - $attempts
        ];
    }

    return ['locked' => false, 'attempts' => 1, 'remaining' => MAX_LOGIN_ATTEMPTS - 1];
}

// Function to record successful login
function recordSuccessfulLogin($email, $ip_address)
{
    global $conn;

    // Log successful attempt
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 1)");
    $stmt->bind_param("ss", $email, $ip_address);
    $stmt->execute();

    // Reset failed attempts
    $stmt = $conn->prepare("
        UPDATE users 
        SET failed_login_attempts = 0,
            last_failed_login = NULL,
            account_locked_until = NULL
        WHERE email = ?
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

// Function to get client IP address
function getClientIP()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}
// Function to generate OTP
function generateOTP($length = 6)
{
    return str_pad(rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Function to create password reset request
function createPasswordResetRequest($email)
{
    global $conn;

    // Check if email exists
    $stmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'No account found with this email address'];
    }

    $user = $result->fetch_assoc();

    // Generate OTP and token
    $otp = generateOTP(6);
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes')); // OTP valid for 15 minutes

    // Delete old reset requests for this user
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    // Insert new reset request
    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, email, otp_code, token, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $user['user_id'], $email, $otp, $token, $expires_at);

    if ($stmt->execute()) {
        // Send OTP email
        $subject = "Password Reset OTP - " . SITE_NAME;
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 20px auto; background: white; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; }
                .otp-box { background: #f8f9fa; border: 2px dashed #667eea; padding: 20px; text-align: center; margin: 20px 0; border-radius: 10px; }
                .otp-code { font-size: 32px; font-weight: bold; color: #667eea; letter-spacing: 5px; }
                .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; background: #f8f9fa; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 Password Reset Request</h1>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($user['full_name']) . ",</h2>
                    <p>We received a request to reset your password. Use the OTP code below to proceed:</p>
                    
                    <div class='otp-box'>
                        <div style='font-size: 14px; color: #6c757d; margin-bottom: 10px;'>Your OTP Code</div>
                        <div class='otp-code'>" . $otp . "</div>
                        <div style='font-size: 12px; color: #6c757d; margin-top: 10px;'>Valid for 15 minutes</div>
                    </div>
                    
                    <p><strong>Important:</strong></p>
                    <ul>
                        <li>Do not share this code with anyone</li>
                        <li>This code expires in 15 minutes</li>
                        <li>If you didn't request this, please ignore this email</li>
                    </ul>
                    
                    <p style='margin-top: 30px;'>Best regards,<br><strong>" . SITE_NAME . " Team</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                    <p>This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        sendEmail($email, $subject, $message);

        return [
            'success' => true,
            'message' => 'OTP has been sent to your email',
            'token' => $token
        ];
    }

    return ['success' => false, 'message' => 'Failed to create reset request'];
}

// Function to verify OTP
function verifyOTP($email, $otp)
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT reset_id, token, user_id 
        FROM password_resets 
        WHERE email = ? AND otp_code = ? AND is_used = 0 AND expires_at > NOW()
    ");
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'Invalid or expired OTP'];
    }

    $reset = $result->fetch_assoc();

    return [
        'success' => true,
        'message' => 'OTP verified successfully',
        'token' => $reset['token'],
        'user_id' => $reset['user_id']
    ];
}

// Function to reset password with token
function resetPasswordWithToken($token, $new_password)
{
    global $conn;

    // Verify token
    $stmt = $conn->prepare("
        SELECT user_id, email 
        FROM password_resets 
        WHERE token = ? AND is_used = 0 AND expires_at > NOW()
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'Invalid or expired reset token'];
    }

    $reset = $result->fetch_assoc();

    // Validate password
    if (!isStrongPassword($new_password)) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters with uppercase, lowercase, and numbers'];
    }

    // Update password
    $hashed_password = hashPassword($new_password);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
    $stmt->bind_param("si", $hashed_password, $reset['user_id']);

    if ($stmt->execute()) {
        // Mark token as used
        $stmt = $conn->prepare("UPDATE password_resets SET is_used = 1 WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();

        // Reset failed login attempts
        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $reset['user_id']);
        $stmt->execute();

        return ['success' => true, 'message' => 'Password reset successfully'];
    }

    return ['success' => false, 'message' => 'Failed to reset password'];
}
// Function to upload profile picture
function uploadProfilePicture($file, $user_id)
{
    // Allowed file types
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
    $max_size = 2 * 1024 * 1024; // 2MB

    // Validate file
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'No file uploaded'];
    }

    // Check file type
    $file_type = $file['type'];
    if (!in_array($file_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed'];
    }

    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large. Maximum size is 2MB'];
    }

    // Get file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Generate unique filename
    $new_filename = 'user_' . $user_id . '_' . time() . '.' . $extension;

    // Upload directory
    $upload_dir = dirname(__DIR__) . '/uploads/avatars/';
    $upload_path = $upload_dir . $new_filename;
    $db_path = 'uploads/avatars/' . $new_filename;

    // Create directory if not exists
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        // Delete old profile picture (if exists and not default)
        global $conn;
        $stmt = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $old_pic = $result->fetch_assoc()['profile_picture'];
            if ($old_pic && file_exists(dirname(__DIR__) . '/' . $old_pic)) {
                // Don't delete default avatar
                if (!strpos($old_pic, 'default-avatar')) {
                    unlink(dirname(__DIR__) . '/' . $old_pic);
                }
            }
        }

        // Update database
        $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
        $stmt->bind_param("si", $db_path, $user_id);

        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Profile picture updated successfully',
                'file_path' => $db_path
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to update database'];
        }
    } else {
        return ['success' => false, 'message' => 'Failed to upload file'];
    }
}

// Function to delete profile picture
function deleteProfilePicture($user_id)
{
    global $conn;

    $stmt = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $pic = $result->fetch_assoc()['profile_picture'];

        if ($pic && file_exists(dirname(__DIR__) . '/' . $pic)) {
            unlink(dirname(__DIR__) . '/' . $pic);
        }

        // Set to NULL in database
        $stmt = $conn->prepare("UPDATE users SET profile_picture = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Profile picture deleted'];
        }
    }

    return ['success' => false, 'message' => 'Failed to delete picture'];
}

// Function to get user avatar (with fallback)
function getUserAvatar($user_id = null, $size = 'md')
{
    global $conn;

    $sizes = [
        'sm' => 35,
        'md' => 42,
        'lg' => 100,
        'xl' => 150
    ];

    $dimension = $sizes[$size] ?? 42;

    if ($user_id) {
        $stmt = $conn->prepare("SELECT profile_picture, full_name FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            if ($user['profile_picture'] && file_exists(dirname(__DIR__) . '/' . $user['profile_picture'])) {
                return '<img src="' . SITE_URL . $user['profile_picture'] . '" class="rounded-circle" width="' . $dimension . '" height="' . $dimension . '" style="object-fit: cover;" alt="Profile">';
            } else {
                // Show initials
                $initial = strtoupper(substr($user['full_name'], 0, 1));
                return '<div class="user-avatar" style="width: ' . $dimension . 'px; height: ' . $dimension . 'px; font-size: ' . ($dimension * 0.4) . 'px;">' . $initial . '</div>';
            }
        }
    }

    // Default avatar
    return '<div class="user-avatar" style="width: ' . $dimension . 'px; height: ' . $dimension . 'px;"><i class="bi bi-person"></i></div>';
}


// Function to delete user account permanently
function deleteUserAccount($user_id)
{
    global $conn;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get user info before deletion
        $stmt = $conn->prepare("SELECT email, full_name, profile_picture FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        // 1. Delete profile picture file if exists
        if ($user['profile_picture'] && file_exists(dirname(__DIR__) . '/' . $user['profile_picture'])) {
            unlink(dirname(__DIR__) . '/' . $user['profile_picture']);
        }
        
        // 2. Delete complaint attachments files and records
        $stmt = $conn->prepare("
            SELECT ca.file_path 
            FROM complaint_attachments ca
            JOIN complaints c ON ca.complaint_id = c.complaint_id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $attachments = $stmt->get_result();
        
        while ($attachment = $attachments->fetch_assoc()) {
            if (file_exists(dirname(__DIR__) . '/' . $attachment['file_path'])) {
                unlink(dirname(__DIR__) . '/' . $attachment['file_path']);
            }
        }
        
        // 3. Delete complaint attachments records (CASCADE will handle this, but explicit is safer)
        $stmt = $conn->prepare("
            DELETE ca FROM complaint_attachments ca
            JOIN complaints c ON ca.complaint_id = c.complaint_id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // 4. Delete complaint comments (CASCADE will handle this)
        $stmt = $conn->prepare("
            DELETE cc FROM complaint_comments cc
            JOIN complaints c ON cc.complaint_id = c.complaint_id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // 5. Delete complaint history (CASCADE will handle this)
        $stmt = $conn->prepare("
            DELETE ch FROM complaint_history ch
            JOIN complaints c ON ch.complaint_id = c.complaint_id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // 6. Delete notifications
        $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // 7. Delete password reset tokens
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // 8. Delete login attempts
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->bind_param("s", $user['email']);
        $stmt->execute();
        
        // 9. Delete all complaints (CASCADE will handle related records)
        $stmt = $conn->prepare("DELETE FROM complaints WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // 10. Finally, delete the user
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        return ['success' => true, 'message' => 'Account deleted successfully'];
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        error_log("Account deletion error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete account. Please try again.'];
    }
}

// Function to send account deletion confirmation email
function sendAccountDeletionEmail($email, $name)
{
    $subject = "Account Deleted - " . SITE_NAME;
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 20px auto; background: white; }
            .header { background: #dc3545; color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; background: #f8f9fa; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>👋 Account Deleted</h1>
            </div>
            <div class='content'>
                <h2>Goodbye " . htmlspecialchars($name) . ",</h2>
                <p>Your account has been <strong>permanently deleted</strong> from " . SITE_NAME . ".</p>
                
                <p><strong>What was deleted:</strong></p>
                <ul>
                    <li>All your personal information</li>
                    <li>All your submitted complaints</li>
                    <li>All your comments and attachments</li>
                    <li>Your profile picture</li>
                </ul>
                
                <p>We're sorry to see you go. If you change your mind, you're welcome to create a new account anytime.</p>
                
                <p>If you didn't request this deletion, please contact us immediately at <a href='mailto:" . ADMIN_EMAIL . "'>" . ADMIN_EMAIL . "</a></p>
                
                <p style='margin-top: 30px;'>Best regards,<br><strong>" . SITE_NAME . " Team</strong></p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($email, $subject, $message);
}

// ============================================
// STATUS PROGRESSION FUNCTIONS
// ============================================

/**
 * Check if status transition is allowed
 */
function canChangeStatus($current_status, $new_status, $is_super_admin = false) {
    global $conn;
    
    // Same status - no change needed
    if ($current_status === $new_status) {
        return [
        'allowed' => false, 
        'message' => "Status is already '$current_status'. Please select a different status to update."
    ];
    }
    
    // Check if transition exists in rules (forward direction)
    $stmt = $conn->prepare("
        SELECT can_reverse 
        FROM status_progression_rules 
        WHERE current_status = ? AND allowed_next_status = ?
    ");
    $stmt->bind_param("ss", $current_status, $new_status);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Not a valid forward progression - check if it's a reverse
        $stmt = $conn->prepare("
            SELECT can_reverse 
            FROM status_progression_rules 
            WHERE current_status = ? AND allowed_next_status = ?
        ");
        $stmt->bind_param("ss", $new_status, $current_status);
        $stmt->execute();
        $reverse_result = $stmt->get_result();
        
        if ($reverse_result->num_rows === 0) {
            return [
                'allowed' => false, 
                'message' => "Cannot change status from '$current_status' to '$new_status'. This transition is not allowed."
            ];
        }
        
        // It's a reverse transition
        $reverse_rule = $reverse_result->fetch_assoc();
        
        if ($reverse_rule['can_reverse'] == 0) {
            // Cannot reverse at all
            return [
                'allowed' => false,
                'message' => "Cannot reverse status from '$current_status' to '$new_status'. This change cannot be reversed."
            ];
        } else {
            // Can reverse, but only super admin
            if (!$is_super_admin) {
                return [
                    'allowed' => false,
                    'message' => "Cannot reverse status from '$current_status' to '$new_status'. Only Super Admin can reverse this change."
                ];
            }
        }
    }
    
    // Valid progression - allowed
    return ['allowed' => true, 'message' => 'Status change allowed'];
}

/**
 * Get allowed next statuses for current status
 */
function getAllowedNextStatuses($current_status, $is_super_admin = false) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT allowed_next_status, can_reverse 
        FROM status_progression_rules 
        WHERE current_status = ?
    ");
    $stmt->bind_param("s", $current_status);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $allowed = [];
    while ($row = $result->fetch_assoc()) {
        $allowed[] = [
            'status' => $row['allowed_next_status'],
            'can_reverse' => $row['can_reverse']
        ];
    }
    
    return $allowed;
}

/**
 * Check if admin can modify complaint
 */
function canAdminModifyComplaint($complaint_id, $admin_id, $is_super_admin) {
 global $conn;
    
    $stmt = $conn->prepare("
        SELECT assigned_to, assigned_by, status, is_locked 
        FROM complaints 
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['can_modify' => false, 'reason' => 'Complaint not found'];
    }
    
    $complaint = $result->fetch_assoc();
    
    // Check if complaint is locked (HIGHEST PRIORITY - blocks everyone including Super Admin)
    if ($complaint['is_locked'] == 1) {
        return [
            'can_modify' => false, 
            'reason' => 'This complaint is LOCKED by Super Admin. No modifications allowed until unlocked.'
        ];
    }
    
    // Check if complaint is closed
    if ($complaint['status'] === 'Closed') {
        return [
            'can_modify' => false, 
            'reason' => 'This complaint is closed and cannot be modified. User has confirmed the resolution.'
        ];
    }   

    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['can_modify' => false, 'reason' => 'Complaint not found'];
    }
    
    $complaint = $result->fetch_assoc();
    
    // Complaint not assigned yet
    if (empty($complaint['assigned_to'])) {
        if ($is_super_admin) {
            return ['can_modify' => false, 'reason' => 'Please assign this complaint first before taking action'];
        } else {
            return ['can_modify' => false, 'reason' => 'This complaint has not been assigned yet. Only Super Admin can assign complaints.'];
        }
    }
    
    // Super admin assigned to themselves
    if ($is_super_admin && $complaint['assigned_to'] == $admin_id) {
        return ['can_modify' => true, 'reason' => ''];
    }
    
    // Super admin trying to modify someone else's complaint
    if ($is_super_admin && $complaint['assigned_to'] != $admin_id) {
        return ['can_modify' => false, 'reason' => 'This complaint is assigned to another admin. Only the assigned admin can modify it.'];
    }
    
    // Regular admin checking their own assignment
    if ($complaint['assigned_to'] == $admin_id) {
        return ['can_modify' => true, 'reason' => ''];
    }
    
    // Regular admin trying to modify someone else's complaint
    return ['can_modify' => false, 'reason' => 'This complaint is assigned to another admin.'];
}

/**
 * Record assignment in history
 */
function recordAssignment($complaint_id, $assigned_by, $assigned_to, $note = '') {
    global $conn;
    
    // Get previous assignment
    $stmt = $conn->prepare("SELECT assigned_to FROM complaints WHERE complaint_id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $prev_assigned = $result->fetch_assoc()['assigned_to'] ?? null;
    
    $action_type = empty($prev_assigned) ? 'assigned' : 'reassigned';
    
    // Insert into assignment_history
    $stmt = $conn->prepare("
        INSERT INTO assignment_history 
        (complaint_id, assigned_by, assigned_from, assigned_to, assignment_note, action_type) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiiss", $complaint_id, $assigned_by, $prev_assigned, $assigned_to, $note, $action_type);
    
    return $stmt->execute();
}

/**
 * Get admin workload (number of active complaints)
 */
function getAdminWorkload($admin_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM complaints 
        WHERE assigned_to = ? 
        AND status NOT IN ('Closed', 'Resolved')
    ");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    
    return (int)$stmt->get_result()->fetch_assoc()['count'];
}

/**
 * Get all admins with their workload
 */
function getAdminsWithWorkload() {
    global $conn;
    
    $result = $conn->query("
        SELECT 
            u.user_id,
            u.full_name,
            u.admin_level,
            u.email,
            COUNT(CASE WHEN c.status NOT IN ('Closed', 'Resolved') THEN 1 END) as active_complaints,
            COUNT(c.complaint_id) as total_assigned,
            AVG(CASE 
                WHEN c.status = 'Resolved' 
                THEN TIMESTAMPDIFF(DAY, c.submitted_date, c.resolved_date) 
                END
            ) as avg_resolution_days
        FROM users u
        LEFT JOIN complaints c ON u.user_id = c.assigned_to
        WHERE u.role = 'admin' AND u.status = 'active'
        GROUP BY u.user_id, u.full_name, u.admin_level, u.email
        ORDER BY active_complaints ASC, u.full_name ASC
    ");
    
    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    
    return $admins;
}

// ============================================
// REOPEN REQUEST FUNCTIONS
// ============================================

/**
 * Create reopen request
 */
function createReopenRequest($complaint_id, $user_id, $reason) {
    global $conn;
    
    if (empty($reason)) {
        return ['success' => false, 'message' => 'Reason is required'];
    }
    
    // Check if there's already a pending request
    $stmt = $conn->prepare("
        SELECT reopen_id FROM reopen_requests 
        WHERE complaint_id = ? AND status = 'pending'
    ");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'A reopen request is already pending for this complaint'];
    }
    
    // Create reopen request
    $stmt = $conn->prepare("
        INSERT INTO reopen_requests (complaint_id, requested_by, reason) 
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iis", $complaint_id, $user_id, $reason);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Reopen request submitted successfully'];
    }
    
    return ['success' => false, 'message' => 'Failed to submit reopen request'];
}

/**
 * Get pending reopen requests for a complaint
 */
function getPendingReopenRequest($complaint_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT r.*, u.full_name as requester_name 
        FROM reopen_requests r
        JOIN users u ON r.requested_by = u.user_id
        WHERE r.complaint_id = ? AND r.status = 'pending'
        ORDER BY r.created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0 ? $result->fetch_assoc() : null;
}

/**
 * Get all reopen requests for a complaint
 */
function getAllReopenRequests($complaint_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT r.*, 
               u.full_name as requester_name,
               admin.full_name as reviewer_name
        FROM reopen_requests r
        JOIN users u ON r.requested_by = u.user_id
        LEFT JOIN users admin ON r.reviewed_by = admin.user_id
        WHERE r.complaint_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Approve reopen request
 */
function approveReopenRequest($reopen_id, $admin_id, $review_note = '') {
    global $conn;
    
    // Get reopen request details
    $stmt = $conn->prepare("
        SELECT r.complaint_id, r.requested_by, r.reason, c.assigned_to 
        FROM reopen_requests r
        JOIN complaints c ON r.complaint_id = c.complaint_id
        WHERE r.reopen_id = ? AND r.status = 'pending'
    ");
    $stmt->bind_param("i", $reopen_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'Reopen request not found or already processed'];
    }
    
    $request = $result->fetch_assoc();
    
    $conn->begin_transaction();
    
    try {
        // Update reopen request status
        $stmt = $conn->prepare("
            UPDATE reopen_requests 
            SET status = 'approved', 
                reviewed_by = ?, 
                reviewed_at = NOW(),
                review_note = ?
            WHERE reopen_id = ?
        ");
        $stmt->bind_param("isi", $admin_id, $review_note, $reopen_id);
        $stmt->execute();
        
        // Reopen the complaint
        $stmt = $conn->prepare("
            UPDATE complaints 
            SET status = 'In Progress',
                user_confirmed_resolved = 0,
                updated_date = NOW()
            WHERE complaint_id = ?
        ");
        $stmt->bind_param("i", $request['complaint_id']);
        $stmt->execute();
        
        // Log to history
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, old_status, new_status, comment) 
            VALUES (?, ?, 'Resolved', 'In Progress', ?)
        ");
        $comment = "Reopen request approved by admin. User's reason: " . $request['reason'];
        $stmt->bind_param("iis", $request['complaint_id'], $admin_id, $comment);
        $stmt->execute();
        
        $conn->commit();
        return ['success' => true, 'message' => 'Reopen request approved and complaint reopened'];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Failed to approve reopen request: ' . $e->getMessage()];
    }
}

/**
 * Reject reopen request
 */
function rejectReopenRequest($reopen_id, $admin_id, $review_note) {
    global $conn;
    
    if (empty($review_note)) {
        return ['success' => false, 'message' => 'Please provide a reason for rejection'];
    }
    
    $stmt = $conn->prepare("
        UPDATE reopen_requests 
        SET status = 'rejected', 
            reviewed_by = ?, 
            reviewed_at = NOW(),
            review_note = ?
        WHERE reopen_id = ? AND status = 'pending'
    ");
    $stmt->bind_param("isi", $admin_id, $review_note, $reopen_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Reopen request rejected'];
    }
    
    return ['success' => false, 'message' => 'Failed to reject request or request already processed'];
}

/**
 * Create admin account (auto-approved)
 */
function createAdminAccount($full_name, $email, $phone, $password, $admin_level, $created_by) {
    global $conn;
    
    // Validate inputs
    if (empty($full_name) || empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'All required fields must be filled'];
    }
    
    if (!isValidEmail($email)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }
    
    if (!empty($phone) && !isValidPhone($phone)) {
        return ['success' => false, 'message' => 'Invalid phone number format'];
    }
    
    if (!isStrongPassword($password)) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters with uppercase, lowercase, and numbers'];
    }
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Email already registered'];
    }
    
    // Hash password
    $hashed_password = hashPassword($password);
    
    // Insert admin with automatic approval
    $approval_status = 'approved'; // Auto-approve since created by Super Admin
    $stmt = $conn->prepare("
        INSERT INTO users 
        (full_name, email, phone, password, role, admin_level, status, approval_status) 
        VALUES (?, ?, ?, ?, 'admin', ?, 'active', ?)
    ");
    $stmt->bind_param("ssssss", $full_name, $email, $phone, $hashed_password, $admin_level, $approval_status);
    
    if ($stmt->execute()) {
        $new_admin_id = $conn->insert_id;
        
        // Log the creation in complaint_history or create an admin_actions table
        // (Optional: track who created which admin)
        
        return [
            'success' => true, 
            'message' => 'Admin account created successfully and automatically approved',
            'admin_id' => $new_admin_id
        ];
    } else {
        return ['success' => false, 'message' => 'Failed to create admin account'];
    }
}

// ============================================
// COMPLAINT LOCK FUNCTIONS
// ============================================

/**
 * Lock a complaint (Super Admin only)
 */
function lockComplaint($complaint_id, $admin_id, $reason) {
    global $conn;
    
    if (empty($reason)) {
        return ['success' => false, 'message' => 'Please provide a reason for locking this complaint.'];
    }
    
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET is_locked = 1,
            locked_by = ?,
            locked_at = NOW(),
            lock_reason = ?
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("isi", $admin_id, $reason, $complaint_id);
    
    if ($stmt->execute()) {
        // Log to history
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, comment) 
            VALUES (?, ?, ?)
        ");
        $comment = "Complaint locked by Super Admin. Reason: " . $reason;
        $stmt->bind_param("iis", $complaint_id, $admin_id, $comment);
        $stmt->execute();

        // Notify assigned admin about lock
$stmt_check = $conn->prepare("SELECT assigned_to, user_id FROM complaints WHERE complaint_id = ?");
$stmt_check->bind_param("i", $complaint_id);
$stmt_check->execute();
$complaint_data = $stmt_check->get_result()->fetch_assoc();

if (!empty($complaint_data['assigned_to'])) {
    createEnhancedNotification([
        'user_id' => $complaint_data['assigned_to'],
        'title' => "🔒 Complaint Locked",
        'message' => "Complaint #$complaint_id has been locked by Super Admin.\n\n📝 Reason: $reason\n\n⚠️ No modifications can be made until unlocked.",
        'type' => 'warning',
        'complaint_id' => $complaint_id,
        'reference_type' => 'lock',
        'action_url' => "complaint_details.php?id=$complaint_id",
        'metadata' => ['reason' => $reason]
    ]);
}
        
        return ['success' => true, 'message' => 'Complaint locked successfully.'];
    }
    
    return ['success' => false, 'message' => 'Failed to lock complaint.'];
}

/**
 * Unlock a complaint (Super Admin only)
 */
function unlockComplaint($complaint_id, $admin_id, $reason = '') {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET is_locked = 0,
            locked_by = NULL,
            locked_at = NULL,
            lock_reason = NULL
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("i", $complaint_id);
    
    if ($stmt->execute()) {
        // Log to history
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, comment) 
            VALUES (?, ?, ?)
        ");
        $comment = "Complaint unlocked by Super Admin." . (!empty($reason) ? " Reason: " . $reason : "");
        $stmt->bind_param("iis", $complaint_id, $admin_id, $comment);
        $stmt->execute();

        // Notify assigned admin about unlock
$stmt_check = $conn->prepare("SELECT assigned_to FROM complaints WHERE complaint_id = ?");
$stmt_check->bind_param("i", $complaint_id);
$stmt_check->execute();
$complaint_data = $stmt_check->get_result()->fetch_assoc();

if (!empty($complaint_data['assigned_to'])) {
    createEnhancedNotification([
        'user_id' => $complaint_data['assigned_to'],
        'title' => "🔓 Complaint Unlocked",
        'message' => "Complaint #$complaint_id has been unlocked by Super Admin. You can now modify it." . (!empty($reason) ? "\n\n📝 Note: $reason" : ""),
        'type' => 'success',
        'complaint_id' => $complaint_id,
        'reference_type' => 'unlock',
        'action_url' => "complaint_details.php?id=$complaint_id",
        'metadata' => ['reason' => $reason]
    ]);
}
        
        return ['success' => true, 'message' => 'Complaint unlocked successfully.'];
    }
    
    return ['success' => false, 'message' => 'Failed to unlock complaint.'];
}

/**
 * Check if complaint is locked
 */
function isComplaintLocked($complaint_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT is_locked FROM complaints WHERE complaint_id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result && $result['is_locked'] == 1;
}

// ============================================
// REASSIGNMENT TRACKING FUNCTIONS
// ============================================

/**
 * Record reassignment in history
 */
function recordReassignment($complaint_id, $old_admin_id, $new_admin_id, $reassigned_by, $reason) {
    global $conn;
    
    // Insert into reassignment_history table
    $stmt = $conn->prepare("
        INSERT INTO reassignment_history 
        (complaint_id, old_admin_id, new_admin_id, reassigned_by, reason) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiis", $complaint_id, $old_admin_id, $new_admin_id, $reassigned_by, $reason);
    $stmt->execute();
    
    // Update reassignment count and reason in complaints table
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET reassignment_count = reassignment_count + 1,
            reassignment_reason = ?
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("si", $reason, $complaint_id);
    $stmt->execute();
    
    return true;
}

/**
 * Get reassignment history for a complaint
 */
function getReassignmentHistory($complaint_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT rh.*,
               old_admin.full_name as old_admin_name,
               new_admin.full_name as new_admin_name,
               reassigner.full_name as reassigned_by_name
        FROM reassignment_history rh
        LEFT JOIN users old_admin ON rh.old_admin_id = old_admin.user_id
        JOIN users new_admin ON rh.new_admin_id = new_admin.user_id
        JOIN users reassigner ON rh.reassigned_by = reassigner.user_id
        WHERE rh.complaint_id = ?
        ORDER BY rh.reassigned_at DESC
    ");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    
    return $stmt->get_result();
}

/**
 * Get reassignment statistics for an admin
 */
function getAdminReassignmentStats($admin_id) {
    global $conn;
    
    // Times reassigned TO this admin
    $stmt = $conn->prepare("
        SELECT COUNT(*) as assigned_to_count 
        FROM reassignment_history 
        WHERE new_admin_id = ?
    ");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $assigned_to = $stmt->get_result()->fetch_assoc()['assigned_to_count'];
    
    // Times reassigned FROM this admin
    $stmt = $conn->prepare("
        SELECT COUNT(*) as assigned_from_count 
        FROM reassignment_history 
        WHERE old_admin_id = ?
    ");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $assigned_from = $stmt->get_result()->fetch_assoc()['assigned_from_count'];
    
    return [
        'assigned_to' => $assigned_to,
        'assigned_from' => $assigned_from,
        'net' => $assigned_to - $assigned_from
    ];
}

// ============================================
// USER SATISFACTION RATING FUNCTIONS
// ============================================

/**
 * Save user satisfaction rating
 */
function saveUserRating($complaint_id, $user_id, $rating, $feedback = '') {
    global $conn;
    
    // Validate rating (1-5)
    if ($rating < 1 || $rating > 5) {
        return ['success' => false, 'message' => 'Invalid rating. Please select 1-5 stars.'];
    }
    
    // Verify complaint belongs to user and is closed
    $stmt = $conn->prepare("
        SELECT status, user_id, assigned_to 
        FROM complaints 
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $complaint = $stmt->get_result()->fetch_assoc();
    
    if (!$complaint) {
        return ['success' => false, 'message' => 'Complaint not found.'];
    }
    
    if ($complaint['user_id'] != $user_id) {
        return ['success' => false, 'message' => 'You can only rate your own complaints.'];
    }
    
    if ($complaint['status'] !== 'Closed') {
        return ['success' => false, 'message' => 'You can only rate closed complaints.'];
    }
    
    // Save rating
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET user_rating = ?,
            user_feedback = ?,
            rated_at = NOW()
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("isi", $rating, $feedback, $complaint_id);
    
    if ($stmt->execute()) {
      // Notify assigned admin about the rating with enhanced notification
if (!empty($complaint['assigned_to'])) {
    $rating_emoji = ['1' => '😞', '2' => '😕', '3' => '🙂', '4' => '😊', '5' => '⭐'];
    $rating_text = ['1' => 'Poor', '2' => 'Fair', '3' => 'Good', '4' => 'Very Good', '5' => 'Excellent'];
    
    $title = "{$rating_emoji[$rating]} User Rated Complaint";
    $message = "User rated their resolution as {$rating_text[$rating]} ({$rating}/5 stars) for complaint #$complaint_id";
    
    if (!empty($feedback)) {
        $feedback_preview = strlen($feedback) > 100 ? substr($feedback, 0, 100) . '...' : $feedback;
        $message .= "\n\n💬 Feedback: $feedback_preview";
    }
    
    createEnhancedNotification([
        'user_id' => $complaint['assigned_to'],
        'title' => $title,
        'message' => $message,
        'type' => $rating >= 4 ? 'success' : ($rating >= 3 ? 'info' : 'warning'),
        'complaint_id' => $complaint_id,
        'reference_type' => 'rating',
        'action_url' => "complaint_details.php?id=$complaint_id",
        'metadata' => [
            'rating' => $rating,
            'rating_text' => $rating_text[$rating],
            'feedback' => $feedback
        ]
    ]);
}
        // Log to history
        $rating_text = ['1' => 'Poor', '2' => 'Fair', '3' => 'Good', '4' => 'Very Good', '5' => 'Excellent'];
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, comment) 
            VALUES (?, ?, ?)
        ");
        $comment = "User provided satisfaction rating: {$rating}/5 ({$rating_text[$rating]})";
        if (!empty($feedback)) {
            $comment .= " - Feedback: " . $feedback;
        }
        $stmt->bind_param("iis", $complaint_id, $user_id, $comment);
        $stmt->execute();
        
        return ['success' => true, 'message' => 'Thank you for your feedback!'];
    }
    
    return ['success' => false, 'message' => 'Failed to save rating.'];
}

/**
 * Get average rating for an admin
 */
function getAdminAverageRating($admin_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            AVG(user_rating) as avg_rating,
            COUNT(user_rating) as total_ratings,
            SUM(CASE WHEN user_rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN user_rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN user_rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN user_rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN user_rating = 1 THEN 1 ELSE 0 END) as one_star
        FROM complaints 
        WHERE assigned_to = ? 
          AND user_rating IS NOT NULL
    ");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get overall system rating statistics
 */
function getSystemRatingStats() {
    global $conn;
    
    $stmt = $conn->query("
        SELECT 
            AVG(user_rating) as avg_rating,
            COUNT(user_rating) as total_ratings,
            SUM(CASE WHEN user_rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN user_rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN user_rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN user_rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN user_rating = 1 THEN 1 ELSE 0 END) as one_star
        FROM complaints 
        WHERE user_rating IS NOT NULL
    ");
    
    return $stmt->fetch_assoc();
}

/**
 * Create Enhanced Notification with Context
 */
function createEnhancedNotification($params) {
    global $conn;
    
    // Required params
    $user_id = $params['user_id'];
    $title = $params['title'];
    $message = $params['message'];
    $type = $params['type'] ?? 'info';
    
    // Optional params
    $complaint_id = $params['complaint_id'] ?? null;
    $reference_type = $params['reference_type'] ?? null;
    $action_url = $params['action_url'] ?? null;
    $metadata = isset($params['metadata']) ? json_encode($params['metadata']) : null;
    
    $stmt = $conn->prepare("
        INSERT INTO notifications 
        (user_id, complaint_id, title, message, type, action_url, reference_type, metadata) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iissssss", 
        $user_id, 
        $complaint_id, 
        $title, 
        $message, 
        $type,
        $action_url,
        $reference_type,
        $metadata
    );
    
    return $stmt->execute();
}

/**
 * Helper: Create notification for assignment
 */
function notifyAssignment($user_id, $complaint_id, $admin_name, $is_reassignment = false, $old_admin_name = null, $reason = null) {
    $is_user = !isAdminUserId($user_id);
    
    if ($is_reassignment) {
        // Reassignment notification
        if ($is_user) {
            $title = "📋 Complaint Reassigned";
            $message = "Your complaint has been reassigned to a different admin for better assistance.";
        } else {
            $title = "🔄 Complaint Reassigned to You";
            $message = "Complaint #$complaint_id has been reassigned to you" . 
                      ($old_admin_name ? " from $old_admin_name" : "") . ".";
            if ($reason) {
                $message .= "\n\n📝 Reason: $reason";
            }
        }
    } else {
        // First assignment
        if ($is_user) {
            $title = "✅ Complaint Assigned";
            $message = "Your complaint has been assigned to an admin and will be reviewed shortly.";
        } else {
            $title = "📬 New Complaint Assigned";
            $message = "You have been assigned to handle complaint #$complaint_id.";
        }
    }
    
    createEnhancedNotification([
        'user_id' => $user_id,
        'title' => $title,
        'message' => $message,
        'type' => $is_reassignment ? 'warning' : 'success',
        'complaint_id' => $complaint_id,
        'reference_type' => 'assignment',
        'action_url' => "complaint_details.php?id=$complaint_id",
        'metadata' => [
            'admin_name' => $admin_name,
            'is_reassignment' => $is_reassignment,
            'old_admin_name' => $old_admin_name,
            'reason' => $reason
        ]
    ]);
}

/**
 * Helper: Create notification for status change
 */
function notifyStatusChange($user_id, $complaint_id, $old_status, $new_status, $admin_name, $admin_response = null) {
    $is_user = !isAdminUserId($user_id);
    
    // Status change emoji
    $emoji_map = [
        'Pending' => '⏳',
        'Assigned' => '📋',
        'In Progress' => '🔄',
        'On Hold' => '⏸️',
        'Resolved' => '✅',
        'Closed' => '🔒'
    ];
    
    $emoji = $emoji_map[$new_status] ?? '📌';
    
    if ($is_user) {
        $title = "$emoji Status Updated: $new_status";
        $message = "Your complaint status has been changed from '$old_status' to '$new_status'.";
        
        if ($admin_response) {
            $message .= "\n\n💬 Admin Response: " . (strlen($admin_response) > 100 ? substr($admin_response, 0, 100) . '...' : $admin_response);
        }
        
        if ($new_status === 'Resolved') {
            $message .= "\n\n👉 Please confirm if your issue is completely resolved.";
        }
    } else {
        $title = "$emoji Complaint Status Changed";
        $message = "Complaint #$complaint_id status changed: $old_status → $new_status";
    }
    
    // Type based on status
    $type = 'info';
    if ($new_status === 'Resolved' || $new_status === 'Closed') {
        $type = 'success';
    } elseif ($new_status === 'On Hold') {
        $type = 'warning';
    }
    
    createEnhancedNotification([
        'user_id' => $user_id,
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'complaint_id' => $complaint_id,
        'reference_type' => 'status_change',
        'action_url' => "complaint_details.php?id=$complaint_id",
        'metadata' => [
            'old_status' => $old_status,
            'new_status' => $new_status,
            'admin_name' => $admin_name,
            'admin_response' => $admin_response
        ]
    ]);
}

/**
 * Helper: Create notification for comment
 */
function notifyComment($user_id, $complaint_id, $commenter_name, $comment_text, $is_admin_comment) {
    $is_user = !isAdminUserId($user_id);
    
    if ($is_admin_comment && $is_user) {
        // Admin commented on user's complaint
        $title = "💬 Admin Replied";
        $message = "Admin responded to your complaint #$complaint_id";
    } elseif (!$is_admin_comment && !$is_user) {
        // User commented on their complaint
        $title = "💬 User Added Comment";
        $message = "$commenter_name added a comment to complaint #$complaint_id";
    } else {
        $title = "💬 New Comment";
        $message = "$commenter_name commented on complaint #$complaint_id";
    }
    
    $preview = strlen($comment_text) > 80 ? substr($comment_text, 0, 80) . '...' : $comment_text;
    $message .= "\n\n\"$preview\"";
    
    createEnhancedNotification([
        'user_id' => $user_id,
        'title' => $title,
        'message' => $message,
        'type' => 'info',
        'complaint_id' => $complaint_id,
        'reference_type' => 'comment',
        'action_url' => "complaint_details.php?id=$complaint_id#comments",
        'metadata' => [
            'commenter_name' => $commenter_name,
            'comment_preview' => $preview,
            'is_admin_comment' => $is_admin_comment
        ]
    ]);
}

/**
 * Helper: Create notification for resolution confirmation
 */
function notifyResolutionConfirmed($admin_id, $complaint_id, $user_name, $rating = null) {
    $title = "⭐ User Confirmed Resolution";
    $message = "$user_name confirmed that complaint #$complaint_id is resolved.";
    
    if ($rating) {
        $stars = str_repeat('⭐', $rating);
        $rating_text = ['1' => 'Poor', '2' => 'Fair', '3' => 'Good', '4' => 'Very Good', '5' => 'Excellent'][$rating];
        $message .= "\n\n$stars Rating: $rating_text ($rating/5)";
    }
    
    createEnhancedNotification([
        'user_id' => $admin_id,
        'title' => $title,
        'message' => $message,
        'type' => $rating >= 4 ? 'success' : 'info',
        'complaint_id' => $complaint_id,
        'reference_type' => 'resolution_confirmed',
        'action_url' => "complaint_details.php?id=$complaint_id",
        'metadata' => [
            'user_name' => $user_name,
            'rating' => $rating
        ]
    ]);
}

/**
 * Helper: Create notification for reopen request
 */
function notifyReopenRequest($admin_id, $complaint_id, $user_name, $reason) {
    $title = "🔄 Reopen Request";
    $message = "$user_name has requested to reopen complaint #$complaint_id.";
    
    $reason_preview = strlen($reason) > 100 ? substr($reason, 0, 100) . '...' : $reason;
    $message .= "\n\n📝 Reason: $reason_preview";
    
    createEnhancedNotification([
        'user_id' => $admin_id,
        'title' => $title,
        'message' => $message,
        'type' => 'warning',
        'complaint_id' => $complaint_id,
        'reference_type' => 'reopen_request',
        'action_url' => "complaint_details.php?id=$complaint_id",
        'metadata' => [
            'user_name' => $user_name,
            'reason' => $reason
        ]
    ]);
}

/**
 * Helper: Check if user ID is an admin
 */
function isAdminUserId($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result && $result['role'] === 'admin';
}

// ============================================
// COMPLAINT APPROVAL SYSTEM
// ============================================

/**
 * Approve a complaint (Super Admin only)
 */
function approveComplaint($complaint_id, $admin_id) {
    global $conn;
    
    // Verify Super Admin
    if (!isSuperAdmin()) {
        return ['success' => false, 'message' => 'Only Super Admin can approve complaints.'];
    }
    
    // Get complaint details
    $stmt = $conn->prepare("SELECT user_id, subject, approval_status FROM complaints WHERE complaint_id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $complaint = $stmt->get_result()->fetch_assoc();
    
    if (!$complaint) {
        return ['success' => false, 'message' => 'Complaint not found.'];
    }
    
    if ($complaint['approval_status'] === 'approved') {
        return ['success' => false, 'message' => 'This complaint is already approved.'];
    }
    
    // Update complaint status
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET approval_status = 'approved',
            reviewed_by = ?,
            reviewed_at = NOW(),
            rejection_reason = NULL,
            status = 'Pending'
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("ii", $admin_id, $complaint_id);
    
    if ($stmt->execute()) {
        // Log approval in history
        $stmt = $conn->prepare("
            INSERT INTO complaint_approvals 
            (complaint_id, reviewed_by, action) 
            VALUES (?, ?, 'approved')
        ");
        $stmt->bind_param("ii", $complaint_id, $admin_id);
        $stmt->execute();
        
        // Log to complaint history
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, comment) 
            VALUES (?, ?, 'Complaint approved by Super Admin')
        ");
        $stmt->bind_param("ii", $complaint_id, $admin_id);
        $stmt->execute();
        
        // Notify user
        createEnhancedNotification([
            'user_id' => $complaint['user_id'],
            'title' => "✅ Complaint Approved",
            'message' => "Your complaint has been approved and is now being processed.\n\nSubject: " . $complaint['subject'],
            'type' => 'success',
            'complaint_id' => $complaint_id,
            'reference_type' => 'approval',
            'action_url' => "complaint_details.php?id=$complaint_id",
            'metadata' => ['action' => 'approved']
        ]);
        
        return ['success' => true, 'message' => 'Complaint approved successfully.'];
    }
    
    return ['success' => false, 'message' => 'Failed to approve complaint.'];
}

/**
 * Reject a complaint with reason
 */
function rejectComplaint($complaint_id, $admin_id, $reason) {
    global $conn;
    
    if (!isSuperAdmin()) {
        return ['success' => false, 'message' => 'Only Super Admin can reject complaints.'];
    }
    
    if (empty($reason)) {
        return ['success' => false, 'message' => 'Please provide a reason for rejection.'];
    }
    
    // Get complaint details
    $stmt = $conn->prepare("SELECT user_id, subject FROM complaints WHERE complaint_id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $complaint = $stmt->get_result()->fetch_assoc();
    
    if (!$complaint) {
        return ['success' => false, 'message' => 'Complaint not found.'];
    }
    
    // Update complaint
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET approval_status = 'rejected',
            reviewed_by = ?,
            reviewed_at = NOW(),
            rejection_reason = ?,
            status = 'Closed'
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("isi", $admin_id, $reason, $complaint_id);
    
    if ($stmt->execute()) {
        // Log rejection
        $stmt = $conn->prepare("
            INSERT INTO complaint_approvals 
            (complaint_id, reviewed_by, action, reason) 
            VALUES (?, ?, 'rejected', ?)
        ");
        $stmt->bind_param("iis", $complaint_id, $admin_id, $reason);
        $stmt->execute();
        
        // Log to history
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, old_status, new_status, comment) 
            VALUES (?, ?, 'Pending', 'Closed', ?)
        ");
        $comment = "Complaint rejected by Super Admin. Reason: $reason";
        $stmt->bind_param("iis", $complaint_id, $admin_id, $comment);
        $stmt->execute();
        
        // Notify user
        createEnhancedNotification([
            'user_id' => $complaint['user_id'],
            'title' => "❌ Complaint Rejected",
            'message' => "Your complaint was reviewed and rejected.\n\n📝 Reason: $reason\n\n💡 You can edit and resubmit if needed.",
            'type' => 'danger',
            'complaint_id' => $complaint_id,
            'reference_type' => 'rejection',
            'action_url' => "complaint_details.php?id=$complaint_id",
            'metadata' => ['action' => 'rejected', 'reason' => $reason]
        ]);
        
        return ['success' => true, 'message' => 'Complaint rejected.'];
    }
    
    return ['success' => false, 'message' => 'Failed to reject complaint.'];
}

/**
 * Request changes to a complaint
 */
function requestComplaintChanges($complaint_id, $admin_id, $changes_needed) {
    global $conn;
    
    if (!isSuperAdmin()) {
        return ['success' => false, 'message' => 'Only Super Admin can request changes.'];
    }
    
    if (empty($changes_needed)) {
        return ['success' => false, 'message' => 'Please specify what changes are needed.'];
    }
    
    // Get complaint details
    $stmt = $conn->prepare("SELECT user_id, subject FROM complaints WHERE complaint_id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $complaint = $stmt->get_result()->fetch_assoc();
    
    if (!$complaint) {
        return ['success' => false, 'message' => 'Complaint not found.'];
    }
    
    // Update complaint
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET approval_status = 'changes_requested',
            reviewed_by = ?,
            reviewed_at = NOW(),
            rejection_reason = ?
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("isi", $admin_id, $changes_needed, $complaint_id);
    
    if ($stmt->execute()) {
        // Log action
        $stmt = $conn->prepare("
            INSERT INTO complaint_approvals 
            (complaint_id, reviewed_by, action, reason) 
            VALUES (?, ?, 'changes_requested', ?)
        ");
        $stmt->bind_param("iis", $complaint_id, $admin_id, $changes_needed);
        $stmt->execute();
        
        // Log to history
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, comment) 
            VALUES (?, ?, ?)
        ");
        $comment = "Changes requested by Super Admin: $changes_needed";
        $stmt->bind_param("iis", $complaint_id, $admin_id, $comment);
        $stmt->execute();
        
        // Notify user
        createEnhancedNotification([
            'user_id' => $complaint['user_id'],
            'title' => "📝 Changes Requested",
            'message' => "Please update your complaint with the following changes:\n\n$changes_needed\n\n👉 Edit your complaint and resubmit for review.",
            'type' => 'warning',
            'complaint_id' => $complaint_id,
            'reference_type' => 'changes_requested',
            'action_url' => "complaint_details.php?id=$complaint_id",
            'metadata' => ['action' => 'changes_requested', 'changes' => $changes_needed]
        ]);
        
        return ['success' => true, 'message' => 'Changes requested successfully.'];
    }
    
    return ['success' => false, 'message' => 'Failed to request changes.'];
}

/**
 * Resubmit complaint after changes
 */
function resubmitComplaint($complaint_id, $user_id) {
    global $conn;
    
    // Get complaint details
    $stmt = $conn->prepare("
        SELECT approval_status, resubmission_count 
        FROM complaints 
        WHERE complaint_id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $complaint_id, $user_id);
    $stmt->execute();
    $complaint = $stmt->get_result()->fetch_assoc();
    
    if (!$complaint) {
        return ['success' => false, 'message' => 'Complaint not found.'];
    }
    
    if ($complaint['approval_status'] !== 'changes_requested' && $complaint['approval_status'] !== 'rejected') {
        return ['success' => false, 'message' => 'This complaint cannot be resubmitted.'];
    }
    
    // Update to pending review
    $stmt = $conn->prepare("
        UPDATE complaints 
        SET approval_status = 'pending_review',
            reviewed_by = NULL,
            reviewed_at = NULL,
            rejection_reason = NULL,
            resubmission_count = resubmission_count + 1,
            status = 'Pending',
            submitted_date = NOW()
        WHERE complaint_id = ?
    ");
    $stmt->bind_param("i", $complaint_id);
    
    if ($stmt->execute()) {
        // Log to history
        $stmt = $conn->prepare("
            INSERT INTO complaint_history 
            (complaint_id, changed_by, comment) 
            VALUES (?, ?, ?)
        ");
        $resubmission_num = $complaint['resubmission_count'] + 1;
        $comment = "Complaint resubmitted for review (Resubmission #$resubmission_num)";
        $stmt->bind_param("iis", $complaint_id, $user_id, $comment);
        $stmt->execute();
        
        // Notify Super Admins
        $super_admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND admin_level = 'super_admin' AND status = 'active'");
        while ($admin = $super_admins->fetch_assoc()) {
            createEnhancedNotification([
                'user_id' => $admin['user_id'],
                'title' => "🔄 Complaint Resubmitted",
                'message' => "User has resubmitted complaint #$complaint_id after making requested changes.\n\nResubmission #$resubmission_num - Please review.",
                'type' => 'info',
                'complaint_id' => $complaint_id,
                'reference_type' => 'resubmission',
                'action_url' => "review_complaints.php?id=$complaint_id"
            ]);
        }
        
        return ['success' => true, 'message' => 'Complaint resubmitted for review.'];
    }
    
    return ['success' => false, 'message' => 'Failed to resubmit complaint.'];
}

/**
 * Get approval statistics
 */
function getApprovalStats($admin_id = null, $date_from = null, $date_to = null) {
    global $conn;
    
    $where = [];
    $params = [];
    $types = "";
    
    if ($admin_id) {
        $where[] = "reviewed_by = ?";
        $params[] = $admin_id;
        $types .= "i";
    }
    
    if ($date_from) {
        $where[] = "reviewed_at >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    
    if ($date_to) {
        $where[] = "reviewed_at <= ?";
        $params[] = $date_to;
        $types .= "s";
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $query = "
        SELECT 
            action,
            COUNT(*) as count
        FROM complaint_approvals
        $where_clause
        GROUP BY action
    ";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stats = [
        'approved' => 0,
        'rejected' => 0,
        'changes_requested' => 0,
        'total' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $stats[$row['action']] = (int)$row['count'];
        $stats['total'] += (int)$row['count'];
    }
    
    return $stats;
}