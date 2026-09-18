<?php
session_start();
// Already logged-in customers go straight to their dashboard
if (isset($_SESSION['customer_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once '../config/db_connect.php';

$errors   = [];
$success  = '';
$verify_url = '';
$form = ['full_name'=>'','phone'=>'','email'=>'','address'=>'','elec_bill'=>'','water_bill'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // Collect
    $form['full_name']  = trim($_POST['full_name']  ?? '');
    $form['phone']      = trim($_POST['phone']       ?? '');
    $form['email']      = trim($_POST['email']       ?? '');
    $form['address']    = trim($_POST['address']     ?? '');
    $form['elec_bill']  = trim($_POST['elec_bill']   ?? '');
    $form['water_bill'] = trim($_POST['water_bill']  ?? '');
    $password           = $_POST['password']          ?? '';
    $password_confirm   = $_POST['password_confirm'] ?? '';

    // Validate
    if (empty($form['full_name'])) $errors[] = "Full name is required.";
    if (empty($form['phone'])) {
        $errors[] = "Phone number is required.";
    } elseif (!preg_match('/^(\+94|0)[0-9]{9}$/', $form['phone'])) {
        $errors[] = "Invalid Sri Lankan phone number. Use +94XXXXXXXXX or 07XXXXXXXX format.";
    }
    if (empty($form['email'])) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address format.";
    }
    if (empty($form['address'])) $errors[] = "Physical address is required.";
    if (empty($form['elec_bill']) && empty($form['water_bill'])) {
        $errors[] = "Please provide at least one utility bill number (Electricity or Water).";
    }
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    } elseif ($password !== $password_confirm) {
        $errors[] = "Passwords do not match.";
    }

    // Check email uniqueness
    if (empty($errors)) {
        $chk = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
        $chk->bind_param("s", $form['email']);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) $errors[] = "An account with this email already exists. Please sign in.";
        $chk->close();
    }

    // Insert
    if (empty($errors)) {
        $hash  = password_hash($password, PASSWORD_BCRYPT);
        $token = bin2hex(random_bytes(32));
        $ins   = $conn->prepare("INSERT INTO customers (full_name, phone, email, address, electricity_bill_no, water_bill_no, password_hash, verify_token) VALUES (?,?,?,?,?,?,?,?)");
        $ins->bind_param("ssssssss",
            $form['full_name'], $form['phone'], $form['email'], $form['address'],
            $form['elec_bill'], $form['water_bill'], $hash, $token
        );
        if ($ins->execute()) {
            $ins->close();

            // Build verification URL safely considering subdirectory
            $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir        = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $verify_url = $scheme . '://' . $host . $dir . '/verify.php?token=' . urlencode($token);

            // Attempt real mail (works on production with SMTP)
            $to      = $form['email'];
            $subject = 'Verify your Balangoda Utility account';
            $body    = "Hello " . $form['full_name'] . ",\n\n"
                     . "Please verify your email by clicking the link below:\n\n"
                     . $verify_url . "\n\n"
                     . "This link expires after use.\n\n"
                     . "— Balangoda Municipal Utility System";
            $headers = "From: noreply@balangoda-utility.lk\r\nX-Mailer: PHP/" . phpversion();
            @mail($to, $subject, $body, $headers); // Silenced — may fail on localhost

            $success = "Account created! Please verify your email to activate it.";
            $form    = ['full_name'=>'','phone'=>'','email'=>'','address'=>'','elec_bill'=>'','water_bill'=>''];
        } else {
            $errors[] = "Registration failed: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — Balangoda Utility Portal</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --navy:   #0f172a;
            --navy2:  #1e293b;
            --blue:   #3b82f6;
            --blue2:  #0d6efd;
            --cyan:   #38bdf8;
            --accent: #6366f1;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Outfit', system-ui, sans-serif;
            background: linear-gradient(135deg, var(--navy) 0%, #1a1040 50%, var(--navy2) 100%);
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        .page-card {
            background: rgba(30,41,59,0.85);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            max-width: 680px;
            margin: 0 auto;
            padding: 2.5rem 2rem;
            box-shadow: 0 30px 60px rgba(0,0,0,0.5);
        }
        .brand-header { text-align: center; margin-bottom: 2rem; }
        .brand-icon {
            font-size: 2.8rem;
            display: inline-block;
            filter: drop-shadow(0 0 16px rgba(56,189,248,0.7));
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-6px); }
        }
        .brand-header h1 { color: #f8fafc; font-size: 1.55rem; font-weight: 800; margin: 0.5rem 0 0; }
        .brand-header p  { color: #94a3b8; font-size: 0.88rem; margin: 0; }

        .form-label { color: #cbd5e1; font-weight: 600; font-size: 0.85rem; margin-bottom: 4px; }
        .form-control, .form-select {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            color: #f1f5f9;
            font-family: 'Outfit', sans-serif;
            transition: border-color .2s, box-shadow .2s;
            padding: 0.55rem 0.9rem;
        }
        .form-control:focus, .form-select:focus {
            background: rgba(255,255,255,0.1);
            border-color: var(--cyan);
            box-shadow: 0 0 0 3px rgba(56,189,248,0.18);
            color: #fff;
            outline: none;
        }
        .form-control::placeholder { color: #475569; }
        .form-control.is-invalid { border-color: #ef4444 !important; }

        .section-label {
            color: var(--cyan);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border-bottom: 1px solid rgba(56,189,248,0.2);
            padding-bottom: 6px;
            margin: 1.5rem 0 1rem;
        }

        .bill-note { color: #64748b; font-size: 0.78rem; margin-top: 4px; }
        .bill-required { color: #f59e0b; font-weight: 600; }

        .btn-register {
            background: linear-gradient(135deg, var(--blue2), var(--accent));
            border: none;
            border-radius: 12px;
            color: #fff;
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            padding: 0.75rem;
            width: 100%;
            transition: all .2s;
            box-shadow: 0 6px 18px rgba(99,102,241,.35);
        }
        .btn-register:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(99,102,241,.45); color:#fff; }

        .alert-glass {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.35);
            border-radius: 12px;
            color: #fca5a5;
            padding: 1rem 1.2rem;
        }
        .alert-success-glass {
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.35);
            border-radius: 12px;
            color: #86efac;
            padding: 1rem 1.2rem;
        }
        .verify-link-box {
            background: rgba(59,130,246,0.12);
            border: 1px solid rgba(59,130,246,0.35);
            border-radius: 12px;
            padding: 1rem 1.2rem;
            margin-top: 1rem;
            word-break: break-all;
        }
        .verify-link-box a { color: var(--cyan); font-weight: 600; }
        .divider { border-color: rgba(255,255,255,0.08); margin: 1.5rem 0; }
        .signin-link { color: #94a3b8; font-size: 0.88rem; text-align: center; margin-top: 1.2rem; }
        .signin-link a { color: var(--cyan); font-weight: 600; text-decoration: none; }
        .pw-toggle { cursor: pointer; }

        /* Password strength bar */
        .pw-strength-bar { height: 4px; border-radius: 2px; transition: width .3s, background .3s; width: 0; }
    </style>
</head>
<body>
<div class="page-card">
    <div class="brand-header">
        <span class="brand-icon">⚡</span>
        <h1>Create Your Account</h1>
        <p>Balangoda Municipal Utility Warning & Complaint Portal</p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert-glass mb-3">
        <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-2 ps-3">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert-success-glass mb-3">
        <strong><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?></strong>
    </div>
    <?php if ($verify_url): ?>
    <div class="verify-link-box">
        <p class="mb-2" style="color:#94a3b8;font-size:.85rem;">
            <i class="bi bi-info-circle me-1"></i>
            <strong style="color:#f59e0b;">Running on localhost?</strong> Click the link below to verify your email instantly:
        </p>
        <a href="<?= htmlspecialchars($verify_url) ?>" id="verifyLink">
            <i class="bi bi-shield-check me-1"></i><?= htmlspecialchars($verify_url) ?>
        </a>
        <div class="mt-2" style="color:#64748b;font-size:.78rem;">On a production server, this link will be emailed to you automatically.</div>
    </div>
    <?php endif; ?>
    <?php else: ?>

    <form method="POST" action="register.php" novalidate id="registerForm">

        <!-- Personal Info -->
        <div class="section-label"><i class="bi bi-person me-1"></i> Personal Information</div>

        <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" class="form-control" placeholder="e.g. Kavindu Perera"
                   value="<?= htmlspecialchars($form['full_name']) ?>" required autocomplete="name">
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-right:none;color:#94a3b8;">
                        <i class="bi bi-telephone"></i>
                    </span>
                    <input type="tel" name="phone" id="phoneInput" class="form-control" style="border-left:none;"
                           placeholder="+94771234567 or 0771234567"
                           value="<?= htmlspecialchars($form['phone']) ?>" required>
                </div>
                <div class="bill-note">Format: +94XXXXXXXXX or 07XXXXXXXX</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" placeholder="you@example.com"
                       value="<?= htmlspecialchars($form['email']) ?>" required autocomplete="email">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Physical Address <span class="text-danger">*</span></label>
            <textarea name="address" class="form-control" rows="2"
                      placeholder="House No., Street, Balangoda..."><?= htmlspecialchars($form['address']) ?></textarea>
        </div>

        <!-- Utility Bills -->
        <div class="section-label"><i class="bi bi-receipt me-1"></i> Utility Bill Numbers <span class="bill-required">*</span></div>
        <p class="bill-note mb-3"><i class="bi bi-info-circle me-1"></i>At least <strong style="color:#f1f5f9;">one</strong> bill number is required to verify your residency.</p>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-lightning-charge me-1" style="color:#fbbf24;"></i> Electricity Bill No.</label>
                <input type="text" name="elec_bill" id="elecBill" class="form-control" placeholder="e.g. EB-0012345"
                       value="<?= htmlspecialchars($form['elec_bill']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-droplet me-1" style="color:#38bdf8;"></i> Water Bill No.</label>
                <input type="text" name="water_bill" id="waterBill" class="form-control" placeholder="e.g. WB-0067890"
                       value="<?= htmlspecialchars($form['water_bill']) ?>">
            </div>
        </div>
        <div id="billError" class="bill-note" style="color:#ef4444;display:none;">
            <i class="bi bi-exclamation-triangle me-1"></i>Please enter at least one bill number.
        </div>

        <!-- Password -->
        <div class="section-label"><i class="bi bi-lock me-1"></i> Set Password</div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="password" name="password" id="pwField" class="form-control"
                           placeholder="Min. 8 characters" required style="border-right:none;">
                    <button type="button" class="input-group-text pw-toggle" id="togglePw"
                            style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-left:none;color:#94a3b8;">
                        <i class="bi bi-eye" id="togglePwIcon"></i>
                    </button>
                </div>
                <div class="mt-1" style="background:rgba(255,255,255,0.06);border-radius:4px;overflow:hidden;">
                    <div class="pw-strength-bar" id="pwBar"></div>
                </div>
                <div class="bill-note" id="pwStrengthLabel"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                <input type="password" name="password_confirm" id="pwConfirmField" class="form-control"
                       placeholder="Repeat password" required>
                <div class="bill-note" id="pwMatchNote" style="display:none;"></div>
            </div>
        </div>

        <button type="submit" name="register" class="btn-register mt-2">
            <i class="bi bi-person-plus me-2"></i>Create Account
        </button>
    </form>
    <?php endif; ?>

    <div class="signin-link">
        Already have an account? <a href="login.php">Sign in here →</a>
    </div>
    <div class="signin-link mt-1">
        <a href="../index.php" style="color:#475569;">← Back to Public Portal</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Phone live format hint
const phoneEl = document.getElementById('phoneInput');
phoneEl?.addEventListener('input', function() {
    const v = this.value.replace(/\s/g,'');
    const ok = /^(\+94|0)[0-9]{9}$/.test(v);
    this.classList.toggle('is-invalid', this.value.length > 3 && !ok);
    this.classList.toggle('is-valid',   this.value.length > 3 &&  ok);
});

// Bill: at least one required
const elecBill  = document.getElementById('elecBill');
const waterBill = document.getElementById('waterBill');
const billErr   = document.getElementById('billError');
function checkBills() {
    const missing = !elecBill?.value.trim() && !waterBill?.value.trim();
    if (billErr) billErr.style.display = missing ? '' : 'none';
    return !missing;
}
elecBill?.addEventListener('input', checkBills);
waterBill?.addEventListener('input', checkBills);

// Password strength
const pwField = document.getElementById('pwField');
const pwBar   = document.getElementById('pwBar');
const pwLabel = document.getElementById('pwStrengthLabel');
const levels  = [
    { re: /.{8}/, label: 'Weak', color: '#ef4444', w: '30%' },
    { re: /(?=.*[a-z])(?=.*[A-Z]).{8}/, label: 'Fair', color: '#f59e0b', w: '55%' },
    { re: /(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8}/, label: 'Good', color: '#3b82f6', w: '78%' },
    { re: /(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[\W]).{10}/, label: 'Strong', color: '#22c55e', w: '100%' },
];
pwField?.addEventListener('input', function() {
    const v = this.value;
    let lvl = -1;
    levels.forEach((l, i) => { if (l.re.test(v)) lvl = i; });
    if (!v) { pwBar.style.width='0'; pwLabel.textContent=''; return; }
    const cur = levels[lvl] ?? { label:'Too short', color:'#ef4444', w:'12%' };
    pwBar.style.width = cur.w;
    pwBar.style.background = cur.color;
    pwLabel.textContent = 'Strength: ' + cur.label;
    pwLabel.style.color = cur.color;
});

// Password match feedback
const pwConfirm = document.getElementById('pwConfirmField');
const matchNote = document.getElementById('pwMatchNote');
pwConfirm?.addEventListener('input', function() {
    const match = this.value === pwField?.value;
    matchNote.style.display = this.value ? '' : 'none';
    matchNote.textContent = match ? '✓ Passwords match' : '✗ Passwords do not match';
    matchNote.style.color = match ? '#22c55e' : '#ef4444';
    this.classList.toggle('is-invalid', !match && this.value.length > 0);
    this.classList.toggle('is-valid',    match && this.value.length > 0);
});

// Toggle password visibility
document.getElementById('togglePw')?.addEventListener('click', function() {
    const type = pwField.type === 'password' ? 'text' : 'password';
    pwField.type = type;
    document.getElementById('togglePwIcon').className = type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
});

// Form submit guard
document.getElementById('registerForm')?.addEventListener('submit', function(e) {
    if (!checkBills()) {
        e.preventDefault();
        elecBill?.focus();
    }
    const ph = phoneEl?.value.replace(/\s/g,'');
    if (ph && !/^(\+94|0)[0-9]{9}$/.test(ph)) {
        e.preventDefault();
        phoneEl?.focus();
    }
});
</script>
</body>
</html>
