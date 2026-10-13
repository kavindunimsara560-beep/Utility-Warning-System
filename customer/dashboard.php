<?php
session_start();
if (!isset($_SESSION['customer_id'])) {
    header("Location: login_page.php?msg=login_required");
    exit();
}

require_once '../config/db_connect.php';
require_once '../config/profile_helper.php';
ensure_profile_schema($conn);

$customer_id = (int) $_SESSION['customer_id'];

// Handle Profile / Residence Update from Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile_modal') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $elec_no = trim($_POST['electricity_bill_no'] ?? '');
    $water_no = trim($_POST['water_bill_no'] ?? '');

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
            $_SESSION['customer_name'] = $full_name;
            $_SESSION['customer_phone'] = $phone;
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
        $flash = ['type' => 'danger', 'text' => htmlspecialchars($err)];
    }
}

// Handle Password Change from Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password_modal') {
    $cur_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';

    $pw_chk = $conn->prepare("SELECT password_hash FROM customers WHERE customer_id = ?");
    $pw_chk->bind_param("i", $customer_id);
    $pw_chk->execute();
    $curr_hash = $pw_chk->get_result()->fetch_assoc()['password_hash'] ?? '';
    $pw_chk->close();

    $pw_err = '';
    if (empty($cur_pass) || empty($new_pass) || empty($conf_pass)) {
        $pw_err = "All password fields are required.";
    } elseif (!password_verify($cur_pass, $curr_hash)) {
        $pw_err = "Your current password does not match our records.";
    } elseif (strlen($new_pass) < 6) {
        $pw_err = "New password must be at least 6 characters in length.";
    } elseif ($new_pass !== $conf_pass) {
        $pw_err = "New password and confirmation do not match.";
    }

    if (empty($pw_err)) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE customers SET password_hash = ? WHERE customer_id = ?");
        $upd->bind_param("si", $new_hash, $customer_id);
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
        $flash = ['type' => 'danger', 'text' => htmlspecialchars($pw_err)];
    }
}

// Fetch full customer record
$crec = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
$crec->bind_param("i", $customer_id);
$crec->execute();
$cdata = $crec->get_result()->fetch_assoc();
$crec->close();

$customer_name = htmlspecialchars($_SESSION['customer_name'] ?? ($cdata['full_name'] ?? 'Resident'));
$customer_email = htmlspecialchars($_SESSION['customer_email'] ?? ($cdata['email'] ?? ''));
$customer_phone = htmlspecialchars($_SESSION['customer_phone'] ?? ($cdata['phone'] ?? ''));

// Notice / Flash Messages
$msg_map = [
    'verified' => ['type' => 'success', 'text' => 'Email verified successfully! Welcome to your resident portal.'],
    'already_verified' => ['type' => 'info', 'text' => 'Your email is already verified.'],
    'profile_updated' => ['type' => 'success', 'text' => 'Your profile and residence details have been updated successfully!'],
    'password_updated' => ['type' => 'success', 'text' => 'Your account password has been changed successfully! Your account is secure.'],
];
$flash = $flash ?? (isset($_GET['msg']) ? ($msg_map[$_GET['msg']] ?? null) : null);

// Fetch this customer's complaints (matched by phone or email)
$contact_search = '%' . ($cdata['phone'] ?? '') . '%';
$contact_email = '%' . ($cdata['email'] ?? '') . '%';
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
$warnings = $warnings_res ? $warnings_res->fetch_all(MYSQLI_ASSOC) : [];
$active_outage_count = count($warnings);

// Stats
$total_complaints = count($complaints);
$resolved = count(array_filter($complaints, fn($c) => stripos($c['status'], 'resolve') !== false));
$pending = count(array_filter($complaints, fn($c) => stripos($c['status'], 'pending') !== false));

if (!function_exists('time_ago')) {
    function time_ago($ts)
    {
        $diff = time() - strtotime($ts);
        if ($diff < 60)
            return 'just now';
        if ($diff < 3600)
            return (int) ($diff / 60) . 'm ago';
        if ($diff < 86400)
            return (int) ($diff / 3600) . 'h ago';
        return date('M d, Y', strtotime($ts));
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Portal — Balangoda Municipal Utility System</title>
    <meta name="description"
        content="Resident civic portal for Balangoda Municipal Utility Warning System. Real-time outage bulletins, complaint tracking, and notifications.">
    <link rel="manifest" href="../resident-manifest.json">
    <meta name="theme-color" content="#091322">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800;900&display=swap"
        rel="stylesheet">

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
                        portal: {
                            dark: '#091322',
                            navy: '#0f172a',
                            azure: '#2563eb',
                            cyan: '#0891b2',
                            bright: '#38bdf8',
                        }
                    }
                }
            }
        }
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .font-display {
            font-family: 'Outfit', sans-serif;
        }
    </style>
</head>

