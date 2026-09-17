<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/db_connect.php';

$success_msg = "";
$error_msg = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_complaint'])) {
    $name = $conn->real_escape_string($_POST['name'] ?? '');
    $phone = $conn->real_escape_string($_POST['phone'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $utility_type = $conn->real_escape_string($_POST['utility_type'] ?? 'General');
    $description = $conn->real_escape_string($_POST['description'] ?? '');

    if (!empty($name) && (!empty($phone) || !empty($email)) && !empty($description)) {
        $sql = "INSERT INTO complaints (name, phone, email, utility_type, description, status, created_at) VALUES ('$name', '$phone', '$email', '$utility_type', '$description', 'Pending', NOW())";
        if ($conn->query($sql)) {
            $success_msg = "Your complaint has been successfully submitted! You can track its status in the portal.";
        } else {
            $error_msg = "Database Error: " . $conn->error;
        }
    } else {
        $error_msg = "Please fill in all required fields (Name, Contact number/email, and Description).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Complaint - Balangoda Utility Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-light">

    <!-- Top Navigation Header -->
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold text-decoration-none" href="index.php">← Back to Outage Warnings</a>
            <span class="text-white">Resident Portal - Submit Issue</span>
        </div>
    </nav>

    <div class="container my-5">
        
        <!-- Action Buttons -->
        <div class="d-flex justify-content-center gap-3 mb-4">
            <a href="submit_complaint.php" class="btn btn-primary px-4 fw-bold active">Report Utility Issue</a>
            <a href="report.php" class="btn btn-outline-primary px-4 fw-bold">Track My Complaints</a>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 p-4">
                    <h3 class="mb-3">Report a Utility Issue</h3>
                    <p class="text-muted mb-4">Fill out the form below to report water, power, telecom, or road issues to the municipal authorities.</p>

                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="submit_complaint.php">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" placeholder="Enter your name" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" name="phone" class="form-control" placeholder="e.g. 0771234567">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email5" name="email" class="form-control" placeholder="name@example.com">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Utility Type</label>
                            <select name="utility_type" class="form-select" required>
                                <option value="Power">Power / Electricity</option>
                                <option value="Water">Water Supply</option>
                                <option value="Road">Road Outage / Damage</option>
                                <option value="Telecom">Telecom / Internet</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Issue Description & Location</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Describe the problem and specify your exact location in Balangoda..." required></textarea>
                        </div>

                        <button type="submit" name="submit_complaint" class="btn btn-danger w-100 btn-lg fw-bold">Submit Complaint</button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>