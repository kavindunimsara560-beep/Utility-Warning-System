<?php
session_start();
require_once 'config/db_connect.php';

// If already logged in, redirect directly to their dashboard
$customer_logged_in = isset($_SESSION['customer_id']);
$admin_logged_in    = isset($_SESSION['admin_id']);

// Determine active role tab ('customer' or 'admin')
$active_role = isset($_GET['role']) && $_GET['role'] === 'admin' ? 'admin' : 'customer';
if (isset($_POST['role']) && in_array($_POST['role'], ['customer', 'admin'], true)) {
    $active_role = $_POST['role'];
}

// Redirect target handling
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
// Validate safe redirect targets
$allowed_redirects = [
    'index.php',
    'submit_complaint.php',
    'report.php',
    'customer/dashboard.php',
    'admin/dashboard.php',
    'admin/edit.php'
];
if (!in_array($redirect, $allowed_redirects, true)) {
    $redirect = ''; // fallback will be decided per role
}

// Notice messages
$notice = '';
$notice_type = 'info';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'login_required') {
        $notice = 'Authentication Required: Please sign in to access that page.';
        $notice_type = 'warning';
    } elseif ($_GET['msg'] === 'logged_out') {
        $notice = 'You have been safely signed out.';
        $notice_type = 'success';
    } elseif ($_GET['msg'] === 'verified') {
        $notice = 'Your resident email has been verified! Please sign in below.';
        $notice_type = 'success';
    }
}

// Whitelist for admin self-registration
$ALLOWED_ADMIN_EMAILS = [
    'kavindunimsara560@gmail.com',
];

$cust_error    = '';
$admin_error   = '';
$admin_success = '';
$admin_tab     = isset($_GET['tab']) && $_GET['tab'] === 'signup' ? 'signup' : 'signin';

// -------------------------------------------------------------
// Handle Customer (Resident) Login Submission
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'customer_login') {
    $active_role = 'customer';
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $cust_error = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare("SELECT customer_id, full_name, phone, email, profile_pic, password_hash, email_verified FROM customers WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $cust = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$cust) {
                $cust_error = "No resident account found with that email address.";
            } elseif (!$cust['email_verified']) {
                $cust_error = "Your email address is not verified yet. Please check your verification link.";
            } elseif (!password_verify($password, $cust['password_hash'])) {
                $cust_error = "Incorrect password. Please verify your credentials and try again.";
            } else {
                // Success: initialize customer session and clear admin session to prevent overlap
                session_regenerate_id(true);
                unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_email'], $_SESSION['admin_fullname'], $_SESSION['admin_avatar']);
                $_SESSION['active_role']     = 'customer';
                $_SESSION['customer_id']     = $cust['customer_id'];
                $_SESSION['customer_name']   = $cust['full_name'];
                $_SESSION['customer_email']  = $cust['email'];
                $_SESSION['customer_phone']  = $cust['phone'];
                $_SESSION['customer_avatar'] = $cust['profile_pic'] ?? null;

                $target = !empty($redirect) ? $redirect : 'customer/dashboard.php';
                header("Location: " . $target);
                exit();
            }
        } else {
            $cust_error = "Database query failed: " . htmlspecialchars($conn->error);
        }
    }
}

