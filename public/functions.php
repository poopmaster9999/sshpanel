<?php
/**
 * SSH Panel Manager - Core Functions (FIXED)
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
//  SSH USER MANAGEMENT (REAL SYSTEM COMMANDS)
// ============================================================

/**
 * Create a real Linux system user for SSH access
 */
function createSystemUser(string $username, string $password): array {
    // Validate username - only alphanumeric and underscore, 3-32 chars
    if (!preg_match('/^[a-z_][a-z0-9_]{2,31}$/i', $username)) {
        return ['success' => false, 'error' => 'Invalid username format. Use letters, numbers, underscore. Start with letter.'];
    }

    // Sanitize for shell
    $safeUsername = escapeshellarg($username);
    $safePassword = escapeshellarg($password);

    // Check if user already exists on system
    exec("id {$safeUsername} 2>/dev/null", $output, $returnCode);
    if ($returnCode === 0) {
        return ['success' => false, 'error' => 'System user already exists'];
    }

    // Create the sshusers group if it doesn't exist
    exec("getent group sshusers >/dev/null 2>&1 || groupadd sshusers 2>/dev/null");

    // Create system user
    // -M = no home directory
    // -N = no user group
    // -s /usr/sbin/nologin for security, but we need /bin/bash for SSH
    // Using /bin/false or /usr/sbin/nologin prevents SSH, so use /bin/bash
    $createCmd = "useradd -m -s /bin/bash -G sshusers {$safeUsername} 2>&1";
    exec($createCmd, $createOutput, $createReturn);

    if ($createReturn !== 0) {
        // Try without group
        $createCmd2 = "useradd -m -s /bin/bash {$safeUsername} 2>&1";
        exec($createCmd2, $createOutput2, $createReturn2);
        if ($createReturn2 !== 0) {
            $errorMsg = implode(' ', $createOutput2 ?: $createOutput);
            return ['success' => false, 'error' => "Failed to create user: $errorMsg"];
        }
    }

    // Set password using chpasswd
    $passCmd = "echo {$safeUsername}:{$safePassword} | chpasswd 2>&1";
    exec($passCmd, $passOutput, $passReturn);

    if ($passReturn !== 0) {
        // Try alternative method
        $passCmd2 = "echo -e {$safePassword}'\n'{$safePassword} | passwd {$safeUsername} 2>&1";
        exec($passCmd2, $passOutput2, $passReturn2);
        if ($passReturn2 !== 0) {
            // User created but password failed - delete and return error
            exec("userdel -r {$safeUsername} 2>/dev/null");
            return ['success' => false, 'error' => 'Failed to set password'];
        }
    }

    // Set up iptables rule for traffic monitoring
    $uid = trim(shell_exec("id -u {$safeUsername} 2>/dev/null") ?? '');
    if (!empty($uid) && is_numeric($uid)) {
        // Add iptables rules for traffic counting
        exec("iptables -N SSH_USER_{$username} 2>/dev/null");
        exec("iptables -A OUTPUT -m owner --uid-owner {$uid} -j ACCEPT 2>/dev/null");
        exec("iptables -A INPUT -m owner --uid-owner {$uid} -j ACCEPT 2>/dev/null");
    }

    return ['success' => true, 'message' => 'User created successfully'];
}

/**
 * Delete a system user completely
 */
function deleteSystemUser(string $username): array {
    $safeUsername = escapeshellarg($username);

    // First, kill all user processes
    exec("pkill -KILL -u {$safeUsername} 2>/dev/null");
    sleep(1);

    // Remove iptables rules
    $uid = trim(shell_exec("id -u {$safeUsername} 2>/dev/null") ?? '');
    if (!empty($uid) && is_numeric($uid)) {
        exec("iptables -D OUTPUT -m owner --uid-owner {$uid} -j ACCEPT 2>/dev/null");
        exec("iptables -D INPUT -m owner --uid-owner {$uid} -j ACCEPT 2>/dev/null");
    }

    // Delete user and home directory
    $deleteCmd = "userdel -r {$safeUsername} 2>&1";
    exec($deleteCmd, $deleteOutput, $deleteReturn);

    // Also try without -r if first fails
    if ($deleteReturn !== 0) {
        exec("userdel {$safeUsername} 2>&1", $deleteOutput2, $deleteReturn2);
    }

    return ['success' => true, 'message' => 'User deleted from system'];
}

