<?php
session_start();
require_once 'config/db_connect.php';

// Check active session states
$customer_logged_in = isset($_SESSION['customer_id']);
$customer_name      = $customer_logged_in ? htmlspecialchars($_SESSION['customer_name'] ?? 'Resident') : '';
$admin_logged_in    = isset($_SESSION['admin_id']);
$admin_username     = $admin_logged_in ? htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balangoda Municipal Utility Portal</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0d6efd">

    <!-- Google Fonts & Bootstrap -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/style.css">

    <style>
        :root {
            --navy-dark: #070b12;
            --navy-deep: #0f172a;
            --navy-card: #1e293b;
            --cyan-accent: #38bdf8;
            --blue-accent: #2563eb;
            --amber-accent: #f59e0b;
        }

        body {
            font-family: 'Outfit', 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--navy-dark) 0%, #161a2e 50%, var(--navy-deep) 100%);
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Navigation */
        .portal-nav {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .portal-logo {
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #ffffff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .portal-logo .logo-badge {
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            color: #fff;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.4);
        }

        /* Main Minimal Gateway Area */
        .gateway-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3.5rem 1rem;
        }

        .hero-headline {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.5px;
            color: #f8fafc;
            margin-bottom: 0.75rem;
        }

        .hero-headline span {
            background: linear-gradient(135deg, #38bdf8 0%, #818cf8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-lead {
            color: #94a3b8;
            font-size: 1.1rem;
            max-width: 620px;
            margin: 0 auto 3rem;
            line-height: 1.6;
        }

        /* Gateway Cards */
        .gateway-card {
            background: rgba(30, 41, 59, 0.75);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 22px;
            padding: 2.5rem 2.25rem;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.25s ease-in-out;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.35);
            position: relative;
        }

        .gateway-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            border-color: rgba(56, 189, 248, 0.4);
        }

        .gateway-card.admin-gateway:hover {
            border-color: rgba(245, 158, 11, 0.45);
        }

        .card-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 1.5rem;
        }

        .resident-icon-wrap {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.2), rgba(37, 99, 235, 0.25));
            color: var(--cyan-accent);
            border: 1px solid rgba(56, 189, 248, 0.3);
            box-shadow: 0 8px 20px rgba(56, 189, 248, 0.15);
        }

        .admin-icon-wrap {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(220, 38, 38, 0.25));
            color: var(--amber-accent);
            border: 1px solid rgba(245, 158, 11, 0.3);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.15);
        }

        .gateway-card h3 {
            font-size: 1.55rem;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: 0.75rem;
            letter-spacing: -0.3px;
        }

        .gateway-card p {
            color: #94a3b8;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .btn-gateway-primary {
            background: linear-gradient(135deg, #2563eb, #38bdf8);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.75rem 1.25rem;
            transition: all 0.2s;
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.35);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-gateway-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.45);
            color: #ffffff;
        }

        .btn-gateway-admin {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #0f172a;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.75rem 1.25rem;
            transition: all 0.2s;
            box-shadow: 0 6px 18px rgba(217, 119, 6, 0.35);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-gateway-admin:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(217, 119, 6, 0.45);
            color: #0f172a;
        }

        .btn-gateway-outline {
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 12px;
            color: #cbd5e1;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.04);
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-gateway-outline:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.3);
        }

        .active-session-banner {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.28);
            border-radius: 12px;
            padding: 0.65rem 1rem;
            color: #86efac;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .active-admin-banner {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.28);
            border-radius: 12px;
            padding: 0.65rem 1rem;
            color: #fde68a;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Minimal Footer */
        .portal-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            padding: 1.5rem 0;
            color: #64748b;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>

    <!-- Minimal Top Navbar -->
    <nav class="navbar navbar-expand portal-nav py-3">
        <div class="container">
            <a class="portal-logo" href="index.php">
                <span class="logo-badge">⚡</span>
                <div>
                    <div style="font-size:1.15rem; line-height:1.2;">Balangoda Utility Portal</div>
                    <div style="font-size:0.75rem; color:#94a3b8; font-weight:500;">Municipal Council Outage &amp; Incident Management</div>
                </div>
            </a>

            <div class="d-flex align-items-center gap-3 ms-auto">
                <!-- PWA Install App Button -->
                <button class="btn btn-outline-info btn-sm d-none pwa-install-btn fw-semibold" title="Install App on your device">
                    <i class="bi bi-download me-1"></i> Install App
                </button>

                <!-- Active Session Badges if already signed in -->
                <?php if ($customer_logged_in): ?>
                    <a href="customer/dashboard.php" class="btn btn-outline-info btn-sm fw-bold">
                        <i class="bi bi-person-circle me-1"></i> <?= $customer_name ?>
                    </a>
                <?php endif; ?>

                <?php if ($admin_logged_in): ?>
                    <a href="admin/dashboard.php" class="btn btn-outline-warning btn-sm fw-bold">
                        <i class="bi bi-shield-lock-fill me-1"></i> <?= $admin_username ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Main Clean Minimal Gateway -->
    <main class="gateway-section">
        <div class="container" style="max-width: 960px;">
            <div class="text-center">
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill mb-3" style="background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.25); color:var(--cyan-accent); font-size:0.82rem; font-weight:600;">
                    <i class="bi bi-shield-check"></i> Official Municipal Gateway • Balangoda Urban Council
                </div>
                <h1 class="hero-headline">
                    Municipal Utility <span>Outage &amp; Incident</span> Portal
                </h1>
                <p class="hero-lead">
                    Select your portal below to access utility warning bulletins, submit outage complaints, or manage municipal repair dispatches.
                </p>
            </div>

            <!-- Two Clean Gateway Cards -->
            <div class="row g-4">
                <!-- 1. Citizen & Resident Services Card -->
                <div class="col-md-6">
                    <div class="gateway-card resident-gateway">
                        <div>
                            <div class="card-icon-wrap resident-icon-wrap">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <h3>Citizen &amp; Resident Services</h3>
                            <p>For Balangoda residents and local property owners. Access live outage bulletins, lodge water/power/road failure reports, and track repair status.</p>
                        </div>

                        <div>
                            <?php if ($customer_logged_in): ?>
                                <div class="active-session-banner">
                                    <span><i class="bi bi-check-circle-fill me-1"></i> Signed in as <strong><?= $customer_name ?></strong></span>
                                    <a href="logout.php" class="text-white-50 text-decoration-none small">Sign Out</a>
                                </div>
                                <div class="d-grid gap-2">
                                    <a href="customer/dashboard.php" class="btn-gateway-primary">
                                        <i class="bi bi-speedometer2 me-2"></i> Enter Resident Dashboard
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="d-grid gap-2">
                                    <a href="login.php?role=customer" class="btn-gateway-primary">
                                        <i class="bi bi-box-arrow-in-right me-2"></i> Resident Sign In
                                    </a>
                                    <a href="customer/register.php" class="btn-gateway-outline">
                                        <i class="bi bi-person-plus me-2"></i> Create Account
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 2. Administrative Console Card -->
                <div class="col-md-6">
                    <div class="gateway-card admin-gateway">
                        <div>
                            <div class="card-icon-wrap admin-icon-wrap">
                                <i class="bi bi-shield-lock-fill"></i>
                            </div>
                            <h3>Administrative Console</h3>
                            <p>For authorized Balangoda Municipal Council officers, technical supervisors, and utility coordinators managing city infrastructure.</p>
                        </div>

                        <div>
                            <?php if ($admin_logged_in): ?>
                                <div class="active-admin-banner">
                                    <span><i class="bi bi-shield-lock-fill me-1"></i> Admin Active: <strong><?= $admin_username ?></strong></span>
                                    <a href="logout.php" class="text-white-50 text-decoration-none small">Sign Out</a>
                                </div>
                                <div class="d-grid gap-2">
                                    <a href="admin/dashboard.php" class="btn-gateway-admin">
                                        <i class="bi bi-sliders me-2"></i> Enter Admin Dashboard
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="d-grid gap-2">
                                    <a href="login.php?role=admin" class="btn-gateway-admin">
                                        <i class="bi bi-shield-lock me-2"></i> Admin Sign In
                                    </a>
                                    <a href="login.php?role=admin&tab=signup" class="btn-gateway-outline">
                                        <i class="bi bi-person-check me-2"></i> Official Sign Up
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Minimal Footer -->
    <footer class="portal-footer text-center">
        <div class="container">
            <div class="mb-1">
                Emergency Hotlines: Electricity (CEB): <strong>1987</strong> &bull; Water (NWSDB): <strong>1939</strong> &bull; Urban Council: <strong>045-2287222</strong>
            </div>
            <div>&copy; <?= date('Y') ?> Balangoda Municipal Council &bull; All Rights Reserved</div>
        </div>
    </footer>

    <!-- Bootstrap JS & PWA Script -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/pwa.js"></script>
</body>
</html>
