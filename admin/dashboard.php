<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php?role=admin&msg=login_required&redirect=" . urlencode('admin/dashboard.php'));
    exit();
}

require '../config/db_connect.php';
require_once '../config/profile_helper.php';
ensure_profile_schema($conn);

$admin_id = (int)$_SESSION['admin_id'];
$admin_stmt = $conn->prepare("SELECT username, full_name, email, phone, address, profile_pic, created_at FROM admins WHERE admin_id = ?");
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();
$admin_display_name = !empty($admin_info['full_name']) ? $admin_info['full_name'] : ($admin_info['username'] ?? $_SESSION['admin_username'] ?? 'Municipal Admin');
$admin_username     = $admin_info['username'] ?? $_SESSION['admin_username'] ?? 'admin';
$admin_profile_pic  = $admin_info['profile_pic'] ?? null;

$message      = "";
$message_type = "success";

// GET messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'warning_deleted')        { $message = "Outage warning notice has been removed.";           $message_type = "success"; }
    elseif ($_GET['msg'] === 'complaint_deleted') { $message = "Complaint record has been removed.";              $message_type = "success"; }
    elseif ($_GET['msg'] === 'status_updated')    { $message = "Complaint status updated successfully.";          $message_type = "success"; }
    elseif ($_GET['msg'] === 'warning_updated')   { $message = "Warning notice updated successfully.";            $message_type = "success"; }
    elseif ($_GET['msg'] === 'account_created')   { $message = "Welcome! Your administrator account is ready.";   $message_type = "success"; }
    elseif ($_GET['msg'] === 'profile_updated')   { $message = "Your profile has been updated successfully.";     $message_type = "success"; }
    elseif ($_GET['msg'] === 'password_updated')  { $message = "Your administrative password has been changed successfully."; $message_type = "success"; }
}

// Handle Administrator Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_admin_password_modal') {
    $cur_pass  = $_POST['current_password'] ?? '';
    $new_pass  = $_POST['new_password'] ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';

    $pw_chk = $conn->prepare("SELECT password_hash FROM admins WHERE admin_id = ?");
    $pw_chk->bind_param("i", $admin_id);
    $pw_chk->execute();
    $curr_hash = $pw_chk->get_result()->fetch_assoc()['password_hash'] ?? '';
    $pw_chk->close();

    $pw_err = '';
    if (empty($cur_pass) || empty($new_pass) || empty($conf_pass)) {
        $pw_err = "All password fields are required.";
    } elseif (!password_verify($cur_pass, $curr_hash)) {
        $pw_err = "Current administrative password does not match our records.";
    } elseif (strlen($new_pass) < 6) {
        $pw_err = "New password must be at least 6 characters in length.";
    } elseif ($new_pass !== $conf_pass) {
        $pw_err = "New password and confirmation do not match.";
    }

    if (empty($pw_err)) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ?");
        $upd->bind_param("si", $new_hash, $admin_id);
        if ($upd->execute()) {
            $upd->close();
            header("Location: dashboard.php?msg=password_updated");
            exit();
        } else {
            $pw_err = "Database error: " . $conn->error;
            $upd->close();
        }
    }

    if (!empty($pw_err)) {
        $message = $pw_err;
        $message_type = "danger";
    }
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_admin_profile') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $err = '';
    if (empty($full_name)) { $err = "Full name cannot be empty."; }
    $avatar_path = $admin_profile_pic;
    if (empty($err) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_res = handle_avatar_upload($_FILES['avatar'], 'admin', $admin_id, $avatar_path);
        if ($upload_res['success']) { $avatar_path = $upload_res['path']; } else { $err = $upload_res['error']; }
    }
    if (empty($err)) {
        $upd = $conn->prepare("UPDATE admins SET full_name = ?, phone = ?, address = ?, profile_pic = ? WHERE admin_id = ?");
        if ($upd) {
            $upd->bind_param("ssssi", $full_name, $phone, $address, $avatar_path, $admin_id);
            if ($upd->execute()) { $upd->close(); header("Location: dashboard.php?msg=profile_updated"); exit(); }
            else { $err = "Database error: " . $conn->error; }
            $upd->close();
        }
    }
    if (!empty($err)) { $message = $err; $message_type = "danger"; }
}

// Add new warning
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_warning'])) {
    $utility_type = trim($_POST['utility_type'] ?? 'Electricity');
    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $color_code   = trim($_POST['color_code'] ?? '#d97706');
    $start_time   = $_POST['start_time'] ?? '';
    $end_time     = $_POST['end_time'] ?? '';
    $start_ts = strtotime($start_time);
    $end_ts   = strtotime($end_time);
    $current_time = time();

    if (empty($title) || empty($description) || empty($start_time) || empty($end_time)) {
        $message = "All warning fields are required."; $message_type = "danger";
    } elseif ($start_ts < $current_time - 300) {
        $message = "Start Date and Time cannot be in the past. Only current and future times are allowed."; $message_type = "danger";
    } elseif ($end_ts < $start_ts) {
        $message = "Warning End Time must be the same as or after Start Time."; $message_type = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO warnings (utility_type, title, description, color_code, start_time, end_time, posted_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $utility_type, $title, $description, $color_code, $start_time, $end_time, $admin_id);
            if ($stmt->execute()) { $message = "Warning bulletin successfully broadcast to all municipal residents."; $message_type = "success"; }
            else { $message = "Error posting warning: " . $stmt->error; $message_type = "danger"; }
            $stmt->close();
        } else { $message = "Database error: " . $conn->error; $message_type = "danger"; }
    }
}

