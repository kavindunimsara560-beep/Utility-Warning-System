<?php
session_start();

// Role-based direct redirects (legacy support)
if (isset($_GET['role'])) {
    $role   = $_GET['role'];
    $params = $_GET;
    unset($params['role']);
    $qs = !empty($params) ? '?' . http_build_query($params) : '';
    if ($role === 'admin')    { header("Location: admin/login_page.php"    . $qs); exit(); }
    if ($role === 'customer') { header("Location: customer/login_page.php" . $qs); exit(); }
}

// Session state
$customer_logged_in = !empty($_SESSION['customer_id']);
$admin_logged_in    = !empty($_SESSION['admin_id']);
$customer_name      = $customer_logged_in ? htmlspecialchars($_SESSION['customer_name']    ?? 'Resident')      : '';
$admin_username     = $admin_logged_in    ? htmlspecialchars($_SESSION['admin_username']   ?? 'Administrator') : '';

// Notice messages
$notice = '';
$notice_type = 'success';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logged_out')      { $notice = 'You have been safely signed out.';                       $notice_type = 'success'; }
    elseif ($_GET['msg'] === 'login_required') { $notice = 'Please sign in to continue.';                         $notice_type = 'warning'; }
    elseif ($_GET['msg'] === 'verified')    { $notice = 'Your email has been verified! You can now sign in.';     $notice_type = 'success'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Balangoda Municipal Utility System — Portal Launcher</title>
<meta name="description" content="Access the Resident Portal or Admin Console for the Balangoda Municipal Utility Warning System.">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#060c1a">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
/* ══════════════════════════════════════════════════════════
   Balangoda Utility — Split Portal Launcher
   Two distinct apps, side by side
══════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    /* Resident — Blue/Cyan */
    --res-dark:   #020810;
    --res-mid:    #050d20;
    --res-accent: #2563eb;
    --res-bright: #38bdf8;
    --res-glow:   rgba(37,99,235,0.35);

    /* Admin — Amber/Gold */
    --adm-dark:   #080500;
    --adm-mid:    #120c00;
    --adm-accent: #d97706;
    --adm-bright: #f59e0b;
    --adm-glow:   rgba(217,119,6,0.35);
}

html, body { height: 100%; }

body {
    font-family: 'Inter', system-ui, sans-serif;
    min-height: 100vh;
    overflow-x: hidden;
    color: #f1f5f9;
}

/* ── Split Layout ───────────────────────────────────────── */
.split-screen {
    display: flex;
    min-height: 100vh;
    position: relative;
}

/* ── Notice Bar ─────────────────────────────────────────── */
.notice-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    padding: 0.65rem 1.5rem;
    font-size: 0.875rem;
    font-weight: 500;
    animation: slideDown 0.4s ease;
}
.notice-bar.success { background: rgba(16,185,129,0.92); backdrop-filter: blur(10px); color: #fff; }
.notice-bar.warning { background: rgba(245,158,11,0.92); backdrop-filter: blur(10px); color: #000; }
@keyframes slideDown { from { transform: translateY(-100%); opacity:0; } to { transform: translateY(0); opacity:1; } }

/* ── Centre Divider Badge ──────────────────────────────── */
.centre-divider {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    z-index: 20;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    pointer-events: none;
}

.divider-line {
    width: 2px;
    height: 80px;
    background: linear-gradient(to bottom, transparent, rgba(255,255,255,0.18), transparent);
}

.divider-badge {
    width: 52px; height: 52px;
    border-radius: 50%;
    background: rgba(10,15,30,0.90);
    border: 2px solid rgba(255,255,255,0.14);
    backdrop-filter: blur(12px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    box-shadow: 0 0 30px rgba(0,0,0,0.6);
}

/* ══════════════════════════════════════════════════════════
   RESIDENT SIDE (left — Blue theme)
══════════════════════════════════════════════════════════ */
.side {
    flex: 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 3.5rem;
    overflow: hidden;
    transition: flex 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 100vh;
}

.side:hover { flex: 1.08; }

/* Resident side */
.resident-side {
    background:
        radial-gradient(ellipse 100% 70% at 20% 10%, rgba(37,99,235,0.22) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 90%, rgba(6,182,212,0.10) 0%, transparent 55%),
        linear-gradient(160deg, #020810 0%, #050d20 60%, #070e1c 100%);
    border-right: 1px solid rgba(255,255,255,0.06);
}

/* Admin side */
.admin-side {
    background:
        radial-gradient(ellipse 100% 70% at 80% 10%, rgba(217,119,6,0.22) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 20% 90%, rgba(146,64,14,0.10) 0%, transparent 55%),
        linear-gradient(200deg, #080500 0%, #120c00 60%, #0f0900 100%);
}

/* Animated background glow per side */
.resident-side::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 80% 60% at 30% 30%, rgba(37,99,235,0.08) 0%, transparent 60%);
    animation: resGlow 8s ease-in-out infinite alternate;
    pointer-events: none;
}
.admin-side::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 80% 60% at 70% 30%, rgba(217,119,6,0.08) 0%, transparent 60%);
    animation: admGlow 8s ease-in-out infinite alternate;
    pointer-events: none;
}

@keyframes resGlow  { from { opacity:0.5; } to { opacity:1; } }
@keyframes admGlow  { from { opacity:1; }   to { opacity:0.5; } }

/* Floating grid dots */
.side::after {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(rgba(255,255,255,0.035) 1px, transparent 1px);
    background-size: 28px 28px;
    pointer-events: none;
}

/* ── App Tag ──────────────────────────────────────────────── */
.app-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.3rem 0.85rem;
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 2rem;
    position: relative;
    z-index: 1;
}
.resident-tag {
    background: rgba(37,99,235,0.18);
    border: 1px solid rgba(56,189,248,0.30);
    color: #93c5fd;
}
.admin-tag {
    background: rgba(217,119,6,0.18);
    border: 1px solid rgba(245,158,11,0.30);
    color: #fde68a;
}

