<?php
/**
 * SSH Panel Manager - Database Initialization
 */

require_once __DIR__ . '/config.php';

$dbDir = dirname(DB_PATH);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$db = new SQLite3(DB_PATH);
$db->exec('PRAGMA journal_mode=WAL;');
$db->exec('PRAGMA foreign_keys=ON;');

// Create tables
$db->exec("
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

$db->exec("
CREATE TABLE IF NOT EXISTS ssh_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    max_connections INTEGER DEFAULT 1,
    current_connections INTEGER DEFAULT 0,
    data_limit REAL DEFAULT 0,
    data_used REAL DEFAULT 0,
    bandwidth_limit INTEGER DEFAULT 0,
    is_online INTEGER DEFAULT 0,
    is_enabled INTEGER DEFAULT 1,
    last_connected DATETIME,
    connected_ips TEXT DEFAULT '',
    notes TEXT DEFAULT ''
);
");

$db->exec("
CREATE TABLE IF NOT EXISTS backups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    size TEXT DEFAULT '',
    type TEXT DEFAULT 'manual'
);
");

$db->exec("
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    success INTEGER DEFAULT 0
);
");

$db->exec("
CREATE TABLE IF NOT EXISTS telegram_chat_ids (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT UNIQUE NOT NULL,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Insert default settings if not exist
$defaults = [
    'ssh_port' => DEFAULT_SSH_PORT,
    'panel_port' => DEFAULT_PANEL_PORT,
    'admin_username' => DEFAULT_ADMIN_USER,
    'admin_password' => password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT),
    'telegram_bot_token' => '',
    'telegram_backup_enabled' => '0',
    'telegram_backup_interval' => '24',
    'telegram_last_backup' => '',
    'server_ip' => '',
];

foreach ($defaults as $key => $value) {
    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', (string)$value, SQLITE3_TEXT);
    $stmt->execute();
}

$db->close();

echo "Database initialized successfully at: " . DB_PATH . "\n";
