<?php
// ============================================
// SUBMIT COMPLAINT PAGE
// user/submit_complaint.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

requireLogin();

if (isAdmin()) {
    header("Location: ../admin/index.php");
    exit();
}

$page_title = "Submit Complaint";

$error = '';
$success = '';

$user_id = $_SESSION['user_id'];

// Check daily limit
$limit_check = checkDailyComplaintLimit($user_id);
$can_submit = $limit_check['can_submit'];
$complaints_today = $limit_check['count'];
$remaining = $limit_check['remaining'];

// Get all active categories
$categories = getAllCategories();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

   $limit_check = checkDailyComplaintLimit($user_id);
    if (!$limit_check['can_submit']) {
        $error = 'Daily complaint limit reached! You have submitted ' . $limit_check['count'] . ' complaint(s) today. Please try again tomorrow.';
    } else {

    $user_id = $_SESSION['user_id'];
    $category_id = sanitizeInput($_POST['category_id']);
    $subject = sanitizeInput($_POST['subject']);
    $description = sanitizeInput($_POST['description']);
    $priority = sanitizeInput($_POST['priority']);
    
    // Validate inputs
    if (empty($subject) || empty($description) || empty($category_id)) {
        $error = 'Please fill in all required fields';
    } else if (strlen($subject) < 5) {
        $error = 'Subject must be at least 5 characters long';
    } else if (strlen($description) < 20) {
        $error = 'Description must be at least 20 characters long';
    } else {
        // Insert complaint
     $stmt = $conn->prepare("INSERT INTO complaints (user_id, category_id, subject, description, priority, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->bind_param("iisss", $user_id, $category_id, $subject, $description, $priority);

        if ($stmt->execute()) {
            $complaint_id = $conn->insert_id;
            
            // Handle file uploads
            $upload_success = true;
            $uploaded_files = [];
            
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = '../uploads/complaints/';
                $db_path = 'uploads/complaints/'; // Path to store in database
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
      $allowed_types = [
    'image/jpeg', 'image/png', 'image/gif', 
    'application/pdf', 
    'application/msword', 
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm'
    ];
      $max_file_size = 50 * 1024 * 1024; // 50MB (increased for videos)
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === 0) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        
                        // Validate file type
                        if (!in_array($file_type, $allowed_types)) {
                            $error = "File type not allowed: $file_name";
                            $upload_success = false;
                            break;
                        }
                        
                        // Validate file size
                        if ($file_size > $max_file_size) {
                            $error = "File too large (max 5MB): $file_name";
                            $upload_success = false;
                            break;
                        }
                        
                        // Generate unique filename
                        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                        $unique_name = 'complaint_' . $complaint_id . '_' . time() . '_' . $key . '.' . $file_extension;
                        $file_path = $upload_dir . $unique_name;
                        $db_file_path = $db_path . $unique_name; // Path for database
                        
                        // Move uploaded file
                        if (move_uploaded_file($tmp_name, $file_path)) {
                            // Save to database
                            $stmt = $conn->prepare("INSERT INTO complaint_attachments (complaint_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");
                            $stmt->bind_param("isssi", $complaint_id, $file_name, $db_file_path, $file_type, $file_size);
                            $stmt->execute();
                            
                            $uploaded_files[] = $file_name;
                        } else {
                            $error = "Failed to upload file: $file_name";
                            $upload_success = false;
                            break;
                        }
                    }
                }
            }
            
            // Log to history
            $stmt = $conn->prepare("INSERT INTO complaint_history (complaint_id, changed_by, old_status, new_status, comment) VALUES (?, ?, NULL, 'Pending', 'Complaint submitted')");
            $stmt->bind_param("ii", $complaint_id, $user_id);
            $stmt->execute();
            
        // Notify ONLY super admins about new complaint (regular admins get notified when assigned)
