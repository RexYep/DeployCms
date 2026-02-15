<!-- Footer Section -->
    <footer class="footer-section">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12 text-center">
                    <p class="mb-0">&copy; 2026 Complaint Management System. All rights reserved.</p>
                </div>
                
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script src="<?php echo SITE_URL; ?>assets/js/script.js"></script>
    <?php
// Include global auto-refresh only on authenticated pages
if (isset($_SESSION['user_id'])) {
    include __DIR__ . '/global_auto_refresh.php';
}
?>

<!-- Online Users Auto-Update Script -->
<script>
// Online Users Management
let onlineUsersInterval;

function updateOnlineUsers() {
    fetch('<?php echo SITE_URL; ?>includes/get_online_users.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const userList = document.getElementById('onlineUserList');
                const countBadge = document.getElementById('onlineUserCount');
                
                // Update count badge
                if (countBadge) {
                    countBadge.textContent = data.total_online;
                }
                
                // Update user list
                if (userList) {
                    if (data.online_users.length === 0) {
                        userList.innerHTML = `
                            <div class="text-center py-3">
                                <i class="bi bi-person-x text-white-50"></i>
                                <p class="text-white-50 mb-0 mt-2" style="font-size: 0.75rem;">
                                    ${data.viewer_role === 'admin' ? 'No users online' : 'Admin not available'}
                                </p>
                            </div>
                        `;
                    } else {
                        userList.innerHTML = data.online_users.map(user => {
                            // Check if this is "Assigned Admin" (for regular users)
                            const isAssignedAdmin = user.is_assigned_admin || false;
                            
                            // Display name logic
                            let displayName = '';
                            if (isAssignedAdmin) {
                                displayName = '<i class="bi bi-shield-check"></i> Your Assigned Admin';
                            } else {
                                displayName = user.full_name;
                            }
                            
                            // Role badge
                            let roleBadge = '';
                            if (isAssignedAdmin) {
                                roleBadge = '<span class="badge bg-success" style="font-size: 0.65rem;">Available</span>';
                            } else if (user.role === 'admin') {
                                const adminType = user.admin_level === 'super_admin' ? 'Super Admin' : 'Admin';
                                roleBadge = `<span class="badge bg-info" style="font-size: 0.65rem;">${adminType}</span>`;
                            } else {
                                roleBadge = '<span class="badge bg-secondary" style="font-size: 0.65rem;">User</span>';
                            }
                            
                            return `
                                <div class="online-user-item p-2 mb-2 rounded" 
                                     style="background: rgba(40, 167, 69, 0.15); transition: all 0.2s;">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <span class="bg-success rounded-circle d-inline-block me-2" 
                                                  style="width: 8px; height: 8px; animation: pulse 2s ease-in-out infinite;"></span>
                                            <div class="flex-grow-1">
                                                <div style="color: white; font-size: 0.8rem; font-weight: 500;">
                                                    ${displayName}
                                                </div>
                                                ${!isAssignedAdmin && user.email ? 
                                                    `<small class="text-white-50" style="font-size: 0.7rem;">${user.email}</small>` 
                                                    : ''}
                                            </div>
                                        </div>
                                        <div class="ms-2">
                                            ${roleBadge}
                                        </div>
                                    </div>
                                    <small class="text-white-50" style="font-size: 0.7rem;">
                                        <i class="bi bi-clock"></i> ${user.minutes_ago < 1 ? 'Just now' : user.minutes_ago + ' min ago'}
                                    </small>
                                </div>
                            `;
                        }).join('');
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error fetching online users:', error);
            const userList = document.getElementById('onlineUserList');
            if (userList) {
                userList.innerHTML = `
                    <div class="text-center py-3">
                        <i class="bi bi-exclamation-triangle text-warning"></i>
                        <p class="text-white-50 mb-0 mt-2" style="font-size: 0.75rem;">Failed to load</p>
                    </div>
                `;
            }
        });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initial load
    updateOnlineUsers();
    
    // Update every 30 seconds
    if (typeof onlineUsersInterval !== 'undefined') {
        clearInterval(onlineUsersInterval);
    }
    onlineUsersInterval = setInterval(updateOnlineUsers, 30000);
    
    // Update when tab becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateOnlineUsers();
        }
    });
});

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    if (typeof onlineUsersInterval !== 'undefined') {
        clearInterval(onlineUsersInterval);
    }
});

// Add CSS animation for pulse effect
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0%, 100% {
            opacity: 1;
            transform: scale(1);
        }
        50% {
            opacity: 0.7;
            transform: scale(1.2);
        }
    }
    
    .online-user-item:hover {
        background: rgba(40, 167, 69, 0.25) !important;
        transform: translateX(3px);
    }