// -------------------------------------------------------------
// Handle Admin Sign In / Sign Up Submission
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'admin_signin') {
        $active_role = 'admin';
        $admin_tab   = 'signin';
        $identity    = trim($_POST['identity'] ?? '');
        $password    = $_POST['password'] ?? '';

        if (empty($identity) || empty($password)) {
            $admin_error = "Please enter your username/email and password.";
        } else {
            $stmt = $conn->prepare("SELECT admin_id, username, full_name, email, profile_pic, password_hash FROM admins WHERE username = ? OR email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ss", $identity, $identity);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result && $row = $result->fetch_assoc()) {
                    if (password_verify($password, $row['password_hash'])) {
                        session_regenerate_id(true);
                        unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email'], $_SESSION['customer_phone'], $_SESSION['customer_avatar']);
                        $_SESSION['active_role']    = 'admin';
                        $_SESSION['admin_id']       = $row['admin_id'];
                        $_SESSION['admin_username'] = $row['username'];
                        $_SESSION['admin_email']    = $row['email'];
                        $_SESSION['admin_fullname'] = $row['full_name'] ?? null;
                        $_SESSION['admin_avatar']   = $row['profile_pic'] ?? null;

                        $target = (!empty($redirect) && strpos($redirect, 'admin/') !== false) ? $redirect : 'admin/dashboard.php';
                        header("Location: " . $target);
                        exit();
                    } else {
                        $admin_error = "Incorrect administrator password.";
                    }
                } else {
                    $admin_error = "No administrator account found with that username or email.";
                }
                $stmt->close();
            } else {
                $admin_error = "Database error: " . htmlspecialchars($conn->error);
            }
        }
    } elseif ($_POST['action'] === 'admin_signup') {
        $active_role = 'admin';
        $admin_tab   = 'signup';
        $username    = trim($_POST['username'] ?? '');
        $email       = strtolower(trim($_POST['email'] ?? ''));
        $password    = $_POST['password'] ?? '';
        $confirm_pw  = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password) || empty($confirm_pw)) {
            $admin_error = "All fields are required to register an administrative account.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $admin_error = "Please enter a valid email address.";
        } elseif (!in_array($email, array_map('strtolower', $ALLOWED_ADMIN_EMAILS), true)) {
            $admin_error = "Access Restricted: The email address '" . htmlspecialchars($email) . "' is not authorized. Only pre-approved municipal staff may register.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $admin_error = "Username must be 3-30 characters (letters, numbers, underscores).";
        } elseif (strlen($password) < 6) {
            $admin_error = "Password must be at least 6 characters long.";
        } elseif ($password !== $confirm_pw) {
            $admin_error = "Passwords do not match.";
        } else {
            // Check uniqueness
            $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? OR email = ? LIMIT 1");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $admin_error = "An administrator account with that username or email already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $conn->prepare("INSERT INTO admins (username, email, password_hash) VALUES (?, ?, ?)");
                $ins->bind_param("sss", $username, $email, $hash);
                if ($ins->execute()) {
                    $new_id = $ins->insert_id;
                    $ins->close();
                    // Auto login
                    session_regenerate_id(true);
                    unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email'], $_SESSION['customer_phone'], $_SESSION['customer_avatar']);
                    $_SESSION['active_role']    = 'admin';
                    $_SESSION['admin_id']       = $new_id;
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_email']    = $email;
                    $_SESSION['admin_fullname'] = null;
                    $_SESSION['admin_avatar']   = null;
                    header("Location: admin/dashboard.php?msg=account_created");
                    exit();
                } else {
                    $admin_error = "Failed to create account: " . $conn->error;
                }
            }
            $check->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Balangoda Municipal Utility Portal</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="description" content="Secure login portal for Balangoda Municipal Utility residents and administrators.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        /* ── Login Page Enterprise Theme v3.0 ─────────────────────────── */
        :root {
            --l-navy:       #04080f;
            --l-navy-mid:   #0c1227;
            --l-card-bg:    rgba(18, 27, 50, 0.95);
            --l-border:     rgba(255, 255, 255, 0.09);
            --l-cyan:       #06b6d4;
            --l-blue:       #2563eb;
            --l-amber:      #f59e0b;
            --l-gold:       #d97706;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background:
                radial-gradient(ellipse 80% 60% at 70% 0%, rgba(29,78,216,0.18) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 10% 90%, rgba(6,182,212,0.10) 0%, transparent 60%),
                linear-gradient(160deg, #03060e 0%, #0a1022 45%, #0d1630 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #f1f5f9;
        }

        /* Outer glow wrapper */
        .auth-outer {
            width: 100%;
            max-width: 920px;
            position: relative;
        }

        .auth-outer::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 26px;
            background: linear-gradient(135deg, rgba(37,99,235,0.4) 0%, rgba(6,182,212,0.2) 50%, rgba(217,119,6,0.3) 100%);
            z-index: 0;
            filter: blur(1px);
            opacity: 0.5;
        }

        .auth-container {
            width: 100%;
            background: var(--l-card-bg);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            border: 1px solid var(--l-border);
            border-radius: 24px;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.04) inset,
                0 40px 100px rgba(0,0,0,0.7),
                0 12px 32px rgba(0,0,0,0.4);
            overflow: hidden;
            display: flex;
            position: relative;
            z-index: 1;
        }

        /* ── Left Brand Panel ──────────────────────────── */
        .auth-brand-side {
            background:
                radial-gradient(ellipse 100% 80% at 50% 0%, rgba(29,78,216,0.22) 0%, transparent 60%),
                linear-gradient(170deg, #0d1535 0%, #080f20 100%);
            border-right: 1px solid rgba(255,255,255,0.06);
            flex: 0 0 38%;
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .auth-brand-side::after {
            content: '';
            position: absolute;
            bottom: -30%;
            right: -20%;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(6,182,212,0.08) 0%, transparent 70%);
            pointer-events: none;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px; height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, #1d4ed8 0%, #06b6d4 100%);
            color: #ffffff;
            font-size: 1.75rem;
            box-shadow: 0 8px 24px rgba(29,78,216,0.45), 0 0 0 1px rgba(255,255,255,0.15) inset;
            margin-bottom: 1.35rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .brand-badge:hover {
            transform: rotate(-6deg) scale(1.08);
            box-shadow: 0 12px 32px rgba(29,78,216,0.55), 0 0 0 1px rgba(255,255,255,0.2) inset;
        }

        .auth-brand-side h2 {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.45rem;
            line-height: 1.25;
            color: #f8fafc;
            margin-bottom: 0.7rem;
            letter-spacing: -0.025em;
        }

        .auth-brand-side p {
            color: rgba(148, 163, 184, 0.85);
            font-size: 0.86rem;
            line-height: 1.65;
            margin: 0;
        }

        .brand-feature-list { margin-top: 1.75rem; }

        .brand-feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(203, 213, 225, 0.90);
            font-size: 0.84rem;
            font-weight: 500;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .brand-feature-item:last-child { border-bottom: none; }

        .brand-feature-item i {
            color: var(--l-cyan);
            font-size: 1.05rem;
            flex-shrink: 0;
            width: 20px;
            text-align: center;
        }

        /* ── Right Form Panel ──────────────────────────── */
        .auth-form-side {
            flex: 1;
            padding: 3rem 2.75rem;
            position: relative;
        }

        /* ── Role Switcher ─────────────────────────────── */
        .role-switcher {
            background: rgba(8, 14, 30, 0.65);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 14px;
            padding: 4px;
            display: flex;
            gap: 3px;
            margin-bottom: 2rem;
        }

        .role-btn {
            flex: 1;
            padding: 0.62rem 1rem;
            border: none;
            background: transparent;
            color: rgba(148,163,184,0.85);
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 0.86rem;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            text-decoration: none;
            letter-spacing: 0.01em;
        }

        .role-btn:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.07);
        }

        .role-btn.active.resident {
            background: linear-gradient(135deg, #1d4ed8, #0284c7);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(29,78,216,0.45), 0 0 0 1px rgba(255,255,255,0.12) inset;
        }

        .role-btn.active.admin {
            background: linear-gradient(135deg, #92400e, #d97706);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(146,64,14,0.45), 0 0 0 1px rgba(255,255,255,0.12) inset;
        }

        /* ── Form Section Header ───────────────────────── */
        .form-section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 0 0 0.35rem;
            letter-spacing: -0.03em;
        }

        .form-section-sub {
            color: rgba(148,163,184,0.80);
            font-size: 0.85rem;
            margin: 0;
        }

        /* ── Form Inputs ───────────────────────────────── */
        .form-label {
            color: rgba(203,213,225,0.90);
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .form-control {
            background: rgba(255,255,255,0.055);
            border: 1px solid rgba(255,255,255,0.11);
            color: #f1f5f9;
            border-radius: 10px;
            padding: 0.7rem 0.95rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            font-weight: 400;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            width: 100%;
        }

        .form-control:hover {
            border-color: rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.07);
        }

        .form-control:focus {
            background: rgba(255,255,255,0.09);
            border-color: var(--l-cyan);
            box-shadow: 0 0 0 3px rgba(6,182,212,0.22);
            color: #ffffff;
            outline: none;
        }

        .form-control::placeholder { color: rgba(100,116,139,0.75); }

        /* Admin-themed focus */
        .admin-form .form-control:focus {
            border-color: var(--l-amber);
            box-shadow: 0 0 0 3px rgba(245,158,11,0.22);
        }

        /* ── Submit Buttons ────────────────────────────── */
        .btn-submit {
            border: none;
            border-radius: 12px;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            font-size: 0.92rem;
            padding: 0.78rem 1rem;
            width: 100%;
            transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.015em;
        }

        .btn-submit::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 60%);
            pointer-events: none;
        }

        .btn-resident {
            background: linear-gradient(135deg, #1d4ed8 0%, #0284c7 100%);
            box-shadow: 0 6px 20px rgba(29,78,216,0.40);
        }

        .btn-resident:hover {
            box-shadow: 0 10px 28px rgba(29,78,216,0.55);
            transform: translateY(-2px);
            color: #ffffff;
        }

        .btn-resident:active { transform: translateY(0); }

        .btn-admin {
            background: linear-gradient(135deg, #92400e 0%, #d97706 60%, #f59e0b 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(146,64,14,0.40);
        }

        .btn-admin:hover {
            box-shadow: 0 10px 28px rgba(146,64,14,0.55);
            transform: translateY(-2px);
            color: #ffffff;
        }

        .btn-admin:active { transform: translateY(0); }

        /* ── Password Toggle ───────────────────────────── */
        .toggle-pw-btn {
            background: rgba(255,255,255,0.055);
            border: 1px solid rgba(255,255,255,0.11);
            border-left: none;
            color: rgba(148,163,184,0.80);
            border-radius: 0 10px 10px 0;
            padding: 0 0.9rem;
            cursor: pointer;
            transition: color 0.15s, background 0.15s;
        }

        .toggle-pw-btn:hover {
            background: rgba(255,255,255,0.10);
            color: #cbd5e1;
        }

        /* ── Admin Whitelist Box ───────────────────────── */
        .whitelist-box {
            background: rgba(245,158,11,0.09);
            border: 1px solid rgba(245,158,11,0.22);
            border-left: 3px solid #d97706;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.81rem;
            color: #fde68a;
            margin-bottom: 1.25rem;
            line-height: 1.55;
        }

        /* ── Divider ───────────────────────────────────── */
        .auth-divider {
            border-color: rgba(255,255,255,0.06);
        }

        /* ── Responsive ────────────────────────────────── */
        @media (max-width: 768px) {
            .auth-brand-side { display: none; }
            .auth-outer { max-width: 480px; }
            .auth-form-side { padding: 2.25rem 1.75rem; }
        }

        @media (max-width: 480px) {
            body { padding: 1rem; }
            .auth-form-side { padding: 1.75rem 1.35rem; }
        }
    </style>
</head>
<body>

    <div class="auth-outer">
    <div class="auth-container">
        <!-- Left Brand Side -->
        <div class="auth-brand-side">
            <div>
                <div class="brand-badge">⚡</div>
                <h2>Balangoda Municipal<br>Utility Portal</h2>
                <p>Official single sign-in gateway for both Balangoda citizens and municipal authorities.</p>

                <div class="brand-feature-list">
                    <div class="brand-feature-item">
                        <i class="bi bi-broadcast"></i>
                        <span>Live power, water &amp; road outages</span>
                    </div>
                    <div class="brand-feature-item">
                        <i class="bi bi-shield-check"></i>
                        <span>Secure verified utility accounts</span>
                    </div>
                    <div class="brand-feature-item">
                        <i class="bi bi-clock-history"></i>
                        <span>Real-time dispatch &amp; tracking</span>
                    </div>
                    <div class="brand-feature-item">
                        <i class="bi bi-people-fill"></i>
                        <span>Resident complaint management</span>
                    </div>
                </div>
            </div>

            <div class="pt-4 auth-divider border-top">
                <a href="index.php" class="text-secondary text-decoration-none small d-inline-flex align-items-center gap-1">
                    <i class="bi bi-arrow-left"></i> Back to Public Site
                </a>
            </div>
        </div>

        <!-- Right Form Side -->
        <div class="auth-form-side">
            <!-- Global Notice if any -->
            <?php if (!empty($notice)): ?>
                <div class="alert alert-<?= $notice_type ?> py-2 px-3 mb-3 d-flex align-items-center gap-2" style="border-radius:10px; font-size:0.86rem;">
                    <i class="bi bi-info-circle-fill flex-shrink-0"></i>
                    <span><?= htmlspecialchars($notice) ?></span>
                </div>
            <?php endif; ?>

            <!-- Segmented Role Switcher: One place for both roles -->
            <div class="role-switcher">
                <a href="login.php?role=customer<?= !empty($redirect) ? '&redirect=' . urlencode($redirect) : '' ?>"
                   class="role-btn <?= $active_role === 'customer' ? 'active resident' : '' ?>">
                    <i class="bi bi-person-circle"></i> Resident Portal
                </a>
                <a href="login.php?role=admin<?= !empty($redirect) ? '&redirect=' . urlencode($redirect) : '' ?>"
                   class="role-btn <?= $active_role === 'admin' ? 'active admin' : '' ?>">
                    <i class="bi bi-shield-lock-fill"></i> Municipal Admin
                </a>
            </div>

            <!-- ============================================== -->
            <!-- ROLE 1: RESIDENT / CITIZEN LOGIN               -->
            <!-- ============================================== -->
            <?php if ($active_role === 'customer'): ?>
                <div class="mb-3">
                    <h3 class="form-section-title">Resident Sign In</h3>
                    <p class="form-section-sub">Sign in to track utility outages, report issues, and receive alerts.</p>
                </div>

                <?php if ($customer_logged_in): ?>
                    <div class="alert alert-success py-3 px-3 mb-4" style="background:rgba(34,197,94,0.15); border:1px solid rgba(34,197,94,0.3); border-radius:12px; color:#86efac;">
                        <div class="fw-bold mb-1"><i class="bi bi-check-circle-fill me-1"></i> Already Signed In</div>
                        <div class="small mb-3">You are currently logged in as <strong><?= htmlspecialchars($_SESSION['customer_name'] ?? 'Resident') ?></strong>.</div>
                        <div class="d-flex gap-2">
                            <a href="customer/dashboard.php" class="btn btn-success btn-sm fw-bold">Go to My Dashboard</a>
                            <a href="logout.php" class="btn btn-outline-light btn-sm">Sign Out</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!empty($cust_error)): ?>
                        <div class="alert alert-danger py-2 px-3 mb-3 d-flex align-items-center gap-2" style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.35); color:#fca5a5; border-radius:10px; font-size:0.86rem;">
                            <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                            <span><?= htmlspecialchars($cust_error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php">
                        <input type="hidden" name="action" value="customer_login">
                        <input type="hidden" name="role" value="customer">
                        <?php if (!empty($redirect)): ?>
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Resident Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="you@example.com"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="custPwField" class="form-control" placeholder="Enter your password" required style="border-right:none;">
                                <button type="button" class="toggle-pw-btn" onclick="togglePw('custPwField', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-submit btn-resident mb-3">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Resident Portal
                        </button>
                    </form>

                    <div class="text-center small text-secondary">
                        Don't have an account yet? <a href="customer/register.php" class="text-info fw-semibold text-decoration-none">Create Resident Account &rarr;</a>
                    </div>
                <?php endif; ?>

            <!-- ============================================== -->
            <!-- ROLE 2: MUNICIPAL ADMIN SIGN IN / SIGN UP      -->
            <!-- ============================================== -->
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h3 class="form-section-title">
                            <?= $admin_tab === 'signup' ? 'Admin Registration' : 'Admin Console Sign In' ?>
                        </h3>
                        <p class="form-section-sub">Authorized management console for municipal utility staff.</p>
                    </div>

                    <?php if (!$admin_logged_in): ?>
                        <div class="btn-group btn-group-sm">
                            <a href="login.php?role=admin&tab=signin" class="btn <?= $admin_tab === 'signin' ? 'btn-warning fw-bold text-dark' : 'btn-outline-secondary text-white-50' ?>">Sign In</a>
                            <a href="login.php?role=admin&tab=signup" class="btn <?= $admin_tab === 'signup' ? 'btn-warning fw-bold text-dark' : 'btn-outline-secondary text-white-50' ?>">Sign Up</a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($admin_logged_in): ?>
                    <div class="alert alert-warning py-3 px-3 mb-4" style="background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.3); border-radius:12px; color:#fde68a;">
                        <div class="fw-bold mb-1"><i class="bi bi-shield-lock-fill me-1"></i> Administrator Active</div>
                        <div class="small mb-3">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></strong>.</div>
                        <div class="d-flex gap-2">
                            <a href="admin/dashboard.php" class="btn btn-warning btn-sm fw-bold text-dark">Go to Admin Dashboard</a>
                            <a href="logout.php" class="btn btn-outline-light btn-sm">Sign Out</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!empty($admin_error)): ?>
                        <div class="alert alert-danger py-2 px-3 mb-3 d-flex align-items-center gap-2" style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.35); color:#fca5a5; border-radius:10px; font-size:0.86rem;">
                            <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                            <span><?= htmlspecialchars($admin_error) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($admin_tab === 'signup'): ?>
                        <!-- Admin Signup Mode -->
                        <div class="whitelist-box">
                            <i class="bi bi-shield-check me-1"></i>
                            <strong>Authorized Staff Only:</strong> Registration is restricted to approved municipal staff emails (<code>kavindunimsara560@gmail.com</code>).
                        </div>

                        <form method="POST" action="login.php" class="admin-form">
                            <input type="hidden" name="action" value="admin_signup">
                            <input type="hidden" name="role" value="admin">

                            <div class="mb-3">
                                <label class="form-label">Admin Username</label>
                                <input type="text" name="username" class="form-control" placeholder="e.g. kavindu_admin" required pattern="^[a-zA-Z0-9_]{3,30}$">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Authorized Municipal Email</label>
                                <input type="email" name="email" class="form-control" placeholder="kavindunimsara560@gmail.com" required>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" placeholder="Min 6 characters" minlength="6" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Confirm Password</label>
                                    <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" minlength="6" required>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-submit btn-admin mb-3">
                                <i class="bi bi-person-check-fill me-1"></i> Register Admin Account
                            </button>
                        </form>
                    <?php else: ?>
                        <!-- Admin Signin Mode -->
                        <form method="POST" action="login.php" class="admin-form">
                            <input type="hidden" name="action" value="admin_signin">
                            <input type="hidden" name="role" value="admin">
                            <?php if (!empty($redirect)): ?>
                                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Admin Username or Email</label>
                                <input type="text" name="identity" class="form-control" placeholder="admin or officer@domain.com"
                                       value="<?= htmlspecialchars($_POST['identity'] ?? '') ?>" required autofocus>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="adminPwField" class="form-control" placeholder="Enter admin password" required style="border-right:none;">
                                    <button type="button" class="toggle-pw-btn" onclick="togglePw('adminPwField', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-submit btn-admin mb-3">
                                <i class="bi bi-shield-lock-fill me-1"></i> Sign In to Admin Console
                            </button>
                        </form>

                        <div class="text-center small text-secondary">
                            Official municipal officer registration: <a href="login.php?role=admin&tab=signup" class="text-warning fw-semibold text-decoration-none">Sign up here &rarr;</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <div class="mt-4 pt-3 border-top auth-divider text-center">
                <a href="index.php" class="text-secondary small text-decoration-none d-inline-flex align-items-center gap-1">
                    <i class="bi bi-house-door"></i> Return to Public Home Page
                </a>
            </div>
        </div>
    </div>
    </div><!-- /.auth-outer -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePw(fieldId, btn) {
            const f = document.getElementById(fieldId);
            const ic = btn.querySelector('i');
            if (f.type === 'password') {
                f.type = 'text';
                ic.className = 'bi bi-eye-slash';
            } else {
                f.type = 'password';
                ic.className = 'bi bi-eye';
            }
        }
    </script>
</body>
</html>
