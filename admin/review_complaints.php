<?php
// ============================================
// REVIEW COMPLAINTS PAGE (Super Admin Only)
// admin/review_complaints.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

requireAdmin();

// Only Super Admin can access this page
if (!isSuperAdmin()) {
    header("Location: index.php");
    exit();
}

$page_title = "Review Complaints";

$admin_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaint_id = (int)$_POST['complaint_id'];
    
    if (isset($_POST['approve'])) {
        $result = approveComplaint($complaint_id, $admin_id);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
    
    if (isset($_POST['reject'])) {
        $reason = sanitizeInput($_POST['rejection_reason']);
        $result = rejectComplaint($complaint_id, $admin_id, $reason);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
    
    if (isset($_POST['request_changes'])) {
        $changes = sanitizeInput($_POST['changes_needed']);
        $result = requestComplaintChanges($complaint_id, $admin_id, $changes);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

// Filter
$filter = isset($_GET['filter']) ? sanitizeInput($_GET['filter']) : 'pending_review';

// Build WHERE clause
$where_conditions = [];
if ($filter === 'pending_review') {
    $where_conditions[] = "c.approval_status = 'pending_review'";
} elseif ($filter === 'approved') {
    $where_conditions[] = "c.approval_status = 'approved'";
} elseif ($filter === 'rejected') {
    $where_conditions[] = "c.approval_status = 'rejected'";
} elseif ($filter === 'changes_requested') {
    $where_conditions[] = "c.approval_status = 'changes_requested'";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get complaints
$query = "
    SELECT c.*, 
           cat.category_name,
           u.full_name as user_name,
           u.email as user_email,
           reviewer.full_name as reviewer_name
    FROM complaints c
    LEFT JOIN categories cat ON c.category_id = cat.category_id
    LEFT JOIN users u ON c.user_id = u.user_id
    LEFT JOIN users reviewer ON c.reviewed_by = reviewer.user_id
    $where_clause
    ORDER BY 
        CASE c.approval_status
            WHEN 'pending_review' THEN 1
            WHEN 'changes_requested' THEN 2
            WHEN 'approved' THEN 3
            WHEN 'rejected' THEN 4
        END,
        c.submitted_date DESC
";

$complaints = $conn->query($query);

// Get counts for each status
// Get counts for all statuses in one query (more efficient)


include '../includes/header.php';
include '../includes/navbar.php';

// Get counts for each status
$pending_count = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'pending_review'")->fetch_assoc()['count'];
$approved_count = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'approved'")->fetch_assoc()['count'];
$rejected_count = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'rejected'")->fetch_assoc()['count'];
$changes_count = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'changes_requested'")->fetch_assoc()['count'];

?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filter Tabs -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="btn-group w-100" role="group">
                     <a href="?filter=pending_review" 
   class="btn btn-<?php echo $filter == 'pending_review' ? 'warning' : 'outline-warning'; ?>">
    <i class="bi bi-hourglass-split"></i> Pending Review 
    <span class="badge bg-<?php echo $filter == 'pending_review' ? 'light text-dark' : 'warning'; ?>">
        <?php echo (int)$pending_count; ?>
    </span>
</a>
    
    <!-- Changes Requested Tab -->
    <a href="?filter=changes_requested" 
       class="btn btn-<?php echo $filter == 'changes_requested' ? 'info' : 'outline-info'; ?>">
        <i class="bi bi-pencil-square"></i> Changes Requested
        <span class="badge <?php echo $filter == 'changes_requested' ? 'bg-dark text-white' : 'bg-info text-white'; ?>" 
              style="font-size: 0.9rem; padding: 4px 8px; margin-left: 5px;">
            <?php echo $changes_count; ?>
        </span>
    </a>
    
    <!-- Approved Tab -->
    <a href="?filter=approved" 
       class="btn btn-<?php echo $filter == 'approved' ? 'success' : 'outline-success'; ?>">
        <i class="bi bi-check-circle"></i> Approved
        <span class="badge <?php echo $filter == 'approved' ? 'bg-dark text-white' : 'bg-success text-white'; ?>" 
              style="font-size: 0.9rem; padding: 4px 8px; margin-left: 5px;">
            <?php echo $approved_count; ?>
        </span>
    </a>
    
    <!-- Rejected Tab -->
    <a href="?filter=rejected" 
       class="btn btn-<?php echo $filter == 'rejected' ? 'danger' : 'outline-danger'; ?>">
        <i class="bi bi-x-circle"></i> Rejected
        <span class="badge <?php echo $filter == 'rejected' ? 'bg-dark text-white' : 'bg-danger text-white'; ?>" 
              style="font-size: 0.9rem; padding: 4px 8px; margin-left: 5px;">
            <?php echo $rejected_count; ?>
        </span>
    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Complaints List -->
<div class="row">
    <div class="col-12">
        <?php if ($complaints->num_rows > 0): ?>
            <?php while ($complaint = $complaints->fetch_assoc()): ?>
                <div class="card mb-3 border-start border-4 
                     <?php 
                     if ($complaint['approval_status'] == 'pending_review') echo 'border-warning';
                     elseif ($complaint['approval_status'] == 'approved') echo 'border-success';
                     elseif ($complaint['approval_status'] == 'rejected') echo 'border-danger';
                     else echo 'border-info';
                     ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">
                                Complaint #<?php echo $complaint['complaint_id']; ?> - 
                                <?php echo htmlspecialchars($complaint['subject']); ?>
                            </h5>
                            <small class="text-muted">
                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($complaint['user_name']); ?> 
                                | <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($complaint['user_email']); ?>
                                | <i class="bi bi-calendar"></i> <?php echo formatDateTime($complaint['submitted_date']); ?>
                            </small>
                        </div>
                        <div>
                            <?php
                            $status_badge = [
                                'pending_review' => 'badge bg-warning text-dark',
                                'approved' => 'badge bg-success',
                                'rejected' => 'badge bg-danger',
                                'changes_requested' => 'badge bg-info'
                            ];
                            $status_text = [
                                'pending_review' => 'Pending Review',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                                'changes_requested' => 'Changes Requested'
                            ];
                            ?>
                            <span class="<?php echo $status_badge[$complaint['approval_status']]; ?>">
                                <?php echo $status_text[$complaint['approval_status']]; ?>
                            </span>
                            <?php if ($complaint['resubmission_count'] > 0): ?>
                                <span class="badge bg-secondary ms-2">
                                    <i class="bi bi-arrow-repeat"></i> Resubmission #<?php echo $complaint['resubmission_count']; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h6>Description:</h6>
                                <p style="white-space: pre-wrap;"><?php echo htmlspecialchars($complaint['description']); ?></p>
                                
                                <div class="mt-3">
                                    <span class="badge bg-light text-dark">
                                        <i class="bi bi-tag"></i> <?php echo htmlspecialchars($complaint['category_name']); ?>
                                    </span>
                                    <span class="<?php echo getPriorityBadge($complaint['priority']); ?> ms-2">
                                        <?php echo $complaint['priority']; ?>
                                    </span>
                                </div>
                                
                                <?php if (!empty($complaint['rejection_reason'])): ?>
                                    <div class="alert alert-<?php echo $complaint['approval_status'] == 'rejected' ? 'danger' : 'info'; ?> mt-3">
                                        <strong>
                                            <?php echo $complaint['approval_status'] == 'rejected' ? 'Rejection Reason:' : 'Changes Needed:'; ?>
                                        </strong>
                                        <p class="mb-0 mt-2"><?php echo htmlspecialchars($complaint['rejection_reason']); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($complaint['reviewer_name'])): ?>
                                    <small class="text-muted mt-2 d-block">
                                        <i class="bi bi-person-check"></i> Reviewed by <?php echo htmlspecialchars($complaint['reviewer_name']); ?> 
                                        on <?php echo formatDateTime($complaint['reviewed_at']); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-4">
                                <?php if ($complaint['approval_status'] == 'pending_review' || $complaint['approval_status'] == 'changes_requested'): ?>
                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2">
                                        <!-- Approve Button -->
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="complaint_id" value="<?php echo $complaint['complaint_id']; ?>">
                                            <button type="submit" name="approve" class="btn btn-success w-100" 
                                                    onclick="return confirm('Are you sure you want to APPROVE this complaint?')">
                                                <i class="bi bi-check-circle"></i> Approve
                                            </button>
                                        </form>
                                        
                                        <!-- Request Changes Button -->
                                        <button type="button" class="btn btn-info w-100" 
                                                onclick="showChangesModal(<?php echo $complaint['complaint_id']; ?>)">
                                            <i class="bi bi-pencil-square"></i> Request Changes
                                        </button>
                                        
                                        <!-- Reject Button -->
                                        <button type="button" class="btn btn-danger w-100" 
                                                onclick="showRejectModal(<?php echo $complaint['complaint_id']; ?>)">
                                            <i class="bi bi-x-circle"></i> Reject
                                        </button>
                                        
                                        <!-- View Details -->
                                        <a href="complaint_details.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                           class="btn btn-outline-primary w-100">
                                            <i class="bi bi-eye"></i> View Full Details
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-<?php echo $complaint['approval_status'] == 'approved' ? 'success' : 'danger'; ?>">
                                        <i class="bi bi-<?php echo $complaint['approval_status'] == 'approved' ? 'check-circle' : 'x-circle'; ?>-fill"></i>
                                        <strong>Already <?php echo ucfirst(str_replace('_', ' ', $complaint['approval_status'])); ?></strong>
                                    </div>
                                    <a href="complaint_details.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                       class="btn btn-outline-primary w-100">
                                        <i class="bi bi-eye"></i> View Full Details
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 4rem; color: #ddd;"></i>
                    <h5 class="mt-3 text-muted">No complaints in this category</h5>
                    <p class="text-muted">
                        <?php
                        if ($filter == 'pending_review') echo 'All complaints have been reviewed';
                        elseif ($filter == 'approved') echo 'No approved complaints yet';
                        elseif ($filter == 'rejected') echo 'No rejected complaints yet';
                        else echo 'No complaints requesting changes';
                        ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle"></i> Reject Complaint</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="complaint_id" id="rejectComplaintId">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> This complaint will be rejected and user will be notified.
                    </div>
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">
                            Reason for Rejection <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" 
                                  rows="4" required placeholder="Explain why this complaint is being rejected..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject" class="btn btn-danger">
                        <i class="bi bi-x-circle"></i> Reject Complaint
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Request Changes Modal -->
<div class="modal fade" id="changesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Request Changes</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="complaint_id" id="changesComplaintId">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> User will be able to edit and resubmit their complaint.
                    </div>
                    <div class="mb-3">
                        <label for="changes_needed" class="form-label">
                            What changes are needed? <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="changes_needed" name="changes_needed" 
                                  rows="4" required placeholder="Specify what the user needs to change or add..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="request_changes" class="btn btn-info">
                        <i class="bi bi-pencil-square"></i> Request Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

</div> <!-- End Main Content -->

<script>
function showRejectModal(complaintId) {
    document.getElementById('rejectComplaintId').value = complaintId;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function showChangesModal(complaintId) {
    document.getElementById('changesComplaintId').value = complaintId;
    new bootstrap.Modal(document.getElementById('changesModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>