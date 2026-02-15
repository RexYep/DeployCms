<?php
// ============================================
// ADMIN NOTIFICATIONS PAGE
// admin/notifications.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

requireAdmin();

$page_title = "Notifications";

$user_id = $_SESSION['user_id'];
$success = '';

// Handle mark as read
if (isset($_GET['read'])) {
    $notification_id = (int)$_GET['read'];
    markNotificationAsRead($notification_id);
    header("Location: notifications.php");
    exit();
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    markAllNotificationsAsRead($user_id);
    $success = 'All notifications marked as read';
}

// Filter
$filter = isset($_GET['filter']) ? sanitizeInput($_GET['filter']) : 'all';

// Build query
$where_clause = "user_id = $user_id";
if ($filter == 'unread') {
    $where_clause .= " AND is_read = 0";
} else if ($filter == 'read') {
    $where_clause .= " AND is_read = 1";
}

// Get notifications
$notifications = $conn->query("SELECT * FROM notifications WHERE $where_clause ORDER BY created_at DESC");

// Get counts
$unread_count = getUnreadNotificationCount($user_id);
$total_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id")->fetch_assoc()['count'];

include '../includes/header.php';
include '../includes/navbar.php';
?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="btn-group" role="group">
                        <a href="?filter=all" class="btn btn-<?php echo $filter == 'all' ? 'primary' : 'outline-primary'; ?>">
                            All (<?php echo $total_count; ?>)
                        </a>
                        <a href="?filter=unread" class="btn btn-<?php echo $filter == 'unread' ? 'primary' : 'outline-primary'; ?>">
                            Unread (<?php echo $unread_count; ?>)
                        </a>
                        <a href="?filter=read" class="btn btn-<?php echo $filter == 'read' ? 'primary' : 'outline-primary'; ?>">
                            Read (<?php echo $total_count - $unread_count; ?>)
                        </a>
                    </div>
                    
                    <?php if ($unread_count > 0): ?>
                        <a href="?mark_all_read=1" class="btn btn-success">
                            <i class="bi bi-check-all"></i> Mark All as Read
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bell"></i> Notifications
            </div>
            <div class="card-body">
           <?php if ($notifications->num_rows > 0): ?>
    <div class="list-group">
        <?php while ($notif = $notifications->fetch_assoc()): ?>
            <?php
            // Parse metadata if exists
            $metadata = !empty($notif['metadata']) ? json_decode($notif['metadata'], true) : [];
            
            // Get action URL
            $action_url = $notif['action_url'];
            if (!$action_url && $notif['complaint_id']) {
                // Fallback if no action_url
                $action_url = "complaint_details.php?id=" . $notif['complaint_id'];
            }
            ?>
            
            <div class="list-group-item <?php echo $notif['is_read'] == 0 ? ($is_admin ? 'list-group-item-warning' : 'list-group-item-primary') : ''; ?> notification-item">
                <div class="d-flex w-100 justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <!-- Title with Icon -->
                        <div class="d-flex align-items-center mb-2">
                            <?php
                            $icon_class = 'bi-info-circle-fill text-info';
                            if ($notif['type'] == 'success') $icon_class = 'bi-check-circle-fill text-success';
                            if ($notif['type'] == 'warning') $icon_class = 'bi-exclamation-triangle-fill text-warning';
                            if ($notif['type'] == 'danger') $icon_class = 'bi-x-circle-fill text-danger';
                            ?>
                            <i class="bi <?php echo $icon_class; ?> fs-4 me-2"></i>
                            <h5 class="mb-0"><?php echo htmlspecialchars($notif['title']); ?></h5>
                        </div>
                        
                        <!-- Message with better formatting -->
                        <p class="mb-2" style="white-space: pre-wrap;"><?php echo htmlspecialchars($notif['message']); ?></p>
                        
                        <!-- Metadata badges (if available) -->
                        <?php if (!empty($metadata)): ?>
                            <div class="mb-2">
                                <?php if (isset($metadata['old_status']) && isset($metadata['new_status'])): ?>
                                    <span class="badge bg-secondary me-1"><?php echo $metadata['old_status']; ?></span>
                                    <i class="bi bi-arrow-right"></i>
                                    <span class="badge bg-primary ms-1"><?php echo $metadata['new_status']; ?></span>
                                <?php endif; ?>
                                
                                <?php if (isset($metadata['rating'])): ?>
                                    <span class="badge bg-warning text-dark">
                                        <?php echo str_repeat('⭐', $metadata['rating']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Actions -->
                        <div class="d-flex gap-3 align-items-center flex-wrap">
                            <small class="text-muted">
                                <i class="bi bi-clock"></i> <?php echo formatDateTime($notif['created_at']); ?>
                            </small>
                            
                            <?php if ($action_url): ?>
                                <a href="<?php echo htmlspecialchars($action_url); ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> View Details
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($notif['is_read'] == 0): ?>
                                <a href="?read=<?php echo $notif['notification_id']; ?>" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-check"></i> Mark as Read
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($notif['is_read'] == 0): ?>
                        <span class="badge bg-<?php echo $is_admin ? 'danger' : 'primary'; ?> ms-2">NEW</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-bell-slash" style="font-size: 4rem; color: #ddd;"></i>
                        <h5 class="mt-3 text-muted">No notifications</h5>
                        <p class="text-muted">
                            <?php if ($filter == 'unread'): ?>
                                You have no unread notifications
                            <?php elseif ($filter == 'read'): ?>
                                You have no read notifications
                            <?php else: ?>
                                You'll receive notifications when users submit new complaints
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div> <!-- End page-content -->
</div> <!-- End main-content -->

<?php include '../includes/footer.php'; ?>