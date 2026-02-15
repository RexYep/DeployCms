<?php
// ============================================
// ADMIN DASHBOARD
// admin/index.php
// ============================================

require_once '../config/config.php';
require_once '../includes/functions.php';

// Ensure user is logged in and is admin
requireAdmin();

$page_title = "Admin Dashboard";

// Build WHERE clause based on admin level
$where_clause = "";
if (!isSuperAdmin()) {
    // Regular admin only sees assigned complaints
    $admin_id = $_SESSION['user_id'];
    $where_clause = "WHERE assigned_to = $admin_id";
}

// Get statistics
// Total complaints
$total_complaints = $conn->query("SELECT COUNT(*) as total FROM complaints $where_clause")->fetch_assoc()['total'];

// Pending complaints
$pending_complaints = $conn->query("SELECT COUNT(*) as total FROM complaints $where_clause " . ($where_clause ? "AND" : "WHERE") . " status = 'Pending'")->fetch_assoc()['total'];

// In Progress complaints
$inprogress_complaints = $conn->query("SELECT COUNT(*) as total FROM complaints $where_clause " . ($where_clause ? "AND" : "WHERE") . " status = 'In Progress'")->fetch_assoc()['total'];

// Resolved complaints
$resolved_complaints = $conn->query("SELECT COUNT(*) as total FROM complaints $where_clause " . ($where_clause ? "AND" : "WHERE") . " status = 'Resolved'")->fetch_assoc()['total'];

// Total users
$total_users = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'user'")->fetch_assoc()['total'];

// Total admins
$total_admins = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'admin'")->fetch_assoc()['total'];

// High priority complaints
$high_priority = $conn->query("SELECT COUNT(*) as total FROM complaints $where_clause " . ($where_clause ? "AND" : "WHERE") . " priority = 'High' AND status != 'Resolved' AND status != 'Closed'")->fetch_assoc()['total'];

// Recent complaints (last 10)
$recent_query = "
    SELECT c.*, cat.category_name, u.full_name as user_name
    FROM complaints c
    LEFT JOIN categories cat ON c.category_id = cat.category_id
    LEFT JOIN users u ON c.user_id = u.user_id
    $where_clause
    ORDER BY c.submitted_date DESC
    LIMIT 10
";
$recent_complaints = $conn->query($recent_query);

