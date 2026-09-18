<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php?role=admin&msg=login_required&redirect=" . urlencode('admin/profile.php'));
    exit();
}

require_once '../config/db_connect.php';
require_once '../config/profile_helper.php';
ensure_profile_schema($conn);

$admin_id = (int)$_SESSION['admin_id'];
$flash = null;

// Fetch current admin record
$stmt = $conn->prepare("SELECT admin_id, username, full_name, email, phone, address, profile_pic, created_at, password_hash FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    session_destroy();
    header("Location: ../login.php?role=admin");
    exit();
}

// -------------------------------------------------------------------------
// Handle Profile Info & Avatar Update
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    $err = '';
    if (empty($username)) {
        $err = "Username is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $err = "Username must be 3-30 characters (letters, numbers, underscores only).";
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = "Please enter a valid email address.";
    }

    // Check unique username (if changed)
    if (empty($err) && $username !== $admin['username']) {
        $u_check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? AND admin_id != ?");
        $u_check->bind_param("si", $username, $admin_id);
        $u_check->execute();
        if ($u_check->get_result()->num_rows > 0) {
            $err = "This username is already taken by another administrator.";
        }
        $u_check->close();
    }

    // Check unique email (if changed)
    if (empty($err) && !empty($email) && $email !== strtolower($admin['email'] ?? '')) {
        $e_check = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? AND admin_id != ?");
        $e_check->bind_param("si", $email, $admin_id);
        $e_check->execute();
        if ($e_check->get_result()->num_rows > 0) {
            $err = "This email is already registered to another administrator.";
        }
        $e_check->close();
    }

    $avatar_path = $admin['profile_pic'];

    // Handle remove avatar
    if (isset($_POST['remove_avatar']) && $_POST['remove_avatar'] == '1') {
        if (!empty($avatar_path)) {
            delete_avatar_file($avatar_path);
            $avatar_path = null;
        }
    }

    // Handle avatar upload
    if (empty($err) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_res = handle_avatar_upload($_FILES['avatar'], 'admin', $admin_id, $avatar_path);
        if ($upload_res['success']) {
            $avatar_path = $upload_res['path'];
        } else {
            $err = $upload_res['error'];
        }
    }

    if (empty($err)) {
        $upd = $conn->prepare("UPDATE admins SET full_name = ?, username = ?, email = ?, phone = ?, address = ?, profile_pic = ? WHERE admin_id = ?");
        $upd->bind_param("ssssssi", $full_name, $username, $email, $phone, $address, $avatar_path, $admin_id);
        if ($upd->execute()) {
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_email']    = $email;
            $_SESSION['admin_fullname'] = $full_name;
            $_SESSION['admin_avatar']   = $avatar_path;
            $flash = ['type' => 'success', 'text' => 'Administrator profile updated successfully!'];

            // Refresh data
            $stmt = $conn->prepare("SELECT admin_id, username, full_name, email, phone, address, profile_pic, created_at, password_hash FROM admins WHERE admin_id = ?");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $flash = ['type' => 'danger', 'text' => 'Database error: ' . $upd->error];
        }
        $upd->close();
    } else {
        $flash = ['type' => 'danger', 'text' => $err];
    }
}

// -------------------------------------------------------------------------
// Handle Password Change
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $cur_pass  = $_POST['current_password'] ?? '';
    $new_pass  = $_POST['new_password'] ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';

    $err = '';
    if (empty($cur_pass) || empty($new_pass) || empty($conf_pass)) {
        $err = "All password fields are required.";
    } elseif (!password_verify($cur_pass, $admin['password_hash'])) {
        $err = "Current administrative password is incorrect.";
    } elseif (strlen($new_pass) < 6) {
        $err = "New password must be at least 6 characters in length.";
    } elseif ($new_pass !== $conf_pass) {
        $err = "New password confirmation does not match.";
    }

    if (empty($err)) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $pw_stmt = $conn->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ?");
        $pw_stmt->bind_param("si", $new_hash, $admin_id);
        if ($pw_stmt->execute()) {
            $flash = ['type' => 'success', 'text' => 'Administrator credentials updated successfully!'];
            $admin['password_hash'] = $new_hash;
        } else {
            $flash = ['type' => 'danger', 'text' => 'Failed to update password: ' . $pw_stmt->error];
        }
        $pw_stmt->close();
    } else {
        $flash = ['type' => 'danger', 'text' => $err];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile — Balangoda Utility System</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .admin-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff;
            padding: 2.2rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 2rem;
        }
        .admin-avatar {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.85);
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }
        .admin-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .nav-avatar-mini {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
            vertical-align: middle;
            margin-right: 4px;
        }
    </style>
