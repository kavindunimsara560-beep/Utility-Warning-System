<?php
session_start();
if (!isset($_SESSION['customer_id'])) {
    header("Location: ../login.php?role=customer&msg=login_required");
    exit();
}

require_once '../config/db_connect.php';
require_once '../config/profile_helper.php';
ensure_profile_schema($conn);

$customer_id    = (int)$_SESSION['customer_id'];

// Handle Profile / Residence Update from Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile_modal') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $elec_no   = trim($_POST['electricity_bill_no'] ?? '');
    $water_no  = trim($_POST['water_bill_no'] ?? '');

    $err = '';
    if (empty($phone) || empty($address)) {
        $err = "Contact phone number and residential address are required.";
    } elseif (empty($full_name)) {
        $err = "Full name cannot be empty.";
    }

    // Get current record to preserve or replace avatar
    $cur_stmt = $conn->prepare("SELECT profile_pic FROM customers WHERE customer_id = ?");
    $cur_stmt->bind_param("i", $customer_id);
    $cur_stmt->execute();
    $cur_row = $cur_stmt->get_result()->fetch_assoc();
    $cur_stmt->close();
    $avatar_path = $cur_row['profile_pic'] ?? null;

    if (empty($err) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_res = handle_avatar_upload($_FILES['avatar'], 'cust', $customer_id, $avatar_path);
        if ($upload_res['success']) {
            $avatar_path = $upload_res['path'];
        } else {
            $err = $upload_res['error'];
        }
    }

    if (empty($err)) {
        $upd = $conn->prepare("UPDATE customers SET full_name = ?, phone = ?, address = ?, electricity_bill_no = ?, water_bill_no = ?, profile_pic = ? WHERE customer_id = ?");
        $upd->bind_param("ssssssi", $full_name, $phone, $address, $elec_no, $water_no, $avatar_path, $customer_id);
        if ($upd->execute()) {
            $_SESSION['customer_name']   = $full_name;
            $_SESSION['customer_phone']  = $phone;
            $_SESSION['customer_avatar'] = $avatar_path;
            $upd->close();
            header("Location: dashboard.php?msg=profile_updated");
            exit();
        } else {
            $err = "Database error: " . $conn->error;
        }
        $upd->close();
    }

    if (!empty($err)) {
        $flash = ['type' => 'danger', 'text' => 'âš ï¸ ' . htmlspecialchars($err)];
    }
}

// Fetch full customer record
$crec = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
$crec->bind_param("i", $customer_id);
$crec->execute();
$cdata = $crec->get_result()->fetch_assoc();
$crec->close();

$customer_name  = htmlspecialchars($_SESSION['customer_name']  ?? ($cdata['full_name'] ?? 'Resident'));
$customer_email = htmlspecialchars($_SESSION['customer_email'] ?? ($cdata['email'] ?? ''));
$customer_phone = htmlspecialchars($_SESSION['customer_phone'] ?? ($cdata['phone'] ?? ''));

// Message
$msg_map = [
    'verified'          => ['type'=>'success', 'text'=>'ðŸŽ‰ Email verified successfully! Welcome to your portal.'],
    'already_verified'  => ['type'=>'info',    'text'=>'Your email is already verified.'],
    'profile_updated'   => ['type'=>'success', 'text'=>'âœ… Your profile and residence details have been updated successfully!'],
];
$flash = $flash ?? (isset($_GET['msg']) ? ($msg_map[$_GET['msg']] ?? null) : null);

// Fetch this customer's complaints (matched by phone or email)
$contact_search = '%' . ($cdata['phone'] ?? '') . '%';
$contact_email  = '%' . ($cdata['email'] ?? '')  . '%';
$complaints_res = $conn->prepare(
    "SELECT * FROM complaints WHERE contact_info LIKE ? OR contact_info LIKE ? ORDER BY complaint_id DESC"
);
$complaints_res->bind_param("ss", $contact_search, $contact_email);
$complaints_res->execute();
$complaints = $complaints_res->get_result()->fetch_all(MYSQLI_ASSOC);
$complaints_res->close();

// Unread notifications for this customer
$notif_res = $conn->prepare(
    "SELECT * FROM notifications WHERE target_type='client' AND (target_ref LIKE ? OR target_ref LIKE ?) ORDER BY created_at DESC LIMIT 20"
);
$notif_res->bind_param("ss", $contact_search, $contact_email);
$notif_res->execute();
$notifs = $notif_res->get_result()->fetch_all(MYSQLI_ASSOC);
$unread_count = count(array_filter($notifs, fn($n) => !$n['is_read']));
$notif_res->close();

