<?php
session_start();
require_once '../config/db_connect.php';

// If admin already logged in, go to dashboard
if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Redirect target
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
$allowed_redirects = ['admin/dashboard.php', 'admin/edit.php', 'admin/profile.php'];
if (!in_array($redirect, $allowed_redirects, true)) {
    $redirect = '';
}

$admin_error   = '';
$admin_success = '';
$admin_tab     = isset($_GET['tab']) && $_GET['tab'] === 'signup' ? 'signup' : 'signin';

// Whitelist for admin self-registration
$ALLOWED_ADMIN_EMAILS = [
    'kavindunimsara560@gmail.com',
];

// ── Handle Admin Sign In ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'admin_signin') {
        $admin_tab = 'signin';
        $identity  = trim($_POST['identity'] ?? '');
        $password  = $_POST['password'] ?? '';

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

                        if (!empty($redirect)) {
                            if (strpos($redirect, 'admin/') === 0) {
                                $target = substr($redirect, strlen('admin/'));
                            } else {
                                $target = '../' . $redirect;
                            }
                        } else {
                            $target = 'dashboard.php';
                        }
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
        $admin_tab  = 'signup';
        $username   = trim($_POST['username'] ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $password   = $_POST['password'] ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password) || empty($confirm_pw)) {
            $admin_error = "All fields are required to register an administrative account.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $admin_error = "Please enter a valid email address.";
        } elseif (!in_array($email, array_map('strtolower', $ALLOWED_ADMIN_EMAILS), true)) {
            $admin_error = "Access Restricted: The email '" . htmlspecialchars($email) . "' is not authorized. Only pre-approved municipal staff may register.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $admin_error = "Username must be 3–30 characters (letters, numbers, underscores).";
        } elseif (strlen($password) < 6) {
            $admin_error = "Password must be at least 6 characters long.";
        } elseif ($password !== $confirm_pw) {
            $admin_error = "Passwords do not match.";
        } else {
            $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? OR email = ? LIMIT 1");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $admin_error = "An administrator account with that username or email already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins  = $conn->prepare("INSERT INTO admins (username, email, password_hash) VALUES (?, ?, ?)");
                $ins->bind_param("sss", $username, $email, $hash);
                if ($ins->execute()) {
                    $new_id = $ins->insert_id;
                    $ins->close();
                    session_regenerate_id(true);
                    $_SESSION['active_role']    = 'admin';
                    $_SESSION['admin_id']       = $new_id;
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_email']    = $email;
                    $_SESSION['admin_fullname'] = null;
                    $_SESSION['admin_avatar']   = null;
                    header("Location: dashboard.php?msg=account_created");
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
<title>Admin Console — Balangoda Municipal Utility Portal</title>
<meta name="description" content="Secure administrator login console for Balangoda Municipal Utility staff.">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0d0f12">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Admin Console">
<link rel="manifest" href="../admin-manifest.json">
<link rel="apple-touch-icon" href="../icons/admin-icon-512.jpg">

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'], display: ['Outfit', 'sans-serif'] },
            colors: {
                adm: { amber: '#f59e0b', gold: '#d97706', dark: '#0d0f12' }
            }
        }
    }
}
</script>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600&display=swap" rel="stylesheet">

<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

<style>
  body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
  .font-display { font-family: 'Outfit', sans-serif; }
  .input-field {
    width: 100%;
    padding: 0.72rem 1rem 0.72rem 2.75rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-size: 0.925rem;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: all .2s ease;
  }
  .input-field:focus {
    border-color: #f59e0b;
    box-shadow: 0 0 0 4px rgba(245,158,11,0.15);
  }
  .input-field::placeholder { color: #94a3b8; }

  .tab-btn {
    flex: 1;
    padding: .6rem .75rem;
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    font-size: .86rem;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    transition: all .2s;
    background: transparent;
    color: #64748b;
  }
  .tab-btn.active {
    background: #ffffff;
    color: #b45309;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
  }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; animation: fadeIn .2s ease; }
  @keyframes fadeIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:translateY(0)} }
