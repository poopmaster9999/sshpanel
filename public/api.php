<?php
/**
 * SSH Panel Manager - API Endpoints (FIXED)
 */

session_start();
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Public endpoints (no auth required)
$publicActions = ['user_login', 'user_info'];

// Check admin auth for non-public endpoints
if (!in_array($action, $publicActions)) {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }
}

// Sync online status
try {
    syncOnlineStatus();
} catch (Exception $e) {
    // Continue even if sync fails
}

switch ($action) {

    // ============================================================
    //  AUTH
    // ============================================================

    case 'admin_login':
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $adminUser = getSetting('admin_username', 'admin');
        $adminPass = getSetting('admin_password', '');

        $valid = false;
        if ($username === $adminUser) {
            if (!empty($adminPass) && password_verify($password, $adminPass)) {
                $valid = true;
            }
            if ($username === 'admin' && $password === 'admin' && (empty($adminPass) || $adminPass === 'admin')) {
                $valid = true;
            }
        }

        if ($valid) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            jsonResponse(['success' => true]);
        }
        jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
        break;

    case 'admin_logout':
        session_destroy();
        jsonResponse(['success' => true]);
        break;

    case 'user_login':
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $user = getUserByUsername($username);
        if ($user && $user['password'] === $password) {
            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_username'] = $user['username'];
            unset($user['password']); // Don't send password back
            jsonResponse(['success' => true, 'user' => $user]);
        }
        jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
        break;

    case 'user_info':
        if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
            jsonResponse(['success' => false, 'error' => 'Not logged in'], 401);
        }
        $user = getUserById($_SESSION['user_id']);
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'User not found'], 404);
        }
        $configs = generateConnectionConfigs($user);
        unset($user['password']);
        jsonResponse(['success' => true, 'user' => $user, 'configs' => $configs]);
        break;

    // ============================================================
    //  USERS
    // ============================================================

    case 'get_users':
        $users = getUsers();
        // Remove passwords from response
        foreach ($users as &$u) {
            unset($u['password']);
        }
        jsonResponse(['success' => true, 'users' => $users]);
        break;

    case 'get_user':
        $id = (int)($_GET['id'] ?? 0);
        $user = getUserById($id);
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Not found'], 404);
        }
        jsonResponse(['success' => true, 'user' => $user]);
        break;

    case 'create_user':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            $password = generateRandomPassword();
        }

        if (strlen($username) < 3) {
            jsonResponse(['success' => false, 'error' => 'Username must be at least 3 characters'], 400);
        }

        $data = [
            'username' => $username,
            'password' => $password,
            'expires_at' => date('Y-m-d H:i:s', time() + (int)($_POST['days'] ?? 30) * 86400),
            'max_connections' => (int)($_POST['max_connections'] ?? 1),
            'data_limit' => (float)($_POST['data_limit'] ?? 0),
            'bandwidth_limit' => (int)($_POST['bandwidth_limit'] ?? 0),
            'is_enabled' => 1,
            'notes' => $_POST['notes'] ?? '',
        ];

        $result = createUser($data);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    case 'update_user':
        $id = (int)($_POST['id'] ?? 0);
        $user = getUserById($id);
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Not found'], 404);
        }

        $data = [];
        if (isset($_POST['username'])) $data['username'] = trim($_POST['username']);
        if (!empty($_POST['password'])) {
            $data['password'] = $_POST['password'];
            changeSystemPassword($user['username'], $_POST['password']);
        }
        if (isset($_POST['days'])) {
            $data['expires_at'] = date('Y-m-d H:i:s', time() + (int)$_POST['days'] * 86400);
        }
        if (isset($_POST['max_connections'])) $data['max_connections'] = (int)$_POST['max_connections'];
        if (isset($_POST['data_limit'])) $data['data_limit'] = (float)$_POST['data_limit'];
        if (isset($_POST['data_used'])) $data['data_used'] = (float)$_POST['data_used'];
        if (isset($_POST['bandwidth_limit'])) {
            $data['bandwidth_limit'] = (int)$_POST['bandwidth_limit'];
            setBandwidthLimit($user['username'], (int)$_POST['bandwidth_limit']);
        }
        if (isset($_POST['notes'])) $data['notes'] = $_POST['notes'];

        $result = updateDBUser($id, $data);
        jsonResponse($result);
        break;

    case 'delete_user':
        $id = (int)($_POST['id'] ?? 0);
        $result = removeUser($id);
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    case 'toggle_user':
        $id = (int)($_POST['id'] ?? 0);
        $result = toggleUser($id);
        jsonResponse($result);
        break;

    case 'kick_user':
        $id = (int)($_POST['id'] ?? 0);
        $user = getUserById($id);
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Not found'], 404);
        }
        kickUser($user['username']);
        updateDBUser($id, ['is_online' => 0, 'current_connections' => 0, 'connected_ips' => '']);
        jsonResponse(['success' => true, 'message' => 'User kicked']);
        break;

    case 'reset_data':
        $id = (int)($_POST['id'] ?? 0);
        $result = updateDBUser($id, ['data_used' => 0]);
        jsonResponse($result);
        break;

    case 'generate_password':
        $length = (int)($_GET['length'] ?? 12);
        if ($length < 6) $length = 6;
        if ($length > 32) $length = 32;
        jsonResponse(['success' => true, 'password' => generateRandomPassword($length)]);
        break;

    case 'get_qr_configs':
        $id = (int)($_GET['id'] ?? 0);
        $user = getUserById($id);
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'User not found'], 404);
        }
        $configs = generateConnectionConfigs($user);
        jsonResponse(['success' => true, 'configs' => $configs, 'user' => $user['username']]);
        break;

    // ============================================================
    //  SETTINGS
    // ============================================================

    case 'get_settings':
        $settings = getAllSettings();
        $settings['admin_password'] = '********'; // Hide password
        $settings['telegram_chat_ids'] = getTelegramChatIds();
        $settings['backups'] = getBackups();
        $settings['server_ip'] = getServerIP();
        jsonResponse(['success' => true, 'settings' => $settings]);
        break;

    case 'save_settings':
        if (isset($_POST['ssh_port'])) {
            $port = (int)$_POST['ssh_port'];
            $portResult = changeSSHPort($port);
            if (!$portResult['success']) {
                jsonResponse(['success' => false, 'error' => 'SSH port change failed: ' . $portResult['error']], 400);
            }
        }
        if (isset($_POST['panel_port'])) setSetting('panel_port', $_POST['panel_port']);
        if (isset($_POST['admin_username'])) setSetting('admin_username', $_POST['admin_username']);
        if (isset($_POST['admin_password']) && $_POST['admin_password'] !== '********' && !empty($_POST['admin_password'])) {
            setSetting('admin_password', password_hash($_POST['admin_password'], PASSWORD_DEFAULT));
        }
        if (isset($_POST['telegram_bot_token'])) setSetting('telegram_bot_token', $_POST['telegram_bot_token']);
        if (isset($_POST['telegram_backup_enabled'])) setSetting('telegram_backup_enabled', $_POST['telegram_backup_enabled']);
        if (isset($_POST['telegram_backup_interval'])) setSetting('telegram_backup_interval', $_POST['telegram_backup_interval']);
        if (isset($_POST['server_ip'])) setSetting('server_ip', $_POST['server_ip']);

        jsonResponse(['success' => true, 'message' => 'Settings saved']);
        break;

    case 'add_chat_id':
        $chatId = trim($_POST['chat_id'] ?? '');
        if (empty($chatId)) {
            jsonResponse(['success' => false, 'error' => 'Empty chat ID'], 400);
        }
        $result = addTelegramChatId($chatId);
        jsonResponse(['success' => $result, 'message' => $result ? 'Chat ID added' : 'Chat ID already exists']);
        break;

    case 'remove_chat_id':
        $chatId = trim($_POST['chat_id'] ?? '');
        $result = removeTelegramChatId($chatId);
        jsonResponse(['success' => $result]);
        break;

    case 'test_telegram':
        $stats = getPanelStats();
        $message = "✅ <b>SSH Panel Test Message</b>\n\n";
        $message .= "📅 " . date('Y-m-d H:i:s') . "\n";
        $message .= "🖥 Server: " . getServerIP() . "\n";
        $message .= "👥 Users: {$stats['total_users']}\n";
        $message .= "🟢 Online: {$stats['online_users']}\n";
        $message .= "📊 Data: " . formatDataSize($stats['total_data_used']);
        $result = sendTelegramMessage($message);
        jsonResponse($result);
        break;

    // ============================================================
    //  BACKUPS
    // ============================================================

    case 'create_backup':
        $result = createBackup();
        jsonResponse($result);
        break;

    case 'download_backup':
        $filename = basename($_GET['filename'] ?? '');
        $filepath = BACKUP_DIR . '/' . $filename;
        if (!file_exists($filepath)) {
            jsonResponse(['success' => false, 'error' => 'Backup not found'], 404);
        }
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
        break;

    case 'restore_backup':
        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== 0) {
            jsonResponse(['success' => false, 'error' => 'No file uploaded'], 400);
        }
        $content = file_get_contents($_FILES['backup_file']['tmp_name']);
        $result = restoreBackup($content);
        jsonResponse($result);
        break;

    case 'telegram_backup':
        $result = sendTelegramBackup();
        jsonResponse($result);
        break;

    case 'get_backups':
        jsonResponse(['success' => true, 'backups' => getBackups()]);
        break;

    // ============================================================
    //  STATS
    // ============================================================

    case 'get_stats':
        $stats = getPanelStats();
        $stats['top_users'] = getTopDataUsers();
        $stats['server_ip'] = getServerIP();
        $stats['ssh_port'] = getSetting('ssh_port', '22');
        $stats['uptime'] = trim(shell_exec("uptime -p 2>/dev/null") ?? 'N/A');
        $stats['os'] = trim(shell_exec("lsb_release -ds 2>/dev/null") ?? 'Ubuntu');
        $stats['is_root'] = isRunningAsRoot();
        jsonResponse(['success' => true, 'stats' => $stats]);
        break;

    case 'get_online_users':
        syncOnlineStatus();
        $db = getDB();
        $result = $db->query("SELECT * FROM ssh_users WHERE is_online = 1");
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            unset($row['password']);
            $users[] = $row;
        }
        jsonResponse(['success' => true, 'users' => $users]);
        break;

    case 'sync_status':
        syncOnlineStatus();
        checkUserLimits();
        jsonResponse(['success' => true, 'message' => 'Status synced']);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action: ' . $action], 400);
}
