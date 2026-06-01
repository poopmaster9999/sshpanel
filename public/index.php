<?php
/**
 * SSH Panel Manager - Main Entry Point (FIXED)
 */

session_start();

// Initialize database if needed
if (!file_exists(__DIR__ . '/database/panel.db')) {
    require_once __DIR__ . '/init_db.php';
}

require_once __DIR__ . '/functions.php';

// Route handling
$page = $_GET['page'] ?? 'login';
$isAdminLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isUserLoggedIn = isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;

// Handle logout
if ($page === 'logout') {
    session_destroy();
    header('Location: ?page=login');
    exit;
}

// Handle user logout
if ($page === 'user_logout') {
    unset($_SESSION['user_logged_in'], $_SESSION['user_id'], $_SESSION['user_username']);
    header('Location: ?page=user_login');
    exit;
}

// Handle admin login POST
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $adminUser = getSetting('admin_username', 'admin');
    $adminPassHash = getSetting('admin_password', '');

    $valid = false;
    if ($username === $adminUser) {
        if (!empty($adminPassHash) && password_verify($password, $adminPassHash)) {
            $valid = true;
        }
        // Default credentials on first run
        if ($username === 'admin' && $password === 'admin' && (empty($adminPassHash) || $adminPassHash === 'admin')) {
            $valid = true;
            setSetting('admin_password', password_hash('admin', PASSWORD_DEFAULT));
        }
    }

    if ($valid) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        header('Location: ?page=dashboard');
        exit;
    } else {
        $loginError = 'Invalid username or password';
    }
}

// Handle user login POST
if ($page === 'user_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $user = getUserByUsername($username);
    if ($user && $user['password'] === $password) {
        $_SESSION['user_logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_username'] = $user['username'];
        header('Location: ?page=my_account');
        exit;
    } else {
        $userLoginError = 'Invalid username or password';
    }
}

// Redirect logic
if ($isAdminLoggedIn && $page === 'login') {
    header('Location: ?page=dashboard');
    exit;
}

$adminPages = ['dashboard', 'users', 'online', 'settings'];
if (in_array($page, $adminPages) && !$isAdminLoggedIn) {
    header('Location: ?page=login');
    exit;
}

if ($page === 'my_account' && !$isUserLoggedIn) {
    header('Location: ?page=user_login');
    exit;
}

// Handle admin actions via POST
if ($isAdminLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            $password = generateRandomPassword();
        }
        $days = (int)($_POST['days'] ?? 30);
        $result = createUser([
            'username' => $username,
            'password' => $password,
            'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
            'max_connections' => (int)($_POST['max_connections'] ?? 1),
            'data_limit' => (float)($_POST['data_limit'] ?? 0),
            'bandwidth_limit' => (int)($_POST['bandwidth_limit'] ?? 0),
            'is_enabled' => 1,
        ]);
        $_SESSION['flash'] = $result['success']
            ? "User '{$username}' created! " . ($result['system_created'] ? '(System + DB)' : '(DB only - run as root for system user)')
            : ('Error: ' . ($result['error'] ?? 'Unknown'));
        header('Location: ?page=users');
        exit;
    }

    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        $user = getUserById($id);
        $result = removeUser($id);
        $_SESSION['flash'] = $result['success']
            ? "User '{$user['username']}' deleted from system and database."
            : ('Error: ' . ($result['error'] ?? 'Unknown'));
        header('Location: ?page=users');
        exit;
    }

    if ($action === 'toggle_user') {
        $id = (int)($_POST['id'] ?? 0);
        $user = getUserById($id);
        toggleUser($id);
        $newState = $user['is_enabled'] ? 'paused' : 'enabled';
        $_SESSION['flash'] = "User '{$user['username']}' {$newState}.";
        header('Location: ?page=users');
        exit;
    }

    if ($action === 'kick_user') {
        $id = (int)($_POST['id'] ?? 0);
        $user = getUserById($id);
        if ($user) {
            kickUser($user['username']);
            updateDBUser($id, ['is_online' => 0, 'current_connections' => 0, 'connected_ips' => '']);
            $_SESSION['flash'] = "User '{$user['username']}' kicked.";
        }
        header('Location: ?page=online');
        exit;
    }

    if ($action === 'update_user') {
        $id = (int)($_POST['id'] ?? 0);
        $user = getUserById($id);
        if ($user) {
            $data = [];
            if (!empty($_POST['password'])) {
                $data['password'] = $_POST['password'];
                changeSystemPassword($user['username'], $_POST['password']);
            }
            if (isset($_POST['days']) && $_POST['days'] !== '') {
                $data['expires_at'] = date('Y-m-d H:i:s', time() + (int)$_POST['days'] * 86400);
            }
            if (isset($_POST['max_connections']) && $_POST['max_connections'] !== '') {
                $data['max_connections'] = (int)$_POST['max_connections'];
            }
            if (isset($_POST['data_limit']) && $_POST['data_limit'] !== '') {
                $data['data_limit'] = (float)$_POST['data_limit'];
            }
            if (isset($_POST['bandwidth_limit']) && $_POST['bandwidth_limit'] !== '') {
                $data['bandwidth_limit'] = (int)$_POST['bandwidth_limit'];
                setBandwidthLimit($user['username'], (int)$_POST['bandwidth_limit']);
            }
            updateDBUser($id, $data);
            $_SESSION['flash'] = "User '{$user['username']}' updated.";
        }
        header('Location: ?page=users');
        exit;
    }

    if ($action === 'reset_data') {
        $id = (int)($_POST['id'] ?? 0);
        $user = getUserById($id);
        updateDBUser($id, ['data_used' => 0]);
        $_SESSION['flash'] = "Data usage reset for '{$user['username']}'.";
        header('Location: ?page=users');
        exit;
    }

    if ($action === 'save_settings') {
        if (!empty($_POST['ssh_port'])) {
            $portResult = changeSSHPort((int)$_POST['ssh_port']);
            if (!$portResult['success']) {
                $_SESSION['flash'] = 'Warning: SSH port change failed - ' . $portResult['error'];
            }
        }
        if (!empty($_POST['admin_username'])) {
            setSetting('admin_username', $_POST['admin_username']);
        }
        if (!empty($_POST['admin_password_new'])) {
            setSetting('admin_password', password_hash($_POST['admin_password_new'], PASSWORD_DEFAULT));
        }
        if (isset($_POST['telegram_bot_token'])) {
            setSetting('telegram_bot_token', $_POST['telegram_bot_token']);
        }
        if (isset($_POST['telegram_backup_enabled'])) {
            setSetting('telegram_backup_enabled', $_POST['telegram_backup_enabled']);
        }
        if (isset($_POST['telegram_backup_interval'])) {
            setSetting('telegram_backup_interval', $_POST['telegram_backup_interval']);
        }
        if (isset($_POST['server_ip'])) {
            setSetting('server_ip', $_POST['server_ip']);
        }
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = 'Settings saved!';
        }
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'add_chat_id') {
        $chatId = trim($_POST['chat_id'] ?? '');
        if (!empty($chatId)) {
            addTelegramChatId($chatId);
            $_SESSION['flash'] = "Chat ID added.";
        }
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'remove_chat_id') {
        removeTelegramChatId(trim($_POST['chat_id'] ?? ''));
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'create_backup') {
        $result = createBackup();
        if ($result['success']) {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            readfile($result['filepath']);
            exit;
        }
        $_SESSION['flash'] = 'Backup failed.';
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'telegram_backup') {
        $result = sendTelegramBackup();
        $_SESSION['flash'] = $result['success'] ? 'Backup sent to Telegram!' : ('Error: ' . ($result['error'] ?? 'Failed'));
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'test_telegram') {
        $stats = getPanelStats();
        $msg = "✅ <b>SSH Panel Test</b>\n📅 " . date('Y-m-d H:i:s') . "\n🖥 Server: " . getServerIP() . "\n👥 Users: {$stats['total_users']}\n🟢 Online: {$stats['online_users']}";
        $result = sendTelegramMessage($msg);
        $_SESSION['flash'] = $result['success'] ? 'Test message sent!' : ('Error: ' . ($result['error'] ?? 'Failed'));
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'restore_backup' && isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === 0) {
        $content = file_get_contents($_FILES['backup_file']['tmp_name']);
        $result = restoreBackup($content);
        $_SESSION['flash'] = $result['success'] ? "Backup restored! ({$result['executed']} queries, {$result['errors']} errors)" : 'Restore failed.';
        header('Location: ?page=settings');
        exit;
    }
}