/* ── App Icon ──────────────────────────────────────────────── */
.app-icon {
    position: relative;
    z-index: 1;
    width: 88px; height: 88px;
    border-radius: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.6rem;
    margin-bottom: 1.75rem;
}
.resident-icon {
    background: linear-gradient(135deg, #1d4ed8 0%, #0891b2 100%);
    box-shadow: 0 16px 48px rgba(37,99,235,0.50), 0 0 0 1px rgba(255,255,255,0.12) inset;
    animation: iconFloatBlue 4s ease-in-out infinite alternate;
}
.admin-icon {
    background: linear-gradient(135deg, #92400e 0%, #d97706 50%, #f59e0b 100%);
    box-shadow: 0 16px 48px rgba(217,119,6,0.50), 0 0 0 1px rgba(255,255,255,0.12) inset;
    animation: iconFloatGold 4s ease-in-out infinite alternate;
}

@keyframes iconFloatBlue {
    from { transform: translateY(0); box-shadow: 0 16px 48px rgba(37,99,235,0.45), 0 0 0 1px rgba(255,255,255,0.12) inset; }
    to   { transform: translateY(-8px); box-shadow: 0 28px 60px rgba(37,99,235,0.65), 0 0 0 1px rgba(255,255,255,0.18) inset; }
}
@keyframes iconFloatGold {
    from { transform: translateY(-8px); box-shadow: 0 28px 60px rgba(217,119,6,0.65), 0 0 0 1px rgba(255,255,255,0.18) inset; }
    to   { transform: translateY(0); box-shadow: 0 16px 48px rgba(217,119,6,0.45), 0 0 0 1px rgba(255,255,255,0.12) inset; }
}

/* ── App Content ───────────────────────────────────────────── */
.app-content {
    position: relative;
    z-index: 1;
    text-align: center;
    max-width: 340px;
}

.app-name {
    font-family: 'Outfit', sans-serif;
    font-weight: 800;
    font-size: clamp(1.6rem, 3vw, 2.2rem);
    letter-spacing: -0.04em;
    line-height: 1.1;
    margin-bottom: 0.75rem;
}
.resident-name { color: #f0f9ff; }
.admin-name    { color: #fffbeb; }

.app-desc {
    font-size: 0.9rem;
    line-height: 1.7;
    color: rgba(200,220,240,0.65);
    margin-bottom: 2rem;
}
.admin-side .app-desc { color: rgba(253,230,138,0.55); }

/* ── Feature Pills ─────────────────────────────────────────── */
.feature-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: center;
    margin-bottom: 2.25rem;
}
.pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 0.3rem 0.75rem;
    border-radius: 99px;
    font-size: 0.78rem;
    font-weight: 500;
}
.resident-side .pill {
    background: rgba(37,99,235,0.14);
    border: 1px solid rgba(56,189,248,0.18);
    color: #93c5fd;
}
.admin-side .pill {
    background: rgba(217,119,6,0.14);
    border: 1px solid rgba(245,158,11,0.18);
    color: #fde68a;
}

/* ── CTA Buttons ───────────────────────────────────────────── */
.cta-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    width: 100%;
    max-width: 280px;
}

.btn-launch {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    padding: 0.9rem 1.5rem;
    border-radius: 14px;
    font-family: 'Outfit', sans-serif;
    font-size: 1rem;
    font-weight: 700;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.2s ease, opacity 0.2s;
    letter-spacing: 0.01em;
    position: relative;
    overflow: hidden;
}
.btn-launch::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0);
    transition: background 0.2s;
}
.btn-launch:hover::after { background: rgba(255,255,255,0.06); }
.btn-launch:hover { transform: translateY(-3px); }
.btn-launch:active { transform: translateY(0) scale(0.98); }

