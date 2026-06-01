<?php
/**
 * SSH Panel Manager - Core Functions
 */

require_once __DIR__ . '/config.php';

// ============================================================
//  DATABASE
// ============================================================

function getDB(): SQLite3 {
    static $db = null;
    if ($db === null) {
        if (!file_exists(DB_PATH)) {
            require_once __DIR__ . '/init_db.php';
        }
        $db = new SQLite3(DB_PATH);
        $db->exec('PRAGMA journal_mode=WAL;');
        $db->exec('PRAGMA foreign_keys=ON;');
    }
    return $db;
}

// ============================================================
//  SETTINGS
// ============================================================

function getSetting(string $key, string $default = ''): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = :key");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['value'] : $default;
}

function setSetting(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (:key, :value, datetime('now'))");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function getAllSettings(): array {
    $db = getDB();
    $result = $db->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

// ============================================================
//  SSH USER MANAGEMENT (SYSTEM-LEVEL)
// ============================================================

function createSystemUser(string $username, string $password): array {
    // Validate username
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        return ['success' => false, 'error' => 'Invalid username. Use only letters, numbers, underscore. 3-32 chars.'];
    }

    // Check if user exists on system
    $checkCmd = "id {$username} 2>/dev/null";
    exec($checkCmd, $output, $returnCode);
    if ($returnCode === 0) {
        return ['success' => false, 'error' => 'System user already exists'];
    }

    // Create system user with no home dir login shell restricted
    $cmd = sprintf(
        "useradd -M -s /bin/false -G sshusers %s 2>&1",
        escapeshellarg($username)
    );

    // Ensure sshusers group exists
    exec("groupadd -f sshusers 2>/dev/null");

    exec($cmd, $output, $returnCode);
    if ($returnCode !== 0) {
        // Try without group if sshusers fails
        $cmd2 = sprintf("useradd -M -s /bin/false %s 2>&1", escapeshellarg($username));
        exec($cmd2, $output2, $returnCode2);
        if ($returnCode2 !== 0) {
            return ['success' => false, 'error' => 'Failed to create system user: ' . implode(' ', $output2)];
        }
    }

    // Set password
    $passCmd = sprintf(
        "echo '%s:%s' | chpasswd 2>&1",
        $username,
        $password
    );
    exec($passCmd, $passOutput, $passReturn);
    if ($passReturn !== 0) {
        return ['success' => false, 'error' => 'Failed to set password'];
    }

    return ['success' => true];
}

function deleteSystemUser(string $username): array {
    // Kill user sessions first
    exec(sprintf("pkill -u %s 2>/dev/null", escapeshellarg($username)));
    sleep(1);

    // Delete user
    $cmd = sprintf("userdel %s 2>&1", escapeshellarg($username));
    exec($cmd, $output, $returnCode);

    // Remove iptables rules
    exec(sprintf("iptables -D OUTPUT -m owner --uid-owner $(id -u %s 2>/dev/null) -j ACCEPT 2>/dev/null", escapeshellarg($username)));

    return ['success' => true];
}

function changeSystemPassword(string $username, string $password): array {
    $cmd = sprintf("echo '%s:%s' | chpasswd 2>&1", $username, $password);
    exec($cmd, $output, $returnCode);
    if ($returnCode !== 0) {
        return ['success' => false, 'error' => 'Failed to change password'];
    }
    return ['success' => true];
}

function enableSystemUser(string $username): void {
    exec(sprintf("usermod -U %s 2>/dev/null", escapeshellarg($username)));
}

function disableSystemUser(string $username): void {
    exec(sprintf("usermod -L %s 2>/dev/null", escapeshellarg($username)));
    exec(sprintf("pkill -u %s 2>/dev/null", escapeshellarg($username)));
}

function kickUser(string $username): void {
    exec(sprintf("pkill -u %s 2>/dev/null", escapeshellarg($username)));
}

