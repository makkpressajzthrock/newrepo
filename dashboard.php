<?php
require_once 'classes/User.php';
require_once 'classes/Monitor.php';
require_once 'classes/PlanManager.php';

// Require authentication
User::requireAuth();

$currentUser = User::getCurrentUser();
$monitor = new Monitor();
$planManager = new PlanManager();

// Get user's plan information
$userPlan = $planManager->getUserPlan($currentUser['id']);
$canAddMonitor = $planManager->canAddMonitor($currentUser['id']);
$upgradeMessage = $planManager->getUpgradeMessage($currentUser['id']);

// Check if free plan has expired (24 hours)
$isFreePlanExpired = false;
$hoursRemaining = 0;

if ($userPlan && $userPlan['plan_type'] === 'free') {
    $hoursRemaining = $planManager->getHoursRemaining($userPlan);
    $isFreePlanExpired = $hoursRemaining <= 0;
}

$userMonitors = $monitor->getUserMonitors($currentUser['id']);

$error = '';
$success = '';

// Handle monitor actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_monitor') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $checkInterval = (int)($_POST['check_interval'] ?? 300);
        $timeout = (int)($_POST['timeout'] ?? 30);
        $monitorTypes = $_POST['monitor_type'] ?? [];
        
        if (empty($name) || empty($url) || empty($monitorTypes)) {
            $error = 'Please fill in all required fields and select at least one monitor type';
        } elseif (!$canAddMonitor) {
            $error = $upgradeMessage;
        } else {
            // Handle "all" selection
            if (in_array('all', $monitorTypes)) {
                $monitorTypes = [1, 2, 3, 4, 5]; // All monitor types
            } else {
                // Convert to integers and validate
                $monitorTypes = array_map('intval', $monitorTypes);
                $monitorTypes = array_filter($monitorTypes, function($type) {
                    return $type >= 1 && $type <= 5;
                });
            }
            
            $successCount = 0;
            $errorMessages = [];
            
            // Create monitors for each selected type
            foreach ($monitorTypes as $monitorType) {
                $typeName = [
                    1 => 'HTTP/HTTPS',
                    2 => 'DNS',
                    3 => 'SSL Certificate', 
                    4 => 'Domain Expiry',
                    5 => 'Performance'
                ][$monitorType];
                
                $monitorName = count($monitorTypes) > 1 ? "$name ($typeName)" : $name;
                
                $result = $monitor->addMonitor($currentUser['id'], $monitorType, $monitorName, $url, $checkInterval, $timeout);
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorMessages[] = "Failed to create $typeName monitor: " . $result['message'];
                }
            }
            
            if ($successCount > 0) {
                $success = "Successfully created $successCount monitor(s)!";
                if (!empty($errorMessages)) {
                    $success .= " (Some monitors failed to create)";
                }
                // Refresh monitors list
                $userMonitors = $monitor->getUserMonitors($currentUser['id']);
                
                // Update plan status after adding monitor
                $canAddMonitor = $planManager->canAddMonitor($currentUser['id']);
                $upgradeMessage = $planManager->getUpgradeMessage($currentUser['id']);
            }
            
            if (!empty($errorMessages)) {
                $error = implode('; ', $errorMessages);
            }
        }
    }
}

// Calculate overall statistics
$totalMonitors = count($userMonitors);
$upMonitors = 0;
$downMonitors = 0;
$warningMonitors = 0;

