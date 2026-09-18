<?php
session_start();
if (!isset($_SESSION['customer_id'])) {
    header("Location: ../login.php?role=customer&msg=login_required");
    exit();
}

require_once '../config/db_connect.php';
require_once '../config/profile_helper.php';
ensure_profile_schema($conn);

$customer_id = (int)$_SESSION['customer_id'];
$flash = null;

// Fetch current customer data
$crec = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
$crec->bind_param("i", $customer_id);
$crec->execute();
$cdata = $crec->get_result()->fetch_assoc();
$crec->close();

if (!$cdata) {
    session_destroy();
    header("Location: ../login.php?role=customer");
    exit();
}

// -------------------------------------------------------------------------
// Handle Profile Info & Avatar Update
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $elec_no   = trim($_POST['electricity_bill_no'] ?? '');
    $water_no  = trim($_POST['water_bill_no'] ?? '');

    $err = '';
    if (empty($full_name)) {
        $err = "Full name is required.";
    } elseif (empty($phone)) {
        $err = "Phone number is required.";
    } elseif (empty($address)) {
        $err = "Physical residential address is required.";
    }

    $avatar_path = $cdata['profile_pic'];

    // Check if remove avatar requested
    if (isset($_POST['remove_avatar']) && $_POST['remove_avatar'] == '1') {
        if (!empty($avatar_path)) {
            delete_avatar_file($avatar_path);
            $avatar_path = null;
        }
    }

    // Check if new avatar uploaded
    if (empty($err) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_res = handle_avatar_upload($_FILES['avatar'], 'cust', $customer_id, $avatar_path);
        if ($upload_res['success']) {
            $avatar_path = $upload_res['path'];
        } else {
            $err = $upload_res['error'];
        }
    }

    if (empty($err)) {
        $stmt = $conn->prepare("UPDATE customers SET full_name = ?, phone = ?, address = ?, electricity_bill_no = ?, water_bill_no = ?, profile_pic = ? WHERE customer_id = ?");
        $stmt->bind_param("ssssssi", $full_name, $phone, $address, $elec_no, $water_no, $avatar_path, $customer_id);
        if ($stmt->execute()) {
            $_SESSION['customer_name']   = $full_name;
            $_SESSION['customer_phone']  = $phone;
            $_SESSION['customer_avatar'] = $avatar_path;
            $flash = ['type' => 'success', 'text' => 'Your profile details have been saved successfully!'];
            
            // Refresh customer data
            $crec = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
            $crec->bind_param("i", $customer_id);
            $crec->execute();
            $cdata = $crec->get_result()->fetch_assoc();
            $crec->close();
        } else {
            $flash = ['type' => 'danger', 'text' => 'Database error: ' . $stmt->error];
        }
        $stmt->close();
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
    } elseif (!password_verify($cur_pass, $cdata['password_hash'])) {
        $err = "Your current password does not match our records.";
    } elseif (strlen($new_pass) < 6) {
        $err = "New password must be at least 6 characters in length.";
    } elseif ($new_pass !== $conf_pass) {
        $err = "New password and confirmation do not match.";
    }

    if (empty($err)) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $pw_stmt = $conn->prepare("UPDATE customers SET password_hash = ? WHERE customer_id = ?");
        $pw_stmt->bind_param("si", $new_hash, $customer_id);
        if ($pw_stmt->execute()) {
            $flash = ['type' => 'success', 'text' => 'Password updated successfully! Your account is secure.'];
            // Refresh
            $cdata['password_hash'] = $new_hash;
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
    <title>My Profile &amp; Residence Settings — Balangoda Utility Portal</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --navy:#0f172a; --navy2:#1e293b; --blue:#3b82f6; --cyan:#38bdf8; --accent:#6366f1; }
        body { font-family:'Outfit',system-ui,sans-serif; background:#f1f5f9; color:#1e293b; }

        /* Navbar */
        .cust-nav {
            background:linear-gradient(135deg, var(--navy) 0%, #1a1040 100%);
            padding:.75rem 0; box-shadow:0 4px 20px rgba(0,0,0,.3);
        }
        .cust-nav .brand { color:#f8fafc; font-size:1.1rem; font-weight:800; text-decoration:none; }
        .cust-nav .brand span { color:var(--cyan); }
        .cust-nav .nav-btn {
            background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
            border-radius:8px; color:#f1f5f9; padding:.38rem .85rem; font-size:.83rem;
            text-decoration:none; transition:background .15s;
        }
        .cust-nav .nav-btn:hover { background:rgba(255,255,255,.16); color:#fff; }
        .cust-nav .nav-btn.danger { border-color:rgba(239,68,68,.4); color:#fca5a5; }
        .cust-nav .nav-btn.danger:hover { background:rgba(239,68,68,.15); }

        /* Hero */
        .hero-strip {
            background:linear-gradient(135deg, #1a1040 0%, var(--navy2) 100%);
            color:#f8fafc; padding:2rem 0 1.8rem;
            border-bottom:1px solid rgba(255,255,255,.06);
        }
        .avatar-box {
            width:100px; height:100px; border-radius:50%;
            object-fit:cover; border:3px solid #fff;
            box-shadow:0 8px 24px rgba(0,0,0,0.25);
            background:linear-gradient(135deg,var(--blue),var(--accent));
            display:flex; align-items:center; justify-content:center;
            font-size:2.4rem; font-weight:800; color:#fff;
            flex-shrink:0;
        }
        .card-profile {
            background:#fff; border-radius:16px;
            border:1px solid #e2e8f0;
            box-shadow:0 4px 20px rgba(0,0,0,.04);
        }
        .section-badge {
            display:inline-flex; align-items:center; gap:.4rem;
            padding:.3rem .75rem; border-radius:8px;
            font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="cust-nav">
    <div class="container-fluid px-4">
        <div class="d-flex align-items-center justify-content-between">
            <a href="../index.php" class="brand">⚡ <span>Balangoda</span> Utility Portal</a>
            <div class="d-flex align-items-center gap-2">
                <a href="dashboard.php" class="nav-btn"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
                <a href="../index.php" class="nav-btn"><i class="bi bi-house me-1"></i>Public Site</a>
                <a href="logout.php" class="nav-btn danger"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
            </div>
        </div>
    </div>
</nav>

<!-- Hero Strip -->
<div class="hero-strip">
    <div class="container px-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                    <img src="../<?= htmlspecialchars($cdata['profile_pic']) ?>" alt="Avatar" class="avatar-box">
                <?php else: ?>
                    <div class="avatar-box"><?= mb_strtoupper(mb_substr($cdata['full_name'] ?? 'R', 0, 1)) ?></div>
                <?php endif; ?>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h2 class="fw-bold mb-0 text-white"><?= htmlspecialchars($cdata['full_name']) ?></h2>
                        <?php if ($cdata['email_verified']): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill" style="font-size:0.75rem;">
                                <i class="bi bi-patch-check-fill me-1"></i>Verified Resident
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-white-50 small mb-0">
                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($cdata['email']) ?>
                        &nbsp;·&nbsp;
                        <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($cdata['phone']) ?>
                        &nbsp;·&nbsp;
                        <i class="bi bi-calendar3 me-1"></i>Member since <?= date('M Y', strtotime($cdata['created_at'])) ?>
                    </p>
                </div>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm px-3 rounded-3 shadow-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container px-4 py-4" style="max-width: 980px;">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show shadow-sm mb-4" style="border-radius:12px;">
        <?= $flash['text'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Main Form Column: Profile, Residence & Meters -->
        <div class="col-lg-8">
            <div class="card-profile p-4 mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">Profile &amp; Residence Information</h5>
                        <small class="text-muted">Keep your residential address and contact details current for Balangoda utility services.</small>
                    </div>
                    <span class="section-badge bg-primary-subtle text-primary">
                        <i class="bi bi-person-lines-fill"></i> Contact &amp; Residence
                    </span>
                </div>

                <form method="POST" action="profile.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">

                    <!-- Profile Picture Selector inside the card -->
                    <div class="bg-light p-3 rounded-3 border mb-4">
                        <label class="form-label fw-bold text-dark mb-2">Profile Picture / Avatar</label>
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="position-relative">
                                <?php if (!empty($cdata['profile_pic']) && file_exists('../' . $cdata['profile_pic'])): ?>
                                    <img id="profileAvatarPreview" src="../<?= htmlspecialchars($cdata['profile_pic']) ?>" alt="Avatar" class="rounded-circle border border-2 border-primary shadow-sm" style="width:68px; height:68px; object-fit:cover;">
                                <?php else: ?>
                                    <div id="profileAvatarFallback" class="avatar-box shadow-sm" style="width:68px; height:68px; font-size:1.8rem;">
                                        <?= mb_strtoupper(mb_substr($cdata['full_name'] ?? 'R', 0, 1)) ?>
                                    </div>
                                    <img id="profileAvatarPreview" src="" alt="Avatar" class="rounded-circle border border-2 border-primary shadow-sm d-none" style="width:68px; height:68px; object-fit:cover;">
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <input type="file" name="avatar" id="profileAvatarInput" class="form-control form-control-sm mb-1" accept="image/png, image/jpeg, image/webp, image/gif">
                                <div class="d-flex align-items-center justify-content-between">
                                    <small class="text-muted" style="font-size:0.75rem;">JPG, PNG, WEBP or GIF (Max 3MB).</small>
                                    <?php if (!empty($cdata['profile_pic'])): ?>
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input" type="checkbox" name="remove_avatar" value="1" id="removeAvatarCheck">
                                            <label class="form-check-label text-danger small fw-semibold" for="removeAvatarCheck">
                                                <i class="bi bi-trash3 me-1"></i>Remove photo
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person text-secondary"></i></span>
                                <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($cdata['full_name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Phone Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-telephone text-secondary"></i></span>
                                <input type="tel" name="phone" class="form-control" required placeholder="07X XXX XXXX" value="<?= htmlspecialchars($cdata['phone'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-envelope text-secondary"></i></span>
                            <input type="email" class="form-control bg-light" value="<?= htmlspecialchars($cdata['email']) ?>" readonly disabled>
                            <span class="input-group-text bg-success-subtle text-success small"><i class="bi bi-check-circle-fill me-1"></i>Verified</span>
                        </div>
                        <small class="text-muted" style="font-size:0.75rem;">Your email address is your verified login identity and cannot be edited directly.</small>
                    </div>

                    <!-- Physical Address -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold text-dark small">Residential Address <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-geo-alt text-secondary"></i></span>
                            <textarea name="address" rows="2" class="form-control" required placeholder="Enter your home or business physical address in Balangoda"><?= htmlspecialchars($cdata['address'] ?? '') ?></textarea>
                        </div>
                        <small class="text-muted" style="font-size:0.75rem;">Used by municipal technical teams when investigating reported utility failures in your neighborhood.</small>
                    </div>

                    <!-- Utility Meter Accounts -->
                    <div class="p-3 rounded-3 border bg-light mb-4">
                        <h6 class="fw-bold text-dark mb-1" style="font-size:0.9rem;">
                            <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Utility Account &amp; Meter Numbers
                        </h6>
                        <p class="text-muted small mb-3">Associating your meter numbers allows authorities to speed up fault tracing and restoration updates.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark small">Electricity (CEB) Account No</label>
                                <input type="text" name="electricity_bill_no" class="form-control form-control-sm bg-white" placeholder="e.g. 045-8192-33" value="<?= htmlspecialchars($cdata['electricity_bill_no'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark small">Water Supply (NWSDB) Account No</label>
                                <input type="text" name="water_bill_no" class="form-control form-control-sm bg-white" placeholder="e.g. BAL/4412/08" value="<?= htmlspecialchars($cdata['water_bill_no'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="dashboard.php" class="btn btn-outline-secondary px-3">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i>Save Profile Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Side Column: Password / Security & Quick Info -->
        <div class="col-lg-4">
            <!-- Change Password Card -->
            <div class="card-profile p-4 mb-4">
                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                    <i class="bi bi-shield-lock-fill text-warning fs-5"></i>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">Change Password</h6>
                        <small class="text-muted">Keep your resident account safe</small>
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
                        <i class="bi bi-key-fill me-1"></i>Update Password
                    </button>
                </form>
            </div>

            <!-- Residence & Hotlines summary -->
            <div class="card-profile p-4">
                <h6 class="fw-bold mb-3 d-flex align-items-center gap-2 text-dark">
                    <i class="bi bi-info-circle text-primary"></i> Need Help?
                </h6>
                <p class="text-secondary small mb-3">
                    If you require urgent assistance with your billing numbers, transfer of utility accounts, or emergency services in Balangoda, contact the municipal center.
                </p>
                <div class="small text-muted">
                    <div class="mb-1"><strong>Balangoda UC:</strong> 045-2287222</div>
                    <div class="mb-1"><strong>Electricity CEB:</strong> 1987</div>
                    <div><strong>Water Board:</strong> 1939</div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Live avatar preview in profile page
document.getElementById('profileAvatarInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const preview = document.getElementById('profileAvatarPreview');
        const fallback = document.getElementById('profileAvatarFallback');
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
