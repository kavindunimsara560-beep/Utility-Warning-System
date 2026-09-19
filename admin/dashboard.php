<?php
session_start();
// Redirect to login if the admin is not authenticated
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php?role=admin&msg=login_required&redirect=" . urlencode('admin/dashboard.php'));
    exit();
}

require '../config/db_connect.php';
require_once '../config/profile_helper.php';
ensure_profile_schema($conn);

$admin_id = (int)$_SESSION['admin_id'];
$admin_stmt = $conn->prepare("SELECT username, full_name, email, phone, profile_pic, created_at FROM admins WHERE admin_id = ?");
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();
$admin_display_name = !empty($admin_info['full_name']) ? $admin_info['full_name'] : ($admin_info['username'] ?? $_SESSION['admin_username'] ?? 'Admin');
$admin_profile_pic = $admin_info['profile_pic'] ?? null;

$message = "";
$message_type = "success";

// Handle GET messages (e.g. from redirects)
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'warning_deleted') {
        $message = "Outage warning notice has been removed.";
        $message_type = "success";
    } elseif ($_GET['msg'] === 'complaint_deleted') {
        $message = "Complaint record has been removed.";
        $message_type = "success";
    } elseif ($_GET['msg'] === 'status_updated') {
        $message = "Complaint status updated successfully.";
        $message_type = "success";
    } elseif ($_GET['msg'] === 'warning_updated') {
        $message = "Warning notice updated successfully.";
        $message_type = "success";
    } elseif ($_GET['msg'] === 'account_created') {
        $message = "Welcome! Your admin account was successfully created and you are now signed in.";
        $message_type = "success";
    }
}

