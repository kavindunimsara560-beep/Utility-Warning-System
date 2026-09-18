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
    <meta name="theme-color" content="#0d6efd">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --navy: #090e17;
            --navy-deep: #0f172a;
            --navy-card: #1e293b;
            --cyan: #38bdf8;
            --blue: #2563eb;
            --amber: #f59e0b;
        }

        body {
            font-family: 'Outfit', system-ui, sans-serif;
            background: linear-gradient(135deg, var(--navy) 0%, #1e1b4b 50%, var(--navy-deep) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #f1f5f9;
        }

        .auth-container {
            width: 100%;
            max-width: 900px;
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.6);
            overflow: hidden;
            display: flex;
        }

        /* Left Branding Panel */
        .auth-brand-side {
            background: linear-gradient(160deg, #1e1b4b 0%, #0f172a 100%);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            flex: 0 0 38%;
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            color: #ffffff;
            font-size: 1.7rem;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.35);
            margin-bottom: 1.25rem;
        }

        .auth-brand-side h2 {
            font-weight: 800;
            font-size: 1.5rem;
            line-height: 1.25;
            color: #f8fafc;
            margin-bottom: 0.75rem;
        }

        .auth-brand-side p {
            color: #94a3b8;
            font-size: 0.88rem;
            line-height: 1.6;
        }

        .brand-feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #cbd5e1;
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }

        .brand-feature-item i {
            color: var(--cyan);
            font-size: 1rem;
        }

        /* Right Form Side */
        .auth-form-side {
            flex: 1;
            padding: 3rem 2.75rem;
        }

        /* Role Switcher */
        .role-switcher {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 5px;
            display: flex;
            gap: 4px;
            margin-bottom: 2rem;
        }

        .role-btn {
            flex: 1;
            padding: 0.65rem 1rem;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-weight: 700;
            font-size: 0.9rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
        }

        .role-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }

        .role-btn.active.resident {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
        }

        .role-btn.active.admin {
            background: #d97706;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);
        }

        /* Form Inputs */
        .form-label {
            color: #cbd5e1;
            font-size: 0.83rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        .form-control {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 10px;
            padding: 0.65rem 0.9rem;
            font-family: 'Outfit', sans-serif;
            font-size: 0.92rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--cyan);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
            color: #ffffff;
            outline: none;
        }

        .form-control::placeholder {
            color: #64748b;
        }

        .btn-submit {
            border: none;
            border-radius: 12px;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.75rem;
            width: 100%;
            transition: all 0.2s;
        }

        .btn-resident {
            background: linear-gradient(135deg, #2563eb, #38bdf8);
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.35);
        }

        .btn-resident:hover {
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.5);
            transform: translateY(-2px);
            color: #ffffff;
        }

        .btn-admin {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #0f172a;
            box-shadow: 0 6px 18px rgba(217, 119, 6, 0.35);
        }

        .btn-admin:hover {
            box-shadow: 0 10px 24px rgba(217, 119, 6, 0.5);
            transform: translateY(-2px);
            color: #0f172a;
        }

        .toggle-pw-btn {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-left: none;
            color: #94a3b8;
            border-radius: 0 10px 10px 0;
            padding: 0 0.85rem;
            cursor: pointer;
        }

        .whitelist-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.25);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            color: #fde68a;
            margin-bottom: 1.25rem;
        }

        @media (max-width: 768px) {
            .auth-brand-side { display: none; }
            .auth-container { max-width: 480px; }
            .auth-form-side { padding: 2rem 1.75rem; }
        }
    </style>
</head>
<body>

    <div class="auth-container">
        <!-- Left Brand Side -->
        <div class="auth-brand-side">
            <div>
                <div class="brand-badge">⚡</div>
                <h2>Balangoda Municipal<br>Utility Portal</h2>
                <p>Official single sign-in gateway for both Balangoda citizens and municipal authorities.</p>
                
                <div class="mt-4">
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
                </div>
            </div>

            <div class="pt-4 border-top" style="border-color:rgba(255,255,255,0.08)!important;">
                <a href="index.php" class="text-secondary text-decoration-none small d-inline-flex align-items-center gap-1">
                    <i class="bi bi-arrow-left"></i> Back to Outage Warnings
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
                    <h3 class="fw-bold text-white mb-1" style="font-size:1.4rem;">Resident Sign In</h3>
                    <p class="text-secondary small mb-0">Sign in to report utility issues, track repairs, and receive alerts.</p>
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
                        <h3 class="fw-bold text-white mb-1" style="font-size:1.4rem;">
                            <?= $admin_tab === 'signup' ? 'Admin Registration' : 'Municipal Admin Sign In' ?>
                        </h3>
                        <p class="text-secondary small mb-0">Authorized management console for municipal utility staff.</p>
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

                        <form method="POST" action="login.php">
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
                        <form method="POST" action="login.php">
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

            <div class="mt-4 pt-3 border-top text-center" style="border-color:rgba(255,255,255,0.06)!important;">
                <a href="index.php" class="text-secondary small text-decoration-none">
                    <i class="bi bi-house-door me-1"></i> Return to Public Home Page
                </a>
            </div>
        </div>
    </div>

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