function getOnlineSystemUsers(): array {
    $output = [];
    exec("who 2>/dev/null", $output);
    $users = [];
    foreach ($output as $line) {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 5) {
            $username = $parts[0];
            $ip = trim($parts[4] ?? '', '()');
            if (!isset($users[$username])) {
                $users[$username] = ['connections' => 0, 'ips' => []];
            }
            $users[$username]['connections']++;
            if ($ip && !in_array($ip, $users[$username]['ips'])) {
                $users[$username]['ips'][] = $ip;
            }
        }
    }
    return $users;
}

// ============================================================
//  DATABASE USER OPERATIONS
// ============================================================

function getUsers(): array {
    $db = getDB();
    $result = $db->query("SELECT * FROM ssh_users ORDER BY created_at DESC");
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}

function getUserById(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM ssh_users WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function getUserByUsername(string $username): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM ssh_users WHERE username = :username");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function addDBUser(array $data): array {
    $db = getDB();

    // Check duplicate
    $existing = getUserByUsername($data['username']);
    if ($existing) {
        return ['success' => false, 'error' => 'Username already exists in database'];
    }

    $stmt = $db->prepare("
        INSERT INTO ssh_users (username, password, expires_at, max_connections, data_limit, bandwidth_limit, is_enabled, notes)
        VALUES (:username, :password, :expires_at, :max_connections, :data_limit, :bandwidth_limit, :is_enabled, :notes)
    ");
    $stmt->bindValue(':username', $data['username'], SQLITE3_TEXT);
    $stmt->bindValue(':password', $data['password'], SQLITE3_TEXT);
    $stmt->bindValue(':expires_at', $data['expires_at'], SQLITE3_TEXT);
    $stmt->bindValue(':max_connections', $data['max_connections'] ?? 1, SQLITE3_INTEGER);
    $stmt->bindValue(':data_limit', $data['data_limit'] ?? 0, SQLITE3_FLOAT);
    $stmt->bindValue(':bandwidth_limit', $data['bandwidth_limit'] ?? 0, SQLITE3_INTEGER);
    $stmt->bindValue(':is_enabled', $data['is_enabled'] ?? 1, SQLITE3_INTEGER);
    $stmt->bindValue(':notes', $data['notes'] ?? '', SQLITE3_TEXT);
    $stmt->execute();

    $id = $db->lastInsertRowID();
    return ['success' => true, 'id' => $id];
}

function updateDBUser(int $id, array $data): array {
    $db = getDB();
    $sets = [];
    $params = [];

    $allowed = ['username', 'password', 'expires_at', 'max_connections', 'data_limit', 'data_used', 'bandwidth_limit', 'is_enabled', 'is_online', 'current_connections', 'connected_ips', 'last_connected', 'notes'];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed)) {
            $sets[] = "$key = :$key";
            $params[$key] = $value;
        }
    }

    if (empty($sets)) {
        return ['success' => false, 'error' => 'No valid fields to update'];
    }

    $sql = "UPDATE ssh_users SET " . implode(', ', $sets) . " WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    foreach ($params as $key => $value) {
        if (is_int($value)) {
            $stmt->bindValue(":$key", $value, SQLITE3_INTEGER);
        } elseif (is_float($value)) {
            $stmt->bindValue(":$key", $value, SQLITE3_FLOAT);
        } else {
            $stmt->bindValue(":$key", (string)$value, SQLITE3_TEXT);
        }
    }
    $stmt->execute();

    return ['success' => true];
}