.btn-res-primary {
    background: linear-gradient(135deg, #1d4ed8 0%, #0891b2 100%);
    color: #fff;
    box-shadow: 0 8px 28px rgba(37,99,235,0.45);
}
.btn-res-primary:hover { box-shadow: 0 14px 36px rgba(37,99,235,0.60); color: #fff; }

.btn-res-secondary {
    background: rgba(37,99,235,0.10);
    color: #93c5fd;
    border: 1px solid rgba(56,189,248,0.25);
    box-shadow: none;
}
.btn-res-secondary:hover { background: rgba(37,99,235,0.18); color: #bae6fd; box-shadow: none; }

.btn-adm-primary {
    background: linear-gradient(135deg, #92400e 0%, #d97706 50%, #f59e0b 100%);
    color: #fff;
    box-shadow: 0 8px 28px rgba(217,119,6,0.45);
}
.btn-adm-primary:hover { box-shadow: 0 14px 36px rgba(217,119,6,0.60); color: #fff; }

.btn-adm-secondary {
    background: rgba(217,119,6,0.10);
    color: #fde68a;
    border: 1px solid rgba(245,158,11,0.25);
    box-shadow: none;
}
.btn-adm-secondary:hover { background: rgba(217,119,6,0.18); color: #fef3c7; box-shadow: none; }

/* ── Already Logged In Banner ──────────────────────────────── */
.session-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1rem;
    border-radius: 12px;
    font-size: 0.82rem;
    margin-bottom: 1.25rem;
    width: 100%;
    max-width: 280px;
}
.session-bar-res {
    background: rgba(37,99,235,0.14);
    border: 1px solid rgba(56,189,248,0.22);
    color: #bae6fd;
}
.session-bar-adm {
    background: rgba(217,119,6,0.14);
    border: 1px solid rgba(245,158,11,0.22);
    color: #fde68a;
}
.session-bar strong { display: block; font-size: 0.88rem; }
.session-bar a {
    margin-left: auto;
    font-size: 0.78rem;
    opacity: 0.75;
    text-decoration: none;
    color: inherit;
    white-space: nowrap;
}
.session-bar a:hover { opacity: 1; text-decoration: underline; }

/* ── System Label at top ───────────────────────────────────── */
.system-header {
    position: fixed;
    top: 0; left: 50%; transform: translateX(-50%);
    z-index: 50;
    padding: 0.6rem 2rem;
    text-align: center;
    pointer-events: none;
}
.system-label {
    font-family: 'Outfit', sans-serif;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.30);
}

/* ── Responsive ────────────────────────────────────────────── */
@media (max-width: 768px) {
    .split-screen { flex-direction: column; }
    .side { padding: 3rem 1.75rem; min-height: 50vh; }
    .side:hover { flex: 1; }
    .resident-side { border-right: none; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .centre-divider { display: none; }
    .divider-line { display: none; }
    .app-name { font-size: 1.7rem; }
}

@media (max-width: 420px) {
    .side { padding: 2.5rem 1.25rem; }
}
</style>
</head>
<body>

<?php if (!empty($notice)): ?>
<div class="notice-bar <?= htmlspecialchars($notice_type) ?>">
    <i class="bi bi-<?= $notice_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
    <?= htmlspecialchars($notice) ?>
</div>
<?php endif; ?>

<!-- System label -->
<div class="system-header">
    <div class="system-label">Balangoda Municipal Utility System</div>
</div>

<!-- Centre divider -->
<div class="centre-divider">
    <div class="divider-line"></div>
    <div class="divider-badge">⚡</div>
    <div class="divider-line"></div>
</div>

<div class="split-screen">

    <!-- ════════════════ RESIDENT SIDE ════════════════ -->
    <div class="side resident-side">
        <div class="app-tag resident-tag">
            <i class="bi bi-people-fill"></i> Resident App
        </div>

        <div class="app-icon resident-icon">
            <i class="bi bi-house-fill" style="color:#fff;"></i>
        </div>

        <div class="app-content">
            <div class="app-name resident-name">Resident<br>Portal</div>
            <p class="app-desc">
                Your personal civic dashboard to track utility outages, submit complaints, and receive real-time alerts for Balangoda.
            </p>

            <div class="feature-pills">
                <span class="pill"><i class="bi bi-broadcast"></i> Live Alerts</span>
                <span class="pill"><i class="bi bi-chat-dots-fill"></i> Complaints</span>
                <span class="pill"><i class="bi bi-bell-fill"></i> Notifications</span>
                <span class="pill"><i class="bi bi-clock-history"></i> Outage History</span>
            </div>

            <?php if ($customer_logged_in): ?>
                <div class="session-bar session-bar-res">
                    <i class="bi bi-check-circle-fill" style="font-size:1.1rem; flex-shrink:0;"></i>
                    <div>
                        <strong><?= $customer_name ?></strong>
                        Signed in as Resident
                    </div>
                    <a href="logout.php?role=customer">Sign Out</a>
                </div>
                <div class="cta-group">
                    <a href="customer/dashboard.php" class="btn-launch btn-res-primary">
                        <i class="bi bi-speedometer2"></i> Go to Dashboard
                    </a>
                </div>
            <?php else: ?>
                <div class="cta-group">
                    <a href="customer/login_page.php" id="resident-signin-btn" class="btn-launch btn-res-primary">
                        <i class="bi bi-box-arrow-in-right"></i> Sign In
                    </a>
                    <a href="customer/register.php" id="resident-register-btn" class="btn-launch btn-res-secondary">
                        <i class="bi bi-person-plus"></i> Create Account
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════════════════ ADMIN SIDE ════════════════ -->
    <div class="side admin-side">
        <div class="app-tag admin-tag">
            <i class="bi bi-shield-lock-fill"></i> Admin App
        </div>

        <div class="app-icon admin-icon">
            <i class="bi bi-shield-lock-fill" style="color:#fff;"></i>
        </div>

        <div class="app-content">
            <div class="app-name admin-name">Admin<br>Console</div>
            <p class="app-desc">
                Restricted access for authorized Balangoda municipal staff to manage utility warnings, resident accounts, and system operations.
            </p>

            <div class="feature-pills">
                <span class="pill"><i class="bi bi-broadcast-pin"></i> Post Warnings</span>
                <span class="pill"><i class="bi bi-people-fill"></i> Manage Residents</span>
                <span class="pill"><i class="bi bi-graph-up-arrow"></i> Analytics</span>
                <span class="pill"><i class="bi bi-gear-fill"></i> System Config</span>
            </div>

            <?php if ($admin_logged_in): ?>
                <div class="session-bar session-bar-adm">
                    <i class="bi bi-shield-lock-fill" style="font-size:1.1rem; flex-shrink:0;"></i>
                    <div>
                        <strong><?= $admin_username ?></strong>
                        Signed in as Administrator
                    </div>
                    <a href="logout.php?role=admin">Sign Out</a>
                </div>
                <div class="cta-group">
                    <a href="admin/dashboard.php" class="btn-launch btn-adm-primary">
                        <i class="bi bi-sliders"></i> Go to Dashboard
                    </a>
                </div>
            <?php else: ?>
                <div class="cta-group">
                    <a href="admin/login_page.php" id="admin-signin-btn" class="btn-launch btn-adm-primary">
                        <i class="bi bi-shield-check"></i> Admin Sign In
                    </a>
                    <a href="admin/login_page.php?tab=signup" id="admin-signup-btn" class="btn-launch btn-adm-secondary">
                        <i class="bi bi-person-badge"></i> Official Sign Up
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.split-screen -->

<script>
// Entrance animation
document.querySelectorAll('.side').forEach((el, i) => {
    el.style.opacity = '0';
    el.style.transform = i === 0 ? 'translateX(-30px)' : 'translateX(30px)';
    setTimeout(() => {
        el.style.transition = 'opacity 0.65s ease, transform 0.65s cubic-bezier(0.34,1.56,0.64,1)';
        el.style.opacity = '1';
        el.style.transform = 'translateX(0)';
    }, 80 + i * 120);
});

// Auto-dismiss notice
const notice = document.querySelector('.notice-bar');
if (notice) setTimeout(() => {
    notice.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
    notice.style.opacity = '0';
    notice.style.transform = 'translateY(-100%)';
    setTimeout(() => notice.remove(), 400);
}, 4000);
</script>
</body>
</html>