// Delete warning
if (isset($_GET['delete'])) {
    $warning_id = intval($_GET['delete']);
    $del_stmt = $conn->prepare("DELETE FROM warnings WHERE warning_id = ?");
    if ($del_stmt) { $del_stmt->bind_param("i", $warning_id); $del_stmt->execute(); $del_stmt->close(); }
    header("Location: dashboard.php?msg=warning_deleted"); exit();
}

// Delete complaint
if (isset($_GET['delete_complaint'])) {
    $complaint_id = intval($_GET['delete_complaint']);
    $del_stmt = $conn->prepare("DELETE FROM complaints WHERE complaint_id = ?");
    if ($del_stmt) { $del_stmt->bind_param("i", $complaint_id); $del_stmt->execute(); $del_stmt->close(); }
    header("Location: dashboard.php?msg=complaint_deleted"); exit();
}

// Update complaint status
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $new_status   = trim($_POST['status'] ?? 'Pending Review');
    $update_stmt  = $conn->prepare("UPDATE complaints SET status = ? WHERE complaint_id = ?");
    if ($update_stmt) { $update_stmt->bind_param("si", $new_status, $complaint_id); $update_stmt->execute(); $update_stmt->close(); }
    $ref_res = $conn->prepare("SELECT resident_name, contact_info, title FROM complaints WHERE complaint_id = ?");
    if ($ref_res) {
        $ref_res->bind_param("i", $complaint_id);
        $ref_res->execute();
        $comp_row = $ref_res->get_result()->fetch_assoc();
        $ref_res->close();
        if ($comp_row) {
            $notif_msg  = "Your complaint #" . $complaint_id . " (\"" . mb_strimwidth($comp_row['title'], 0, 60, '...') . "\") status has been updated to: " . $new_status . ".";
            $notif_link = null;
            $client_ref = $comp_row['contact_info'];
            $cn_stmt = $conn->prepare("INSERT INTO notifications (target_type, target_ref, message, link) VALUES ('client', ?, ?, ?)");
            if ($cn_stmt) { $cn_stmt->bind_param("sss", $client_ref, $notif_msg, $notif_link); $cn_stmt->execute(); $cn_stmt->close(); }
        }
    }
    header("Location: dashboard.php?msg=status_updated"); exit();
}

// Metrics
$active_outages_res       = $conn->query("SELECT COUNT(*) AS cnt FROM warnings WHERE end_time >= NOW()");
$active_outages_count     = $active_outages_res ? (int)$active_outages_res->fetch_assoc()['cnt'] : 0;
$total_comp_res           = $conn->query("SELECT COUNT(*) AS cnt FROM complaints");
$total_complaints_count   = $total_comp_res ? (int)$total_comp_res->fetch_assoc()['cnt'] : 0;
$pending_comp_res         = $conn->query("SELECT COUNT(*) AS cnt FROM complaints WHERE status LIKE '%Pending%' OR status LIKE '%Review%'");
$pending_complaints_count = $pending_comp_res ? (int)$pending_comp_res->fetch_assoc()['cnt'] : 0;

$admin_notif_count = 0;
$admin_notifs      = [];
$anc_res = $conn->query("SELECT COUNT(*) AS cnt FROM notifications WHERE target_type='admin' AND is_read=0");
if ($anc_res) { $admin_notif_count = (int)($anc_res->fetch_assoc()['cnt'] ?? 0); }
$an_res = $conn->query("SELECT notification_id, message, created_at, is_read FROM notifications WHERE target_type='admin' ORDER BY created_at DESC LIMIT 25");
if ($an_res) { while ($an = $an_res->fetch_assoc()) { $admin_notifs[] = $an; } }

// Data
$warnings_result = $conn->query("SELECT * FROM warnings ORDER BY (end_time >= NOW()) DESC, start_time DESC");
$all_warnings    = $warnings_result ? $warnings_result->fetch_all(MYSQLI_ASSOC) : [];
$complaints_result = $conn->query("SELECT * FROM complaints ORDER BY complaint_id DESC");
$all_complaints    = $complaints_result ? $complaints_result->fetch_all(MYSQLI_ASSOC) : [];