function deleteDBUser(int $id): array {
    $db = getDB();
    $user = getUserById($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }

    $stmt = $db->prepare("DELETE FROM ssh_users WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    return ['success' => true, 'username' => $user['username']];
}

// ============================================================
//  FULL CREATE / DELETE (System + DB)
// ============================================================

function createUser(array $data): array {
    // Create system user
    $sysResult = createSystemUser($data['username'], $data['password']);
    // Even if system user creation fails (e.g., not root), still add to DB
    // so panel works in demo/non-root mode too

    // Add to database
    $dbResult = addDBUser($data);
    if (!$dbResult['success']) {
        // Rollback system user if DB fails
        if ($sysResult['success']) {
            deleteSystemUser($data['username']);
        }
        return $dbResult;
    }

    // Set up bandwidth limit with tc if specified
    if (($data['bandwidth_limit'] ?? 0) > 0) {
        setBandwidthLimit($data['username'], $data['bandwidth_limit']);
    }

    return ['success' => true, 'id' => $dbResult['id'], 'system_created' => $sysResult['success']];
}

function removeUser(int $id): array {
    $user = getUserById($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }

    // Delete from system
    deleteSystemUser($user['username']);

    // Delete from database
    return deleteDBUser($id);
}

function toggleUser(int $id): array {
    $user = getUserById($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }

    $newState = $user['is_enabled'] ? 0 : 1;
    if ($newState) {
        enableSystemUser($user['username']);
    } else {
        disableSystemUser($user['username']);
    }

    return updateDBUser($id, ['is_enabled' => $newState]);
}

// ============================================================
//  BANDWIDTH LIMITING
// ============================================================

function setBandwidthLimit(string $username, int $kbps): void {
    if ($kbps <= 0) return;
    // Using tc (traffic control) - requires root
    $uid = trim(shell_exec(sprintf("id -u %s 2>/dev/null", escapeshellarg($username))) ?? '');
    if (empty($uid)) return;

    // This is a simplified version - production would use more sophisticated tc rules
    $cmd = sprintf(
        "iptables -A OUTPUT -m owner --uid-owner %s -m limit --limit %dkb/s -j ACCEPT 2>/dev/null",
        $uid, $kbps / 8
    );
    exec($cmd);
}

// ============================================================
//  SSH PORT
// ============================================================

function changeSSHPort(int $port): array {
    if ($port < 1 || $port > 65535) {
        return ['success' => false, 'error' => 'Invalid port number'];
    }

    // Update sshd_config
    $config = file_get_contents('/etc/ssh/sshd_config');
    if ($config === false) {
        return ['success' => false, 'error' => 'Cannot read SSH config'];
    }

    $config = preg_replace('/^#?Port\s+\d+/m', "Port $port", $config);
    if (strpos($config, "Port $port") === false) {
        $config = "Port $port\n" . $config;
    }

    file_put_contents('/etc/ssh/sshd_config', $config);
    exec("systemctl restart ssh 2>&1", $output, $returnCode);

    setSetting('ssh_port', (string)$port);

    return ['success' => $returnCode === 0, 'error' => $returnCode !== 0 ? 'Failed to restart SSH' : ''];
}

// ============================================================
//  BACKUPS
// ============================================================

function createBackup(): array {
    $db = getDB();
    $users = getUsers();
    $settings = getAllSettings();
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "ssh_panel_backup_{$timestamp}.sql";
    $filepath = BACKUP_DIR . '/' . $filename;

    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0777, true);
    }

    $sql = "-- SSH Panel Manager Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Version: " . PANEL_VERSION . "\n\n";

    // Settings
    $sql .= "-- Settings\n";
    $sql .= "CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at DATETIME);\n";
    $sql .= "DELETE FROM settings;\n";
    foreach ($settings as $key => $value) {
        $sql .= sprintf("INSERT INTO settings (key, value) VALUES ('%s', '%s');\n",
            SQLite3::escapeString($key), SQLite3::escapeString($value));
    }

    // Users
    $sql .= "\n-- SSH Users\n";
    $sql .= "CREATE TABLE IF NOT EXISTS ssh_users (\n";
    $sql .= "  id INTEGER PRIMARY KEY AUTOINCREMENT,\n";
    $sql .= "  username TEXT UNIQUE NOT NULL,\n";
    $sql .= "  password TEXT NOT NULL,\n";
    $sql .= "  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n";
    $sql .= "  expires_at DATETIME NOT NULL,\n";
    $sql .= "  max_connections INTEGER DEFAULT 1,\n";
    $sql .= "  data_limit REAL DEFAULT 0,\n";
    $sql .= "  data_used REAL DEFAULT 0,\n";
    $sql .= "  bandwidth_limit INTEGER DEFAULT 0,\n";
    $sql .= "  is_enabled INTEGER DEFAULT 1,\n";
    $sql .= "  notes TEXT DEFAULT ''\n";
    $sql .= ");\n";
    $sql .= "DELETE FROM ssh_users;\n";

    foreach ($users as $u) {
        $sql .= sprintf(
            "INSERT INTO ssh_users (id, username, password, created_at, expires_at, max_connections, data_limit, data_used, bandwidth_limit, is_enabled, notes) VALUES (%d, '%s', '%s', '%s', '%s', %d, %s, %s, %d, %d, '%s');\n",
            $u['id'],
            SQLite3::escapeString($u['username']),
            SQLite3::escapeString($u['password']),
            $u['created_at'],
            $u['expires_at'],
            $u['max_connections'],
            $u['data_limit'],
            $u['data_used'],
            $u['bandwidth_limit'],
            $u['is_enabled'],
            SQLite3::escapeString($u['notes'] ?? '')
        );
    }

    // Telegram chat IDs
    $sql .= "\n-- Telegram Chat IDs\n";
    $sql .= "CREATE TABLE IF NOT EXISTS telegram_chat_ids (id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id TEXT UNIQUE NOT NULL);\n";
    $sql .= "DELETE FROM telegram_chat_ids;\n";
    $chatIds = getTelegramChatIds();
    foreach ($chatIds as $cid) {
        $sql .= sprintf("INSERT INTO telegram_chat_ids (chat_id) VALUES ('%s');\n", SQLite3::escapeString($cid));
    }

    file_put_contents($filepath, $sql);
    $size = filesize($filepath);
    $sizeStr = $size > 1024 ? round($size / 1024, 1) . ' KB' : $size . ' B';

    // Record backup
    $stmt = $db->prepare("INSERT INTO backups (filename, size, type) VALUES (:filename, :size, :type)");
    $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
    $stmt->bindValue(':size', $sizeStr, SQLITE3_TEXT);
    $stmt->bindValue(':type', 'manual', SQLITE3_TEXT);
    $stmt->execute();

    return ['success' => true, 'filename' => $filename, 'filepath' => $filepath, 'size' => $sizeStr];
}