/**
 * Change system user password
 */
function changeSystemPassword(string $username, string $password): array {
    $safeUsername = escapeshellarg($username);
    $safePassword = escapeshellarg($password);

    // Check if user exists
    exec("id {$safeUsername} 2>/dev/null", $output, $returnCode);
    if ($returnCode !== 0) {
        return ['success' => false, 'error' => 'User does not exist on system'];
    }

    $passCmd = "echo {$safeUsername}:{$safePassword} | chpasswd 2>&1";
    exec($passCmd, $passOutput, $passReturn);

    if ($passReturn !== 0) {
        return ['success' => false, 'error' => 'Failed to change password'];
    }

    return ['success' => true];
}

/**
 * Lock/unlock system user account
 */
function lockSystemUser(string $username): void {
    $safeUsername = escapeshellarg($username);
    // Lock the account
    exec("passwd -l {$safeUsername} 2>/dev/null");
    // Also use usermod to be sure
    exec("usermod -L {$safeUsername} 2>/dev/null");
    // Kill their sessions
    exec("pkill -KILL -u {$safeUsername} 2>/dev/null");
}

function unlockSystemUser(string $username): void {
    $safeUsername = escapeshellarg($username);
    exec("passwd -u {$safeUsername} 2>/dev/null");
    exec("usermod -U {$safeUsername} 2>/dev/null");
}

/**
 * Kick user - terminate all their SSH sessions
 */
function kickUser(string $username): void {
    $safeUsername = escapeshellarg($username);
    // Kill all processes owned by user
    exec("pkill -KILL -u {$safeUsername} 2>/dev/null");
    // Also try killall
    exec("killall -KILL -u {$safeUsername} 2>/dev/null");
}

/**
 * Get currently connected SSH users with their IPs
 */