foreach ($userMonitors as $mon) {
    switch ($mon['last_status']) {
        case 'up':
            $upMonitors++;
            break;
        case 'down':
            $downMonitors++;
            break;
        case 'warning':
            $warningMonitors++;
            break;
    }
}
?>

    <!-- Header -->
    <?php include 'includes/header.php'; ?>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid p-4">
        <!-- Notifications -->
        <?php 
        require_once 'includes/notifications.php';
        NotificationManager::showNotifications();
        ?>
        
        <!-- Plan Status Banner -->
        <?php if ($userPlan && $userPlan['plan_type'] === 'free' && !$isFreePlanExpired): ?>
            <?php 
            $hoursRemaining = $planManager->getHoursRemaining($userPlan);
            $isExpiringSoon = $hoursRemaining <= 6; // Show warning if 6 hours or less remaining
            ?>
            <div class="alert alert-<?php echo $isExpiringSoon ? 'warning' : 'info'; ?> alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <i class="fas fa-<?php echo $isExpiringSoon ? 'clock' : 'gift'; ?> fa-2x"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-2">
                            <?php if ($isExpiringSoon): ?>
                                ⏰ Free Trial Expiring Soon!
                            <?php else: ?>
                                🎁 Free Trial Active
                            <?php endif; ?>
                        </h5>
                        <p class="mb-2">
                            <?php if ($isExpiringSoon): ?>
                                Your free trial expires in <strong><?php echo $hoursRemaining; ?> hours</strong>. 
                                Upgrade now to continue monitoring your websites without interruption.
                            <?php else: ?>
                                You're currently on the <strong>Free Trial</strong> plan. 
                                You can monitor <strong>1 website</strong> for <strong><?php echo $hoursRemaining; ?> more hours</strong>.
                            <?php endif; ?>
                        </p>
                        <div class="mt-3">
                            <a href="plans.php" class="btn btn-<?php echo $isExpiringSoon ? 'warning' : 'primary'; ?> btn-sm me-2">
                                <i class="fas fa-crown"></i> <?php echo $isExpiringSoon ? 'Upgrade Now' : 'View Plans'; ?>
                            </a>
                            <?php if (!$isExpiringSoon): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#planDetailsModal">
                                    <i class="fas fa-info-circle"></i> Plan Details
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Free Plan Expired Warning -->
        <?php if ($isFreePlanExpired): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-2">⚠️ Free Trial Expired</h5>
                        <p class="mb-2">
                            Your 24-hour free trial has ended. Manual checks are now disabled. 
                            <a href="plans.php" class="alert-link">Upgrade your plan</a> to continue using all features.
                        </p>
                        <div class="mt-3">
                            <a href="plans.php" class="btn btn-danger btn-sm">
                                <i class="fas fa-crown"></i> Upgrade Now
                            </a>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Enhanced Welcome Section -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="welcome-alert alert alert-<?php echo User::isSuperAdmin() ? 'warning' : 'info'; ?> border-0 fade-in-up" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="welcome-icon">
                                <i class="fas fa-<?php echo User::isSuperAdmin() ? 'shield-alt' : 'store'; ?> fa-2x"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <h4 class="alert-heading mb-2">
                                Welcome back, <?php echo htmlspecialchars($currentUser['first_name']); ?>! 👋
                            </h4>
                            <p class="mb-2">
                                <?php if (User::isSuperAdmin()): ?>
                                    You're logged in as <strong>Super Administrator</strong>. You have full access to manage users, system settings, and all monitoring data.
                                <?php else: ?>
                                    You're logged in as <strong>Store Owner</strong>. You can monitor your websites and manage your monitoring settings.
                                <?php endif; ?>
                            </p>
                            <?php if (User::isSuperAdmin()): ?>
                                <div class="mt-3">
                                    <a href="admin/users.php" class="btn btn-outline-warning btn-sm me-2">
                                        <i class="fas fa-users"></i> Manage Users
                                    </a>
                                    <a href="admin/system-stats.php" class="btn btn-outline-warning btn-sm">
                                        <i class="fas fa-chart-line"></i> System Statistics
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Statistics Cards -->
        <div class="stats-grid mb-5">
            <div class="card stat-card fade-in-up" style="animation-delay: 0.1s;">
                <div class="card-body text-center p-4">
                    <div class="stat-icon">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="stat-number"><?php echo $totalMonitors; ?></div>
                    <div class="stat-label">Total Monitors</div>
                </div>
            </div>
            
            <div class="card stat-card success fade-in-up" style="animation-delay: 0.2s;">
                <div class="card-body text-center p-4">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $upMonitors; ?></div>
                    <div class="stat-label">Online</div>
                </div>
            </div>
            
            <div class="card stat-card danger fade-in-up" style="animation-delay: 0.3s;">
                <div class="card-body text-center p-4">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $downMonitors; ?></div>
                    <div class="stat-label">Offline</div>
                </div>
            </div>
            
            <div class="card stat-card warning fade-in-up" style="animation-delay: 0.4s;">
                <div class="card-body text-center p-4">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-number"><?php echo $warningMonitors; ?></div>
                    <div class="stat-label">Warning</div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Enhanced Monitors Section -->
        <div class="section-header fade-in-up" style="animation-delay: 0.5s;">
            <h3><i class="fas fa-globe"></i> Monitor Management</h3>
            <button class="btn btn-outline-primary" onclick="refreshMonitors()">
                <i class="fas fa-sync-alt"></i> Refresh All
            </button>
        </div>
        
        <div class="row">
            <!-- Enhanced Add Monitor Card -->
            <div class="col-lg-4 mb-4">
                <div class="card monitor-card h-100 fade-in-up" style="animation-delay: 0.6s;">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus"></i> Add New Monitor</h5>
                        <?php if ($userPlan): ?>
                            <small class="text-muted">
                                <?php echo $planManager->getCurrentMonitorCount($currentUser['id']); ?>/<?php echo $userPlan['monitor_limit'] === -1 ? '∞' : $userPlan['monitor_limit']; ?> monitors
                                <?php if ($userPlan['days_remaining'] > 0): ?>
                                    • <?php echo $userPlan['days_remaining']; ?> days remaining
                                <?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($canAddMonitor): ?>
                            <form method="POST" action="" id="addMonitorForm">
                                <input type="hidden" name="action" value="add_monitor">
                                
                                <div class="mb-3">
                                    <label for="name" class="form-label">Monitor Name</label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           placeholder="My Website" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="url" class="form-label">URL</label>
                                    <input type="url" class="form-control" id="url" name="url" 
                                           placeholder="https://example.com" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="monitor_type" class="form-label">Monitor Types 
                                        <small class="text-muted">(Select multiple types)</small>
                                    </label>
                                    <select class="form-select" id="monitor_type" name="monitor_type[]" multiple size="6" required>
                                        <option value="all" id="select_all" style="background-color: #e3f2fd; font-weight: bold;">
                                            📊 All Monitor Types
                                        </option>
                                        <option value="1">🌐 HTTP/HTTPS Monitoring</option>
                                        <option value="2">🔍 DNS Resolution</option>
                                        <option value="3">🔒 SSL Certificate</option>
                                        <option value="4">📅 Domain Expiry</option>
                                        <option value="5">⚡ Performance Metrics</option>
                                    </select>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle"></i> 
                                        Hold Ctrl (Cmd on Mac) to select multiple types, or choose "All Monitor Types" for comprehensive monitoring.
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="check_interval" class="form-label">Check Interval</label>
                                            <select class="form-select" id="check_interval" name="check_interval">
                                                <option value="60">1 minute</option>
                                                <option value="300" selected>5 minutes</option>
                                                <option value="600">10 minutes</option>
                                                <option value="1800">30 minutes</option>
                                                <option value="3600">1 hour</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="timeout" class="form-label">Timeout (s)</label>
                                            <input type="number" class="form-control" id="timeout" name="timeout" 
                                                   value="30" min="5" max="120">
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-plus"></i> Add Monitor
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-lock fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Plan Limit Reached</h5>
                                <p class="text-muted mb-3"><?php echo $upgradeMessage; ?></p>
                                <a href="plans.php" class="btn btn-primary">
                                    <i class="fas fa-crown"></i> Upgrade Plan
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Enhanced Monitors List -->
            <div class="col-lg-8">
                <div class="card monitor-card fade-in-up" style="animation-delay: 0.7s;">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Your Monitors</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($userMonitors)): ?>
                            <div class="empty-state">
                                <i class="fas fa-globe"></i>
                                <h4>No monitors yet</h4>
                                <p>Add your first monitor to get started with uptime monitoring!</p>
                                <button class="btn btn-primary" onclick="document.getElementById('name').focus()">
                                    <i class="fas fa-plus"></i> Add Your First Monitor
                                </button>
                            </div>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($userMonitors as $mon): ?>
                                    <div class="list-group-item monitor-item <?php echo $mon['last_status'] ?? 'unknown'; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1">
                                                    <?php echo htmlspecialchars($mon['name']); ?>
                                                    <span class="badge bg-<?php 
                                                        echo $mon['last_status'] === 'up' ? 'success' : 
                                                             ($mon['last_status'] === 'down' ? 'danger' : 'warning'); 
                                                    ?> status-badge ms-2">
                                                        <?php echo strtoupper($mon['last_status'] ?? 'UNKNOWN'); ?>
                                                    </span>
                                                </h6>
                                                <p class="mb-1 text-truncate">
                                                    <i class="fas fa-globe me-1"></i>
                                                    <a href="<?php echo htmlspecialchars($mon['url']); ?>" target="_blank" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($mon['url']); ?>
                                                    </a>
                                                </p>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($mon['last_response_time']): ?>
                                                        <span class="response-time me-3">
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?php echo $mon['last_response_time']; ?>ms
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($mon['last_checked']): ?>
                                                        <span class="last-checked">
                                                            <i class="fas fa-history me-1"></i>
                                                            <?php echo date('M j, g:i A', strtotime($mon['last_checked'])); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="dropdown">
                                                <button class="btn btn-outline-secondary btn-sm" type="button" 
                                                        data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="monitor-details.php?id=<?php echo $mon['id']; ?>">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </a></li>
                                                    <?php if ($isFreePlanExpired): ?>
                                                        <li><a class="dropdown-item text-muted" href="#" onclick="showUpgradeMessage(event)" title="Upgrade your plan to continue using manual checks">
                                                            <i class="fas fa-lock"></i> Check Now (Upgrade Required)
                                                        </a></li>
                                                    <?php else: ?>
                                                        <li><a class="dropdown-item" href="#" onclick="checkNow(<?php echo $mon['id']; ?>, event)">
                                                            <i class="fas fa-play"></i> Check Now
                                                        </a></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Notification functions (inline to ensure they're available)
        function showNotification(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '9999';
            alertDiv.style.minWidth = '300px';
            alertDiv.style.maxWidth = '500px';
            
            const iconMap = {
                'success': 'fas fa-check-circle',
                'danger': 'fas fa-exclamation-circle', 
                'warning': 'fas fa-exclamation-triangle',
                'info': 'fas fa-info-circle'
            };
            
            alertDiv.innerHTML = `
                <i class='${iconMap[type] || iconMap.info}'></i> ${message}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            `;
            
            document.body.appendChild(alertDiv);
            
            // Auto-dismiss after 4 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.classList.remove('show');
                    setTimeout(() => alertDiv.remove(), 150);
                }
            }, 4000);
        }
        
        function showSuccess(message) { showNotification(message, 'success'); }
        function showError(message) { showNotification(message, 'danger'); }
        function showWarning(message) { showNotification(message, 'warning'); }
        function showInfo(message) { showNotification(message, 'info'); }

        // Refresh monitors
        function refreshMonitors() {
            location.reload();
        }

        // Check monitor now - FIXED VERSION
        function checkNow(monitorId, evt) {
            // Prevent default action and get the clicked element
            if (evt) {
                evt.preventDefault();
                evt.stopPropagation();
            }
            
            const clickedElement = evt ? (evt.target.closest('a') || evt.target.closest('button')) : null;
            const originalText = clickedElement ? clickedElement.innerHTML : '';
            
            if (clickedElement) {
                clickedElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
                clickedElement.onclick = null; // Prevent multiple clicks
            }

            fetch('api/check-monitor.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ monitor_id: monitorId })
            })
            .then(response => {
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const statusText = data.status ? (data.status.toUpperCase() + ' - ' + data.response_time + 'ms') : 'checked';
                    showSuccess('✅ Monitor checked successfully! Status: ' + statusText);
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showError('❌ Error: ' + (data.message || 'Check failed'));
                }
            })
            .catch(error => {
                console.error('Check error:', error);
                showError('❌ Network error. Please try again.');
            })
            .finally(() => {
                // Restore original state
                if (clickedElement) {
                    clickedElement.innerHTML = originalText;
                    clickedElement.onclick = (e) => checkNow(monitorId, e);
                }
            });
        }

        // Handle add monitor form submission
        document.addEventListener('DOMContentLoaded', function() {
            const addMonitorForm = document.getElementById('addMonitorForm');
            if (addMonitorForm) {
                addMonitorForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Show loading state
                    const submitBtn = addMonitorForm.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding Monitor...';
                    submitBtn.disabled = true;
                    
                    // Get form data
                    const formData = new FormData(addMonitorForm);
                    
                    // Submit form via AJAX
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(html => {
                        // Create a temporary div to parse the response
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = html;
                        
                        // Check if we can still add monitors
                        const canAddMonitor = !tempDiv.querySelector('.text-center.py-4 .text-muted') && 
                                            tempDiv.querySelector('#addMonitorForm');
                        
                        // Update the add monitor section
                        const addMonitorSection = document.querySelector('.col-lg-4 .card-body');
                        if (addMonitorSection) {
                            const newAddMonitorSection = tempDiv.querySelector('.col-lg-4 .card-body');
                            if (newAddMonitorSection) {
                                addMonitorSection.innerHTML = newAddMonitorSection.innerHTML;
                            }
                        }
                        
                        // Update monitors list
                        const monitorsList = document.querySelector('.col-lg-8 .list-group');
                        const newMonitorsList = tempDiv.querySelector('.col-lg-8 .list-group');
                        if (monitorsList && newMonitorsList) {
                            monitorsList.innerHTML = newMonitorsList.innerHTML;
                        }
                        
                        // Update statistics
                        const statsCards = document.querySelectorAll('.stat-card .stat-number');
                        const newStatsCards = tempDiv.querySelectorAll('.stat-card .stat-number');
                        if (statsCards.length === newStatsCards.length) {
                            statsCards.forEach((card, index) => {
                                if (newStatsCards[index]) {
                                    card.textContent = newStatsCards[index].textContent;
                                }
                            });
                        }
                        
                        // Update plan status banner if it exists
                        const planBanner = document.querySelector('.alert-info, .alert-warning');
                        const newPlanBanner = tempDiv.querySelector('.alert-info, .alert-warning');
                        if (planBanner && newPlanBanner) {
                            planBanner.innerHTML = newPlanBanner.innerHTML;
                        }
                        
                        // Show success message
                        if (canAddMonitor) {
                            showSuccess('Monitor added successfully! You can add more monitors.');
                        } else {
                            // Check if user is on free plan
                            const isFreePlan = tempDiv.querySelector('.alert-info') && 
                                             tempDiv.querySelector('.alert-info').textContent.includes('Free Trial');
                            if (isFreePlan) {
                                showSuccess('Monitor added successfully! You have reached your free trial limit (1 website). Upgrade to add more monitors.');
                            } else {
                                showSuccess('Monitor added successfully! You have reached your plan limit. Upgrade to add more monitors.');
                            }
                        }
                        
                        // Reset form if still can add monitors
                        if (canAddMonitor) {
                            addMonitorForm.reset();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showError('Error adding monitor. Please try again.');
                    })
                    .finally(() => {
                        // Restore button state
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    });
                });
            }
        });

        // Auto-refresh every 5 minutes
        setInterval(refreshMonitors, 300000);
        
        // Show upgrade message for expired free plan
        function showUpgradeMessage(evt) {
            evt.preventDefault();
            evt.stopPropagation();
            
            if (confirm('Your free trial has expired. Would you like to upgrade your plan to continue using manual checks?')) {
                window.location.href = 'plans.php';
            }
        }
    </script>

    <!-- Plan Details Modal -->
    <div class="modal fade" id="planDetailsModal" tabindex="-1" aria-labelledby="planDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="planDetailsModalLabel">
                        <i class="fas fa-crown"></i> Free Trial Plan Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-check text-success"></i> What's Included:</h6>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-globe text-primary"></i> 1 Website Monitoring</li>
                                <li><i class="fas fa-clock text-primary"></i> 24-Hour Duration</li>
                                <li><i class="fas fa-shield-alt text-primary"></i> All Monitor Types</li>
                                <li><i class="fas fa-bell text-primary"></i> Basic Notifications</li>
                                <li><i class="fas fa-chart-line text-primary"></i> Response Time Tracking</li>
                                <li><i class="fas fa-history text-primary"></i> Uptime History</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-times text-danger"></i> Limitations:</h6>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-lock text-muted"></i> Only 1 Website</li>
                                <li><i class="fas fa-calendar-times text-muted"></i> 24-Hour Limit</li>
                                <li><i class="fas fa-ban text-muted"></i> No Advanced Features</li>
                                <li><i class="fas fa-ban text-muted"></i> No SMS Notifications</li>
                                <li><i class="fas fa-ban text-muted"></i> No API Access</li>
                                <li><i class="fas fa-ban text-muted"></i> No Priority Support</li>
                            </ul>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <h6 class="text-primary">Ready to upgrade?</h6>
                        <p class="text-muted">Get unlimited monitoring with our premium plans!</p>
                        <a href="plans.php" class="btn btn-primary">
                            <i class="fas fa-crown"></i> View All Plans
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 