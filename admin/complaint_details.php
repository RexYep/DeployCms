<?php
// ============================================
// ADMIN COMPLAINT DETAILS PAGE
// admin/complaint_details.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

requireAdmin();

$page_title = "Complaint Details";

$admin_id = $_SESSION['user_id'];
$complaint_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = '';
$success = '';

// Fetch complaint details
$stmt = $conn->prepare("
    SELECT c.*, cat.category_name, u.full_name as user_name, u.email as user_email, u.phone as user_phone,
           admin.full_name as assigned_admin_name,
           locker.full_name as locked_by_name,
           reviewer.full_name as reviewed_by_name
    FROM complaints c
    LEFT JOIN categories cat ON c.category_id = cat.category_id
    LEFT JOIN users u ON c.user_id = u.user_id
    LEFT JOIN users admin ON c.assigned_to = admin.user_id
    LEFT JOIN users locker ON c.locked_by = locker.user_id
    LEFT JOIN users reviewer ON c.reviewed_by = reviewer.user_id
    WHERE c.complaint_id = ?
");
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: manage_complaints.php");
    exit();
}

$complaint = $result->fetch_assoc();

// Check if regular admin has permission to view this complaint
if (!isSuperAdmin() && $complaint['assigned_to'] != $_SESSION['user_id']) {
    header("Location: manage_complaints.php");
    exit();
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $comment_text = sanitizeInput($_POST['comment']);
    
    // Check if admin can modify this complaint
    $can_modify = canAdminModifyComplaint($complaint_id, $admin_id, isSuperAdmin());
    
    if (!$can_modify['can_modify']) {
        $error = $can_modify['reason'];
    } else {
        $result = addComment($complaint_id, $admin_id, $comment_text);

        if ($result['success']) {
            // Get admin name
            $stmt_admin = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $stmt_admin->bind_param("i", $admin_id);
            $stmt_admin->execute();
            $admin_name = $stmt_admin->get_result()->fetch_assoc()['full_name'];

            // Notify user with enhanced notification
            notifyComment(
                $complaint['user_id'],
                $complaint_id,
                $admin_name,
                $comment_text,
                true // is_admin_comment
            );

            $success = $result['message'];
            header("Location: complaint_details.php?id=$complaint_id#comments");
            exit();
        } else {
            $error = $result['message'];
        }
    }
}

// Get comments
$comments = getComplaintComments($complaint_id);
$comment_count = getCommentCount($complaint_id);

