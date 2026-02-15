<?php
// ============================================
// NAVIGATION BAR (SIDEBAR + TOP NAVBAR)
// includes/navbar.php
// ============================================

// Determine user role and current page
$current_page = basename($_SERVER['PHP_SELF']);
$is_admin = isAdmin();
$is_user = isUser();
$pending_review = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'pending_review'")->fetch_assoc()['count']; ?>

<!-- Sidebar Navigation -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <!-- Barangay Logo -->
        <img src="<?php echo SITE_URL; ?>assets/images/barangay-logo.jpg" 
            alt="Barangay Logo" 
            class="sidebar-logo"
            style="width: 80px; height: 80px; object-fit: cover; border-radius: 50%; margin-bottom: 10px; border: 3px solid rgba(255,255,255,0.3);">
        
        <!-- System Name -->
        <div><?php echo $is_admin ? '' : ''; ?></div>
        
          <!-- Subtitle -->
        <small class="d-block mt-1" style="font-size: 0.75rem; opacity: 0.8;">
            <?php echo $is_admin ? '' : ''; ?>
        </small>
    </div>
    
    <ul class="sidebar-menu">
        <?php if ($is_admin): ?>
            <!-- ========== ADMIN MENU ========== -->
            <li>
                <a href="<?php echo SITE_URL; ?>admin/index.php" 
                   class="<?php echo $current_page == 'index.php' && $current_dir == 'admin' ? 'active' : ''; ?>">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>admin/manage_complaints.php" 
                   class="<?php echo $current_page == 'manage_complaints.php' ? 'active' : ''; ?>">
                    <i class="bi bi-folder"></i> Manage Complaints
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>admin/complaint_details.php" 
                   class="<?php echo $current_page == 'complaint_details.php' && $current_dir == 'admin' ? 'active' : ''; ?>">
                    <i class="bi bi-file-text"></i> Complaint Details
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>admin/manage_users.php" 
                   class="<?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i> Manage Users
                </a>
            </li>
            
            <?php if (isSuperAdmin()): ?>
            <!-- Super Admin Only Menu Items -->
            <li>
                <a href="<?php echo SITE_URL; ?>admin/create_admin.php" 
                   class="<?php echo $current_page == 'create_admin.php' ? 'active' : ''; ?>">
                    <i class="bi bi-person-plus-fill"></i> Create Admin
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>admin/manage_categories.php" 
                   class="<?php echo $current_page == 'manage_categories.php' ? 'active' : ''; ?>">
                    <i class="bi bi-tags-fill"></i> Manage Categories
                </a>
            </li>

            <li>
    <a href="<?php echo SITE_URL; ?>admin/pending_users.php" 
       class="<?php echo $current_page == 'pending_users.php' ? 'active' : ''; ?>">
        <i class="bi bi-hourglass-split"></i> Pending Approvals
        <?php 
        $pending_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE approval_status = 'pending' AND role = 'user'")->fetch_assoc()['count'];
        if ($pending_count > 0): 
        ?>
            <span class="badge bg-warning text-dark ms-2"><?php echo $pending_count; ?></span>
        <?php endif; ?>
    </a>
