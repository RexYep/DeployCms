<?php
// ============================================
// CHECK NOTIFICATIONS (AJAX Endpoint)
// includes/check_notifications.php
// ============================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

requireLogin();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

// Get unread count
$unread_count = getUnreadNotificationCount($user_id);

// Get recent notifications
$recent_notifications = getRecentNotifications($user_id, 5);

$notifications = [];
while ($notif = $recent_notifications->fetch_assoc()) {
    $notifications[] = [
        'notification_id' => $notif['notification_id'],
        'title' => $notif['title'],
        'message' => $notif['message'],
        'type' => $notif['type'],
        'complaint_id' => $notif['complaint_id'],
        'is_read' => $notif['is_read'],
        'created_at' => $notif['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'unread_count' => (int)$unread_count,
    'notifications' => $notifications,
    'timestamp' => time()
]);