<body class="min-h-full flex flex-col bg-slate-50 text-slate-800">

    <!-- ════════════════ TOP NAVBAR (SLEEK DARK NAVY) ════════════════ -->
    <header class="bg-[#091322] border-b border-slate-800/90 sticky top-0 z-40 text-slate-100 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="flex items-center gap-3 group">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-cyan-500 flex items-center justify-center text-white shadow-md shadow-blue-900/40 group-hover:scale-105 transition-transform">
                        <i data-lucide="zap" class="w-5 h-5 text-white"></i>
                    </div>
                    <div>
                        <div class="font-display font-black text-base tracking-tight text-white leading-tight">
                            Balangoda <span class="text-cyan-400">Resident Portal</span>
                        </div>
                        <div class="text-[11px] text-slate-400 font-medium tracking-wider uppercase">
                            Citizen Services &amp; Outage Network
                        </div>
                    </div>
                </a>
            </div>





            <a href="logout.php"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold text-rose-300 hover:text-white hover:bg-rose-950/60 border border-rose-900/60 transition-all">
                <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                <span class="hidden sm:inline">Sign Out</span>
            </a>
        </div>
        </div>
    </header>

    <!-- ════════════════ CONSOLIDATED RESIDENT PROFILE HERO (AZURE / CYAN GRADIENT) ════════════════ -->
    <section
        class="bg-gradient-to-r from-[#091322] via-[#0c1c36] to-[#0f2347] text-white border-b border-slate-800 shadow-inner relative overflow-hidden">
        <!-- Ambient subtle glow -->
        <div
            class="absolute inset-0 bg-[radial-gradient(circle_at_85%_20%,rgba(56,189,248,0.15),transparent_50%)] pointer-events-none">
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-7 relative z-10">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">

                <!-- Resident Identity & Contact (Consolidated, no duplicate on sidebar) -->
                <div class="flex items-start sm:items-center gap-4 sm:gap-5">
                    <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                        <img src="../<?= htmlspecialchars($cdata['profile_pic']) ?>"
                            class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl object-cover ring-2 ring-cyan-400/50 shadow-xl flex-shrink-0"
                            alt="<?= $customer_name ?>">
                    <?php else: ?>
                        <div
                            class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-gradient-to-br from-blue-600 via-blue-700 to-cyan-600 text-white font-display font-black text-2xl sm:text-3xl flex items-center justify-center shadow-xl ring-2 ring-cyan-400/40 flex-shrink-0">
                            <?= mb_strtoupper(mb_substr($customer_name, 0, 1)) ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <div class="flex flex-wrap items-center gap-2 sm:gap-2.5">
                            <h1 class="font-display font-extrabold text-xl sm:text-2xl text-white tracking-tight">
                                <?= $customer_name ?>
                            </h1>
                            <?php if (!empty($cdata['email_verified'])): ?>
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 tracking-wide uppercase">
                                    <i data-lucide="badge-check" class="w-3.5 h-3.5 text-emerald-400"></i>
                                    Verified Resident
                                </span>
                            <?php else: ?>
                                <span
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-800 text-slate-300 border border-slate-700">
                                    Registered Resident
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Consolidated Contact Info -->
                        <div class="flex flex-wrap items-center gap-y-1 gap-x-4 mt-2 text-xs text-slate-300">
                            <span class="inline-flex items-center gap-1.5 text-slate-300">
                                <i data-lucide="mail" class="w-3.5 h-3.5 text-cyan-400"></i>
                                <?= $customer_email ?>
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-slate-300">
                                <i data-lucide="phone" class="w-3.5 h-3.5 text-cyan-400"></i>
                                <?= !empty($customer_phone) ? $customer_phone : 'Phone not set' ?>
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-slate-300 font-medium">
                                <i data-lucide="map-pin" class="w-3.5 h-3.5 text-cyan-400"></i>
                                <?= htmlspecialchars($cdata['address'] ?? 'Balangoda Municipal Area') ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Primary CTAs matching login button -->
                <div class="flex flex-wrap items-center gap-3 self-start lg:self-center">
                    <button type="button" onclick="openProfileModal()"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold bg-slate-800/80 hover:bg-slate-700 text-slate-200 border border-slate-700 transition-all shadow-sm cursor-pointer">
                        <i data-lucide="pencil" class="w-3.5 h-3.5 text-cyan-400"></i>
                        <span>Edit Profile</span>
                    </button>

                    <a href="../submit_complaint.php"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white shadow-md shadow-blue-900/30 hover:shadow-lg transition-all transform hover:-translate-y-0.5">
                        <i data-lucide="alert-triangle" class="w-4 h-4 text-white"></i>
                        <span>Report an Issue</span>
                    </a>
                </div>

            </div>
        </div>
    </section>

    <!-- ════════════════ MAIN CONTENT CANVAS ════════════════ -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1 w-full">

        <!-- Flash Notice -->
        <?php if (!empty($flash)): ?>
            <div
                class="mb-6 rounded-2xl p-4 border flex items-center gap-3 shadow-sm <?= $flash['type'] === 'danger' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
                <i data-lucide="<?= $flash['type'] === 'danger' ? 'alert-circle' : 'check-circle-2' ?>"
                    class="w-5 h-5 flex-shrink-0"></i>
                <span class="text-sm font-medium"><?= $flash['text'] ?></span>
            </div>
        <?php endif; ?>

        <!-- 4 RESIDENT KPI METRIC TILES -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
            <!-- 1. Active Outages -->
            <div
                class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Active Outages</span>
                    <div
                        class="w-10 h-10 rounded-xl <?= $active_outage_count > 0 ? 'bg-rose-50 text-rose-600 border border-rose-200/80' : 'bg-emerald-50 text-emerald-600 border border-emerald-200/80' ?> flex items-center justify-center">
                        <i data-lucide="radio-tower" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $active_outage_count ?>
                    </div>
                    <?php if ($active_outage_count > 0): ?>
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                            <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                            Live Interruption
                        </span>
                    <?php else: ?>
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            All Clear
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-slate-500">Balangoda utility supply status</div>
            </div>

            <!-- 2. Total Complaints -->
            <div
                class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">My Reports</span>
                    <div
                        class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-700 border border-cyan-200/80 flex items-center justify-center">
                        <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $total_complaints ?>
                    </div>
                    <span
                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-cyan-50 text-cyan-700 border border-cyan-200">
                        Total Logged
                    </span>
                </div>
                <div class="mt-2 text-xs text-slate-500">Tickets submitted from this account</div>
            </div>

            <!-- 3. Pending Review -->
            <div
                class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Under Review</span>
                    <div
                        class="w-10 h-10 rounded-xl <?= $pending > 0 ? 'bg-amber-50 text-amber-600 border border-amber-200/80' : 'bg-emerald-50 text-emerald-600 border border-emerald-200/80' ?> flex items-center justify-center">
                        <i data-lucide="clock-3" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $pending ?>
                    </div>
                    <?php if ($pending > 0): ?>
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                            In Progress
                        </span>
                    <?php else: ?>
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            Resolved
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-slate-500">Awaiting municipal crew closure</div>
            </div>

            <!-- 4. Notifications -->
            <div
                class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">New Alerts</span>
                    <div
                        class="w-10 h-10 rounded-xl <?= $unread_count > 0 ? 'bg-blue-50 text-blue-600 border border-blue-200/80' : 'bg-slate-100 text-slate-600 border border-slate-200' ?> flex items-center justify-center">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="font-display font-extrabold text-3xl text-slate-900 tracking-tight">
                        <?= $unread_count ?>
                    </div>
                    <?php if ($unread_count > 0): ?>
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                            Unread
                        </span>
                    <?php else: ?>
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600">
                            All Caught Up
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-slate-500">Status updates regarding your tickets</div>
            </div>
        </div>

        <!-- SERVICE STATUS ALERT BANNER -->
        <?php if (!empty($warnings)):
            $now_t = time();
            $has_crit = false;
            foreach ($warnings as $w_i) {
                if (strtotime($w_i['start_time']) <= $now_t && strtotime($w_i['end_time']) > $now_t) {
                    $has_crit = true;
                    break;
                }
            }
            ?>
            <div
                class="bg-amber-50 border border-amber-200/90 text-amber-900 rounded-2xl px-5 py-3.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6 shadow-sm">
                <div class="flex items-center gap-3">
                    <div
                        class="w-8 h-8 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <span class="font-bold text-sm">
                            <?= $has_crit ? 'Active Municipal Outage Interruption Ongoing' : 'Scheduled Utility Maintenance Advisory' ?>
                        </span>
                        <span class="text-xs text-amber-800 ml-1.5 block sm:inline">
                            &mdash; <?= count($warnings) ?> bulletin<?= count($warnings) > 1 ? 's are' : ' is' ?> currently
                            active in the Balangoda service zone.
                        </span>
                    </div>
                </div>
                <span
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300 flex-shrink-0 self-start sm:self-auto">
                    <span class="w-2 h-2 rounded-full bg-amber-600 animate-pulse"></span>
                    ACTIVE ADVISORY
                </span>
            </div>
        <?php else: ?>
            <div
                class="bg-emerald-50 border border-emerald-200/80 text-emerald-800 rounded-xl px-4 py-3 flex items-center justify-between mb-6 shadow-sm">
                <div class="flex items-center gap-3">
                    <div
                        class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="shield-check" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <span class="font-bold text-sm">All Municipal Utility Services Running Routinely</span>
                        <span class="text-xs text-emerald-700 ml-1.5 block sm:inline">
                            &mdash; No electricity, water, or road interruptions published for your area.
                        </span>
                    </div>
                </div>
                <span
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 flex-shrink-0">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    SYSTEM NOMINAL
                </span>
            </div>
        <?php endif; ?>

        <!-- BALANCED 2-COLUMN GRID (8 COLS DATA / 4 COLS SIDEBAR) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- ── LEFT COLUMN (8 COLS): OUTAGES, COMPLAINTS & NOTIFICATIONS ── -->
            <div class="lg:col-span-8 space-y-6">

                <!-- Active Outage Bulletins (if any) -->
                <?php if (!empty($warnings)): ?>
                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5 sm:p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-pulse"></span>
                                <h3 class="font-display font-bold text-base text-slate-900">Active Outage Bulletins</h3>
                            </div>
                            <span
                                class="text-xs font-bold text-rose-700 bg-rose-50 px-2.5 py-0.5 rounded-full border border-rose-200">
                                <?= count($warnings) ?> Active
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($warnings as $w):
                                $s_ts = strtotime($w['start_time']);
                                $e_ts = strtotime($w['end_time']);
                                $now_val = time();
                                $is_live = ($s_ts <= $now_val && $e_ts > $now_val);
                                $u_col = !empty($w['color_code']) ? htmlspecialchars($w['color_code']) : '#2563eb';
                                $u_tp = htmlspecialchars($w['utility_type']);
                                $t_low = strtolower($w['utility_type']);

                                $icon_tag = 'alert-circle';
                                if (str_contains($t_low, 'power') || str_contains($t_low, 'electr'))
                                    $icon_tag = 'zap';
                                elseif (str_contains($t_low, 'water'))
                                    $icon_tag = 'droplets';
                                elseif (str_contains($t_low, 'road') || str_contains($t_low, 'trans'))
                                    $icon_tag = 'construction';
                                elseif (str_contains($t_low, 'gas'))
                                    $icon_tag = 'flame';
                                elseif (str_contains($t_low, 'waste') || str_contains($t_low, 'drain'))
                                    $icon_tag = 'trash-2';
                                ?>
                                <div
                                    class="rounded-xl border border-slate-200 p-4 bg-white flex flex-col justify-between hover:border-slate-300 transition-all shadow-xs">
                                    <div>
                                        <div class="flex items-center justify-between gap-2 mb-2">
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold text-white shadow-xs"
                                                style="background-color: <?= $u_col ?>;">
                                                <i data-lucide="<?= $icon_tag ?>" class="w-3 h-3"></i>
                                                <?= strtoupper($u_tp) ?>
                                            </span>
                                            <?php if ($is_live): ?>
                                                <span
                                                    class="text-[11px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-200 inline-flex items-center gap-1">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span> LIVE
                                                </span>
                                            <?php else: ?>
                                                <span
                                                    class="text-[11px] font-semibold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200">
                                                    SCHEDULED
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <h4 class="font-display font-bold text-sm text-slate-900 mb-1 leading-snug">
                                            <?= htmlspecialchars($w['title']) ?>
                                        </h4>
                                        <p class="text-xs text-slate-600 line-clamp-3 leading-relaxed mb-3">
                                            <?= nl2br(htmlspecialchars($w['description'])) ?>
                                        </p>
                                    </div>

                                    <div
                                        class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                                        <span class="inline-flex items-center gap-1">
                                            <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                                            <?= date('M d, H:i', $s_ts) ?> &ndash; <?= date('M d, H:i', $e_ts) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tabbed Container: Complaints & Notifications -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">

                    <!-- Modern Azure / Cyan Tabs -->
                    <div
                        class="border-b border-slate-200 bg-slate-50/70 px-4 pt-3 flex items-center gap-2 overflow-x-auto">
                        <button type="button" onclick="switchResidentTab('complaints')" id="tab-res-complaints-btn"
                            class="tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-blue-600 text-blue-600 transition-colors whitespace-nowrap">
                            <i data-lucide="message-square" class="w-4 h-4"></i>
                            <span>My Incident Reports</span>
                            <span
                                class="text-xs px-2 py-0.5 rounded-full font-bold <?= $total_complaints > 0 ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-200/70 text-slate-700' ?>">
                                <?= $total_complaints ?>
                            </span>
                        </button>

                        <button type="button" onclick="switchResidentTab('notifs')" id="tab-res-notifs-btn"
                            class="tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition-colors whitespace-nowrap">
                            <i data-lucide="bell" class="w-4 h-4"></i>
                            <span>Notifications &amp; Dispatch Updates</span>
                            <?php if ($unread_count > 0): ?>
                                <span
                                    class="text-xs px-2 py-0.5 rounded-full font-bold bg-blue-100 text-blue-700"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <!-- Panel 1: Complaints -->
                    <div id="panel-res-complaints" class="p-5 sm:p-6">
                        <?php if (empty($complaints)): ?>
                            <div class="py-12 px-4 text-center max-w-md mx-auto">
                                <div
                                    class="w-14 h-14 rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center mx-auto mb-3.5 border border-cyan-100">
                                    <i data-lucide="inbox" class="w-7 h-7"></i>
                                </div>
                                <h3 class="font-display font-bold text-base text-slate-900 mb-1">No Incident Reports Logged
                                </h3>
                                <p class="text-xs text-slate-500 leading-relaxed mb-5">
                                    Experiencing a power breakdown, water supply cutoff, or municipal infrastructure issue?
                                    Lodge a ticket to notify dispatch teams immediately.
                                </p>
                                <a href="../submit_complaint.php"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white transition-all shadow-sm">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                    <span>Report an Issue Now</span>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs font-semibold text-slate-500">Recorded Citizen Tickets</span>
                                <a href="../submit_complaint.php"
                                    class="text-xs font-bold text-blue-600 hover:text-cyan-600 inline-flex items-center gap-1">
                                    <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i>
                                    <span>New Report</span>
                                </a>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs text-slate-600">
                                    <thead
                                        class="bg-slate-50 border-b border-slate-200 text-slate-500 uppercase tracking-wider font-semibold text-[11px]">
                                        <tr>
                                            <th class="py-3 px-3.5">Ref #</th>
                                            <th class="py-3 px-3.5">Subject &amp; Details</th>
                                            <th class="py-3 px-3.5">Utility</th>
                                            <th class="py-3 px-3.5">Status</th>
                                            <th class="py-3 px-3.5 text-right">Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($complaints as $c):
                                            $st = strtolower($c['status'] ?? '');
                                            $badge_cls = 'bg-slate-100 text-slate-700 border-slate-200';
                                            if (str_contains($st, 'resolve')) {
                                                $badge_cls = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                            } elseif (str_contains($st, 'progress')) {
                                                $badge_cls = 'bg-cyan-50 text-cyan-700 border-cyan-200';
                                            } elseif (str_contains($st, 'pending') || str_contains($st, 'review')) {
                                                $badge_cls = 'bg-amber-50 text-amber-800 border-amber-200';
                                            }
                                            ?>
                                            <tr class="hover:bg-slate-50/70 transition-colors">
                                                <td class="py-3 px-3.5 font-mono font-bold text-slate-900">
                                                    #<?= $c['complaint_id'] ?>
                                                </td>
                                                <td class="py-3 px-3.5 max-w-xs">
                                                    <div class="font-semibold text-slate-900 text-xs truncate"
                                                        title="<?= htmlspecialchars($c['title']) ?>">
                                                        <?= htmlspecialchars($c['title']) ?>
                                                    </div>
                                                    <div class="text-[11px] text-slate-500 truncate mt-0.5">
                                                        <?= htmlspecialchars(mb_strimwidth($c['description'], 0, 70, '...')) ?>
                                                    </div>
                                                </td>
                                                <td class="py-3 px-3.5">
                                                    <span
                                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                                        <?= htmlspecialchars($c['utility_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-3.5">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border <?= $badge_cls ?>">
                                                        <?= htmlspecialchars($c['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-3.5 text-right text-slate-400 text-[11px]">
                                                    <?= time_ago($c['created_at']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Panel 2: Notifications -->
                    <div id="panel-res-notifs" class="hidden p-5 sm:p-6">
                        <?php if (empty($notifs)): ?>
                            <div class="py-12 px-4 text-center max-w-md mx-auto">
                                <div
                                    class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-500 flex items-center justify-center mx-auto mb-3.5 border border-slate-200">
                                    <i data-lucide="bell-off" class="w-7 h-7"></i>
                                </div>
                                <h3 class="font-display font-bold text-base text-slate-900 mb-1">No New Notifications</h3>
                                <p class="text-xs text-slate-500 leading-relaxed">
                                    You will receive real-time notices when municipal dispatch crews review or resolve your
                                    complaints.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($notifs as $n): ?>
                                    <div
                                        class="py-3.5 flex items-start gap-3.5 <?= !$n['is_read'] ? 'bg-cyan-50/40 -mx-5 px-5 rounded-xl' : '' ?>">
                                        <div
                                            class="w-8 h-8 rounded-lg bg-cyan-100 text-cyan-700 flex items-center justify-center flex-shrink-0 mt-0.5">
                                            <i data-lucide="bell" class="w-4 h-4"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-xs text-slate-800 leading-relaxed">
                                                <?= htmlspecialchars($n['message']) ?>
                                            </p>
                                            <span class="text-[11px] text-slate-400 mt-1 inline-flex items-center gap-1">
                                                <i data-lucide="clock" class="w-3 h-3"></i>
                                                <?= time_ago($n['created_at']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            </div><!-- /lg:col-span-8 -->

            <!-- ── RIGHT COLUMN (4 COLS): CLEAN SIDEBAR (NO DUPLICATE PROFILE) ── -->
            <div class="lg:col-span-4 space-y-6">

                <!-- 1. Registered Utility Accounts (CEB & NWSDB) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                <i data-lucide="zap" class="w-4 h-4"></i>
                            </div>
                            <h3 class="font-display font-bold text-sm text-slate-900">Utility Accounts</h3>
                        </div>

                    </div>

                    <div class="space-y-3">
                        <!-- Electricity Meter -->
                        <div
                            class="p-3 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-8 h-8 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="zap" class="w-4 h-4"></i>
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-slate-800">Electricity (CEB)</div>
                                    <div class="text-[11px] text-slate-500 font-mono">
                                        <?= !empty($cdata['electricity_bill_no']) ? htmlspecialchars($cdata['electricity_bill_no']) : 'Not configured' ?>
                                    </div>
                                </div>
                            </div>
                            <span
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= !empty($cdata['electricity_bill_no']) ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-200 text-slate-600' ?>">
                                <?= !empty($cdata['electricity_bill_no']) ? 'LINKED' : 'UNSET' ?>
                            </span>
                        </div>

                        <!-- Water Meter -->
                        <div
                            class="p-3 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-8 h-8 rounded-lg bg-cyan-100 text-cyan-700 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="droplets" class="w-4 h-4"></i>
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-slate-800">Water Supply (NWSDB)</div>
                                    <div class="text-[11px] text-slate-500 font-mono">
                                        <?= !empty($cdata['water_bill_no']) ? htmlspecialchars($cdata['water_bill_no']) : 'Not configured' ?>
                                    </div>
                                </div>
                            </div>
                            <span
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= !empty($cdata['water_bill_no']) ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-200 text-slate-600' ?>">
                                <?= !empty($cdata['water_bill_no']) ? 'LINKED' : 'UNSET' ?>
                            </span>
                        </div>
                    </div>

                    <div
                        class="mt-3.5 pt-3 border-t border-slate-100 text-[11px] text-slate-400 flex items-center gap-1.5">
                        <i data-lucide="info" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Meter numbers assist crews with field identification.</span>
                    </div>
                </div>

                <!-- 2. Emergency Helplines (24/7 Fast Dial) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                                <i data-lucide="phone-call" class="w-4 h-4"></i>
                            </div>
                            <h3 class="font-display font-bold text-sm text-slate-900">Emergency Helplines</h3>
                        </div>
                        <span
                            class="text-[10px] font-bold uppercase tracking-wider bg-rose-100 text-rose-800 px-2 py-0.5 rounded-full">24/7
                            Direct</span>
                    </div>

                    <div class="space-y-2.5">
                        <a href="tel:1987"
                            class="p-2.5 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-blue-50/50 flex items-center justify-between transition-all group">
                            <div class="flex items-center gap-2.5">
                                <div
                                    class="w-7 h-7 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center">
                                    <i data-lucide="zap" class="w-3.5 h-3.5"></i>
                                </div>
                                <span class="text-xs font-semibold text-slate-700">Electricity Faults (CEB)</span>
                            </div>
                            <span class="text-xs font-bold text-blue-600 group-hover:underline">1987</span>
                        </a>

                        <a href="tel:1939"
                            class="p-2.5 rounded-xl border border-slate-200 hover:border-cyan-400 hover:bg-cyan-50/50 flex items-center justify-between transition-all group">
                            <div class="flex items-center gap-2.5">
                                <div
                                    class="w-7 h-7 rounded-lg bg-cyan-100 text-cyan-700 flex items-center justify-center">
                                    <i data-lucide="droplets" class="w-3.5 h-3.5"></i>
                                </div>
                                <span class="text-xs font-semibold text-slate-700">Water Leaks &amp; Cuts (NWSDB)</span>
                            </div>
                            <span class="text-xs font-bold text-cyan-700 group-hover:underline">1939</span>
                        </a>

                        <a href="tel:0452287222"
                            class="p-2.5 rounded-xl border border-slate-200 hover:border-slate-400 hover:bg-slate-50 flex items-center justify-between transition-all group">
                            <div class="flex items-center gap-2.5">
                                <div
                                    class="w-7 h-7 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center">
                                    <i data-lucide="building-2" class="w-3.5 h-3.5"></i>
                                </div>
                                <span class="text-xs font-semibold text-slate-700">Balangoda Urban Council</span>
                            </div>
                            <span class="text-xs font-bold text-slate-700 group-hover:underline">045-2287222</span>
                        </a>

                        <a href="tel:119"
                            class="p-2.5 rounded-xl border border-slate-200 hover:border-rose-400 hover:bg-rose-50/50 flex items-center justify-between transition-all group">
                            <div class="flex items-center gap-2.5">
                                <div
                                    class="w-7 h-7 rounded-lg bg-rose-100 text-rose-700 flex items-center justify-center">
                                    <i data-lucide="shield" class="w-3.5 h-3.5"></i>
                                </div>
                                <span class="text-xs font-semibold text-slate-700">Police Emergency</span>
                            </div>
                            <span class="text-xs font-bold text-rose-700 group-hover:underline">119</span>
                        </a>
                    </div>
                </div>

                <!-- 3. Municipal Support Note -->
                <div
                    class="bg-gradient-to-br from-slate-900 to-[#091322] text-white rounded-2xl p-5 border border-slate-800 shadow-sm">
                    <div class="flex items-center gap-2 mb-2">
                        <i data-lucide="heart-handshake" class="w-4 h-4 text-cyan-400"></i>
                        <h4 class="font-display font-bold text-xs uppercase tracking-wider text-cyan-300">Municipal
                            Commitment</h4>
                    </div>
                    <p class="text-xs text-slate-300 leading-relaxed">
                        Balangoda Urban Council response squads inspect recorded complaints during active duty shifts.
                        Ensure your contact phone is up to date for SMS dispatch status updates.
                    </p>
                </div>

            </div><!-- /lg:col-span-4 -->

        </div><!-- /grid -->

    </main>

    <!-- ════════════════ FOOTER ════════════════ -->
    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-500 mt-12">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-cyan-500"></span>
                <span>Balangoda Urban Council &bull; Resident Utility Network</span>
            </div>
            <div class="text-slate-400">
                Official Citizen Portal &bull; UTF-8 Verified
            </div>
        </div>
    </footer>

    <!-- ════════════════ MODAL: EDIT RESIDENT PROFILE & RESIDENCE ════════════════ -->
    <!-- ════════════════ MODAL: RESIDENT PROFILE & SECURITY SETTINGS ════════════════ -->
    <div id="editProfileModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 backdrop-blur-xs p-4 hidden">
        <div
            class="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-lg w-full overflow-hidden transform transition-all">

            <!-- Modal Header -->
            <div class="bg-[#091322] px-6 py-4 flex items-center justify-between border-b border-slate-800 text-white">
                <div class="flex items-center gap-2.5">
                    <div
                        class="w-8 h-8 rounded-xl bg-blue-600/20 border border-blue-500/40 flex items-center justify-center text-cyan-400">
                        <i data-lucide="user-cog" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-bold text-sm text-white">Resident Profile &amp; Settings</h3>
                        <p class="text-[11px] text-slate-400">Manage account information and security credentials</p>
                    </div>
                </div>
                <button type="button" onclick="closeProfileModal()"
                    class="text-slate-400 hover:text-white transition-colors p-1 rounded-lg cursor-pointer">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <!-- Modal Internal Tabs -->
            <div class="flex border-b border-slate-200 bg-slate-50/70 px-6 pt-2 gap-2">
                <button type="button" id="prof-tab-info-btn" onclick="switchProfileModalTab('info')"
                    class="pb-2.5 px-3 text-xs font-bold border-b-2 border-blue-600 text-blue-600 flex items-center gap-1.5 transition-colors cursor-pointer">
                    <i data-lucide="user" class="w-3.5 h-3.5"></i>
                    <span>Profile Details</span>
                </button>
                <button type="button" id="prof-tab-pw-btn" onclick="switchProfileModalTab('pw')"
                    class="pb-2.5 px-3 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition-colors cursor-pointer">
                    <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
                    <span>Change Password</span>
                </button>
            </div>

            <!-- Tab 1: Profile Details Form -->
            <div id="prof-panel-info">
                <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update_profile_modal">

                    <!-- Avatar Preview & Upload -->
                    <div class="flex items-center gap-4 pb-2 border-b border-slate-100">
                        <div class="relative flex-shrink-0">
                            <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                                <img src="../<?= htmlspecialchars($cdata['profile_pic']) ?>"
                                    class="w-16 h-16 rounded-2xl object-cover ring-2 ring-cyan-400/50" alt="">
                            <?php else: ?>
                                <div
                                    class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-600 text-white font-display font-black text-xl flex items-center justify-center shadow-md">
                                    <?= mb_strtoupper(mb_substr($customer_name, 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <label
                                class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">Profile
                                Photo</label>
                            <input type="file" name="avatar" accept="image/png, image/jpeg, image/webp, image/gif"
                                class="w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                            <p class="text-[11px] text-slate-400 mt-1">Square image under 3MB recommended.</p>
                        </div>
                    </div>

                    <!-- Personal Info -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                                Full Name <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="full_name"
                                value="<?= htmlspecialchars($cdata['full_name'] ?? '') ?>" required
                                class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                                Contact Phone <span class="text-rose-500">*</span>
                            </label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($cdata['phone'] ?? '') ?>"
                                required placeholder="07X XXX XXXX"
                                class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none">
                        </div>
                    </div>

                    <!-- Address -->
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                            Residential Address <span class="text-rose-500">*</span>
                        </label>
                        <textarea name="address" rows="2" required placeholder="No. 12, Main Street, Balangoda"
                            class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none resize-none"><?= htmlspecialchars($cdata['address'] ?? '') ?></textarea>
                    </div>

                    <!-- Utility Account Numbers -->
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
                        <span class="block text-xs font-bold text-slate-800">Balangoda Utility Account / Meter
                            Numbers</span>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-1">⚡ Electricity (CEB)
                                    No</label>
                                <input type="text" name="electricity_bill_no"
                                    value="<?= htmlspecialchars($cdata['electricity_bill_no'] ?? '') ?>"
                                    placeholder="e.g. 045-8192-33"
                                    class="w-full px-3 py-1.5 rounded-lg border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 outline-none">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-1">💧 Water (NWSDB)
                                    No</label>
                                <input type="text" name="water_bill_no"
                                    value="<?= htmlspecialchars($cdata['water_bill_no'] ?? '') ?>"
                                    placeholder="e.g. BAL/4412/08"
                                    class="w-full px-3 py-1.5 rounded-lg border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 outline-none">
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                        <button type="button" onclick="closeProfileModal()"
                            class="px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            <i data-lucide="check" class="w-3.5 h-3.5"></i>
                            <span>Save Profile Details</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab 2: Change Password Form -->
            <div id="prof-panel-pw" class="hidden">
                <form method="POST" action="dashboard.php" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="change_password_modal">

                    <div
                        class="p-3 rounded-xl bg-blue-50/70 border border-blue-100 text-blue-900 text-xs flex items-center gap-2.5">
                        <i data-lucide="shield-alert" class="w-4 h-4 text-blue-600 flex-shrink-0"></i>
                        <span>Create a new secure password. Must be at least 6 characters in length.</span>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                            Current Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="current_password" id="cust_cur_pw" required
                                placeholder="Enter current password"
                                class="w-full px-3.5 py-2 pr-10 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none">
                            <button type="button" onclick="toggleModalPw('cust_cur_pw', 'eye_cust_cur')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer">
                                <i data-lucide="eye" id="eye_cust_cur" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                            New Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="new_password" id="cust_new_pw" required minlength="6"
                                placeholder="Minimum 6 characters"
                                class="w-full px-3.5 py-2 pr-10 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none">
                            <button type="button" onclick="toggleModalPw('cust_new_pw', 'eye_cust_new')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer">
                                <i data-lucide="eye" id="eye_cust_new" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1">
                            Confirm New Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="confirm_password" id="cust_conf_pw" required minlength="6"
                                placeholder="Confirm new password"
                                class="w-full px-3.5 py-2 pr-10 rounded-xl border border-slate-300 text-slate-900 text-xs focus:ring-2 focus:ring-blue-600 focus:border-blue-600 outline-none">
                            <button type="button" onclick="toggleModalPw('cust_conf_pw', 'eye_cust_conf')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer">
                                <i data-lucide="eye" id="eye_cust_conf" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                        <button type="button" onclick="closeProfileModal()"
                            class="px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                            <span>Update Password</span>
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <!-- ════════════════ SCRIPTS ════════════════ -->
    <script>
        lucide.createIcons();

        function switchResidentTab(tab) {
            ['complaints', 'notifs'].forEach(function (t) {
                var btn = document.getElementById('tab-res-' + t + '-btn');
                var panel = document.getElementById('panel-res-' + t);
                if (btn) {
                    if (t === tab) {
                        btn.className = "tab-btn pb-3 px-4 text-sm font-semibold flex items-center gap-2 border-b-2 border-blue-600 text-blue-600 transition-colors whitespace-nowrap";
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

        function switchProfileModalTab(tab) {
            var infoBtn = document.getElementById('prof-tab-info-btn');
            var pwBtn = document.getElementById('prof-tab-pw-btn');
            var infoPanel = document.getElementById('prof-panel-info');
            var pwPanel = document.getElementById('prof-panel-pw');

            if (tab === 'pw') {
                if (pwBtn) pwBtn.className = "pb-2.5 px-3 text-xs font-bold border-b-2 border-blue-600 text-blue-600 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (infoBtn) infoBtn.className = "pb-2.5 px-3 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (infoPanel) infoPanel.classList.add('hidden');
                if (pwPanel) pwPanel.classList.remove('hidden');
            } else {
                if (infoBtn) infoBtn.className = "pb-2.5 px-3 text-xs font-bold border-b-2 border-blue-600 text-blue-600 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (pwBtn) pwBtn.className = "pb-2.5 px-3 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition-colors cursor-pointer";
                if (infoPanel) infoPanel.classList.remove('hidden');
                if (pwPanel) pwPanel.classList.add('hidden');
            }
            lucide.createIcons();
        }

        function openProfileModal(tab) {
            var m = document.getElementById('editProfileModal');
            if (m) {
                switchProfileModalTab(tab === 'pw' ? 'pw' : 'info');
                m.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }
        }

        function closeProfileModal() {
            var m = document.getElementById('editProfileModal');
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

        var modalOverlay = document.getElementById('editProfileModal');
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function (e) {
                if (e.target === modalOverlay) closeProfileModal();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeProfileModal();
            }
        });
    </script>
</body>

</html>