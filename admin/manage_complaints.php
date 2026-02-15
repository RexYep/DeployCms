<?php
// ============================================
// MANAGE COMPLAINTS PAGE
// admin/manage_complaints.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

requireAdmin();

$page_title = "Manage Complaints";

// Filter parameters
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$priority_filter = isset($_GET['priority']) ? sanitizeInput($_GET['priority']) : '';
$category_filter = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = RECORDS_PER_PAGE;
$offset = ($page - 1) * $records_per_page;

// Build query
$where_conditions = [];
$params = [];
$types = "";

$approval_filter = isset($_GET['approval_status']) ? sanitizeInput($_GET['approval_status']) : '';

// Add to where conditions
if (!empty($approval_filter)) {
    $where_conditions[] = "c.approval_status = ?";
    $params[] = $approval_filter;
    $types .= "s";
}

// Add assignment filter for regular admins
if (!isSuperAdmin()) {
    $where_conditions[] = "c.assigned_to = ?";
    $params[] = $_SESSION['user_id'];
    $types .= "i";
}

if (!empty($status_filter)) {
    $where_conditions[] = "c.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($priority_filter)) {
    $where_conditions[] = "c.priority = ?";
    $params[] = $priority_filter;
    $types .= "s";
}

if (!empty($category_filter)) {
    $where_conditions[] = "c.category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

if (!empty($search_query)) {
    $where_conditions[] = "(c.subject LIKE ? OR c.description LIKE ? OR u.full_name LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total records
$count_query = "SELECT COUNT(*) as total FROM complaints c 
                LEFT JOIN users u ON c.user_id = u.user_id 
                $where_clause";

if (!empty($params)) {
    $stmt = $conn->prepare($count_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_records = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $total_records = $conn->query($count_query)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $records_per_page);

// Fetch complaints
$query = "
    SELECT c.*, cat.category_name, u.full_name as user_name, u.email as user_email
    FROM complaints c
    LEFT JOIN categories cat ON c.category_id = cat.category_id
    LEFT JOIN users u ON c.user_id = u.user_id
    $where_clause
    ORDER BY 
        CASE WHEN c.status = 'Pending' THEN 1
             WHEN c.status = 'In Progress' THEN 2
             WHEN c.status = 'Resolved' THEN 3
             ELSE 4 END,
        c.priority DESC,
        c.submitted_date DESC
    LIMIT ? OFFSET ?
";

$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$complaints = $stmt->get_result();

// Get all categories for filter
$categories = getAllCategories();

include '../includes/header.php';
include '../includes/navbar.php';
?>

<?php if (!isSuperAdmin()): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> <strong>Regular Admin:</strong> 
        You can only view and manage complaints that have been assigned to you by a Super Admin.
    </div>
<?php endif; ?>

<!-- Filter Section -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Resolved" <?php echo $status_filter == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="Closed" <?php echo $status_filter == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="priority" class="form-label">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="">All Priority</option>
                            <option value="Low" <?php echo $priority_filter == 'Low' ? 'selected' : ''; ?>>Low</option>
                            <option value="Medium" <?php echo $priority_filter == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="High" <?php echo $priority_filter == 'High' ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-select" id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>" 
                                    <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="search" class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="search" name="search" 
                                   placeholder="Subject, description, user..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <?php if (!empty($status_filter) || !empty($priority_filter) || !empty($category_filter) || !empty($search_query)): ?>
                    <div class="col-12">
                        <a href="manage_complaints.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-3">
    <label for="approval_status" class="form-label">Approval Status</label>
    <select class="form-select" id="approval_status" name="approval_status">
        <option value="">All Approvals</option>
        <option value="pending_review" <?php echo $approval_filter == 'pending_review' ? 'selected' : ''; ?>>
            Pending Review
        </option>
        <option value="approved" <?php echo $approval_filter == 'approved' ? 'selected' : ''; ?>>
            Approved
        </option>
        <option value="changes_requested" <?php echo $approval_filter == 'changes_requested' ? 'selected' : ''; ?>>
            Changes Requested
        </option>
        <option value="rejected" <?php echo $approval_filter == 'rejected' ? 'selected' : ''; ?>>
            Rejected
        </option>
    </select>
</div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Complaints Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-folder"></i> All Complaints (<?php echo $total_records; ?>)</span>
                    <a href="index.php" class="btn btn-sm btn-light">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($complaints->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Days</th>
                                    <th>Action</th>
                                    <th>Approval</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($complaint = $complaints->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#<?php echo $complaint['complaint_id']; ?></strong></td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($complaint['user_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($complaint['user_email']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="max-width: 250px;">
                                            <strong><?php echo htmlspecialchars($complaint['subject']); ?></strong><br>
                                            <small class="text-muted">
                                                <?php echo substr(htmlspecialchars($complaint['description']), 0, 60) . '...'; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <?php echo htmlspecialchars($complaint['category_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo getPriorityBadge($complaint['priority']); ?>">
                                            <?php echo $complaint['priority']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo getStatusBadge($complaint['status']); ?>">
                                            <?php echo $complaint['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo formatDate($complaint['submitted_date']); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $days = daysElapsed($complaint['submitted_date']);
                                        $color = $days > 7 ? 'text-danger' : ($days > 3 ? 'text-warning' : 'text-muted');
                                        ?>
                                        <small class="<?php echo $color; ?>">
                                            <?php echo $days; ?> day<?php echo $days != 1 ? 's' : ''; ?>
                                        </small>
                                    </td>
                                    <td>
                                        
     <div class="btn-group" role="group">
        <a href="complaint_details.php?id=<?php echo $complaint['complaint_id']; ?>" 
           class="btn btn-sm btn-outline-primary" title="View Details">
            <i class="bi bi-eye"></i>
        </a>
        <?php if (isSuperAdmin() && empty($complaint['assigned_to'])): ?>
            <button type="button" class="btn btn-sm btn-outline-warning" 
                    title="Needs Assignment" 
                    onclick="window.location.href='complaint_details.php?id=<?php echo $complaint['complaint_id']; ?>#assign'">
                <i class="bi bi-exclamation-triangle"></i>
            </button>
        <?php endif; ?>
    </div>
                                       
                                    </td>
                                    <td>
    <?php
    $approval_badges = [
        'pending_review' => 'badge bg-warning text-dark',
        'approved' => 'badge bg-success',
        'rejected' => 'badge bg-danger',
        'changes_requested' => 'badge bg-info'
    ];
    $approval_text = [
        'pending_review' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'changes_requested' => 'Changes'
    ];
    ?>
    <span class="<?php echo $approval_badges[$complaint['approval_status']]; ?>">
        <?php echo $approval_text[$complaint['approval_status']]; ?>
    </span>
</td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page-1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>">
                                    <i class="bi bi-chevron-left"></i> Previous
                                </a>
                            </li>
                            
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page+1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search_query); ?>">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 4rem; color: #ddd;"></i>
                        <h5 class="mt-3 text-muted">No complaints found</h5>
                        <?php if (!empty($status_filter) || !empty($priority_filter) || !empty($category_filter) || !empty($search_query)): ?>
                            <p class="text-muted">Try adjusting your filters</p>
                            <a href="manage_complaints.php" class="btn btn-outline-primary">Clear Filters</a>
                        <?php else: ?>
                            <p class="text-muted">No complaints have been submitted yet</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div> <!-- End page-content -->
</div> <!-- End main-content -->

<?php include '../includes/footer.php'; ?>