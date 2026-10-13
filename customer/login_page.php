<?php
session_start();
require_once '../config/db_connect.php';

// If customer already logged in, go to dashboard
if (isset($_SESSION['customer_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Redirect target
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
$allowed_redirects = ['index.php', 'submit_complaint.php', 'report.php', 'customer/dashboard.php'];
if (!in_array($redirect, $allowed_redirects, true)) {
    $redirect = '';
}

// Notice messages
$notice      = '';
$notice_type = 'info';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'login_required') {
        $notice      = 'Authentication Required: Please sign in to access that page.';
        $notice_type = 'warning';
    } elseif ($_GET['msg'] === 'logged_out') {
        $notice      = 'You have been safely signed out.';
        $notice_type = 'success';
    } elseif ($_GET['msg'] === 'verified') {
        $notice      = 'Your resident email has been verified! Please sign in below.';
        $notice_type = 'success';
    } elseif ($_GET['msg'] === 'registered') {
        $notice      = 'Account successfully verified and registered! Please sign in.';
        $notice_type = 'success';
    }
}

$cust_error = '';

// ── Handle Resident Login ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'customer_login') {
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
                session_regenerate_id(true);
                unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_email'], $_SESSION['admin_fullname'], $_SESSION['admin_avatar']);
                $_SESSION['active_role']     = 'customer';
                $_SESSION['customer_id']     = $cust['customer_id'];
                $_SESSION['customer_name']   = $cust['full_name'];
                $_SESSION['customer_email']  = $cust['email'];
                $_SESSION['customer_phone']  = $cust['phone'];
                $_SESSION['customer_avatar'] = $cust['profile_pic'] ?? null;

                if (!empty($redirect)) {
                    if (strpos($redirect, 'customer/') === 0) {
                        $target = substr($redirect, strlen('customer/'));
                    } else {
                        $target = '../' . $redirect;
                    }
                } else {
                    $target = 'dashboard.php';
                }
                header("Location: " . $target);
                exit();
            }
        } else {
            $cust_error = "Database query failed: " . htmlspecialchars($conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resident Sign In — Balangoda Municipal Utility Portal</title>
<meta name="description" content="Resident login portal — track utility outages, file complaints, and receive real-time alerts.">
<meta name="theme-color" content="#091322">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Resident Portal">
<link rel="manifest" href="../resident-manifest.json">
<link rel="apple-touch-icon" href="../icons/resident-icon-512.jpg">

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'], display: ['Outfit', 'sans-serif'] },
            colors: {
                brand: { 500: '#2563eb', 600: '#1d4ed8', cyan: '#38bdf8' }
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
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37,99,235,0.12);
  }
  .input-field::placeholder { color: #94a3b8; }
</style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col relative overflow-x-hidden selection:bg-cyan-500 selection:text-white">

  <!-- Ambient background glow matching Resident Portal theme -->
  <div class="fixed inset-0 pointer-events-none">
    <div class="absolute top-[5%] left-1/2 -translate-x-1/2 w-[750px] h-[380px] rounded-full bg-blue-500/10 blur-[130px]"></div>
    <div class="absolute bottom-[10%] left-1/2 -translate-x-1/2 w-[650px] h-[320px] rounded-full bg-cyan-400/8 blur-[130px]"></div>
    <div class="absolute inset-0 bg-[radial-gradient(rgba(37,99,235,0.03)_1px,transparent_1px)] bg-[size:28px_28px]"></div>
  </div>

  <!-- Top bar — Sleek Dark Navy (#091322) matching Resident Portal -->
  <header class="relative z-20 w-full bg-[#091322] border-b border-cyan-500/25 px-6 py-3.5 flex items-center justify-between shadow-lg shadow-blue-950/20">
    <div class="flex items-center gap-2.5">
      <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-blue-600 to-cyan-400 flex items-center justify-center text-white shadow-xs">
        <i data-lucide="zap" class="w-4 h-4 text-white"></i>
      </div>
      <span class="text-sm font-bold text-white tracking-wide">
        Balangoda <span class="text-cyan-400">Urban Council</span>
      </span>
    </div>
    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-950/60 text-cyan-300 border border-cyan-500/30">
      <i data-lucide="shield-check" class="w-3.5 h-3.5 text-cyan-400"></i>
      <span>Resident Portal</span>
    </div>
  </header>

  <!-- Main Content -->
  <main class="relative z-10 flex-1 flex items-center justify-center px-4 py-8 sm:py-12">
    <div class="w-full max-w-md">

      <!-- Card -->
      <div class="bg-white border border-slate-200/90 hover:border-cyan-400/30 rounded-3xl p-7 sm:p-9 shadow-xl shadow-blue-950/5 transition-all">

        <!-- Header -->
        <div class="flex flex-col items-center mb-6 text-center">
          <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-500 flex items-center justify-center mb-3.5 shadow-lg shadow-blue-500/30 ring-2 ring-cyan-400/30">
            <i data-lucide="home" class="w-8 h-8 text-white"></i>
          </div>
          <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-cyan-700 border border-cyan-200 uppercase tracking-wider mb-2">
            <i data-lucide="users" class="w-3 h-3 text-cyan-600"></i>
            Resident Portal
          </div>
          <h1 class="font-display text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Resident Sign In</h1>
          <p class="text-slate-500 text-xs sm:text-sm mt-1">Access real-time outage warnings and lodge complaints</p>
        </div>

        <!-- Notice -->
        <?php if (!empty($notice)): ?>
        <div class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs sm:text-sm font-medium mb-5 <?= $notice_type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-amber-50 border border-amber-200 text-amber-800' ?>">
          <i data-lucide="<?= $notice_type === 'success' ? 'check-circle' : 'alert-triangle' ?>" class="w-4 h-4 flex-shrink-0"></i>
          <span><?= htmlspecialchars($notice) ?></span>
        </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if (!empty($cust_error)): ?>
        <div class="flex items-start gap-2.5 p-3.5 bg-rose-50 border border-rose-200 rounded-xl text-rose-800 text-xs sm:text-sm mb-5">
          <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0 mt-0.5 text-rose-600"></i>
          <span><?= htmlspecialchars($cust_error) ?></span>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="" novalidate>
          <input type="hidden" name="action" value="customer_login">
          <?php if ($redirect): ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
          <?php endif; ?>

          <!-- Email -->
          <div class="mb-4">
            <label for="email" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Email Address</label>
            <div class="relative">
              <i data-lucide="mail" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
              <input type="email" id="email" name="email"
                     class="input-field"
                     placeholder="resident@example.com"
                     value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                     autocomplete="email" required>
            </div>
          </div>

          <!-- Password -->
          <div class="mb-5">
            <div class="flex items-center justify-between mb-1.5">
              <label for="password" class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Password</label>
            </div>
            <div class="relative">
              <i data-lucide="lock" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
              <input type="password" id="password" name="password"
                     class="input-field pr-10"
                     placeholder="Enter your password"
                     autocomplete="current-password" required>
              <button type="button" id="togglePw"
                      class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors p-1"
                      aria-label="Toggle password visibility">
                <i data-lucide="eye" class="w-4 h-4" id="eyeIcon"></i>
              </button>
            </div>
          </div>

          <!-- Submit -->
          <button type="submit" id="signin-btn" class="w-full py-3.5 px-5 rounded-xl font-display font-bold text-sm bg-gradient-to-r from-blue-600 via-blue-500 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white shadow-lg shadow-blue-900/30 hover:shadow-cyan-900/40 transition-all flex items-center justify-center gap-2 transform hover:-translate-y-0.5 cursor-pointer">
            <i data-lucide="log-in" class="w-4 h-4"></i>
            <span>Sign In as Resident</span>
          </button>
        </form>

        <!-- Divider -->
        <div class="flex items-center gap-3 my-5">
          <div class="flex-1 h-px bg-slate-200"></div>
          <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider">New Resident?</span>
          <div class="flex-1 h-px bg-slate-200"></div>
        </div>

        <!-- Register link -->
        <a href="register.php" id="register-btn"
           class="flex items-center justify-center gap-2 w-full py-3 px-4 rounded-xl border border-slate-200 hover:border-cyan-400/60 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs sm:text-sm font-semibold transition-all">
          <i data-lucide="user-plus" class="w-4 h-4 text-cyan-600"></i>
          <span>Create a Resident Account</span>
        </a>

      </div>

      <!-- Trust note -->
      <p class="text-center text-xs text-slate-400 mt-5 flex items-center justify-center gap-1.5">
        <i data-lucide="shield-check" class="w-3.5 h-3.5 text-cyan-600"></i>
        <span>Official Citizen Portal &bull; Balangoda Urban Council</span>
      </p>
    </div>
  </main>

<script>
lucide.createIcons();

// Password toggle
const pwInput = document.getElementById('password');
const toggleBtn = document.getElementById('togglePw');
const eyeIcon  = document.getElementById('eyeIcon');
toggleBtn.addEventListener('click', () => {
    const visible = pwInput.type === 'text';
    pwInput.type  = visible ? 'password' : 'text';
    eyeIcon.setAttribute('data-lucide', visible ? 'eye-off' : 'eye');
    lucide.createIcons();
});

// PWA service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('../resident-sw.js', { scope: '/Web_base_project/' })
        .catch(e => console.warn('[SW] Error:', e));
}
</script>
</body>
</html>
