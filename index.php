<?php
require_once 'config/db_connect.php';

// Capture filter inputs
$search_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$search_keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$search_date = isset($_GET['date']) ? trim($_GET['date']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balangoda Utility Outage Warnings</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .card:hover { 
            transform: translateY(-4px); 
            transition: 0.25s ease-in-out; 
            box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; 
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">⚡ Balangoda Utility Outage Warnings</a>
            <div class="d-flex align-items-center gap-2">
                <a href="submit_complaint.php" class="btn btn-danger btn-sm fw-bold">🚨 Report Issue</a>
                <a href="report.php" class="btn btn-outline-light btn-sm">Track Complaints</a>
                <a href="admin/login.php" class="btn btn-outline-secondary btn-sm text-white-50">Admin</a>
                <button class="btn btn-outline-info btn-sm d-none pwa-install-btn" title="Install as App">
                    <i class="bi bi-download"></i> Install App
                </button>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Page Header -->
        <div class="mb-4">
            <h3 class="fw-bold mb-1">Active &amp; Scheduled Outage Warnings</h3>
            <p class="text-muted mb-0 small">Official municipal utility alerts for electricity, water, and roadways in Balangoda.</p>
        </div>

        <!-- Advanced Filter & Search Bar -->
        <div class="card shadow-sm border-0 p-3 mb-4">
            <form method="GET" action="index.php" class="row g-3">
                <!-- Utility Type Filter -->
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Utility Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="Power" <?php if ($search_type === 'Power') echo 'selected'; ?>>Power</option>
                        <option value="Water" <?php if ($search_type === 'Water') echo 'selected'; ?>>Water</option>
                        <option value="Road" <?php if ($search_type === 'Road') echo 'selected'; ?>>Road</option>
                    </select>
                </div>

                <!-- Keyword Search -->
                <div class="col-md-4">
                    <label class="form-label fw-bold small">Keyword Search</label>
                    <input type="text" name="keyword" class="form-control" placeholder="e.g. Maintenance, Main St..."
                        value="<?php echo htmlspecialchars($search_keyword); ?>">
                </div>

                <!-- Date Filter -->
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Filter by Date</label>
                    <input type="date" name="date" class="form-control"
                        value="<?php echo htmlspecialchars($search_date); ?>">
                </div>

                <!-- Buttons -->
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">Search</button>
                    <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>

        <!-- Warning Cards Grid -->
        <div class="row g-4 mb-5">
            <?php
            // Base SQL query keeping active & upcoming warnings
            $sql = "SELECT * FROM warnings WHERE end_time > NOW()";

            // Dynamically append filters based on user input
            if ($search_type && in_array($search_type, ['Power', 'Water', 'Road'])) {
                $sql .= " AND utility_type = '" . $conn->real_escape_string($search_type) . "'";
            }

            if (!empty($search_keyword)) {
                $escaped_keyword = $conn->real_escape_string($search_keyword);
                $sql .= " AND (title LIKE '%$escaped_keyword%' OR description LIKE '%$escaped_keyword%')";
            }

            if (!empty($search_date)) {
                $escaped_date = $conn->real_escape_string($search_date);
                $sql .= " AND ('$escaped_date' BETWEEN DATE(start_time) AND DATE(end_time))";
            }

            $sql .= " ORDER BY start_time ASC";
            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $type = htmlspecialchars($row['utility_type']);
                    $customColor = htmlspecialchars($row['color_code']);
                    
                    // Check if outage is currently ongoing vs scheduled ahead
                    $now = time();
                    $start_ts = strtotime($row['start_time']);
                    $end_ts = strtotime($row['end_time']);
                    $is_ongoing = ($start_ts <= $now && $end_ts > $now);
                    
                    // Check if warning was published recently (last 24 hours)
                    $is_new = (!empty($row['created_at']) && strtotime($row['created_at']) > ($now - 86400));

                    $statusBadge = "";
                    if ($is_ongoing) {
                        $statusBadge .= "<span class='badge bg-warning text-dark me-1'>⚡ ONGOING</span>";
                    } else {
                        $statusBadge .= "<span class='badge bg-light text-dark me-1'>SCHEDULED</span>";
                    }
                    if ($is_new) {
                        $statusBadge .= "<span class='badge bg-danger'>NEW ALERT</span>";
                    }

                    $start_formatted = date('M d, Y - h:i A', $start_ts);
                    $end_formatted = date('M d, Y - h:i A', $end_ts);

                    echo "
                    <div class='col-md-6 col-lg-4'>
                        <div class='card h-100 shadow-sm' style='border: 2px solid $customColor;'>
                            <div class='card-header fw-bold text-white d-flex justify-content-between align-items-center' style='background-color: $customColor;'>
                                <span>$type Outage</span>
                                <div>$statusBadge</div>
                            </div>
                            <div class='card-body'>
                                <h5 class='card-title fw-bold'>" . htmlspecialchars($row['title']) . "</h5>
                                <p class='card-text text-muted'>" . htmlspecialchars($row['description']) . "</p>
                            </div>
                            <div class='card-footer bg-transparent' style='border-top: 1px solid rgba(0,0,0,0.08);'>
                                <small class='d-block text-muted'><strong>Start:</strong> $start_formatted</small>
                                <small class='d-block text-muted'><strong>End:</strong> $end_formatted</small>
                            </div>
                        </div>
                    </div>";
                }
            } else {
                echo "<div class='col-12'><div class='alert alert-warning shadow-sm'>No active or scheduled utility outages match your search criteria.</div></div>";
            }
            ?>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- PWA: SW registration, install prompt, notifications, beforeunload guard -->
    <script src="/js/pwa.js"></script>
</body>
</html>