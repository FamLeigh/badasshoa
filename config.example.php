<?php
// BadassHOA configuration template.
// Copy this file to config.php and fill in real values. config.php is gitignored.
//
// Local dev (MAMP PRO): use the Unix socket DSN below.
// Hostinger production: use 'mysql:host=...;dbname=...;charset=utf8mb4' with your real DB creds.

return [
    // 'local' or 'production'. Toggles error display and demo-credentials hint on /login.php.
    'env' => 'local',

    'db' => [
        // LOCAL (MAMP PRO):
        'dsn'  => 'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=badassHOA;charset=utf8mb4',
        // PRODUCTION (Hostinger):
        // 'dsn'  => 'mysql:host=localhost;dbname=u123456789_badasshoa;charset=utf8mb4',
        'user' => 'root',
        'pass' => 'root',
    ],

    'app' => [
        // Used to build absolute reset-link URLs in emails. Include scheme + port (no trailing slash).
        'base_url'    => 'https://badasshoa.com:8890',
        'admin_email' => 'admin@badasshoa.com', // signup notifications go here
    ],

    'mail' => [
        // 'log'   → writes to storage/logs/mail.log (local dev, no SMTP available)
        // 'mail'  → PHP mail() via host sendmail (Hostinger has hsendmail preconfigured — zero creds)
        // 'msmtp' → pipe to /usr/bin/msmtp -t (needs ~/.msmtprc with smtp.hostinger.com:587)
        'driver'     => 'log',
        'from'       => 'success@badasshoa.com', // must be a real mailbox in your Hostinger account
        'msmtp_path' => '/usr/bin/msmtp',
    ],
];