// Complaints by status for chart
$status_stats = $conn->query("
    SELECT status, COUNT(*) as count 
    FROM complaints 
    GROUP BY status
");

// Prepare data for charts
$status_labels = [];
$status_data = [];
$status_colors = [
    'Pending' => '#ffc107',
    'In Progress' => '#17a2b8',
    'Resolved' => '#28a745',
    'Closed' => '#6c757d'
];

while ($row = $status_stats->fetch_assoc()) {
    $status_labels[] = $row['status'];
    $status_data[] = $row['count'];
}

// Complaints by category for pie chart
$category_stats = $conn->query("
    SELECT cat.category_name, COUNT(c.complaint_id) as count
    FROM categories cat
    LEFT JOIN complaints c ON cat.category_id = c.category_id
    $where_clause
    GROUP BY cat.category_id, cat.category_name
    ORDER BY count DESC
    LIMIT 10
");

$category_labels = [];
$category_data = [];
$category_colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a', '#fee140', '#30cfd0', '#a8edea', '#fed6e3'];

while ($row = $category_stats->fetch_assoc()) {
    $category_labels[] = $row['category_name'];
    $category_data[] = $row['count'];
}

// Complaints trend over last 7 days
$trend_stats = $conn->query("
    SELECT DATE(submitted_date) as date, COUNT(*) as count
    FROM complaints
    $where_clause " . ($where_clause ? "AND" : "WHERE") . " submitted_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(submitted_date)
    ORDER BY date ASC
");
// Approval statistics (NEW)
$pending_review_count = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'pending_review'")->fetch_assoc()['count'];
$changes_requested_count = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'changes_requested'")->fetch_assoc()['count'];


$trend_labels = [];
$trend_data = [];

// Fill in missing dates with 0
$dates = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[$date] = 0;
}

while ($row = $trend_stats->fetch_assoc()) {
    $dates[$row['date']] = $row['count'];
}

foreach ($dates as $date => $count) {
    $trend_labels[] = date('M d', strtotime($date));
    $trend_data[] = $count;
}

// Priority distribution
$priority_stats = $conn->query("
    SELECT priority, COUNT(*) as count
    FROM complaints
    $where_clause
    GROUP BY priority
    ORDER BY FIELD(priority, 'High', 'Medium', 'Low')
");

$priority_labels = [];
$priority_data = [];
$priority_colors_map = [
    'High' => '#dc3545',
    'Medium' => '#ffc107',
    'Low' => '#28a745'
];

while ($row = $priority_stats->fetch_assoc()) {
    $priority_labels[] = $row['priority'];
    $priority_data[] = $row['count'];
}

// Monthly comparison (current month vs last month)
$current_month = date('Y-m');
$last_month = date('Y-m', strtotime('-1 month'));

$monthly_comparison = $conn->query("
    SELECT 
        SUM(CASE WHEN DATE_FORMAT(submitted_date, '%Y-%m') = '$current_month' THEN 1 ELSE 0 END) as current_month,
        SUM(CASE WHEN DATE_FORMAT(submitted_date, '%Y-%m') = '$last_month' THEN 1 ELSE 0 END) as last_month
    FROM complaints
    $where_clause
");

$monthly_data = $monthly_comparison->fetch_assoc();
$current_month_count = $monthly_data['current_month'] ?? 0;
$last_month_count = $monthly_data['last_month'] ?? 0;

include '../includes/header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<?php
include '../includes/navbar.php';
?>

<?php if (!isSuperAdmin()): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> <strong>Regular Admin:</strong> 
        You are viewing only complaints assigned to you. To see all complaints, contact a Super Admin.
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stats-card" style="border-left-color: #667eea;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Total Complaints</h6>
                        <h2 class="mb-0"><?php echo $total_complaints; ?></h2>
                    </div>
                    <div class="text-primary" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-folder-fill"></i>
                    </div>
                </div>
                <small class="text-muted">All time</small>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stats-card" style="border-left-color: #ffc107;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Pending</h6>
                        <h2 class="mb-0"><?php echo $pending_complaints; ?></h2>
                    </div>
                    <div class="text-warning" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-clock-fill"></i>
                    </div>
                </div>
                <small class="text-muted">Awaiting review</small>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
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
                <small class="text-muted">Being processed</small>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stats-card" style="border-left-color: #28a745;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Resolved</h6>
                        <h2 class="mb-0"><?php echo $resolved_complaints; ?></h2>
                    </div>
                    <div class="text-success" style="font-size: 3rem; opacity: 0.3;">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                </div>
                <small class="text-muted">Completed</small>
            </div>
        </div>
    </div>
</div>


<!-- Secondary Stats -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card stats-card" style="border-left-color: #6610f2;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Total Users</h6>
                        <h3 class="mb-0"><?php echo $total_users; ?></h3>
                    </div>
                    <div style="font-size: 2.5rem; opacity: 0.3; color: #6610f2;">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-3">
        <div class="card stats-card" style="border-left-color: #e83e8c;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Administrators</h6>
                        <h3 class="mb-0"><?php echo $total_admins; ?></h3>
                    </div>
                    <div style="font-size: 2.5rem; opacity: 0.3; color: #e83e8c;">
                        <i class="bi bi-person-badge-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-3">
        <div class="card stats-card" style="border-left-color: #dc3545;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">High Priority</h6>
                        <h3 class="mb-0"><?php echo $high_priority; ?></h3>
                    </div>
                    <div style="font-size: 2.5rem; opacity: 0.3; color: #dc3545;">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

    <!-- Approval Queue Widget (Super Admin Only) -->
<?php if (isSuperAdmin()): ?>
    <?php if ($pending_review_count > 0 || $changes_requested_count > 0): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle-fill"></i> Action Required - Complaint Approvals
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php if ($pending_review_count > 0): ?>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center justify-content-between p-3 border rounded">
                                <div>
                                    <h6 class="mb-1">⏳ Pending Review</h6>
                                    <p class="mb-0 text-muted">Complaints awaiting your approval</p>
                                </div>
                                <div>
                                    <span class="badge bg-warning text-dark" style="font-size: 1.5rem;">
                                        <?php echo $pending_review_count; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($changes_requested_count > 0): ?>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center justify-content-between p-3 border rounded">
                                <div>
                                    <h6 class="mb-1">📝 Changes Requested</h6>
                                    <p class="mb-0 text-muted">Waiting for user updates</p>
                                </div>
                                <div>
                                    <span class="badge bg-info" style="font-size: 1.5rem;">
                                        <?php echo $changes_requested_count; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="text-center mt-2">
                        <a href="review_complaints.php" class="btn btn-warning">
                            <i class="bi bi-shield-check"></i> Review Complaints Now
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>


<!-- Charts Section -->
<!-- Quick Actions -->
<div class="row mb-4">
        <!-- Complaints Trend Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-graph-up"></i> Complaints Trend (Last 7 Days)
            </div>
            <div class="card-body">
                <canvas id="trendChart" height="80"></canvas>
            </div>
        </div>
    </div>

    <!-- Priority Distribution -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pie-chart"></i> Priority Distribution
            </div>
            <div class="card-body">
                <canvas id="priorityChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Category Distribution Pie Chart -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-diagram-3"></i> Complaints by Category
            </div>
            <div class="card-body">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Status Distribution Bar Chart -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart"></i> Status Overview
            </div>
            <div class="card-body">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Monthly Comparison -->
    <div class="col-lg-12 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-calendar3"></i> Monthly Comparison
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-6 mb-3">
                        <div class="p-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; color: white;">
                            <h6 class="mb-2">Current Month</h6>
                            <h2 class="mb-0"><?php echo $current_month_count; ?></h2>
                            <small><?php echo date('F Y'); ?></small>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="p-4" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); border-radius: 10px; color: #333;">
                            <h6 class="mb-2">Last Month</h6>
                            <h2 class="mb-0"><?php echo $last_month_count; ?></h2>
                            <small><?php echo date('F Y', strtotime('-1 month')); ?></small>
                        </div>
                    </div>
                </div>
                
                <?php
                $difference = $current_month_count - $last_month_count;
                $percentage = $last_month_count > 0 ? round(($difference / $last_month_count) * 100, 1) : 0;
                ?>
                
                <div class="text-center mt-3">
                    <?php if ($difference > 0): ?>
                        <span class="badge bg-warning text-dark" style="font-size: 1rem; padding: 10px 20px;">
                            <i class="bi bi-arrow-up"></i> <?php echo abs($percentage); ?>% increase from last month
                        </span>
                    <?php elseif ($difference < 0): ?>
                        <span class="badge bg-success" style="font-size: 1rem; padding: 10px 20px;">
                            <i class="bi bi-arrow-down"></i> <?php echo abs($percentage); ?>% decrease from last month
                        </span>
                    <?php else: ?>
                        <span class="badge bg-info" style="font-size: 1rem; padding: 10px 20px;">
                            No change from last month
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3"><i class="bi bi-lightning-charge-fill"></i> Quick Actions</h5>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="manage_complaints.php" class="btn btn-primary">
                        <i class="bi bi-folder"></i> Manage Complaints
                    </a>
                    <a href="manage_complaints.php?status=Pending" class="btn btn-warning">
                        <i class="bi bi-clock"></i> View Pending (<?php echo $pending_complaints; ?>)
                    </a>
                    <a href="manage_users.php" class="btn btn-info">
                        <i class="bi bi-people"></i> Manage Users
                    </a>
                    <a href="reports.php" class="btn btn-success">
                        <i class="bi bi-graph-up"></i> View Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Complaints Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history"></i> Recent Complaints</span>
                    <a href="manage_complaints.php" class="btn btn-sm btn-light">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($recent_complaints->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($complaint = $recent_complaints->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#<?php echo $complaint['complaint_id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($complaint['user_name']); ?></td>
                                    <td>
                                        <div style="max-width: 250px;">
                                            <?php echo htmlspecialchars($complaint['subject']); ?>
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
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 4rem; color: #ddd;"></i>
                        <h5 class="mt-3 text-muted">No complaints yet</h5>
                        <p class="text-muted">Complaints will appear here when users submit them</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div> <!-- End page-content -->
</div> <!-- End main-content -->

<!-- Chart.js Configuration -->
<script>
// Chart.js Global Configuration
Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
Chart.defaults.color = '#6c757d';

// 1. Complaints Trend Chart (Line Chart)
const trendCtx = document.getElementById('trendChart').getContext('2d');
const trendChart = new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trend_labels); ?>,
        datasets: [{
            label: 'Complaints',
            data: <?php echo json_encode($trend_data); ?>,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#667eea',
            pointBorderColor: '#fff',
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                titleColor: '#fff',
                bodyColor: '#fff',
                borderColor: '#667eea',
                borderWidth: 1
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});

// 2. Priority Distribution (Doughnut Chart)
const priorityCtx = document.getElementById('priorityChart').getContext('2d');
const priorityChart = new Chart(priorityCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($priority_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($priority_data); ?>,
            backgroundColor: [
                '#dc3545', // High - Red
                '#ffc107', // Medium - Yellow
                '#28a745'  // Low - Green
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    usePointStyle: true
                }
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12
            }
        }
    }
});

// 3. Category Distribution (Pie Chart)
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
const categoryChart = new Chart(categoryCtx, {
    type: 'pie',
    data: {
        labels: <?php echo json_encode($category_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($category_data); ?>,
            backgroundColor: <?php echo json_encode(array_slice($category_colors, 0, count($category_labels))); ?>,
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    padding: 10,
                    usePointStyle: true,
                    font: {
                        size: 11
                    }
                }
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed || 0;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((value / total) * 100).toFixed(1);
                        return label + ': ' + value + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

// 4. Status Overview (Bar Chart)
const statusCtx = document.getElementById('statusChart').getContext('2d');
const statusChart = new Chart(statusCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($status_labels); ?>,
        datasets: [{
            label: 'Complaints',
            data: <?php echo json_encode($status_data); ?>,
            backgroundColor: [
                'rgba(255, 193, 7, 0.8)',   // Pending - Yellow
                'rgba(23, 162, 184, 0.8)',  // In Progress - Cyan
                'rgba(40, 167, 69, 0.8)',   // Resolved - Green
                'rgba(108, 117, 125, 0.8)'  // Closed - Gray
            ],
            borderColor: [
                '#ffc107',
                '#17a2b8',
                '#28a745',
                '#6c757d'
            ],
            borderWidth: 2,
            borderRadius: 8,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});

// Make charts responsive on window resize
window.addEventListener('resize', function() {
    trendChart.resize();
    priorityChart.resize();
    categoryChart.resize();
    statusChart.resize();
});
</script>

<?php include '../includes/footer.php'; ?>