// Mark notifications read
if (!empty($notifs)) {
    $mark = $conn->prepare("UPDATE notifications SET is_read=1 WHERE target_type='client' AND (target_ref LIKE ? OR target_ref LIKE ?) AND is_read=0");
    $mark->bind_param("ss", $contact_search, $contact_email);
    $mark->execute();
    $mark->close();
}

// Active and upcoming warnings (Ongoing first, then nearest start time)
$warnings_res = $conn->query("SELECT * FROM warnings WHERE end_time >= NOW() ORDER BY (start_time <= NOW()) DESC, start_time ASC");
$warnings     = $warnings_res ? $warnings_res->fetch_all(MYSQLI_ASSOC) : [];
$active_outage_count = count($warnings);

// Stats
$total_complaints  = count($complaints);
$resolved          = count(array_filter($complaints, fn($c) => stripos($c['status'], 'resolve') !== false));
$pending           = count(array_filter($complaints, fn($c) => stripos($c['status'], 'pending') !== false));

if (!function_exists('time_ago')) {
    function time_ago($ts) {
        $diff = time() - strtotime($ts);
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return (int)($diff/60)  . 'm ago';
        if ($diff < 86400) return (int)($diff/3600) . 'h ago';
        return date('M d, Y', strtotime($ts));
    }
}

