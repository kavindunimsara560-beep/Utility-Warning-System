<?php
// Balangoda Utility Outage Warnings - Database Connection
mysqli_report(MYSQLI_REPORT_OFF);

// Ensure timezone is set to Sri Lanka (Balangoda)
date_default_timezone_set('Asia/Colombo');

$is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) 
    || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1']) 
    || php_sapi_name() === 'cli';

$conn = null;

if ($is_local) {
    // Candidates for local connection (Port 3306 MySQL service, Port 3308 XAMPP MySQL)
    $local_configs = [
        ['host' => '127.0.0.1', 'user' => 'root', 'pass' => 'K1@7n#U5', 'db' => 'balangoda_utility_warnings', 'port' => 3306],
        ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'db' => 'balangoda_utility_warnings', 'port' => 3308],
        ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'db' => 'balangoda_utility_warnings', 'port' => 3306],
        ['host' => 'localhost', 'user' => 'root', 'pass' => 'K1@7n#U5', 'db' => 'balangoda_utility_warnings', 'port' => 3306],
        ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'balangoda_utility_warnings', 'port' => 3308],
    ];

    foreach ($local_configs as $cfg) {
        try {
            $test_conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['port']);
            if (!$test_conn->connect_error) {
                $conn = $test_conn;
                break;
            }
        } catch (Throwable $e) {
            // Try next candidate
        }
    }
}

// Fallback to remote production database (e.g. InfinityFree hosting)
if (!$conn) {
    $remote_host = "sql106.infinityfree.com";
    $remote_user = "if0_42904484";
    $remote_pass = "K1@7n#U5";
    $remote_dbname = "if0_42904484_warnings";

    try {
        $conn = @new mysqli($remote_host, $remote_user, $remote_pass, $remote_dbname);
    } catch (Throwable $e) {
        // Handled below
    }
}

if (!$conn || $conn->connect_error) {
    $err = $conn ? $conn->connect_error : "Unable to reach database server. Please ensure MySQL is running in XAMPP.";
    die("<div style='font-family:sans-serif;padding:20px;background:#fff3cd;color:#856404;border:1px solid #ffeeba;border-radius:6px;margin:20px;'>
        <h3>Database Connection Failed</h3>
        <p>" . htmlspecialchars($err) . "</p>
        <p><small>Check that MySQL is running on port 3306 or 3308 in XAMPP.</small></p>
    </div>");
}

$conn->set_charset("utf8mb4");
@$conn->query("SET time_zone = '+05:30'");
?>