// Fetch attachments
$stmt_attachments = $conn->prepare("SELECT * FROM complaint_attachments WHERE complaint_id = ? ORDER BY uploaded_date ASC");
$stmt_attachments->bind_param("i", $complaint_id);
$stmt_attachments->execute();
$attachments = $stmt_attachments->get_result();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = sanitizeInput($_POST['status']);
    $admin_response = sanitizeInput($_POST['admin_response']);
    $old_status = $complaint['status'];

    
    // Check if admin can modify this complaint
    $can_modify = canAdminModifyComplaint($complaint_id, $admin_id, isSuperAdmin());
    

    if (!$can_modify['can_modify']) {
        $error = $can_modify['reason'];
    } else {
        // Validate status progression
        $status_check = canChangeStatus($old_status, $new_status, isSuperAdmin());
        
        if (!$status_check['allowed']) {
            $error = $status_check['message'];
        } else {
            // Update complaint
            $stmt = $conn->prepare("UPDATE complaints SET status = ?, admin_response = ?, updated_date = NOW() WHERE complaint_id = ?");
            $stmt->bind_param("ssi", $new_status, $admin_response, $complaint_id);
            
            if ($stmt->execute()) {
        // If status is resolved, set resolved_date
        if ($new_status === 'Resolved' || $new_status === 'Closed') {
            $stmt = $conn->prepare("UPDATE complaints SET resolved_date = NOW() WHERE complaint_id = ?");
            $stmt->bind_param("i", $complaint_id);
            $stmt->execute();
        }
        
        // Log to history
        $comment = "Status updated by admin" . (!empty($admin_response) ? " with response" : "");
        $stmt = $conn->prepare("INSERT INTO complaint_history (complaint_id, changed_by, old_status, new_status, comment) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $complaint_id, $admin_id, $old_status, $new_status, $comment);
        $stmt->execute();
        
       // Get admin name for notification
$stmt_admin = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt_admin->bind_param("i", $admin_id);
$stmt_admin->execute();
$admin_name = $stmt_admin->get_result()->fetch_assoc()['full_name'];

// Create notification for user with enhanced context
notifyStatusChange(
    $complaint['user_id'],
    $complaint_id,
    $old_status,
    $new_status,
    $admin_name,
    $admin_response
);
        
        $success = "Complaint updated successfully!";
        
        // Refresh complaint data
        $stmt = $conn->prepare("
            SELECT c.*, cat.category_name, u.full_name as user_name, u.email as user_email, u.phone as user_phone,
                   admin.full_name as assigned_admin_name
            FROM complaints c
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            LEFT JOIN users u ON c.user_id = u.user_id
            LEFT JOIN users admin ON c.assigned_to = admin.user_id
            WHERE c.complaint_id = ?
        ");
        $stmt->bind_param("i", $complaint_id);
        $stmt->execute();
        $complaint = $stmt->get_result()->fetch_assoc();
        
    } else {
        $error = "Failed to update complaint. Please try again.";
    }
    } // Close status_check else
    } // Close can_modify else
}

// Handle admin assignment
if (isset($_POST['assign_admin']) && isSuperAdmin()) {
    $assigned_admin = (int)$_POST['assigned_to'];
    
    // Get current assignment
    $stmt = $conn->prepare("SELECT assigned_to FROM complaints WHERE complaint_id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $old_assignment = $stmt->get_result()->fetch_assoc();
    $old_admin_id = $old_assignment['assigned_to'];
    
    // ========================================
    // APPROVAL STATUS CHECK (NEW)
    // ========================================
    if ($complaint['approval_status'] !== 'approved') {
        $approval_status_text = [
            'pending_review' => 'pending review',
            'rejected' => 'rejected',
            'changes_requested' => 'awaiting changes from user'
        ];
        $status_text = $approval_status_text[$complaint['approval_status']] ?? 'not approved';
        
        $error = "Cannot assign this complaint - it is currently $status_text. ";
        
        if ($complaint['approval_status'] === 'pending_review') {
            $error .= '<a href="review_complaints.php?filter=pending_review" class="alert-link">Go to Review Complaints</a> to approve it first.';
        } elseif ($complaint['approval_status'] === 'changes_requested') {
            $error .= 'Waiting for user to make requested changes and resubmit.';
        } elseif ($complaint['approval_status'] === 'rejected') {
            $error .= 'This complaint has been rejected and cannot be assigned.';
        }
    } 
    // ========================================
    // CLOSED COMPLAINT CHECK (Existing)
    // ========================================
    elseif ($complaint['status'] === 'Closed') {
        $error = 'Cannot assign a closed complaint. Please reopen it first.';
    }
    // ========================================
    // LOCKED COMPLAINT CHECK (Existing)
    // ========================================
    elseif (isComplaintLocked($complaint_id)) {
        $error = 'This complaint is locked and cannot be modified.';
    }
    // ========================================
    // PROCEED WITH ASSIGNMENT
    // ========================================
    else {
        // Validate assignment
        if (empty($assigned_admin)) {
            $error = 'Please select an admin to assign.';
        } else {
            // Check if it's a reassignment
            $is_reassignment = !empty($old_admin_id);
            
            // If reassignment, require reason
            if ($is_reassignment) {
                $reassignment_reason = sanitizeInput($_POST['reassignment_reason'] ?? '');
                if (empty($reassignment_reason)) {
                    $error = 'Please provide a reason for reassignment.';
                } else {
                    // Get target admin details
                    $stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
                    $stmt->bind_param("i", $assigned_admin);
                    $stmt->execute();
                    $target_admin = $stmt->get_result()->fetch_assoc();
                    
                    // Get old admin name
                    $stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
                    $stmt->bind_param("i", $old_admin_id);
                    $stmt->execute();
                    $old_admin_name = $stmt->get_result()->fetch_assoc()['full_name'];
                    
                    // Update assignment
                    $stmt = $conn->prepare("UPDATE complaints SET assigned_to = ?, assigned_at = NOW() WHERE complaint_id = ?");
                    $stmt->bind_param("ii", $assigned_admin, $complaint_id);
                    
                    if ($stmt->execute()) {
                        // Record reassignment
                        recordReassignment($complaint_id, $old_admin_id, $assigned_admin, $admin_id, $reassignment_reason);
                        
                        // Notify newly assigned admin
                        if ($assigned_admin != $admin_id) {
                            notifyAssignment(
                                $assigned_admin,
                                $complaint_id,
                                $target_admin['full_name'],
                                true,
                                $old_admin_name,
                                $reassignment_reason
                            );
                        }
                        
                        // Notify user
                        notifyAssignment(
                            $complaint['user_id'],
                            $complaint_id,
                            $target_admin['full_name'],
                            true,
                            $old_admin_name,
                            $reassignment_reason
                        );
                        
                        // Notify old admin
                        if ($old_admin_id) {
                            createEnhancedNotification([
                                'user_id' => $old_admin_id,
                                'title' => "🔄 Complaint Reassigned Away",
                                'message' => "Complaint #$complaint_id has been reassigned to " . $target_admin['full_name'] . ".\n\n📝 Reason: $reassignment_reason",
                                'type' => 'warning',
                                'complaint_id' => $complaint_id,
                                'reference_type' => 'reassignment',
                                'action_url' => "admin/complaint_details.php?id=$complaint_id",
                                'metadata' => [
                                    'new_admin_name' => $target_admin['full_name'],
                                    'reason' => $reassignment_reason
                                ]
                            ]);
                        }
                        
                        $success = "Complaint reassigned successfully to " . htmlspecialchars($target_admin['full_name']) . ".";
                        
                        // Refresh complaint data
                        $stmt = $conn->prepare("
                            SELECT c.*, cat.category_name, u.full_name as admin_name, u.email as admin_email,
                                   locked_by_user.full_name as locked_by_name
                            FROM complaints c
                            LEFT JOIN categories cat ON c.category_id = cat.category_id
                            LEFT JOIN users u ON c.assigned_to = u.user_id
                            LEFT JOIN users locked_by_user ON c.locked_by = locked_by_user.user_id
                            WHERE c.complaint_id = ?
                        ");
                        $stmt->bind_param("i", $complaint_id);
                        $stmt->execute();
                        $complaint = $stmt->get_result()->fetch_assoc();
                    }
                }
            } else {
                // First-time assignment
                $assignment_note = sanitizeInput($_POST['assignment_note'] ?? '');
                
                // Get target admin details
                $stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $assigned_admin);
                $stmt->execute();
                $target_admin = $stmt->get_result()->fetch_assoc();
                
                // Update assignment
                $stmt = $conn->prepare("UPDATE complaints SET assigned_to = ?, assigned_at = NOW(), status = 'Assigned' WHERE complaint_id = ?");
                $stmt->bind_param("ii", $assigned_admin, $complaint_id);
                
                if ($stmt->execute()) {
                    // Log assignment
                    $stmt = $conn->prepare("
                        INSERT INTO complaint_history 
                        (complaint_id, changed_by, old_status, new_status, comment) 
                        VALUES (?, ?, 'Pending', 'Assigned', ?)
                    ");
                    $comment = "Assigned to " . $target_admin['full_name'];
                    if (!empty($assignment_note)) {
                        $comment .= ". Note: $assignment_note";
                    }
                    $stmt->bind_param("iis", $complaint_id, $admin_id, $comment);
                    $stmt->execute();
                    
                    // Notify assigned admin
                    if ($assigned_admin != $admin_id) {
                        notifyAssignment(
                            $assigned_admin,
                            $complaint_id,
                            $target_admin['full_name'],
                            false,
                            null,
                            null
                        );
                    }
                    
                    // Notify user
                    notifyAssignment(
                        $complaint['user_id'],
                        $complaint_id,
                        $target_admin['full_name'],
                        false,
                        null,
                        null
                    );
                    
                    $success = "Complaint assigned successfully to " . htmlspecialchars($target_admin['full_name']) . ".";
                    
                    // Refresh complaint data
                    $stmt = $conn->prepare("
                        SELECT c.*, cat.category_name, u.full_name as admin_name, u.email as admin_email,
                               locked_by_user.full_name as locked_by_name
                        FROM complaints c
                        LEFT JOIN categories cat ON c.category_id = cat.category_id
                        LEFT JOIN users u ON c.assigned_to = u.user_id
                        LEFT JOIN users locked_by_user ON c.locked_by = locked_by_user.user_id
                        WHERE c.complaint_id = ?
                    ");
                    $stmt->bind_param("i", $complaint_id);
                    $stmt->execute();
                    $complaint = $stmt->get_result()->fetch_assoc();
                }
            }
        }
    }
}

// Handle lock/unlock complaint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_lock'])) {
    if (!isSuperAdmin()) {
        $error = 'Only Super Admin can lock/unlock complaints.';
    } else {
        $action = $_POST['lock_action']; // 'lock' or 'unlock'
        $lock_reason = sanitizeInput($_POST['lock_reason'] ?? '');
        
        if ($action === 'lock') {
            $result = lockComplaint($complaint_id, $admin_id, $lock_reason);
        } else {
            $result = unlockComplaint($complaint_id, $admin_id, $lock_reason);
        }
        
        if ($result['success']) {
            $success = $result['message'];
            
            // Refresh complaint data
            $stmt = $conn->prepare("
                SELECT c.*, cat.category_name, u.full_name as user_name, u.email as user_email, u.phone as user_phone,
                       admin.full_name as assigned_admin_name,
                       locker.full_name as locked_by_name
                FROM complaints c
                LEFT JOIN categories cat ON c.category_id = cat.category_id
                LEFT JOIN users u ON c.user_id = u.user_id
                LEFT JOIN users admin ON c.assigned_to = admin.user_id
                LEFT JOIN users locker ON c.locked_by = locker.user_id
                WHERE c.complaint_id = ?
            ");
            $stmt->bind_param("i", $complaint_id);
            $stmt->execute();
            $complaint = $stmt->get_result()->fetch_assoc();
        } else {
            $error = $result['message'];
        }
    }
}

// Handle reopen request approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_reopen'])) {
    $reopen_id = (int)$_POST['reopen_id'];
    $review_note = sanitizeInput($_POST['review_note']);
    
    $result = approveReopenRequest($reopen_id, $admin_id, $review_note);
    
    if ($result['success']) {
        // Notify user
        createNotification(
            $complaint['user_id'],
            "Reopen Request Approved #$complaint_id",
            "Your request to reopen the complaint has been approved. " . $review_note,
            'success',
            $complaint_id
        );
        
        $success = $result['message'];
        header("Location: complaint_details.php?id=$complaint_id");
        exit();
    } else {
        $error = $result['message'];
    }
}

// Handle reopen request rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_reopen'])) {
    $reopen_id = (int)$_POST['reopen_id'];
    $review_note = sanitizeInput($_POST['review_note']);
    
    $result = rejectReopenRequest($reopen_id, $admin_id, $review_note);
    
    if ($result['success']) {
        // Notify user
        createNotification(
            $complaint['user_id'],
            "Reopen Request Rejected #$complaint_id",
            "Your request to reopen has been reviewed. Admin response: " . $review_note,
            'info',
            $complaint_id
        );
        
        $success = $result['message'];
        header("Location: complaint_details.php?id=$complaint_id");
        exit();
    } else {
        $error = $result['message'];
    }
}

// Fetch complaint history
$stmt = $conn->prepare("
    SELECT h.*, u.full_name 
    FROM complaint_history h
    JOIN users u ON h.changed_by = u.user_id
    WHERE h.complaint_id = ?
    ORDER BY h.changed_date DESC
");
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$history = $stmt->get_result();

// Get all admins for assignment dropdown
// If Super Admin: show all admins
// If Regular Admin: show only regular admins (not super admins)
if (isSuperAdmin()) {
    $admins = $conn->query("SELECT user_id, full_name, admin_level FROM users WHERE role = 'admin' AND status = 'active' ORDER BY full_name ASC");
} else {
    $admins = $conn->query("SELECT user_id, full_name, admin_level FROM users WHERE role = 'admin' AND admin_level = 'admin' AND status = 'active' ORDER BY full_name ASC");
}

include '../includes/header.php';
include '../includes/navbar.php';
?>

<?php if (!isSuperAdmin() && empty($complaint['assigned_to'])): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> <strong>Note:</strong> 
        This complaint is not assigned to anyone yet. Only Super Admin can assign it.
    </div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-12">
        <a href="manage_complaints.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to All Complaints
        </a>
    </div>
</div>

<!-- Lock Status Alert -->
<?php if ($complaint['is_locked'] == 1): ?>
    <div class="alert alert-danger border-danger">
        <div class="d-flex align-items-center">
            <i class="bi bi-lock-fill" style="font-size: 2rem; margin-right: 15px;"></i>
            <div class="flex-grow-1">
                <h5 class="mb-1"><i class="bi bi-exclamation-triangle-fill"></i> This Complaint is LOCKED</h5>
                <p class="mb-1">
                    <strong>Locked by:</strong> <?php echo htmlspecialchars($complaint['locked_by_name']); ?><br>
                    <strong>Locked on:</strong> <?php echo formatDateTime($complaint['locked_at']); ?><br>
                    <strong>Reason:</strong> <?php echo htmlspecialchars($complaint['lock_reason']); ?>
                </p>
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> No modifications can be made to this complaint until it is unlocked by Super Admin.
                </small>
            </div>
        </div>
    </div>
<?php endif; ?>

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
    <!-- Main Complaint Details -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-file-text"></i> Complaint #<?php echo $complaint['complaint_id']; ?></span>
        <div class="d-flex gap-2">
            <!-- Approval Status Badge -->
            <?php
            $approval_badges = [
                'pending_review' => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Pending Review</span>',
                'approved' => '<span class="badge bg-success"><i class="bi bi-check-circle-fill"></i> Approved</span>',
                'rejected' => '<span class="badge bg-danger"><i class="bi bi-x-circle-fill"></i> Rejected</span>',
                'changes_requested' => '<span class="badge bg-info"><i class="bi bi-pencil-square"></i> Changes Needed</span>'
            ];
            echo $approval_badges[$complaint['approval_status']] ?? '';
            
            // Add warning if not approved
            if ($complaint['approval_status'] !== 'approved') {
                echo ' <span class="badge bg-danger"><i class="bi bi-lock-fill"></i> Cannot Assign</span>';
            }
            ?>
            
            <!-- Status Badge -->
            <span class="<?php echo getStatusBadge($complaint['status']); ?>">
                <?php echo $complaint['status']; ?>
            </span>
        </div>
    </div>
</div>
            <div class="card-body">
                <h4 class="mb-3"><?php echo htmlspecialchars($complaint['subject']); ?></h4>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Category:</strong><br>
                        <span class="badge bg-light text-dark">
                            <?php echo htmlspecialchars($complaint['category_name']); ?>
                        </span>
                    </div>
                    <div class="col-md-4">
                        <strong>Priority:</strong><br>
                        <span class="<?php echo getPriorityBadge($complaint['priority']); ?>">
                            <?php echo $complaint['priority']; ?>
                        </span>
                    </div>

                    <!-- NEW: Approval Status Info -->
<div class="col-md-4">
    <strong>Approval:</strong><br>
    <?php
    $approval_badges_small = [
        'pending_review' => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass"></i> Pending</span>',
        'approved' => '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>',
        'rejected' => '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>',
        'changes_requested' => '<span class="badge bg-info"><i class="bi bi-pencil"></i> Changes</span>'
    ];
    echo $approval_badges_small[$complaint['approval_status']] ?? '';
    ?>
    <?php if (!empty($complaint['reviewed_by_name'])): ?>
        <br><small class="text-muted">by <?php echo htmlspecialchars($complaint['reviewed_by_name']); ?></small>
    <?php endif; ?>
</div>

                    <div class="col-md-4">
                        <strong>Days Pending:</strong><br>
                        <?php 
                        $days = daysElapsed($complaint['submitted_date']);
                        $color = $days > 7 ? 'text-danger' : ($days > 3 ? 'text-warning' : 'text-success');
                        ?>
                        <span class="<?php echo $color; ?>">
                            <strong><?php echo $days; ?> day<?php echo $days != 1 ? 's' : ''; ?></strong>
                        </span>
                    </div>
                </div>

                <?php if (!empty($complaint['rejection_reason']) && $complaint['approval_status'] !== 'approved'): ?>
    <div class="alert alert-<?php echo $complaint['approval_status'] === 'rejected' ? 'danger' : 'info'; ?> mt-3 mb-4">
        <h6 class="alert-heading">
            <i class="bi bi-<?php echo $complaint['approval_status'] === 'rejected' ? 'x-circle' : 'info-circle'; ?>-fill"></i>
            <?php echo $complaint['approval_status'] === 'rejected' ? 'Rejection Reason' : 'Changes Requested'; ?>
        </h6>
        <p class="mb-0" style="white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($complaint['rejection_reason'])); ?></p>
    </div>
<?php endif; ?>

                <div class="mb-4">
                    <strong>Description:</strong>
                   <p class="mt-2 complaint-description" style="white-space: pre-wrap; padding: 15px; border-radius: 5px;">
    <?php echo htmlspecialchars($complaint['description']); ?>
</p>
                </div>

                <?php if ($attachments->num_rows > 0): ?>
                <div class="mb-4">
                    <strong><i class="bi bi-paperclip"></i> Attachments (<?php echo $attachments->num_rows; ?>):</strong>
                    <div class="mt-3">
                        <div class="row">
                            <?php while ($file = $attachments->fetch_assoc()): 
                                $file_url = SITE_URL . $file['file_path'];
                                $is_image = in_array(strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif']);
                            ?>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 attachment-box" style="border-radius: 5px; border-left: 3px solid #667eea;">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-file-earmark-text fs-4 me-2 text-primary"></i>
                                            <div class="flex-grow-1">
                                                <strong><?php echo htmlspecialchars($file['file_name']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo number_format($file['file_size'] / 1024, 2); ?> KB
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <?php if ($is_image): ?>
                                            <div class="mb-2">
                                                <img src="<?php echo $file_url; ?>" 
                                                     alt="<?php echo htmlspecialchars($file['file_name']); ?>" 
                                                     class="img-fluid" 
                                                     style="max-width: 100%; max-height: 200px; border-radius: 5px; cursor: pointer;"
                                                     onclick="window.open('<?php echo $file_url; ?>', '_blank')">
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo $file_url; ?>" target="_blank" class="btn btn-sm btn-outline-primary flex-grow-1">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="<?php echo $file_url; ?>" download class="btn btn-sm btn-outline-success flex-grow-1">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <strong>User Information:</strong><br>
                    <div class="mt-2">
                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($complaint['user_name']); ?><br>
                        <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($complaint['user_email']); ?><br>
                        <?php if (!empty($complaint['user_phone'])): ?>
                        <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($complaint['user_phone']); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($complaint['admin_response'])): ?>
                <div class="alert alert-info">
                    <strong><i class="bi bi-chat-left-text"></i> Current Admin Response:</strong>
                    <p class="mb-0 mt-2" style="white-space: pre-wrap;"><?php echo htmlspecialchars($complaint['admin_response']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Update Status Form -->
         <form method="POST" action="">
<div class="mb-3">

    <label for="status" class="form-label">Update Status</label>
    <?php
    // Get allowed next statuses based on current status and admin level
    $allowed_statuses = getAllowedNextStatuses($complaint['status'], isSuperAdmin());
    ?>
    
    <?php if (!empty($allowed_statuses)): ?>
        <select class="form-select" id="status" name="status" required>
            <option value="">Select Status</option>
            <?php foreach ($allowed_statuses as $status_option): ?>
                <option value="<?php echo htmlspecialchars($status_option['status']); ?>">
                    <?php echo htmlspecialchars($status_option['status']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted">
            <i class="bi bi-info-circle"></i> 
            Current status: <strong><?php echo $complaint['status']; ?></strong>
        </small>
        
        <div class="mb-3 mt-3">
            <label for="admin_response" class="form-label">Admin Response/Note</label>
            <textarea class="form-control" id="admin_response" name="admin_response" rows="4" 
                      placeholder="Provide details about this status update..."></textarea>
            <small class="text-muted">This message will be visible to the user.</small>
        </div>

        <button type="submit" name="update_status" class="btn btn-primary w-100">
            <i class="bi bi-arrow-up-circle"></i> Update Status
        </button>
        
    <?php else: ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-lock-fill"></i> 
            <strong>Status: <?php echo $complaint['status']; ?></strong>
            <?php if ($complaint['status'] == 'Closed'): ?>
                <p class="mb-0 mt-2">This complaint is closed. No further status updates are possible.</p>
            <?php elseif ($complaint['status'] == 'Pending'): ?>
                <p class="mb-0 mt-2">Please assign this complaint to an admin first before updating its status.</p>
            <?php else: ?>
                <p class="mb-0 mt-2">No status transitions available from the current state.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
    </form>
        <!-- History -->
        <div class="card mt-3">
            <div class="card-header">
                <i class="bi bi-clock-history"></i> Complaint History
            </div>
            <div class="card-body">
                <?php if ($history->num_rows > 0): ?>
                    <div class="timeline">
                        <?php while ($h = $history->fetch_assoc()): ?>
                        <div class="timeline-item mb-3 pb-3" style="border-left: 2px solid #e0e0e0; padding-left: 20px; position: relative;">
                            <div style="position: absolute; left: -8px; top: 0; width: 14px; height: 14px; background: #667eea; border-radius: 50%;"></div>
                            <div class="d-flex justify-content-between">
                                <div>
                                    <strong><?php echo htmlspecialchars($h['full_name']); ?></strong>
                                    <?php if ($h['old_status'] && $h['new_status']): ?>
                                        changed status from 
                                        <span class="badge bg-secondary"><?php echo $h['old_status']; ?></span> to 
                                        <span class="<?php echo getStatusBadge($h['new_status']); ?>"><?php echo $h['new_status']; ?></span>
                                    <?php elseif ($h['new_status']): ?>
                                        set status to 
                                        <span class="<?php echo getStatusBadge($h['new_status']); ?>"><?php echo $h['new_status']; ?></span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted"><?php echo formatDateTime($h['changed_date']); ?></small>
                            </div>
                            <?php if (!empty($h['comment'])): ?>
                                <div class="mt-2 text-muted">
                                    <i class="bi bi-chat-quote"></i> <?php echo htmlspecialchars($h['comment']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No history available</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reassignment History -->
<?php 
$reassignment_history = getReassignmentHistory($complaint_id);
if ($reassignment_history->num_rows > 0): 
?>
<div class="card mt-3">
    <div class="card-header bg-info text-white">
        <i class="bi bi-arrow-repeat"></i> Reassignment History (<?php echo $reassignment_history->num_rows; ?>)
    </div>
    <div class="card-body">
        <div class="timeline">
            <?php while ($rh = $reassignment_history->fetch_assoc()): ?>
            <div class="timeline-item mb-3 pb-3" style="border-left: 2px solid #17a2b8; padding-left: 20px; position: relative;">
                <div style="position: absolute; left: -8px; top: 0; width: 14px; height: 14px; background: #17a2b8; border-radius: 50%;"></div>
                
                <div class="d-flex justify-content-between mb-2">
                    <div>
                        <strong><?php echo htmlspecialchars($rh['reassigned_by_name']); ?></strong> 
                        <span class="text-muted">reassigned complaint</span>
                    </div>
                    <small class="text-muted"><?php echo formatDateTime($rh['reassigned_at']); ?></small>
                </div>
                
                <div class="mb-2">
                    <?php if ($rh['old_admin_id']): ?>
                        <span class="badge bg-secondary">
                            <i class="bi bi-person-x"></i> <?php echo htmlspecialchars($rh['old_admin_name']); ?>
                        </span>
                        <i class="bi bi-arrow-right mx-2"></i>
                    <?php else: ?>
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-inbox"></i> Unassigned
                        </span>
                        <i class="bi bi-arrow-right mx-2"></i>
                    <?php endif; ?>
                    <span class="badge bg-info">
                        <i class="bi bi-person-check"></i> <?php echo htmlspecialchars($rh['new_admin_name']); ?>
                    </span>
                </div>
                
                <div class="alert alert-light mb-0">
                    <small>
                        <i class="bi bi-chat-quote"></i> 
                        <strong>Reason:</strong> <?php echo htmlspecialchars($rh['reason']); ?>
                    </small>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>
<?php endif; ?>

        <!-- Comments Section -->
        <div class="card mt-3" id="comments">
            <div class="card-header bg-warning">
                <i class="bi bi-chat-dots-fill"></i> Comments & Discussion (<?php echo $comment_count; ?>)
            </div>
            <div class="card-body">
                <!-- Existing Comments -->
                <?php if ($comments->num_rows > 0): ?>
                    <div class="mb-4">
                        <?php while ($comment = $comments->fetch_assoc()): ?>
                            <div class="comment-item mb-3 p-3" style="background: <?php echo $comment['role'] == 'admin' ? '#fff3cd' : '#e3f2fd'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $comment['role'] == 'admin' ? '#ffc107' : '#667eea'; ?>;">
                                <div class="d-flex align-items-start">
                                    <div class="user-avatar me-2" style="width: 40px; height: 40px; font-size: 1rem; background: <?php echo $comment['role'] == 'admin' ? 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)' : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'; ?>;">
                                        <?php echo strtoupper(substr($comment['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div>
                                                <strong><?php echo htmlspecialchars($comment['full_name']); ?></strong>
                                                <?php if ($comment['role'] == 'admin'): ?>
                                                    <span class="badge bg-warning text-dark ms-1">
                                                        <i class="bi bi-shield-check"></i> Admin
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-info ms-1">
                                                        <i class="bi bi-person"></i> User
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($comment['user_id'] == $_SESSION['user_id']): ?>
                                                    <span class="badge bg-primary ms-1">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <i class="bi bi-clock"></i> <?php echo formatDateTime($comment['created_at']); ?>
                                            </small>
                                        </div>
                                        <p class="mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars($comment['comment']); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-3 mb-4">
                        <i class="bi bi-chat-left-text" style="font-size: 3rem; color: #ddd;"></i>
                        <p class="text-muted mb-0">No comments yet. Start the conversation!</p>
                    </div>
                <?php endif; ?>

                <!-- Add Comment Form -->
                <div class="add-comment-section">
                    <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Add Admin Comment</h6>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <textarea class="form-control" name="comment" rows="3" 
                                      placeholder="Reply to user or add internal notes..." required></textarea>
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> User will be notified when you post a comment.
                            </small>
                        </div>
                        <button type="submit" name="add_comment" class="btn btn-warning">
                            <i class="bi bi-send"></i> Post Comment
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- Complaint Info -->
        <div class="card">
            <div class="card-header bg-light">
                <i class="bi bi-info-circle"></i> Complaint Information
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <strong>Tracking ID:</strong>
                    <div class="mt-1">
                        <span class="badge bg-dark" style="font-size: 1rem;">
                            #<?php echo $complaint['complaint_id']; ?>
                        </span>
                    </div>
                </div>

                <div class="mb-3">
                    <strong>Submitted:</strong>
                    <div class="text-muted"><?php echo formatDateTime($complaint['submitted_date']); ?></div>
                </div>

                <div class="mb-3">
                    <strong>Last Updated:</strong>
                    <div class="text-muted"><?php echo formatDateTime($complaint['updated_date']); ?></div>
                </div>

                <?php if (!empty($complaint['resolved_date'])): ?>
                <div class="mb-3">
                    <strong>Resolved:</strong>
                    <div class="text-muted"><?php echo formatDateTime($complaint['resolved_date']); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($complaint['assigned_admin_name'])): ?>
                <div class="mb-3">
                    <strong>Assigned To:</strong>
                    <div class="text-muted"><?php echo htmlspecialchars($complaint['assigned_admin_name']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($complaint['user_rating'])): ?>
<div class="mb-3">
    <strong>User Satisfaction:</strong>
    <div class="mt-2">
        <div style="color: #ffc107; font-size: 1.2rem;">
            <?php 
            for ($i = 1; $i <= 5; $i++) {
                echo $i <= $complaint['user_rating'] 
                    ? '<i class="bi bi-star-fill"></i>' 
                    : '<i class="bi bi-star"></i>';
            }
            ?>
            <span class="ms-2" style="color: #333; font-size: 0.9rem;">
                <?php echo $complaint['user_rating']; ?>/5
            </span>
        </div>
        <?php if (!empty($complaint['user_feedback'])): ?>
            <div class="alert alert-light mt-2 mb-0">
                <small><strong>User Feedback:</strong></small>
                <p class="mb-0 mt-1" style="font-size: 0.9rem;">
                    <?php echo nl2br(htmlspecialchars($complaint['user_feedback'])); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
        </div>
        

<!-- Assign/Reassign Complaint -->
<?php if (isSuperAdmin() && $complaint['status'] !== 'Closed'): ?>
<div class="card mt-3 <?php echo $complaint['approval_status'] !== 'approved' ? 'border-warning' : ''; ?>">
    <div class="card-header <?php echo $complaint['approval_status'] !== 'approved' ? 'bg-warning text-dark' : 'bg-light'; ?>">
        <i class="bi bi-person-check"></i> 
        <?php echo !empty($complaint['assigned_to']) ? 'Reassign Complaint' : 'Assign Complaint'; ?>
        <?php if ($complaint['approval_status'] !== 'approved'): ?>
            <i class="bi bi-lock-fill float-end"></i>
        <?php endif; ?>
    </div>
    
    <!-- Approval Warning Alert -->
    <?php if ($complaint['approval_status'] !== 'approved'): ?>
        <div class="alert alert-warning m-3 mb-0 border-0">
            <h6 class="alert-heading">
                <i class="bi bi-exclamation-triangle-fill"></i> Cannot Assign Yet
            </h6>
            <p class="mb-2">
                <?php
                if ($complaint['approval_status'] === 'pending_review') {
                    echo 'This complaint is <strong>pending review</strong>. You must approve it before assignment.';
                } elseif ($complaint['approval_status'] === 'rejected') {
                    echo 'This complaint has been <strong>rejected</strong> and cannot be assigned.';
                } elseif ($complaint['approval_status'] === 'changes_requested') {
                    echo 'Waiting for user to <strong>make requested changes</strong> and resubmit.';
                }
                ?>
            </p>
            <?php if ($complaint['approval_status'] === 'pending_review'): ?>
                <hr>
                <a href="review_complaints.php?filter=pending_review" class="btn btn-warning btn-sm">
                    <i class="bi bi-shield-check"></i> Go to Review Complaints
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="card-body" <?php echo $complaint['approval_status'] !== 'approved' ? 'style="opacity: 0.5; pointer-events: none;"' : ''; ?>>
        <?php if ($admins->num_rows > 0): ?>
            <?php 
            // Check if this would be a reassignment
            $is_reassignment = !empty($complaint['assigned_to']); 
            ?>
            
            <?php if ($is_reassignment): ?>
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Reassignment Notice:</strong>
                    <p class="mb-1 mt-2">
                        Currently assigned to: <strong><?php echo htmlspecialchars($complaint['assigned_admin_name']); ?></strong>
                    </p>
                    <small class="text-muted">
                        Reassigning will notify both the old and new admin, and the user.
                    </small>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="assigned_to" class="form-label">
                        <?php echo $is_reassignment ? 'Reassign to Admin' : 'Assign to Admin'; ?>
                    </label>
                    <select class="form-select" id="assigned_to" name="assigned_to" required>
                        <option value="">-- Select Admin --</option>
                        <?php 
                        // Reset the result pointer
                        $admins->data_seek(0);
                        while ($admin = $admins->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $admin['user_id']; ?>" 
                                <?php echo $complaint['assigned_to'] == $admin['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($admin['full_name']); ?>
                                <?php if ($admin['admin_level'] == 'super_admin'): ?>
                                    (Super Admin)
                                <?php endif; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <?php if ($is_reassignment): ?>
                    <!-- Reassignment Reason (REQUIRED for reassignments) -->
                    <div class="mb-3">
                        <label for="reassignment_reason" class="form-label">
                            Reassignment Reason <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="reassignment_reason" 
                                  name="reassignment_reason" rows="3" required
                                  placeholder="Why are you reassigning this complaint?"></textarea>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> Both admins and the user will see this reason.
                        </small>
                    </div>
                <?php else: ?>
                    <!-- Assignment Note (OPTIONAL for first assignment) -->
                    <div class="mb-3">
                        <label for="assignment_note" class="form-label">Assignment Note (Optional)</label>
                        <textarea class="form-control" id="assignment_note" 
                                  name="assignment_note" rows="2"
                                  placeholder="Any special instructions for the admin?"></textarea>
                    </div>
                <?php endif; ?>
                
                <button type="submit"
        name="assign_admin"
        class="btn btn-info btn-sm w-100"
        <?php echo $complaint['approval_status'] !== 'approved' ? 'disabled title="Approve complaint first"' : ''; ?>>
    <i class="bi bi-person-<?php echo $is_reassignment ? 'dash' : 'plus'; ?>"></i>
    <?php echo $is_reassignment ? 'Reassign Complaint' : 'Assign Complaint'; ?>
</button>
                <?php if ($complaint['reassignment_count'] > 0): ?>
                    <div class="mt-3 text-center">
                        <small class="text-muted">
                            <i class="bi bi-arrow-repeat"></i> 
                            This complaint has been reassigned <strong><?php echo $complaint['reassignment_count']; ?></strong> time(s)
                        </small>
                    </div>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <p class="text-muted mb-0">No admins available for assignment.</p>
           <?php endif; ?>
    </div>
</div>

       
        <!-- Quick Actions -->
        <div class="card mt-3">
            <div class="card-header bg-light">
                <i class="bi bi-lightning"></i> Quick Actions
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button class="btn btn-sm btn-outline-success" onclick="window.print();">
                        <i class="bi bi-printer"></i> Print Details
                    </button>
                    <a href="mailto:<?php echo $complaint['user_email']; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-envelope"></i> Email User
                    </a>
                </div>
            </div>
        </div>

        <!-- Lock/Unlock Complaint (Super Admin Only) -->
<?php if (isSuperAdmin()): ?>
<div class="card mt-3 <?php echo $complaint['is_locked'] ? 'border-danger' : 'border-warning'; ?>">
    <div class="card-header <?php echo $complaint['is_locked'] ? 'bg-danger text-white' : 'bg-warning text-dark'; ?>">
        <i class="bi bi-<?php echo $complaint['is_locked'] ? 'lock-fill' : 'shield-lock'; ?>"></i> 
        <?php echo $complaint['is_locked'] ? 'Locked Complaint' : 'Lock Complaint'; ?>
    </div>
    <div class="card-body">
        <?php if ($complaint['is_locked']): ?>
            <!-- Currently Locked -->
            <div class="alert alert-light mb-3">
                <small>
                    <strong>Locked by:</strong> <?php echo htmlspecialchars($complaint['locked_by_name']); ?><br>
                    <strong>Date:</strong> <?php echo formatDateTime($complaint['locked_at']); ?><br>
                    <strong>Reason:</strong> <?php echo htmlspecialchars($complaint['lock_reason']); ?>
                </small>
            </div>
            
            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to unlock this complaint? It will allow modifications again.');">
                <input type="hidden" name="lock_action" value="unlock">
                
                <div class="mb-3">
                    <label for="lock_reason" class="form-label">Unlock Reason (Optional)</label>
                    <input type="text" class="form-control" id="lock_reason" name="lock_reason" 
                           placeholder="Why are you unlocking this?">
                </div>
                
                <button type="submit" name="toggle_lock" class="btn btn-success w-100">
                    <i class="bi bi-unlock"></i> Unlock Complaint
                </button>
            </form>
        <?php else: ?>
            <!-- Not Locked -->
            <p class="text-muted mb-3" style="font-size: 0.9rem;">
                Lock this complaint to prevent any modifications by anyone (including you) until unlocked.
            </p>
            
            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to lock this complaint? No one will be able to modify it until you unlock it.');">
                <input type="hidden" name="lock_action" value="lock">
                
                <div class="mb-3">
                    <label for="lock_reason" class="form-label">Lock Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="lock_reason" name="lock_reason" rows="3" 
                              placeholder="Why are you locking this complaint?" required></textarea>
                    <small class="text-muted">This will be visible in the complaint history.</small>
                </div>
                
                <button type="submit" name="toggle_lock" class="btn btn-danger w-100">
                    <i class="bi bi-lock"></i> Lock Complaint
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
        <?php
// Check for pending reopen requests
$pending_reopen = getPendingReopenRequest($complaint_id);
if ($pending_reopen):
?>
<div class="card mt-3 border-warning">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-exclamation-triangle"></i> Reopen Request Pending
    </div>
    <div class="card-body">
        <p><strong>User Request:</strong></p>
        <div class="alert alert-light">
            <small><?php echo nl2br(htmlspecialchars($pending_reopen['reason'])); ?></small>
        </div>
        <p class="text-muted mb-3">
            <small>Requested by: <?php echo htmlspecialchars($pending_reopen['requester_name']); ?><br>
            Date: <?php echo formatDateTime($pending_reopen['created_at']); ?></small>
        </p>
        
        <form method="POST" action="">
            <input type="hidden" name="reopen_id" value="<?php echo $pending_reopen['reopen_id']; ?>">
            
            <div class="mb-3">
                <label for="review_note" class="form-label">Admin Response</label>
                <textarea class="form-control" id="review_note" name="review_note" rows="3" 
                          placeholder="Provide feedback to the user..." required></textarea>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" name="approve_reopen" class="btn btn-success btn-sm">
                    <i class="bi bi-check-circle"></i> Approve & Reopen
                </button>
                <button type="submit" name="reject_reopen" class="btn btn-danger btn-sm">
                    <i class="bi bi-x-circle"></i> Reject Request
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
    </div>

<?php endif; ?>

</div>

</div> <!-- End page-content -->
</div> <!-- End main-content -->
<!-- Auto-Refresh Script -->
<script>
let lastCommentCount = <?php echo $comment_count; ?>;
let isChecking = false;
let checkInterval;

// Create status indicator
function createStatusIndicator() {
    const indicator = document.createElement('div');
    indicator.id = 'autoRefreshIndicator';
    indicator.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: rgba(102, 126, 234, 0.95);
        color: white;
        padding: 12px 20px;
        border-radius: 25px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: none;
        align-items: center;
        gap: 10px;
        z-index: 1000;
        font-size: 14px;
    `;
    indicator.innerHTML = '<i class="bi bi-arrow-repeat" style="animation: spin 1s linear infinite;"></i> Checking for updates...';
    document.body.appendChild(indicator);
    
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
}

function showIndicator(show = true) {
    const indicator = document.getElementById('autoRefreshIndicator');
    if (indicator) {
        indicator.style.display = show ? 'flex' : 'none';
    }
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        background: ${type === 'info' ? '#17a2b8' : '#28a745'};
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 10000;
        min-width: 300px;
    `;
    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="bi bi-info-circle-fill" style="font-size: 1.5rem;"></i>
            <div>
                <strong>ℹ Update</strong>
                <div style="font-size: 14px; margin-top: 5px;">${message}</div>
            </div>
        </div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.remove(), 5000);
}

async function checkForUpdates() {
    if (isChecking) return;
    
    isChecking = true;
    showIndicator(true);
    
    try {
        const response = await fetch('check_complaint_updates.php?id=<?php echo $complaint_id; ?>');
        const data = await response.json();
        
        if (data.success) {
            // Check new comments from user
            if (data.comment_count > lastCommentCount) {
                const newCommentsCount = data.comment_count - lastCommentCount;
                showToast(`User added ${newCommentsCount} new comment(s)!`, 'info');
                lastCommentCount = data.comment_count;
                
                // Reload to show new comments
                setTimeout(() => window.location.reload(), 2000);
            }
        }
    } catch (error) {
        console.error('Error checking updates:', error);
    } finally {
        isChecking = false;
        showIndicator(false);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    createStatusIndicator();
    checkInterval = setInterval(checkForUpdates, 30000);
    
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            checkForUpdates();
        }
    });
});

window.addEventListener('beforeunload', function() {
    clearInterval(checkInterval);
});
</script>

<?php include '../includes/footer.php'; ?>