function getOnlineSystemUsers(): array {
    $users = [];

    // Method 1: Use 'who' command
    $output = [];
    exec("who 2>/dev/null", $output);

    foreach ($output as $line) {
        // Format: username pts/0 2024-01-15 10:30 (192.168.1.100)
        if (preg_match('/^(\S+)\s+\S+\s+\S+\s+\S+\s*\(([^)]+)\)?/', $line, $matches)) {
            $username = $matches[1];
            $ip = isset($matches[2]) ? trim($matches[2]) : '';

            if (!isset($users[$username])) {
                $users[$username] = ['connections' => 0, 'ips' => []];
            }
            $users[$username]['connections']++;
            if (!empty($ip) && $ip !== ':0' && !in_array($ip, $users[$username]['ips'])) {
                $users[$username]['ips'][] = $ip;
            }
        }
    }

    // Method 2: Also check ss/netstat for SSH connections on port 22
    $sshPort = getSetting('ssh_port', '22');
    $ssOutput = [];
    exec("ss -tnp 2>/dev/null | grep ':{$sshPort}' | grep ESTAB", $ssOutput);

    foreach ($ssOutput as $line) {
        // Try to extract user info from ss output
        if (preg_match('/users:\(\("sshd",pid=\d+,fd=\d+\)\)/', $line)) {
            // This is an SSH connection
            if (preg_match('/([0-9.]+):\d+\s+([0-9.]+):/', $line, $ipMatch)) {
                // We have IPs but need to map to user - handled by 'who' above
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

/**
 * Create user in both system and database
 */
function createUser(array $data): array {
    // First try to create system user
    $sysResult = createSystemUser($data['username'], $data['password']);

    if (!$sysResult['success']) {
        // Check if we're running without root - still add to DB for demo mode
        $isRoot = (posix_getuid() === 0);
        if ($isRoot) {
            return $sysResult; // Return error if we're root but still failed
        }
        // Non-root mode: continue with DB only (demo mode)
    }

    // Add to database
    $dbResult = addDBUser($data);
    if (!$dbResult['success']) {
        // Rollback system user if DB fails
        if ($sysResult['success']) {
            deleteSystemUser($data['username']);
        }
        return $dbResult;
    }

    // Set up bandwidth limit if specified
    if (($data['bandwidth_limit'] ?? 0) > 0) {
        setBandwidthLimit($data['username'], $data['bandwidth_limit']);
    }

    return [
        'success' => true,
        'id' => $dbResult['id'],
        'system_created' => $sysResult['success'],
        'message' => $sysResult['success'] ? 'User created on system and database' : 'User added to database only (run as root for system user)'
    ];
}

/**
 * Remove user from both system and database
 */
function removeUser(int $id): array {
    $user = getUserById($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }

    // Delete from system first
    $sysResult = deleteSystemUser($user['username']);

    // Delete from database
    $dbResult = deleteDBUser($id);

    return [
        'success' => $dbResult['success'],
        'message' => 'User removed from system and database'
    ];
}

/**
 * Toggle user enabled/disabled state (pause/unpause)
 */
function toggleUser(int $id): array {
    $user = getUserById($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }

    $newState = $user['is_enabled'] ? 0 : 1;

    if ($newState === 1) {
        // Enabling user - unlock system account
        unlockSystemUser($user['username']);
    } else {
        // Disabling user - lock system account and kick
        lockSystemUser($user['username']);
    }

    return updateDBUser($id, ['is_enabled' => $newState]);
}

/**
 * Pause user (when data limit reached) - different from disable
 */
function pauseUser(int $id, string $reason = 'data_limit'): array {
    $user = getUserById($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }

    // Lock system account
    lockSystemUser($user['username']);

    // Update database
    return updateDBUser($id, [
        'is_enabled' => 0,
        'notes' => ($user['notes'] ?? '') . "\n[PAUSED: {$reason} at " . date('Y-m-d H:i:s') . "]"
    ]);
}

/**
 * Unpause user - re-enable after being paused
 */
function unpauseUser(int $id): array {
    $user = getUserById($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }

    // Unlock system account
    unlockSystemUser($user['username']);

    return updateDBUser($id, ['is_enabled' => 1]);
}

// ============================================================
//  BANDWIDTH LIMITING (tc/iptables)
// ============================================================

function setBandwidthLimit(string $username, int $kbps): void {
    if ($kbps <= 0) return;

    $safeUsername = escapeshellarg($username);
    $uid = trim(shell_exec("id -u {$safeUsername} 2>/dev/null") ?? '');
    if (empty($uid) || !is_numeric($uid)) return;

    // Calculate bytes per second
    $bytesPerSec = $kbps * 128; // kbps to bytes/sec

    // Using iptables with hashlimit (simpler than tc)
    // Remove existing rules first
    exec("iptables -D OUTPUT -m owner --uid-owner {$uid} -m hashlimit --hashlimit-above {$kbps}kb/s --hashlimit-mode srcip -j DROP 2>/dev/null");

    // Add new rate limit rule
    exec("iptables -I OUTPUT -m owner --uid-owner {$uid} -m hashlimit --hashlimit-above {$kbps}kb/s --hashlimit-mode srcip --hashlimit-name user_{$username} -j DROP 2>/dev/null");
}

function removeBandwidthLimit(string $username): void {
    $safeUsername = escapeshellarg($username);
    $uid = trim(shell_exec("id -u {$safeUsername} 2>/dev/null") ?? '');
    if (empty($uid) || !is_numeric($uid)) return;

    exec("iptables -D OUTPUT -m owner --uid-owner {$uid} -m hashlimit --hashlimit-above 0kb/s --hashlimit-mode srcip -j DROP 2>/dev/null");
}

// ============================================================
//  SSH PORT
// ============================================================

function changeSSHPort(int $port): array {
    if ($port < 1 || $port > 65535) {
        return ['success' => false, 'error' => 'Invalid port number'];
    }

    $configFile = '/etc/ssh/sshd_config';

    if (!file_exists($configFile)) {
        return ['success' => false, 'error' => 'SSH config file not found'];
    }

    $config = file_get_contents($configFile);
    if ($config === false) {
        return ['success' => false, 'error' => 'Cannot read SSH config'];
    }

    // Replace or add Port directive
    if (preg_match('/^Port\s+\d+/m', $config)) {
        $config = preg_replace('/^Port\s+\d+/m', "Port $port", $config);
    } elseif (preg_match('/^#Port\s+\d+/m', $config)) {
        $config = preg_replace('/^#Port\s+\d+/m', "Port $port", $config);
    } else {
        $config = "Port $port\n" . $config;
    }

    file_put_contents($configFile, $config);

    // Restart SSH service
    exec("systemctl restart sshd 2>&1 || systemctl restart ssh 2>&1", $output, $returnCode);

    setSetting('ssh_port', (string)$port);

    return [
        'success' => $returnCode === 0,
        'error' => $returnCode !== 0 ? 'Failed to restart SSH service' : ''
    ];
}

// ============================================================
//  SYNC ONLINE STATUS & CHECK LIMITS
// ============================================================

/**
 * Sync online status from system to database
 */
function syncOnlineStatus(): void {
    $onlineUsers = getOnlineSystemUsers();
    $dbUsers = getUsers();

    foreach ($dbUsers as $u) {
        $isOnline = isset($onlineUsers[$u['username']]);
        $connections = $isOnline ? $onlineUsers[$u['username']]['connections'] : 0;
        $ips = $isOnline ? implode(',', $onlineUsers[$u['username']]['ips']) : '';

        $updates = [];

        // Update online status if changed
        if ((bool)$u['is_online'] !== $isOnline) {
            $updates['is_online'] = $isOnline ? 1 : 0;
        }

        // Update connection count if changed
        if ((int)$u['current_connections'] !== $connections) {
            $updates['current_connections'] = $connections;
        }

        // Update connected IPs
        if ($u['connected_ips'] !== $ips) {
            $updates['connected_ips'] = $ips;
        }

        // Update last connected time
        if ($isOnline) {
            $updates['last_connected'] = date('Y-m-d H:i:s');
        }

        if (!empty($updates)) {
            updateDBUser($u['id'], $updates);
        }

        // Check if user should be paused (data limit reached)
        if ($u['data_limit'] > 0 && $u['data_used'] >= $u['data_limit'] && $u['is_enabled']) {
            pauseUser($u['id'], 'data_limit_reached');
        }

        // Check if user expired
        if (strtotime($u['expires_at']) < time() && $u['is_enabled']) {
            pauseUser($u['id'], 'expired');
        }

        // Check max connections exceeded - kick extra connections
        if ($connections > $u['max_connections'] && $u['is_enabled']) {
            // Just kick, don't pause - user can reconnect with fewer devices
            kickUser($u['username']);
        }
    }
}

/**
 * Check and enforce limits for all users
 */
function checkUserLimits(): void {
    $users = getUsers();
    $now = time();

    foreach ($users as $u) {
        if (!$u['is_enabled']) continue;

        $shouldPause = false;
        $reason = '';

        // Check expiry
        if (strtotime($u['expires_at']) < $now) {
            $shouldPause = true;
            $reason = 'expired';
        }

        // Check data limit
        if ($u['data_limit'] > 0 && $u['data_used'] >= $u['data_limit']) {
            $shouldPause = true;
            $reason = 'data_limit';
        }

        if ($shouldPause) {
            pauseUser($u['id'], $reason);
        }
    }
}

// ============================================================
//  BACKUPS
// ============================================================

function createBackup(): array {
    $users = getUsers();
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
    $settings = getAllSettings();
    $sql .= "-- Settings\n";
    $sql .= "DELETE FROM settings;\n";
    foreach ($settings as $key => $value) {
        if ($key === 'admin_password') continue; // Don't backup password hash
        $sql .= sprintf("INSERT INTO settings (key, value) VALUES ('%s', '%s');\n",
            SQLite3::escapeString($key), SQLite3::escapeString($value));
    }

    // Users
    $sql .= "\n-- SSH Users\n";
    $sql .= "DELETE FROM ssh_users;\n";

    foreach ($users as $u) {
        $sql .= sprintf(
            "INSERT INTO ssh_users (id, username, password, created_at, expires_at, max_connections, data_limit, data_used, bandwidth_limit, is_enabled, notes) VALUES (%d, '%s', '%s', '%s', '%s', %d, %.2f, %.2f, %d, %d, '%s');\n",
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
    $chatIds = getTelegramChatIds();
    foreach ($chatIds as $cid) {
        $sql .= sprintf("INSERT OR IGNORE INTO telegram_chat_ids (chat_id) VALUES ('%s');\n", SQLite3::escapeString($cid));
    }

    file_put_contents($filepath, $sql);
    $size = filesize($filepath);
    $sizeStr = $size > 1024 ? round($size / 1024, 1) . ' KB' : $size . ' B';

    // Record backup in database
    $db = getDB();
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
    $executed = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '--') === 0) continue;

        try {
            $db->exec($line);
            $executed++;
        } catch (Exception $e) {
            $errors++;
        }
    }

    return ['success' => true, 'executed' => $executed, 'errors' => $errors];
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
    if (empty(trim($chatId))) return false;
    $db = getDB();
    $stmt = $db->prepare("INSERT OR IGNORE INTO telegram_chat_ids (chat_id) VALUES (:chat_id)");
    $stmt->bindValue(':chat_id', trim($chatId), SQLITE3_TEXT);
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
            CURLOPT_SSL_VERIFYPEER => false,
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
    if (empty($chatIds)) {
        return ['success' => false, 'error' => 'No chat IDs configured'];
    }

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
            CURLOPT_SSL_VERIFYPEER => false,
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
    $password = $user['password'];
    $encodedPassword = urlencode($password);

    return [
        [
            'name' => 'V2Box',
            'app' => 'v2box',
            'icon' => '📦',
            'uri' => "ssh://{$username}:{$password}@{$serverIP}:{$port}#{$username}_SSH"
        ],
        [
            'name' => 'Rocket Tunnel',
            'app' => 'rocket',
            'icon' => '🚀',
            'uri' => "rocket://ssh?server={$serverIP}&port={$port}&username={$username}&password={$encodedPassword}&remark={$username}"
        ],
        [
            'name' => 'NapsternetV',
            'app' => 'napsternet',
            'icon' => '🌐',
            'uri' => "napsternetv://ssh={$serverIP}:{$port}@{$username}:{$encodedPassword}#{$username}"
        ],
        [
            'name' => 'NetMod',
            'app' => 'netmod',
            'icon' => '🔧',
            'uri' => "netmod://ssh?host={$serverIP}&port={$port}&user={$username}&pass={$encodedPassword}&name={$username}"
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
    $pausedUsers = 0;
    $now = time();

    foreach ($users as $u) {
        if ($u['is_online']) $onlineUsers++;
        $totalDataUsed += $u['data_used'];
        $activeConnections += $u['current_connections'];
        if (strtotime($u['expires_at']) < $now) $expiredUsers++;
        if (!$u['is_enabled']) $pausedUsers++;
    }

    return [
        'total_users' => $totalUsers,
        'online_users' => $onlineUsers,
        'total_data_used' => round($totalDataUsed, 2),
        'active_connections' => $activeConnections,
        'expired_users' => $expiredUsers,
        'paused_users' => $pausedUsers,
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
    $charLen = strlen($chars);
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charLen - 1)];
    }
    return $password;
}

function formatDataSize(float $mb): string {
    if ($mb >= 1024) {
        return round($mb / 1024, 2) . ' GB';
    }
    return round($mb, 1) . ' MB';
}

function getDaysLeft(string $expiresAt): int {
    $diff = strtotime($expiresAt) - time();
    return (int)ceil($diff / 86400);
}

function getServerIP(): string {
    // Try multiple methods to get server IP
    $ip = '';

    // Method 1: hostname -I
    $ip = trim(shell_exec("hostname -I 2>/dev/null | awk '{print $1}'") ?? '');

    // Method 2: ip route
    if (empty($ip)) {
        $ip = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print $7}' | head -1") ?? '');
    }

    // Method 3: curl external service
    if (empty($ip)) {
        $ip = trim(shell_exec("curl -s --max-time 2 ifconfig.me 2>/dev/null") ?? '');
    }

    // Method 4: PHP server variable
    if (empty($ip)) {
        $ip = $_SERVER['SERVER_ADDR'] ?? '';
    }

    // Fallback
    if (empty($ip)) {
        $ip = '0.0.0.0';
    }

    return $ip;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function isRunningAsRoot(): bool {
    return function_exists('posix_getuid') && posix_getuid() === 0;
}
