<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = '{database_url}';
$dbname = '{database_name}';
$usernamedb = '{username_db}';
$passworddb = '{password_db}';
$connect = mysqli_init();
if ($connect === false) { die("Database initialization failed"); }
$connect->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
if (!$connect->real_connect($dbhost, $usernamedb, $passworddb, $dbname)) { die("Database connection failed"); }
mysqli_set_charset($connect, "utf8mb4");
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 5, ];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
$pdo = null;
try { $pdo = new PDO($dsn, $usernamedb, $passworddb, $options); } catch (\PDOException $e) { error_log("Database connection failed: " . $e->getMessage()); }

if (!function_exists('mirzaCloseDatabaseConnections')) {
    function mirzaCloseDatabaseConnections()
    {
        global $pdo, $connect;
        $pdo = null;
        if ($connect instanceof mysqli) {
            try {
                $connect->close();
            } catch (Throwable $e) {
                // The connection may already have been closed explicitly.
            }
        }
        $connect = null;
    }
    register_shutdown_function('mirzaCloseDatabaseConnections');
}
$APIKEY = '{API_KEY}';
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';
$usernamebot = '{username_bot}';

?>