`;
document.head.appendChild(style);

// 2

let lastNotificationCount = <?php echo getUnreadNotificationCount($_SESSION['user_id']); ?>;
let notificationCheckInterval;
let isCheckingNotifications = false;

function updateNotificationBadge() {
    if (isCheckingNotifications) return;
    
    isCheckingNotifications = true;
    
    fetch('<?php echo SITE_URL; ?>includes/check_notifications.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const currentCount = data.unread_count;
                const badge = document.querySelector('#notificationDropdown .badge');
                
                // Update badge count
                if (badge) {
                    if (currentCount > 0) {
                        badge.textContent = currentCount > 9 ? '9+' : currentCount;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
                
                // Show popup if new notifications arrived
                if (currentCount > lastNotificationCount) {
                    const newCount = currentCount - lastNotificationCount;
                    showNotificationPopup(newCount, data.notifications[0]);
                    
                    // Update the notification dropdown list
                    updateNotificationDropdown(data.notifications);
                }
                
                lastNotificationCount = currentCount;
            }
        })
        .catch(error => console.error('Error checking notifications:', error))
        .finally(() => {
            isCheckingNotifications = false;
        });
}

function showNotificationPopup(count, latestNotif) {
    // Check if popup already exists
    if (document.getElementById('notificationPopup')) return;
    
    const popup = document.createElement('div');
    popup.id = 'notificationPopup';
    popup.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        background: white;
        padding: 15px 20px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        z-index: 10000;
        min-width: 320px;
        max-width: 400px;
        animation: slideInRight 0.3s ease;
        border-left: 4px solid ${getNotificationColor(latestNotif.type)};
    `;
    
    const iconClass = getNotificationIcon(latestNotif.type);
    const iconColor = getNotificationColor(latestNotif.type);
    
    popup.innerHTML = `
        <div style="display: flex; align-items-start; gap: 12px;">
            <div style="flex-shrink: 0;">
                <i class="bi ${iconClass}" style="font-size: 1.8rem; color: ${iconColor};"></i>
            </div>
            <div style="flex-grow: 1;">
                <div style="font-weight: 600; color: #333; margin-bottom: 5px;">
                    <i class="bi bi-bell-fill" style="font-size: 0.9rem;"></i> 
                    ${count > 1 ? count + ' New Notifications' : 'New Notification'}
                </div>
                <div style="font-weight: 500; color: #555; margin-bottom: 3px; font-size: 0.95rem;">
                    ${escapeHtml(latestNotif.title)}
                </div>
                <div style="color: #777; font-size: 0.85rem;">
                    ${escapeHtml(latestNotif.message.substring(0, 80))}${latestNotif.message.length > 80 ? '...' : ''}
                </div>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: none; border: none; font-size: 1.2rem; color: #999; cursor: pointer; padding: 0; flex-shrink: 0;">
                <i class="bi bi-x"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(popup);
    
    // Auto-remove after 6 seconds
    setTimeout(() => {
        if (popup.parentElement) {
            popup.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => popup.remove(), 300);
        }
    }, 6000);
}

function updateNotificationDropdown(notifications) {
    const dropdownMenu = document.querySelector('.notification-dropdown');
    if (!dropdownMenu) return;
    
    // Get the notification list container (after header and divider)
    const notifList = dropdownMenu.querySelectorAll('li');
    
    // Find where notifications start (after the "Mark all read" header)
    let startIndex = 0;
    for (let i = 0; i < notifList.length; i++) {
        if (notifList[i].querySelector('.dropdown-divider')) {
            startIndex = i + 1;
            break;
        }
    }
    
    // Clear old notifications
    for (let i = startIndex; i < notifList.length - 2; i++) {
        if (notifList[i]) notifList[i].remove();
    }
    
    // Re-build notification list
    const baseUrl = '<?php echo SITE_URL . (isAdmin() ? "admin" : "user"); ?>/notifications.php';
    
    if (notifications.length > 0) {
        const divider = dropdownMenu.querySelector('.dropdown-divider');
        
        notifications.forEach(notif => {
            const li = document.createElement('li');
            const iconClass = getNotificationIcon(notif.type);
            const iconColor = getNotificationColor(notif.type);
            
            li.innerHTML = `
                <a class="dropdown-item ${notif.is_read == 0 ? 'bg-light' : ''}" 
                   href="${baseUrl}?read=${notif.notification_id}">
                    <div class="d-flex align-items-start">
                        <div class="me-2">
                            <i class="bi ${iconClass}" style="color: ${iconColor}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <strong>${escapeHtml(notif.title)}</strong>
                            <p class="mb-1 small text-muted">
                                ${escapeHtml(notif.message.substring(0, 80))}${notif.message.length > 80 ? '...' : ''}
                            </p>
                            <small class="text-muted">
                                <i class="bi bi-clock"></i> ${formatRelativeTime(notif.created_at)}
                            </small>
                        </div>
                        ${notif.is_read == 0 ? '<div class="ms-2"><span class="badge bg-primary rounded-pill">New</span></div>' : ''}
                    </div>
                </a>
            `;
            
            divider.parentNode.insertBefore(li, divider.nextSibling);
            
            const hr = document.createElement('li');
            hr.innerHTML = '<hr class="dropdown-divider">';
            li.parentNode.insertBefore(hr, li.nextSibling);
        });
    }
}

function getNotificationIcon(type) {
    switch(type) {
        case 'success': return 'bi-check-circle-fill';
        case 'warning': return 'bi-exclamation-triangle-fill';
        case 'danger': return 'bi-x-circle-fill';
        default: return 'bi-info-circle-fill';
    }
}

function getNotificationColor(type) {
    switch(type) {
        case 'success': return '#28a745';
        case 'warning': return '#ffc107';
        case 'danger': return '#dc3545';
        default: return '#17a2b8';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatRelativeTime(datetime) {
    const date = new Date(datetime);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return diffMins + ' min ago';
    if (diffHours < 24) return diffHours + ' hour' + (diffHours > 1 ? 's' : '') + ' ago';
    return diffDays + ' day' + (diffDays > 1 ? 's' : '') + ' ago';
}

// Add CSS animations
const notifStyle = document.createElement('style');
notifStyle.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(notifStyle);

// Initialize notification checking
document.addEventListener('DOMContentLoaded', function() {
    // Check immediately on load
    updateNotificationBadge();
    
    // Then check every 15 seconds
    notificationCheckInterval = setInterval(updateNotificationBadge, 15000);
    
    // Check when tab becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateNotificationBadge();
        }
    });
});

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    if (notificationCheckInterval) {
        clearInterval(notificationCheckInterval);
    }
});


</script>
</body>
</html>