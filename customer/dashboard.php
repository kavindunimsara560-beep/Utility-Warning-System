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
        $flash = ['type' => 'danger', 'text' => '⚠️ ' . htmlspecialchars($err)];
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
    'verified'          => ['type'=>'success', 'text'=>'🎉 Email verified successfully! Welcome to your portal.'],
    'already_verified'  => ['type'=>'info',    'text'=>'Your email is already verified.'],
    'profile_updated'   => ['type'=>'success', 'text'=>'✅ Your profile and residence details have been updated successfully!'],
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

function time_ago($ts) {
    $diff = time() - strtotime($ts);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return (int)($diff/60)  . 'm ago';
    if ($diff < 86400) return (int)($diff/3600) . 'h ago';
    return date('M d, Y', strtotime($ts));
}

function status_class($s) {
    $s = strtolower($s);
    if (str_contains($s, 'resolve'))  return 'bg-success';
    if (str_contains($s, 'progress')) return 'bg-info text-dark';
    if (str_contains($s, 'review'))   return 'bg-warning text-dark';
    return 'bg-secondary';
}

function utility_icon($type) {
    $t = strtolower($type);
    if (str_contains($t, 'power') || str_contains($t, 'electric')) return 'bi-lightning-charge-fill';
    if (str_contains($t, 'water')) return 'bi-droplet-fill';
    if (str_contains($t, 'road') || str_contains($t, 'transport')) return 'bi-cone-striped';
    if (str_contains($t, 'gas')) return 'bi-fire';
    if (str_contains($t, 'drain') || str_contains($t, 'waste') || str_contains($t, 'sewer')) return 'bi-recycle';
    return 'bi-exclamation-triangle-fill';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Portal — Balangoda Utility System</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --navy:#0f172a; --navy2:#1e293b; --blue:#3b82f6; --cyan:#38bdf8; --accent:#6366f1; }
        body { font-family:'Outfit',system-ui,sans-serif; background:#f1f5f9; color:#1e293b; }

        /* Navbar */
        .cust-nav {
            background:linear-gradient(135deg, var(--navy) 0%, #1a1040 100%);
            padding:.75rem 0; box-shadow:0 4px 20px rgba(0,0,0,.3);
        }
        .cust-nav .brand { color:#f8fafc; font-size:1.1rem; font-weight:800; text-decoration:none; }
        .cust-nav .brand span { color:var(--cyan); }
        .cust-nav .nav-btn {
            background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
            border-radius:8px; color:#f1f5f9; padding:.38rem .85rem; font-size:.83rem;
            text-decoration:none; transition:background .15s;
        }
        .cust-nav .nav-btn:hover { background:rgba(255,255,255,.16); color:#fff; }
        .cust-nav .nav-btn.danger { border-color:rgba(239,68,68,.4); color:#fca5a5; }
        .cust-nav .nav-btn.danger:hover { background:rgba(239,68,68,.15); }

        /* Hero strip */
        .hero-strip {
            background:linear-gradient(135deg, #1a1040 0%, var(--navy2) 100%);
            color:#f8fafc; padding:2rem 0 1.8rem;
            border-bottom:1px solid rgba(255,255,255,.06);
        }
        .avatar-circle {
            width:56px; height:56px; border-radius:50%;
            background:linear-gradient(135deg,var(--blue),var(--accent));
            display:flex; align-items:center; justify-content:center;
            font-size:1.4rem; font-weight:800; color:#fff;
            flex-shrink:0; box-shadow:0 4px 12px rgba(59,130,246,0.35);
        }
        .avatar-circle-img {
            width:56px; height:56px; border-radius:50%;
            object-fit:cover; border:2px solid rgba(255,255,255,0.85);
            box-shadow:0 4px 12px rgba(59,130,246,0.35);
            flex-shrink:0;
        }
        .nav-avatar-mini {
            width:22px; height:22px; border-radius:50%;
            object-fit:cover; vertical-align:middle; margin-right:4px;
        }
        .btn-update-account {
            background:rgba(255,255,255,.12); color:#ffffff; font-weight:600;
            border:1px solid rgba(255,255,255,.22); padding:0.65rem 1.15rem;
            border-radius:10px; backdrop-filter:blur(8px);
            transition:all .2s ease; text-decoration:none;
            display:inline-flex; align-items:center; gap:0.5rem;
            font-size:0.92rem; cursor:pointer;
        }
        .btn-update-account:hover {
            background:rgba(255,255,255,.22); color:#38bdf8; border-color:#38bdf8;
            transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,0.2);
        }
        .hero-strip h2 { font-size:1.35rem; font-weight:800; margin:0; }
        .hero-strip p  { color:#94a3b8; font-size:.84rem; margin:0; }

        /* Primary Report Issue Button */
        .btn-report-single {
            background: #f59e0b;
            color: #0f172a;
            font-weight: 700;
            border: none;
            padding: 0.65rem 1.25rem;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.92rem;
        }
        .btn-report-single:hover {
            background: #d97706;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.45);
        }

        /* Stat cards */
        .stat-card {
            background:#fff; border-radius:14px; padding:1.1rem 1.3rem;
            box-shadow:0 2px 10px rgba(0,0,0,.05); border:1px solid #e2e8f0;
            transition:transform .2s;
        }
        .stat-card:hover { transform:translateY(-2px); }
        .stat-card .icon { font-size:1.75rem; }
        .stat-card .num  { font-size:1.85rem; font-weight:800; color:#0f172a; line-height:1.1; }
        .stat-card .lbl  { color:#64748b; font-size:.8rem; margin-top:3px; }

        /* Notification badge */
        .notif-badge { background:#ef4444; color:#fff; border-radius:12px; font-size:.7rem; font-weight:700; padding:1px 6px; }

        /* HIGHLIGHTED OUTAGE CARDS */
        .live-pulse-indicator {
            width: 12px;
            height: 12px;
            background-color: #ef4444;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 rgba(239, 68, 68, 0.6);
            animation: live-pulse 1.8s infinite;
        }
        @keyframes live-pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { transform: scale(1.15); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .pulse-dot {
            width: 7px;
            height: 7px;
            background-color: #ffffff;
            border-radius: 50%;
            display: inline-block;
            animation: live-pulse 1.2s infinite;
        }

        .highlight-outage-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            border-left: 6px solid var(--accent-color, #dc3545) !important;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
        }
        .highlight-outage-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.09);
        }
        .highlight-outage-card.is-ongoing {
            border-top: 1px solid rgba(239, 68, 68, 0.3);
            border-right: 1px solid rgba(239, 68, 68, 0.2);
            border-bottom: 1px solid rgba(239, 68, 68, 0.2);
            background: linear-gradient(180deg, #fffafa 0%, #ffffff 100%);
            box-shadow: 0 4px 22px rgba(239, 68, 68, 0.12);
        }
        .highlight-card-header {
            padding: 1rem 1.25rem 0.65rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .highlight-card-body {
            padding: 1.1rem 1.25rem;
        }
        .highlight-card-footer {
            padding: 0.85rem 1.25rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }
        .utility-icon-pill {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .badge-ongoing {
            background: #dc2626;
            color: #ffffff;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            padding: 0.35rem 0.65rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Table styles */
        .complaints-table th { background:#f8fafc; color:#475569; font-size:.77rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
        .complaints-table td { vertical-align:middle; font-size:.88rem; }

        /* Timeline notification */
        .notif-item { border-left:3px solid var(--cyan); padding:0.75rem 1rem; margin-bottom:.65rem; background:#f8fafc; border-radius:0 10px 10px 0; }
        .notif-item.read { border-left-color:#cbd5e1; opacity:.75; }

        /* Tab nav */
        .cust-tabs { border-bottom:2px solid #e2e8f0; margin-bottom:1.5rem; }
        .cust-tabs .nav-link { color:#64748b; font-weight:600; padding:.65rem 1.2rem; border:none; border-radius:0; border-bottom:2px solid transparent; margin-bottom:-2px; background:none; }
        .cust-tabs .nav-link.active { color:var(--blue); border-bottom-color:var(--blue); font-weight:700; }
        .cust-tabs .nav-link:hover  { color:var(--navy); }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="cust-nav">
    <div class="container-fluid px-4">
        <div class="d-flex align-items-center justify-content-between">
            <a href="../index.php" class="brand">⚡ <span>Balangoda</span> Utility Portal</a>
            <div class="d-flex align-items-center gap-2">
                <a href="../index.php" class="nav-btn"><i class="bi bi-house me-1"></i>Home</a>
                <span class="text-white-50 small d-none d-md-inline">|</span>
                <a href="profile.php" class="nav-btn d-inline-flex align-items-center">
                    <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                        <img src="../<?= htmlspecialchars($cdata['profile_pic']) ?>" alt="Avatar" class="nav-avatar-mini">
                    <?php else: ?>
                        <i class="bi bi-person-circle me-1"></i>
                    <?php endif; ?>
                    My Profile
                </a>
                <a href="logout.php" class="nav-btn danger"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
            </div>
        </div>
    </div>
</nav>

<!-- Hero Strip with Profile / Residence Actions -->
<div class="hero-strip">
    <div class="container-fluid px-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                    <img src="../<?= htmlspecialchars($cdata['profile_pic']) ?>" alt="Avatar" class="avatar-circle-img">
                <?php else: ?>
                    <div class="avatar-circle"><?= mb_strtoupper(mb_substr($cdata['full_name'] ?? 'R', 0, 1)) ?></div>
                <?php endif; ?>
                <div>
                    <h2><?= $customer_name ?></h2>
                    <p><?= $customer_email ?> &nbsp;·&nbsp; <?= $customer_phone ?></p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn-update-account" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                    <i class="bi bi-pencil-square"></i>
                    <span>Edit Profile / Residence</span>
                </button>
                <a href="../submit_complaint.php" class="btn-report-single">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Report an Issue</span>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4 py-4">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show shadow-sm mb-4" style="border-radius:12px;">
        <?= $flash['text'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon">📢</div>
                <div class="num <?= $active_outage_count > 0 ? 'text-danger' : 'text-success' ?>"><?= $active_outage_count ?></div>
                <div class="lbl">Active Outages</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon">📋</div>
                <div class="num"><?= $total_complaints ?></div>
                <div class="lbl">Total Complaints</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon">⏳</div>
                <div class="num text-warning"><?= $pending ?></div>
                <div class="lbl">Pending Review</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon">🔔</div>
                <div class="num"><?= $unread_count ?></div>
                <div class="lbl">New Notifications</div>
            </div>
        </div>
    </div>

    <!-- HIGHLIGHTED ACTIVE OUTAGE WARNINGS SECTION -->
    <div class="mb-5">
        <?php if (!empty($warnings)): ?>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="live-pulse-indicator"></span>
                    <h5 class="fw-bold mb-0 text-dark">Active Utility Outages &amp; Bulletins</h5>
                </div>
                <span class="badge bg-danger rounded-pill px-3 py-2 fw-semibold shadow-sm">
                    <?= count($warnings) ?> Outage Notice<?= count($warnings) > 1 ? 's' : '' ?> Active
                </span>
            </div>

            <div class="row g-3">
                <?php foreach ($warnings as $w): 
                    $now = time();
                    $start_ts = strtotime($w['start_time']);
                    $end_ts = strtotime($w['end_time']);
                    $is_ongoing = ($start_ts <= $now && $end_ts > $now);
                    $is_new = (!empty($w['created_at']) && strtotime($w['created_at']) > ($now - 86400));
                    $u_color = !empty($w['color_code']) ? htmlspecialchars($w['color_code']) : '#dc3545';
                    $u_icon = utility_icon($w['utility_type']);
                ?>
                <div class="col-12 <?= count($warnings) > 1 ? 'col-lg-6' : '' ?>">
                    <div class="highlight-outage-card <?= $is_ongoing ? 'is-ongoing' : 'is-scheduled' ?>" style="--accent-color: <?= $u_color ?>;">
                        <div class="highlight-card-header d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <span class="utility-icon-pill" style="background: <?= $u_color ?>;">
                                    <i class="bi <?= $u_icon ?>"></i>
                                </span>
                                <div>
                                    <span class="fw-bold text-dark text-uppercase" style="font-size:0.82rem; letter-spacing:0.04em;">
                                        <?= htmlspecialchars($w['utility_type']) ?> ALERT
                                    </span>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <?php if ($is_ongoing): ?>
                                    <span class="badge badge-ongoing"><span class="pulse-dot"></span> ONGOING OUTAGE</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="bi bi-clock me-1"></i> SCHEDULED</span>
                                <?php endif; ?>
                                <?php if ($is_new): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-bell-fill"></i> NEW</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="highlight-card-body">
                            <h5 class="fw-bold text-dark mb-2"><?= htmlspecialchars($w['title']) ?></h5>
                            <p class="text-secondary mb-0" style="font-size:0.9rem; line-height:1.6;">
                                <?= nl2br(htmlspecialchars($w['description'])) ?>
                            </p>
                        </div>

                        <div class="highlight-card-footer">
                            <div class="row g-2 text-muted" style="font-size:0.8rem;">
                                <div class="col-sm-6 d-flex align-items-center gap-2">
                                    <i class="bi bi-calendar-event text-primary fs-6"></i>
                                    <div>
                                        <div class="text-secondary" style="font-size:0.7rem; text-transform:uppercase;">Starts</div>
                                        <strong><?= date('M d, Y • h:i A', $start_ts) ?></strong>
                                    </div>
                                </div>
                                <div class="col-sm-6 d-flex align-items-center gap-2">
                                    <i class="bi bi-calendar-check text-success fs-6"></i>
                                    <div>
                                        <div class="text-secondary" style="font-size:0.7rem; text-transform:uppercase;">Expected Restoration</div>
                                        <strong><?= date('M d, Y • h:i A', $end_ts) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-success d-flex align-items-center gap-3 shadow-sm border-0 p-3 mb-0" style="border-radius:14px; background:#ecfdf5; border-left:5px solid #10b981!important;">
                <i class="bi bi-shield-check fs-2 text-success"></i>
                <div>
                    <h6 class="fw-bold text-success mb-0">All Municipal Utility Services Running Routinely</h6>
                    <p class="text-secondary small mb-0">No active electricity, water, or municipal infrastructure outage warnings published for Balangoda at this time.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Portal Content Row -->
    <div class="row g-4">
        <!-- Main Column: Complaints and Notifications -->
        <div class="col-lg-8">

            <!-- Tab navigation -->
            <ul class="nav cust-tabs" id="portalTabs">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-complaints">
                        <i class="bi bi-chat-dots me-1"></i> My Complaints
                        <?php if ($total_complaints > 0): ?><span class="badge bg-secondary ms-1"><?= $total_complaints ?></span><?php endif; ?>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-notifs">
                        <i class="bi bi-bell me-1"></i> Notifications &amp; Updates
                        <?php if ($unread_count > 0): ?><span class="notif-badge ms-1"><?= $unread_count ?></span><?php endif; ?>
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Complaints tab -->
                <div class="tab-pane fade show active" id="tab-complaints">
                    <?php if (empty($complaints)): ?>
                    <div class="text-center py-5 text-muted bg-white rounded-3 shadow-sm border p-4">
                        <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                        <h6 class="fw-bold text-dark">No Complaints Lodged Yet</h6>
                        <p class="small text-muted mb-0">Whenever you experience utility breakdowns or interruptions, click the <strong>Report an Issue</strong> button above to notify the authorities.</p>
                    </div>
                    <?php else: ?>
                    <div class="bg-white rounded-3 shadow-sm border overflow-hidden">
                        <div class="table-responsive">
                            <table class="table table-hover complaints-table mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">#</th>
                                        <th>Title &amp; Issue</th>
                                        <th>Utility</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($complaints as $c): ?>
                                    <tr>
                                        <td class="ps-3 text-muted">#<?= $c['complaint_id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($c['title']) ?></strong>
                                            <div class="text-muted small"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 70, '...')) ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($c['utility_type']) ?></span></td>
                                        <td><span class="badge <?= status_class($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                                        <td class="text-muted small"><?= time_ago($c['created_at']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Notifications tab -->
                <div class="tab-pane fade" id="tab-notifs">
                    <?php if (empty($notifs)): ?>
                    <div class="text-center py-5 text-muted bg-white rounded-3 shadow-sm border p-4">
                        <i class="bi bi-bell-slash fs-1 d-block mb-3 text-secondary"></i>
                        <h6 class="fw-bold text-dark">No Notifications Yet</h6>
                        <p class="small text-muted mb-0">You will receive automatic alerts when municipal teams inspect or resolve your reports.</p>
                    </div>
                    <?php else: ?>
                    <div class="bg-white rounded-3 shadow-sm border p-3">
                        <?php foreach ($notifs as $n): ?>
                        <div class="notif-item <?= $n['is_read'] ? 'read' : '' ?>">
                            <div style="font-size:.88rem; color:#1e293b;"><?= htmlspecialchars($n['message']) ?></div>
                            <div style="font-size:.74rem; color:#94a3b8; margin-top:3px;"><?= time_ago($n['created_at']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Side column: Account profile and Emergency Hotlines -->
        <div class="col-lg-4">

            <!-- My Account profile info -->
            <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-person-badge text-primary"></i> My Registered Services
                    </h6>
                    <div style="font-size:.85rem; color:#475569;">
                        <div class="mb-2 d-flex align-items-center gap-2">
                            <i class="bi bi-person text-secondary"></i>
                            <div><strong>Name:</strong> <?= $customer_name ?></div>
                        </div>
                        <div class="mb-2 d-flex align-items-center gap-2">
                            <i class="bi bi-envelope text-secondary"></i>
                            <div><strong>Email:</strong> <?= $customer_email ?></div>
                        </div>
                        <div class="mb-2 d-flex align-items-center gap-2">
                            <i class="bi bi-telephone text-secondary"></i>
                            <div><strong>Phone:</strong> <?= $customer_phone ?></div>
                        </div>
                        <div class="mb-2 d-flex align-items-start gap-2">
                            <i class="bi bi-geo-alt text-secondary mt-1"></i>
                            <div><strong>Address:</strong> <?= htmlspecialchars($cdata['address'] ?? 'Balangoda') ?></div>
                        </div>
                        <hr class="my-3 text-muted">
                        <?php if (!empty($cdata['electricity_bill_no'])): ?>
                        <div class="mb-2 d-flex align-items-center gap-2">
                            <i class="bi bi-lightning-charge-fill text-warning"></i>
                            <div><strong>Electricity Account:</strong> <?= htmlspecialchars($cdata['electricity_bill_no']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($cdata['water_bill_no'])): ?>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-droplet-fill text-info"></i>
                            <div><strong>Water Account:</strong> <?= htmlspecialchars($cdata['water_bill_no']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-3 border-top d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm flex-grow-1 d-flex align-items-center justify-content-center gap-1" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                            <i class="bi bi-pencil-square"></i> Update Residence
                        </button>
                        <a href="profile.php" class="btn btn-light btn-sm text-secondary border d-flex align-items-center justify-content-center px-3" title="Full Profile Page">
                            <i class="bi bi-person-gear"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Emergency Helplines Card -->
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-telephone-inbound text-danger"></i> Emergency Utility Helplines
                    </h6>
                    <div class="list-group list-group-flush" style="font-size:0.85rem;">
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-lightning-charge text-warning me-2"></i>
                                <strong>Electricity (CEB)</strong>
                            </div>
                            <span class="badge bg-light text-dark border fw-bold">1987</span>
                        </div>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-droplet text-info me-2"></i>
                                <strong>Water Board (NWSDB)</strong>
                            </div>
                            <span class="badge bg-light text-dark border fw-bold">1939</span>
                        </div>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-building text-primary me-2"></i>
                                <strong>Urban Council Office</strong>
                            </div>
                            <span class="badge bg-light text-dark border fw-bold">045-2287222</span>
                        </div>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-shield text-danger me-2"></i>
                                <strong>Police Emergency</strong>
                            </div>
                            <span class="badge bg-light text-dark border fw-bold">119</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- EDIT PROFILE / RESIDENCE MODAL                              -->
<!-- ============================================================ -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
            <div class="modal-header text-white" style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-bottom:1px solid rgba(255,255,255,0.1);">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary p-2 rounded-circle"><i class="bi bi-house-gear-fill fs-5"></i></span>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="editProfileModalLabel">Update Residence &amp; Account Details</h5>
                        <small class="text-white-50">Relocated or changed meters in Balangoda? Update your contact and account records.</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="dashboard.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile_modal">
                <div class="modal-body p-4 bg-light">
                    <!-- Avatar Preview & Upload Row -->
                    <div class="bg-white p-3 rounded-3 border mb-3 d-flex flex-column flex-sm-row align-items-center gap-3">
                        <div class="position-relative">
                            <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                                <img id="modalAvatarPreview" src="../<?= htmlspecialchars($cdata['profile_pic']) ?>" alt="Avatar Preview" class="rounded-circle border border-2 border-primary shadow-sm" style="width:72px; height:72px; object-fit:cover;">
                            <?php else: ?>
                                <div id="modalAvatarPreviewFallback" class="avatar-circle shadow-sm" style="width:72px; height:72px; font-size:1.8rem;">
                                    <?= mb_strtoupper(mb_substr($cdata['full_name'] ?? 'R', 0, 1)) ?>
                                </div>
                                <img id="modalAvatarPreview" src="" alt="Avatar Preview" class="rounded-circle border border-2 border-primary shadow-sm d-none" style="width:72px; height:72px; object-fit:cover;">
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <label for="modalAvatarInput" class="form-label fw-bold text-dark mb-1">Profile Photo</label>
                            <input type="file" class="form-control form-control-sm" id="modalAvatarInput" name="avatar" accept="image/png, image/jpeg, image/webp, image/gif">
                            <small class="text-muted" style="font-size:0.75rem;">Supported: JPG, PNG, WEBP, GIF (Max 3MB). Square format recommended.</small>
                        </div>
                    </div>

                    <!-- Personal Info -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-person text-secondary"></i></span>
                                <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($cdata['full_name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Contact Phone Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-telephone text-secondary"></i></span>
                                <input type="tel" name="phone" class="form-control" required placeholder="07X XXX XXXX" value="<?= htmlspecialchars($cdata['phone'] ?? '') ?>">
                            </div>
                            <small class="text-muted" style="font-size:0.75rem;">Complaints and outage SMS notifications match this phone number.</small>
                        </div>
                    </div>

                    <!-- Physical Address -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Physical Residential Address <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-geo-alt text-secondary"></i></span>
                            <textarea name="address" rows="2" class="form-control" required placeholder="e.g., No. 12, Main Street, Balangoda"><?= htmlspecialchars($cdata['address'] ?? '') ?></textarea>
                        </div>
                        <small class="text-muted" style="font-size:0.75rem;">Update this whenever you move so emergency repair crews locate issues accurately.</small>
                    </div>

                    <!-- Utility Accounts -->
                    <div class="bg-white p-3 rounded-3 border">
                        <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2" style="font-size:0.85rem;">
                            <i class="bi bi-link-45deg text-primary fs-5"></i> Utility Meter / Account Numbers (Balangoda)
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary small mb-1">
                                    <i class="bi bi-lightning-charge-fill text-warning me-1"></i>Electricity (CEB) Account No
                                </label>
                                <input type="text" name="electricity_bill_no" class="form-control form-control-sm" placeholder="e.g. 045-8192-33" value="<?= htmlspecialchars($cdata['electricity_bill_no'] ?? '') ?>">
                                <small class="text-muted" style="font-size:0.72rem;">Found on your monthly electricity bill statement.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary small mb-1">
                                    <i class="bi bi-droplet-fill text-info me-1"></i>Water Supply (NWSDB) Account No
                                </label>
                                <input type="text" name="water_bill_no" class="form-control form-control-sm" placeholder="e.g. BAL/4412/08" value="<?= htmlspecialchars($cdata['water_bill_no'] ?? '') ?>">
                                <small class="text-muted" style="font-size:0.72rem;">Found on your monthly water bill slip.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white d-flex justify-content-between">
                    <a href="profile.php" class="text-decoration-none small text-muted">
                        <i class="bi bi-shield-lock me-1"></i>Open Full Profile &amp; Password Settings →
                    </a>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i>Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/pwa.js"></script>
<script>
// Live avatar preview in modal
document.getElementById('modalAvatarInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const preview = document.getElementById('modalAvatarPreview');
        const fallback = document.getElementById('modalAvatarPreviewFallback');
        const reader = new FileReader();
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

