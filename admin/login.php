<?php
session_start();

// Redirect if already authenticated
if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

require '../config/db_connect.php';

// Pre-authorized administrative email addresses whitelist
$ALLOWED_ADMIN_EMAILS = [
    'kavindunimsara560@gmail.com',
];

$signin_error = "";
$signup_error = "";
$signup_success = "";
$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'signup' ? 'signup' : 'signin';

// Handle Sign In submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'signin') {
    $active_tab = 'signin';
    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($identity) || empty($password)) {
        $signin_error = "Please enter your username/email and password.";
    } else {
        $stmt = $conn->prepare("SELECT admin_id, username, email, password_hash FROM admins WHERE username = ? OR email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ss", $identity, $identity);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $row = $result->fetch_assoc()) {
                if (password_verify($password, $row['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $row['admin_id'];
                    $_SESSION['admin_username'] = $row['username'];
                    $_SESSION['admin_email'] = $row['email'];
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $signin_error = "Incorrect password. Please verify your credentials and try again.";
                }
            } else {
                $signin_error = "No admin account found with that username or email address.";
            }
            $stmt->close();
        } else {
            $signin_error = "Database query failed: " . htmlspecialchars($conn->error);
        }
    }
}

// Handle Sign Up submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'signup') {
    $active_tab = 'signup';
    $username = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 1. Basic field presence checks
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $signup_error = "All fields are required to create an admin account.";
    } 
    // 2. Validate email format
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $signup_error = "Please enter a valid email address.";
    } 
    // 3. Check email against pre-authorized whitelist
    elseif (!in_array($email, array_map('strtolower', $ALLOWED_ADMIN_EMAILS), true)) {
        $signup_error = "Access Restricted: The email address '" . htmlspecialchars($email) . "' is not authorized to register as an administrator. Only pre-authorized municipal emails are allowed.";
    } 
    // 4. Validate username format (3-30 chars, alphanumeric + underscore)
    elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $signup_error = "Username must be 3-30 characters and contain only letters, numbers, and underscores.";
    } 
    // 5. Password length check
    elseif (strlen($password) < 6) {
        $signup_error = "Password must be at least 6 characters long.";
    } 
    // 6. Password confirmation check
    elseif ($password !== $confirm_password) {
        $signup_error = "Passwords do not match. Please re-enter your password.";
    } 
    else {
        // 7. Check if username already exists
        $check_user = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? LIMIT 1");
        if ($check_user) {
            $check_user->bind_param("s", $username);
            $check_user->execute();
            $check_user->store_result();
            if ($check_user->num_rows > 0) {
                $signup_error = "The username '" . htmlspecialchars($username) . "' is already taken. Please choose another.";
            }
            $check_user->close();
        }

        // 8. Check if email already registered
        if (empty($signup_error)) {
            $check_email = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? LIMIT 1");
            if ($check_email) {
                $check_email->bind_param("s", $email);
                $check_email->execute();
                $check_email->store_result();
                if ($check_email->num_rows > 0) {
                    $signup_error = "An administrator account is already registered with this email. Please sign in instead.";
                }
                $check_email->close();
            }
        }

        // 9. Insert new admin if validations pass
        if (empty($signup_error)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare("INSERT INTO admins (username, email, password_hash) VALUES (?, ?, ?)");
            if ($insert_stmt) {
                $insert_stmt->bind_param("sss", $username, $email, $password_hash);
                if ($insert_stmt->execute()) {
                    $new_admin_id = $conn->insert_id;
                    $insert_stmt->close();

                    // Automatically sign in the newly registered administrator
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $new_admin_id;
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_email'] = $email;

                    header("Location: dashboard.php?msg=account_created");
                    exit();
                } else {
                    $signup_error = "Error registering admin account: " . htmlspecialchars($insert_stmt->error);
                    $insert_stmt->close();
                }
            } else {
                $signup_error = "Database error: " . htmlspecialchars($conn->error);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - Balangoda Outage Warning System</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- PWA -->
    <link rel="manifest" href="/Web_base_project/manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/style.css">

    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            color: #f8fafc;
        }

        .auth-card {
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 40px rgba(13, 110, 253, 0.15);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .auth-header {
            background: rgba(15, 23, 42, 0.6);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 2rem 2rem 1.5rem;
            text-align: center;
        }

        .auth-logo-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, #0d6efd 0%, #06b6d4 100%);
            color: white;
            font-size: 1.85rem;
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.35);
            margin-bottom: 1rem;
        }

        .portal-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.45rem;
            letter-spacing: -0.02em;
            color: #ffffff;
            margin-bottom: 0.25rem;
        }

        .portal-subtitle {
            color: #94a3b8;
            font-size: 0.875rem;
            margin-bottom: 0;
        }

        .auth-body {
            padding: 2rem;
        }

        /* Nav Pills Custom Tabs */
        .auth-nav-pills {
            background: rgba(15, 23, 42, 0.8);
            border-radius: 12px;
            padding: 4px;
            display: flex;
            margin-bottom: 1.75rem;
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .auth-nav-link {
            flex: 1;
            text-align: center;
            padding: 0.6rem 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: #94a3b8;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .auth-nav-link.active {
            background: #0d6efd;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.35);
        }

        .auth-nav-link:hover:not(.active) {
            color: #f1f5f9;
            background: rgba(255, 255, 255, 0.05);
        }

        /* Form Controls */
        .form-label {
            font-size: 0.825rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 0.4rem;
            letter-spacing: 0.01em;
        }

        .input-group-dark {
            position: relative;
        }

        .input-group-dark .form-control {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #f8fafc;
            border-radius: 10px;
            padding: 0.7rem 0.95rem;
            font-size: 0.925rem;
            transition: all 0.2s ease;
        }

        .input-group-dark .form-control:focus {
            background: rgba(15, 23, 42, 0.85);
            border-color: #38bdf8;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.15);
            color: #ffffff;
        }

        .input-group-dark .form-control::placeholder {
            color: #64748b;
            font-size: 0.875rem;
        }

        .toggle-pw-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            padding: 0;
            cursor: pointer;
            z-index: 5;
            transition: color 0.15s ease;
        }

        .toggle-pw-btn:hover {
            color: #f8fafc;
        }

        /* Authorization Badge Box */
        .whitelist-notice {
            background: rgba(13, 110, 253, 0.1);
            border: 1px dashed rgba(56, 189, 248, 0.4);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            font-size: 0.8rem;
            color: #93c5fd;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .whitelist-email-tag {
            background: rgba(56, 189, 248, 0.2);
            border-radius: 4px;
            padding: 2px 6px;
            color: #ffffff;
            font-family: monospace;
            font-weight: 600;
        }

        /* Primary Button */
        .btn-auth-submit {
            background: linear-gradient(135deg, #0d6efd 0%, #2563eb 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 0.01em;
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(13, 110, 253, 0.35);
            transition: all 0.2s ease;
            width: 100%;
        }

        .btn-auth-submit:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.45);
        }

        .btn-auth-submit:active {
            transform: translateY(0);
        }

        /* Portal Footer */
        .auth-footer {
            background: rgba(15, 23, 42, 0.6);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding: 1.25rem 2rem;
            text-align: center;
        }

        .auth-footer a {
            color: #94a3b8;
            font-size: 0.85rem;
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .auth-footer a:hover {
            color: #38bdf8;
        }

        .alert-custom {
            border-radius: 10px;
            font-size: 0.85rem;
            border: none;
        }
    </style>
</head>
<body>

    <div class="auth-card">
        <!-- Card Header -->
        <div class="auth-header">
            <div class="auth-logo-badge">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <h1 class="portal-title">Official Admin Portal</h1>
            <p class="portal-subtitle">Balangoda Municipal Utility Warning System</p>
        </div>

        <!-- Card Body -->
        <div class="auth-body">
            <!-- Nav Switcher (Sign In / Sign Up) -->
            <div class="auth-nav-pills" role="tablist">
                <button type="button" class="auth-nav-link <?php echo $active_tab === 'signin' ? 'active' : ''; ?>" id="tab-btn-signin" onclick="switchAuthTab('signin')">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                </button>
                <button type="button" class="auth-nav-link <?php echo $active_tab === 'signup' ? 'active' : ''; ?>" id="tab-btn-signup" onclick="switchAuthTab('signup')">
                    <i class="bi bi-person-plus-fill"></i> Sign Up
                </button>
            </div>

            <!-- Global Alert Messages -->
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out'): ?>
                <div class="alert alert-info alert-custom py-2 px-3 mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill fs-6"></i>
                    <span>You have been safely logged out.</span>
                </div>
            <?php endif; ?>

            <!-- ================= SIGN IN TAB ================= -->
            <div id="tab-content-signin" style="<?php echo $active_tab === 'signin' ? 'display: block;' : 'display: none;'; ?>">
                <?php if (!empty($signin_error)): ?>
                    <div class="alert alert-danger alert-custom py-2 px-3 mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill fs-6 flex-shrink-0"></i>
                        <span><?php echo htmlspecialchars($signin_error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" autocomplete="on">
                    <input type="hidden" name="action" value="signin">

                    <div class="mb-3">
                        <label class="form-label" for="signin_identity">Username or Email</label>
                        <div class="input-group-dark">
                            <input type="text" id="signin_identity" name="identity" class="form-control" placeholder="admin or name@domain.com" required value="<?php echo isset($_POST['identity']) ? htmlspecialchars($_POST['identity']) : ''; ?>" autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="signin_password">Password</label>
                        <div class="input-group-dark">
                            <input type="password" id="signin_password" name="password" class="form-control" placeholder="Enter your password" required>
                            <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility('signin_password', this)" title="Toggle password visibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-auth-submit mb-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Dashboard
                    </button>

                    <div class="text-center">
                        <span class="text-muted small">Need an administrative account?</span>
                        <a href="javascript:void(0)" onclick="switchAuthTab('signup')" class="small text-info text-decoration-none ms-1 fw-semibold">Register here</a>
                    </div>
                </form>
            </div>

            <!-- ================= SIGN UP TAB ================= -->
            <div id="tab-content-signup" style="<?php echo $active_tab === 'signup' ? 'display: block;' : 'display: none;'; ?>">
                <!-- Authorization Whitelist Info Box -->
                <div class="whitelist-notice">
                    <i class="bi bi-shield-check fs-5 text-info flex-shrink-0"></i>
                    <div>
                        <strong>Restricted Registration:</strong> Only pre-approved administrative emails can create an account. Currently authorized:
                        <div class="mt-1"><span class="whitelist-email-tag">kavindunimsara560@gmail.com</span></div>
                    </div>
                </div>

                <?php if (!empty($signup_error)): ?>
                    <div class="alert alert-danger alert-custom py-2 px-3 mb-3 d-flex align-items-start gap-2">
                        <i class="bi bi-shield-x fs-6 text-danger flex-shrink-0 mt-1"></i>
                        <span><?php echo htmlspecialchars($signup_error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" autocomplete="off">
                    <input type="hidden" name="action" value="signup">

                    <!-- Username -->
                    <div class="mb-3">
                        <label class="form-label" for="signup_username">Admin Username</label>
                        <div class="input-group-dark">
                            <input type="text" id="signup_username" name="username" class="form-control" placeholder="e.g., kavindu_admin" required pattern="^[a-zA-Z0-9_]{3,30}$" title="3-30 letters, numbers, or underscores" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>
                        <small class="text-secondary" style="font-size: 0.75rem;">3–30 alphanumeric characters or underscores.</small>
                    </div>

                    <!-- Authorized Email -->
                    <div class="mb-3">
                        <label class="form-label" for="signup_email">Authorized Email Address</label>
                        <div class="input-group-dark">
                            <input type="email" id="signup_email" name="email" class="form-control" placeholder="kavindunimsara560@gmail.com" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                        <small class="text-secondary" style="font-size: 0.75rem;">Must match your assigned administrative email address.</small>
                    </div>

                    <!-- Password -->
                    <div class="mb-3">
                        <label class="form-label" for="signup_password">Admin Password</label>
                        <div class="input-group-dark">
                            <input type="password" id="signup_password" name="password" class="form-control" placeholder="Minimum 6 characters" minlength="6" required>
                            <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility('signup_password', this)" title="Toggle password visibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="mb-4">
                        <label class="form-label" for="signup_confirm_password">Confirm Password</label>
                        <div class="input-group-dark">
                            <input type="password" id="signup_confirm_password" name="confirm_password" class="form-control" placeholder="Repeat your password" minlength="6" required>
                            <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility('signup_confirm_password', this)" title="Toggle password visibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-auth-submit mb-3">
                        <i class="bi bi-person-check-fill me-1"></i> Create Admin Account
                    </button>

                    <div class="text-center">
                        <span class="text-muted small">Already have an admin account?</span>
                        <a href="javascript:void(0)" onclick="switchAuthTab('signin')" class="small text-info text-decoration-none ms-1 fw-semibold">Sign in</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card Footer -->
        <div class="auth-footer">
            <a href="../index.php" class="d-inline-flex align-items-center gap-1">
                <i class="bi bi-arrow-left"></i> Back to Public Outage Portal
            </a>
        </div>
    </div>

    <!-- Interactive Scripts -->
    <script>
        function switchAuthTab(tabName) {
            const signInTabBtn = document.getElementById('tab-btn-signin');
            const signUpTabBtn = document.getElementById('tab-btn-signup');
            const signInContent = document.getElementById('tab-content-signin');
            const signUpContent = document.getElementById('tab-content-signup');

            if (tabName === 'signup') {
                signInTabBtn.classList.remove('active');
                signUpTabBtn.classList.add('active');
                signInContent.style.display = 'none';
                signUpContent.style.display = 'block';
                history.replaceState(null, '', '?tab=signup');
                const userField = document.getElementById('signup_username');
                if (userField) userField.focus();
            } else {
                signUpTabBtn.classList.remove('active');
                signInTabBtn.classList.add('active');
                signUpContent.style.display = 'none';
                signInContent.style.display = 'block';
                history.replaceState(null, '', '?tab=signin');
                const idField = document.getElementById('signin_identity');
                if (idField) idField.focus();
            }
        }

        function togglePasswordVisibility(fieldId, btn) {
            const field = document.getElementById(fieldId);
            const icon = btn.querySelector('i');
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    </script>
</body>
</html>