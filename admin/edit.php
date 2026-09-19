<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php?role=admin&msg=login_required");
    exit();
}

require '../config/db_connect.php';

$message = "";
$warning_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch existing warning data safely
$stmt = $conn->prepare("SELECT * FROM warnings WHERE warning_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $warning_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        header("Location: dashboard.php");
        exit();
    }
    $warning = $result->fetch_assoc();
    $stmt->close();
} else {
    header("Location: dashboard.php");
    exit();
}

// Handle update form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_warning'])) {
    $utility_type = trim($_POST['utility_type'] ?? 'Power');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color_code = trim($_POST['color_code'] ?? '#dc3545');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);
    $today_start = strtotime(date('Y-m-d 00:00:00'));

    if (empty($title) || empty($description) || empty($start_time) || empty($end_time)) {
        $message = "All fields are required.";
    } elseif ($start_ts < $today_start) {
        $message = "Start date cannot be in the past. Only today and future dates are allowed.";
    } elseif ($end_ts < $start_ts) {
        $message = "End Time must be the same as or after Start Time.";
    } else {
        $upd_stmt = $conn->prepare("UPDATE warnings SET utility_type=?, title=?, description=?, color_code=?, start_time=?, end_time=? WHERE warning_id=?");
        if ($upd_stmt) {
            $upd_stmt->bind_param("ssssssi", $utility_type, $title, $description, $color_code, $start_time, $end_time, $warning_id);
            if ($upd_stmt->execute()) {
                $upd_stmt->close();
                header("Location: dashboard.php?msg=warning_updated");
                exit();
            } else {
                $message = "Error updating warning: " . $upd_stmt->error;
            }
            $upd_stmt->close();
        } else {
            $message = "Database error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warning Notice — Balangoda Utility Admin</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#d97706">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body class="theme-admin">

    <!-- Unified Top Navigation (Admin Theme) -->
    <nav class="portal-nav mb-4">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between">
                <a href="dashboard.php" class="brand">
                    <span class="brand-badge">⚡</span>
                    <span>Balangoda</span> Admin Console
                </a>
                <div class="d-flex align-items-center gap-2">
                    <a href="dashboard.php" class="nav-btn">
                        <i class="bi bi-speedometer2"></i>
                        <span class="d-none d-sm-inline">Dashboard</span>
                    </a>
                    <a href="profile.php" class="nav-btn">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-sm-inline">My Profile</span>
                    </a>
                    <a href="logout.php" class="nav-btn danger">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="d-none d-sm-inline">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container my-4" style="max-width: 680px;">
        <div class="portal-card p-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <div>
                    <h4 class="mb-1 fw-bold text-dark">Update Outage Notice #<?php echo $warning_id; ?></h4>
                    <small class="text-muted">Modify scheduled dates, utility sector, or citizen advisory description.</small>
                </div>
                <span class="role-pill bg-warning-subtle text-warning border border-warning-subtle">Admin Edit</span>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:12px;">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="edit.php?id=<?php echo $warning_id; ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark small">Utility Type</label>
                    <select name="utility_type" class="form-select" required>
                        <option value="Power" <?php if ($warning['utility_type'] == 'Power')
                            echo 'selected'; ?>>Power (CEB)
                        </option>
                        <option value="Water" <?php if ($warning['utility_type'] == 'Water')
                            echo 'selected'; ?>>Water (NWSDB)
                        </option>
                        <option value="Road" <?php if ($warning['utility_type'] == 'Road')
                            echo 'selected'; ?>>Road &amp; Municipal
                        </option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark small">Title / Notice Headline</label>
                    <input type="text" name="title" class="form-control"
                        value="<?php echo htmlspecialchars($warning['title']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark small">Description &amp; Affected Areas</label>
                    <textarea name="description" class="form-control" rows="3"
                        required><?php echo htmlspecialchars($warning['description']); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark small">Color Code (Hex Accent)</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" name="color_code" class="form-control form-control-color"
                            value="<?php echo htmlspecialchars($warning['color_code']); ?>" required>
                        <span class="text-muted small">Choose card accent color for resident dashboard</span>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold text-dark small">Start Time</label>
                        <input type="datetime-local" id="edit_start_time" name="start_time" class="form-control"
                            value="<?php echo date('Y-m-d\TH:i', strtotime($warning['start_time'])); ?>"
                            min="<?= date('Y-m-d\T00:00') ?>" required>
                        <div class="invalid-feedback">Start date cannot be before today.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold text-dark small">End Time</label>
                        <input type="datetime-local" id="edit_end_time" name="end_time" class="form-control"
                            value="<?php echo date('Y-m-d\TH:i', strtotime($warning['end_time'])); ?>"
                            min="<?= date('Y-m-d\T00:00') ?>" required>
                        <div class="invalid-feedback">End time must be after start time and not in the past.</div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4 pt-2 border-top">
                    <a href="dashboard.php" class="portal-btn-outline">Cancel</a>
                    <button type="submit" name="update_warning" class="portal-btn-primary">
                        <i class="bi bi-check2-circle me-1"></i>Update Warning Notice
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── Date-time dependency: Today & future only, and End Time >= Start Time ────────
        (function () {
            const startEl = document.getElementById('edit_start_time');
            const endEl = document.getElementById('edit_end_time');
            if (!startEl || !endEl) return;

            function getTodayMin() {
                const now = new Date();
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}T00:00`;
            }

            const todayMin = getTodayMin();
            startEl.min = todayMin;

            function validateDates() {
                const startVal = startEl.value;
                const endVal = endEl.value;

                // Validate start date is not before today
                if (startVal && startVal < todayMin) {
                    startEl.classList.add('is-invalid');
                } else {
                    startEl.classList.remove('is-invalid');
                }

                // Sync endEl.min with startVal or todayMin
                const effectiveEndMin = (startVal && startVal > todayMin) ? startVal : todayMin;
                endEl.min = effectiveEndMin;

                // Validate end date
                if (endVal && startVal && endVal < startVal) {
                    endEl.classList.add('is-invalid');
                } else if (endVal && endVal < todayMin) {
                    endEl.classList.add('is-invalid');
                } else {
                    endEl.classList.remove('is-invalid');
                }
            }

            validateDates();

            startEl.addEventListener('change', validateDates);
            startEl.addEventListener('input', validateDates);
            endEl.addEventListener('change', validateDates);
            endEl.addEventListener('input', validateDates);

            // Guard on form submit
            startEl.closest('form')?.addEventListener('submit', function (e) {
                validateDates();
                if (startEl.classList.contains('is-invalid') || endEl.classList.contains('is-invalid')) {
                    e.preventDefault();
                    if (startEl.classList.contains('is-invalid')) {
                        startEl.focus();
                    } else {
                        endEl.focus();
                    }
                }
            });
        })();
    </script>
</body>

</html>