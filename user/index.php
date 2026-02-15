<?php
// ============================================
// USER DASHBOARD
// user/index.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

// Ensure user is logged in
requireLogin();

// Redirect admin to admin dashboard
if (isAdmin()) {
    header("Location: ../admin/index.php");
    exit();
}

$page_title = "Dashboard";

// Get user statistics
$user_id = $_SESSION['user_id'];

// Total complaints
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_complaints = $stmt->get_result()->fetch_assoc()['total'];

// Pending Review (NEW)
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ? AND approval_status = 'pending_review'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pending_review = $stmt->get_result()->fetch_assoc()['total'];

// Approved complaints (NEW)
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ? AND approval_status = 'approved'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$approved_complaints = $stmt->get_result()->fetch_assoc()['total'];

// Changes Requested (NEW)
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ? AND approval_status = 'changes_requested'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$changes_requested = $stmt->get_result()->fetch_assoc()['total'];

// Rejected (NEW)
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ? AND approval_status = 'rejected'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rejected_complaints = $stmt->get_result()->fetch_assoc()['total'];

// Pending complaints
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ? AND status = 'Pending' AND approval_status = 'approved'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pending_complaints = $stmt->get_result()->fetch_assoc()['total'];

// In Progress complaints
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ? AND status = 'In Progress'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$inprogress_complaints = $stmt->get_result()->fetch_assoc()['total'];

// Resolved complaints
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE user_id = ? AND status = 'Resolved'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resolved_complaints = $stmt->get_result()->fetch_assoc()['total'];