$super_admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND admin_level = 'super_admin' AND status = 'active'");
while ($admin = $super_admins->fetch_assoc()) {
    $notif_title = "New Complaint Submitted";
    $notif_message = "Complaint #$complaint_id: " . substr($subject, 0, 50) . "... (Priority: $priority)";
    $notif_type = ($priority == 'High') ? 'danger' : 'info';
    createNotification($admin['user_id'], $notif_title, $notif_message, $notif_type, $complaint_id);
}
            
            if ($upload_success) {
                $success = 'Complaint submitted successfully! Tracking ID: #' . $complaint_id;
                if (!empty($uploaded_files)) {
                    $success .= '<br>Files uploaded: ' . implode(', ', $uploaded_files);
                }
            } else {
                $success = 'Complaint submitted (ID: #' . $complaint_id . '), but some files failed to upload.';
            }
            
            // Clear form
            $subject = $description = '';
        } else {
            $error = 'Failed to submit complaint. Please try again.';
        }
    }
    } // Close the daily limit check
}

include '../includes/header.php';
include '../includes/navbar.php';
?>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-plus-circle"></i> Submit New Complaint
            </div>
            <div class="card-body">
                <!-- Daily Limit Warning -->
        <?php if ($can_submit): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> 
                <strong>Daily Limit:</strong> You can submit <strong><?php echo $remaining; ?></strong> more complaint(s) today. 
                (<?php echo $complaints_today; ?>/<?php echo DAILY_COMPLAINT_LIMIT; ?> used)
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> 
                <strong>Limit Reached!</strong> You have reached your daily complaint limit (<?php echo DAILY_COMPLAINT_LIMIT; ?> complaints/day). 
                Please try again tomorrow.
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

              <form method="POST" action="" enctype="multipart/form-data" <?php echo !$can_submit ? 'style="display:none;"' : ''; ?>>
                    <div class="mb-3">
                        <label for="category_id" class="form-label">
                            Category <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['category_id']; ?>"
                                    <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select the category that best describes your complaint</small>
                    </div>

                    <div class="mb-3">
                        <label for="priority" class="form-label">
                            Priority <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="priority" name="priority" required>
                            <option value="Low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'Low') ? 'selected' : ''; ?>>
                                Low - Not urgent
                            </option>
                            <option value="Medium" <?php echo (!isset($_POST['priority']) || $_POST['priority'] == 'Medium') ? 'selected' : ''; ?>>
                                Medium - Normal priority
                            </option>
                            <option value="High" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'High') ? 'selected' : ''; ?>>
                                High - Urgent
                            </option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="subject" class="form-label">
                            Subject <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="subject" name="subject" 
                               placeholder="Brief description of your complaint" required
                               minlength="5" maxlength="200"
                               value="<?php echo isset($subject) ? htmlspecialchars($subject) : ''; ?>">
                        <small class="text-muted">Minimum 5 characters, maximum 200 characters</small>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">
                            Detailed Description <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="description" name="description" rows="8" 
                                  placeholder="Provide detailed information about your complaint..." required
                                  minlength="20"><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
                        <small class="text-muted">
                            <span id="charCount">0</span> characters (minimum 20 required)
                        </small>
                    </div>

                    <!-- File Attachments -->
                    <div class="mb-3">
                        <label for="attachments" class="form-label">
                            <i class="bi bi-paperclip"></i> Attachments (Optional)
                        </label>
                       <input type="file" class="form-control" id="attachments" name="attachments[]" 
       multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.mp4,.mpeg,.mov,.avi,.webm">
<small class="text-muted">
    <i class="bi bi-info-circle"></i> You can upload multiple files:<br>
    • <strong>Images:</strong> JPG, PNG, GIF (Max 5MB)<br>
    • <strong>Videos:</strong> MP4, MPEG, MOV, AVI, WEBM (Max 50MB)<br>
    • <strong>Documents:</strong> PDF, DOC, DOCX (Max 5MB)
</small>
                        <div id="fileList" class="mt-2"></div>
                    </div>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Note:</strong> All fields marked with <span class="text-danger">*</span> are required. 
                        Please provide as much detail as possible to help us resolve your complaint quickly.
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Submit Complaint
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear Form
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary ms-auto">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tips Card -->
        <div class="card mt-3">
            <div class="card-header bg-light">
                <i class="bi bi-lightbulb"></i> Tips for Submitting a Complaint
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>Be specific and clear about your issue</li>
                    <li>Include relevant dates, times, and reference numbers if applicable</li>
                    <li>Choose the correct category for faster processing</li>
                    <li>Set appropriate priority level</li>
                    <li>Attach screenshots or documents as evidence if available</li>
                    <li>Provide contact information if additional details are needed</li>
                </ul>
            </div>
        </div>
    </div>