// Handle form submission to add a new warning
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_warning'])) {
    $utility_type = trim($_POST['utility_type'] ?? 'Power');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color_code = trim($_POST['color_code'] ?? '#dc3545');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);
    $today_start = strtotime(date('Y-m-d 00:00:00'));

    if (empty($title) || empty($description) || empty($start_time) || empty($end_time)) {
        $message = "All warning fields are required.";
        $message_type = "danger";
    } elseif ($start_ts < $today_start) {
        $message = "Start Date cannot be in the past. Only today and future dates are allowed.";
        $message_type = "danger";
    } elseif ($end_ts < $start_ts) {
        $message = "Warning End Time must be the same as or after Start Time.";
        $message_type = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO warnings (utility_type, title, description, color_code, start_time, end_time, posted_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $utility_type, $title, $description, $color_code, $start_time, $end_time, $admin_id);
            if ($stmt->execute()) {
                $message = "Warning notice successfully published to residents!";
                $message_type = "success";
            } else {
                $message = "Error posting warning: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "Database error: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Handle deletion of a warning
if (isset($_GET['delete'])) {
    $warning_id = intval($_GET['delete']);
    $del_stmt = $conn->prepare("DELETE FROM warnings WHERE warning_id = ?");
    if ($del_stmt) {
        $del_stmt->bind_param("i", $warning_id);
        $del_stmt->execute();
        $del_stmt->close();
    }
    header("Location: dashboard.php?msg=warning_deleted");
    exit();
}

// Handle deletion of a complaint
if (isset($_GET['delete_complaint'])) {
    $complaint_id = intval($_GET['delete_complaint']);
    $del_stmt = $conn->prepare("DELETE FROM complaints WHERE complaint_id = ?");
    if ($del_stmt) {
        $del_stmt->bind_param("i", $complaint_id);
        $del_stmt->execute();
        $del_stmt->close();
    }
    header("Location: dashboard.php?msg=complaint_deleted");
    exit();
}

// Handle updating complaint status
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $new_status = trim($_POST['status'] ?? 'Pending Review');

    $update_stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE complaint_id = ?");
    if ($update_stmt) {
        $update_stmt->bind_param("si", $new_status, $complaint_id);
        $update_stmt->execute();
        $update_stmt->close();
    }

    // Insert a client notification for the status change, keyed to contact_info
    $ref_res = $conn->prepare("SELECT resident_name, contact_info, title FROM complaints WHERE complaint_id = ?");
    if ($ref_res) {
        $ref_res->bind_param("i", $complaint_id);
        $ref_res->execute();
        $comp_row = $ref_res->get_result()->fetch_assoc();
        $ref_res->close();
        if ($comp_row) {
            $notif_msg = "Your complaint #" . $complaint_id . " (\"" . mb_strimwidth($comp_row['title'], 0, 60, '...') . "\") status has been updated to: " . $new_status . ".";
            $notif_link = null;
            $client_ref = $comp_row['contact_info']; // client identified by contact_info
            $cn_stmt = $conn->prepare("INSERT INTO notifications (target_type, target_ref, message, link) VALUES ('client', ?, ?, ?)");
            if ($cn_stmt) {
                $cn_stmt->bind_param("sss", $client_ref, $notif_msg, $notif_link);
                $cn_stmt->execute();
                $cn_stmt->close();
            }
        }
    }

    header("Location: dashboard.php?msg=status_updated");
    exit();
}

// â”€â”€ Metrics & Data Queries for Unified Stat Cards & Tabs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$active_outages_res = $conn->query("SELECT COUNT(*) AS cnt FROM warnings WHERE end_time >= NOW()");
$active_outages_count = $active_outages_res ? (int)$active_outages_res->fetch_assoc()['cnt'] : 0;

$total_comp_res = $conn->query("SELECT COUNT(*) AS cnt FROM complaints");
$total_complaints_count = $total_comp_res ? (int)$total_comp_res->fetch_assoc()['cnt'] : 0;

$pending_comp_res = $conn->query("SELECT COUNT(*) AS cnt FROM complaints WHERE status LIKE '%Pending%' OR status LIKE '%Review%'");
$pending_complaints_count = $pending_comp_res ? (int)$pending_comp_res->fetch_assoc()['cnt'] : 0;

$admin_notif_count = 0;
$admin_notifs = [];
$anc_res = $conn->query("SELECT COUNT(*) AS cnt FROM notifications WHERE target_type='admin' AND is_read=0");
if ($anc_res) {
    $admin_notif_count = (int)($anc_res->fetch_assoc()['cnt'] ?? 0);
}
$an_res = $conn->query("SELECT notification_id, message, created_at, is_read FROM notifications WHERE target_type='admin' ORDER BY created_at DESC LIMIT 25");
if ($an_res) {
    while ($an = $an_res->fetch_assoc()) {
        $admin_notifs[] = $an;
    }
}

// Fetch all warnings (active/upcoming first)
$warnings_result = $conn->query("SELECT * FROM warnings ORDER BY (end_time >= NOW()) DESC, start_time DESC");
$all_warnings = $warnings_result ? $warnings_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch all complaints
$complaints_result = $conn->query("SELECT * FROM complaints ORDER BY complaint_id DESC");
$all_complaints = $complaints_result ? $complaints_result->fetch_all(MYSQLI_ASSOC) : [];

if (!function_exists('time_ago')) {
    function time_ago($ts) {
        $diff = time() - strtotime($ts);
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return (int)($diff/60) . 'm ago';
        if ($diff < 86400) return (int)($diff/3600) . 'h ago';
        return date('M d, Y', strtotime($ts));
    }
}

if (!function_exists('status_class')) {
    function status_class($s) {
        $s = strtolower($s);
        if (str_contains($s, 'resolve'))  return 'badge-status-resolved';
        if (str_contains($s, 'progress')) return 'badge-status-progress';
        if (str_contains($s, 'review') || str_contains($s, 'pending')) return 'badge-status-pending';
        return 'bg-secondary text-white';
    }
}

if (!function_exists('utility_icon')) {
    function utility_icon($type) {
        $t = strtolower($type);
        if (str_contains($t, 'power') || str_contains($t, 'electric')) return 'bi-lightning-charge-fill';
        if (str_contains($t, 'water')) return 'bi-droplet-fill';
        if (str_contains($t, 'road') || str_contains($t, 'transport')) return 'bi-cone-striped';
        if (str_contains($t, 'gas')) return 'bi-fire';
        if (str_contains($t, 'drain') || str_contains($t, 'waste') || str_contains($t, 'sewer')) return 'bi-recycle';
        return 'bi-exclamation-triangle-fill';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Console â€” Balangoda Utility System</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#2563eb">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- Bootstrap CSS + Bootstrap Icons (for existing modal/toast logic) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../css/dashboard.css">
</head>
<body>

<!-- ============================================================
     TOP NAVIGATION
============================================================ -->
<nav class="rd-nav" role="navigation" aria-label="Admin Portal Navigation">
    <div class="nav-inner">
        <!-- Brand -->
        <a href="dashboard.php" class="brand" aria-label="Balangoda Admin Console Home">
            <div class="brand-icon" aria-hidden="true" style="background:var(--text);">
                <i data-lucide="shield"></i>
            </div>
            <span class="brand-text d-none d-sm-inline">Balangoda <span>Admin Console</span></span>
        </a>

        <!-- Nav Links -->
        <ul class="nav-links" role="list">
            <li>
                <a href="../index.php" target="_blank" class="nav-link-item" aria-label="Go to Public Site">
                    <i data-lucide="external-link" aria-hidden="true"></i>
                    <span class="nav-link-text d-none d-md-inline">Public Site</span>
                </a>
            </li>
            <li>
                <a href="edit.php" class="nav-link-item" aria-label="My Profile">
                    <?php if (!empty($admin_profile_pic) && file_exists('../' . $admin_profile_pic)): ?>
                        <img src="../<?= htmlspecialchars($admin_profile_pic) ?>" alt="" class="nav-avatar" aria-hidden="true">
                    <?php else: ?>
                        <i data-lucide="user-cog" aria-hidden="true"></i>
                    <?php endif; ?>
                    <span class="nav-link-text d-none d-md-inline">My Profile</span>
                </a>
            </li>
            <li>
                <a href="logout.php" class="nav-link-item danger" aria-label="Logout">
                    <i data-lucide="log-out" aria-hidden="true"></i>
                    <span class="nav-link-text d-none d-md-inline">Logout</span>
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- ============================================================
     PROFILE HERO
============================================================ -->
<div class="rd-hero">
    <div class="hero-inner">
        <div class="hero-left">
            <!-- Avatar -->
            <div class="avatar-wrap">
                <?php if (!empty($admin_profile_pic) && file_exists('../' . $admin_profile_pic)): ?>
                    <img src="../<?= htmlspecialchars($admin_profile_pic) ?>"
                         alt="<?= htmlspecialchars($admin_display_name) ?>'s profile picture" class="avatar-img">
                <?php else: ?>
                    <div class="avatar-initials" aria-hidden="true" style="background: linear-gradient(135deg, var(--text), var(--text-secondary));">
                        <?= mb_strtoupper(mb_substr($admin_display_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="hero-info">
                <div class="hero-name-row">
                    <h1><?= htmlspecialchars($admin_display_name) ?></h1>
                    <span class="badge-admin-role" role="status">
                        <i data-lucide="shield-check" aria-hidden="true"></i>
                        Municipal Administrator
                    </span>
                </div>
                <div class="hero-meta">
                    <span>
                        <i data-lucide="mail" aria-hidden="true"></i>
                        <?= htmlspecialchars($admin_info['email'] ?? 'No email set') ?>
                    </span>
                    <span>
                        <i data-lucide="building-2" aria-hidden="true"></i>
                        Balangoda Urban Council
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     FLASH MESSAGE
============================================================ -->
<?php if (!empty($message)): ?>
<div class="rd-flash">
    <div class="alert alert-<?= $message_type == 'danger' ? 'danger' : 'success' ?> alert-dismissible fade show shadow-sm"
         role="alert" style="border-radius:12px; font-size:0.88rem; display:flex; align-items:center; gap:10px;">
        <i data-lucide="<?= $message_type == 'danger' ? 'alert-triangle' : 'check-circle' ?>" style="width:18px;height:18px;"></i>
        <div><?= htmlspecialchars($message) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     MAIN PAGE
============================================================ -->
<main class="rd-page" role="main">

    <!-- â”€â”€ STATISTICS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div class="stats-grid" role="region" aria-label="Dashboard statistics">

        <!-- Active Outages -->
        <div class="stat-card-new <?= $active_outages_count > 0 ? 'danger-active' : '' ?>"
             role="article" aria-label="Active Outages: <?= $active_outages_count ?>">
            <div class="stat-icon-box <?= $active_outages_count > 0 ? '' : 'slate' ?>" aria-hidden="true">
                <i data-lucide="radio-tower"></i>
            </div>
            <div class="stat-number"><?= $active_outages_count ?></div>
            <div class="stat-label">Active Outages</div>
        </div>

        <!-- Total Complaints -->
        <div class="stat-card-new" role="article" aria-label="Total Complaints: <?= $total_complaints_count ?>">
            <div class="stat-icon-box blue" aria-hidden="true">
                <i data-lucide="clipboard-list"></i>
            </div>
            <div class="stat-number" style="color:var(--primary);"><?= $total_complaints_count ?></div>
            <div class="stat-label">Total Complaints</div>
        </div>

        <!-- Pending Review -->
        <div class="stat-card-new <?= $pending_complaints_count > 0 ? 'warning-active' : '' ?>" role="article" aria-label="Pending Review: <?= $pending_complaints_count ?>">
            <div class="stat-icon-box <?= $pending_complaints_count > 0 ? '' : 'amber' ?>" aria-hidden="true">
                <i data-lucide="clock-3"></i>
            </div>
            <div class="stat-number" style="<?= $pending_complaints_count == 0 ? 'color:var(--warning);' : '' ?>"><?= $pending_complaints_count ?></div>
            <div class="stat-label">Pending Review</div>
        </div>

        <!-- Unread Alerts -->
        <div class="stat-card-new <?= $admin_notif_count > 0 ? 'info-active' : '' ?>" role="article" aria-label="Unread Alerts: <?= $admin_notif_count ?>">
            <div class="stat-icon-box <?= $admin_notif_count > 0 ? '' : 'purple' ?>" aria-hidden="true">
                <i data-lucide="bell"></i>
            </div>
            <div class="stat-number" style="<?= $admin_notif_count == 0 ? 'color:var(--purple);' : '' ?>"><?= $admin_notif_count ?></div>
            <div class="stat-label">Unread Alerts</div>
        </div>

    </div><!-- /stats-grid -->


    <!-- â”€â”€ SERVICE STATUS BANNER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <?php if ($active_outages_count > 0): ?>
        <?php
        // Determine if any are ongoing (critical) vs scheduled (advisory)
        $now = time();
        $has_critical = false;
        foreach ($all_warnings as $w) {
            if (strtotime($w['start_time']) <= $now && strtotime($w['end_time']) > $now) {
                $has_critical = true;
                break;
            }
        }
        ?>
        <div class="service-status-banner <?= $has_critical ? 'has-critical' : 'has-outage' ?>"
             role="alert" aria-live="polite">
            <div class="status-icon-wrap <?= $has_critical ? 'red' : 'orange' ?>" aria-hidden="true">
                <i data-lucide="alert-triangle"></i>
            </div>
            <div>
                <h6 style="color:<?= $has_critical ? 'var(--danger)' : '#c2410c' ?>">
                    <?= $has_critical ? 'ACTIVE OUTAGE BULLETIN BROADCASTING' : 'SCHEDULED INTERRUPTION BROADCASTING' ?>
                </h6>
                <p>
                    <?= $active_outages_count ?> active outage notice<?= $active_outages_count > 1 ? 's' : '' ?> currently visible to residents.
                </p>
            </div>
        </div>
    <?php else: ?>
        <!-- All Clear -->
        <div class="service-status-banner all-clear" role="status" aria-live="polite">
            <div class="status-icon-wrap green" aria-hidden="true">
                <i data-lucide="shield-check"></i>
            </div>
            <div>
                <h6 style="color:var(--success);">All Municipal Utility Services Running Routinely</h6>
                <p>No active outage warnings are currently published to the public portal.</p>
            </div>
        </div>
    <?php endif; ?>


    <!-- â”€â”€ TWO-COLUMN LAYOUT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div class="rd-two-col">

        <!-- â”€â”€ LEFT: Main Content Area â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <div class="rd-main-col">

            <!-- Tabs -->
            <div class="rd-tabs" role="tablist" aria-label="Admin Dashboard sections">
                <button class="rd-tab-btn active"
                        id="tab-outages-btn"
                        role="tab"
                        aria-selected="true"
                        aria-controls="panel-outages"
                        onclick="switchTab('outages')">
                    <i data-lucide="radio-tower" aria-hidden="true"></i>
                    Outage Bulletins
                    <?php if ($active_outages_count > 0): ?>
                        <span class="rd-tab-badge" aria-label="<?= $active_outages_count ?> active outages">
                            <?= $active_outages_count ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="rd-tab-btn"
                        id="tab-complaints-btn"
                        role="tab"
                        aria-selected="false"
                        aria-controls="panel-complaints"
                        onclick="switchTab('complaints')">
                    <i data-lucide="message-square" aria-hidden="true"></i>
                    Resident Complaints
                    <?php if ($pending_complaints_count > 0): ?>
                        <span class="rd-tab-badge" aria-label="<?= $pending_complaints_count ?> pending complaints">
                            <?= $pending_complaints_count ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="rd-tab-btn"
                        id="tab-notifs-btn"
                        role="tab"
                        aria-selected="false"
                        aria-controls="panel-notifs"
                        onclick="switchTab('notifs')">
                    <i data-lucide="bell-ring" aria-hidden="true"></i>
                    Alerts &amp; Logs
                    <?php if ($admin_notif_count > 0): ?>
                        <span class="rd-tab-badge unread" aria-label="<?= $admin_notif_count ?> unread">
                            <?= $admin_notif_count ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Outages Panel -->
            <div id="panel-outages"
                 class="rd-tab-panel active"
                 role="tabpanel"
                 aria-labelledby="tab-outages-btn">

                <?php if (empty($all_warnings)): ?>
                <!-- Empty State -->
                <div class="rd-card">
                    <div class="empty-state">
                        <div class="empty-icon-wrap" aria-hidden="true">
                            <i data-lucide="shield-check"></i>
                        </div>
                        <h6>No Active or Scheduled Outage Notices</h6>
                        <p>There are currently no public utility interruptions affecting the Balangoda area.</p>
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('title').focus();" style="border-radius:var(--radius-md); font-weight:600; font-size:0.85rem; padding:0.6rem 1.2rem;">
                            <i data-lucide="megaphone" style="width:16px;height:16px;margin-right:5px;vertical-align:middle;"></i> Publish Outage Warning
                        </button>
                    </div>
                </div>

                <?php else: ?>
                <!-- Outage Cards Grid -->
                <div class="rd-card">
                    <div class="outage-cards-grid" role="region" aria-label="Published Outage Warnings">
                        <?php foreach ($all_warnings as $w): 
                            $now = time();
                            $s_ts = strtotime($w['start_time']);
                            $e_ts = strtotime($w['end_time']);
                            $is_ongoing = ($s_ts <= $now && $e_ts > $now);
                            $is_past = ($e_ts < $now);
                            $u_color = !empty($w['color_code']) ? htmlspecialchars($w['color_code']) : '#dc2626';
                            $u_type = htmlspecialchars($w['utility_type']);
                            
                            $t = strtolower($w['utility_type']);
                            $lu_icon = 'alert-circle';
                            if (str_contains($t,'power')||str_contains($t,'electric')) $lu_icon = 'zap';
                            elseif (str_contains($t,'water')) $lu_icon = 'droplets';
                            elseif (str_contains($t,'road')||str_contains($t,'transport')) $lu_icon = 'construction';
                            elseif (str_contains($t,'gas')) $lu_icon = 'flame';
                            elseif (str_contains($t,'drain')||str_contains($t,'waste')) $lu_icon = 'trash-2';
                        ?>
                        <div class="outage-card <?= $is_ongoing ? 'is-ongoing' : ($is_past ? 'opacity-75' : '') ?>"
                             style="--accent:<?= $u_color ?>;"
                             role="article">

                            <div class="outage-card-head">
                                <div class="utility-badge" style="background:<?= $u_color ?>;" aria-hidden="true">
                                    <i data-lucide="<?= $lu_icon ?>"></i>
                                    <?= strtoupper($u_type) ?>
                                </div>
                                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                    <?php if ($is_ongoing): ?>
                                        <span class="status-pill ongoing" role="status">
                                            <i data-lucide="circle" style="fill:currentColor;" aria-hidden="true"></i> ONGOING
                                        </span>
                                    <?php elseif ($is_past): ?>
                                        <span class="status-pill scheduled" role="status">
                                            <i data-lucide="check-circle" aria-hidden="true"></i> EXPIRED
                                        </span>
                                    <?php else: ?>
                                        <span class="status-pill new" role="status">
                                            <i data-lucide="clock" aria-hidden="true"></i> SCHEDULED
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="outage-card-body">
                                <h5><?= htmlspecialchars($w['title']) ?></h5>
                                <p><?= nl2br(htmlspecialchars($w['description'])) ?></p>
                            </div>

                            <div class="outage-card-footer">
                                <div class="outage-time-block">
                                    <i data-lucide="calendar" style="color:var(--text-secondary);" aria-hidden="true"></i>
                                    <div>
                                        <span class="time-label">Starts</span>
                                        <strong><?= date('M d, Y Â· h:i A', $s_ts) ?></strong>
                                    </div>
                                </div>
                                <a href="dashboard.php?delete=<?= $w['warning_id'] ?>" 
                                   class="btn-delete-outage" 
                                   onclick="return confirm('Are you sure you want to permanently delete this outage bulletin?');"
                                   title="Delete Bulletin" aria-label="Delete Outage Bulletin">
                                    <i data-lucide="trash-2"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div><!-- /panel-outages -->

            <!-- Complaints Panel -->
            <div id="panel-complaints"
                 class="rd-tab-panel"
                 role="tabpanel"
                 aria-labelledby="tab-complaints-btn">

                <div class="rd-card">
                    <div class="rd-card-header">
                        <div class="rd-card-header-title">
                            <i data-lucide="clipboard-list" aria-hidden="true"></i>
                            Resident Complaints Management
                        </div>
                    </div>
                    <?php if (empty($all_complaints)): ?>
                        <div class="empty-state py-5">
                            <p class="text-muted mb-0">No complaints have been submitted by residents yet.</p>
                        </div>
                    <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="complaints-tbl" role="table" aria-label="Resident complaints table">
                            <thead>
                                <tr>
                                    <th scope="col">Ref &amp; Date</th>
                                    <th scope="col">Resident &amp; Issue</th>
                                    <th scope="col">Utility</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_complaints as $c):
                                    $s = strtolower($c['status'] ?? '');
                                    $badge_class = 'status-default';
                                    if (str_contains($s,'resolve'))  $badge_class = 'status-resolved';
                                    elseif (str_contains($s,'progress')) $badge_class = 'status-progress';
                                    elseif (str_contains($s,'pending'))  $badge_class = 'status-pending';
                                    elseif (str_contains($s,'warn'))     $badge_class = 'status-warning';
                                    elseif (str_contains($s,'critical')) $badge_class = 'status-critical';

                                    $ct = strtolower($c['utility_type'] ?? '');
                                    $c_lucide = 'alert-circle';
                                    if (str_contains($ct,'power')||str_contains($ct,'electric')) $c_lucide = 'zap';
                                    elseif (str_contains($ct,'water')) $c_lucide = 'droplets';
                                    elseif (str_contains($ct,'road')) $c_lucide = 'construction';
                                    elseif (str_contains($ct,'gas')) $c_lucide = 'flame';
                                ?>
                                <tr>
                                    <td>
                                        <div class="complaint-ref">#<?= $c['complaint_id'] ?></div>
                                        <div class="complaint-time"><?= date('M d, Y', strtotime($c['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="complaint-title"><?= htmlspecialchars($c['title']) ?></div>
                                        <div class="complaint-desc">By: <?= htmlspecialchars($c['resident_name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="utility-type-badge">
                                            <i data-lucide="<?= $c_lucide ?>" aria-hidden="true"></i>
                                            <?= htmlspecialchars($c['utility_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" action="dashboard.php" class="d-flex align-items-center gap-2">
                                            <input type="hidden" name="complaint_id" value="<?= $c['complaint_id'] ?>">
                                            <select name="status" class="form-select form-select-sm" style="width:140px; font-size:0.75rem;">
                                                <option value="Pending Review" <?= $c['status']=='Pending Review'?'selected':'' ?>>Pending Review</option>
                                                <option value="In Progress" <?= $c['status']=='In Progress'?'selected':'' ?>>In Progress</option>
                                                <option value="Resolved" <?= $c['status']=='Resolved'?'selected':'' ?>>Resolved</option>
                                            </select>
                                            <button type="submit" name="update_status" class="btn-action-small" title="Update Status">
                                                <i data-lucide="save"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <a href="dashboard.php?delete_complaint=<?= $c['complaint_id'] ?>" 
                                           onclick="return confirm('Delete this complaint record permanently?');"
                                           class="btn-action-small danger" title="Delete Complaint">
                                            <i data-lucide="trash-2"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div><!-- /panel-complaints -->

            <!-- Notifications Panel -->
            <div id="panel-notifs"
                 class="rd-tab-panel"
                 role="tabpanel"
                 aria-labelledby="tab-notifs-btn">

                <div class="rd-card">
                    <div class="rd-card-header">
                        <div class="rd-card-header-title">
                            <i data-lucide="bell-ring" aria-hidden="true"></i>
                            System Alerts &amp; Activity Logs
                        </div>
                    </div>
                    
                    <?php if (empty($admin_notifs)): ?>
                    <div class="empty-state">
                        <div class="empty-icon-wrap" aria-hidden="true">
                            <i data-lucide="bell-off"></i>
                        </div>
                        <h6>No Alerts</h6>
                        <p>Your activity log is clean.</p>
                    </div>
                    <?php else: ?>
                    <div class="notif-list" role="list" aria-label="Admin Notifications list">
                        <?php foreach ($admin_notifs as $an): ?>
                        <div class="notif-row <?= $an['is_read'] ? '' : 'unread' ?>" role="listitem">
                            <div class="notif-icon-wrap" aria-hidden="true">
                                <i data-lucide="activity"></i>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div class="notif-message"><?= htmlspecialchars($an['message']) ?></div>
                                <div class="notif-time">
                                    <i data-lucide="clock" aria-hidden="true"></i>
                                    <?= time_ago($an['created_at']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div><!-- /panel-notifs -->

        </div><!-- /rd-main-col -->


        <!-- â”€â”€ RIGHT: Form Sidebar (Publish Outage) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <aside class="rd-sidebar" role="complementary" aria-label="Publish Outage Form">
            <div class="publish-form-card">
                <div class="publish-form-header">
                    <i data-lucide="megaphone"></i>
                    <h5>Publish Outage Warning</h5>
                </div>
                <div class="publish-form-body">
                    <form method="POST" action="dashboard.php">
                        
                        <div class="form-group">
                            <label class="form-label" for="utility_type">Utility Category</label>
                            <select class="form-control" name="utility_type" id="utility_type" required>
                                <option value="Electricity">âš¡ Electricity (CEB)</option>
                                <option value="Water">ðŸ’§ Water Supply (NWSDB)</option>
                                <option value="Roads">ðŸš§ Road Maintenance</option>
                                <option value="Waste">ðŸ—‘ï¸ Waste Management</option>
                                <option value="Other">ðŸ¢ Other Municipal Service</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="title">Bulletin Title</label>
                            <input type="text" class="form-control" name="title" id="title" required placeholder="e.g. Scheduled Power Cut">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">Details &amp; Affected Areas</label>
                            <textarea class="form-control" name="description" id="description" rows="3" required placeholder="Provide clear details on impacted zones..."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Severity Level</label>
                            <div class="severity-selector">
                                <label class="severity-option normal">
                                    <input type="radio" name="color_code" value="#16a34a">
                                    <span class="severity-label">
                                        <span class="severity-color-dot"></span> Normal
                                    </span>
                                </label>
                                <label class="severity-option advisory">
                                    <input type="radio" name="color_code" value="#d97706" checked>
                                    <span class="severity-label">
                                        <span class="severity-color-dot"></span> Advisory
                                    </span>
                                </label>
                                <label class="severity-option urgent">
                                    <input type="radio" name="color_code" value="#dc2626">
                                    <span class="severity-label">
                                        <span class="severity-color-dot"></span> Urgent
                                    </span>
                                </label>
                                <label class="severity-option critical">
                                    <input type="radio" name="color_code" value="#991b1b">
                                    <span class="severity-label">
                                        <span class="severity-color-dot"></span> Critical
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="start_time">Start Time</label>
                            <input type="datetime-local" class="form-control" name="start_time" id="start_time" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="end_time">Expected Restoration</label>
                            <input type="datetime-local" class="form-control" name="end_time" id="end_time" required>
                        </div>

                        <button type="submit" name="add_warning" class="btn-publish-outage mt-2">
                            <i data-lucide="megaphone" aria-hidden="true"></i> Broadcast Bulletin
                        </button>
                        
                    </form>
                </div>
            </div>
        </aside><!-- /rd-sidebar -->

    </div><!-- /rd-two-col -->

</main><!-- /rd-page -->


<!-- ============================================================
     SCRIPTS
============================================================ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // â”€â”€ Initialize Lucide Icons â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    lucide.createIcons();

    // â”€â”€ Custom Tab System â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    function switchTab(tab) {
        // Update buttons
        document.querySelectorAll('.rd-tab-btn').forEach(function(btn) {
            btn.classList.remove('active');
            btn.setAttribute('aria-selected', 'false');
        });
        document.getElementById('tab-' + tab + '-btn').classList.add('active');
        document.getElementById('tab-' + tab + '-btn').setAttribute('aria-selected', 'true');

        // Update panels
        document.querySelectorAll('.rd-tab-panel').forEach(function(panel) {
            panel.classList.remove('active');
        });
        var panel = document.getElementById('panel-' + tab);
        if (panel) {
            panel.classList.add('active');
            // Re-init Lucide in freshly-shown panel
            lucide.createIcons();
        }
    }
</script>

</body>
</html>