if (!function_exists('status_class')) {
    function status_class($s) {
        $s = strtolower($s);
        if (str_contains($s, 'resolve'))  return 'bg-success';
        if (str_contains($s, 'progress')) return 'bg-info text-dark';
        if (str_contains($s, 'review'))   return 'bg-warning text-dark';
        return 'bg-secondary';
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
    <title>Resident Portal â€” Balangoda Municipal Utility System</title>
    <meta name="description" content="Resident dashboard for Balangoda Municipal Utility Warning System. Track outages, complaints, and notifications.">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#2563eb">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">

    <!-- Lucide Icons (SVG icon library) -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- Bootstrap CSS + Bootstrap Icons (keep existing for modal/tab JS) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../css/dashboard.css">
</head>
<body>

<!-- ============================================================
     TOP NAVIGATION
============================================================ -->
<nav class="rd-nav" role="navigation" aria-label="Resident Portal Navigation">
    <div class="nav-inner">
        <!-- Brand -->
        <a href="dashboard.php" class="brand" aria-label="Balangoda Resident Portal Home">
            <div class="brand-icon" aria-hidden="true">
                <i data-lucide="zap"></i>
            </div>
            <span class="brand-text d-none d-sm-inline">Balangoda <span>Resident Portal</span></span>
        </a>

        <!-- Nav Links -->
        <ul class="nav-links" role="list">
            <li>
                <a href="../index.php" class="nav-link-item" aria-label="Go to Public Site">
                    <i data-lucide="globe" aria-hidden="true"></i>
                    <span class="nav-link-text">Public Site</span>
                </a>
            </li>
            <li>
                <a href="profile.php" class="nav-link-item" aria-label="My Profile">
                    <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                        <img src="../<?= htmlspecialchars($cdata['profile_pic']) ?>" alt="" class="nav-avatar" aria-hidden="true">
                    <?php else: ?>
                        <i data-lucide="user-circle-2" aria-hidden="true"></i>
                    <?php endif; ?>
                    <span class="nav-link-text">My Profile</span>
                </a>
            </li>
            <li>
                <a href="logout.php" class="nav-link-item danger" aria-label="Logout">
                    <i data-lucide="log-out" aria-hidden="true"></i>
                    <span class="nav-link-text">Logout</span>
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
                <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                    <img src="../<?= htmlspecialchars($cdata['profile_pic']) ?>"
                         alt="<?= $customer_name ?>'s profile picture" class="avatar-img">
                <?php else: ?>
                    <div class="avatar-initials" aria-hidden="true">
                        <?= mb_strtoupper(mb_substr($cdata['full_name'] ?? 'R', 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="hero-info">
                <div class="hero-name-row">
                    <h1><?= $customer_name ?></h1>
                    <?php if ($cdata['email_verified']): ?>
                        <span class="badge-verified" role="status" aria-label="Verified Resident">
                            <i data-lucide="badge-check" aria-hidden="true"></i>
                            Verified Resident
                        </span>
                    <?php endif; ?>
                </div>
                <div class="hero-meta">
                    <span>
                        <i data-lucide="mail" aria-hidden="true"></i>
                        <?= $customer_email ?>
                    </span>
                    <span>
                        <i data-lucide="phone" aria-hidden="true"></i>
                        <?= $customer_phone ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="hero-actions">
            <button type="button"
                    class="btn-edit-profile"
                    data-bs-toggle="modal"
                    data-bs-target="#editProfileModal"
                    aria-label="Edit Profile and Residence Details">
                <i data-lucide="pencil" aria-hidden="true"></i>
                <span>Edit Profile / Residence</span>
            </button>

            <a href="../submit_complaint.php"
               class="btn-report-issue"
               aria-label="Report a Utility Issue">
                <i data-lucide="alert-triangle" aria-hidden="true"></i>
                <span>Report an Issue</span>
            </a>
        </div>
    </div>
</div>

<!-- ============================================================
     FLASH MESSAGE
============================================================ -->
<?php if ($flash): ?>
<div class="rd-flash">
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show shadow-sm"
         role="alert" style="border-radius:12px; font-size:0.88rem;">
        <?= $flash['text'] ?>
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
        <div class="stat-card-new <?= $active_outage_count > 0 ? 'danger-active' : 'safe-active' ?>"
             role="article" aria-label="Active Outages: <?= $active_outage_count ?>">
            <div class="stat-icon-box <?= $active_outage_count > 0 ? 'red' : 'green' ?>" aria-hidden="true">
                <i data-lucide="radio-tower"></i>
            </div>
            <div class="stat-number"><?= $active_outage_count ?></div>
            <div class="stat-label">Active Outages</div>
        </div>

        <!-- Total Complaints -->
        <div class="stat-card-new" role="article" aria-label="Total Complaints: <?= $total_complaints ?>">
            <div class="stat-icon-box blue" aria-hidden="true">
                <i data-lucide="clipboard-list"></i>
            </div>
            <div class="stat-number" style="color:var(--primary);"><?= $total_complaints ?></div>
            <div class="stat-label">Total Complaints</div>
        </div>

        <!-- Pending Review -->
        <div class="stat-card-new" role="article" aria-label="Pending Review: <?= $pending ?>">
            <div class="stat-icon-box amber" aria-hidden="true">
                <i data-lucide="clock-3"></i>
            </div>
            <div class="stat-number" style="color:var(--warning);"><?= $pending ?></div>
            <div class="stat-label">Pending Review</div>
        </div>

        <!-- New Notifications -->
        <div class="stat-card-new" role="article" aria-label="New Notifications: <?= $unread_count ?>">
            <div class="stat-icon-box purple" aria-hidden="true">
                <i data-lucide="bell"></i>
            </div>
            <div class="stat-number" style="color:var(--purple);"><?= $unread_count ?></div>
            <div class="stat-label">New Notifications</div>
        </div>

    </div><!-- /stats-grid -->


    <!-- â”€â”€ SERVICE STATUS BANNER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <?php if (!empty($warnings)): ?>
        <?php
        // Determine if any are ongoing (critical) vs scheduled (advisory)
        $now = time();
        $has_critical = false;
        foreach ($warnings as $w) {
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
                    <?= $has_critical ? 'Active Utility Outage in Progress' : 'Scheduled Utility Interruption' ?>
                </h6>
                <p>
                    <?= count($warnings) ?> outage notice<?= count($warnings) > 1 ? 's' : '' ?> affecting Balangoda area.
                    <?= $has_critical ? 'An active disruption is ongoing right now.' : 'Upcoming scheduled maintenance may affect services.' ?>
                    Scroll down for full details.
                </p>
            </div>
        </div>

        <!-- Outage Cards -->
        <div class="section-label">
            <div class="section-label-title">
                <div class="pulse-dot" aria-hidden="true"></div>
                <i data-lucide="broadcast" aria-hidden="true"></i>
                Active Outage Bulletins
            </div>
            <span class="outage-count-badge" role="status">
                <i data-lucide="zap" style="width:11px;height:11px;" aria-hidden="true"></i>
                <?= count($warnings) ?> Active
            </span>
        </div>

        <div class="outage-cards-grid" role="region" aria-label="Outage warning cards">
        <?php foreach ($warnings as $w):
            $s_ts = strtotime($w['start_time']);
            $e_ts = strtotime($w['end_time']);
            $is_ongoing = ($s_ts <= $now && $e_ts > $now);
            $is_new = (!empty($w['created_at']) && strtotime($w['created_at']) > ($now - 86400));
            $u_color = !empty($w['color_code']) ? htmlspecialchars($w['color_code']) : '#dc2626';
            $u_type = htmlspecialchars($w['utility_type']);
            // Pick Lucide icon name for utility
            $t = strtolower($w['utility_type']);
            $lu_icon = 'alert-circle';
            if (str_contains($t,'power')||str_contains($t,'electric')) $lu_icon = 'zap';
            elseif (str_contains($t,'water')) $lu_icon = 'droplets';
            elseif (str_contains($t,'road')||str_contains($t,'transport')) $lu_icon = 'construction';
            elseif (str_contains($t,'gas')) $lu_icon = 'flame';
            elseif (str_contains($t,'drain')||str_contains($t,'waste')) $lu_icon = 'trash-2';
        ?>
        <div class="outage-card <?= $is_ongoing ? 'is-ongoing' : '' ?>"
             style="--accent:<?= $u_color ?>;"
             role="article"
             aria-label="<?= $u_type ?> outage: <?= htmlspecialchars($w['title']) ?>">

            <div class="outage-card-head">
                <div class="utility-badge" style="background:<?= $u_color ?>;" aria-hidden="true">
                    <i data-lucide="<?= $lu_icon ?>"></i>
                    <?= strtoupper($u_type) ?>
                </div>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <?php if ($is_ongoing): ?>
                        <span class="status-pill ongoing" role="status">
                            <i data-lucide="circle" style="fill:currentColor;" aria-hidden="true"></i>
                            ONGOING
                        </span>
                    <?php else: ?>
                        <span class="status-pill scheduled" role="status">
                            <i data-lucide="clock" aria-hidden="true"></i>
                            SCHEDULED
                        </span>
                    <?php endif; ?>
                    <?php if ($is_new): ?>
                        <span class="status-pill new" aria-label="New warning">NEW</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="outage-card-body">
                <h5><?= htmlspecialchars($w['title']) ?></h5>
                <p><?= nl2br(htmlspecialchars($w['description'])) ?></p>
            </div>

            <div class="outage-card-footer">
                <div class="outage-time-block">
                    <i data-lucide="calendar" style="color:var(--primary);" aria-hidden="true"></i>
                    <div>
                        <span class="time-label">Starts</span>
                        <strong><?= date('M d, Y Â· h:i A', $s_ts) ?></strong>
                    </div>
                </div>
                <div class="outage-time-block">
                    <i data-lucide="calendar-check" style="color:var(--success);" aria-hidden="true"></i>
                    <div>
                        <span class="time-label">Expected Restoration</span>
                        <strong><?= date('M d, Y Â· h:i A', $e_ts) ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div><!-- /outage-cards-grid -->

    <?php else: ?>
        <!-- All Clear -->
        <div class="service-status-banner all-clear" role="status" aria-live="polite">
            <div class="status-icon-wrap green" aria-hidden="true">
                <i data-lucide="shield-check"></i>
            </div>
            <div>
                <h6 style="color:var(--success);">All Municipal Utility Services Running Routinely</h6>
                <p>No active electricity, water, or municipal infrastructure outage warnings published for Balangoda at this time. You will be notified if anything changes.</p>
            </div>
        </div>
    <?php endif; ?>


    <!-- â”€â”€ TWO-COLUMN LAYOUT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div class="rd-two-col">

        <!-- â”€â”€ LEFT: Complaints + Notifications â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <div class="rd-main-col">

            <!-- Tabs -->
            <div class="rd-tabs" role="tablist" aria-label="Dashboard sections">
                <button class="rd-tab-btn active"
                        id="tab-complaints-btn"
                        role="tab"
                        aria-selected="true"
                        aria-controls="panel-complaints"
                        onclick="switchTab('complaints')">
                    <i data-lucide="message-circle" aria-hidden="true"></i>
                    My Complaints
                    <?php if ($total_complaints > 0): ?>
                        <span class="rd-tab-badge" aria-label="<?= $total_complaints ?> complaints">
                            <?= $total_complaints ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="rd-tab-btn"
                        id="tab-notifs-btn"
                        role="tab"
                        aria-selected="false"
                        aria-controls="panel-notifs"
                        onclick="switchTab('notifs')">
                    <i data-lucide="bell" aria-hidden="true"></i>
                    Notifications & Updates
                    <?php if ($unread_count > 0): ?>
                        <span class="rd-tab-badge unread" aria-label="<?= $unread_count ?> unread">
                            <?= $unread_count ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Complaints Panel -->
            <div id="panel-complaints"
                 class="rd-tab-panel active"
                 role="tabpanel"
                 aria-labelledby="tab-complaints-btn">

                <?php if (empty($complaints)): ?>
                <!-- Empty State -->
                <div class="rd-card">
                    <div class="empty-state">
                        <div class="empty-icon-wrap" aria-hidden="true">
                            <i data-lucide="inbox"></i>
                        </div>
                        <h6>No Complaints Lodged Yet</h6>
                        <p>Experiencing a power cut, water disruption, or road issue? Let us know so municipal teams can respond quickly.</p>
                        <a href="../submit_complaint.php" class="btn-empty-action">
                            <i data-lucide="plus-circle" aria-hidden="true"></i>
                            Report an Issue Now
                        </a>
                    </div>
                </div>

                <?php else: ?>
                <!-- Complaints Table -->
                <div class="rd-card">
                    <div class="rd-card-header">
                        <div class="rd-card-header-title">
                            <i data-lucide="clipboard-list" aria-hidden="true"></i>
                            My Complaint History
                        </div>
                        <a href="../submit_complaint.php" class="btn-empty-action" style="font-size:0.78rem; padding:0.35rem 0.75rem;">
                            <i data-lucide="plus" aria-hidden="true"></i>
                            New Report
                        </a>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="complaints-tbl" role="table" aria-label="My complaints table">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Title & Description</th>
                                    <th scope="col">Utility</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($complaints as $c):
                                    $s = strtolower($c['status'] ?? '');
                                    $badge_class = 'status-default';
                                    if (str_contains($s,'resolve'))  $badge_class = 'status-resolved';
                                    elseif (str_contains($s,'progress')) $badge_class = 'status-progress';
                                    elseif (str_contains($s,'pending'))  $badge_class = 'status-pending';
                                    elseif (str_contains($s,'warn'))     $badge_class = 'status-warning';
                                    elseif (str_contains($s,'critical')) $badge_class = 'status-critical';

                                    // Utility icon
                                    $ct = strtolower($c['utility_type'] ?? '');
                                    $c_lucide = 'zap';
                                    if (str_contains($ct,'water')) $c_lucide = 'droplets';
                                    elseif (str_contains($ct,'road')) $c_lucide = 'construction';
                                    elseif (str_contains($ct,'gas')) $c_lucide = 'flame';
                                ?>
                                <tr>
                                    <td class="complaint-ref">#<?= $c['complaint_id'] ?></td>
                                    <td>
                                        <div class="complaint-title"><?= htmlspecialchars($c['title']) ?></div>
                                        <div class="complaint-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 72, '...')) ?></div>
                                    </td>
                                    <td>
                                        <span class="utility-type-badge">
                                            <i data-lucide="<?= $c_lucide ?>" aria-hidden="true"></i>
                                            <?= htmlspecialchars($c['utility_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $badge_class ?>" role="status">
                                            <?= htmlspecialchars($c['status']) ?>
                                        </span>
                                    </td>
                                    <td class="complaint-time"><?= time_ago($c['created_at']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div><!-- /panel-complaints -->

            <!-- Notifications Panel -->
            <div id="panel-notifs"
                 class="rd-tab-panel"
                 role="tabpanel"
                 aria-labelledby="tab-notifs-btn">

                <?php if (empty($notifs)): ?>
                <div class="rd-card">
                    <div class="empty-state">
                        <div class="empty-icon-wrap" aria-hidden="true">
                            <i data-lucide="bell-off"></i>
                        </div>
                        <h6>No Notifications Yet</h6>
                        <p>You will receive automatic alerts when municipal teams inspect or resolve your reported issues.</p>
                    </div>
                </div>

                <?php else: ?>
                <div class="rd-card">
                    <div class="rd-card-header">
                        <div class="rd-card-header-title">
                            <i data-lucide="bell" aria-hidden="true"></i>
                            Notifications & Updates
                        </div>
                        <?php if ($unread_count > 0): ?>
                            <span class="status-badge status-pending" role="status">
                                <?= $unread_count ?> unread
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list" role="list" aria-label="Notifications list">
                        <?php foreach ($notifs as $n): ?>
                        <div class="notif-row <?= $n['is_read'] ? '' : 'unread' ?>" role="listitem">
                            <div class="notif-icon-wrap" aria-hidden="true">
                                <i data-lucide="bell"></i>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div class="notif-message"><?= htmlspecialchars($n['message']) ?></div>
                                <div class="notif-time">
                                    <i data-lucide="clock" aria-hidden="true"></i>
                                    <?= time_ago($n['created_at']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div><!-- /panel-notifs -->

        </div><!-- /rd-main-col -->


        <!-- â”€â”€ RIGHT: Sidebar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <aside class="rd-sidebar" role="complementary" aria-label="Account and services sidebar">

            <!-- My Account / Registered Services -->
            <div class="sidebar-section">
                <div class="sidebar-head">
                    <div class="sidebar-head-title">
                        <i data-lucide="contact" aria-hidden="true"></i>
                        My Registered Services
                    </div>
                    <span class="sidebar-badge green" role="status">Active</span>
                </div>

                <!-- Contact info rows -->
                <div class="service-row">
                    <div class="service-icon-box slate" aria-hidden="true">
                        <i data-lucide="user"></i>
                    </div>
                    <div class="service-info">
                        <div class="service-name"><?= $customer_name ?></div>
                        <div class="service-number" style="text-transform:none; font-size:0.72rem; color:var(--muted);">Resident Name</div>
                    </div>
                </div>

                <div class="service-row">
                    <div class="service-icon-box slate" aria-hidden="true">
                        <i data-lucide="mail"></i>
                    </div>
                    <div class="service-info">
                        <div class="service-name" style="font-size:0.78rem;"><?= $customer_email ?></div>
                        <div class="service-number">Email Address</div>
                    </div>
                </div>

                <div class="service-row">
                    <div class="service-icon-box slate" aria-hidden="true">
                        <i data-lucide="phone"></i>
                    </div>
                    <div class="service-info">
                        <div class="service-name"><?= $customer_phone ?></div>
                        <div class="service-number">Contact Phone</div>
                    </div>
                </div>

                <div class="service-row">
                    <div class="service-icon-box slate" aria-hidden="true">
                        <i data-lucide="map-pin"></i>
                    </div>
                    <div class="service-info">
                        <div class="service-name" style="font-size:0.78rem; white-space:normal; line-height:1.4;">
                            <?= htmlspecialchars($cdata['address'] ?? 'Balangoda') ?>
                        </div>
                        <div class="service-number">Residential Address</div>
                    </div>
                </div>

                <!-- Utility Accounts (only if set) -->
                <?php if (!empty($cdata['electricity_bill_no']) || !empty($cdata['water_bill_no'])): ?>
                <div style="padding:0.55rem 1.15rem; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); font-weight:700; background:var(--surface-2); border-bottom:1px solid var(--border-subtle);">
                    Utility Accounts
                </div>

                <?php if (!empty($cdata['electricity_bill_no'])): ?>
                <div class="service-row" role="listitem" aria-label="Electricity account <?= htmlspecialchars($cdata['electricity_bill_no']) ?>">
                    <div class="service-icon-box amber" aria-hidden="true">
                        <i data-lucide="zap"></i>
                    </div>
                    <div class="service-info">
                        <div class="service-name">Electricity (CEB)</div>
                        <div class="service-number"><?= htmlspecialchars($cdata['electricity_bill_no']) ?></div>
                    </div>
                    <span class="sidebar-badge green" style="align-self:center;" aria-label="Account active">Active</span>
                </div>
                <?php endif; ?>

                <?php if (!empty($cdata['water_bill_no'])): ?>
                <div class="service-row" role="listitem" aria-label="Water account <?= htmlspecialchars($cdata['water_bill_no']) ?>">
                    <div class="service-icon-box blue" aria-hidden="true">
                        <i data-lucide="droplets"></i>
                    </div>
                    <div class="service-info">
                        <div class="service-name">Water Supply (NWSDB)</div>
                        <div class="service-number"><?= htmlspecialchars($cdata['water_bill_no']) ?></div>
                    </div>
                    <span class="sidebar-badge green" style="align-self:center;" aria-label="Account active">Active</span>
                </div>
                <?php endif; ?>

                <?php endif; ?>

                <!-- Action Buttons -->
                <div style="padding:0.9rem 1.15rem; display:flex; gap:8px; border-top:1px solid var(--border-subtle);">
                    <button type="button"
                            class="btn-sidebar-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#editProfileModal"
                            aria-label="Update Residence and Account Details">
                        <i data-lucide="pencil" aria-hidden="true"></i>
                        Update Residence
                    </button>
                    <a href="profile.php"
                       class="btn-sidebar-secondary"
                       title="Full Profile Page"
                       aria-label="Go to full profile page">
                        <i data-lucide="settings" aria-hidden="true"></i>
                    </a>
                </div>
            </div><!-- /sidebar-section: services -->

            <!-- Emergency Helplines -->
            <div class="sidebar-section">
                <div class="sidebar-head">
                    <div class="sidebar-head-title">
                        <i data-lucide="phone-call" aria-hidden="true" style="color:#dc2626;"></i>
                        Emergency Helplines
                    </div>
                    <span class="sidebar-badge red">24/7</span>
                </div>

                <div class="helpline-row" role="listitem">
                    <div class="helpline-left">
                        <i data-lucide="zap" style="color:#ca8a04;" aria-hidden="true"></i>
                        Electricity (CEB)
                    </div>
                    <a href="tel:1987" class="helpline-number" aria-label="Call Electricity CEB at 1987">1987</a>
                </div>

                <div class="helpline-row" role="listitem">
                    <div class="helpline-left">
                        <i data-lucide="droplets" style="color:#0891b2;" aria-hidden="true"></i>
                        Water Board (NWSDB)
                    </div>
                    <a href="tel:1939" class="helpline-number" aria-label="Call Water Board at 1939">1939</a>
                </div>

                <div class="helpline-row" role="listitem">
                    <div class="helpline-left">
                        <i data-lucide="building-2" style="color:#6366f1;" aria-hidden="true"></i>
                        Urban Council Office
                    </div>
                    <a href="tel:0452287222" class="helpline-number" aria-label="Call Urban Council at 045-2287222">045-2287222</a>
                </div>

                <div class="helpline-row" role="listitem">
                    <div class="helpline-left">
                        <i data-lucide="shield" style="color:#dc2626;" aria-hidden="true"></i>
                        Police Emergency
                    </div>
                    <a href="tel:119" class="helpline-number" aria-label="Call Police Emergency at 119">119</a>
                </div>
            </div><!-- /sidebar-section: helplines -->

        </aside><!-- /rd-sidebar -->

    </div><!-- /rd-two-col -->

</main><!-- /rd-page -->


<!-- ============================================================
     EDIT PROFILE / RESIDENCE MODAL  (unchanged functionality)
============================================================ -->
<div class="modal fade rd-modal" id="editProfileModal" tabindex="-1"
     aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
            <div class="modal-header text-white"
                 style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-bottom:1px solid rgba(255,255,255,0.08); padding: 1.25rem 1.5rem;">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary p-2 rounded-circle">
                        <i class="bi bi-house-gear-fill fs-5" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="editProfileModalLabel" style="color: #f8fafc; font-size: 1rem; font-weight: 700;">
                            Update Residence &amp; Account Details
                        </h5>
                        <small class="text-white-50">Relocated or changed meters in Balangoda? Update your records.</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" action="dashboard.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile_modal">
                <div class="modal-body p-4 bg-light">

                    <!-- Avatar Preview & Upload -->
                    <div class="bg-white p-3 rounded-3 border mb-3 d-flex flex-column flex-sm-row align-items-center gap-3">
                        <div class="position-relative">
                            <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                                <img id="modalAvatarPreview"
                                     src="../<?= htmlspecialchars($cdata['profile_pic']) ?>"
                                     alt="Avatar Preview"
                                     class="rounded-circle border border-2 border-primary shadow-sm"
                                     style="width:72px; height:72px; object-fit:cover;">
                            <?php else: ?>
                                <div id="modalAvatarPreviewFallback"
                                     class="avatar-initials shadow-sm"
                                     style="width:72px; height:72px; font-size:1.8rem;"
                                     aria-label="Avatar initials">
                                    <?= mb_strtoupper(mb_substr($cdata['full_name'] ?? 'R', 0, 1)) ?>
                                </div>
                                <img id="modalAvatarPreview" src="" alt="Avatar Preview"
                                     class="rounded-circle border border-2 border-primary shadow-sm d-none"
                                     style="width:72px; height:72px; object-fit:cover;">
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <label for="modalAvatarInput" class="form-label fw-bold text-dark mb-1">Profile Photo</label>
                            <input type="file" class="form-control form-control-sm"
                                   id="modalAvatarInput" name="avatar"
                                   accept="image/png, image/jpeg, image/webp, image/gif">
                            <small class="text-muted" style="font-size:0.75rem;">
                                JPG, PNG, WEBP, GIF â€” Max 3MB. Square recommended.
                            </small>
                        </div>
                    </div>

                    <!-- Personal Info -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">
                                Full Name <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-person text-secondary" aria-hidden="true"></i></span>
                                <input type="text" name="full_name" class="form-control" required
                                       value="<?= htmlspecialchars($cdata['full_name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">
                                Contact Phone <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-telephone text-secondary" aria-hidden="true"></i></span>
                                <input type="tel" name="phone" class="form-control" required
                                       placeholder="07X XXX XXXX"
                                       value="<?= htmlspecialchars($cdata['phone'] ?? '') ?>">
                            </div>
                            <small class="text-muted" style="font-size:0.75rem;">
                                Complaint SMS notifications use this number.
                            </small>
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">
                            Residential Address <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-geo-alt text-secondary" aria-hidden="true"></i></span>
                            <textarea name="address" rows="2" class="form-control" required
                                      placeholder="No. 12, Main Street, Balangoda"><?= htmlspecialchars($cdata['address'] ?? '') ?></textarea>
                        </div>
                        <small class="text-muted" style="font-size:0.75rem;">
                            Update when you move so repair crews locate issues accurately.
                        </small>
                    </div>

                    <!-- Utility Accounts -->
                    <div class="bg-white p-3 rounded-3 border">
                        <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2" style="font-size:0.85rem;">
                            <i class="bi bi-link-45deg text-primary fs-5" aria-hidden="true"></i>
                            Utility Meter / Account Numbers (Balangoda)
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary small mb-1">
                                    <i class="bi bi-lightning-charge-fill text-warning me-1" aria-hidden="true"></i>
                                    Electricity (CEB) Account No
                                </label>
                                <input type="text" name="electricity_bill_no" class="form-control form-control-sm"
                                       placeholder="e.g. 045-8192-33"
                                       value="<?= htmlspecialchars($cdata['electricity_bill_no'] ?? '') ?>">
                                <small class="text-muted" style="font-size:0.72rem;">Found on your electricity bill.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary small mb-1">
                                    <i class="bi bi-droplet-fill text-info me-1" aria-hidden="true"></i>
                                    Water Supply (NWSDB) Account No
                                </label>
                                <input type="text" name="water_bill_no" class="form-control form-control-sm"
                                       placeholder="e.g. BAL/4412/08"
                                       value="<?= htmlspecialchars($cdata['water_bill_no'] ?? '') ?>">
                                <small class="text-muted" style="font-size:0.72rem;">Found on your water bill.</small>
                            </div>
                        </div>
                    </div>
                </div><!-- /modal-body -->

                <div class="modal-footer bg-white d-flex justify-content-between">
                    <a href="profile.php" class="text-decoration-none small text-muted">
                        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                        Full Profile &amp; Password Settings â†’
                    </a>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary btn-sm px-3"
                                data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold shadow-sm">
                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ============================================================
     SCRIPTS
============================================================ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/pwa.js"></script>
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

    // â”€â”€ Live avatar preview in modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    document.getElementById('modalAvatarInput')?.addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (file) {
            var preview = document.getElementById('modalAvatarPreview');
            var fallback = document.getElementById('modalAvatarPreviewFallback');
            var reader = new FileReader();
            reader.onload = function(evt) {
                preview.src = evt.target.result;
                preview.classList.remove('d-none');
                if (fallback) fallback.classList.add('d-none');
            };
            reader.readAsDataURL(file);
        }
    });
</script>

</body>
</html>


