<?php
declare(strict_types=1);

// Load config once (returns the array from config.php).
function config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $path = __DIR__ . '/../config.php';
    if (!is_file($path)) {
        http_response_code(500);
        die("Missing config.php — copy config.example.php to config.php and fill in real values.");
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        http_response_code(500);
        die("config.php must return an array.");
    }
    return $cfg;
}

// One-time bootstrap (env-aware error reporting, UTC, log dir).
(function () {
    $env = config()['env'] ?? 'production';

    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

    error_reporting(E_ALL);
    ini_set('log_errors',     '1');
    ini_set('error_log',      $logDir . '/php-errors.log');
    ini_set('display_errors', $env === 'local' ? '1' : '0');
    date_default_timezone_set('UTC'); // matches the MySQL session TZ pinned in db()
})();

// PDO singleton.
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = config()['db'];
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Pin every connection to UTC so PHP's time()/strtotime() and MySQL's NOW() agree.
    // MAMP MySQL runs in the system TZ (e.g. EDT) by default, while PHP defaults to UTC.
    // Without this, TIMESTAMP comparisons drift by hours.
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}
