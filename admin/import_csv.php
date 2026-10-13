<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php?role=admin&msg=login_required&redirect=" . urlencode('admin/import_csv.php'));
    exit();
}

require_once '../config/db_connect.php';
require_once '../config/profile_helper.php';

$admin_id = (int)$_SESSION['admin_id'];
$admin_display_name = $_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Municipal Admin';

$report = null;
$error_msg = '';
$default_csv_path = realpath(__DIR__ . '/../Balangoda_Utility_Warning_Syst_clean.csv');
$default_csv_exists = $default_csv_path && file_exists($default_csv_path);

// Helper function to process CSV file with PDO Prepared Statements & Transactions
function process_council_csv(PDO $pdo, string $file_path): array {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        throw new RuntimeException("CSV file is not accessible at path: " . htmlspecialchars($file_path));
    }

    $fh = fopen($file_path, 'r');
    if (!$fh) {
        throw new RuntimeException("Could not open CSV file stream.");
    }

    // Read header row
    $raw_headers = fgetcsv($fh);
    if (!$raw_headers) {
        fclose($fh);
        throw new RuntimeException("CSV file appears to be empty or corrupted.");
    }

    // Map column names to indexes
    $col_map = [];
    foreach ($raw_headers as $idx => $hdr) {
        $clean_hdr = trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $hdr)); // Strip BOM / control characters
        $col_map[$clean_hdr] = $idx;
    }

    // Check required header mappings
    $name_idx    = $col_map['Full Name'] ?? null;
    $email_idx   = $col_map['Email Address'] ?? null;
    $phone_idx   = $col_map['Phone number'] ?? null;
    $addr_idx    = $col_map['Residential Address'] ?? null;
    $gn_idx      = $col_map['GN division/Village'] ?? null;
    $elec_idx    = $col_map['Electricity Account Bill Number'] ?? null;
    $water_idx   = $col_map['Water Account Bill Number'] ?? null;
    $service_idx = $col_map['Which services do you want to receive warnings about?'] ?? null;

    if ($name_idx === null) {
        fclose($fh);
        throw new RuntimeException("Missing required column 'Full Name' in CSV header.");
    }

    // Prepare duplicate existence check query
    $check_stmt = $pdo->prepare("
        SELECT id FROM council_registry 
        WHERE (electricity_account_no = :ceb AND :ceb_chk != '')
           OR (water_account_no = :water AND :water_chk != '')
        LIMIT 1
    ");

    // Prepare insert statement
    $insert_stmt = $pdo->prepare("
        INSERT INTO council_registry (
            full_name,
            email,
            phone_number,
            residential_address,
            gn_division,
            electricity_account_no,
            water_account_no,
            service_preferences,
            registered_status
        ) VALUES (
            :full_name,
            :email,
            :phone_number,
            :residential_address,
            :gn_division,
            :electricity_account_no,
            :water_account_no,
            :service_preferences,
            'unclaimed'
        )
    ");

    $processed = 0;
    $inserted  = 0;
    $skipped   = 0;
    $errors    = [];
    $start_time = microtime(true);

    // Run within a database transaction for ACID guarantees and speed
    $pdo->beginTransaction();

    try {
        while (($row = fgetcsv($fh)) !== false) {
            // Skip empty rows
            if (empty(array_filter($row, 'trim'))) {
                continue;
            }

            $processed++;

            $full_name = trim($row[$name_idx] ?? '');
            if (empty($full_name)) {
                $skipped++;
                continue;
            }

            $email     = !empty($email_idx) && isset($row[$email_idx]) ? trim($row[$email_idx]) : null;
            $phone     = !empty($phone_idx) && isset($row[$phone_idx]) ? trim($row[$phone_idx]) : null;
            $address   = !empty($addr_idx) && isset($row[$addr_idx]) ? trim($row[$addr_idx]) : null;
            $gn        = !empty($gn_idx) && isset($row[$gn_idx]) ? trim($row[$gn_idx]) : null;
            
            $elec_val  = !empty($elec_idx) && isset($row[$elec_idx]) ? trim($row[$elec_idx]) : '';
            $water_val = !empty($water_idx) && isset($row[$water_idx]) ? trim($row[$water_idx]) : '';

            $elec_acc  = $elec_val !== '' ? $elec_val : null;
            $water_acc = $water_val !== '' ? $water_val : null;
            $services  = !empty($service_idx) && isset($row[$service_idx]) ? trim($row[$service_idx]) : null;

            // Existence check: skip duplicates based on electricity or water account
            $is_duplicate = false;
            if ($elec_acc !== null || $water_acc !== null) {
                $check_stmt->execute([
                    ':ceb'       => $elec_acc ?? '',
                    ':ceb_chk'   => $elec_acc ?? '',
                    ':water'     => $water_acc ?? '',
                    ':water_chk' => $water_acc ?? ''
                ]);
                if ($check_stmt->fetch()) {
                    $is_duplicate = true;
                }
            }

            if ($is_duplicate) {
                $skipped++;
                continue;
            }

            // Execute insert
            $insert_stmt->execute([
                ':full_name'              => $full_name,
                ':email'                  => $email ?: null,
                ':phone_number'           => $phone ?: null,
                ':residential_address'    => $address ?: null,
                ':gn_division'            => $gn ?: null,
                ':electricity_account_no' => $elec_acc,
                ':water_account_no'       => $water_acc,
                ':service_preferences'    => $services ?: null
            ]);

            $inserted++;
        }

        // Commit transaction
        $pdo->commit();
        fclose($fh);

        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        return [
            'success'   => true,
            'processed' => $processed,
            'inserted'  => $inserted,
            'skipped'   => $skipped,
            'time_ms'   => $execution_time,
            'filename'  => basename($file_path)
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        fclose($fh);
        throw $e;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'import_default') {
        if (!$default_csv_exists) {
            $error_msg = "Root CSV file 'Balangoda_Utility_Warning_Syst_clean.csv' could not be found.";
        } else {
            try {
                $report = process_council_csv($pdo, $default_csv_path);
            } catch (Throwable $ex) {
                $error_msg = "Import failed: " . $ex->getMessage();
            }
        }
    } elseif ($action === 'upload_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Please select a valid CSV file to upload.";
        } else {
            $tmp_path = $_FILES['csv_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
            if ($file_ext !== 'csv') {
                $error_msg = "Uploaded file must have a .csv extension.";
            } else {
                try {
                    $report = process_council_csv($pdo, $tmp_path);
                } catch (Throwable $ex) {
                    $error_msg = "Import failed: " . $ex->getMessage();
                }
            }
        }
    } elseif ($action === 'truncate_registry') {
        // Safe clear registry for testing/refresh purposes
        try {
            $pdo->exec("DELETE FROM council_registry");
            $pdo->exec("ALTER TABLE council_registry AUTO_INCREMENT = 1");
            $report = [
                'success'   => true,
                'truncated' => true,
                'message'   => "Council registry table was successfully emptied and reset."
            ];
        } catch (Throwable $ex) {
            $error_msg = "Reset failed: " . $ex->getMessage();
        }
    }
}

// Fetch current registry statistics
$stat_total = 0;
$stat_claimed = 0;
$stat_unclaimed = 0;
$recent_records = [];

try {
    $stat_total     = (int)$pdo->query("SELECT COUNT(*) FROM council_registry")->fetchColumn();
    $stat_claimed   = (int)$pdo->query("SELECT COUNT(*) FROM council_registry WHERE registered_status = 'claimed'")->fetchColumn();
    $stat_unclaimed = (int)$pdo->query("SELECT COUNT(*) FROM council_registry WHERE registered_status = 'unclaimed'")->fetchColumn();

    $recent_stmt = $pdo->query("
        SELECT id, full_name, email, phone_number, gn_division, electricity_account_no, water_account_no, registered_status, created_at 
        FROM council_registry 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $recent_records = $recent_stmt->fetchAll();
} catch (Throwable $e) {
    // Registry table might not exist yet
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Council Registry Importer — Balangoda Municipal Council</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#111317">

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
                    adm: {
                        dark: '#080500',
                        charcoal: '#111317',
                        accent: '#d97706',
                        gold: '#f59e0b',
                    }
                }
            }
        }
    }
    </script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body class="min-h-screen flex flex-col font-sans text-slate-800 bg-slate-50 antialiased selection:bg-amber-500 selection:text-white">

    <!-- Top Admin Header -->
    <header class="sticky top-0 z-40 bg-[#111317] border-b border-amber-500/20 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-white shadow-sm ring-1 ring-amber-400/30">
                    <i data-lucide="database" class="w-5 h-5 text-amber-100"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-display font-bold text-white text-base tracking-wide">Balangoda <span class="text-amber-400">Urban Council</span></span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-500/10 text-amber-300 border border-amber-500/20">Admin Utility</span>
                    </div>
                    <p class="text-slate-400 text-xs">Official Resident Verification Registry</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors border border-slate-700">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5 text-amber-400"></i>
                    <span>Dashboard</span>
                </a>
                <a href="logout.php" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-rose-300 hover:text-rose-100 bg-rose-950/40 hover:bg-rose-900/60 rounded-lg transition-colors border border-rose-800/40">
                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                    <span>Exit</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Title Section -->
        <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs font-semibold text-amber-700 uppercase tracking-wider mb-1">
                    <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5"></i>
                    <span>Automated Municipal Data Ingestion</span>
                </div>
                <h1 class="font-display text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Council Registry CSV Importer</h1>
                <p class="text-slate-600 text-sm mt-1">Pre-load validated Ceylon Electricity Board (CEB) and NWSDB resident accounts for instant citizen verification.</p>
            </div>

            <!-- Stats Overview Pills -->
            <div class="flex items-center gap-2">
                <div class="bg-white border border-slate-200 rounded-2xl px-4 py-2.5 shadow-sm text-center">
                    <div class="text-xs text-slate-500 font-medium">Total Registered</div>
                    <div class="text-xl font-display font-extrabold text-slate-900"><?= number_format($stat_total) ?></div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl px-4 py-2.5 shadow-sm text-center">
                    <div class="text-xs text-emerald-600 font-medium">Claimed</div>
                    <div class="text-xl font-display font-extrabold text-emerald-700"><?= number_format($stat_claimed) ?></div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl px-4 py-2.5 shadow-sm text-center">
                    <div class="text-xs text-amber-600 font-medium">Unclaimed</div>
                    <div class="text-xl font-display font-extrabold text-amber-700"><?= number_format($stat_unclaimed) ?></div>
                </div>
            </div>
        </div>

        <!-- Alert messages -->
        <?php if (!empty($error_msg)): ?>
        <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 flex items-start gap-3 text-rose-800 shadow-sm animate-fade-in">
            <i data-lucide="alert-octagon" class="w-5 h-5 flex-shrink-0 text-rose-600 mt-0.5"></i>
            <div class="flex-1 text-sm">
                <strong class="font-semibold">Import Error:</strong> <?= htmlspecialchars($error_msg) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Success Report Card -->
        <?php if ($report && !empty($report['success'])): ?>
            <?php if (!empty($report['truncated'])): ?>
            <div class="mb-8 p-4 rounded-2xl bg-amber-50 border border-amber-200 flex items-center gap-3 text-amber-800 shadow-sm">
                <i data-lucide="trash-2" class="w-5 h-5 text-amber-600"></i>
                <span class="text-sm font-semibold"><?= htmlspecialchars($report['message']) ?></span>
            </div>
            <?php else: ?>
            <div class="mb-8 bg-gradient-to-br from-emerald-500/10 via-emerald-500/5 to-white border-2 border-emerald-400 rounded-3xl p-6 sm:p-8 shadow-lg shadow-emerald-500/10">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-600 flex items-center justify-center text-white shadow-md shadow-emerald-600/30">
                        <i data-lucide="check-check" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="font-display text-xl font-bold text-emerald-950">Batch Ingestion Completed Successfully</h2>
                        <p class="text-emerald-700 text-xs">Dataset: <code class="font-mono bg-emerald-100 text-emerald-900 px-1.5 py-0.5 rounded"><?= htmlspecialchars($report['filename']) ?></code> committed inside PDO transaction.</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-white rounded-2xl p-4 border border-emerald-200/80 shadow-xs">
                        <div class="text-xs text-slate-500 font-medium">Rows Processed</div>
                        <div class="text-2xl font-display font-black text-slate-900 mt-1"><?= number_format($report['processed']) ?></div>
                    </div>
                    <div class="bg-white rounded-2xl p-4 border border-emerald-200/80 shadow-xs">
                        <div class="text-xs text-emerald-600 font-medium">New Records Inserted</div>
                        <div class="text-2xl font-display font-black text-emerald-600 mt-1"><?= number_format($report['inserted']) ?></div>
                    </div>
                    <div class="bg-white rounded-2xl p-4 border border-emerald-200/80 shadow-xs">
                        <div class="text-xs text-amber-600 font-medium">Skipped / Duplicates</div>
                        <div class="text-2xl font-display font-black text-amber-600 mt-1"><?= number_format($report['skipped']) ?></div>
                    </div>
                    <div class="bg-white rounded-2xl p-4 border border-emerald-200/80 shadow-xs">
                        <div class="text-xs text-slate-500 font-medium">Execution Duration</div>
                        <div class="text-2xl font-display font-black text-slate-900 mt-1"><?= $report['time_ms'] ?> <span class="text-xs font-normal text-slate-500">ms</span></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Import Panels Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">

            <!-- Panel 1: Fast Root Import -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-7 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 flex items-center justify-center">
                            <i data-lucide="zap" class="w-5 h-5"></i>
                        </div>
                        <?php if ($default_csv_exists): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                File Ready
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                <i data-lucide="x" class="w-3 h-3"></i>
                                File Not Found
                            </span>
                        <?php endif; ?>
                    </div>

                    <h2 class="font-display text-lg font-bold text-slate-900 mb-1">One-Click Project Root Import</h2>
                    <p class="text-slate-600 text-xs mb-4">Automatically ingests the 300-row municipal dataset <code class="font-mono text-amber-800 bg-amber-50 px-1 py-0.5 rounded">Balangoda_Utility_Warning_Syst_clean.csv</code> located in the root directory.</p>

                    <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200/80 mb-6 text-xs text-slate-600 space-y-2">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Filename:</span>
                            <span class="font-mono font-medium text-slate-800">Balangoda_Utility_Warning_Syst_clean.csv</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">File Size:</span>
                            <span class="font-medium text-slate-800"><?= $default_csv_exists ? round(filesize($default_csv_path) / 1024, 1) . ' KB' : 'N/A' ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Target Table:</span>
                            <span class="font-mono text-slate-800 font-semibold">council_registry</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Duplicate Guard:</span>
                            <span class="font-medium text-emerald-700">CEB / NWSDB Uniqueness Filter</span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" onsubmit="return confirm('Initiate batch import of 300 records into council_registry? Existing duplicates will be safely skipped.');">
                    <input type="hidden" name="action" value="import_default">
                    <button type="submit" <?= !$default_csv_exists ? 'disabled' : '' ?> class="w-full py-3.5 px-5 rounded-2xl font-display font-bold text-sm bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 disabled:opacity-50 text-white shadow-md shadow-amber-900/20 hover:shadow-lg transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <i data-lucide="play" class="w-4 h-4 text-amber-200"></i>
                        <span>Execute Root CSV Ingestion</span>
                    </button>
                </form>
            </div>

            <!-- Panel 2: Upload Custom CSV -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-7 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 flex items-center justify-center">
                            <i data-lucide="upload-cloud" class="w-5 h-5"></i>
                        </div>
                        <span class="text-xs text-slate-500 font-medium">Manual Upload</span>
                    </div>

                    <h2 class="font-display text-lg font-bold text-slate-900 mb-1">Upload Updated CSV File</h2>
                    <p class="text-slate-600 text-xs mb-4">Upload an updated municipal utility export to append new accounts or refresh registry records.</p>

                    <form method="POST" action="" enctype="multipart/form-data" id="uploadForm" class="space-y-4">
                        <input type="hidden" name="action" value="upload_csv">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Select CSV File</label>
                            <label for="csv_file" class="flex flex-col items-center justify-center w-full h-32 border-2 border-slate-300 border-dashed rounded-2xl cursor-pointer bg-slate-50 hover:bg-slate-100 transition-colors">
                                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                    <i data-lucide="file-up" class="w-8 h-8 text-slate-400 mb-2"></i>
                                    <p class="text-xs text-slate-600 font-medium"><span class="font-bold text-amber-600">Click to choose</span> or drag & drop</p>
                                    <p class="text-[11px] text-slate-400 mt-1">.csv spreadsheet files only</p>
                                </div>
                                <input id="csv_file" name="csv_file" type="file" accept=".csv" class="hidden" onchange="document.getElementById('fileNamePreview').textContent = this.files[0]?.name || '';">
                            </label>
                            <div id="fileNamePreview" class="text-xs font-mono text-emerald-700 font-semibold mt-2 text-center"></div>
                        </div>

                        <button type="submit" class="w-full py-3.5 px-5 rounded-2xl font-display font-bold text-sm bg-slate-900 hover:bg-slate-800 text-white shadow-sm transition-all flex items-center justify-center gap-2 cursor-pointer">
                            <i data-lucide="upload" class="w-4 h-4"></i>
                            <span>Upload & Import Dataset</span>
                        </button>
                    </form>
                </div>

                <!-- Safe reset action -->
                <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-[11px] text-slate-400">Development / Testing Tool:</span>
                    <form method="POST" action="" onsubmit="return confirm('CAUTION: This will remove ALL entries from council_registry. Are you sure?');">
                        <input type="hidden" name="action" value="truncate_registry">
                        <button type="submit" class="text-xs font-semibold text-rose-600 hover:text-rose-800 transition-colors flex items-center gap-1">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            <span>Reset Registry Table</span>
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <!-- Registry Preview Table -->
        <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm">
            <div class="px-6 py-4.5 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-50/50">
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center">
                        <i data-lucide="list" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-bold text-slate-900 text-sm">Council Registry Sample & Latest Records</h3>
                        <p class="text-slate-500 text-xs">Preview of pre-registered citizen utility accounts currently verified</p>
                    </div>
                </div>
                <div class="text-xs font-medium text-slate-500">
                    Showing latest <?= count($recent_records) ?> of <?= number_format($stat_total) ?> records
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100/75 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                            <th class="py-3 px-4">ID</th>
                            <th class="py-3 px-4">Resident Full Name</th>
                            <th class="py-3 px-4">GN Division</th>
                            <th class="py-3 px-4">CEB Electricity No</th>
                            <th class="py-3 px-4">NWSDB Water No</th>
                            <th class="py-3 px-4">Phone</th>
                            <th class="py-3 px-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-sans">
                        <?php if (empty($recent_records)): ?>
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-400">
                                <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                                Registry is currently empty. Run the One-Click Import above to populate records.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recent_records as $rec): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-4 font-mono text-slate-400">#<?= htmlspecialchars($rec['id']) ?></td>
                            <td class="py-3 px-4 font-semibold text-slate-900"><?= htmlspecialchars($rec['full_name']) ?></td>
                            <td class="py-3 px-4 text-slate-600"><?= htmlspecialchars($rec['gn_division'] ?? '—') ?></td>
                            <td class="py-3 px-4">
                                <?php if (!empty($rec['electricity_account_no'])): ?>
                                    <span class="inline-flex items-center gap-1 font-mono px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200">
                                        <i data-lucide="zap" class="w-3 h-3 text-amber-600"></i>
                                        <?= htmlspecialchars($rec['electricity_account_no']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-400 italic">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4">
                                <?php if (!empty($rec['water_account_no'])): ?>
                                    <span class="inline-flex items-center gap-1 font-mono px-2 py-0.5 rounded bg-blue-50 text-blue-800 border border-blue-200">
                                        <i data-lucide="droplet" class="w-3 h-3 text-blue-600"></i>
                                        <?= htmlspecialchars($rec['water_account_no']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-400 italic">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 font-mono text-slate-600"><?= htmlspecialchars($rec['phone_number'] ?? '—') ?></td>
                            <td class="py-3 px-4">
                                <?php if ($rec['registered_status'] === 'claimed'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full font-semibold text-[11px] bg-emerald-100 text-emerald-800">
                                        <i data-lucide="shield-check" class="w-3 h-3 text-emerald-600"></i>
                                        Claimed
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full font-semibold text-[11px] bg-slate-100 text-slate-700">
                                        <i data-lucide="clock" class="w-3 h-3 text-slate-400"></i>
                                        Unclaimed
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-400">
        &copy; <?= date('Y') ?> Balangoda Urban Council Utility Warning System &bull; Registry Management Utility
    </footer>

    <script>
    lucide.createIcons();
    </script>
</body>
</html>