</li>
    <li class="nav-item">
        <a class="nav-link" href="review_complaints.php">
            <i class="bi bi-shield-check"></i> Review
            <?php if ($pending_review > 0): ?>
                <span class="badge bg-warning text-dark"><?php echo $pending_review; ?></span>
            <?php endif; ?>
        </a>
    </li>

            <?php endif; ?>
            
            <li>
                <a href="<?php echo SITE_URL; ?>admin/reports.php" 
                   class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                    <i class="bi bi-graph-up"></i> Reports
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>admin/profile.php" 
                   class="<?php echo $current_page == 'profile.php' && $current_dir == 'admin' ? 'active' : ''; ?>">
                    <i class="bi bi-person"></i> My Profile
                </a>
            </li>
            
        <?php else: ?>
            <!-- ========== USER MENU ========== -->
            <li>
                <a href="<?php echo SITE_URL; ?>user/index.php" 
                   class="<?php echo $current_page == 'index.php' && $current_dir == 'user' ? 'active' : ''; ?>">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>user/submit_complaint.php" 
                   class="<?php echo $current_page == 'submit_complaint.php' ? 'active' : ''; ?>">
                    <i class="bi bi-plus-circle"></i> Submit Complaint
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>user/my_complaints.php" 
                   class="<?php echo $current_page == 'my_complaints.php' ? 'active' : ''; ?>">
                    <i class="bi bi-list-ul"></i> My Complaints
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>user/complaint_details.php" 
                   class="<?php echo $current_page == 'complaint_details.php' && $current_dir == 'user' ? 'active' : ''; ?>">
                    <i class="bi bi-eye"></i> View Details
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>user/profile.php" 
                   class="<?php echo $current_page == 'profile.php' && $current_dir == 'user' ? 'active' : ''; ?>">
                    <i class="bi bi-person"></i> My Profile
                </a>
            </li>
        <?php endif; ?>
        
        <!-- ========== COMMON MENU ITEMS ========== -->
        <li class="mt-3" style="border-top: 1px solid rgba(255,255,255,0.2); padding-top: 10px;">
            <a href="<?php echo SITE_URL; ?>auth/logout.php" onclick="return confirm('Are you sure you want to logout?');">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </li>
    </ul>

      <!-- Online Users Widget -->
    <div class="online-users-widget" style="margin: 15px; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 10px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0" style="color: white; font-size: 0.85rem;">
                <i class="bi bi-circle-fill text-success" style="font-size: 0.6rem;"></i> 
                <?php echo $is_admin ? 'Online Users' : 'Admins Available'; ?>
            </h6>
            <span class="badge bg-success" id="onlineUserCount">0</span>
        </div>
        <div id="onlineUserList" style="max-height: 200px; overflow-y: auto;">
            <div class="text-center py-3">
                <div class="spinner-border spinner-border-sm text-light" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-white-50 mb-0 mt-2" style="font-size: 0.75rem;">Loading...</p>
            </div>
        </div>
    </div>
    
    <!-- User Info in Sidebar (Mobile) -->
    <div class="sidebar-user-info d-md-none">
        <div class="d-flex align-items-center p-3" style="background: rgba(255,255,255,0.1); border-radius: 8px; margin: 15px;">
              <?php if ($current_user['profile_picture'] && file_exists('../' . $current_user['profile_picture'])): ?>
            <img src="<?php echo SITE_URL . $current_user['profile_picture']; ?>" 
                 class="rounded-circle me-2" 
                 width="42" 
                 height="42" 
                 style="object-fit: cover;"
                 alt="Profile">
        <?php else: ?>
            <div class="user-avatar me-2">
                <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
            </div>
        <?php endif; ?>
            <div style="color: white; font-size: 0.85rem;">
                <div style="font-weight: 600;"><?php echo $_SESSION['full_name']; ?></div>
                <small style="opacity: 0.8;"><?php echo $_SESSION['email']; ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Toggle Button -->
<button class="mobile-toggle" id="mobileToggle" aria-label="Toggle Sidebar">
    <i class="bi bi-list"></i>
</button>

