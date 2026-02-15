<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isLoggedIn()) {
    header("Location: ../user/index.php");
    exit();
}

$error = '';
$success = '';
$step = 1; // 1: Email, 2: OTP, 3: New Password
$otp_verified = false; // Track if OTP is verified

// Handle email submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = sanitizeInput($_POST['email']);
    
    $result = createPasswordResetRequest($email);
    
    if ($result['success']) {
        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_token'] = $result['token'];
        $_SESSION['reset_started_at'] = time(); // Track when reset started
        $success = $result['message'];
        $step = 2;
    } else {
        $error = $result['message'];
    }
    }
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
   // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request. Please try again.';
        $step = 2;
    } else {
        $email = $_SESSION['reset_email'] ?? '';
        $otp = sanitizeInput($_POST['otp']);
    
    $result = verifyOTP($email, $otp);
    
    if ($result['success']) {
        $_SESSION['reset_token'] = $result['token'];
        $_SESSION['otp_verified'] = true; // Mark as verified
        $step = 3;
        $otp_verified = true;
    } else {
        $error = $result['message'];
        $step = 2;
        $otp_verified = false;
        // Clear any existing token
        unset($_SESSION['reset_token']);
        unset($_SESSION['otp_verified']);
    }
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
      // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request. Please try again.';
        $step = 1;
    }   

// Security check: Ensure OTP was verified
    else if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        $error = 'Invalid session. Please start the password reset process again.';
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_token']);
        unset($_SESSION['otp_verified']);
        $step = 1;
    } else {
    $token = $_SESSION['reset_token'] ?? '';
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    

    if ($new_password !== $confirm_password) {
        $error = 'Passwords do not match';
        $step = 3;
    } else {
        $result = resetPasswordWithToken($token, $new_password);
        
          if ($result['success']) {
            $success = $result['message'] . ' You can now login with your new password.';
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_token']);
            unset($_SESSION['otp_verified']);
            $step = 4; // Success
        } else {
            $error = $result['message'];
            $step = 3;
        }
    }
    }
}


// Check if already in process
if (isset($_SESSION['reset_email']) && !isset($_POST['send_otp'])) {
    $step = 2;
}

// Only allow step 3 if OTP was verified
if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true && isset($_SESSION['reset_token'])) {
    $step = 3;
    $otp_verified = true;
}

// If trying to access step 3 without verification, redirect to step 1
if ($step === 3 && (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true)) {
    $error = 'Invalid session. Please start over.';
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_token']);
    unset($_SESSION['otp_verified']);
    $step = 1;
}

// Check if reset session has expired (30 minutes)
if (isset($_SESSION['reset_started_at'])) {
    $elapsed = time() - $_SESSION['reset_started_at'];
    if ($elapsed > 1800) { // 30 minutes
        $error = 'Password reset session expired. Please start over.';
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_token']);
        unset($_SESSION['otp_verified']);
        unset($_SESSION['reset_started_at']);
        $step = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #4a4d52 0%, #6b6e73 100%);
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .forgot-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
            max-width: 450px;
            width: 100%;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px;
            position: relative;
        }
        .step.active {
            color: #667eea;
            font-weight: bold;
        }
        .step.completed {
            color: #28a745;
        }
        .step::after {
            content: '';
            position: absolute;
            top: 50%;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #e0e0e0;
            z-index: -1;
        }
        .step:last-child::after {
            display: none;
        }
        .step.completed::after {
            background: #28a745;
        }
        .otp-input {
            font-size: 24px;
            letter-spacing: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="forgot-card">
        <div class="text-center mb-4">
            <i class="bi bi-key-fill" style="font-size: 60px; color: #667eea;"></i>
            <h2 class="mt-3">Forgot Password</h2>
            <p class="text-muted">Reset your password securely</p>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                <i class="bi bi-envelope"></i><br>
                <small>Email</small>
            </div>
            <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                <i class="bi bi-shield-lock"></i><br>
                <small>OTP</small>
            </div>
            <div class="step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">
                <i class="bi bi-lock"></i><br>
                <small>New Password</small>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- Step 1: Enter Email -->
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" name="email" required placeholder="Enter your registered email">
                </div>
                <button type="submit" name="send_otp" class="btn btn-primary w-100">
                    <i class="bi bi-send"></i> Send OTP
                </button>
            </form>

        <?php elseif ($step === 2): ?>
            <!-- Step 2: Enter OTP -->
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> We've sent a 6-digit OTP to <strong><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong>
            </div>
            <form method="POST">
             <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="mb-3">
                    <label class="form-label">Enter OTP</label>
                    <input type="text" class="form-control otp-input" name="otp" required maxlength="6" placeholder="000000">
                    <small class="text-muted">Valid for 15 minutes</small>
                </div>
                <button type="submit" name="verify_otp" class="btn btn-primary w-100">
                    <i class="bi bi-check-circle"></i> Verify OTP
                </button>
            </form>
            <div class="text-center mt-3">
                <a href="forgot_password.php" class="text-decoration-none" onclick="return confirm('Start over?');">
                    <i class="bi bi-arrow-counterclockwise"></i> Didn't receive code? Try again
                </a>
            </div>

        <?php elseif ($step === 3): ?>
            <!-- Step 3: Enter New Password -->
             <?php if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true): ?>
        <!-- Security check failed -->
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> Invalid session. Please start the password reset process again.
        </div>
        <div class="text-center">
            <a href="forgot_password.php" class="btn btn-primary">
                <i class="bi bi-arrow-counterclockwise"></i> Start Over
            </a>
        </div>
    <?php else: ?>
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" name="new_password" required>
                    <small class="text-muted">Min 8 chars, uppercase, lowercase, numbers</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" name="confirm_password" required>
                </div>
                <button type="submit" name="reset_password" class="btn btn-success w-100">
                    <i class="bi bi-shield-check"></i> Reset Password
                </button>
            </form>
        <?php endif; ?>

        <?php else: ?>
            <!-- Step 4: Success -->
            <div class="text-center">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 80px;"></i>
                <h4 class="mt-3">Password Reset Successful!</h4>
                <p class="text-muted">You can now login with your new password</p>
                <a href="login.php" class="btn btn-primary w-100 mt-3">
                    <i class="bi bi-box-arrow-in-right"></i> Go to Login
                </a>
            </div>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="login.php" class="text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>