</head>
<body>

    <!-- Admin Navbar -->
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center gap-3">
                <span class="navbar-brand mb-0 h1 fw-bold">⚡ Balangoda Utility Admin</span>
                <a href="../index.php" target="_blank" class="btn btn-outline-info btn-sm">View Public Site ↗</a>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-speedometer2 me-1"></i>Dashboard
                </a>
                <span class="text-white-50 small">
                    <?php if (!empty($admin['profile_pic']) && file_exists('../' . $admin['profile_pic'])): ?>
                        <img src="../<?= htmlspecialchars($admin['profile_pic']) ?>" alt="Avatar" class="nav-avatar-mini">
                    <?php else: ?>
                        <i class="bi bi-person-circle me-1"></i>
                    <?php endif; ?>
                    <strong class="text-white"><?= htmlspecialchars($admin['username']) ?></strong>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Admin Hero Header -->
    <div class="admin-hero">
        <div class="container px-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <?php if (!empty($admin['profile_pic']) && file_exists('../' . $admin['profile_pic'])): ?>
                        <img src="../<?= htmlspecialchars($admin['profile_pic']) ?>" alt="Admin Avatar" class="admin-avatar">
                    <?php else: ?>
                        <div class="admin-avatar"><?= mb_strtoupper(mb_substr($admin['username'] ?? 'A', 0, 1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h3 class="fw-bold mb-0 text-white"><?= htmlspecialchars(!empty($admin['full_name']) ? $admin['full_name'] : $admin['username']) ?></h3>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill" style="font-size:0.75rem;">
                                <i class="bi bi-shield-fill-check me-1"></i>Municipal Administrator
                            </span>
                        </div>
                        <p class="text-white-50 small mb-0">
                            <i class="bi bi-person-badge me-1"></i>@<?= htmlspecialchars($admin['username']) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($admin['email'] ?? 'No email set') ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-calendar3 me-1"></i>Created <?= date('M d, Y', strtotime($admin['created_at'])) ?>
                        </p>
                    </div>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm px-3">
                        <i class="bi bi-arrow-left me-1"></i>Back to Outages &amp; Complaints
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="container px-4 mb-5" style="max-width: 960px;">

        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <?= $flash['text'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column: Administrator Profile Info -->
            <div class="col-lg-8">
                <div class="admin-card p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <div>
                            <h5 class="fw-bold text-dark mb-1">Administrator Details</h5>
                            <small class="text-muted">Manage your administrative credentials and official contact information.</small>
                        </div>
                        <span class="badge bg-dark-subtle text-dark border px-2 py-1">Staff Record</span>
                    </div>

                    <form method="POST" action="profile.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">

                        <!-- Profile Picture Upload Area -->
                        <div class="bg-light p-3 rounded-3 border mb-4">
                            <label class="form-label fw-bold text-dark mb-2">Administrator Profile Picture</label>
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="position-relative">
                                    <?php if (!empty($admin['profile_pic']) && file_exists('../' . $admin['profile_pic'])): ?>
                                        <img id="adminAvatarPreview" src="../<?= htmlspecialchars($admin['profile_pic']) ?>" alt="Avatar" class="rounded-circle border border-2 border-primary shadow-sm" style="width:68px; height:68px; object-fit:cover;">
                                    <?php else: ?>
                                        <div id="adminAvatarFallback" class="admin-avatar shadow-sm" style="width:68px; height:68px; font-size:1.6rem;">
                                            <?= mb_strtoupper(mb_substr($admin['username'] ?? 'A', 0, 1)) ?>
                                        </div>
                                        <img id="adminAvatarPreview" src="" alt="Avatar" class="rounded-circle border border-2 border-primary shadow-sm d-none" style="width:68px; height:68px; object-fit:cover;">
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="avatar" id="adminAvatarInput" class="form-control form-control-sm mb-1" accept="image/png, image/jpeg, image/webp, image/gif">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <small class="text-muted" style="font-size:0.75rem;">Supported formats: JPG, PNG, WEBP, GIF (Max 3MB).</small>
                                        <?php if (!empty($admin['profile_pic'])): ?>
                                            <div class="form-check form-check-inline m-0">
                                                <input class="form-check-input" type="checkbox" name="remove_avatar" value="1" id="removeAdminAvatar">
                                                <label class="form-check-label text-danger small fw-semibold" for="removeAdminAvatar">
                                                    <i class="bi bi-trash3 me-1"></i>Remove photo
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Username and Full Name -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark small">Staff Full Name</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-person text-secondary"></i></span>
                                    <input type="text" name="full_name" class="form-control" placeholder="e.g. Kavindu Nimsara" value="<?= htmlspecialchars($admin['full_name'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark small">Username <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-at text-secondary"></i></span>
                                    <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($admin['username']) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Email and Phone -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark small">Official Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-envelope text-secondary"></i></span>
                                    <input type="email" name="email" class="form-control" placeholder="admin@balangoda.gov.lk" value="<?= htmlspecialchars($admin['email'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark small">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-telephone text-secondary"></i></span>
                                    <input type="tel" name="phone" class="form-control" placeholder="045-XXXXXXX or mobile" value="<?= htmlspecialchars($admin['phone'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Municipal Office / Physical Address -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold text-dark small">Municipal Office / Department Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-building text-secondary"></i></span>
                                <textarea name="address" rows="2" class="form-control" placeholder="e.g. Balangoda Urban Council, Utility Operations Division, Balangoda"><?= htmlspecialchars($admin['address'] ?? '') ?></textarea>
                            </div>
                            <small class="text-muted" style="font-size:0.75rem;">Designated municipal office or department for official communications.</small>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="dashboard.php" class="btn btn-outline-secondary px-3">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i>Save Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column: Password & Security -->
            <div class="col-lg-4">
                <div class="admin-card p-4 mb-4">
                    <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                        <i class="bi bi-key-fill text-warning fs-5"></i>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Change Password</h6>
                            <small class="text-muted">Administrator security</small>
                        </div>
                    </div>

                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark small">Current Password</label>
                            <input type="password" name="current_password" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark small">New Password</label>
                            <input type="password" name="new_password" class="form-control form-control-sm" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark small">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control form-control-sm" required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-outline-dark btn-sm w-100 fw-bold">
                            <i class="bi bi-shield-lock me-1"></i>Update Password
                        </button>
                    </form>
                </div>

                <div class="admin-card p-4">
                    <h6 class="fw-bold mb-2 text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-shield-shaded text-primary"></i> Administrative Privileges
                    </h6>
                    <p class="text-secondary small mb-3">
                        As a verified municipal administrator, your account is authorized to broadcast outage bulletins and manage resident complaints across Balangoda.
                    </p>
                    <div class="small text-muted border-top pt-2">
                        <div><strong>Role:</strong> Superadmin / Utility Dispatcher</div>
                        <div><strong>Database ID:</strong> #<?= $admin['admin_id'] ?></div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Live avatar preview in admin profile
    document.getElementById('adminAvatarInput')?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const preview = document.getElementById('adminAvatarPreview');
            const fallback = document.getElementById('adminAvatarFallback');
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
