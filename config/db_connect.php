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

    $active_cfg = null;
    foreach ($local_configs as $cfg) {
        try {
            $test_conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['port']);
            if (!$test_conn->connect_error) {
                $conn = $test_conn;
                $active_cfg = $cfg;
                break;
            }
        } catch (Throwable $e) {
            // Try next candidate
        }
    }
}

// Fallback to remote production database (e.g. InfinityFree hosting)
if (!$conn) {
    $remote_cfg = [
        'host' => 'sql106.infinityfree.com',
        'user' => 'if0_42904484',
        'pass' => 'K1@7n#U5',
        'db'   => 'if0_42904484_warnings',
        'port' => 3306
    ];

    try {
        $conn = @new mysqli($remote_cfg['host'], $remote_cfg['user'], $remote_cfg['pass'], $remote_cfg['db'], $remote_cfg['port']);
        if (!$conn->connect_error) {
            $active_cfg = $remote_cfg;
        }
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

// Modern PDO instance for prepared transactions, batch imports & registry verification
$pdo = null;
if (!empty($active_cfg)) {
    try {
        $dsn = "mysql:host=" . $active_cfg['host'] . ";dbname=" . $active_cfg['db'] . ";charset=utf8mb4";
        if (!empty($active_cfg['port'])) {
            $dsn .= ";port=" . $active_cfg['port'];
        }
        $pdo = new PDO($dsn, $active_cfg['user'], $active_cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        @$pdo->exec("SET time_zone = '+05:30'");
    } catch (Throwable $pe) {
        error_log("PDO initialization notice: " . $pe->getMessage());
    }
}
?>