</div>

</div> <!-- End page-content -->

<script>
    // Character counter for description
    const description = document.getElementById('description');
    const charCount = document.getElementById('charCount');
    
    description.addEventListener('input', function() {
        charCount.textContent = this.value.length;
        
        if (this.value.length < 20) {
            charCount.classList.add('text-danger');
            charCount.classList.remove('text-success');
        } else {
            charCount.classList.remove('text-danger');
            charCount.classList.add('text-success');
        }
    });
    
    description.dispatchEvent(new Event('input'));
    
    // Enhanced File upload with video preview and progress bar
    document.getElementById('attachments').addEventListener('change', function() {
        const fileList = document.getElementById('fileList');
        fileList.innerHTML = '';
        
        if (this.files.length > 0) {
            const container = document.createElement('div');
            container.className = 'mt-3';
            
            Array.from(this.files).forEach((file, index) => {
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                const isVideo = file.type.startsWith('video/');
                const isImage = file.type.startsWith('image/');
                
                // Create file item card
                const fileCard = document.createElement('div');
                fileCard.className = 'card mb-3';
                fileCard.innerHTML = `
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-${isVideo ? 'camera-video' : isImage ? 'image' : 'file-earmark'} fs-3 me-3 text-primary"></i>
                                    <div>
                                        <strong>${file.name}</strong><br>
                                        <small class="text-muted">
                                            ${fileSize} MB | ${file.type || 'Unknown type'}
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <span class="badge bg-${fileSize > 50 ? 'danger' : 'success'}">
                                    ${fileSize > 50 ? 'Too Large!' : 'Ready'}
                                </span>
                            </div>
                        </div>
                        
                        <!-- Preview Area -->
                        <div class="preview-area mt-3" id="preview-${index}"></div>
                        
                        <!-- Progress Bar (initially hidden) -->
                        <div class="progress mt-3" style="display: none;" id="progress-${index}">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: 0%">0%</div>
                        </div>
                    </div>
                `;
                
                container.appendChild(fileCard);
                
                // Generate preview
                const previewArea = fileCard.querySelector(`#preview-${index}`);
                
                if (isImage) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewArea.innerHTML = `
                            <div class="text-center">
                                <img src="${e.target.result}" class="img-fluid rounded" 
                                     style="max-height: 200px; max-width: 100%;" 
                                     alt="Image Preview">
                            </div>
                        `;
                    };
                    reader.readAsDataURL(file);
                } else if (isVideo) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewArea.innerHTML = `
                            <div class="text-center">
                                <video controls class="rounded" style="max-height: 300px; max-width: 100%;">
                                    <source src="${e.target.result}" type="${file.type}">
                                    Your browser does not support video preview.
                                </video>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="bi bi-play-circle"></i> Video preview - 
                                        Click play to preview before submitting
                                    </small>
                                </div>
                            </div>
                        `;
                    };
                    reader.readAsDataURL(file);
                }
            });
            
            fileList.appendChild(container);
        }
    });
    
    // Simulate upload progress on form submit
    const form = document.querySelector('form[enctype="multipart/form-data"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            const progressBars = document.querySelectorAll('.progress');
            
            progressBars.forEach((progressBar, index) => {
                progressBar.style.display = 'block';
                const bar = progressBar.querySelector('.progress-bar');
                
                // Simulate progress
                let progress = 0;
                const interval = setInterval(() => {
                    progress += Math.random() * 15;
                    if (progress > 95) progress = 95;
                    
                    bar.style.width = progress + '%';
                    bar.textContent = Math.round(progress) + '%';
                    
                    if (progress >= 95) {
                        clearInterval(interval);
                    }
                }, 200);
            });
        });
    }
</script>
<?php include '../includes/footer.php'; ?>