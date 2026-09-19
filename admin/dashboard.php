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

// ── Metrics & Data Queries for Unified Stat Cards & Tabs ──────────────────
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
    <title>Administrator Console — Balangoda Utility System</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#d97706">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Notification bell dropdown in navbar */
        .notif-bell-btn {
            position: relative;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 10px;
            color: #fff;
            padding: 0.42rem 0.75rem;
            transition: background 0.18s;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
        }
        .notif-bell-btn:hover { background: rgba(255, 255, 255, 0.18); }
        .notif-badge {
            position: absolute;
            top: -5px; right: -5px;
            background: #ef4444; color: #fff;
            font-size: 0.65rem; font-weight: 700;
            border-radius: 50%; min-width: 18px; height: 18px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 3px; border: 2px solid #0f172a;
        }
        .notif-dropdown {
            min-width: 330px; max-height: 400px;
            overflow-y: auto; border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.12);
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.2);
            padding: 0;
        }
    </style>
</head>
<body class="theme-admin">

    <!-- Unified Top Navigation (Admin Theme) -->
    <nav class="portal-nav">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between">
                <a href="dashboard.php" class="brand">
                    <span class="brand-badge">⚡</span>
                    <span>Balangoda</span> Admin Console
                </a>
                <div class="d-flex align-items-center gap-2">
                    <!-- View Public Site -->
                    <a href="../index.php" target="_blank" class="nav-btn">
                        <i class="bi bi-box-arrow-up-right"></i>
                        <span class="d-none d-sm-inline">Public Site</span>
                    </a>

                    <!-- Notification Bell Dropdown -->
                    <div class="dropdown" id="notif-dropdown-container">
                        <button class="notif-bell-btn" id="notifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" title="System Notifications">
                            <i class="bi bi-bell-fill"></i>
                            <?php if ($admin_notif_count > 0): ?>
                                <span class="notif-badge" id="notif-badge"><?= $admin_notif_count ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notif-dropdown" aria-labelledby="notifBellBtn">
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                                <span class="fw-bold small text-dark">Administrative Alerts</span>
                                <button id="mark-all-read-btn" class="btn btn-link btn-sm p-0 text-warning text-decoration-none small fw-bold">Mark all read</button>
                            </div>
                            <div id="notif-list">
                                <?php if (empty($admin_notifs)): ?>
                                    <div class="text-center text-muted py-4 small"><i class="bi bi-bell-slash fs-4 d-block mb-2"></i>No notifications yet.</div>
                                <?php else: ?>
                                    <?php foreach ($admin_notifs as $an): ?>
                                        <div class="p-3 border-bottom <?= $an['is_read'] ? 'opacity-75' : 'bg-warning-subtle' ?>" style="font-size:0.83rem;">
                                            <div class="text-dark"><?= htmlspecialchars($an['message']) ?></div>
                                            <small class="text-muted"><?= time_ago($an['created_at']) ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Push permission button if available -->
                    <button id="enable-notif-btn" class="nav-btn" title="Enable browser notifications" onclick="requestNotifPermission()" style="display:none;">
                        <i class="bi bi-bell-slash text-warning"></i>
                    </button>

                    <!-- My Profile Link with Mini Avatar -->
                    <a href="profile.php" class="nav-btn">
                        <?php if (!empty($admin_profile_pic) && file_exists('../' . $admin_profile_pic)): ?>
                            <img src="../<?= htmlspecialchars($admin_profile_pic) ?>" alt="Avatar" class="nav-avatar-mini">
                        <?php else: ?>
                            <i class="bi bi-person-circle"></i>
                        <?php endif; ?>
                        <span class="d-none d-md-inline">My Profile</span>
                    </a>

                    <!-- Logout Button -->
                    <a href="logout.php" class="nav-btn danger">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="d-none d-sm-inline">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Unified Hero Strip (Admin Gold/Amber Accent) -->
    <div class="portal-hero">
        <div class="container-fluid px-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <?php if (!empty($admin_profile_pic) && file_exists('../' . $admin_profile_pic)): ?>
                        <img src="../<?= htmlspecialchars($admin_profile_pic) ?>" alt="Avatar" class="avatar-circle-img">
                    <?php else: ?>
                        <div class="avatar-circle"><?= mb_strtoupper(mb_substr($admin_display_name, 0, 1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <h2><?= htmlspecialchars($admin_display_name) ?></h2>
                            <span class="role-pill bg-warning-subtle text-warning border border-warning-subtle">
                                <i class="bi bi-shield-lock-fill me-1"></i>Municipal Administrator
                            </span>
                        </div>
                        <p>
                            <i class="bi bi-at"></i><?= htmlspecialchars($_SESSION['admin_username'] ?? 'admin') ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($admin_info['email'] ?? 'admin@balangoda.gov.lk') ?>
                            &nbsp;·&nbsp;
                            Balangoda Urban Council
                        </p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn-hero-action" onclick="document.getElementById('postWarningSection').scrollIntoView({behavior:'smooth'})">
                        <i class="bi bi-plus-circle-fill"></i>
                        <span>Publish Outage Warning</span>
                    </button>
                    <a href="profile.php" class="btn-hero-outline">
                        <i class="bi bi-person-gear"></i>
                        <span>Edit Profile</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Container -->
    <div class="container-fluid px-4 py-4">

        <!-- Flash Notice Message -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show shadow-sm mb-4" role="alert" style="border-radius:14px;">
                <strong><?= $message_type === 'success' ? 'Success:' : 'Notice:' ?></strong> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Unified Top 4 Metric Stat Cards (Identical layout to Resident Dashboard) -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="icon-wrap">📢</div>
                    <div class="num <?= $active_outages_count > 0 ? 'text-danger' : 'text-success' ?>"><?= $active_outages_count ?></div>
                    <div class="lbl">Active Outages</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="icon-wrap">📋</div>
                    <div class="num text-dark"><?= $total_complaints_count ?></div>
                    <div class="lbl">Total Complaints</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="icon-wrap">⏳</div>
                    <div class="num text-warning"><?= $pending_complaints_count ?></div>
                    <div class="lbl">Pending Review</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="icon-wrap">🔔</div>
                    <div class="num" style="color:#d97706;"><?= $admin_notif_count ?></div>
                    <div class="lbl">Unread Alerts</div>
                </div>
            </div>
        </div>

        <!-- Portal 2-Column Content Row (Main col-8 + Side col-4) -->
        <div class="row g-4">

            <!-- Main Column: Tabbed Outages, Complaints, and Notification Feeds -->
            <div class="col-lg-8">

                <!-- Unified Tab Navigation -->
                <ul class="nav portal-tabs" id="adminTabs">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-warnings">
                            <i class="bi bi-broadcast-pin"></i> Outage Bulletins
                            <?php if (count($all_warnings) > 0): ?>
                                <span class="badge bg-secondary-subtle text-dark ms-1"><?= count($all_warnings) ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-complaints">
                            <i class="bi bi-chat-left-dots"></i> Resident Complaints
                            <?php if ($total_complaints_count > 0): ?>
                                <span class="badge bg-warning-subtle text-dark ms-1"><?= $total_complaints_count ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-feed">
                            <i class="bi bi-bell"></i> Alerts &amp; Logs
                            <?php if ($admin_notif_count > 0): ?>
                                <span class="badge bg-danger ms-1"><?= $admin_notif_count ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- Tab 1: Outage Bulletins -->
                    <div class="tab-pane fade show active" id="tab-warnings">
                        <?php if (empty($all_warnings)): ?>
                            <div class="portal-card p-5 text-center text-muted">
                                <i class="bi bi-shield-check fs-1 text-success d-block mb-2"></i>
                                <h6 class="fw-bold text-dark">No Active or Scheduled Outage Notices</h6>
                                <p class="small text-muted mb-0">Use the form on the right panel to publish emergency or routine maintenance alerts.</p>
                            </div>
                        <?php else: ?>
                            <div class="portal-card overflow-hidden">
                                <div class="table-responsive">
                                    <table class="table portal-table align-middle">
                                        <thead>
                                            <tr>
                                                <th class="ps-3">Utility</th>
                                                <th>Title &amp; Notice Details</th>
                                                <th>Schedule Window</th>
                                                <th class="text-end pe-3">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_warnings as $w): 
                                                $now = time();
                                                $s_ts = strtotime($w['start_time']);
                                                $e_ts = strtotime($w['end_time']);
                                                $is_active = ($s_ts <= $now && $e_ts > $now);
                                                $u_icon = utility_icon($w['utility_type']);
                                                $u_color = !empty($w['color_code']) ? htmlspecialchars($w['color_code']) : '#d97706';
                                            ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <span class="badge" style="background: <?= $u_color ?>; color: #fff;">
                                                        <i class="bi <?= $u_icon ?> me-1"></i><?= htmlspecialchars($w['utility_type']) ?>
                                                    </span>
                                                    <?php if ($is_active): ?>
                                                        <span class="badge bg-danger rounded-pill ms-1">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-muted rounded-pill ms-1">Scheduled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($w['title']) ?></div>
                                                    <small class="text-muted d-block" style="max-width: 320px;">
                                                        <?= htmlspecialchars(mb_strimwidth($w['description'], 0, 75, '...')) ?>
                                                    </small>
                                                </td>
                                                <td class="small text-muted">
                                                    <div><i class="bi bi-play-circle text-primary me-1"></i><?= date('M d, g:i A', $s_ts) ?></div>
                                                    <div><i class="bi bi-stop-circle text-success me-1"></i><?= date('M d, g:i A', $e_ts) ?></div>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="edit.php?id=<?= $w['warning_id'] ?>" class="btn btn-outline-secondary" title="Edit Warning">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="dashboard.php?delete=<?= $w['warning_id'] ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this outage notice?');" title="Delete Warning">
                                                            <i class="bi bi-trash3"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab 2: Resident Complaints Review -->
                    <div class="tab-pane fade" id="tab-complaints">
                        <?php if (empty($all_complaints)): ?>
                            <div class="portal-card p-5 text-center text-muted">
                                <i class="bi bi-inbox fs-1 text-secondary d-block mb-2"></i>
                                <h6 class="fw-bold text-dark">No Resident Complaints Lodged</h6>
                                <p class="small text-muted mb-0">Incoming utility breakdown reports filed by residents will appear here for review and status updates.</p>
                            </div>
                        <?php else: ?>
                            <div class="portal-card overflow-hidden">
                                <div class="table-responsive">
                                    <table class="table portal-table align-middle">
                                        <thead>
                                            <tr>
                                                <th class="ps-3"># Ref</th>
                                                <th>Utility</th>
                                                <th>Issue &amp; Resident Details</th>
                                                <th>Status Update</th>
                                                <th class="text-end pe-3">Delete</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_complaints as $comp): 
                                                $cur_status = $comp['status'] ?? 'Pending Review';
                                            ?>
                                            <tr>
                                                <td class="ps-3 fw-bold text-secondary">#<?= $comp['complaint_id'] ?></td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <i class="bi <?= utility_icon($comp['utility_type']) ?> me-1"></i><?= htmlspecialchars($comp['utility_type']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($comp['title']) ?></div>
                                                    <div class="small text-muted"><?= htmlspecialchars(mb_strimwidth($comp['description'], 0, 70, '...')) ?></div>
                                                    <div class="small mt-1 text-secondary">
                                                        <i class="bi bi-person me-1"></i><strong><?= htmlspecialchars($comp['resident_name']) ?></strong> &bull;
                                                        <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($comp['contact_info']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <form method="POST" action="dashboard.php" class="d-flex gap-1 align-items-center">
                                                        <input type="hidden" name="complaint_id" value="<?= $comp['complaint_id'] ?>">
                                                        <select name="status" class="form-select form-select-sm" style="min-width: 145px; font-size:0.82rem;">
                                                            <option value="Pending Review" <?= $cur_status === 'Pending Review' ? 'selected' : '' ?>>Pending Review</option>
                                                            <option value="Warning Published" <?= $cur_status === 'Warning Published' ? 'selected' : '' ?>>Warning Published</option>
                                                            <option value="Repair in Progress" <?= $cur_status === 'Repair in Progress' ? 'selected' : '' ?>>Repair in Progress</option>
                                                            <option value="Resolved" <?= $cur_status === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                                                        </select>
                                                        <button type="submit" name="update_status" class="btn btn-sm btn-outline-dark fw-bold">Save</button>
                                                    </form>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <a href="dashboard.php?delete_complaint=<?= $comp['complaint_id'] ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this resident complaint record permanently?');">
                                                        <i class="bi bi-trash3"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab 3: Notifications Feed -->
                    <div class="tab-pane fade" id="tab-feed">
                        <?php if (empty($admin_notifs)): ?>
                            <div class="portal-card p-5 text-center text-muted">
                                <i class="bi bi-bell-slash fs-1 text-secondary d-block mb-2"></i>
                                <h6 class="fw-bold text-dark">No Notifications Recorded</h6>
                                <p class="small text-muted mb-0">System events and new resident reports will stream into this feed automatically.</p>
                            </div>
                        <?php else: ?>
                            <div class="portal-card p-3">
                                <?php foreach ($admin_notifs as $an): ?>
                                    <div class="notif-item <?= $an['is_read'] ? 'read' : '' ?>">
                                        <div style="font-size:.88rem; color:#1e293b;"><?= htmlspecialchars($an['message']) ?></div>
                                        <small class="text-muted" style="font-size:0.75rem;"><?= time_ago($an['created_at']) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

            <!-- Side Column: Post Outage Warning Form & Municipal Helplines -->
            <div class="col-lg-4">

                <!-- Post Warning Notice Form Card (Identical layout to Resident Side Cards) -->
                <div class="portal-card mb-4" id="postWarningSection">
                    <div class="portal-card-header">
                        <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                            <i class="bi bi-megaphone-fill text-warning"></i> Publish Outage Bulletin
                        </h6>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Broadcast</span>
                    </div>
                    <div class="portal-card-body">
                        <form method="POST" action="dashboard.php">
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark small">Utility Category <span class="text-danger">*</span></label>
                                <select name="utility_type" class="form-select form-select-sm" required>
                                    <option value="Power">⚡ Electricity (CEB)</option>
                                    <option value="Water">💧 Water Supply (NWSDB)</option>
                                    <option value="Road">🚧 Road &amp; Infrastructure</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark small">Bulletin Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control form-control-sm" placeholder="e.g. Emergency Feeder Maintenance" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark small">Details &amp; Affected Areas <span class="text-danger">*</span></label>
                                <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Specify towns, streets, reasons, safety warnings..." required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark small">Severity Color Code</label>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="color" name="color_code" class="form-control form-control-color" value="#dc3545" required>
                                    <small class="text-muted" style="font-size:0.75rem;">Red (#dc3545: Urgent) | Yellow (#f59e0b: Advisory)</small>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold text-dark small">Start Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" id="warn_start_time" name="start_time" class="form-control form-control-sm" min="<?= date('Y-m-d\T00:00') ?>" required>
                                    <div class="invalid-feedback" style="font-size:0.7rem;">Cannot be in past.</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold text-dark small">End Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" id="warn_end_time" name="end_time" class="form-control form-control-sm" min="<?= date('Y-m-d\T00:00') ?>" required>
                                    <div class="invalid-feedback" style="font-size:0.7rem;">Must be after start.</div>
                                </div>
                            </div>
                            <button type="submit" name="add_warning" class="btn btn-hero-action w-100 justify-content-center">
                                <i class="bi bi-broadcast"></i> Publish Notice to Residents
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Emergency Helplines & Municipal Directory Card (Identical layout to Resident Dashboard) -->
                <div class="portal-card">
                    <div class="portal-card-header">
                        <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                            <i class="bi bi-telephone-inbound-fill text-danger"></i> Municipal Dispatch Lines
                        </h6>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">24/7 Hotlines</span>
                    </div>
                    <div class="portal-card-body p-0">
                        <div class="list-group list-group-flush" style="font-size:0.85rem;">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <div>
                                    <i class="bi bi-lightning-charge-fill text-warning me-2"></i>
                                    <strong>Electricity (CEB Dispatch)</strong>
                                </div>
                                <span class="badge bg-light text-dark border fw-bold">1987</span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <div>
                                    <i class="bi bi-droplet-fill text-info me-2"></i>
                                    <strong>Water Board (NWSDB Hot)</strong>
                                </div>
                                <span class="badge bg-light text-dark border fw-bold">1939</span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <div>
                                    <i class="bi bi-building text-primary me-2"></i>
                                    <strong>Balangoda UC Head Office</strong>
                                </div>
                                <span class="badge bg-light text-dark border fw-bold">045-2287222</span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <div>
                                    <i class="bi bi-shield-fill text-danger me-2"></i>
                                    <strong>Balangoda Police Station</strong>
                                </div>
                                <span class="badge bg-light text-dark border fw-bold">119</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../js/pwa.js"></script>
    <script>
        // ── Show "Enable Notifications" button if permission not yet granted ──
        if ('Notification' in window && Notification.permission === 'default') {
            const el = document.getElementById('enable-notif-btn');
            if (el) el.style.display = '';
        }

        // ── Notification badge auto-poll (every 30s) ──────────────────
        const NOTIF_API = '../api/admin_notif_count.php';

        async function fetchNotifCount() {
            try {
                const res = await fetch(NOTIF_API, { credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                updateBadge(data.count);
            } catch (e) { }
        }

        function updateBadge(count) {
            const container = document.querySelector('#notif-dropdown-container .notif-bell-btn');
            if (!container) return;
            let badge = document.getElementById('notif-badge');
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.id = 'notif-badge';
                    badge.className = 'notif-badge';
                    container.appendChild(badge);
                }
                badge.textContent = count;
            } else {
                if (badge) badge.remove();
            }
        }

        setInterval(fetchNotifCount, 30000);

        // ── Mark all read ─────────────────────────────────────────────
        document.getElementById('mark-all-read-btn')?.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                const fd = new FormData();
                fd.append('action', 'mark_all_read');
                await fetch(NOTIF_API, { method: 'POST', body: fd, credentials: 'same-origin' });
                updateBadge(0);
                document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            } catch (e) { }
        });

        // ── Date-time dependency: Today & future only, and End Time >= Start Time ────────
        (function () {
            const startEl = document.getElementById('warn_start_time');
            const endEl = document.getElementById('warn_end_time');
            if (!startEl || !endEl) return;

            function getTodayMin() {
                const now = new Date();
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}T00:00`;
            }

            const todayMin = getTodayMin();
            startEl.min = todayMin;
            endEl.min = todayMin;

            function validateDates() {
                const startVal = startEl.value;
                const endVal = endEl.value;

                if (startVal && startVal < todayMin) {
                    startEl.classList.add('is-invalid');
                } else {
                    startEl.classList.remove('is-invalid');
                }

                const effectiveEndMin = (startVal && startVal > todayMin) ? startVal : todayMin;
                endEl.min = effectiveEndMin;

                if (endVal && startVal && endVal < startVal) {
                    endEl.classList.add('is-invalid');
                } else if (endVal && endVal < todayMin) {
                    endEl.classList.add('is-invalid');
                } else {
                    endEl.classList.remove('is-invalid');
                }
            }

            startEl.addEventListener('change', validateDates);
            startEl.addEventListener('input', validateDates);
            endEl.addEventListener('change', validateDates);
            endEl.addEventListener('input', validateDates);

            startEl.closest('form')?.addEventListener('submit', function (e) {
                validateDates();
                if (startEl.classList.contains('is-invalid') || endEl.classList.contains('is-invalid')) {
                    e.preventDefault();
                    if (startEl.classList.contains('is-invalid')) {
                        startEl.focus();
                    } else {
                        endEl.focus();
                    }
                }
            });
        })();
    </script>
</body>
</html>
