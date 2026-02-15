<?php
// ============================================
// GET ONLINE USERS (AJAX Endpoint)
// includes/get_online_users.php
// ============================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

requireLogin();

header('Content-Type: application/json');

$current_user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
$is_super_admin = isSuperAdmin();

$online_users = [];
$total_online = 0;

if ($is_admin) {
    // ADMIN VIEW: See online users
    if ($is_super_admin) {
        // Super Admin: See ALL users and admins
        $stmt = $conn->prepare("
            SELECT user_id, full_name, email, role, admin_level, last_activity, profile_picture,
                   TIMESTAMPDIFF(MINUTE, last_activity, NOW()) as minutes_ago
            FROM users 
            WHERE is_online = 1 
              AND status = 'active'
              AND user_id != ?
            ORDER BY role DESC, last_activity DESC
            LIMIT 50
        ");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $online_users[] = [
                'user_id' => $row['user_id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'role' => $row['role'],
                'admin_level' => $row['admin_level'] ?? null,
                'profile_picture' => $row['profile_picture'],
                'minutes_ago' => (int)$row['minutes_ago'],
                'last_activity' => $row['last_activity']
            ];
        }
        
        // Total count for Super Admin (everyone except self)
        $count_query = "SELECT COUNT(*) as count FROM users WHERE is_online = 1 AND status = 'active' AND user_id != ?";
        $stmt = $conn->prepare($count_query);
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $total_online = $stmt->get_result()->fetch_assoc()['count'];
        
    } else {
        // Regular Admin: Only see users assigned to them
        $stmt = $conn->prepare("
            SELECT DISTINCT u.user_id, u.full_name, u.email, u.role, u.last_activity, u.profile_picture,
                   TIMESTAMPDIFF(MINUTE, u.last_activity, NOW()) as minutes_ago
            FROM users u
            INNER JOIN complaints c ON u.user_id = c.user_id
            WHERE u.role = 'user' 
              AND u.is_online = 1 
              AND u.status = 'active'
              AND u.approval_status = 'approved'
              AND c.assigned_to = ?
              AND c.status NOT IN ('Closed', 'Resolved')
            ORDER BY u.last_activity DESC
            LIMIT 20
        ");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $online_users[] = [
                'user_id' => $row['user_id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'role' => $row['role'],
                'admin_level' => null,
                'profile_picture' => $row['profile_picture'],
                'minutes_ago' => (int)$row['minutes_ago'],
                'last_activity' => $row['last_activity']
            ];
        }
        
        // Total count for Regular Admin (only assigned users)
        $count_query = "
            SELECT COUNT(DISTINCT u.user_id) as count 
            FROM users u
            INNER JOIN complaints c ON u.user_id = c.user_id
            WHERE u.role = 'user' 
              AND u.is_online = 1 
              AND u.status = 'active'
              AND u.approval_status = 'approved'
              AND c.assigned_to = ?
              AND c.status NOT IN ('Closed', 'Resolved')
        ";
        $stmt = $conn->prepare($count_query);
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $total_online = $stmt->get_result()->fetch_assoc()['count'];
    }
    
} else {
    // USER VIEW: See assigned admin only (if they have active complaints)
    $stmt = $conn->prepare("
        SELECT DISTINCT u.user_id, u.last_activity,
               TIMESTAMPDIFF(MINUTE, u.last_activity, NOW()) as minutes_ago
        FROM users u
        INNER JOIN complaints c ON u.user_id = c.assigned_to
        WHERE c.user_id = ?
          AND c.status NOT IN ('Closed', 'Resolved')
          AND u.is_online = 1
          AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $online_users[] = [
            'user_id' => 0, // Hide actual ID for privacy
            'full_name' => 'Assigned Admin',
            'email' => null,
            'role' => 'admin',
            'admin_level' => null,
            'profile_picture' => null,
            'minutes_ago' => (int)$row['minutes_ago'],
            'last_activity' => $row['last_activity'],
            'is_assigned_admin' => true
        ];
        $total_online = 1;
    }
}

echo json_encode([
    'success' => true,
    'online_users' => $online_users,
    'total_online' => (int)$total_online,
    'timestamp' => time(),
    'viewer_role' => $is_admin ? 'admin' : 'user',
    'is_super_admin' => $is_super_admin
]);