// Sync online status
try {
    syncOnlineStatus();
} catch (Exception $e) {
    // Continue even if sync fails
}

// Flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Get data for current page
$stats = getPanelStats();
$users = getUsers();
$settings = getAllSettings();
$onlineUsers = array_filter($users, fn($u) => $u['is_online']);
$chatIds = getTelegramChatIds();
$backups = getBackups();
$serverIP = getServerIP();
$isRoot = isRunningAsRoot();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSH Panel Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    dark: { 900: '#0a0e17', 800: '#111827', 700: '#1a2235', 600: '#243049', 500: '#2d3a52' },
                    accent: { DEFAULT: '#3b82f6', light: '#60a5fa', dark: '#2563eb' },
                },
                fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }
            }
        }
    }
    </script>
    <style>
        body { background: #0a0e17; font-family: 'Inter', system-ui, sans-serif; }
        * { scrollbar-width: thin; scrollbar-color: #2d3a52 #111827; }
        .glass { background: linear-gradient(135deg, rgba(26,34,53,0.8), rgba(17,24,39,0.9)); border: 1px solid rgba(59,130,246,0.15); backdrop-filter: blur(10px); }
        .glow { box-shadow: 0 0 15px rgba(59,130,246,0.1), inset 0 0 15px rgba(59,130,246,0.05); }
        .stat-card { background: linear-gradient(135deg, rgba(26,34,53,0.9), rgba(17,24,39,0.95)); border: 1px solid rgba(59,130,246,0.2); transition: all 0.3s; }
        .stat-card:hover { border-color: rgba(59,130,246,0.4); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(59,130,246,0.15); }
        .sidebar-item { transition: all 0.2s; border-left: 3px solid transparent; }
        .sidebar-item:hover { background: rgba(59,130,246,0.1); border-left-color: rgba(59,130,246,0.5); }
        .sidebar-item.active { background: rgba(59,130,246,0.15); border-left-color: #3b82f6; color: #60a5fa; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); transition: all 0.2s; }
        .btn-primary:hover { background: linear-gradient(135deg, #60a5fa, #3b82f6); box-shadow: 0 4px 15px rgba(59,130,246,0.3); }
        .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .btn-danger:hover { background: linear-gradient(135deg, #f87171, #ef4444); }
        .btn-success { background: linear-gradient(135deg, #10b981, #059669); }
        .btn-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .input-field { background: rgba(10,14,23,0.8); border: 1px solid rgba(59,130,246,0.2); transition: all 0.2s; }
        .input-field:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .table-row { border-bottom: 1px solid rgba(59,130,246,0.08); transition: background 0.2s; }
        .table-row:hover { background: rgba(59,130,246,0.05); }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-online { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
        .badge-offline { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }
        .badge-expired { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        .badge-paused { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
        .badge-active { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
        .progress-bar { background: rgba(59,130,246,0.15); border-radius: 10px; height: 8px; overflow: hidden; }
        .progress-fill { border-radius: 10px; height: 100%; transition: width 0.5s; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
        .pulse-dot { animation: pulse 2s infinite; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        .fade-in { animation: fadeIn 0.3s ease forwards; }
        .modal-bg { background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
        .qr-card { border: 1px solid rgba(59,130,246,0.2); }
        .preset-btn { cursor: pointer; }
        .preset-btn:hover { background: rgba(59,130,246,0.2) !important; color: white !important; }
    </style>
</head>
<body class="text-gray-200 min-h-screen">

<?php if ($flash): ?>
<div id="flash-msg" class="fixed top-4 right-4 z-[100] px-6 py-3 rounded-xl text-sm font-medium fade-in <?= strpos($flash, 'Error') !== false || strpos($flash, 'Warning') !== false ? 'bg-red-500/20 text-red-400 border border-red-500/30' : 'bg-green-500/20 text-green-400 border border-green-500/30' ?>">
    <?= htmlspecialchars($flash) ?>
</div>
<script>setTimeout(()=>document.getElementById('flash-msg')?.remove(), 5000);</script>
<?php endif; ?>

<?php if (!$isRoot && $isAdminLoggedIn): ?>
<div class="fixed top-4 left-1/2 -translate-x-1/2 z-[90] px-4 py-2 rounded-lg text-xs bg-yellow-500/20 text-yellow-400 border border-yellow-500/30">
    ⚠️ Not running as root - system user management disabled (demo mode)
</div>
<?php endif; ?>

<?php
// ============================================================
//  ADMIN LOGIN PAGE
// ============================================================
if ($page === 'login'): ?>
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md fade-in">
        <div class="text-center mb-8">
            <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 via-blue-600 to-purple-600 flex items-center justify-center mb-4 shadow-lg shadow-blue-500/20">
                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <h1 class="text-3xl font-bold text-white">SSH Panel</h1>
            <p class="text-gray-400 mt-1">Administration Dashboard</p>
        </div>
        <div class="glass glow rounded-2xl p-8">
            <h2 class="text-lg font-semibold text-white mb-6 flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                Admin Login
            </h2>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Username</label>
                    <input type="text" name="username" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="admin" value="admin" required>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Password</label>
                    <input type="password" name="password" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="admin" required>
                </div>
                <?php if (isset($loginError)): ?>
                <div class="text-red-400 text-sm bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-center"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>
                <button type="submit" class="btn-primary w-full py-3 text-base rounded-lg text-white font-semibold">Login to Panel</button>
            </form>
            <div class="mt-6 pt-4 border-t border-gray-700/50 text-center">
                <p class="text-gray-500 text-sm mb-2">Are you an SSH user?</p>
                <a href="?page=user_login" class="text-blue-400 hover:text-blue-300 text-sm font-medium">Login to My Account →</a>
            </div>
        </div>
        <p class="text-center text-gray-600 text-xs mt-6">SSH Panel Manager v<?= PANEL_VERSION ?> • Default: admin / admin</p>
    </div>
</div>

<?php
// ============================================================
//  USER LOGIN PAGE
// ============================================================
elseif ($page === 'user_login'): ?>
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="glass glow rounded-2xl p-8 w-full max-w-md fade-in">
        <div class="text-center mb-8">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-white">My Account</h1>
            <p class="text-gray-400 text-sm mt-1">Login to view your SSH account</p>
        </div>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Username</label>
                <input type="text" name="username" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="Your SSH username" required>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Password</label>
                <input type="password" name="password" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="Your password" required>
            </div>
            <?php if (isset($userLoginError)): ?>
            <div class="text-red-400 text-sm bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-center"><?= htmlspecialchars($userLoginError) ?></div>
            <?php endif; ?>
            <button type="submit" class="btn-primary w-full py-3 rounded-lg text-white font-semibold">Login</button>
        </form>
        <p class="text-center text-gray-500 text-xs mt-6">
            <a href="?page=login" class="text-gray-500 hover:text-gray-400">← Admin Login</a>
        </p>
    </div>
</div>

<?php
// ============================================================
//  USER DASHBOARD (MY ACCOUNT)
// ============================================================
elseif ($page === 'my_account' && $isUserLoggedIn):
    $myUser = getUserById($_SESSION['user_id']);
    if (!$myUser) { header('Location: ?page=user_logout'); exit; }
    $daysLeft = getDaysLeft($myUser['expires_at']);
    $isExpired = $daysLeft <= 0;
    $dataPercent = $myUser['data_limit'] > 0 ? min(($myUser['data_used'] / $myUser['data_limit']) * 100, 100) : 0;
    $configs = generateConnectionConfigs($myUser);
?>
<div class="min-h-screen p-4 md:p-8">
    <div class="max-w-4xl mx-auto fade-in">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Welcome, <?= htmlspecialchars($myUser['username']) ?></h1>
                    <p class="text-sm text-gray-400">Your SSH Account</p>
                </div>
            </div>
            <a href="?page=user_logout" class="flex items-center gap-2 text-gray-400 hover:text-white">Logout</a>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="stat-card rounded-2xl p-4">
                <p class="text-gray-400 text-sm">⏱️ Time Left</p>
                <p class="text-2xl font-bold mt-1 <?= $isExpired ? 'text-red-400' : ($daysLeft <= 3 ? 'text-yellow-400' : 'text-green-400') ?>"><?= $isExpired ? 'Expired' : "{$daysLeft}d" ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= date('Y-m-d', strtotime($myUser['expires_at'])) ?></p>
            </div>
            <div class="stat-card rounded-2xl p-4">
                <p class="text-gray-400 text-sm">📊 Data Used</p>
                <p class="text-2xl font-bold text-cyan-400 mt-1"><?= formatDataSize($myUser['data_used']) ?></p>
                <?php if ($myUser['data_limit'] > 0): ?>
                <div class="progress-bar mt-2"><div class="progress-fill" style="width:<?= $dataPercent ?>%;background:<?= $dataPercent > 90 ? '#ef4444' : ($dataPercent > 70 ? '#f59e0b' : '#3b82f6') ?>"></div></div>
                <p class="text-xs text-gray-500 mt-1">of <?= formatDataSize($myUser['data_limit']) ?></p>
                <?php else: ?>
                <p class="text-xs text-gray-500 mt-1">Unlimited</p>
                <?php endif; ?>
            </div>
            <div class="stat-card rounded-2xl p-4">
                <p class="text-gray-400 text-sm">📶 Connections</p>
                <p class="text-2xl font-bold text-purple-400 mt-1"><?= $myUser['current_connections'] ?><span class="text-gray-500 text-lg">/<?= $myUser['max_connections'] ?></span></p>
                <p class="text-xs mt-1 <?= $myUser['is_online'] ? 'text-green-400' : 'text-gray-500' ?>"><?= $myUser['is_online'] ? '● Online' : 'Offline' ?></p>
            </div>
            <div class="stat-card rounded-2xl p-4">
                <p class="text-gray-400 text-sm">🚀 Bandwidth</p>
                <p class="text-2xl font-bold text-yellow-400 mt-1"><?= $myUser['bandwidth_limit'] > 0 ? $myUser['bandwidth_limit'].'Kbps' : '∞' ?></p>
            </div>
        </div>

        <!-- Server Info -->
        <div class="glass glow rounded-2xl p-5 mb-6">
            <h3 class="text-lg font-semibold text-white mb-4">🖥️ Connection Info</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div class="bg-dark-900/50 rounded-lg p-3"><span class="text-gray-500">Server</span><p class="text-white font-mono mt-0.5"><?= htmlspecialchars($serverIP) ?></p></div>
                <div class="bg-dark-900/50 rounded-lg p-3"><span class="text-gray-500">SSH Port</span><p class="text-white font-mono mt-0.5"><?= htmlspecialchars($settings['ssh_port'] ?? '22') ?></p></div>
                <div class="bg-dark-900/50 rounded-lg p-3"><span class="text-gray-500">Username</span><p class="text-white font-mono mt-0.5"><?= htmlspecialchars($myUser['username']) ?></p></div>
                <div class="bg-dark-900/50 rounded-lg p-3"><span class="text-gray-500">Status</span><p class="mt-0.5"><span class="badge <?= $isExpired ? 'badge-expired' : (!$myUser['is_enabled'] ? 'badge-paused' : 'badge-active') ?>"><?= $isExpired ? 'Expired' : (!$myUser['is_enabled'] ? 'Paused' : 'Active') ?></span></p></div>
            </div>
        </div>

        <!-- QR Codes -->
        <div class="glass glow rounded-2xl p-5">
            <h3 class="text-lg font-semibold text-white mb-4">📱 Connection QR Codes</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" id="user-qr-container">
                <?php foreach ($configs as $i => $config): ?>
                <div class="glass qr-card rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-xl"><?= $config['icon'] ?></span>
                        <h4 class="font-semibold text-white"><?= $config['name'] ?></h4>
                    </div>
                    <div class="flex justify-center mb-3">
                        <div class="bg-white p-2.5 rounded-lg">
                            <canvas id="qr-user-<?= $i ?>" width="140" height="140"></canvas>
                        </div>
                    </div>
                    <div class="bg-dark-900/80 rounded-lg p-2 mb-3">
                        <p class="text-xs text-gray-400 break-all font-mono leading-relaxed max-h-14 overflow-y-auto" id="uri-user-<?= $i ?>"><?= htmlspecialchars($config['uri']) ?></p>
                    </div>
                    <button onclick="copyUri('uri-user-<?= $i ?>', this)" class="w-full py-2 px-3 rounded-lg text-sm font-medium bg-blue-500/10 text-blue-400 border border-blue-500/20 hover:bg-blue-500/20 transition-all">📋 Copy Link</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($configs as $i => $config): ?>
    try {
        QRCode.toCanvas(document.getElementById('qr-user-<?= $i ?>'), <?= json_encode($config['uri']) ?>, {width:140,margin:0,color:{dark:'#000',light:'#fff'}});
    } catch(e) { console.error('QR error:', e); }
    <?php endforeach; ?>
});
</script>

<?php
// ============================================================
//  ADMIN PANEL
// ============================================================
elseif ($isAdminLoggedIn): ?>
<div class="flex min-h-screen">
    <!-- Sidebar -->
    <button id="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')" class="lg:hidden fixed top-4 left-4 z-50 p-2 rounded-lg bg-dark-800 border border-blue-500/20 text-white">☰</button>

    <aside id="sidebar" class="fixed lg:static top-0 left-0 z-40 h-screen w-64 flex flex-col -translate-x-full lg:translate-x-0 transition-transform duration-300" style="background:linear-gradient(180deg,#111827,#0d1321);border-right:1px solid rgba(59,130,246,0.1)">
        <div class="p-5 border-b border-blue-500/10">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center shadow-lg shadow-blue-500/20">🖥️</div>
                <div><h1 class="text-lg font-bold text-white">SSH Panel</h1><p class="text-xs text-gray-500">v<?= PANEL_VERSION ?></p></div>
            </div>
        </div>
        <nav class="flex-1 py-4 px-3 space-y-1">
            <?php foreach ([
                ['dashboard', '📊 Dashboard', $stats['total_users']],
                ['users', '👥 Users', count($users)],
                ['online', '🟢 Online', $stats['online_users']],
                ['settings', '⚙️ Settings', null],
            ] as $item): ?>
            <a href="?page=<?= $item[0] ?>" class="sidebar-item flex items-center justify-between gap-3 px-4 py-3 rounded-lg text-sm font-medium <?= $page === $item[0] ? 'active' : 'text-gray-400 hover:text-white' ?>">
                <span><?= $item[1] ?></span>
                <?php if ($item[2] !== null): ?><span class="text-xs px-2 py-0.5 rounded-full <?= $page === $item[0] ? 'bg-blue-500/20 text-blue-400' : 'bg-dark-600 text-gray-500' ?>"><?= $item[2] ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="p-4 mx-3 mb-3 rounded-xl bg-dark-900/50 border border-blue-500/10 text-xs">
            <div class="flex justify-between mb-1"><span class="text-gray-500">Server</span><span class="text-green-400">● Online</span></div>
            <div class="text-gray-500"><?= $serverIP ?></div>
        </div>
        <div class="p-3 border-t border-blue-500/10">
            <a href="?page=logout" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-red-400 hover:bg-red-500/10">🚪 Logout</a>
        </div>
    </aside>

    <main class="flex-1 min-h-screen overflow-y-auto p-4 md:p-6 lg:p-8">

<?php
// ============================================================
//  DASHBOARD PAGE
// ============================================================
if ($page === 'dashboard'): ?>
        <div class="fade-in space-y-6">
            <h1 class="text-2xl font-bold text-white">📊 Dashboard Overview</h1>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="stat-card rounded-2xl p-5">
                    <p class="text-gray-400 text-sm">Total Users</p>
                    <p class="text-3xl font-bold text-white mt-1"><?= $stats['total_users'] ?></p>
                </div>
                <div class="stat-card rounded-2xl p-5">
                    <p class="text-gray-400 text-sm">Online Now</p>
                    <p class="text-3xl font-bold text-green-400 mt-1"><?= $stats['online_users'] ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $stats['active_connections'] ?> connections</p>
                </div>
                <div class="stat-card rounded-2xl p-5">
                    <p class="text-gray-400 text-sm">Total Data</p>
                    <p class="text-3xl font-bold text-cyan-400 mt-1"><?= formatDataSize($stats['total_data_used']) ?></p>
                </div>
                <div class="stat-card rounded-2xl p-5">
                    <p class="text-gray-400 text-sm">Expired/Paused</p>
                    <p class="text-3xl font-bold text-red-400 mt-1"><?= $stats['expired_users'] ?>/<?= $stats['paused_users'] ?></p>
                </div>
            </div>

            <div class="glass glow rounded-2xl p-5">
                <h3 class="text-lg font-semibold text-white mb-4">📈 Top Data Usage</h3>
                <?php $topUsers = getTopDataUsers(); $maxData = $topUsers ? max(array_column($topUsers, 'data_used')) : 0; ?>
                <?php if (empty($topUsers)): ?>
                <p class="text-gray-500 text-center py-8">No data yet</p>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($topUsers as $tu): $pct = $maxData > 0 ? ($tu['data_used'] / $maxData) * 100 : 0; ?>
                    <div class="flex items-center gap-4">
                        <span class="w-28 text-sm text-gray-300 truncate"><?= htmlspecialchars($tu['username']) ?></span>
                        <div class="flex-1 progress-bar"><div class="progress-fill bg-blue-500" style="width:<?= $pct ?>%"></div></div>
                        <span class="text-sm text-cyan-400 w-20 text-right"><?= formatDataSize($tu['data_used']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="glass glow rounded-2xl p-5">
                <h3 class="text-lg font-semibold text-white mb-4">🟢 Online Users <span class="badge badge-online ml-2"><?= count($onlineUsers) ?></span></h3>
                <?php if (empty($onlineUsers)): ?>
                <p class="text-gray-500 text-center py-8">No users online</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-gray-400 text-left border-b border-blue-500/10"><th class="pb-3 px-3">User</th><th class="pb-3 px-3">Conn</th><th class="pb-3 px-3">IPs</th><th class="pb-3 px-3">Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($onlineUsers as $ou): ?>
                        <tr class="table-row">
                            <td class="py-3 px-3 font-medium text-white"><?= htmlspecialchars($ou['username']) ?></td>
                            <td class="py-3 px-3"><span class="text-cyan-400"><?= $ou['current_connections'] ?></span>/<span class="text-gray-500"><?= $ou['max_connections'] ?></span></td>
                            <td class="py-3 px-3"><?php foreach(array_filter(explode(',', $ou['connected_ips'] ?? '')) as $ip): ?><span class="text-xs bg-dark-600 px-2 py-0.5 rounded text-gray-300 mr-1"><?= htmlspecialchars(trim($ip)) ?></span><?php endforeach; ?></td>
                            <td class="py-3 px-3 text-cyan-400"><?= formatDataSize($ou['data_used']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

<?php
// ============================================================
//  USERS PAGE
// ============================================================
elseif ($page === 'users'):
    $search = $_GET['search'] ?? '';
    $filteredUsers = $search ? array_filter($users, fn($u) => stripos($u['username'], $search) !== false) : $users;
?>
        <div class="fade-in space-y-5">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <h1 class="text-2xl font-bold text-white">👥 User Management <span class="text-sm font-normal text-gray-400">(<?= count($users) ?>)</span></h1>
                <button onclick="document.getElementById('create-modal').classList.remove('hidden')" class="btn-primary px-5 py-2.5 rounded-lg text-white font-semibold">➕ Create User</button>
            </div>

            <form method="GET" class="relative">
                <input type="hidden" name="page" value="users">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Search users..." class="input-field w-full rounded-lg px-4 py-3 text-white">
            </form>

            <div class="glass glow rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-gray-400 text-left border-b border-blue-500/10 bg-dark-800/50">
                            <th class="py-3 px-4">User</th><th class="py-3 px-4">Password</th><th class="py-3 px-4">Status</th><th class="py-3 px-4">Expires</th><th class="py-3 px-4">Conn</th><th class="py-3 px-4">Data</th><th class="py-3 px-4">BW</th><th class="py-3 px-4 text-right">Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($filteredUsers as $u):
                            $dl = getDaysLeft($u['expires_at']);
                            $exp = $dl <= 0;
                            $dp = $u['data_limit'] > 0 ? min(($u['data_used'] / $u['data_limit']) * 100, 100) : 0;
                            $overLimit = $u['data_limit'] > 0 && $u['data_used'] >= $u['data_limit'];
                        ?>
                        <tr class="table-row">
                            <td class="py-3 px-4 font-medium text-white"><?= htmlspecialchars($u['username']) ?></td>
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs text-gray-400" id="pass-<?= $u['id'] ?>" data-pass="<?= htmlspecialchars($u['password']) ?>">••••••••</span>
                                    <button onclick="togglePass(<?= $u['id'] ?>)" class="text-gray-500 hover:text-white text-xs">👁</button>
                                    <button onclick="copyPass(<?= $u['id'] ?>)" class="text-gray-500 hover:text-blue-400 text-xs">📋</button>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <?php if ($u['is_online']): ?><span class="badge badge-online"><span class="pulse-dot inline-block w-1.5 h-1.5 rounded-full bg-green-400 mr-1"></span>Online</span>
                                <?php elseif ($exp): ?><span class="badge badge-expired">Expired</span>
                                <?php elseif (!$u['is_enabled']): ?><span class="badge badge-paused">Paused<?= $overLimit ? ' (Data)' : '' ?></span>
                                <?php else: ?><span class="badge badge-offline">Offline</span><?php endif; ?>
                            </td>
                            <td class="py-3 px-4 <?= $exp ? 'text-red-400' : ($dl <= 3 ? 'text-yellow-400' : 'text-gray-300') ?>"><?= $exp ? 'Exp '.abs($dl).'d' : $dl.'d' ?></td>
                            <td class="py-3 px-4"><span class="text-cyan-400"><?= $u['current_connections'] ?></span>/<span class="text-gray-500"><?= $u['max_connections'] ?></span></td>
                            <td class="py-3 px-4">
                                <div class="space-y-1">
                                    <span class="text-xs <?= $overLimit ? 'text-red-400' : 'text-gray-300' ?>"><?= formatDataSize($u['data_used']) ?></span>
                                    <span class="text-xs text-gray-500">/ <?= $u['data_limit'] > 0 ? formatDataSize($u['data_limit']) : '∞' ?></span>
                                    <?php if ($u['data_limit'] > 0): ?><div class="progress-bar w-20"><div class="progress-fill" style="width:<?= $dp ?>%;background:<?= $dp > 90 ? '#ef4444' : ($dp > 70 ? '#f59e0b' : '#3b82f6') ?>"></div></div><?php endif; ?>
                                </div>
                            </td>
                            <td class="py-3 px-4 text-gray-400 text-xs"><?= $u['bandwidth_limit'] > 0 ? $u['bandwidth_limit'].'K' : '∞' ?></td>
                            <td class="py-3 px-4">
                                <div class="flex items-center justify-end gap-1">
                                    <button onclick="showQR(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')" class="p-1.5 rounded hover:bg-purple-500/20 text-purple-400" title="QR">📱</button>
                                    <button onclick='showEdit(<?= json_encode($u) ?>)' class="p-1.5 rounded hover:bg-blue-500/20 text-blue-400" title="Edit">✏️</button>
                                    <form method="POST" class="inline"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="p-1.5 rounded <?= $u['is_enabled'] ? 'hover:bg-yellow-500/20 text-yellow-400' : 'hover:bg-green-500/20 text-green-400' ?>" title="<?= $u['is_enabled'] ? 'Pause' : 'Unpause' ?>">⚡</button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete user and remove from system?')"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="p-1.5 rounded hover:bg-red-500/20 text-red-400" title="Delete">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($filteredUsers)): ?><div class="text-center py-12 text-gray-500">No users</div><?php endif; ?>
            </div>
        </div>

        <!-- Create User Modal -->
        <div id="create-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 modal-bg" onclick="this.classList.add('hidden')">
            <div class="glass glow rounded-2xl p-6 w-full max-w-lg fade-in" onclick="event.stopPropagation()">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-bold text-white">➕ Create New User</h2>
                    <button onclick="document.getElementById('create-modal').classList.add('hidden')" class="text-gray-400 hover:text-white text-xl">✕</button>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_user">
                    <div><label class="block text-sm text-gray-400 mb-1">Username</label><input type="text" name="username" id="create-username" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="username" required pattern="[a-zA-Z][a-zA-Z0-9_]{2,31}"></div>
                    <div><label class="block text-sm text-gray-400 mb-1">Password (leave blank for random)</label>
                        <div class="flex gap-2"><input type="text" name="password" id="create-password" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="Auto-generate if empty">
                        <button type="button" onclick="document.getElementById('create-password').value=generatePass()" class="btn-primary px-4 rounded-lg text-white text-sm whitespace-nowrap">🔄</button></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm text-gray-400 mb-1">Days</label><input type="number" name="days" id="create-days" value="30" class="input-field w-full rounded-lg px-4 py-3 text-white" min="1"></div>
                        <div><label class="block text-sm text-gray-400 mb-1">Max Connections</label><input type="number" name="max_connections" id="create-maxconn" value="1" class="input-field w-full rounded-lg px-4 py-3 text-white" min="1" max="10"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Data Limit (MB, 0=∞)</label>
                            <input type="number" name="data_limit" id="create-data" value="0" class="input-field w-full rounded-lg px-4 py-3 text-white" min="0">
                            <div class="flex flex-wrap gap-1 mt-2">
                                <span onclick="document.getElementById('create-data').value=0" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">∞</span>
                                <span onclick="document.getElementById('create-data').value=1024" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">1GB</span>
                                <span onclick="document.getElementById('create-data').value=5120" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">5GB</span>
                                <span onclick="document.getElementById('create-data').value=10240" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">10GB</span>
                                <span onclick="document.getElementById('create-data').value=51200" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">50GB</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Bandwidth (Kbps, 0=∞)</label>
                            <input type="number" name="bandwidth_limit" id="create-bw" value="0" class="input-field w-full rounded-lg px-4 py-3 text-white" min="0">
                            <div class="flex flex-wrap gap-1 mt-2">
                                <span onclick="document.getElementById('create-bw').value=0" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">∞</span>
                                <span onclick="document.getElementById('create-bw').value=512" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">512K</span>
                                <span onclick="document.getElementById('create-bw').value=1024" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">1M</span>
                                <span onclick="document.getElementById('create-bw').value=2048" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">2M</span>
                                <span onclick="document.getElementById('create-bw').value=4096" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">4M</span>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary w-full py-3 rounded-lg text-white font-semibold text-base">Create User</button>
                </form>
            </div>
        </div>

        <!-- Edit User Modal -->
        <div id="edit-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 modal-bg" onclick="this.classList.add('hidden')">
            <div class="glass glow rounded-2xl p-6 w-full max-w-lg fade-in" onclick="event.stopPropagation()">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-bold text-white">✏️ Edit: <span id="edit-title"></span></h2>
                    <button onclick="document.getElementById('edit-modal').classList.add('hidden')" class="text-gray-400 hover:text-white text-xl">✕</button>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="id" id="edit-id">
                    <div><label class="block text-sm text-gray-400 mb-1">New Password (blank = keep)</label>
                        <div class="flex gap-2"><input type="text" name="password" id="edit-pass" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="New password">
                        <button type="button" onclick="document.getElementById('edit-pass').value=generatePass()" class="btn-primary px-4 rounded-lg text-white text-sm">🔄</button></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm text-gray-400 mb-1">Days (from now)</label><input type="number" name="days" id="edit-days" class="input-field w-full rounded-lg px-4 py-3 text-white" min="1"></div>
                        <div><label class="block text-sm text-gray-400 mb-1">Max Connections</label><input type="number" name="max_connections" id="edit-maxconn" class="input-field w-full rounded-lg px-4 py-3 text-white" min="1" max="10"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Data Limit (MB)</label>
                            <input type="number" name="data_limit" id="edit-data" class="input-field w-full rounded-lg px-4 py-3 text-white" min="0">
                            <div class="flex flex-wrap gap-1 mt-2">
                                <span onclick="document.getElementById('edit-data').value=0" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">∞</span>
                                <span onclick="document.getElementById('edit-data').value=1024" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">1GB</span>
                                <span onclick="document.getElementById('edit-data').value=5120" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">5GB</span>
                                <span onclick="document.getElementById('edit-data').value=10240" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">10GB</span>
                                <span onclick="document.getElementById('edit-data').value=51200" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">50GB</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Bandwidth (Kbps)</label>
                            <input type="number" name="bandwidth_limit" id="edit-bw" class="input-field w-full rounded-lg px-4 py-3 text-white" min="0">
                            <div class="flex flex-wrap gap-1 mt-2">
                                <span onclick="document.getElementById('edit-bw').value=0" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">∞</span>
                                <span onclick="document.getElementById('edit-bw').value=512" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">512K</span>
                                <span onclick="document.getElementById('edit-bw').value=1024" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">1M</span>
                                <span onclick="document.getElementById('edit-bw').value=2048" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">2M</span>
                                <span onclick="document.getElementById('edit-bw').value=4096" class="preset-btn text-xs px-2 py-1 rounded bg-dark-600 text-gray-400 cursor-pointer">4M</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary flex-1 py-3 rounded-lg text-white font-semibold">Save</button>
                    </div>
                </form>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="action" value="reset_data">
                    <input type="hidden" name="id" id="edit-reset-id">
                    <button type="submit" class="btn-warning w-full py-2 rounded-lg text-white text-sm">Reset Data Usage to 0</button>
                </form>
            </div>
        </div>

        <!-- QR Modal -->
        <div id="qr-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 modal-bg" onclick="this.classList.add('hidden')">
            <div class="glass glow rounded-2xl p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto fade-in" onclick="event.stopPropagation()">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-bold text-white">📱 QR Codes: <span id="qr-title"></span></h2>
                    <button onclick="document.getElementById('qr-modal').classList.add('hidden')" class="text-gray-400 hover:text-white text-xl">✕</button>
                </div>
                <div id="qr-content" class="grid grid-cols-1 sm:grid-cols-2 gap-4"></div>
            </div>
        </div>

<?php
// ============================================================
//  ONLINE USERS PAGE
// ============================================================
elseif ($page === 'online'):
    $totalConn = array_sum(array_column($onlineUsers, 'current_connections'));
?>
        <div class="fade-in space-y-5">
            <h1 class="text-2xl font-bold text-white">🟢 Online Users <span class="badge badge-online ml-2"><?= count($onlineUsers) ?></span></h1>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="stat-card rounded-2xl p-4"><p class="text-gray-400 text-sm">Online</p><p class="text-2xl font-bold text-green-400 mt-1"><?= count($onlineUsers) ?></p></div>
                <div class="stat-card rounded-2xl p-4"><p class="text-gray-400 text-sm">Connections</p><p class="text-2xl font-bold text-cyan-400 mt-1"><?= $totalConn ?></p></div>
                <div class="stat-card rounded-2xl p-4"><p class="text-gray-400 text-sm">Data</p><p class="text-2xl font-bold text-purple-400 mt-1"><?= formatDataSize(array_sum(array_column($onlineUsers, 'data_used'))) ?></p></div>
            </div>

            <?php if (empty($onlineUsers)): ?>
            <div class="glass glow rounded-2xl p-12 text-center text-gray-500">No users online</div>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($onlineUsers as $ou): $dl = getDaysLeft($ou['expires_at']); $dp = $ou['data_limit'] > 0 ? min(($ou['data_used'] / $ou['data_limit']) * 100, 100) : 0; ?>
                <div class="glass glow rounded-xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="pulse-dot inline-block w-2.5 h-2.5 rounded-full bg-green-400"></span>
                            <h3 class="font-semibold text-white"><?= htmlspecialchars($ou['username']) ?></h3>
                        </div>
                        <form method="POST"><input type="hidden" name="action" value="kick_user"><input type="hidden" name="id" value="<?= $ou['id'] ?>">
                            <button type="submit" class="p-1.5 rounded hover:bg-red-500/20 text-red-400" title="Kick">⚡</button>
                        </form>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-400">Connections</span><span class="text-cyan-400"><?= $ou['current_connections'] ?>/<?= $ou['max_connections'] ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-400">Time Left</span><span class="<?= $dl <= 3 ? 'text-yellow-400' : 'text-gray-300' ?>"><?= $dl ?>d</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">Data</span><span class="text-gray-300"><?= formatDataSize($ou['data_used']) ?><?= $ou['data_limit'] > 0 ? '/'.formatDataSize($ou['data_limit']) : '' ?></span></div>
                        <?php if ($ou['data_limit'] > 0): ?><div class="progress-bar"><div class="progress-fill" style="width:<?= $dp ?>%;background:<?= $dp > 90 ? '#ef4444' : ($dp > 70 ? '#f59e0b' : '#3b82f6') ?>"></div></div><?php endif; ?>
                    </div>
                    <?php $ips = array_filter(explode(',', $ou['connected_ips'] ?? '')); if (!empty($ips)): ?>
                    <div class="mt-3 pt-3 border-t border-gray-700/30">
                        <p class="text-xs text-gray-500 mb-1">IPs</p>
                        <div class="flex flex-wrap gap-1"><?php foreach($ips as $ip): ?><span class="text-xs bg-dark-600 px-2 py-0.5 rounded text-gray-400 font-mono"><?= htmlspecialchars(trim($ip)) ?></span><?php endforeach; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

<?php
// ============================================================
//  SETTINGS PAGE
// ============================================================
elseif ($page === 'settings'): ?>
        <div class="fade-in space-y-6 max-w-4xl">
            <h1 class="text-2xl font-bold text-white">⚙️ Settings</h1>

            <form method="POST">
                <input type="hidden" name="action" value="save_settings">

                <div class="glass glow rounded-2xl p-6 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-4">🌐 SSH Configuration</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-sm text-gray-400 mb-1">SSH Port</label><input type="number" name="ssh_port" value="<?= htmlspecialchars($settings['ssh_port'] ?? '22') ?>" class="input-field w-full rounded-lg px-4 py-3 text-white"><p class="text-xs text-gray-500 mt-1">Requires SSH restart (root only)</p></div>
                        <div><label class="block text-sm text-gray-400 mb-1">Server IP</label><input type="text" name="server_ip" value="<?= htmlspecialchars($settings['server_ip'] ?? '') ?>" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="<?= $serverIP ?>"></div>
                    </div>
                </div>

                <div class="glass glow rounded-2xl p-6 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-4">🔑 Admin</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-sm text-gray-400 mb-1">Username</label><input type="text" name="admin_username" value="<?= htmlspecialchars($settings['admin_username'] ?? 'admin') ?>" class="input-field w-full rounded-lg px-4 py-3 text-white"></div>
                        <div><label class="block text-sm text-gray-400 mb-1">New Password</label><input type="password" name="admin_password_new" class="input-field w-full rounded-lg px-4 py-3 text-white" placeholder="Leave blank to keep"></div>
                    </div>
                </div>

                <div class="glass glow rounded-2xl p-6 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-4">🤖 Telegram</h3>
                    <div class="space-y-4">
                        <div><label class="block text-sm text-gray-400 mb-1">Bot Token</label><input type="text" name="telegram_bot_token" value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>" class="input-field w-full rounded-lg px-4 py-3 text-white font-mono text-sm" placeholder="123456789:ABC..."></div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div><label class="block text-sm text-gray-400 mb-1">Auto Backup</label>
                                <select name="telegram_backup_enabled" class="input-field w-full rounded-lg px-4 py-3 text-white">
                                    <option value="0" <?= ($settings['telegram_backup_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                                    <option value="1" <?= ($settings['telegram_backup_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                                </select>
                            </div>
                            <div><label class="block text-sm text-gray-400 mb-1">Interval (hours)</label><input type="number" name="telegram_backup_interval" value="<?= htmlspecialchars($settings['telegram_backup_interval'] ?? '24') ?>" class="input-field w-full rounded-lg px-4 py-3 text-white" min="1"></div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-primary px-8 py-3 rounded-xl text-white font-semibold">💾 Save Settings</button>
            </form>

            <div class="glass glow rounded-2xl p-6">
                <h3 class="text-lg font-semibold text-white mb-4">💬 Chat IDs</h3>
                <form method="POST" class="flex gap-2 mb-3">
                    <input type="hidden" name="action" value="add_chat_id">
                    <input type="text" name="chat_id" class="input-field flex-1 rounded-lg px-4 py-3 text-white" placeholder="Chat ID" required>
                    <button type="submit" class="btn-primary px-4 rounded-lg text-white font-semibold">+ Add</button>
                </form>
                <div class="flex flex-wrap gap-2 mb-4">
                    <?php foreach ($chatIds as $cid): ?>
                    <div class="flex items-center gap-1 bg-dark-600 px-3 py-1 rounded-full text-sm">
                        <span class="text-gray-300"><?= htmlspecialchars($cid) ?></span>
                        <form method="POST" class="inline"><input type="hidden" name="action" value="remove_chat_id"><input type="hidden" name="chat_id" value="<?= htmlspecialchars($cid) ?>"><button type="submit" class="text-gray-500 hover:text-red-400 ml-1">✕</button></form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <form method="POST" class="inline"><input type="hidden" name="action" value="test_telegram"><button type="submit" class="btn-primary px-4 py-2 rounded-lg text-white text-sm">📤 Test</button></form>
            </div>

            <div class="glass glow rounded-2xl p-6">
                <h3 class="text-lg font-semibold text-white mb-4">💾 Backup</h3>
                <div class="flex flex-wrap gap-3 mb-4">
                    <form method="POST"><input type="hidden" name="action" value="create_backup"><button type="submit" class="btn-success px-4 py-2 rounded-lg text-white text-sm font-semibold">⬇️ Download SQL</button></form>
                    <form method="POST"><input type="hidden" name="action" value="telegram_backup"><button type="submit" class="btn-primary px-4 py-2 rounded-lg text-white text-sm font-semibold">📤 Send to TG</button></form>
                </div>
                <form method="POST" enctype="multipart/form-data" class="flex items-center gap-3">
                    <input type="hidden" name="action" value="restore_backup">
                    <input type="file" name="backup_file" accept=".sql" class="text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:bg-dark-600 file:text-gray-300">
                    <button type="submit" class="btn-warning px-4 py-2 rounded-lg text-white text-sm font-semibold">⬆️ Restore</button>
                </form>
            </div>
        </div>

<?php endif; ?>

    </main>
</div>
<?php endif; ?>

<script>
// Generate password
function generatePass(len=12) {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*';
    let p = '';
    for (let i = 0; i < len; i++) p += chars.charAt(Math.floor(Math.random() * chars.length));
    return p;
}

// Toggle password visibility
function togglePass(id) {
    const el = document.getElementById('pass-' + id);
    if (el.textContent === '••••••••') {
        el.textContent = el.dataset.pass;
    } else {
        el.textContent = '••••••••';
    }
}

// Copy password
function copyPass(id) {
    const el = document.getElementById('pass-' + id);
    navigator.clipboard.writeText(el.dataset.pass).then(() => {
        el.textContent = '✓ Copied!';
        setTimeout(() => el.textContent = '••••••••', 1500);
    });
}

// Copy URI
function copyUri(elId, btn) {
    const el = document.getElementById(elId);
    navigator.clipboard.writeText(el.textContent).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✅ Copied!';
        setTimeout(() => btn.textContent = orig, 1500);
    });
}

// Show edit modal
function showEdit(user) {
    document.getElementById('edit-modal').classList.remove('hidden');
    document.getElementById('edit-title').textContent = user.username;
    document.getElementById('edit-id').value = user.id;
    document.getElementById('edit-reset-id').value = user.id;
    document.getElementById('edit-pass').value = '';
    const daysLeft = Math.ceil((new Date(user.expires_at).getTime() - Date.now()) / 86400000);
    document.getElementById('edit-days').value = Math.max(daysLeft, 1);
    document.getElementById('edit-maxconn').value = user.max_connections;
    document.getElementById('edit-data').value = user.data_limit;
    document.getElementById('edit-bw').value = user.bandwidth_limit;
}

// Show QR modal with AJAX
function showQR(userId, username) {
    document.getElementById('qr-modal').classList.remove('hidden');
    document.getElementById('qr-title').textContent = username;
    const container = document.getElementById('qr-content');
    container.innerHTML = '<div class="col-span-2 text-center text-gray-400 py-8">Loading...</div>';

    fetch('api.php?action=get_qr_configs&id=' + userId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { container.innerHTML = '<p class="text-red-400 col-span-2 text-center">Error</p>'; return; }
            container.innerHTML = '';
            data.configs.forEach((config, i) => {
                const card = document.createElement('div');
                card.className = 'glass qr-card rounded-xl p-4';
                card.innerHTML = `
                    <div class="flex items-center gap-2 mb-3"><span class="text-xl">${config.icon}</span><h4 class="font-semibold text-white">${config.name}</h4></div>
                    <div class="flex justify-center mb-3"><div class="bg-white p-2.5 rounded-lg"><canvas id="qr-admin-${i}" width="140" height="140"></canvas></div></div>
                    <div class="bg-dark-900/80 rounded-lg p-2 mb-3"><p id="qr-uri-${i}" class="text-xs text-gray-400 break-all font-mono leading-relaxed max-h-14 overflow-y-auto">${escHtml(config.uri)}</p></div>
                    <button onclick="copyUri('qr-uri-${i}', this)" class="w-full py-2 rounded-lg text-sm font-medium bg-blue-500/10 text-blue-400 border border-blue-500/20">📋 Copy</button>
                `;
                container.appendChild(card);
                setTimeout(() => {
                    try {
                        QRCode.toCanvas(document.getElementById('qr-admin-' + i), config.uri, {width:140,margin:0,color:{dark:'#000',light:'#fff'}});
                    } catch(e) { console.error('QR error:', e); }
                }, 50);
            });
        })
        .catch(e => { container.innerHTML = '<p class="text-red-400 col-span-2 text-center">Failed to load</p>'; console.error(e); });
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Auto-refresh on dashboard/online pages
<?php if (in_array($page, ['dashboard', 'online'])): ?>
setTimeout(() => location.reload(), 30000);
<?php endif; ?>
</script>
</body>
</html>