</style>
</head>
<body class="bg-[#fafaf9] min-h-screen flex flex-col relative overflow-x-hidden selection:bg-amber-500 selection:text-slate-950">

  <!-- Ambient background glow matching Admin Console theme -->
  <div class="fixed inset-0 pointer-events-none">
    <div class="absolute top-[5%] left-1/2 -translate-x-1/2 w-[750px] h-[380px] rounded-full bg-amber-500/10 blur-[130px]"></div>
    <div class="absolute bottom-[10%] left-1/2 -translate-x-1/2 w-[650px] h-[320px] rounded-full bg-yellow-600/8 blur-[130px]"></div>
    <div class="absolute inset-0 bg-[radial-gradient(rgba(245,158,11,0.03)_1px,transparent_1px)] bg-[size:28px_28px]"></div>
  </div>

  <!-- Top bar — Rich Charcoal-Black (#0d0f12) matching Admin Console -->
  <header class="relative z-20 w-full bg-[#0d0f12] border-b border-amber-500/25 px-6 py-3.5 flex items-center justify-between shadow-lg shadow-black/30">
    <a href="../login.php" class="flex items-center gap-2 text-amber-200/80 hover:text-amber-300 font-medium text-sm transition-colors group">
      <i data-lucide="arrow-left" class="w-4 h-4 text-amber-400 group-hover:-translate-x-0.5 transition-transform"></i>
      <span>Back to Portal</span>
    </a>
    <div class="flex items-center gap-2">
      <div class="w-6 h-6 rounded-md bg-gradient-to-br from-amber-500 to-amber-600 flex items-center justify-center text-slate-950 shadow-xs font-bold">
        <i data-lucide="shield" class="w-3.5 h-3.5 text-slate-950"></i>
      </div>
      <span class="text-xs font-bold text-white tracking-wide">
        Balangoda <span class="text-amber-400">Admin Console</span>
      </span>
      <span class="hidden sm:inline-block text-slate-600 text-xs">&bull;</span>
      <span class="hidden sm:inline-block text-xs font-medium text-slate-300">Staff Portal</span>
    </div>
  </header>

  <!-- Main Content -->
  <main class="relative z-10 flex-1 flex items-center justify-center px-4 py-8 sm:py-12">
    <div class="w-full max-w-md">

      <!-- Card -->
      <div class="bg-white border border-amber-500/25 hover:border-amber-400/50 rounded-3xl p-7 sm:p-9 shadow-xl shadow-amber-950/5 transition-all">

        <!-- Header -->
        <div class="flex flex-col items-center mb-6 text-center">
          <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-600 via-amber-500 to-amber-400 flex items-center justify-center mb-3.5 shadow-lg shadow-amber-500/30 ring-2 ring-amber-400/40 text-slate-950">
            <i data-lucide="shield-check" class="w-8 h-8 text-slate-950"></i>
          </div>
          <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-300 uppercase tracking-wider mb-2">
            <i data-lucide="lock" class="w-3 h-3 text-amber-600"></i>
            Restricted Console
          </div>
          <h1 class="font-display text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Admin Console</h1>
          <p class="text-slate-500 text-xs sm:text-sm mt-1">Authorized municipal staff operations & dispatch</p>
        </div>

        <!-- Restricted Notice -->
        <div class="flex items-center gap-2.5 bg-amber-50/90 border border-amber-300/80 rounded-xl px-3.5 py-2.5 mb-5 text-xs text-amber-900 font-medium">
          <i data-lucide="shield-alert" class="w-4 h-4 flex-shrink-0 text-amber-600"></i>
          <span>Authorized staff only. All login activities are monitored and logged.</span>
        </div>

        <!-- Tabs -->
        <div class="bg-slate-100 rounded-xl p-1 flex gap-1 mb-5 border border-slate-200/80">
          <button class="tab-btn <?= $admin_tab === 'signin' ? 'active' : '' ?>"
                  id="tab-signin" onclick="switchTab('signin')" type="button">
            <i data-lucide="log-in" class="inline w-3.5 h-3.5 mr-1"></i> Sign In
          </button>
          <button class="tab-btn <?= $admin_tab === 'signup' ? 'active' : '' ?>"
                  id="tab-signup" onclick="switchTab('signup')" type="button">
            <i data-lucide="user-plus" class="inline w-3.5 h-3.5 mr-1"></i> Register
          </button>
        </div>

        <!-- Error Alert -->
        <?php if (!empty($admin_error)): ?>
        <div class="flex items-start gap-2.5 p-3.5 bg-rose-50 border border-rose-200 rounded-xl text-rose-800 text-xs sm:text-sm mb-5">
          <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0 mt-0.5 text-rose-600"></i>
          <span><?= htmlspecialchars($admin_error) ?></span>
        </div>
        <?php endif; ?>

        <!-- ── SIGN IN TAB ── -->
        <div id="panel-signin" class="tab-panel <?= $admin_tab === 'signin' ? 'active' : '' ?>">
          <form method="POST" action="" novalidate>
            <input type="hidden" name="action" value="admin_signin">
            <?php if ($redirect): ?>
              <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
            <?php endif; ?>

            <!-- Username / Email -->
            <div class="mb-4">
              <label for="identity" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                Username or Official Email
              </label>
              <div class="relative">
                <i data-lucide="user-cog" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="identity" name="identity"
                       class="input-field"
                       placeholder="admin or officer@domain.lk"
                       value="<?= htmlspecialchars($_POST['identity'] ?? '') ?>"
                       autocomplete="username" required>
              </div>
            </div>

            <!-- Password -->
            <div class="mb-6">
              <label for="pw_signin" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
              <div class="relative">
                <i data-lucide="lock" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="password" id="pw_signin" name="password"
                       class="input-field pr-10"
                       placeholder="Enter your administrator password"
                       autocomplete="current-password" required>
                <button type="button" onclick="togglePw('pw_signin','eye1')"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1"
                        aria-label="Toggle password visibility">
                  <i data-lucide="eye" id="eye1" class="w-4 h-4"></i>
                </button>
              </div>
            </div>

            <button type="submit" id="admin-signin-btn" class="w-full py-3.5 px-5 rounded-xl font-display font-black text-sm bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500 hover:from-amber-600 hover:to-amber-500 text-slate-950 shadow-lg shadow-amber-950/20 hover:shadow-amber-900/30 transition-all flex items-center justify-center gap-2 transform hover:-translate-y-0.5 cursor-pointer">
              <i data-lucide="shield-check" class="w-4 h-4 text-slate-950"></i>
              <span>Sign In to Admin Console</span>
            </button>
          </form>
        </div>

        <!-- ── SIGN UP TAB ── -->
        <div id="panel-signup" class="tab-panel <?= $admin_tab === 'signup' ? 'active' : '' ?>">
          <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-3 text-xs mb-4">
            <span class="font-bold">Staff Verification:</span> Registration is limited to approved municipal addresses (<code>kavindunimsara560@gmail.com</code>).
          </div>

          <form method="POST" action="" novalidate>
            <input type="hidden" name="action" value="admin_signup">

            <!-- Username -->
            <div class="mb-4">
              <label for="su_username" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Username</label>
              <div class="relative">
                <i data-lucide="at-sign" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="su_username" name="username"
                       class="input-field"
                       placeholder="e.g. kavindu_admin"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" required>
              </div>
              <p class="text-[11px] text-slate-400 mt-1">3–30 chars (letters, numbers, underscores)</p>
            </div>

            <!-- Email -->
            <div class="mb-4">
              <label for="su_email" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Official Staff Email</label>
              <div class="relative">
                <i data-lucide="mail" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="email" id="su_email" name="email"
                       class="input-field"
                       placeholder="kavindunimsara560@gmail.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       autocomplete="email" required>
              </div>
            </div>

            <!-- Password -->
            <div class="mb-4">
              <label for="su_pw" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
              <div class="relative">
                <i data-lucide="lock" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="password" id="su_pw" name="password"
                       class="input-field pr-10"
                       placeholder="At least 6 characters"
                       autocomplete="new-password" required>
                <button type="button" onclick="togglePw('su_pw','eye2')"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1"
                        aria-label="Toggle password visibility">
                  <i data-lucide="eye" id="eye2" class="w-4 h-4"></i>
                </button>
              </div>
            </div>

            <!-- Confirm Password -->
            <div class="mb-6">
              <label for="su_cpw" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Confirm Password</label>
              <div class="relative">
                <i data-lucide="lock-keyhole" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="password" id="su_cpw" name="confirm_password"
                       class="input-field"
                       placeholder="Repeat your password"
                       autocomplete="new-password" required>
              </div>
            </div>

            <button type="submit" id="admin-signup-btn" class="w-full py-3.5 px-5 rounded-xl font-display font-bold text-sm bg-slate-900 hover:bg-slate-800 text-amber-300 border border-amber-500/40 shadow-lg shadow-black/20 transition-all flex items-center justify-center gap-2 transform hover:-translate-y-0.5 cursor-pointer">
              <i data-lucide="user-check" class="w-4 h-4 text-amber-400"></i>
              <span>Create Official Admin Account</span>
            </button>
          </form>
        </div>

      </div>

      <!-- Footer -->
      <p class="text-center text-xs text-slate-400 mt-5 flex items-center justify-center gap-1.5">
        <i data-lucide="shield" class="w-3.5 h-3.5 text-amber-600"></i>
        <span>Authorized Municipal Staff Only &bull; Balangoda Urban Council</span>
      </p>

    </div>
  </main>

<script>
lucide.createIcons();

function switchTab(tab) {
    ['signin','signup'].forEach(t => {
        document.getElementById('tab-' + t).classList.toggle('active', t === tab);
        document.getElementById('panel-' + t).classList.toggle('active', t === tab);
    });
}

function togglePw(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.setAttribute('data-lucide', show ? 'eye-off' : 'eye');
    lucide.createIcons();
}

// PWA service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('../admin-sw.js', { scope: '/Web_base_project/' })
        .catch(e => console.warn('[SW]', e));
}
</script>
</body>
</html>
