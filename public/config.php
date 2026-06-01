<?php
/**
 * SSH Panel Manager - Configuration
 */

// Database
define('DB_PATH', __DIR__ . '/database/panel.db');
define('BACKUP_DIR', __DIR__ . '/backups');
define('LOG_DIR', __DIR__ . '/logs');

// Session
define('SESSION_NAME', 'sshpanel_session');
define('SESSION_LIFETIME', 3600); // 1 hour

// Panel version
define('PANEL_VERSION', '1.0.0');

// Default settings (used on first init)
define('DEFAULT_SSH_PORT', 22);
define('DEFAULT_PANEL_PORT', 8080);
define('DEFAULT_ADMIN_USER', 'admin');
define('DEFAULT_ADMIN_PASS', 'admin');

// Security
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 4);
define('PASSWORD_MAX_LENGTH', 64);
define('USERNAME_MIN_LENGTH', 3);
define('USERNAME_MAX_LENGTH', 32);

// Rate limiting
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 300); // 5 minutes

// Server info
function getServerIP() {
    $ip = trim(shell_exec("hostname -I 2>/dev/null | awk '{print $1}'") ?? '');
    if (empty($ip)) {
        $ip = $_SERVER['SERVER_ADDR'] ?? '0.0.0.0';
    }
    return $ip;
}