<!-- Main Content Wrapper -->
<div class="main-content">
    <!-- Top Navbar -->
    <div class="top-navbar">
        <div class="navbar-left">
            <button class="btn btn-link d-md-none p-0 me-3" id="mobileSidebarToggle">
                <i class="bi bi-list" style="font-size: 1.5rem; color: #667eea;"></i>
            </button>
            <div>
                <h5 class="mb-0"><?php echo isset($page_title) ? $page_title : 'Dashboard'; ?></h5>
                <small class="text-muted">
                    <i class="bi bi-calendar3"></i> <?php echo date('l, F j, Y'); ?>
                </small>
            </div>
        </div>
        
        <div class="user-info d-none d-md-flex">
            <!-- Dark Mode Toggle -->
            <button class="dark-mode-toggle" id="darkModeToggle" aria-label="Toggle Dark Mode">
                <i class="bi bi-moon-stars-fill" id="darkModeIcon"></i>
            </button>
            
            <!-- Notification Bell -->
            <?php 
            $unread_count = getUnreadNotificationCount($_SESSION['user_id']);
            $recent_notifications = getRecentNotifications($_SESSION['user_id'], 5);
            ?>
            <div class="dropdown me-3">
                <button class="btn btn-link position-relative p-0" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell-fill" style="font-size: 1.5rem; color: #667eea;"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $unread_count > 9 ? '9+' : $unread_count; ?>
                        </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown" style="width: 350px; max-height: 400px; overflow-y: auto;">
                    <li class="dropdown-header d-flex justify-content-between align-items-center">
                        <span><strong>Notifications</strong></span>
                        <?php if ($unread_count > 0): ?>
                            <a href="<?php echo SITE_URL . ($is_admin ? 'admin' : 'user'); ?>/notifications.php?mark_all_read=1" class="badge bg-primary text-decoration-none">
                                Mark all read
                            </a>
                        <?php endif; ?>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    
                    <?php if ($recent_notifications->num_rows > 0): ?>
                        <?php while ($notif = $recent_notifications->fetch_assoc()): ?>
                            <li>
                                <a class="dropdown-item <?php echo $notif['is_read'] == 0 ? 'bg-light' : ''; ?>" 
                                   href="<?php echo SITE_URL . ($is_admin ? 'admin' : 'user'); ?>/notifications.php?read=<?php echo $notif['notification_id']; ?>">
                                    <div class="d-flex align-items-start">
                                        <div class="me-2">
                                            <?php
                                            $icon_class = 'bi-info-circle text-info';
                                            if ($notif['type'] == 'success') $icon_class = 'bi-check-circle text-success';
                                            if ($notif['type'] == 'warning') $icon_class = 'bi-exclamation-triangle text-warning';
                                            if ($notif['type'] == 'danger') $icon_class = 'bi-x-circle text-danger';
                                            ?>
                                            <i class="bi <?php echo $icon_class; ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <strong><?php echo htmlspecialchars($notif['title']); ?></strong>
                                            <p class="mb-1 small text-muted">
                                                <?php echo htmlspecialchars(substr($notif['message'], 0, 80)) . '...'; ?>
                                            </p>
                                            <small class="text-muted">
                                                <i class="bi bi-clock"></i> <?php echo formatDateTime($notif['created_at']); ?>
                                            </small>
                                        </div>
                                        <?php if ($notif['is_read'] == 0): ?>
                                            <div class="ms-2">
                                                <span class="badge bg-primary rounded-pill">New</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endwhile; ?>
                        <li class="text-center">
                            <a href="<?php echo SITE_URL . ($is_admin ? 'admin' : 'user'); ?>/notifications.php" class="dropdown-item text-primary">
                                <strong>View All Notifications</strong>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="text-center py-3">
                            <i class="bi bi-bell-slash" style="font-size: 2rem; color: #ddd;"></i>
                            <p class="text-muted mb-0">No notifications</p>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            
           <?php
// Get current user's profile picture
$current_user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
?>

<?php if ($current_user['profile_picture'] && file_exists('../' . $current_user['profile_picture'])): ?>
    <img src="<?php echo SITE_URL . $current_user['profile_picture']; ?>" 
         class="rounded-circle" 
         width="42" 
         height="42" 
         style="object-fit: cover;"
         alt="Profile">
<?php else: ?>
    <div class="user-avatar">
        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
    </div>
<?php endif; ?>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <small class="text-muted user-email"><?php echo htmlspecialchars($_SESSION['email']); ?></small>
            </div>
            <div class="dropdown">
                <button class="btn btn-link p-0 ms-2" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-three-dots-vertical" style="font-size: 1.2rem; color: #667eea;"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li>
                        <a class="dropdown-item" href="<?php echo SITE_URL . ($is_admin ? 'admin' : 'user'); ?>/profile.php">
                            <i class="bi bi-person"></i> My Profile
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger" href="<?php echo SITE_URL; ?>auth/logout.php" 
                           onclick="return confirm('Are you sure you want to logout?');">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Page Content Container -->
    <div class="page-content">