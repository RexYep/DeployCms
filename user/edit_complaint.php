<?php
// ============================================
// EDIT COMPLAINT PAGE
// user/edit_complaint.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

requireLogin();

if (isAdmin()) {
    header("Location: ../admin/index.php");
    exit();
}

$page_title = "Edit Complaint";

$user_id = $_SESSION['user_id'];
$complaint_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = '';
$success = '';

// Fetch complaint details
$stmt = $conn->prepare("
    SELECT c.*, cat.category_name
    FROM complaints c
    LEFT JOIN categories cat ON c.category_id = cat.category_id
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

// Check if complaint can be edited
if ($complaint['approval_status'] !== 'changes_requested' && $complaint['approval_status'] !== 'rejected') {
    $error = 'This complaint cannot be edited at this time.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $category_id = (int)$_POST['category_id'];
    $subject = sanitizeInput($_POST['subject']);
    $description = sanitizeInput($_POST['description']);
    $priority = sanitizeInput($_POST['priority']);
    
    // Validation
    if (empty($subject) || empty($description) || empty($category_id) || empty($priority)) {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($subject) < 10) {
        $error = 'Subject must be at least 10 characters long.';
    } elseif (strlen($description) < 20) {
        $error = 'Description must be at least 20 characters long.';
    } else {
        // Update complaint
        $stmt = $conn->prepare("
            UPDATE complaints 
            SET category_id = ?,
                subject = ?,
                description = ?,
                priority = ?,
                updated_date = NOW()
            WHERE complaint_id = ? AND user_id = ?
        ");
        $stmt->bind_param("isssii", $category_id, $subject, $description, $priority, $complaint_id, $user_id);
        
        if ($stmt->execute()) {
            // Resubmit for review
            $result = resubmitComplaint($complaint_id, $user_id);
            
            if ($result['success']) {
                $success = 'Your complaint has been updated and resubmitted for review.';
                
                // Refresh complaint data
                $stmt = $conn->prepare("SELECT * FROM complaints WHERE complaint_id = ?");
                $stmt->bind_param("i", $complaint_id);
                $stmt->execute();
                $complaint = $stmt->get_result()->fetch_assoc();
            } else {
                $error = $result['message'];
            }
        } else {
            $error = 'Failed to update complaint. Please try again.';
        }
    }
}

// Get categories for dropdown
$categories = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY category_name ASC");

include '../includes/header.php';
include '../includes/navbar.php';
?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <div class="text-center mb-4">
        <a href="my_complaints.php" class="btn btn-primary">
            <i class="bi bi-arrow-left"></i> Back to My Complaints
        </a>
        <a href="complaint_details.php?id=<?php echo $complaint_id; ?>" class="btn btn-outline-primary">
            <i class="bi bi-eye"></i> View Complaint
        </a>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Status Info Card -->
<div class="row mb-4">
    <div class="col-12">
        <?php if ($complaint['approval_status'] === 'changes_requested'): ?>
            <div class="alert alert-info border-info">
                <h5><i class="bi bi-pencil-square"></i> Changes Requested by Admin</h5>
                <p class="mb-2"><strong>Feedback from Admin:</strong></p>
                <div class="alert alert-light mb-3">
                    <?php echo nl2br(htmlspecialchars($complaint['rejection_reason'])); ?>
                </div>
                <p class="mb-0">
                    <i class="bi bi-info-circle"></i> Please address the feedback above and resubmit your complaint.
                </p>
            </div>
        <?php elseif ($complaint['approval_status'] === 'rejected'): ?>
            <div class="alert alert-danger border-danger">
                <h5><i class="bi bi-x-circle"></i> Complaint Rejected</h5>
                <p class="mb-2"><strong>Rejection Reason:</strong></p>
                <div class="alert alert-light mb-3">
                    <?php echo nl2br(htmlspecialchars($complaint['rejection_reason'])); ?>
                </div>
                <p class="mb-0">
                    <i class="bi bi-info-circle"></i> You can edit and resubmit this complaint if you address the concerns above.
                </p>
            </div>
        <?php endif; ?>
        
        <?php if ($complaint['resubmission_count'] > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-arrow-repeat"></i> This complaint has been resubmitted <strong><?php echo $complaint['resubmission_count']; ?></strong> time(s).
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Form -->
<?php if (empty($success)): ?>
<div class="row">
    <div class="col-lg-8 offset-lg-2">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Edit Your Complaint</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="category_id" class="form-label">
                            Category <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">Select a category</option>
                            <?php while ($category = $categories->fetch_assoc()): ?>
                                <option value="<?php echo $category['category_id']; ?>" 
                                        <?php echo $complaint['category_id'] == $category['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="subject" class="form-label">
                            Subject <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="subject" 
                               name="subject" 
                               value="<?php echo htmlspecialchars($complaint['subject']); ?>"
                               placeholder="Brief summary of your complaint" 
                               required
                               minlength="10"
                               maxlength="200">
                        <small class="text-muted">Minimum 10 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">
                            Description <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" 
                                  id="description" 
                                  name="description" 
                                  rows="6" 
                                  placeholder="Provide detailed information about your complaint..." 
                                  required
                                  minlength="20"><?php echo htmlspecialchars($complaint['description']); ?></textarea>
                        <small class="text-muted">Minimum 20 characters. Be specific and provide all relevant details.</small>
                    </div>
                    
                    <div class="mb-4">
                        <label for="priority" class="form-label">
                            Priority <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="priority" name="priority" required>
                            <option value="">Select priority</option>
                            <option value="Low" <?php echo $complaint['priority'] == 'Low' ? 'selected' : ''; ?>>
                                Low - Can wait
                            </option>
                            <option value="Medium" <?php echo $complaint['priority'] == 'Medium' ? 'selected' : ''; ?>>
                                Medium - Normal urgency
                            </option>
                            <option value="High" <?php echo $complaint['priority'] == 'High' ? 'selected' : ''; ?>>
                                High - Urgent attention needed
                            </option>
                        </select>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle"></i> <strong>Note:</strong> 
                        After resubmitting, your complaint will go through the admin review process again.
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Update & Resubmit
                        </button>
                        <a href="my_complaints.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                        <a href="complaint_details.php?id=<?php echo $complaint_id; ?>" class="btn btn-outline-info ms-auto">
                            <i class="bi bi-eye"></i> View Current Version
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</div> <!-- End Main Content -->

<script>
// Character counter for subject
document.getElementById('subject').addEventListener('input', function() {
    const current = this.value.length;
    const max = 200;
    const min = 10;
    
    if (current < min) {
        this.classList.add('is-invalid');
        this.classList.remove('is-valid');
    } else {
        this.classList.add('is-valid');
        this.classList.remove('is-invalid');
    }
});

// Character counter for description
document.getElementById('description').addEventListener('input', function() {
    const current = this.value.length;
    const min = 20;
    
    if (current < min) {
        this.classList.add('is-invalid');
        this.classList.remove('is-valid');
    } else {
        this.classList.add('is-valid');
        this.classList.remove('is-invalid');
    }
});
</script>

<?php include '../includes/footer.php'; ?>