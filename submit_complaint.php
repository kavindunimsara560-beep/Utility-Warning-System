<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/db_connect.php';

$success_msg = "";
$error_msg = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_complaint'])) {
    $name = trim($_POST['name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $utility_type = trim($_POST['utility_type'] ?? 'General');
    $description = trim($_POST['description'] ?? '');

    $contact_info = trim($phone . (!empty($phone) && !empty($email) ? ' | ' : '') . $email);

    if (!empty($name) && !empty($title) && !empty($contact_info) && !empty($description)) {
        $stmt = $conn->prepare("INSERT INTO complaints (resident_name, contact_info, title, utility_type, description, status, created_at) VALUES (?, ?, ?, ?, ?, 'Pending Review', NOW())");
        if ($stmt) {
            $stmt->bind_param("sssss", $name, $contact_info, $title, $utility_type, $description);
            if ($stmt->execute()) {
                $complaint_ref = $stmt->insert_id;
                $success_msg = "Your complaint (Ref #" . $complaint_ref . ") has been successfully submitted! You can track its status in the portal using your contact info.";
            } else {
                $error_msg = "Database Error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_msg = "Database Error: " . $conn->error;
        }
    } else {
        $error_msg = "Please fill in all required fields (Full Name, Issue Title, Contact Number or Email, and Description).";
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
                    <p class="text-muted mb-4">Fill out the form below to report water, power, telecom, or road issues to the municipal authorities in Balangoda.</p>

                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($success_msg); ?>
                            <div class="mt-2">
                                <a href="report.php" class="btn btn-sm btn-success">Go to Track Complaints</a>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="submit_complaint.php">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Enter your full name" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Issue Title / Subject <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. Water pipe burst near Clock Tower" required value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="text" name="phone" class="form-control" placeholder="e.g. 0771234567" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                                <small class="text-muted">Provide phone or email (or both)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="name@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Utility Type <span class="text-danger">*</span></label>
                            <select name="utility_type" class="form-select" required>
                                <option value="Power">Power / Electricity</option>
                                <option value="Water">Water Supply</option>
                                <option value="Road">Road Outage / Damage</option>
                                <option value="Telecom">Telecom / Internet</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Issue Description & Exact Location <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Describe the problem and specify your exact location in Balangoda..." required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>

                        <button type="submit" name="submit_complaint" class="btn btn-danger w-100 btn-lg fw-bold shadow-sm">Submit Complaint</button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>