if (!function_exists('time_ago')) {
    function time_ago($ts) {
        $diff = time() - strtotime($ts);
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return (int)($diff/60) . 'm ago';
        if ($diff < 86400) return (int)($diff/3600) . 'h ago';
        return date('M d, Y', strtotime($ts));
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#f9fafb]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Console — Balangoda Municipal Council</title>
    <meta name="description" content="Authorized Municipal Administration & Utility Warning Dispatch Console for Balangoda Urban Council.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#111317">
    <link rel="manifest" href="../admin-manifest.json">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                    display: ['Outfit', 'sans-serif'],
                },
                colors: {
                    adm: {
                        dark: '#080500',
                        charcoal: '#111317',
                        accent: '#d97706',
                        gold: '#f59e0b',
                    }
                }
            }
        }
    }
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .font-display { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="min-h-full flex flex-col bg-[#f9fafb] text-slate-800">

    <!-- ════════════════ TOP NAVBAR (CHARCOAL-BLACK & WARM AMBER ACCENT) ════════════════ -->
    <header class="bg-[#111317] border-b border-amber-500/20 sticky top-0 z-40 text-slate-100 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-600 via-amber-500 to-amber-400 flex items-center justify-center text-slate-950 shadow-md shadow-amber-950/40 group-hover:scale-105 transition-transform font-bold">
                        <i data-lucide="shield" class="w-5 h-5 text-slate-950"></i>
                    </div>
                    <div>
                        <div class="font-display font-black text-base tracking-tight text-white leading-tight">
                            Balangoda <span class="text-amber-400">Admin</span>
                        </div>
                        <div class="text-[11px] text-amber-400/80 font-medium tracking-wider uppercase">
                            Municipal Dispatch Console
                        </div>
                    </div>
                </a>
            </div>

            <div class="flex items-center gap-2 sm:gap-4">
                <a href="../index.php" target="_blank" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-300 hover:text-white hover:bg-slate-800 border border-slate-700/60 transition-all">
                    <i data-lucide="external-link" class="w-3.5 h-3.5 text-amber-400"></i>
                <a href="import_csv.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold text-amber-300 hover:text-white hover:bg-amber-950/50 border border-amber-600/40 transition-all">
                    <i data-lucide="database" class="w-3.5 h-3.5 text-amber-400"></i>
                    <span>Registry Importer</span>
                </a>
                <a href="logout.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold text-rose-300 hover:text-white hover:bg-rose-950/60 border border-rose-900/60 transition-all">
                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                    <span class="hidden sm:inline">Sign Out</span>
                </a>
            </div>
        </div>
    </header>

    <!-- ════════════════ EXECUTIVE ADMIN HERO (WARM AMBER & CHARCOAL BANNER) ════════════════ -->
    <section class="bg-gradient-to-r from-[#0a0c10] via-[#141006] to-[#1c1405] text-white border-b border-amber-500/20 shadow-inner relative overflow-hidden">
        <!-- Ambient subtle amber glow -->
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_85%_20%,rgba(245,158,11,0.12),transparent_50%)] pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 relative z-10">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
                <div class="flex items-start sm:items-center gap-4 sm:gap-5">
                    <?php if (!empty($admin_profile_pic) && file_exists('../' . $admin_profile_pic)): ?>
                        <img src="../<?= htmlspecialchars($admin_profile_pic) ?>" class="w-16 h-16 sm:w-18 sm:h-18 rounded-2xl object-cover ring-2 ring-amber-400/60 shadow-xl flex-shrink-0" alt="<?= htmlspecialchars($admin_display_name) ?>">
                    <?php else: ?>
                        <div class="w-16 h-16 sm:w-18 sm:h-18 rounded-2xl bg-gradient-to-br from-amber-500 via-amber-600 to-amber-700 text-slate-950 font-display font-black text-2xl sm:text-3xl flex items-center justify-center shadow-xl ring-2 ring-amber-400/40 flex-shrink-0">
                            <?= mb_strtoupper(mb_substr($admin_display_name, 0, 1)) ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <div class="flex flex-wrap items-center gap-2 sm:gap-2.5">
                            <h1 class="font-display font-extrabold text-xl sm:text-2xl text-white tracking-tight">
                                <?= htmlspecialchars($admin_display_name) ?>
                            </h1>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40 tracking-wide uppercase">
                                <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-400"></i>
                                Municipal Administrator
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-y-1 gap-x-4 mt-1.5 text-xs text-slate-300">
                            <span class="inline-flex items-center gap-1.5">
                                <i data-lucide="user" class="w-3.5 h-3.5 text-amber-400"></i>
                                <?= htmlspecialchars($admin_username) ?>
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <i data-lucide="mail" class="w-3.5 h-3.5 text-amber-400"></i>
                                <?= htmlspecialchars($admin_info['email'] ?? 'admin@balangoda.gov.lk') ?>
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-amber-200 font-medium">
                                <i data-lucide="building-2" class="w-3.5 h-3.5 text-amber-400"></i>
                                Balangoda Urban Council
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2.5 self-end sm:self-auto flex-wrap">
                    <button type="button" onclick="openProfileModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-slate-900/80 hover:bg-slate-800 text-amber-200 border border-amber-500/30 transition-all shadow-sm cursor-pointer">
                        <i data-lucide="user-cog" class="w-3.5 h-3.5 text-amber-400"></i>
                        <span>Edit Profile</span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- ════════════════ MAIN CONTENT CANVAS ════════════════ -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1 w-full">

        <!-- Flash Notice -->
        <?php if (!empty($message)): ?>
        <div class="mb-6 rounded-2xl p-4 border flex items-center gap-3 shadow-sm <?= $message_type === 'danger' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
            <i data-lucide="<?= $message_type === 'danger' ? 'alert-triangle' : 'check-circle-2' ?>" class="w-5 h-5 flex-shrink-0"></i>
            <span class="text-sm font-medium leading-relaxed"><?= htmlspecialchars($message) ?></span>
        </div>
        <?php endif; ?>

        <!-- 4 ADMIN KPI METRIC CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
            <!-- 1. Active Outages -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Active Outages</span>
                    <div class="w-10 h-10 rounded-xl <?= $active_outages_count > 0 ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-emerald-50 text-emerald-600 border border-emerald-200' ?> flex items-center justify-center">
                        <i data-lucide="radio-tower" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $active_outages_count ?>
                    </div>
                    <?php if ($active_outages_count > 0): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                            <span class="w-2 h-2 rounded-full bg-amber-600 animate-pulse"></span>
                            Live Interruption
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            All Clear
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-slate-500">Broadcasts actively published to residents</div>
            </div>

            <!-- 2. Total Complaints -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Total Complaints</span>
                    <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-700 border border-slate-200 flex items-center justify-center">
                        <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $total_complaints_count ?>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-600">
                        Total Logged
                    </span>
                </div>
                <div class="mt-2 text-xs text-slate-500">Resident dispatch tickets recorded</div>
            </div>

            <!-- 3. Pending Review -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Pending Review</span>
                    <div class="w-10 h-10 rounded-xl <?= $pending_complaints_count > 0 ? 'bg-amber-50 text-amber-600 border border-amber-200' : 'bg-emerald-50 text-emerald-600 border border-emerald-200' ?> flex items-center justify-center">
                        <i data-lucide="clock-3" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $pending_complaints_count ?>
                    </div>
                    <?php if ($pending_complaints_count > 0): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                            Needs Action
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            Up to date
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-slate-500">Awaiting municipal review or triage</div>
            </div>

            <!-- 4. Unread Alerts -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Unread Alerts</span>
                    <div class="w-10 h-10 rounded-xl <?= $admin_notif_count > 0 ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-slate-100 text-slate-600 border border-slate-200' ?> flex items-center justify-center">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $admin_notif_count ?>
                    </div>
                    <?php if ($admin_notif_count > 0): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-900 border border-amber-200">
                            New Logs
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600">
                            Cleared
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-slate-500">Automated system events &amp; audit notices</div>
            </div>
        </div>

        <!-- SYSTEM ROUTINE STATUS BANNER -->
        <?php
        $now_time = time();
        $is_any_live = false;
        foreach ($all_warnings as $w_c) {
            if (strtotime($w_c['start_time']) <= $now_time && strtotime($w_c['end_time']) > $now_time) {
                $is_any_live = true; break;
            }
        }
        ?>
        <?php if ($active_outages_count > 0): ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl px-5 py-3.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-amber-100 text-amber-800 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                </div>
                <div>
                    <span class="font-bold text-sm">Active Municipal Outage Notices Broadcasting</span>
                    <span class="text-xs text-amber-800 ml-1.5 block sm:inline">
                        &mdash; <?= $active_outages_count ?> bulletin<?= $active_outages_count > 1 ? 's are' : ' is' ?> currently published and visible to Balangoda residents.
                    </span>
                </div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300 flex-shrink-0 self-start sm:self-auto">
                <span class="w-2 h-2 rounded-full bg-amber-600 animate-pulse"></span>
                BROADCAST ACTIVE
            </span>
        </div>
        <?php else: ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl px-4 py-3 flex items-center justify-between mb-6 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                </div>
                <div>
                    <span class="font-bold text-sm">All Municipal Utility Services Running Routinely</span>
                    <span class="text-xs text-emerald-700 ml-1.5 block sm:inline">
                        &mdash; No active outage warnings are published to the public portal.
                    </span>
                </div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 flex-shrink-0">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                SYSTEM NOMINAL
            </span>
        </div>
        <?php endif; ?>

        <!-- BALANCED 12-COLUMN GRID (8 COLS MAIN FEEDS, 4 COLS BROADCAST) -->
        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">

            <!-- ── LEFT / MAIN CONTENT: TABS & DATA FEEDS (8 COLS) ── -->
            <div class="xl:col-span-8 space-y-6">

                <!-- Tab Card Container -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    
                    <!-- Modern Tab Bar (Amber Active Border) -->
                    <div class="border-b border-slate-200 bg-slate-50/70 px-4 pt-3 flex items-center gap-2 overflow-x-auto">
                        <button type="button" onclick="switchAdminTab('outages')" id="tab-adm-outages-btn" class="tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-amber-500 text-amber-900 transition-colors whitespace-nowrap">
                            <i data-lucide="radio-tower" class="w-4 h-4 text-amber-600"></i>
                            <span>Outage Bulletins</span>
                            <?php if ($active_outages_count > 0): ?>
                                <span class="text-xs px-2 py-0.5 rounded-full font-bold bg-amber-100 text-amber-900"><?= $active_outages_count ?></span>
                            <?php else: ?>
                                <span class="text-xs px-2 py-0.5 rounded-full font-bold bg-slate-200/70 text-slate-700"><?= count($all_warnings) ?></span>
                            <?php endif; ?>
                        </button>

                        <button type="button" onclick="switchAdminTab('complaints')" id="tab-adm-complaints-btn" class="tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition-colors whitespace-nowrap">
                            <i data-lucide="message-square" class="w-4 h-4"></i>
                            <span>Resident Complaints</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-bold <?= $pending_complaints_count > 0 ? 'bg-amber-100 text-amber-900' : 'bg-slate-200/70 text-slate-700' ?>">
                                <?= $total_complaints_count ?>
                            </span>
                        </button>

                        <button type="button" onclick="switchAdminTab('notifs')" id="tab-adm-notifs-btn" class="tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition-colors whitespace-nowrap">
                            <i data-lucide="bell" class="w-4 h-4"></i>
                            <span>Alerts &amp; Logs</span>
                            <?php if ($admin_notif_count > 0): ?>
                                <span class="text-xs px-2 py-0.5 rounded-full font-bold bg-amber-100 text-amber-900"><?= $admin_notif_count ?></span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <!-- ── TAB 1: Outage Bulletins ── -->
                    <div id="panel-adm-outages" class="p-5 sm:p-6">
                        <?php if (empty($all_warnings)): ?>
                        <div class="py-12 px-4 text-center max-w-md mx-auto">
                            <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto mb-3.5 border border-amber-100">
                                <i data-lucide="shield-check" class="w-7 h-7"></i>
                            </div>
                            <h3 class="font-display font-bold text-base text-slate-900 mb-1">No Active Outage Bulletins</h3>
                            <p class="text-xs text-slate-500 leading-relaxed mb-5">
                                All municipal utility networks are currently operating routinely. Use the broadcast console on the right to post upcoming maintenance or emergency notices.
                            </p>
                            <button type="button" onclick="document.getElementById('title').focus()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-slate-950 transition-all shadow-sm">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                <span>Create Bulletin Notice</span>
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($all_warnings as $w):
                                $s_ts = strtotime($w['start_time']);
                                $e_ts = strtotime($w['end_time']);
                                $is_ongoing = ($s_ts <= $now_time && $e_ts > $now_time);
                                $is_past    = ($e_ts < $now_time);
                                $u_color    = !empty($w['color_code']) ? htmlspecialchars($w['color_code']) : '#d97706';
                                $u_type     = htmlspecialchars($w['utility_type']);
                                $t_clean    = strtolower($w['utility_type']);
                                
                                $icon_name = 'alert-circle';
                                if (str_contains($t_clean, 'power') || str_contains($t_clean, 'electr')) $icon_name = 'zap';
                                elseif (str_contains($t_clean, 'water')) $icon_name = 'droplets';
                                elseif (str_contains($t_clean, 'road') || str_contains($t_clean, 'trans')) $icon_name = 'construction';
                                elseif (str_contains($t_clean, 'gas')) $icon_name = 'flame';
                                elseif (str_contains($t_clean, 'waste') || str_contains($t_clean, 'drain')) $icon_name = 'trash-2';
                            ?>
                            <div class="rounded-xl border border-slate-200 bg-white p-4 flex flex-col justify-between hover:border-amber-300 transition-all shadow-xs <?= $is_past ? 'opacity-60 bg-slate-50/50' : '' ?>">
                                <div>
                                    <div class="flex items-center justify-between gap-2 mb-2.5">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold text-white shadow-xs" style="background-color: <?= $u_color ?>;">
                                            <i data-lucide="<?= $icon_name ?>" class="w-3 h-3"></i>
                                            <?= htmlspecialchars(strtoupper($u_type)) ?>
                                        </span>

                                        <?php if ($is_ongoing): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-600 animate-pulse"></span>
                                                LIVE NOW
                                            </span>
                                        <?php elseif ($is_past): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-500">
                                                <i data-lucide="check" class="w-3 h-3"></i>
                                                CONCLUDED
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                                                <i data-lucide="clock" class="w-3 h-3"></i>
                                                SCHEDULED
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <h4 class="font-display font-bold text-sm text-slate-900 mb-1.5 leading-snug">
                                        <?= htmlspecialchars($w['title']) ?>
                                    </h4>

                                    <p class="text-xs text-slate-600 line-clamp-3 leading-relaxed mb-3">
                                        <?= nl2br(htmlspecialchars($w['description'])) ?>
                                    </p>
                                </div>

                                <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                                    <div class="flex items-center gap-1.5">
                                        <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                                        <span><?= date('M d, H:i', $s_ts) ?> &ndash; <?= date('M d, H:i', $e_ts) ?></span>
                                    </div>
                                    <a href="dashboard.php?delete=<?= $w['warning_id'] ?>"
                                       onclick="return confirm('Permanently remove this outage bulletin?');"
                                       class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors"
                                       title="Remove Bulletin">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── TAB 2: Resident Complaints ── -->
                    <div id="panel-adm-complaints" class="hidden">
                        <?php if (empty($all_complaints)): ?>
                        <div class="py-12 px-4 text-center max-w-md mx-auto">
                            <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-500 flex items-center justify-center mx-auto mb-3.5 border border-slate-200">
                                <i data-lucide="inbox" class="w-7 h-7"></i>
                            </div>
                            <h3 class="font-display font-bold text-base text-slate-900 mb-1">No Citizen Complaints</h3>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                No incident reports or utility complaints have been lodged by residents yet.
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-slate-600">
                                <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 uppercase tracking-wider font-semibold text-[11px]">
                                    <tr>
                                        <th class="py-3 px-4">Ref &amp; Date</th>
                                        <th class="py-3 px-4">Resident &amp; Subject</th>
                                        <th class="py-3 px-4">Category</th>
                                        <th class="py-3 px-4">Current Status</th>
                                        <th class="py-3 px-4 text-right">Update Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($all_complaints as $c):
                                        $st = strtolower($c['status'] ?? '');
                                        $badge_style = 'bg-slate-100 text-slate-700 border-slate-200';
                                        if (str_contains($st, 'resolve')) {
                                            $badge_style = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                        } elseif (str_contains($st, 'progress')) {
                                            $badge_style = 'bg-blue-50 text-blue-700 border-blue-200';
                                        } elseif (str_contains($st, 'pending') || str_contains($st, 'review')) {
                                            $badge_style = 'bg-amber-50 text-amber-800 border-amber-200';
                                        }
                                    ?>
                                    <tr class="hover:bg-slate-50/70 transition-colors">
                                        <td class="py-3.5 px-4 font-mono">
                                            <span class="font-bold text-slate-900">#<?= $c['complaint_id'] ?></span>
                                            <div class="text-[11px] text-slate-400 font-sans mt-0.5"><?= date('M d, Y', strtotime($c['created_at'])) ?></div>
                                        </td>
                                        <td class="py-3.5 px-4 max-w-xs">
                                            <div class="font-semibold text-slate-900 text-xs truncate" title="<?= htmlspecialchars($c['title']) ?>">
                                                <?= htmlspecialchars($c['title']) ?>
                                            </div>
                                            <div class="text-[11px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                                                <span>From: <strong class="text-slate-700"><?= htmlspecialchars($c['resident_name']) ?></strong></span>
                                                <?php if (!empty($c['contact_info'])): ?>
                                                    <span>&bull; <?= htmlspecialchars($c['contact_info']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                                <?= htmlspecialchars($c['utility_type']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold border <?= $badge_style ?>">
                                                <?= htmlspecialchars($c['status']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 text-right">
                                            <form method="POST" action="dashboard.php" class="inline-flex items-center gap-1.5">
                                                <input type="hidden" name="complaint_id" value="<?= $c['complaint_id'] ?>">
                                                <select name="status" class="text-xs bg-white border border-slate-300 rounded-lg py-1 px-2 text-slate-700 focus:ring-1 focus:ring-amber-500 focus:border-amber-500 outline-none">
                                                    <option value="Pending Review" <?= $c['status']==='Pending Review'?'selected':'' ?>>Pending</option>
                                                    <option value="In Progress"    <?= $c['status']==='In Progress'?'selected':'' ?>>In Progress</option>
                                                    <option value="Resolved"       <?= $c['status']==='Resolved'?'selected':'' ?>>Resolved</option>
                                                </select>
                                                <button type="submit" name="update_status" class="p-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold transition-colors shadow-xs" title="Save Status">
                                                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                                </button>
                                                <a href="dashboard.php?delete_complaint=<?= $c['complaint_id'] ?>"
                                                   onclick="return confirm('Delete this complaint entry permanently?');"
                                                   class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors"
                                                   title="Delete Record">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                </a>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── TAB 3: Alerts & System Logs ── -->
                    <div id="panel-adm-notifs" class="hidden p-5 sm:p-6">
                        <?php if (empty($admin_notifs)): ?>
                        <div class="py-12 px-4 text-center max-w-md mx-auto">
                            <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-500 flex items-center justify-center mx-auto mb-3.5 border border-slate-200">
                                <i data-lucide="bell-off" class="w-7 h-7"></i>
                            </div>
                            <h3 class="font-display font-bold text-base text-slate-900 mb-1">No System Notifications</h3>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                Your municipal operational log is clear with no new alerts.
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($admin_notifs as $an): ?>
                            <div class="py-3.5 flex items-start gap-3.5 <?= !$an['is_read'] ? 'bg-amber-50/50 -mx-5 px-5 rounded-xl' : '' ?>">
                                <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <i data-lucide="activity" class="w-4 h-4"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs text-slate-800 leading-relaxed">
                                        <?= htmlspecialchars($an['message']) ?>
                                    </p>
                                    <span class="text-[11px] text-slate-400 mt-1 inline-flex items-center gap-1">
                                        <i data-lucide="clock" class="w-3 h-3"></i>
                                        <?= time_ago($an['created_at']) ?>
                                    </span>
                                </div>
                                <?php if (!$an['is_read']): ?>
                                <span class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0 mt-2"></span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>

            </div><!-- /xl:col-span-8 -->

            <!-- ── RIGHT COLUMN: BROADCAST OUTAGE WARNING CARD (4 COLS) ── -->
            <div class="xl:col-span-4 space-y-6">

                <!-- Quick Broadcast / Warning Form -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    
                    <div class="p-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 text-slate-950 flex items-center justify-center shadow-xs font-bold">
                            <i data-lucide="megaphone" class="w-4 h-4 text-slate-950"></i>
                        </div>
                        <div>
                            <h3 class="font-display font-bold text-sm text-slate-900">Broadcast Outage Warning</h3>
                            <p class="text-[11px] text-slate-500">Publish immediate notices to resident dashboards</p>
                        </div>
                    </div>

                    <form method="POST" action="dashboard.php" class="p-5 space-y-4">

                        <!-- Utility Category -->
                        <div>
                            <label for="utility_type" class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Utility Category <span class="text-amber-600">*</span>
                            </label>
                            <select name="utility_type" id="utility_type" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-900 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all">
                                <option value="Electricity">⚡ Electricity (CEB / Power)</option>
                                <option value="Water">💧 Water Supply (NWSDB)</option>
                                <option value="Roads">🚧 Road Maintenance / Closure</option>
                                <option value="Waste">♻️ Waste Management & Sanitation</option>
                                <option value="Other">📋 Other Municipal Services</option>
                            </select>
                        </div>

                        <!-- Severity / Urgency Theme Color -->
                        <div>
                            <label for="color_code" class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Urgency / Notice Severity
                            </label>
                            <select name="color_code" id="color_code" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-900 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all">
                                <option value="#d97706" selected>🟠 Official Municipal Warning (#d97706)</option>
                                <option value="#f59e0b">🟡 Scheduled Maintenance Interruption (#f59e0b)</option>
                                <option value="#dc2626">🔴 Critical Emergency Outage (#dc2626)</option>
                                <option value="#0284c7">🔵 Routine Utility Notice (#0284c7)</option>
                            </select>
                        </div>

                        <!-- Bulletin Title -->
                        <div>
                            <label for="title" class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Bulletin Title <span class="text-amber-600">*</span>
                            </label>
                            <input type="text" name="title" id="title" required
                                   placeholder="e.g. Zone 4 Transformer Upgrade Interruption"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-900 text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all">
                        </div>

                        <!-- Details & Affected Areas -->
                        <div>
                            <label for="description" class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Affected Sectors &amp; Details <span class="text-amber-600">*</span>
                            </label>
                            <textarea name="description" id="description" rows="3" required
                                      placeholder="List affected roads, reason for interruption, and emergency contacts..."
                                      class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-900 text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all resize-y"></textarea>
                        </div>

                        <!-- Start and End Time (Grid) -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="start_time" class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                    Start Time <span class="text-amber-600">*</span>
                                </label>
                                <input type="datetime-local" name="start_time" id="start_time" required
                                       min="<?= date('Y-m-d\TH:i') ?>"
                                       class="w-full px-3 py-2 rounded-xl border border-slate-300 bg-white text-slate-900 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all">
                            </div>
                            <div>
                                <label for="end_time" class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                    Restoration ETA <span class="text-amber-600">*</span>
                                </label>
                                <input type="datetime-local" name="end_time" id="end_time" required
                                       min="<?= date('Y-m-d\TH:i') ?>"
                                       class="w-full px-3 py-2 rounded-xl border border-slate-300 bg-white text-slate-900 text-xs focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all">
                            </div>
                        </div>

                        <!-- Standardized Amber-Gold Primary Action Button -->
                        <div class="pt-2">
                            <button type="submit" name="add_warning" class="w-full py-3 px-4 bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500 hover:from-amber-600 hover:to-amber-500 text-slate-950 font-black text-sm rounded-xl shadow-md shadow-amber-950/20 transition-all flex items-center justify-center gap-2 group">
                                <i data-lucide="radio" class="w-4 h-4 text-slate-950 group-hover:scale-110 transition-transform"></i>
                                <span>Broadcast Warning to Residents</span>
                            </button>
                        </div>

                    </form>

                </div>

                <!-- Municipal Operational Protocols Card (Charcoal & Amber) -->
                <div class="bg-[#111317] text-amber-100 rounded-2xl p-5 border border-amber-500/20 shadow-sm">
                    <div class="flex items-center gap-2.5 mb-3 text-amber-300 font-display font-bold text-xs uppercase tracking-wider">
                        <i data-lucide="shield-alert" class="w-4 h-4 text-amber-400"></i>
                        <span>Municipal Protocol</span>
                    </div>
                    <ul class="space-y-2 text-xs text-slate-300 leading-relaxed">
                        <li class="flex items-start gap-2">
                            <i data-lucide="check" class="w-3.5 h-3.5 text-amber-400 mt-0.5 flex-shrink-0"></i>
                            <span>All published bulletins immediately synchronize with resident mobile PWAs and the public web portal.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i data-lucide="check" class="w-3.5 h-3.5 text-amber-400 mt-0.5 flex-shrink-0"></i>
                            <span>Schedule interruptions at least 24 hours in advance whenever non-emergency maintenance is conducted.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i data-lucide="check" class="w-3.5 h-3.5 text-amber-400 mt-0.5 flex-shrink-0"></i>
                            <span>Concluded notices remain archived in municipal audit logs.</span>
                        </li>
                    </ul>
                </div>

            </div><!-- /xl:col-span-4 -->

        </div><!-- /grid -->

    </main>

    <!-- ════════════════ FOOTER ════════════════ -->
    <footer class="bg-white border-t border-slate-200 mt-12 py-6 text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                <span>Balangoda Urban Council &bull; Municipal Utility Dispatch Console</span>
            </div>
            <div class="text-slate-400">
                Official Executive Administration Portal &bull; UTF-8 Verified
            </div>
        </div>
    </footer>

    <!-- ════════════════ MODAL: ADMIN PROFILE & SECURITY SETTINGS ════════════════ -->
    <div id="editAdminProfileModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 backdrop-blur-xs p-4 hidden">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden transform transition-all">
            
            <!-- Modal Header -->
            <div class="bg-[#111317] px-6 py-4 flex items-center justify-between border-b border-amber-500/20 text-white">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-amber-400">
                        <i data-lucide="user-cog" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-bold text-sm text-white">Administrator Profile &amp; Settings</h3>
                        <p class="text-[11px] text-amber-400/80">Manage municipal credentials and personal details</p>
                    </div>
                </div>
                <button type="button" onclick="closeProfileModal()" class="text-slate-400 hover:text-white transition-colors p-1 rounded-lg cursor-pointer">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <!-- Modal Internal Tabs -->
            <div class="flex border-b border-slate-200 bg-slate-50/70 px-6 pt-2 gap-2">
                <button type="button" id="adm-prof-tab-info-btn" onclick="switchAdminProfileModalTab('info')"
                    class="pb-2.5 px-3 text-xs font-bold border-b-2 border-amber-500 text-amber-900 flex items-center gap-1.5 transition-colors cursor-pointer">
                    <i data-lucide="user" class="w-3.5 h-3.5 text-amber-600"></i>
                    <span>Profile Details</span>
                </button>
                <button type="button" id="adm-prof-tab-pw-btn" onclick="switchAdminProfileModalTab('pw')"
                    class="pb-2.5 px-3 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition-colors cursor-pointer">
                    <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
                    <span>Change Password</span>
                </button>
            </div>

            <!-- Tab 1: Profile Details Form -->
            <div id="adm-prof-panel-info">
                <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update_admin_profile">

                    <!-- Avatar Preview & Upload -->
                    <div class="text-center pb-2">
                        <div class="relative inline-block">
                            <?php if (!empty($admin_profile_pic) && file_exists('../' . $admin_profile_pic)): ?>
                                <img src="../<?= htmlspecialchars($admin_profile_pic) ?>" class="w-20 h-20 rounded-2xl object-cover ring-2 ring-amber-400/60 mx-auto" alt="">
                            <?php else: ?>
                                <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-slate-950 font-display font-black text-2xl flex items-center justify-center mx-auto shadow-md">
                                    <?= mb_strtoupper(mb_substr($admin_display_name, 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="absolute -bottom-1 -right-1 bg-amber-500 text-slate-950 p-1.5 rounded-lg border border-amber-400 shadow-xs">
                                <i data-lucide="camera" class="w-3 h-3 text-slate-950"></i>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">Avatar Image</label>
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"
                               class="w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                        <p class="text-[11px] text-slate-400 mt-1">Recommended: Square PNG/JPG under 2MB.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">Full Name <span class="text-amber-600">*</span></label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($admin_info['full_name'] ?? '') ?>" required
                               class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">Official Contact Phone</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($admin_info['phone'] ?? '') ?>"
                               placeholder="+94 45 228 7223"
                               class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">Municipal Department / Office</label>
                        <textarea name="address" rows="2"
                                  class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none resize-none"><?= htmlspecialchars($admin_info['address'] ?? '') ?></textarea>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                        <button type="button" onclick="closeProfileModal()" class="px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-slate-950 transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            <i data-lucide="check" class="w-3.5 h-3.5"></i>
                            <span>Save Profile Details</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab 2: Change Password Form -->
            <div id="adm-prof-panel-pw" class="hidden">
                <form method="POST" action="dashboard.php" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="change_admin_password_modal">

                    <div class="p-3 rounded-xl bg-amber-50/70 border border-amber-200 text-amber-950 text-xs flex items-center gap-2.5">
                        <i data-lucide="shield-check" class="w-4 h-4 text-amber-600 flex-shrink-0"></i>
                        <span>Update your administrative password. Minimum 6 characters required.</span>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                            Current Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="current_password" id="adm_cur_pw" required
                                   placeholder="Enter current password"
                                   class="w-full px-3.5 py-2 pr-10 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none">
                            <button type="button" onclick="toggleModalPw('adm_cur_pw', 'eye_adm_cur')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer">
                                <i data-lucide="eye" id="eye_adm_cur" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                            New Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="new_password" id="adm_new_pw" required minlength="6"
                                   placeholder="Minimum 6 characters"
                                   class="w-full px-3.5 py-2 pr-10 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none">
                            <button type="button" onclick="toggleModalPw('adm_new_pw', 'eye_adm_new')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer">
                                <i data-lucide="eye" id="eye_adm_new" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                            Confirm New Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="confirm_password" id="adm_conf_pw" required minlength="6"
                                   placeholder="Confirm new password"
                                   class="w-full px-3.5 py-2 pr-10 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none">
                            <button type="button" onclick="toggleModalPw('adm_conf_pw', 'eye_adm_conf')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer">
                                <i data-lucide="eye" id="eye_adm_conf" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2.5">
                        <button type="button" onclick="closeProfileModal()"
                                class="px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                            <span>Update Password</span>
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <!-- ════════════════ CLIENT SCRIPTS ════════════════ -->
    <script>
        lucide.createIcons();

        function switchAdminTab(tab) {
            var tabs = ['outages', 'complaints', 'notifs'];
            tabs.forEach(function(t) {
                var btn = document.getElementById('tab-adm-' + t + '-btn');
                var panel = document.getElementById('panel-adm-' + t);
                if (btn) {
                    if (t === tab) {
                        btn.className = "tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-amber-500 text-amber-900 transition-colors whitespace-nowrap";
                    } else {
                        btn.className = "tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition-colors whitespace-nowrap";
                    }
                }
                if (panel) {
                    if (t === tab) {
                        panel.classList.remove('hidden');
                    } else {
                        panel.classList.add('hidden');
                    }
                }
            });
            lucide.createIcons();
        }

        function switchAdminProfileModalTab(tab) {
            var infoBtn = document.getElementById('adm-prof-tab-info-btn');
            var pwBtn = document.getElementById('adm-prof-tab-pw-btn');
            var infoPanel = document.getElementById('adm-prof-panel-info');
            var pwPanel = document.getElementById('adm-prof-panel-pw');

            if (tab === 'pw') {
                if (pwBtn) pwBtn.className = "pb-2.5 px-3 text-xs font-bold border-b-2 border-amber-500 text-amber-900 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (infoBtn) infoBtn.className = "pb-2.5 px-3 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (infoPanel) infoPanel.classList.add('hidden');
                if (pwPanel) pwPanel.classList.remove('hidden');
            } else {
                if (infoBtn) infoBtn.className = "pb-2.5 px-3 text-xs font-bold border-b-2 border-amber-500 text-amber-900 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (pwBtn) pwBtn.className = "pb-2.5 px-3 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (infoPanel) infoPanel.classList.remove('hidden');
                if (pwPanel) pwPanel.classList.add('hidden');
            }
            lucide.createIcons();
        }

        function openProfileModal(tab) {
            var m = document.getElementById('editAdminProfileModal');
            if (m) {
                switchAdminProfileModalTab(tab === 'pw' ? 'pw' : 'info');
                m.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }
        }

        function closeProfileModal() {
            var m = document.getElementById('editAdminProfileModal');
            if (m) {
                m.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        }

        function openPasswordModal() {
            openProfileModal('pw');
        }

        function closePasswordModal() {
            closeProfileModal();
        }

        function toggleModalPw(inputId, iconId) {
            var inp = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
            if (inp && icon) {
                var show = inp.type === 'password';
                inp.type = show ? 'text' : 'password';
                icon.setAttribute('data-lucide', show ? 'eye-off' : 'eye');
                lucide.createIcons();
            }
        }

        var profileModalOverlay = document.getElementById('editAdminProfileModal');
        if (profileModalOverlay) {
            profileModalOverlay.addEventListener('click', function(e) {
                if (e.target === profileModalOverlay) closeProfileModal();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProfileModal();
            }
        });

        // Date synchronizer between Start and End time
        var startInput = document.getElementById('start_time');
        var endInput   = document.getElementById('end_time');
        if (startInput && endInput) {
            startInput.addEventListener('change', function() {
                if (this.value) {
                    endInput.min = this.value;
                    if (endInput.value && endInput.value < this.value) {
                        endInput.value = this.value;
                    }
                }
            });
        }
    </script>
</body>
</html>
