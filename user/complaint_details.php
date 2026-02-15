<?php
// ============================================
// COMPLAINT DETAILS PAGE
// user/complaint_details.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

requireLogin();

if (isAdmin()) {
    header("Location: ../admin/index.php");
    exit();
}

$page_title = "Complaint Details";

$user_id = $_SESSION['user_id'];
$complaint_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = '';
$success = '';

// Fetch complaint details
$stmt = $conn->prepare("
    SELECT c.*, cat.category_name, u.full_name as admin_name, u.email as admin_email
    FROM complaints c
    LEFT JOIN categories cat ON c.category_id = cat.category_id
    LEFT JOIN users u ON c.assigned_to = u.user_id
    WHERE c.complaint_id = ? AND c.user_id = ?
");
$stmt->bind_param("ii", $complaint_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: my_complaints.php");
    exit();
}

$complaint = $result->fetch_assoc();

// Handle user confirmation for resolved complaints
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_resolved'])) {
    $confirmation_action = sanitizeInput($_POST['confirmation_action']);
    
    if ($complaint['status'] !== 'Resolved') {
        $error = 'This complaint is not in Resolved status.';
    } else {
        if ($confirmation_action === 'confirm') {
            // User confirms - close the complaint
            $stmt = $conn->prepare("
                UPDATE complaints 
                SET status = 'Closed', 
                    user_confirmed_resolved = 1,
                    updated_date = NOW() 
                WHERE complaint_id = ?
            ");
            $stmt->bind_param("i", $complaint_id);
            
            if ($stmt->execute()) {
                // Log to history
                $stmt = $conn->prepare("
                    INSERT INTO complaint_history 
                    (complaint_id, changed_by, old_status, new_status, comment) 
                    VALUES (?, ?, 'Resolved', 'Closed', 'User confirmed resolution')
                ");
                $stmt->bind_param("ii", $complaint_id, $user_id);
                $stmt->execute();
                
            // Notify assigned admin first (with rating if exists)
if (!empty($complaint['assigned_to'])) {
    notifyResolutionConfirmed(
        $complaint['assigned_to'],
        $complaint_id,
        $_SESSION['full_name'],
        $complaint['user_rating'] ?? null
    );
}

// Notify super admins
$super_admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND admin_level = 'super_admin' AND status = 'active'");
while ($admin = $super_admins->fetch_assoc()) {
    if ($admin['user_id'] != $complaint['assigned_to']) {
        notifyResolutionConfirmed(
            $admin['user_id'],
            $complaint_id,
            $_SESSION['full_name'],
            $complaint['user_rating'] ?? null
        );
    }
}
                
                $success = 'Thank you! Your complaint has been marked as closed.';
                
                // Refresh complaint data
                $stmt = $conn->prepare("
                    SELECT c.*, cat.category_name, u.full_name as admin_name, u.email as admin_email
                    FROM complaints c
                    LEFT JOIN categories cat ON c.category_id = cat.category_id
                    LEFT JOIN users u ON c.assigned_to = u.user_id
                    WHERE c.complaint_id = ? AND c.user_id = ?
                ");
                $stmt->bind_param("ii", $complaint_id, $user_id);
                $stmt->execute();
                $complaint = $stmt->get_result()->fetch_assoc();
            } else {
                $error = 'Failed to update complaint status.';
            }
            
       } else if ($confirmation_action === 'reopen') {
    // User wants to reopen
    $reopen_reason = sanitizeInput($_POST['reopen_reason']);
    
    if (empty($reopen_reason)) {
        $error = 'Please provide a reason for reopening.';
    } else {
        // Create reopen request instead of directly reopening
        $result = createReopenRequest($complaint_id, $user_id, $reopen_reason);
        
        if ($result['success']) {
          // Notify assigned admin with enhanced notification
if (!empty($complaint['assigned_to'])) {
    notifyReopenRequest(
        $complaint['assigned_to'],
        $complaint_id,
        $_SESSION['full_name'],
        $reopen_reason
    );
}

// Notify super admins
$super_admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND admin_level = 'super_admin' AND status = 'active'");
while ($admin = $super_admins->fetch_assoc()) {
    if ($admin['user_id'] != $complaint['assigned_to']) {
        notifyReopenRequest(
            $admin['user_id'],
            $complaint_id,
            $_SESSION['full_name'],
            $reopen_reason
        );
    }
}
            
            $success = 'Your reopen request has been submitted. An admin will review it shortly.';
            
            // Refresh page
            header("Location: complaint_details.php?id=$complaint_id");
            exit();
        } else {
            $error = $result['message'];
        }
    }
}
    }
}


// Handle user satisfaction rating
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    $rating = (int)$_POST['rating'];
    $feedback = sanitizeInput($_POST['feedback'] ?? '');
    
    if ($complaint['status'] !== 'Closed') {
        $error = 'You can only rate closed complaints.';
    } elseif ($complaint['approval_status'] !== 'approved') {
        $error = 'This complaint cannot be rated because it was not approved.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Please select a valid rating (1-5 stars).';
    } else {
        $result = saveUserRating($complaint_id, $user_id, $rating, $feedback);
        
        if ($result['success']) {
            $success = $result['message'];
            
            // Refresh complaint data to show rating
            $stmt = $conn->prepare("
                SELECT c.*, cat.category_name, u.full_name as admin_name, u.email as admin_email
                FROM complaints c
                LEFT JOIN categories cat ON c.category_id = cat.category_id
                LEFT JOIN users u ON c.assigned_to = u.user_id
                WHERE c.complaint_id = ? AND c.user_id = ?
            ");
            $stmt->bind_param("ii", $complaint_id, $user_id);
            $stmt->execute();
            $complaint = $stmt->get_result()->fetch_assoc();
        } else {
            $error = $result['message'];
        }
    }
}


// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $comment_text = sanitizeInput($_POST['comment']);
    
    $result = addComment($complaint_id, $user_id, $comment_text);
    
  if ($result['success']) {
    // Notify assigned admin with enhanced notification
    if (!empty($complaint['assigned_to'])) {
        notifyComment(
            $complaint['assigned_to'],
            $complaint_id,
            $_SESSION['full_name'],
            $comment_text,
            false // is_admin_comment = false (user commenting)
        );
    }
    
    // Also notify super admins
    $super_admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND admin_level = 'super_admin' AND status = 'active'");
    while ($admin = $super_admins->fetch_assoc()) {
        if ($admin['user_id'] != $complaint['assigned_to']) {
            notifyComment(
                $admin['user_id'],
                $complaint_id,
                $_SESSION['full_name'],
                $comment_text,
                false // is_admin_comment
            );
        }
    }
        
        $success = $result['message'];
        header("Location: complaint_details.php?id=$complaint_id#comments");
        exit();
    } else {
        $error = $result['message'];
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

include '../includes/header.php';
include '../includes/navbar.php';
?>

<div class="row mb-3">
    <div class="col-12">
        <a href="my_complaints.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to My Complaints
        </a>
    </div>
</div>

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
    <!-- Complaint Details -->
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
                    <div class="col-md-6">
                        <strong>Category:</strong><br>
                        <span class="badge bg-light text-dark">
                            <?php echo htmlspecialchars($complaint['category_name']); ?>
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Priority:</strong><br>
                        <span class="<?php echo getPriorityBadge($complaint['priority']); ?>">
                            <?php echo $complaint['priority']; ?>
                        </span>
                    </div>
                </div>
                <!-- Approval Status Alert -->
<?php if ($complaint['approval_status'] === 'pending_review'): ?>
    <div class="alert alert-warning border-warning">
        <h5><i class="bi bi-hourglass-split"></i> Pending Review</h5>
        <p class="mb-0">Your complaint is awaiting admin review. You'll be notified once it's been approved.</p>
    </div>
<?php elseif ($complaint['approval_status'] === 'changes_requested'): ?>
    <div class="alert alert-info border-info">
        <h5><i class="bi bi-pencil-square"></i> Changes Requested</h5>
        <p><strong>Admin Feedback:</strong></p>
        <div class="alert alert-light">
            <?php echo nl2br(htmlspecialchars($complaint['rejection_reason'])); ?>
        </div>
        <a href="edit_complaint.php?id=<?php echo $complaint_id; ?>" class="btn btn-info">
            <i class="bi bi-pencil"></i> Edit & Resubmit
        </a>
    </div>
<?php elseif ($complaint['approval_status'] === 'rejected'): ?>
    <div class="alert alert-danger border-danger">
        <h5><i class="bi bi-x-circle"></i> Complaint Rejected</h5>
        <p><strong>Rejection Reason:</strong></p>
        <div class="alert alert-light">
            <?php echo nl2br(htmlspecialchars($complaint['rejection_reason'])); ?>
        </div>
        <p class="mb-0">
            <small class="text-muted">
                <i class="bi bi-info-circle"></i> You can edit and resubmit if you address the concerns above.
            </small>
        </p>
        <a href="edit_complaint.php?id=<?php echo $complaint_id; ?>" class="btn btn-danger">
            <i class="bi bi-pencil"></i> Edit & Resubmit
        </a>
    </div>
<?php endif; ?>

                <div class="mb-3">
                    <strong>Description:</strong>
                    <p class="mt-2 complaint-description" style="white-space: pre-wrap; background: #f8f9fa; padding: 15px; border-radius: 5px;">
                        <?php echo htmlspecialchars($complaint['description']); ?>
                    </p>
                </div>

                <?php if ($attachments->num_rows > 0): ?>
                <div class="mb-3">
                    <strong><i class="bi bi-paperclip"></i> Attachments:</strong>
                    <div class="mt-2">
                        <?php while ($file = $attachments->fetch_assoc()): 
                            $file_url = SITE_URL . $file['file_path'];
                            $is_image = in_array(strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif']);
                        ?>
                            <div class="mb-3 p-3" style="background: #f8f9fa; border-radius: 5px;">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-file-earmark me-2"></i>
                                    <div class="flex-grow-1">
                                        <strong><?php echo htmlspecialchars($file['file_name']); ?></strong><br>
                                        <small class="text-muted">
                                            <?php echo number_format($file['file_size'] / 1024, 2); ?> KB - 
                                            Uploaded: <?php echo formatDateTime($file['uploaded_date']); ?>
                                        </small>
                                    </div>
                                    <a href="<?php echo $file_url; ?>" download class="btn btn-sm btn-outline-primary me-2">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                    <a href="<?php echo $file_url; ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                                
                                <?php if ($is_image): ?>
                                    <div class="mt-2">
                                        <img src="<?php echo $file_url; ?>" 
                                             alt="<?php echo htmlspecialchars($file['file_name']); ?>" 
                                             class="img-fluid" 
                                             style="max-width: 100%; max-height: 400px; border-radius: 5px; cursor: pointer;"
                                             onclick="window.open('<?php echo $file_url; ?>', '_blank')">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($complaint['admin_response'])): ?>
                <div class="alert alert-info">
                    <strong><i class="bi bi-chat-left-text"></i> Admin Response:</strong>
                    <p class="mb-0 mt-2" style="white-space: pre-wrap;"><?php echo htmlspecialchars($complaint['admin_response']); ?></p>
                </div>
                <?php endif; ?>

                <!-- Status-specific messages with actions -->
                <?php if ($complaint['status'] === 'Resolved' && $complaint['user_confirmed_resolved'] == 0): ?>
                    <!-- User Confirmation Required -->
                    <div class="card border-success mb-3">
                        <div class="card-header bg-success text-white">
                            <i class="bi bi-check-circle"></i> <strong>Complaint Resolved - Your Confirmation Needed</strong>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">
                                <strong>Great news!</strong> The admin has marked your complaint as resolved. 
                                <?php if (!empty($complaint['resolved_date'])): ?>
                                    <br><small class="text-muted">Resolved on: <?php echo formatDateTime($complaint['resolved_date']); ?></small>
                                <?php endif; ?>
                            </p>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Please confirm:</strong> Is your issue completely resolved?
                            </div>
                            
                         <form method="POST" action="" id="confirmationForm">
    <div class="d-flex gap-3 mb-3">
        <button type="button" class="btn btn-success flex-fill" onclick="confirmResolution()">
            <i class="bi bi-check-circle"></i> Yes, Issue is Resolved
        </button>
        <button type="button" class="btn btn-warning flex-fill" onclick="showReopenForm()">
            <i class="bi bi-arrow-counterclockwise"></i> No, Need to Reopen
        </button>
    </div>
    
    <!-- Hidden fields for confirmation -->
    <input type="hidden" name="confirmation_action" id="confirmation_action" value="">
    <input type="hidden" name="confirm_resolved" value="1">
    
    <!-- Reopen reason (hidden by default) -->
    <div id="reopenReasonDiv" style="display: none;">
        <div class="mb-3">
            <label for="reopen_reason" class="form-label">
                <strong>Why do you need to reopen?</strong> 
                <span class="text-danger">*</span>
            </label>
            <textarea class="form-control" id="reopen_reason" name="reopen_reason" rows="4" 
                      placeholder="Please explain why the issue is not resolved..."></textarea>
            <small class="text-muted">Be specific so the admin can help you better.</small>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-send"></i> Submit Reopen Request
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="hideReopenForm()">
                Cancel
            </button>
        </div>
    </div>
</form>
                        </div>
                    </div>

              <?php elseif ($complaint['status'] === 'Closed'): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i> 
        <strong>This complaint has been closed.</strong>
        <?php if (!empty($complaint['resolved_date'])): ?>
            <br><small>Resolved on: <?php echo formatDateTime($complaint['resolved_date']); ?></small>
        <?php endif; ?>
        <?php if ($complaint['user_confirmed_resolved'] == 1): ?>
            <br><small class="text-muted"><i class="bi bi-check2"></i> You confirmed this resolution.</small>
        <?php endif; ?>
    </div>
    
    <!-- Satisfaction Rating Section -->
     <!-- Satisfaction Rating Section -->
    
    <!-- Show message for rejected complaints -->
    <?php if ($complaint['approval_status'] === 'rejected'): ?>
        <div class="card mt-3 border-danger">
            <div class="card-header bg-danger text-white">
                <i class="bi bi-x-circle-fill"></i> Complaint Rejected
            </div>
            <div class="card-body">
                <div class="alert alert-light mb-3">
                    <strong><i class="bi bi-info-circle"></i> Rejection Reason:</strong>
                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($complaint['rejection_reason'])); ?></p>
                </div>
                <p class="mb-0">
                    <i class="bi bi-info-circle"></i> This complaint was rejected and cannot be rated. 
                    You can edit and resubmit if you address the concerns above.
                </p>
                <div class="mt-3">
                    <a href="edit_complaint.php?id=<?php echo $complaint_id; ?>" class="btn btn-danger">
                        <i class="bi bi-pencil"></i> Edit & Resubmit
                    </a>
                </div>
            </div>
        </div>
    
    <!-- Show message for complaints awaiting changes -->
    <?php elseif ($complaint['approval_status'] === 'changes_requested'): ?>
        <div class="card mt-3 border-info">
            <div class="card-header bg-info text-white">
                <i class="bi bi-pencil-square"></i> Changes Requested
            </div>
            <div class="card-body">
                <div class="alert alert-light mb-3">
                    <strong><i class="bi bi-info-circle"></i> Requested Changes:</strong>
                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($complaint['rejection_reason'])); ?></p>
                </div>
                <p class="mb-3">
                    <i class="bi bi-info-circle"></i> Please make the requested changes and resubmit your complaint.
                </p>
                <a href="edit_complaint.php?id=<?php echo $complaint_id; ?>" class="btn btn-info">
                    <i class="bi bi-pencil"></i> Edit & Resubmit
                </a>
            </div>
        </div>
    
    <!-- Show message for pending review -->
    <?php elseif ($complaint['approval_status'] === 'pending_review'): ?>
        <div class="card mt-3 border-warning">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-hourglass-split"></i> Pending Admin Review
            </div>
            <div class="card-body">
                <p class="mb-0">
                    <i class="bi bi-info-circle"></i> Your complaint is awaiting admin approval. 
                    You'll be notified once it's been reviewed and approved.
                </p>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Rating Form (only shows if approved AND closed AND not rated) -->
   <?php if (empty($complaint['user_rating']) && $complaint['approval_status'] === 'approved' && $complaint['status'] === 'Closed'): ?>
        <!-- Show rating form if not rated yet -->
        <div class="card mt-3 border-warning">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-star-fill"></i> Rate Your Experience
            </div>
            <div class="card-body">
                <p class="mb-3">How satisfied are you with the resolution of your complaint?</p>
                
                <form method="POST" action="" id="ratingForm">
                    <input type="hidden" name="rating" id="selectedRating" value="">
                    
                    <!-- Star Rating Buttons -->
                    <div class="d-flex justify-content-center gap-3 mb-4">
                        <button type="button" class="btn btn-outline-danger rating-btn" data-rating="1" 
                                style="font-size: 1.5rem; padding: 15px 25px;">
                            <i class="bi bi-emoji-frown"></i><br>
                            <small style="font-size: 0.7rem;">Poor</small><br>
                            <small style="font-size: 0.6rem;">1 ⭐</small>
                        </button>
                        <button type="button" class="btn btn-outline-warning rating-btn" data-rating="2"
                                style="font-size: 1.5rem; padding: 15px 25px;">
                            <i class="bi bi-emoji-neutral"></i><br>
                            <small style="font-size: 0.7rem;">Fair</small><br>
                            <small style="font-size: 0.6rem;">2 ⭐</small>
                        </button>
                        <button type="button" class="btn btn-outline-info rating-btn" data-rating="3"
                                style="font-size: 1.5rem; padding: 15px 25px;">
                            <i class="bi bi-emoji-smile"></i><br>
                            <small style="font-size: 0.7rem;">Good</small><br>
                            <small style="font-size: 0.6rem;">3 ⭐</small>
                        </button>
                        <button type="button" class="btn btn-outline-primary rating-btn" data-rating="4"
                                style="font-size: 1.5rem; padding: 15px 25px;">
                            <i class="bi bi-emoji-laughing"></i><br>
                            <small style="font-size: 0.7rem;">Very Good</small><br>
                            <small style="font-size: 0.6rem;">4 ⭐</small>
                        </button>
                        <button type="button" class="btn btn-outline-success rating-btn" data-rating="5"
                                style="font-size: 1.5rem; padding: 15px 25px;">
                            <i class="bi bi-emoji-heart-eyes"></i><br>
                            <small style="font-size: 0.7rem;">Excellent</small><br>
                            <small style="font-size: 0.6rem;">5 ⭐</small>
                        </button>
                    </div>
                    
                    <!-- Feedback Text Area (shown after rating selected) -->
                    <div id="feedbackSection" style="display: none;">
                        <div class="mb-3">
                            <label for="feedback" class="form-label">
                                Additional Feedback (Optional)
                            </label>
                            <textarea class="form-control" id="feedback" name="feedback" rows="3"
                                      placeholder="Tell us more about your experience..."></textarea>
                            <small class="text-muted">Your feedback helps us improve our service.</small>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" name="submit_rating" class="btn btn-primary flex-grow-1">
                                <i class="bi bi-send"></i> Submit Rating
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetRating()">
                                <i class="bi bi-x"></i> Cancel
                            </button>
                        </div>
                    </div>
                    
                    <p id="ratingPrompt" class="text-center text-muted mb-0">
                        <small>Please select a rating above</small>
                    </p>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- Show submitted rating -->
        <div class="card mt-3 border-success">
            <div class="card-header bg-success text-white">
                <i class="bi bi-star-fill"></i> Your Rating
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div style="font-size: 2rem; color: #ffc107;">
                        <?php 
                        for ($i = 1; $i <= 5; $i++) {
                            echo $i <= $complaint['user_rating'] 
                                ? '<i class="bi bi-star-fill"></i>' 
                                : '<i class="bi bi-star"></i>';
                        }
                        ?>
                    </div>
                    <p class="mb-1">
                        <strong>
                            <?php 
                            $rating_text = [
                                1 => 'Poor',
                                2 => 'Fair', 
                                3 => 'Good',
                                4 => 'Very Good',
                                5 => 'Excellent'
                            ];
                            echo $rating_text[$complaint['user_rating']] ?? 'Unknown';
                            ?> (<?php echo $complaint['user_rating']; ?>/5)
                        </strong>
                    </p>
                    <small class="text-muted">
                        Rated on <?php echo formatDateTime($complaint['rated_at']); ?>
                    </small>
                </div>
                
                <?php if (!empty($complaint['user_feedback'])): ?>
                    <div class="alert alert-light">
                        <strong>Your Feedback:</strong>
                        <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($complaint['user_feedback'])); ?></p>
                    </div>
                <?php endif; ?>
                
                <p class="text-center text-muted mb-0">
                    <small><i class="bi bi-check-circle"></i> Thank you for your feedback!</small>
                </p>
            </div>
        </div>
    <?php endif; ?>

                <?php elseif ($complaint['status'] === 'Assigned'): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-person-check"></i> 
                        <strong>Complaint Assigned</strong>
                        <?php if (!empty($complaint['admin_name'])): ?>
                            - Your complaint has been assigned to <strong><?php echo htmlspecialchars($complaint['admin_name']); ?></strong> for review.
                        <?php else: ?>
                            - Your complaint has been assigned to an admin for review.
                        <?php endif; ?>
                    </div>

                <?php elseif ($complaint['status'] === 'In Progress'): ?>
                    <div class="alert alert-primary">
                        <i class="bi bi-hourglass-split"></i> 
                        <strong>In Progress</strong> - Your complaint is currently being processed.
                    </div>

                <?php elseif ($complaint['status'] === 'On Hold'): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-pause-circle"></i> 
                        <strong>On Hold</strong> - Your complaint is temporarily on hold. The admin will provide updates soon.
                    </div>
                
                <?php elseif ($complaint['status'] === 'Pending'): ?>
                    <div class="alert alert-secondary">
                        <i class="bi bi-clock"></i> 
                        <strong>Pending Review</strong> - Your complaint is awaiting assignment to an admin.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reopen Requests Section (if any exist) -->
<?php 
$reopen_requests = getAllReopenRequests($complaint_id);
if ($reopen_requests->num_rows > 0): 
?>
<div class="card mt-3">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-arrow-counterclockwise"></i> Reopen Requests
    </div>
    <div class="card-body">
        <?php while ($req = $reopen_requests->fetch_assoc()): ?>
      <div class="mb-3 p-3 border rounded reopen-request-item" 
     data-status="<?php echo $req['status']; ?>">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            <strong class="requester-name"><?php echo htmlspecialchars($req['requester_name']); ?></strong>
            <span class="badge bg-<?php echo $req['status'] == 'pending' ? 'warning text-dark' : ($req['status'] == 'approved' ? 'success' : 'danger'); ?> ms-2">
                <?php echo ucfirst($req['status']); ?>
            </span>
        </div>
        <small class="text-muted request-date"><?php echo formatDateTime($req['created_at']); ?></small>
    </div>
    
    <div class="mb-2 request-reason-section">
        <strong class="reason-label">Reason:</strong>
        <p class="mb-0 reason-text" style="white-space: pre-wrap;"><?php echo htmlspecialchars($req['reason']); ?></p>
    </div>
    
    <?php if ($req['status'] != 'pending'): ?>
    <div class="mt-2 p-2 review-section">
        <small class="review-info">
            <strong>Reviewed by <?php echo htmlspecialchars($req['reviewer_name']); ?></strong>
            on <?php echo formatDateTime($req['reviewed_at']); ?>
        </small>
        <?php if (!empty($req['review_note'])): ?>
            <br><small class="review-note"><?php echo htmlspecialchars($req['review_note']); ?></small>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
        <?php endwhile; ?>
    </div>
</div>
<?php endif; ?>

        <!-- Complaint History -->
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

        <!-- Comments Section -->
        <div class="card mt-3" id="comments">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-chat-dots-fill"></i> Comments & Discussion (<?php echo $comment_count; ?>)
            </div>
            <div class="card-body">
                <!-- Existing Comments -->
                <?php if ($comments->num_rows > 0): ?>
                    <div class="mb-4">
                        <?php while ($comment = $comments->fetch_assoc()): ?>
                            <div class="comment-item mb-3 p-3" style="background: <?php echo $comment['role'] == 'admin' ? '#fff3cd' : '#e3f2fd'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $comment['role'] == 'admin' ? '#ffc107' : '#667eea'; ?>;">
                                <div class="d-flex align-items-start">
                                    <div class="user-avatar me-2" style="width: 40px; height: 40px; font-size: 1rem; background: <?php echo $comment['role'] == 'admin' ? 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)' : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white;">
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
                                                        <i class="bi bi-person"></i> You
                                                    </span>
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
                <?php if ($complaint['status'] !== 'Closed'): ?>
                <div class="add-comment-section">
                    <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Add a Comment</h6>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <textarea class="form-control" name="comment" rows="3" 
                                      placeholder="Type your comment or question here..." required></textarea>
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> You can ask questions or provide additional information about your complaint.
                            </small>
                        </div>
                        <button type="submit" name="add_comment" class="btn btn-primary">
                            <i class="bi bi-send"></i> Post Comment
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="alert alert-secondary text-center">
                    <i class="bi bi-lock"></i> This complaint is closed. Comments are disabled.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
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
                    <strong>Submitted Date:</strong>
                    <div class="text-muted"><?php echo formatDateTime($complaint['submitted_date']); ?></div>
                </div>

                <div class="mb-3">
                    <strong>Last Updated:</strong>
                    <div class="text-muted"><?php echo formatDateTime($complaint['updated_date']); ?></div>
                </div>

                <div class="mb-3">
                    <strong>Days Pending:</strong>
                    <div class="text-muted">
                        <?php 
                        $days = daysElapsed($complaint['submitted_date']);
                        echo $days . ' day' . ($days != 1 ? 's' : '');
                        ?>
                    </div>
                </div>

                <?php if (!empty($complaint['admin_name'])): ?>
                <div class="mb-3">
                    <strong>Assigned To:</strong>
                    <div class="text-muted">
                        <?php echo htmlspecialchars($complaint['admin_name']); ?>
                        <?php if (!empty($complaint['assigned_at'])): ?>
                            <br><small>Since: <?php echo formatDateTime($complaint['assigned_at']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($complaint['status'] === 'Resolved' || $complaint['status'] === 'Closed'): ?>
                <div class="mb-3">
                    <strong>Resolution Time:</strong>
                    <div class="text-muted">
                        <?php 
                        if (!empty($complaint['resolved_date'])) {
                            $resolution_days = floor((strtotime($complaint['resolved_date']) - strtotime($complaint['submitted_date'])) / 86400);
                            echo $resolution_days . ' day' . ($resolution_days != 1 ? 's' : '');
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <hr>

                <div class="mb-3">
                    <strong>Status Legend:</strong>
                    <div class="mt-2">
                        <div class="mb-1"><span class="badge bg-secondary">Pending</span> - Awaiting assignment</div>
                        <div class="mb-1"><span class="badge bg-info text-white">Assigned</span> - Assigned to admin</div>
                        <div class="mb-1"><span class="badge bg-primary">In Progress</span> - Being processed</div>
                        <div class="mb-1"><span class="badge bg-warning text-dark">On Hold</span> - Temporarily paused</div>
                        <div class="mb-1"><span class="badge bg-success">Resolved</span> - Issue resolved</div>
                        <div class="mb-1"><span class="badge bg-dark">Closed</span> - Complaint closed</div>
                    </div>
                </div>
            </div>

        <!-- Contact Support Card -->
        <div class="card mt-3">
            <div class="card-header bg-light">
                <i class="bi bi-headset"></i> Need Help?
            </div>
            <div class="card-body">
<p class="mb-2"><small>If you need to follow up on this complaint:</small></p>
<?php if (!empty($complaint['admin_email'])): ?>
<p class="mb-2">
<i class="bi bi-person-badge"></i> <strong>Your Admin:</strong><br>
<a href="mailto:<?php echo htmlspecialchars($complaint['admin_email']); ?>">
<?php echo htmlspecialchars($complaint['admin_email']); ?>
</a>
</p>
<?php endif; ?>
<p class="mb-0">
<i class="bi bi-envelope"></i> <strong>General Support:</strong><br>
<a href="mailto:<?php echo ADMIN_EMAIL; ?>"><?php echo ADMIN_EMAIL; ?></a>
</p>
</div>
</div>
</div>
</div>
</div> <!-- End Main Content -->
<!-- Confirmation Form Scripts -->

<script>
// ========================================
// CONFIRMATION FUNCTIONS (Load First!)
// ========================================
function confirmResolution() {
    if (confirm('Are you sure your issue is completely resolved? This will close the complaint.')) {
        document.getElementById('confirmation_action').value = 'confirm';
        document.getElementById('confirmationForm').submit();
    }
}

function showReopenForm() {
    document.getElementById('reopenReasonDiv').style.display = 'block';
    document.getElementById('confirmation_action').value = 'reopen';
    document.getElementById('reopen_reason').required = true;
    document.getElementById('reopen_reason').focus();
}

function hideReopenForm() {
    document.getElementById('reopenReasonDiv').style.display = 'none';
    document.getElementById('confirmation_action').value = '';
    document.getElementById('reopen_reason').required = false;
    document.getElementById('reopen_reason').value = '';
}

function resetRating() {
    const ratingButtons = document.querySelectorAll('.rating-btn');
    const selectedRatingInput = document.getElementById('selectedRating');
    const feedbackSection = document.getElementById('feedbackSection');
    const ratingPrompt = document.getElementById('ratingPrompt');
    const feedbackTextarea = document.getElementById('feedback');
    
    if (!ratingButtons.length) return;
    
    const colors = ['danger', 'warning', 'info', 'primary', 'success'];
    ratingButtons.forEach(btn => {
        const origRating = btn.getAttribute('data-rating');
        btn.className = 'btn btn-outline-' + colors[origRating - 1] + ' rating-btn';
    });
    
    if (selectedRatingInput) selectedRatingInput.value = '';
    if (feedbackTextarea) feedbackTextarea.value = '';
    if (feedbackSection) feedbackSection.style.display = 'none';
    if (ratingPrompt) ratingPrompt.style.display = 'block';
}

// ========================================
// RATING FUNCTIONALITY (DOMContentLoaded)
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize rating if form exists
    const ratingForm = document.getElementById('ratingForm');
    if (!ratingForm) {
        console.log('Rating form not found - skipping initialization');
        return;
    }
    
    const ratingButtons = document.querySelectorAll('.rating-btn');
    const selectedRatingInput = document.getElementById('selectedRating');
    const feedbackSection = document.getElementById('feedbackSection');
    const ratingPrompt = document.getElementById('ratingPrompt');
    
    if (!ratingButtons.length) {
        console.log('No rating buttons found');
        return;
    }
    
    console.log('Initializing rating buttons:', ratingButtons.length);
    
    ratingButtons.forEach(button => {
        button.addEventListener('click', function() {
            const rating = this.getAttribute('data-rating');
            const colors = ['danger', 'warning', 'info', 'primary', 'success'];
            
            console.log('Rating clicked:', rating);
            
            // Reset all buttons to outline style
            ratingButtons.forEach(btn => {
                const origRating = btn.getAttribute('data-rating');
                btn.className = 'btn btn-outline-' + colors[origRating - 1] + ' rating-btn';
            });
            
            // Set clicked button to solid color
            this.className = 'btn btn-' + colors[rating - 1] + ' rating-btn';
            
            // Set hidden input value
            if (selectedRatingInput) {
                selectedRatingInput.value = rating;
                console.log('Set rating value to:', rating);
            }
            
            // Show feedback section
            if (feedbackSection) feedbackSection.style.display = 'block';
            if (ratingPrompt) ratingPrompt.style.display = 'none';
        });
    });
    
    console.log('Rating functionality initialized successfully');
});

// ========================================
// AUTO-REFRESH FOR UPDATES
// ========================================
<?php if ($complaint['status'] !== 'Closed'): ?>
let lastStatus = '<?php echo $complaint['status']; ?>';
let lastResponse = <?php echo json_encode($complaint['admin_response']); ?>;
let lastCommentCount = <?php echo $comment_count; ?>;
let isChecking = false;
let checkInterval;

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
        animation: slideIn 0.3s ease;
    `;
    indicator.innerHTML = '<i class="bi bi-arrow-repeat" style="animation: spin 1s linear infinite;"></i> Checking for updates...';
    document.body.appendChild(indicator);
    
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes slideIn {
            from { transform: translateY(100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(100px); opacity: 0; }
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

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        background: ${type === 'success' ? '#28a745' : type === 'info' ? '#17a2b8' : '#ffc107'};
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 10000;
        min-width: 300px;
        animation: slideIn 0.3s ease;
    `;
    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'info' ? 'info-circle' : 'exclamation-triangle'}-fill" style="font-size: 1.5rem;"></i>
            <div>
                <strong>${type === 'success' ? '✓ Updated!' : type === 'info' ? 'ℹ Info' : '⚠ Notice'}</strong>
                <div style="font-size: 14px; margin-top: 5px;">${message}</div>
            </div>
        </div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

