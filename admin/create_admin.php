<?php
// ============================================
// CREATE ADMIN ACCOUNT PAGE
// admin/create_admin.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

// Only Super Admins can access this page
requireSuperAdmin();

$page_title = "Create Admin Account";

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitizeInput($_POST['full_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $admin_level = sanitizeInput($_POST['admin_level']);
    
    // Validate passwords match
    if ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        // Use the new function
        $result = createAdminAccount($full_name, $email, $phone, $password, $admin_level, $_SESSION['user_id']);
        
        if ($result['success']) {
            $success = $result['message'];
            
            // Clear form
            $full_name = $email = $phone = '';
        } else {
            $error = $result['message'];
        }
    }
}
// Get all admins for display
$admins = $conn->query("SELECT user_id, full_name, email, phone, admin_level, status, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC");

include '../includes/header.php';
include '../includes/navbar.php';
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Create Admin Form -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-person-plus-fill"></i> Create New Admin Account
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> <strong>Note:</strong> Only Super Admins can create admin accounts.
                </div>

                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="full_name" name="full_name" 
                               placeholder="Enter full name" required
                               value="<?php echo isset($full_name) ? htmlspecialchars($full_name) : ''; ?>">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="admin@example.com" required
                               value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                        <small class="text-muted">This will be used as the login username</small>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               placeholder="09123456789"
                               value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>">
                    </div>

                    <div class="mb-3">
                        <label for="admin_level" class="form-label">Admin Level <span class="text-danger">*</span></label>
                        <select class="form-select" id="admin_level" name="admin_level" required>
                            <option value="">-- Select Admin Level --</option>
                            <option value="super_admin">Super Administrator (Full Access)</option>
                            <option value="admin">Regular Admin (Limited Access)</option>
                        </select>
                        <small class="text-muted">
                            <strong>Super Admin:</strong> Can create admins, manage all settings<br>
                            <strong>Regular Admin:</strong> Can manage complaints and users only
                        </small>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Enter password" required>
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Min 8 characters, uppercase, lowercase, and numbers</small>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               placeholder="Confirm password" required>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Create Admin Account
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary ms-auto">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Existing Admins List -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-people-fill"></i> Existing Admin Accounts
            </div>
            <div class="card-body">
                <?php if ($admins->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Level</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($admin = $admins->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($admin['full_name']); ?></strong>
                                        <?php if ($admin['user_id'] == $_SESSION['user_id']): ?>
                                            <span class="badge bg-primary">You</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars($admin['email']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($admin['admin_level'] == 'super_admin'): ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-shield-fill-check"></i> Super
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">
                                                <i class="bi bi-shield-check"></i> Admin
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $admin['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($admin['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No admin accounts found.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Admin Level Explanation -->
        <div class="card mt-3">
            <div class="card-header bg-light">
                <i class="bi bi-info-circle"></i> Admin Levels Explained
            </div>
            <div class="card-body">
                <h6><span class="badge bg-danger">Super Administrator</span></h6>
                <ul class="mb-3">
                    <li>Create new admin accounts</li>
                    <li>Manage all admins</li>
                    <li>Access all system features</li>
                    <li>Manage categories</li>
                    <li>View activity logs</li>
                </ul>

                <h6><span class="badge bg-warning">Regular Admin</span></h6>
                <ul class="mb-0">
                    <li>Manage complaints</li>
                    <li>Manage users</li>
                    <li>View reports</li>
                    <li>Cannot create admins</li>
                    <li>Cannot access advanced settings</li>
                </ul>
            </div>
        </div>
    </div>
</div>

</div> <!-- End page-content -->
</div> <!-- End main-content -->

<script>
    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const password = document.getElementById('password');
        const icon = this.querySelector('i');
        
        if (password.type === 'password') {
            password.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            password.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    });

    // Password match validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match!');
        }
    });
</script>

<?php include '../includes/footer.php'; ?>