function getBackups(): array {
    $db = getDB();
    $result = $db->query("SELECT * FROM backups ORDER BY created_at DESC LIMIT 20");
    $backups = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $backups[] = $row;
    }
    return $backups;
}

function restoreBackup(string $sqlContent): array {
    $db = getDB();
    $lines = explode("\n", $sqlContent);
    $errors = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '--') === 0) continue;
        try {
            $db->exec($line);
        } catch (Exception $e) {
            $errors++;
        }
    }
    return ['success' => true, 'errors' => $errors];
}

// ============================================================
//  TELEGRAM
// ============================================================

function getTelegramChatIds(): array {
    $db = getDB();
    $result = $db->query("SELECT chat_id FROM telegram_chat_ids");
    $ids = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ids[] = $row['chat_id'];
    }
    return $ids;
}

function addTelegramChatId(string $chatId): bool {
    $db = getDB();
    $stmt = $db->prepare("INSERT OR IGNORE INTO telegram_chat_ids (chat_id) VALUES (:chat_id)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->execute();
    return $db->changes() > 0;
}

function removeTelegramChatId(string $chatId): bool {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM telegram_chat_ids WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->execute();
    return $db->changes() > 0;
}

function sendTelegramMessage(string $message): array {
    $token = getSetting('telegram_bot_token');
    if (empty($token)) {
        return ['success' => false, 'error' => 'Bot token not configured'];
    }

    $chatIds = getTelegramChatIds();
    if (empty($chatIds)) {
        return ['success' => false, 'error' => 'No chat IDs configured'];
    }

    $results = [];
    foreach ($chatIds as $chatId) {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $results[] = ['chat_id' => $chatId, 'success' => $httpCode === 200, 'response' => $response];
    }

    return ['success' => true, 'results' => $results];
}

function sendTelegramBackup(): array {
    $backup = createBackup();
    if (!$backup['success']) {
        return $backup;
    }

    $token = getSetting('telegram_bot_token');
    if (empty($token)) {
        return ['success' => false, 'error' => 'Bot token not configured'];
    }

    $chatIds = getTelegramChatIds();
    foreach ($chatIds as $chatId) {
        $url = "https://api.telegram.org/bot{$token}/sendDocument";

        $ch = curl_init();
        $postData = [
            'chat_id' => $chatId,
            'document' => new CURLFile($backup['filepath'], 'application/sql', $backup['filename']),
            'caption' => "📦 SSH Panel Backup\n📅 " . date('Y-m-d H:i:s') . "\n📊 " . $backup['size'],
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    setSetting('telegram_last_backup', date('Y-m-d H:i:s'));

    return ['success' => true, 'filename' => $backup['filename']];
}

// ============================================================
//  QR CODE / CONNECTION CONFIGS
// ============================================================

function generateConnectionConfigs(array $user): array {
    $port = getSetting('ssh_port', '22');
    $serverIP = getSetting('server_ip', '');
    if (empty($serverIP)) {
        $serverIP = getServerIP();
    }

    $username = $user['username'];
    $password = urlencode($user['password']);
    $rawPassword = $user['password'];

    return [
        [
            'name' => 'V2Box',
            'app' => 'v2box',
            'icon' => '📦',
            'uri' => "ssh://{$username}:{$rawPassword}@{$serverIP}:{$port}#{$username}_SSH"
        ],
        [
            'name' => 'Rocket Tunnel',
            'app' => 'rocket',
            'icon' => '🚀',
            'uri' => "rocket://ssh?server={$serverIP}&port={$port}&username={$username}&password={$password}&remark={$username}"
        ],
        [
            'name' => 'NapsternetV',
            'app' => 'napsternet',
            'icon' => '🌐',
            'uri' => "napsternetv://ssh={$serverIP}:{$port}@{$username}:{$password}#{$username}"
        ],
        [
            'name' => 'NetMod',
            'app' => 'netmod',
            'icon' => '🔧',
            'uri' => "netmod://ssh?host={$serverIP}&port={$port}&user={$username}&pass={$password}&name={$username}"
        ],
    ];
}

// ============================================================
//  STATS
// ============================================================

function getPanelStats(): array {
    $users = getUsers();
    $totalUsers = count($users);
    $onlineUsers = 0;
    $totalDataUsed = 0;
    $activeConnections = 0;
    $expiredUsers = 0;
    $now = time();

    foreach ($users as $u) {
        if ($u['is_online']) $onlineUsers++;
        $totalDataUsed += $u['data_used'];
        $activeConnections += $u['current_connections'];
        if (strtotime($u['expires_at']) < $now) $expiredUsers++;
    }

    return [
        'total_users' => $totalUsers,
        'online_users' => $onlineUsers,
        'total_data_used' => round($totalDataUsed, 2),
        'active_connections' => $activeConnections,
        'expired_users' => $expiredUsers,
    ];
}

function getTopDataUsers(int $limit = 6): array {
    $db = getDB();
    $result = $db->query("SELECT username, data_used FROM ssh_users ORDER BY data_used DESC LIMIT $limit");
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}

// ============================================================
//  HELPERS
// ============================================================

function generateRandomPassword(int $length = 12): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function formatDataSize(float $mb): string {
    if ($mb >= 1024) {
        return round($mb / 1024, 1) . ' GB';
    }
    return round($mb) . ' MB';
}

function getDaysLeft(string $expiresAt): int {
    $diff = strtotime($expiresAt) - time();
    return (int)ceil($diff / 86400);
}

function generateCSRFToken(): string {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCSRFToken(string $token): bool {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function syncOnlineStatus(): void {
    $onlineUsers = getOnlineSystemUsers();
    $dbUsers = getUsers();

    foreach ($dbUsers as $u) {
        $isOnline = isset($onlineUsers[$u['username']]);
        $connections = $isOnline ? $onlineUsers[$u['username']]['connections'] : 0;
        $ips = $isOnline ? implode(',', $onlineUsers[$u['username']]['ips']) : '';

        if ($u['is_online'] != $isOnline || $u['current_connections'] != $connections) {
            $updates = [
                'is_online' => $isOnline ? 1 : 0,
                'current_connections' => $connections,
                'connected_ips' => $ips,
            ];
            if ($isOnline) {
                $updates['last_connected'] = date('Y-m-d H:i:s');
            }
            updateDBUser($u['id'], $updates);
        }
    }
}