// Get recent complaints (include approval status)
$stmt = $conn->prepare("
    SELECT c.*, cat.category_name 
    FROM complaints c
    LEFT JOIN categories cat ON c.category_id = cat.category_id
    WHERE c.user_id = ?
    ORDER BY c.submitted_date DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_complaints = $stmt->get_result();

// Include header, navbar
include '../includes/header.php';
include '../includes/navbar.php';
?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stats-card" style="border-left-color: #667eea;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Total Complaints</h6>
                        <h2 class="mb-0"><?php echo $total_complaints; ?></h2>
                    </div>
                    <div class="text-primary" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-folder"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stats-card" style="border-left-color: #ffc107;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Pending</h6>
                        <h2 class="mb-0"><?php echo $pending_complaints; ?></h2>
                    </div>
                    <div class="text-warning" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-clock"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stats-card" style="border-left-color: #17a2b8;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">In Progress</h6>
                        <h2 class="mb-0"><?php echo $inprogress_complaints; ?></h2>
                    </div>
                    <div class="text-info" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-arrow-repeat"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stats-card" style="border-left-color: #28a745;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Resolved</h6>
                        <h2 class="mb-0"><?php echo $resolved_complaints; ?></h2>
                    </div>
                    <div class="text-success" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approval Status Section (NEW) -->
<?php if ($pending_review > 0 || $changes_requested > 0 || $rejected_complaints > 0): ?>
<div class="row mb-4">
    <div class="col-12">
        <h5 class="mb-3"><i class="bi bi-shield-check"></i> Approval Status</h5>
    </div>
    
    <?php if ($pending_review > 0): ?>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Pending Review</h6>
                        <h2 class="mb-0 text-warning"><?php echo $pending_review; ?></h2>
                        <small class="text-muted">Awaiting admin review</small>
                    </div>
                    <div class="text-warning" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($changes_requested > 0): ?>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Changes Requested</h6>
                        <h2 class="mb-0 text-info"><?php echo $changes_requested; ?></h2>
                        <small class="text-muted">Action required</small>
                    </div>
                    <div class="text-info" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-info text-white">
                <a href="my_complaints.php?approval_status=changes_requested" class="text-white text-decoration-none">
                    <i class="bi bi-arrow-right-circle"></i> Edit & Resubmit
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($rejected_complaints > 0): ?>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card border-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Rejected</h6>
                        <h2 class="mb-0 text-danger"><?php echo $rejected_complaints; ?></h2>
                        <small class="text-muted">View reasons</small>
                    </div>
                    <div class="text-danger" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-x-circle"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-danger text-white">
                <a href="my_complaints.php?approval_status=rejected" class="text-white text-decoration-none">
                    <i class="bi bi-eye"></i> View Details
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Daily Submission Info -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card" style="border-left: 4px solid #667eea;">
            <div class="card-body">
                <?php 
                $limit_check = checkDailyComplaintLimit($user_id);
                $percentage = ($limit_check['count'] / DAILY_COMPLAINT_LIMIT) * 100;
                ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">
                        <i class="bi bi-calendar-day"></i> Today's Submissions
                    </h6>
                    <span class="badge <?php echo $limit_check['can_submit'] ? 'bg-success' : 'bg-danger'; ?>">
                        <?php echo $limit_check['count']; ?> / <?php echo DAILY_COMPLAINT_LIMIT; ?>
                    </span>
                </div>
                
                <div class="progress" style="height: 25px;">
                    <div class="progress-bar <?php echo $percentage >= 100 ? 'bg-danger' : ($percentage >= 80 ? 'bg-warning' : 'bg-success'); ?>" 
                         role="progressbar" 
                         style="width: <?php echo $percentage; ?>%"
                         aria-valuenow="<?php echo $limit_check['count']; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="<?php echo DAILY_COMPLAINT_LIMIT; ?>">
                        <?php echo $percentage; ?>%
                    </div>
                </div>
                
                <?php if ($limit_check['can_submit']): ?>
                    <small class="text-success mt-2 d-block">
                        <i class="bi bi-check-circle"></i> You can submit <?php echo $limit_check['remaining']; ?> more complaint(s) today
                    </small>
                <?php else: ?>
                    <small class="text-danger mt-2 d-block">
                        <i class="bi bi-x-circle"></i> Daily limit reached. Please try again tomorrow at 12:00 AM
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3"><i class="bi bi-lightning-charge"></i> Quick Actions</h5>
                <div class="d-flex gap-2 flex-wrap">
                   <?php 
    $limit_check = checkDailyComplaintLimit($user_id);
    ?>
    <?php if ($limit_check['can_submit']): ?>
        <a href="submit_complaint.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Submit New Complaint
            <span class="badge bg-light text-dark ms-1"><?php echo $limit_check['remaining']; ?> left today</span>
        </a>
    <?php else: ?>
        <button class="btn btn-secondary" disabled title="Daily limit reached">
            <i class="bi bi-exclamation-circle"></i> Daily Limit Reached (<?php echo DAILY_COMPLAINT_LIMIT; ?>/day)
        </button>
    <?php endif; ?>
                    <a href="my_complaints.php" class="btn btn-outline-primary">
                        <i class="bi bi-list-ul"></i> View All Complaints
                    </a>
                    <a href="profile.php" class="btn btn-outline-secondary">
                        <i class="bi bi-person"></i> Update Profile
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Complaints -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-clock-history"></i> Recent Complaints
            </div>
            <div class="card-body">
                <?php if ($recent_complaints->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Approval</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($complaint = $recent_complaints->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $complaint['complaint_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($complaint['subject']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <?php echo htmlspecialchars($complaint['category_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
    <?php
    $approval_badges = [
        'pending_review' => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass"></i> Pending Review</span>',
        'approved' => '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>',
        'rejected' => '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>',
        'changes_requested' => '<span class="badge bg-info"><i class="bi bi-pencil"></i> Changes Needed</span>'
    ];
    echo $approval_badges[$complaint['approval_status']] ?? '';
    ?>
</td>
<td>
    <span class="<?php echo getStatusBadge($complaint['status']); ?>">
        <?php echo $complaint['status']; ?>
    </span>
</td>
                                    <td>
                                        <span class="<?php echo getStatusBadge($complaint['status']); ?>">
                                            <?php echo $complaint['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo getPriorityBadge($complaint['priority']); ?>">
                                            <?php echo $complaint['priority']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatDate($complaint['submitted_date']); ?></td>
                                    <td>
                                        <a href="complaint_details.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-center mt-3">
                        <a href="my_complaints.php" class="btn btn-outline-primary">
                            View All Complaints <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 4rem; color: #ddd;"></i>
                        <h5 class="mt-3 text-muted">No complaints submitted yet</h5>
                        <p class="text-muted">Click the button below to submit your first complaint</p>
                        <a href="submit_complaint.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Submit Complaint
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div> <!-- End Main Content -->

<?php include '../includes/footer.php'; ?>