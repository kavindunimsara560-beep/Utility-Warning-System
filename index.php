<?php
require 'config/db_connect.php';

// Capture filter inputs
$search_type = isset($_GET['type']) ? $_GET['type'] : '';
$search_keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$search_date = isset($_GET['date']) ? $_GET['date'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balangoda Utility Warnings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }

        .card:hover {
            transform: translateY(-5px);
            transition: 0.3s ease-in-out;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1) !important;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container">
            <span class="navbar-brand mb-0 h1">Balangoda Utility Outage Warnings</span>
        </div>
    </nav>

    <div class="container">
        <!-- Page Header & Report Button -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Active Outages & Warnings</h4>
            <a href="report.php" class="btn btn-danger btn-sm shadow-sm">🚨 Report Utility Issue</a>
        </div>

        <!-- Advanced Filter & Search Bar -->
        <div class="card shadow-sm border-0 p-3 mb-4">
            <form method="GET" action="" class="row g-3">
                <!-- Utility Type Filter -->
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Utility Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="Power" <?php if ($search_type == 'Power')
                            echo 'selected'; ?>>Power</option>
                        <option value="Water" <?php if ($search_type == 'Water')
                            echo 'selected'; ?>>Water</option>
                        <option value="Road" <?php if ($search_type == 'Road')
                            echo 'selected'; ?>>Road</option>
                    </select>
                </div>

                <!-- Keyword Search -->
                <div class="col-md-4">
                    <label class="form-label fw-bold small">Keyword Search</label>
                    <input type="text" name="keyword" class="form-control" placeholder="e.g. Maintenance, Pipeline"
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
            // Base SQL query keeping the automatic active check (hiding expired warnings)
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
                    $type = $row['utility_type'];
                    $customColor = htmlspecialchars($row['color_code']);

                    // Check if the warning was created within the last 24 hours (86400 seconds)
                    $is_new = (strtotime($row['created_at']) > (time() - 86400));
                    $notificationBadge = $is_new ? "<span class='badge bg-danger ms-2'>NEW ALERT</span>" : "";

                    echo "
                    <div class='col-md-6 col-lg-4'>
                        <div class='card h-100 shadow-sm' style='border: 2px solid $customColor;'>
                            <div class='card-header fw-bold text-white d-flex justify-content-between align-items-center' style='background-color: $customColor;'>
                                <span>$type Outage</span>
                                $notificationBadge
                            </div>
                            <div class='card-body'>
                                <h5 class='card-title'>" . htmlspecialchars($row['title']) . "</h5>
                                <p class='card-text text-muted'>" . htmlspecialchars($row['description']) . "</p>
                            </div>
                            <div class='card-footer bg-transparent' style='border-top: 1px solid $customColor;'>
                                <small class='d-block'><strong>Start:</strong> " . $row['start_time'] . "</small>
                                <small class='d-block'><strong>End:</strong> " . $row['end_time'] . "</small>
                            </div>
                        </div>
                    </div>";
                }
            } else {
                echo "<div class='col-12'><div class='alert alert-warning shadow-sm'>No utility outages match your search criteria.</div></div>";
            }
            ?>
        </div>
    </div>
</body>

</html>