async function checkForUpdates() {
    if (isChecking) return;
    
    isChecking = true;
    showIndicator(true);
    
    try {
        const response = await fetch('check_complaint_updates.php?id=<?php echo $complaint_id; ?>');
        const data = await response.json();
        
        if (data.success) {
            let hasChanges = false;
            
            if (data.status !== lastStatus) {
                showToast(`Status updated: ${lastStatus} → ${data.status}`, 'success');
                lastStatus = data.status;
                hasChanges = true;
            }
            
            if (data.admin_response !== lastResponse) {
                showToast('Admin added a response to your complaint!', 'info');
                lastResponse = data.admin_response;
                hasChanges = true;
            }
            
            if (data.comment_count > lastCommentCount) {
                const newCommentsCount = data.comment_count - lastCommentCount;
                showToast(`${newCommentsCount} new comment(s) added!`, 'info');
                lastCommentCount = data.comment_count;
                hasChanges = true;
            }
            
            if (hasChanges) {
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
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    const complaintId = '<?php echo $complaint_id; ?>';
    const commentCount = <?php echo $comment_count ?? 0; ?>;
    
    console.log(`Viewing complaint #${complaintId} with ${commentCount} comments`);
    
    // Get existing last seen counts
    const lastSeenCounts = JSON.parse(localStorage.getItem('lastSeenCommentCounts') || '{}');
    
    // Get previous count
    const previousCount = lastSeenCounts[complaintId] || 0;
    
    // Update count for this complaint
    lastSeenCounts[complaintId] = commentCount;
    
    // Save back to localStorage
    localStorage.setItem('lastSeenCommentCounts', JSON.stringify(lastSeenCounts));
    
    // Log the update
    if (previousCount < commentCount) {
        const newComments = commentCount - previousCount;
        console.log(`✅ Marked ${commentCount} comments as seen (${newComments} were new)`);
    } else {
        console.log(`✅ Marked ${commentCount} comments as seen`);
    }
    
    // Show a subtle notification that comments were marked as read
    if (commentCount > 0 && previousCount < commentCount) {
        showCommentReadNotification(commentCount - previousCount);
    }
});

// Optional: Show brief notification that comments were marked as read
function showCommentReadNotification(newCount) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 10000;
        font-size: 14px;
        animation: slideInRight 0.3s ease;
    `;
    notification.innerHTML = `
        <i class="bi bi-check-circle-fill"></i> 
        ${newCount} new comment${newCount > 1 ? 's' : ''} marked as read
    `;
    document.body.appendChild(notification);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

</script>

<?php include '../includes/footer.php'; ?>