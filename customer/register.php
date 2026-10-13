<?php
session_start();
// Already logged-in customers go straight to their dashboard
if (isset($_SESSION['customer_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once '../config/db_connect.php';

$errors = [];
$form = [
    'full_name'           => '',
    'phone_number'        => '',
    'email'               => '',
    'physical_address'    => '',
    'electricity_bill_no' => '',
    'water_bill_no'       => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // 1. Collect & sanitize inputs
    $form['full_name']           = trim($_POST['full_name'] ?? '');
    $form['phone_number']        = trim($_POST['phone_number'] ?? '');
    $form['email']               = trim($_POST['email'] ?? '');
    $form['physical_address']    = trim($_POST['physical_address'] ?? '');
    $form['electricity_bill_no'] = trim($_POST['electricity_bill_no'] ?? '');
    $form['water_bill_no']       = trim($_POST['water_bill_no'] ?? '');
    $password                    = $_POST['password'] ?? '';
    $password_confirm            = $_POST['password_confirm'] ?? '';

    // 2. Validate Personal Info
    if (empty($form['full_name'])) {
        $errors[] = "Full Name is required.";
    }

    if (empty($form['phone_number'])) {
        $errors[] = "Phone Number is required.";
    } elseif (!preg_match('/^(\+94|0)?[0-9]{9,10}$/', str_replace([' ', '-'], '', $form['phone_number']))) {
        $errors[] = "Please provide a valid phone number (e.g., 0712345678 or +94712345678).";
    }

    if (empty($form['email'])) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address format.";
    }

    if (empty($form['physical_address'])) {
        $errors[] = "Physical address is required.";
    }

    // 3. Validate Utility Bill Numbers (Enforce at least one)
    if (empty($form['electricity_bill_no']) && empty($form['water_bill_no'])) {
        $errors[] = "Please enter at least one utility bill number (Electricity or Water) to verify your residency.";
    }

    // 4. Validate Passwords
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    } elseif ($password !== $password_confirm) {
        $errors[] = "Passwords do not match.";
    }

    // 5. Check if email already registered in residents or customers
    if (empty($errors)) {
        try {
            $chk_email = $pdo->prepare("SELECT id FROM residents WHERE email = :email LIMIT 1");
            $chk_email->execute([':email' => $form['email']]);
            if ($chk_email->fetch()) {
                $errors[] = "An account with this email address already exists. Please sign in.";
            } else {
                // Secondary check in customers table
                $chk_cust = $pdo->prepare("SELECT customer_id FROM customers WHERE email = :email LIMIT 1");
                $chk_cust->execute([':email' => $form['email']]);
                if ($chk_cust->fetch()) {
                    $errors[] = "An account with this email address already exists. Please sign in.";
                }
            }
        } catch (Throwable $e) {
            $errors[] = "Verification check error: " . $e->getMessage();
        }
    }

    // 6. Council Registry Verification Logic (Enforce Before Insertion)
    $matched_registry = null;
    if (empty($errors)) {
        try {
            $registry_query = "
                SELECT * FROM council_registry 
                WHERE (electricity_account_no = :ceb AND :ceb_chk != '')
                   OR (water_account_no = :water AND :water_chk != '')
                LIMIT 1
            ";
            $reg_stmt = $pdo->prepare($registry_query);
            $reg_stmt->execute([
                ':ceb'       => $form['electricity_bill_no'],
                ':ceb_chk'   => $form['electricity_bill_no'],
                ':water'     => $form['water_bill_no'],
                ':water_chk' => $form['water_bill_no']
            ]);
            $matched_registry = $reg_stmt->fetch();

            if (!$matched_registry) {
                // If no record matches
                $errors[] = "Verification failed: The entered Electricity or Water account number was not found in the Balangoda Municipal Registry. Please check your bill.";
            } elseif ($matched_registry['registered_status'] === 'claimed') {
                // If a record is matched but registered_status === 'claimed'
                $errors[] = "This utility account is already linked to an existing resident portal account.";
            }
        } catch (Throwable $e) {
            $errors[] = "Registry database error: " . $e->getMessage();
        }
    }

    // 7. On Success: Database Transaction Insertion & Claiming
    if (empty($errors) && $matched_registry) {
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $registry_id   = (int)$matched_registry['id'];

        $pdo->beginTransaction();
        try {
            // A. Insert resident into `residents` table with verification_status = 'verified'
            $ins_resident = $pdo->prepare("
                INSERT INTO residents (
                    registry_id,
                    full_name,
                    email,
                    phone_number,
                    physical_address,
                    electricity_account_no,
                    water_account_no,
                    password_hash,
                    verification_status
                ) VALUES (
                    :registry_id,
                    :full_name,
                    :email,
                    :phone_number,
                    :physical_address,
                    :electricity_account_no,
                    :water_account_no,
                    :password_hash,
                    'verified'
                )
            ");
            $ins_resident->execute([
                ':registry_id'            => $registry_id,
                ':full_name'              => $form['full_name'],
                ':email'                  => $form['email'],
                ':phone_number'           => $form['phone_number'],
                ':physical_address'       => $form['physical_address'],
                ':electricity_account_no' => $form['electricity_bill_no'] ?: null,
                ':water_account_no'       => $form['water_bill_no'] ?: null,
                ':password_hash'          => $password_hash
            ]);

            // B. Also synchronize into `customers` table for backward-compatible dashboard authentication
            $ins_customer = $pdo->prepare("
                INSERT INTO customers (
                    full_name,
                    phone,
                    email,
                    address,
                    electricity_bill_no,
                    water_bill_no,
                    password_hash,
                    email_verified
                ) VALUES (
                    :full_name,
                    :phone,
                    :email,
                    :address,
                    :elec,
                    :water,
                    :password_hash,
                    1
                )
                ON DUPLICATE KEY UPDATE 
                    password_hash = VALUES(password_hash),
                    email_verified = 1
            ");
            $ins_customer->execute([
                ':full_name'     => $form['full_name'],
                ':phone'         => $form['phone_number'],
                ':email'         => $form['email'],
                ':address'       => $form['physical_address'],
                ':elec'          => $form['electricity_bill_no'] ?: null,
                ':water'         => $form['water_bill_no'] ?: null,
                ':password_hash' => $password_hash
            ]);

            // C. Mark matched council_registry row as `registered_status = 'claimed'`
            $claim_stmt = $pdo->prepare("
                UPDATE council_registry 
                SET registered_status = 'claimed' 
                WHERE id = :id
            ");
            $claim_stmt->execute([':id' => $registry_id]);

            // Commit atomic transaction
            $pdo->commit();

            // Redirect to customer/login_page.php with success flash message
            $_SESSION['flash_success'] = "Account successfully verified and registered! Please sign in.";
            header("Location: login_page.php?msg=registered");
            exit();

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = "Registration failed during database write: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Registration & Verification — Balangoda Municipal Council</title>
    <meta name="description" content="Register and verify your residency using official CEB Electricity or NWSDB Water bill credentials.">
    <meta name="theme-color" content="#2563eb">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Inter', 'system-ui', 'sans-serif'],
                    display: ['Outfit', 'sans-serif'],
                },
                colors: {
                    brand: {
                        50:  '#eff6ff',
                        100: '#dbeafe',
                        500: '#3b82f6',
                        600: '#2563eb',
                        700: '#1d4ed8',
                        900: '#1e3a8a'
                    }
                }
            }
        }
    }
    </script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body class="min-h-full flex flex-col font-sans text-slate-800 bg-slate-50 antialiased selection:bg-blue-600 selection:text-white">

    <!-- Ambient Subtle Backdrop Accents -->
    <div class="fixed inset-0 pointer-events-none overflow-hidden -z-10">
        <div class="absolute -top-32 left-1/2 -translate-x-1/2 w-[700px] h-[350px] bg-blue-500/10 rounded-full blur-[120px]"></div>
        <div class="absolute -bottom-32 left-1/2 -translate-x-1/2 w-[600px] h-[300px] bg-cyan-400/10 rounded-full blur-[100px]"></div>
    </div>

    <!-- Header Navigation -->
    <header class="w-full bg-[#091322] border-b border-cyan-500/25 px-4 sm:px-8 py-3.5 flex items-center justify-between shadow-lg shadow-blue-950/20">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-600 to-cyan-400 flex items-center justify-center text-white shadow-sm ring-1 ring-cyan-400/30">
                <i data-lucide="zap" class="w-4 h-4 text-white"></i>
            </div>
            <div>
                <span class="text-sm font-bold text-white tracking-wide">
                    Balangoda <span class="text-cyan-400">Urban Council</span>
                </span>
                <span class="hidden sm:inline text-xs text-slate-400 ml-2">&bull; Municipal Utility System</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="login_page.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold text-cyan-300 hover:text-white bg-slate-800/80 hover:bg-slate-700 border border-cyan-500/30 transition-all">
                <i data-lucide="log-in" class="w-3.5 h-3.5 text-cyan-400"></i>
                <span>Sign In</span>
            </a>
        </div>
    </header>

    <!-- Main Registration Container -->
    <main class="flex-1 flex items-center justify-center px-4 py-8 sm:py-12">
        <div class="w-full max-w-xl">

            <!-- Card Container -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-10 shadow-xl shadow-blue-950/5 relative">

                <!-- Header Block -->
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-blue-50 border border-blue-200 text-blue-600 mb-3 shadow-inner">
                        <i data-lucide="shield-check" class="w-7 h-7"></i>
                    </div>
                    <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200 uppercase tracking-wider mb-2">
                        Official Verification
                    </div>
                    <h1 class="font-display text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Resident Portal Registration</h1>
                    <p class="text-slate-500 text-xs sm:text-sm mt-1.5">
                        Verify your residency using your municipal electricity or water account number to activate access.
                    </p>
                </div>

                <!-- Dismissible Error Alert Box -->
                <?php if (!empty($errors)): ?>
                <div id="errorAlert" class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-sm shadow-sm relative animate-fade-in">
                    <div class="flex items-start gap-3">
                        <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 text-rose-600 mt-0.5"></i>
                        <div class="flex-1">
                            <strong class="font-semibold block mb-1">Please correct the following:</strong>
                            <ul class="list-disc list-inside space-y-1 text-xs sm:text-sm">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" onclick="document.getElementById('errorAlert').remove();" class="text-rose-400 hover:text-rose-600 p-1 rounded-lg transition-colors" aria-label="Dismiss alert">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Registration Form -->
                <form method="POST" action="register.php" id="registerForm" novalidate class="space-y-6">

                    <!-- SECTION 1: Personal Information -->
                    <div>
                        <div class="flex items-center gap-2 pb-2 mb-4 border-b border-slate-200 text-xs font-bold text-blue-700 uppercase tracking-wider">
                            <i data-lucide="user" class="w-4 h-4"></i>
                            <span>Personal Information</span>
                        </div>

                        <div class="space-y-4">
                            <!-- Full Name -->
                            <div>
                                <label for="full_name" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    Full Name <span class="text-rose-500">*</span>
                                </label>
                                <div class="relative">
                                    <i data-lucide="user-check" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                    <input type="text" id="full_name" name="full_name"
                                           class="w-full pl-10 pr-4 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                           placeholder="e.g., Anjali Karunaratne"
                                           value="<?= htmlspecialchars($form['full_name']) ?>" required>
                                </div>
                            </div>

                            <!-- Phone Number & Email Address Grid -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="phone_number" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                        Phone Number <span class="text-rose-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <i data-lucide="phone" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                        <input type="tel" id="phone_number" name="phone_number"
                                               class="w-full pl-10 pr-4 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                               placeholder="0771234567"
                                               value="<?= htmlspecialchars($form['phone_number']) ?>" required>
                                    </div>
                                    <p class="text-[11px] text-slate-400 mt-1">For urgent SMS outage notifications</p>
                                </div>

                                <div>
                                    <label for="email" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                        Email Address <span class="text-rose-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <i data-lucide="mail" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                        <input type="email" id="email" name="email"
                                               class="w-full pl-10 pr-4 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                               placeholder="resident@example.com"
                                               value="<?= htmlspecialchars($form['email']) ?>" required autocomplete="email">
                                    </div>
                                    <p class="text-[11px] text-slate-400 mt-1">Used for portal sign in & receipts</p>
                                </div>
                            </div>

                            <!-- Physical Address -->
                            <div>
                                <label for="physical_address" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    Physical Address <span class="text-rose-500">*</span>
                                </label>
                                <div class="relative">
                                    <i data-lucide="map-pin" class="absolute left-3.5 top-3 w-4 h-4 text-slate-400"></i>
                                    <textarea id="physical_address" name="physical_address" rows="2"
                                              class="w-full pl-10 pr-4 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                              placeholder="House No., Street, Grama Niladhari division, Balangoda..."
                                              required><?= htmlspecialchars($form['physical_address']) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 2: Utility Bill Numbers -->
                    <div>
                        <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-200">
                            <div class="flex items-center gap-2 text-xs font-bold text-blue-700 uppercase tracking-wider">
                                <i data-lucide="receipt" class="w-4 h-4"></i>
                                <span>Utility Bill Numbers</span>
                            </div>
                            <span class="text-[11px] font-semibold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200">
                                At least 1 required
                            </span>
                        </div>

                        <p class="text-xs text-slate-500 mb-4">
                            We cross-reference your electricity or water bill account number against the Balangoda Municipal Council Registry to automatically verify your identity.
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Electricity Bill -->
                            <div>
                                <label for="electricity_bill_no" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                                    <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-500"></i>
                                    <span>Electricity Bill No.</span>
                                </label>
                                <div class="relative">
                                    <input type="text" id="electricity_bill_no" name="electricity_bill_no"
                                           class="w-full px-3.5 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm font-mono text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                           placeholder="e.g., 5101104729"
                                           value="<?= htmlspecialchars($form['electricity_bill_no']) ?>">
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1">Ceylon Electricity Board (CEB) No.</p>
                            </div>

                            <!-- Water Bill -->
                            <div>
                                <label for="water_bill_no" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                                    <i data-lucide="droplet" class="w-3.5 h-3.5 text-blue-500"></i>
                                    <span>Water Bill No.</span>
                                </label>
                                <div class="relative">
                                    <input type="text" id="water_bill_no" name="water_bill_no"
                                           class="w-full px-3.5 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm font-mono text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                           placeholder="e.g., 3045100041 or 12/45/..."
                                           value="<?= htmlspecialchars($form['water_bill_no']) ?>">
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1">National Water Board (NWSDB) No.</p>
                            </div>
                        </div>

                        <div id="billClientError" class="hidden mt-2 p-2.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-center gap-2">
                            <i data-lucide="alert-triangle" class="w-3.5 h-3.5 flex-shrink-0"></i>
                            <span>Please provide at least one utility bill number to proceed.</span>
                        </div>
                    </div>

                    <!-- SECTION 3: Set Password -->
                    <div>
                        <div class="flex items-center gap-2 pb-2 mb-4 border-b border-slate-200 text-xs font-bold text-blue-700 uppercase tracking-wider">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                            <span>Set Password</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Password -->
                            <div>
                                <label for="password" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    Password <span class="text-rose-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="password" name="password"
                                           class="w-full pl-3.5 pr-10 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                           placeholder="Min. 8 characters" required autocomplete="new-password">
                                    <button type="button" id="togglePasswordBtn" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1" aria-label="Toggle password visibility">
                                        <i data-lucide="eye" class="w-4 h-4" id="toggleIcon"></i>
                                    </button>
                                </div>
                                <!-- Strength meter -->
                                <div class="mt-2 h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                                    <div id="strengthBar" class="h-full w-0 transition-all duration-300 bg-rose-500 rounded-full"></div>
                                </div>
                                <div id="strengthLabel" class="text-[11px] text-slate-400 mt-1 font-medium">Minimum 8 characters</div>
                            </div>

                            <!-- Confirm Password -->
                            <div>
                                <label for="password_confirm" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    Confirm Password <span class="text-rose-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="password_confirm" name="password_confirm"
                                           class="w-full px-3.5 py-2.5 border border-slate-300 focus:border-blue-600 focus:ring-4 focus:ring-blue-600/15 rounded-xl text-sm text-slate-900 placeholder:text-slate-400 outline-none transition-all"
                                           placeholder="Repeat password" required autocomplete="new-password">
                                </div>
                                <div id="matchNotice" class="text-[11px] text-slate-400 mt-1 font-medium"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button type="submit" name="register" id="submitBtn"
                                class="w-full py-3.5 px-6 rounded-xl font-display font-bold text-sm bg-blue-600 hover:bg-blue-700 text-white shadow-lg shadow-blue-600/25 hover:shadow-blue-600/35 transition-all flex items-center justify-center gap-2 transform hover:-translate-y-0.5 cursor-pointer">
                            <i data-lucide="shield-check" class="w-4 h-4"></i>
                            <span>Verify Residency & Create Account</span>
                        </button>
                    </div>

                </form>

                <!-- Footer Sign In Link -->
                <div class="mt-6 pt-6 border-t border-slate-100 text-center">
                    <p class="text-xs text-slate-500">
                        Already have an active account? 
                        <a href="login_page.php" class="text-blue-600 hover:text-blue-700 font-bold ml-1 hover:underline">
                            Sign in here &rarr;
                        </a>
                    </p>
                </div>

            </div>

            <!-- Official Municipal Subtext -->
            <p class="text-center text-xs text-slate-400 mt-6 flex items-center justify-center gap-1.5">
                <i data-lucide="shield" class="w-3.5 h-3.5 text-blue-600"></i>
                <span>Protected by Balangoda Urban Council Municipal Registry Verification</span>
            </p>

        </div>
    </main>

    <!-- Client-side Interactive Script -->
    <script>
    lucide.createIcons();

    // Password visibility toggle
    const pwInput = document.getElementById('password');
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const toggleIcon = document.getElementById('toggleIcon');
    toggleBtn?.addEventListener('click', () => {
        const isPw = pwInput.type === 'password';
        pwInput.type = isPw ? 'text' : 'password';
        toggleIcon.setAttribute('data-lucide', isPw ? 'eye-off' : 'eye');
        lucide.createIcons();
    });

    // Password strength meter
    const strengthBar = document.getElementById('strengthBar');
    const strengthLabel = document.getElementById('strengthLabel');
    pwInput?.addEventListener('input', function() {
        const val = this.value;
        if (!val) {
            strengthBar.style.width = '0%';
            strengthLabel.textContent = 'Minimum 8 characters';
            strengthLabel.className = 'text-[11px] text-slate-400 mt-1 font-medium';
            return;
        }

        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        if (val.length < 8) {
            strengthBar.style.width = '25%';
            strengthBar.className = 'h-full bg-rose-500 rounded-full';
            strengthLabel.textContent = 'Too short (min 8 chars)';
            strengthLabel.className = 'text-[11px] text-rose-600 mt-1 font-medium';
        } else if (score <= 2) {
            strengthBar.style.width = '50%';
            strengthBar.className = 'h-full bg-amber-500 rounded-full';
            strengthLabel.textContent = 'Medium strength';
            strengthLabel.className = 'text-[11px] text-amber-600 mt-1 font-medium';
        } else {
            strengthBar.style.width = '100%';
            strengthBar.className = 'h-full bg-emerald-500 rounded-full';
            strengthLabel.textContent = 'Strong password';
            strengthLabel.className = 'text-[11px] text-emerald-600 mt-1 font-medium';
        }
    });

    // Real-time password match check
    const confirmInput = document.getElementById('password_confirm');
    const matchNotice = document.getElementById('matchNotice');
    function checkMatch() {
        if (!confirmInput.value) {
            matchNotice.textContent = '';
            return;
        }
        if (confirmInput.value === pwInput.value) {
            matchNotice.textContent = '✓ Passwords match';
            matchNotice.className = 'text-[11px] text-emerald-600 mt-1 font-medium';
        } else {
            matchNotice.textContent = '✗ Passwords do not match';
            matchNotice.className = 'text-[11px] text-rose-600 mt-1 font-medium';
        }
    }
    confirmInput?.addEventListener('input', checkMatch);
    pwInput?.addEventListener('input', checkMatch);

    // Bill requirement validator
    const elecInput = document.getElementById('electricity_bill_no');
    const waterInput = document.getElementById('water_bill_no');
    const billError = document.getElementById('billClientError');

    function validateBills() {
        const hasElec = elecInput.value.trim().length > 0;
        const hasWater = waterInput.value.trim().length > 0;
        if (!hasElec && !hasWater) {
            billError.classList.remove('hidden');
            return false;
        } else {
            billError.classList.add('hidden');
            return true;
        }
    }

    elecInput?.addEventListener('input', () => { if (elecInput.value.trim()) billError.classList.add('hidden'); });
    waterInput?.addEventListener('input', () => { if (waterInput.value.trim()) billError.classList.add('hidden'); });

    // Form submission validation guard
    document.getElementById('registerForm')?.addEventListener('submit', function(e) {
        if (!validateBills()) {
            e.preventDefault();
            elecInput.focus();
        }
    });
    </script>
</body>
</html>
