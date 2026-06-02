<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('DB_PATH', __DIR__ . '/database/panel.db');
define('BACKUP_DIR', __DIR__ . '/backups');
define('VERSION', '1.0.0');

// ============================================================
// DATABASE
// ============================================================
function initDB() {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0777, true);
    $db = new SQLite3(DB_PATH);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL,
        max_conn INTEGER DEFAULT 1, cur_conn INTEGER DEFAULT 0,
        data_limit REAL DEFAULT 0, data_used REAL DEFAULT 0, bw_limit INTEGER DEFAULT 0,
        is_online INTEGER DEFAULT 0, is_enabled INTEGER DEFAULT 1, ips TEXT DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS telegram_chats (id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id TEXT UNIQUE NOT NULL)");
    foreach (['ssh_port'=>'22','admin_user'=>'admin','admin_pass'=>'admin','server_ip'=>'','tg_token'=>''] as $k=>$v) {
        $s = $db->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (:k,:v)");
        $s->bindValue(':k',$k); $s->bindValue(':v',$v); $s->execute();
    }
    return $db;
}
function getDB() { static $db=null; if(!$db) $db=initDB(); return $db; }
function getSetting($k,$d='') { $s=getDB()->prepare("SELECT value FROM settings WHERE key=:k"); $s->bindValue(':k',$k); $r=$s->execute()->fetchArray(SQLITE3_ASSOC); return $r?$r['value']:$d; }
function setSetting($k,$v) { $s=getDB()->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (:k,:v)"); $s->bindValue(':k',$k); $s->bindValue(':v',$v); $s->execute(); }
function getServerIP() { $ip=getSetting('server_ip'); if($ip) return $ip; $ip=trim(shell_exec("hostname -I 2>/dev/null|awk '{print $1}'")?:''); return $ip?:($_SERVER['SERVER_ADDR']??'0.0.0.0'); }

// ============================================================
// USER DB
// ============================================================
function getUsers() { $r=getDB()->query("SELECT * FROM users ORDER BY created_at DESC"); $u=[]; while($row=$r->fetchArray(SQLITE3_ASSOC)) $u[]=$row; return $u; }
function getUserById($id) { $s=getDB()->prepare("SELECT * FROM users WHERE id=:id"); $s->bindValue(':id',$id,SQLITE3_INTEGER); return $s->execute()->fetchArray(SQLITE3_ASSOC); }
function getUserByUsername($u) { $s=getDB()->prepare("SELECT * FROM users WHERE username=:u"); $s->bindValue(':u',$u); return $s->execute()->fetchArray(SQLITE3_ASSOC); }
function addUserDB($d) {
    $s=getDB()->prepare("INSERT INTO users (username,password,expires_at,max_conn,data_limit,bw_limit,is_enabled) VALUES (:u,:p,:e,:m,:d,:b,1)");
    $s->bindValue(':u',$d['username']); $s->bindValue(':p',$d['password']); $s->bindValue(':e',$d['expires_at']);
    $s->bindValue(':m',$d['max_conn'],SQLITE3_INTEGER); $s->bindValue(':d',$d['data_limit'],SQLITE3_FLOAT); $s->bindValue(':b',$d['bw_limit'],SQLITE3_INTEGER);
    $s->execute(); return getDB()->lastInsertRowID();
}
function updateUserDB($id,$data) {
    $sets=[]; foreach($data as $k=>$v) $sets[]="$k=:$k";
    $sql="UPDATE users SET ".implode(',',$sets)." WHERE id=:id";
    $s=getDB()->prepare($sql); $s->bindValue(':id',$id,SQLITE3_INTEGER);
    foreach($data as $k=>$v) { if(is_int($v)) $s->bindValue(":$k",$v,SQLITE3_INTEGER); elseif(is_float($v)) $s->bindValue(":$k",$v,SQLITE3_FLOAT); else $s->bindValue(":$k",$v); }
    $s->execute();
}
function deleteUserDB($id) { $s=getDB()->prepare("DELETE FROM users WHERE id=:id"); $s->bindValue(':id',$id,SQLITE3_INTEGER); $s->execute(); }

// ============================================================
// SYSTEM USER (real Linux commands)
// ============================================================
function createSystemUser($u,$p) {
    $su=escapeshellarg($u); $sp=escapeshellarg($p);
    exec("id $su 2>/dev/null",$o,$r); if($r===0) return false;
    exec("groupadd -f sshusers 2>/dev/null");
    exec("useradd -m -s /bin/bash -G sshusers $su 2>&1",$o,$r);
    if($r!==0) { exec("useradd -m -s /bin/bash $su 2>&1",$o2,$r2); if($r2!==0) return false; }
    exec("echo $su:$sp | chpasswd 2>&1",$o,$r); if($r!==0){ exec("userdel -r $su 2>/dev/null"); return false; }
    return true;
}
function deleteSystemUser($u) { $s=escapeshellarg($u); exec("pkill -KILL -u $s 2>/dev/null"); sleep(1); exec("userdel -r $s 2>/dev/null"); }
function lockSystemUser($u) { $s=escapeshellarg($u); exec("passwd -l $s 2>/dev/null"); exec("pkill -KILL -u $s 2>/dev/null"); }
function unlockSystemUser($u) { $s=escapeshellarg($u); exec("passwd -u $s 2>/dev/null"); }
function kickUser($u) { $s=escapeshellarg($u); exec("pkill -KILL -u $s 2>/dev/null"); }
function changeSSHPort($port) {
    $port=(int)$port; if($port<1||$port>65535) return false;
    $c=@file_get_contents('/etc/ssh/sshd_config'); if(!$c) return false;
    $c=preg_match('/^Port\s+\d+/m',$c)?preg_replace('/^Port\s+\d+/m',"Port $port",$c):"Port $port\n".$c;
    file_put_contents('/etc/ssh/sshd_config',$c);
    exec("systemctl restart sshd 2>/dev/null||systemctl restart ssh 2>/dev/null");
    setSetting('ssh_port',$port); return true;
}

// ============================================================
// FULL OPERATIONS
// ============================================================
function createUser($d) {
    if(getUserByUsername($d['username'])) return ['ok'=>false,'err'=>'Username exists'];
    $sys = createSystemUser($d['username'],$d['password']);
    $id = addUserDB($d);
    return ['ok'=>true,'id'=>$id,'sys'=>$sys];
}
function deleteUser($id) {
    $u=getUserById($id); if(!$u) return;
    deleteSystemUser($u['username']);
    deleteUserDB($id);
}
function toggleUser($id) {
    $u=getUserById($id); if(!$u) return;
    $new=$u['is_enabled']?0:1;
    if($new) unlockSystemUser($u['username']); else lockSystemUser($u['username']);
    updateUserDB($id,['is_enabled'=>$new]);
}

// ============================================================
// SYNC ONLINE
// ============================================================
function syncOnline() {
    $online=[]; exec("who 2>/dev/null",$lines);
    foreach($lines as $l) {
        if(preg_match('/^(\S+)\s+\S+\s+\S+\s+\S+\s*\(([^)]*)\)?/',$l,$m)) {
            $u=$m[1]; $ip=trim($m[2]??'');
            if(!isset($online[$u])) $online[$u]=['c'=>0,'ips'=>[]];
            $online[$u]['c']++;
            if($ip&&$ip!==':0'&&!in_array($ip,$online[$u]['ips'])) $online[$u]['ips'][]=$ip;
        }
    }
    foreach(getUsers() as $u) {
        $on=isset($online[$u['username']]);
        $c=$on?$online[$u['username']]['c']:0;
        $ips=$on?implode(',',$online[$u['username']]['ips']):'';
        updateUserDB($u['id'],['is_online'=>$on?1:0,'cur_conn'=>$c,'ips'=>$ips]);
        if($u['is_enabled']) {
            if(strtotime($u['expires_at'])<time()||($u['data_limit']>0&&$u['data_used']>=$u['data_limit'])) {
                lockSystemUser($u['username']);
                updateUserDB($u['id'],['is_enabled'=>0]);
            }
        }
    }
}

// ============================================================
// TELEGRAM
// ============================================================
function getTgChats() { $r=getDB()->query("SELECT chat_id FROM telegram_chats"); $c=[]; while($row=$r->fetchArray(SQLITE3_ASSOC)) $c[]=$row['chat_id']; return $c; }
function addTgChat($c) { $s=getDB()->prepare("INSERT OR IGNORE INTO telegram_chats (chat_id) VALUES (:c)"); $s->bindValue(':c',$c); $s->execute(); }
function removeTgChat($c) { $s=getDB()->prepare("DELETE FROM telegram_chats WHERE chat_id=:c"); $s->bindValue(':c',$c); $s->execute(); }
function sendTgMsg($msg) {
    $tk=getSetting('tg_token'); if(!$tk) return;
    foreach(getTgChats() as $ch) {
        $c=curl_init("https://api.telegram.org/bot{$tk}/sendMessage");
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['chat_id'=>$ch,'text'=>$msg,'parse_mode'=>'HTML'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>false]);
        curl_exec($c); curl_close($c);
    }
}

// ============================================================
// BACKUP (Shahan panel compatible)
// ============================================================
function createBackup() {
    $users=getUsers();
    $ts=date('Y-m-d_H-i-s');
    $fn="backup_{$ts}.sql";
    $fp=BACKUP_DIR.'/'.$fn;

    // Shahan-compatible format
    $sql="-- Shahan SSH Panel Backup\n-- Date: ".date('Y-m-d H:i:s')."\n-- Users: ".count($users)."\n\n";
    $sql.="CREATE TABLE IF NOT EXISTS users (\n";
    $sql.="  id INTEGER PRIMARY KEY AUTOINCREMENT,\n";
    $sql.="  username TEXT UNIQUE NOT NULL,\n";
    $sql.="  password TEXT NOT NULL,\n";
    $sql.="  traffic INTEGER DEFAULT 0,\n";
    $sql.="  traffic_used REAL DEFAULT 0,\n";
    $sql.="  expdate TEXT NOT NULL,\n";
    $sql.="  multiuser INTEGER DEFAULT 1,\n";
    $sql.="  status TEXT DEFAULT 'active',\n";
    $sql.="  bandwidth INTEGER DEFAULT 0,\n";
    $sql.="  created_at TEXT DEFAULT CURRENT_TIMESTAMP\n";
    $sql.=");\n\n";
    $sql.="DELETE FROM users;\n\n";

    foreach($users as $u) {
        $status = $u['is_enabled'] ? 'active' : 'deactive';
        if(strtotime($u['expires_at'])<time()) $status = 'expired';
        $traffic = (int)$u['data_limit']; // MB
        $sql.=sprintf("INSERT INTO users (username, password, traffic, traffic_used, expdate, multiuser, status, bandwidth, created_at) VALUES ('%s', '%s', %d, %.2f, '%s', %d, '%s', %d, '%s');\n",
            SQLite3::escapeString($u['username']),
            SQLite3::escapeString($u['password']),
            $traffic,
            $u['data_used'],
            date('Y-m-d', strtotime($u['expires_at'])),
            $u['max_conn'],
            $status,
            $u['bw_limit'],
            $u['created_at']
        );
    }
    file_put_contents($fp,$sql);
    return ['fn'=>$fn,'fp'=>$fp];
}

// ============================================================
// QR CONFIGS (CORRECT FORMATS)
// ============================================================
function getConfigs($user) {
    $ip = getServerIP();
    $port = (int)getSetting('ssh_port','22');
    $u = $user['username'];
    $p = $user['password'];

    // --- NetMod / V2Box: ssh://user:pass@host:port#remark ---
    $netmod_uri = "ssh://{$u}:{$p}@{$ip}:{$port}#{$u}";

    // --- NapsternetV: npvt-ssh:// + base64 JSON ---
    $npv_json = json_encode([
        "sshConfigType" => "SSH-Direct",
        "sni" => "",
        "tlsVersion" => "DEFAULT",
        "httpProxy" => "",
        "authenticateProxy" => false,
        "proxyUsername" => "",
        "proxyPassword" => "",
        "payload" => "",
        "dnsTTMode" => "UDP",
        "dnsServer" => "",
        "nameserver" => "",
        "publicKey" => "",
        "udpgwPort" => 0,
        "remarks" => $u,
        "sshHost" => $ip,
        "sshPort" => $port,
        "sshUsername" => $u,
        "sshPassword" => $p,
        "udpgwTransparentDNS" => true
    ], JSON_UNESCAPED_SLASHES);
    $npv_uri = "npvt-ssh://" . base64_encode($npv_json);

    // --- Rocket SSH: rocket-ssh:// + base64 JSON ---
    $rocket_json = json_encode([
        "host" => $ip,
        "port" => $port,
        "username" => $u,
        "password" => $p,
        "remark" => $u,
        "udpgw" => "127.0.0.1:7300"
    ], JSON_UNESCAPED_SLASHES);
    $rocket_uri = "rocket-ssh://" . base64_encode($rocket_json);

    // --- MRZ VPN (v2box style) ---
    $mrz_uri = "ssh://{$u}:{$p}@{$ip}:{$port}#{$u}";

    return [
        ['name'=>'NetMod','icon'=>'🔧','uri'=>$netmod_uri],
        ['name'=>'NapsternetV','icon'=>'🌐','uri'=>$npv_uri],
        ['name'=>'Rocket SSH','icon'=>'🚀','uri'=>$rocket_uri],
        ['name'=>'V2Box / MRZ','icon'=>'📦','uri'=>$mrz_uri],
    ];
}

// ============================================================
// HELPERS
// ============================================================
function rndPass($n=12) { $c='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'; $p=''; for($i=0;$i<$n;$i++) $p.=$c[random_int(0,strlen($c)-1)]; return $p; }
function fmtData($mb) { return $mb>=1024?round($mb/1024,1).' GB':round($mb).' MB'; }
function daysLeft($e) { return (int)ceil((strtotime($e)-time())/86400); }
function isRoot() { return function_exists('posix_getuid')&&posix_getuid()===0; }

// ============================================================
// ROUTING
// ============================================================
$page=$_GET['page']??'login';
$action=$_POST['action']??'';
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$isAdmin=isset($_SESSION['admin'])&&$_SESSION['admin']===true;
$isUser=isset($_SESSION['user_id']);
try{syncOnline();}catch(Exception $e){}

if($page==='logout'){session_destroy();header('Location:?page=login');exit;}
if($page==='user_logout'){unset($_SESSION['user_id']);header('Location:?page=user_login');exit;}

if($page==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=$_POST['username']??'';$p=$_POST['password']??'';
    if($u===getSetting('admin_user','admin')&&$p===getSetting('admin_pass','admin')){$_SESSION['admin']=true;header('Location:?page=dashboard');exit;}
    $loginError='Invalid credentials';
}
if($page==='user_login'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=$_POST['username']??'';$p=$_POST['password']??'';$usr=getUserByUsername($u);
    if($usr&&$usr['password']===$p){$_SESSION['user_id']=$usr['id'];header('Location:?page=my_account');exit;}
    $userLoginError='Invalid credentials';
}
if(in_array($page,['dashboard','users','online','settings'])&&!$isAdmin){header('Location:?page=login');exit;}
if($page==='my_account'&&!$isUser){header('Location:?page=user_login');exit;}
if($isAdmin&&$page==='login'){header('Location:?page=dashboard');exit;}

// Handle actions
if($isAdmin&&$action){
    switch($action){
        case 'create_user':
            $un=trim($_POST['username']??'');$pw=$_POST['password']??'';if(!$pw)$pw=rndPass();
            if(strlen($un)>=3){
                $r=createUser(['username'=>$un,'password'=>$pw,'expires_at'=>date('Y-m-d H:i:s',time()+(int)($_POST['days']??30)*86400),'max_conn'=>(int)($_POST['max_conn']??1),'data_limit'=>(float)($_POST['data_limit']??0),'bw_limit'=>(int)($_POST['bw_limit']??0)]);
                $_SESSION['flash']=$r['ok']?"User '$un' created!":"Error: ".($r['err']??'');
            } header('Location:?page=users');exit;
        case 'delete_user':
            $u=getUserById((int)($_POST['id']??0)); if($u){deleteUser($u['id']);$_SESSION['flash']="User '{$u['username']}' deleted!";} header('Location:?page=users');exit;
        case 'toggle_user':
            toggleUser((int)($_POST['id']??0)); header('Location:?page=users');exit;
        case 'kick_user':
            $u=getUserById((int)($_POST['id']??0)); if($u){kickUser($u['username']);updateUserDB($u['id'],['is_online'=>0,'cur_conn'=>0,'ips'=>'']);$_SESSION['flash']="Kicked!";} header('Location:?page=online');exit;
        case 'update_user':
            $u=getUserById((int)($_POST['id']??0)); if($u){
                $d=[]; if(!empty($_POST['password'])){$d['password']=$_POST['password'];$su=escapeshellarg($u['username']);$sp=escapeshellarg($_POST['password']);exec("echo $su:$sp|chpasswd 2>/dev/null");}
                if(isset($_POST['days']))$d['expires_at']=date('Y-m-d H:i:s',time()+(int)$_POST['days']*86400);
                if(isset($_POST['max_conn']))$d['max_conn']=(int)$_POST['max_conn'];
                if(isset($_POST['data_limit']))$d['data_limit']=(float)$_POST['data_limit'];
                if(isset($_POST['bw_limit']))$d['bw_limit']=(int)$_POST['bw_limit'];
                if($d)updateUserDB($u['id'],$d); $_SESSION['flash']="Updated!";
            } header('Location:?page=users');exit;
        case 'reset_data':
            updateUserDB((int)($_POST['id']??0),['data_used'=>0]); $_SESSION['flash']="Data reset!"; header('Location:?page=users');exit;
        case 'save_settings':
            if(!empty($_POST['ssh_port']))changeSSHPort((int)$_POST['ssh_port']);
            if(!empty($_POST['admin_user']))setSetting('admin_user',$_POST['admin_user']);
            if(!empty($_POST['admin_pass']))setSetting('admin_pass',$_POST['admin_pass']);
            if(isset($_POST['server_ip']))setSetting('server_ip',$_POST['server_ip']);
            if(isset($_POST['tg_token']))setSetting('tg_token',$_POST['tg_token']);
            $_SESSION['flash']="Saved!"; header('Location:?page=settings');exit;
        case 'add_chat': $c=trim($_POST['chat_id']??''); if($c)addTgChat($c); header('Location:?page=settings');exit;
        case 'remove_chat': removeTgChat($_POST['chat_id']??''); header('Location:?page=settings');exit;
        case 'test_telegram': sendTgMsg("✅ <b>SSH Panel Test</b>\n📅 ".date('Y-m-d H:i:s')."\n🖥 ".getServerIP()); $_SESSION['flash']="Sent!"; header('Location:?page=settings');exit;
        case 'backup':
            $b=createBackup(); header('Content-Type:application/sql'); header('Content-Disposition:attachment;filename="'.$b['fn'].'"'); readfile($b['fp']); exit;
        case 'telegram_backup':
            $b=createBackup(); $tk=getSetting('tg_token');
            foreach(getTgChats() as $ch){$c=curl_init("https://api.telegram.org/bot{$tk}/sendDocument");curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['chat_id'=>$ch,'document'=>new CURLFile($b['fp']),'caption'=>"📦 Backup ".date('Y-m-d H:i:s')],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false]);curl_exec($c);curl_close($c);}
            $_SESSION['flash']="Backup sent!"; header('Location:?page=settings');exit;
    }
}

$users=getUsers(); $onlineUsers=array_filter($users,fn($u)=>$u['is_online']); $totalData=array_sum(array_column($users,'data_used'));
$expiredCount=count(array_filter($users,fn($u)=>daysLeft($u['expires_at'])<=0)); $pausedCount=count(array_filter($users,fn($u)=>!$u['is_enabled']));
$serverIP=getServerIP(); $sshPort=getSetting('ssh_port','22'); $tgChats=getTgChats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SSH Panel</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body{background:#0a0e17;font-family:system-ui,sans-serif}
.card{background:linear-gradient(135deg,#1a2235,#111827);border:1px solid rgba(59,130,246,.15)}
.inp{background:#0a0e17;border:1px solid rgba(59,130,246,.2);color:#e2e8f0;border-radius:8px;padding:8px 14px;width:100%;font-size:14px}
.inp:focus{border-color:#3b82f6;outline:none}
.btn{background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;font-weight:600;border-radius:8px;border:none;cursor:pointer;transition:.2s}
.btn:hover{filter:brightness(1.15)}
.btn-r{background:linear-gradient(135deg,#ef4444,#dc2626)}
.btn-g{background:linear-gradient(135deg,#10b981,#059669)}
.btn-y{background:linear-gradient(135deg,#f59e0b,#d97706)}
.si{border-left:3px solid transparent;transition:.2s}.si:hover,.si.a{background:rgba(59,130,246,.1);border-left-color:#3b82f6}.si.a{color:#60a5fa}
.bdg{padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
.pulse{animation:p 2s infinite}@keyframes p{0%,100%{opacity:1}50%{opacity:.4}}
.pre{cursor:pointer;transition:.15s;user-select:none}.pre:hover{background:rgba(59,130,246,.3)!important;color:#fff!important}
.mbg{background:rgba(0,0,0,.75);backdrop-filter:blur(4px)}
</style>
</head>
<body class="text-gray-200 min-h-screen">

<?php if($flash):?><div id="fl" class="fixed top-4 right-4 z-50 px-5 py-3 rounded-xl text-sm font-medium bg-green-500/20 text-green-400 border border-green-500/30"><?=htmlspecialchars($flash)?></div><script>setTimeout(()=>document.getElementById('fl')?.remove(),4000)</script><?php endif;?>

<?php
// ================== ADMIN LOGIN ==================
if($page==='login'):?>
<div class="min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-md">
<div class="text-center mb-8"><div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mb-4 shadow-lg shadow-blue-500/30 text-4xl">🖥️</div><h1 class="text-3xl font-bold text-white">SSH Panel</h1><p class="text-gray-500 mt-1 text-sm">Admin Dashboard</p></div>
<div class="card rounded-2xl p-8">
<form method="POST" class="space-y-4">
<div><label class="block text-xs text-gray-400 mb-1">Username</label><input type="text" name="username" value="admin" class="inp" required></div>
<div><label class="block text-xs text-gray-400 mb-1">Password</label><input type="password" name="password" class="inp" placeholder="admin" required></div>
<?php if(isset($loginError)):?><p class="text-red-400 text-sm text-center bg-red-500/10 border border-red-500/20 rounded-lg p-2"><?=$loginError?></p><?php endif;?>
<button type="submit" class="btn w-full py-3">Login</button>
</form>
<div class="mt-6 pt-4 border-t border-gray-700/40 text-center"><a href="?page=user_login" class="text-blue-400 hover:text-blue-300 text-sm font-medium">SSH User Login →</a></div>
</div></div></div>

<?php
// ================== USER LOGIN ==================
elseif($page==='user_login'):?>
<div class="min-h-screen flex items-center justify-center p-4">
<div class="card rounded-2xl p-8 w-full max-w-md">
<div class="text-center mb-8"><div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mb-4 text-3xl">👤</div><h1 class="text-2xl font-bold text-white">My Account</h1></div>
<form method="POST" class="space-y-4">
<div><label class="block text-xs text-gray-400 mb-1">Username</label><input type="text" name="username" class="inp" required></div>
<div><label class="block text-xs text-gray-400 mb-1">Password</label><input type="password" name="password" class="inp" required></div>
<?php if(isset($userLoginError)):?><p class="text-red-400 text-sm text-center bg-red-500/10 rounded-lg p-2"><?=$userLoginError?></p><?php endif;?>
<button type="submit" class="btn w-full py-3">Login</button>
</form>
<p class="text-center mt-5"><a href="?page=login" class="text-gray-500 text-xs">← Admin</a></p>
</div></div>

<?php
// ================== USER DASHBOARD ==================
elseif($page==='my_account'&&$isUser):
$me=getUserById($_SESSION['user_id']); if(!$me){header('Location:?page=user_logout');exit;}
$dl=daysLeft($me['expires_at']); $exp=$dl<=0; $dp=$me['data_limit']>0?min(($me['data_used']/$me['data_limit'])*100,100):0;
$cfgs=getConfigs($me);
?>
<div class="min-h-screen p-4 md:p-8"><div class="max-w-4xl mx-auto">
<div class="flex items-center justify-between mb-6">
<div class="flex items-center gap-3"><div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-lg">👤</div><div><h1 class="text-xl font-bold text-white"><?=htmlspecialchars($me['username'])?></h1><p class="text-xs text-gray-400">SSH Account</p></div></div>
<a href="?page=user_logout" class="text-gray-400 hover:text-white text-sm">Logout</a>
</div>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
<div class="card rounded-2xl p-4"><p class="text-gray-400 text-xs">⏱ Time</p><p class="text-2xl font-bold mt-1 <?=$exp?'text-red-400':($dl<=3?'text-yellow-400':'text-green-400')?>"><?=$exp?'Expired':$dl.'d'?></p></div>
<div class="card rounded-2xl p-4"><p class="text-gray-400 text-xs">📊 Data</p><p class="text-2xl font-bold text-cyan-400 mt-1"><?=fmtData($me['data_used'])?></p><?php if($me['data_limit']>0):?><div class="h-1.5 bg-blue-500/20 rounded-full mt-2"><div class="h-full rounded-full" style="width:<?=$dp?>%;background:<?=$dp>90?'#ef4444':($dp>70?'#f59e0b':'#3b82f6')?>"></div></div><p class="text-[10px] text-gray-500 mt-0.5">/ <?=fmtData($me['data_limit'])?></p><?php endif;?></div>
<div class="card rounded-2xl p-4"><p class="text-gray-400 text-xs">📶 Conn</p><p class="text-2xl font-bold text-purple-400 mt-1"><?=$me['cur_conn']?><span class="text-gray-500 text-base">/<?=$me['max_conn']?></span></p></div>
<div class="card rounded-2xl p-4"><p class="text-gray-400 text-xs">🚀 BW</p><p class="text-2xl font-bold text-yellow-400 mt-1"><?=$me['bw_limit']>0?$me['bw_limit'].'K':'∞'?></p></div>
</div>
<div class="card rounded-2xl p-5 mb-6">
<h3 class="text-base font-semibold text-white mb-3">🖥️ Server</h3>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
<div class="bg-[#0a0e17]/50 rounded-lg p-3"><span class="text-gray-500 text-xs">IP</span><p class="text-white font-mono mt-0.5 text-sm"><?=$serverIP?></p></div>
<div class="bg-[#0a0e17]/50 rounded-lg p-3"><span class="text-gray-500 text-xs">Port</span><p class="text-white font-mono mt-0.5"><?=$sshPort?></p></div>
<div class="bg-[#0a0e17]/50 rounded-lg p-3"><span class="text-gray-500 text-xs">User</span><p class="text-white font-mono mt-0.5"><?=htmlspecialchars($me['username'])?></p></div>
<div class="bg-[#0a0e17]/50 rounded-lg p-3"><span class="text-gray-500 text-xs">Status</span><p class="mt-0.5"><span class="bdg <?=$exp?'bg-red-500/20 text-red-400':(!$me['is_enabled']?'bg-yellow-500/20 text-yellow-400':'bg-blue-500/20 text-blue-400')?>"><?=$exp?'Expired':(!$me['is_enabled']?'Paused':'Active')?></span></p></div>
</div></div>
<div class="card rounded-2xl p-5"><h3 class="text-base font-semibold text-white mb-4">📱 Connection Configs</h3>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
<?php foreach($cfgs as $i=>$cfg):?>
<div class="bg-[#0a0e17]/50 border border-blue-500/10 rounded-xl p-4">
<p class="font-semibold text-white mb-3"><?=$cfg['icon']?> <?=$cfg['name']?></p>
<div class="flex justify-center mb-3"><img id="qr-u-<?=$i?>" class="rounded-lg" width="140" height="140" alt="QR"></div>
<div class="bg-[#0a0e17] rounded-lg p-2 mb-3"><p class="text-[10px] text-gray-400 break-all font-mono max-h-12 overflow-y-auto" id="uri-u-<?=$i?>"><?=htmlspecialchars($cfg['uri'])?></p></div>
<button onclick="cpU('uri-u-<?=$i?>',this)" class="w-full py-2 rounded-lg text-sm bg-blue-500/10 text-blue-400 border border-blue-500/20 hover:bg-blue-500/20">📋 Copy</button>
</div>
<?php endforeach;?>
</div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
function cpU(id,b){navigator.clipboard.writeText(document.getElementById(id).textContent);b.textContent='✅ Copied!';setTimeout(()=>b.textContent='📋 Copy',1500)}
document.addEventListener('DOMContentLoaded',function(){
<?php foreach($cfgs as $i=>$cfg):?>
QRCode.toDataURL(<?=json_encode($cfg['uri'])?>,{width:140,margin:1},function(e,url){if(!e)document.getElementById('qr-u-<?=$i?>').src=url;});
<?php endforeach;?>
});
</script>

<?php
// ================== ADMIN PANEL ==================
elseif($isAdmin):?>
<div class="flex min-h-screen">
<aside class="hidden md:flex w-56 flex-col border-r border-blue-500/10" style="background:linear-gradient(180deg,#111827,#0d1321)">
<div class="p-4 border-b border-blue-500/10 flex items-center gap-3"><div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-lg">🖥️</div><div><p class="text-sm font-bold text-white">SSH Panel</p><p class="text-[10px] text-gray-500">v<?=VERSION?></p></div></div>
<nav class="flex-1 py-3 px-2 space-y-0.5">
<?php foreach([['dashboard','📊 Dashboard',count($users)],['users','👥 Users',count($users)],['online','🟢 Online',count($onlineUsers)],['settings','⚙️ Settings',null]] as $n):?>
<a href="?page=<?=$n[0]?>" class="si <?=$page===$n[0]?'a':''?> flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium text-gray-400 hover:text-white">
<span><?=$n[1]?></span><?php if($n[2]!==null):?><span class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-700/60 text-gray-500"><?=$n[2]?></span><?php endif;?>
</a><?php endforeach;?>
</nav>
<div class="p-3 border-t border-blue-500/10"><a href="?page=logout" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm text-red-400 hover:bg-red-500/10">🚪 Logout</a></div>
</aside>
<div class="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-[#111827] border-t border-blue-500/10 flex">
<?php foreach([['dashboard','📊'],['users','👥'],['online','🟢'],['settings','⚙️'],['logout','🚪']] as $n):?>
<a href="?page=<?=$n[0]?>" class="flex-1 py-3 text-center text-xs <?=$page===$n[0]?'text-blue-400':'text-gray-500'?>"><?=$n[1]?></a>
<?php endforeach;?>
</div>
<main class="flex-1 p-4 md:p-6 overflow-y-auto pb-20 md:pb-6">

<?php if($page==='dashboard'):?>
<div class="space-y-5"><h1 class="text-xl font-bold text-white">📊 Dashboard</h1>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
<div class="card rounded-2xl p-5"><p class="text-gray-400 text-xs">Users</p><p class="text-3xl font-bold text-white mt-1"><?=count($users)?></p></div>
<div class="card rounded-2xl p-5"><p class="text-gray-400 text-xs">Online</p><p class="text-3xl font-bold text-green-400 mt-1"><?=count($onlineUsers)?></p></div>
<div class="card rounded-2xl p-5"><p class="text-gray-400 text-xs">Data</p><p class="text-3xl font-bold text-cyan-400 mt-1"><?=fmtData($totalData)?></p></div>
<div class="card rounded-2xl p-5"><p class="text-gray-400 text-xs">Exp/Paused</p><p class="text-3xl font-bold text-red-400 mt-1"><?=$expiredCount?>/<?=$pausedCount?></p></div>
</div>
<div class="card rounded-2xl p-5"><h3 class="text-base font-semibold text-white mb-3">🟢 Online (<?=count($onlineUsers)?>)</h3>
<?php if(empty($onlineUsers)):?><p class="text-gray-500 text-center py-6 text-sm">Nobody online</p>
<?php else:?><div class="space-y-2"><?php foreach($onlineUsers as $u):?>
<div class="flex items-center justify-between bg-[#0a0e17]/50 rounded-lg px-4 py-2.5"><div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-green-400 pulse"></span><span class="text-white text-sm font-medium"><?=htmlspecialchars($u['username'])?></span></div>
<div class="flex items-center gap-3 text-xs"><span class="text-cyan-400"><?=$u['cur_conn']?>/<?=$u['max_conn']?></span><span class="text-gray-400"><?=fmtData($u['data_used'])?></span>
<?php foreach(array_filter(explode(',',$u['ips'])) as $ip):?><span class="bg-gray-700/60 px-1.5 py-0.5 rounded text-gray-300"><?=htmlspecialchars($ip)?></span><?php endforeach;?></div></div>
<?php endforeach;?></div><?php endif;?></div></div>

<?php elseif($page==='users'):?>
<div class="space-y-4">
<div class="flex items-center justify-between"><h1 class="text-xl font-bold text-white">👥 Users (<?=count($users)?>)</h1>
<button onclick="document.getElementById('cm').classList.remove('hidden')" class="btn px-4 py-2 text-sm">➕ Create</button></div>
<div class="card rounded-2xl overflow-hidden"><div class="overflow-x-auto">
<table class="w-full text-sm"><thead><tr class="text-gray-400 text-left border-b border-blue-500/10 bg-[#0a0e17]/30 text-xs">
<th class="py-2.5 px-3">User</th><th class="py-2.5 px-3">Pass</th><th class="py-2.5 px-3">Status</th><th class="py-2.5 px-3">Exp</th><th class="py-2.5 px-3">Conn</th><th class="py-2.5 px-3">Data</th><th class="py-2.5 px-3">BW</th><th class="py-2.5 px-3 text-right">Act</th>
</tr></thead><tbody>
<?php foreach($users as $u): $dl=daysLeft($u['expires_at']); $exp=$dl<=0; $dp=$u['data_limit']>0?min(($u['data_used']/$u['data_limit'])*100,100):0; ?>
<tr class="border-b border-blue-500/5 hover:bg-blue-500/5">
<td class="py-2.5 px-3 text-white font-medium"><?=htmlspecialchars($u['username'])?></td>
<td class="py-2.5 px-3"><div class="flex items-center gap-1.5"><span class="font-mono text-[11px] text-gray-400" id="p-<?=$u['id']?>" data-p="<?=htmlspecialchars($u['password'])?>">••••••</span><button onclick="tP(<?=$u['id']?>)" class="text-gray-500 hover:text-white text-[11px]">👁</button><button onclick="cP(<?=$u['id']?>)" class="text-gray-500 hover:text-blue-400 text-[11px]">📋</button></div></td>
<td class="py-2.5 px-3"><?php if($u['is_online']):?><span class="bdg bg-green-500/20 text-green-400 border border-green-500/30">● On</span><?php elseif($exp):?><span class="bdg bg-red-500/20 text-red-400 border border-red-500/30">Exp</span><?php elseif(!$u['is_enabled']):?><span class="bdg bg-yellow-500/20 text-yellow-400 border border-yellow-500/30">Paused</span><?php else:?><span class="bdg bg-gray-500/20 text-gray-400 border border-gray-500/30">Off</span><?php endif;?></td>
<td class="py-2.5 px-3 text-xs <?=$exp?'text-red-400':($dl<=3?'text-yellow-400':'text-gray-300')?>"><?=$exp?'-'.abs($dl).'d':$dl.'d'?></td>
<td class="py-2.5 px-3 text-xs"><span class="text-cyan-400"><?=$u['cur_conn']?></span>/<span class="text-gray-500"><?=$u['max_conn']?></span></td>
<td class="py-2.5 px-3"><span class="text-[11px] text-gray-300"><?=fmtData($u['data_used'])?></span><span class="text-[11px] text-gray-500">/<?=$u['data_limit']>0?fmtData($u['data_limit']):'∞'?></span>
<?php if($u['data_limit']>0):?><div class="w-14 h-1 bg-blue-500/20 rounded-full mt-1"><div class="h-full rounded-full" style="width:<?=$dp?>%;background:<?=$dp>90?'#ef4444':($dp>70?'#f59e0b':'#3b82f6')?>"></div></div><?php endif;?></td>
<td class="py-2.5 px-3 text-gray-400 text-[11px]"><?=$u['bw_limit']>0?$u['bw_limit'].'K':'∞'?></td>
<td class="py-2.5 px-3"><div class="flex items-center justify-end gap-0.5">
<button onclick="sQR(<?=$u['id']?>)" class="p-1.5 rounded hover:bg-purple-500/20 text-purple-400 text-xs">📱</button>
<button onclick="sEd(<?=$u['id']?>)" class="p-1.5 rounded hover:bg-blue-500/20 text-blue-400 text-xs">✏️</button>
<form method="POST" class="inline"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?=$u['id']?>"><button type="submit" class="p-1.5 rounded <?=$u['is_enabled']?'hover:bg-yellow-500/20 text-yellow-400':'hover:bg-green-500/20 text-green-400'?> text-xs">⚡</button></form>
<form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?=$u['id']?>"><button type="submit" class="p-1.5 rounded hover:bg-red-500/20 text-red-400 text-xs">🗑️</button></form>
</div></td></tr>
<?php endforeach;?></tbody></table></div>
<?php if(!$users):?><p class="text-center py-10 text-gray-500 text-sm">No users</p><?php endif;?></div></div>

<!-- Create -->
<div id="cm" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 mbg" onclick="this.classList.add('hidden')">
<div class="card rounded-2xl p-6 w-full max-w-lg" onclick="event.stopPropagation()">
<div class="flex items-center justify-between mb-4"><h2 class="text-lg font-bold text-white">➕ Create</h2><button onclick="document.getElementById('cm').classList.add('hidden')" class="text-gray-400 hover:text-white">✕</button></div>
<form method="POST" class="space-y-3"><input type="hidden" name="action" value="create_user">
<div><label class="block text-xs text-gray-400 mb-1">Username</label><input type="text" name="username" class="inp" required pattern="[a-zA-Z][a-zA-Z0-9_]{2,}"></div>
<div><label class="block text-xs text-gray-400 mb-1">Password (blank=random)</label><div class="flex gap-2"><input type="text" name="password" id="np" class="inp"><button type="button" onclick="document.getElementById('np').value=rP()" class="btn px-3 py-2 text-sm">🔄</button></div></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="block text-xs text-gray-400 mb-1">Days</label><input type="number" name="days" value="30" class="inp" min="1"></div>
<div><label class="block text-xs text-gray-400 mb-1">Max Conn</label><input type="number" name="max_conn" value="1" class="inp" min="1" max="10"></div>
</div>
<div class="grid grid-cols-2 gap-3">
<div><label class="block text-xs text-gray-400 mb-1">Data (MB)</label><input type="number" name="data_limit" id="nd" value="0" class="inp" min="0">
<div class="flex flex-wrap gap-1 mt-1.5"><?php foreach([0=>'∞',1024=>'1G',5120=>'5G',10240=>'10G',51200=>'50G'] as $v=>$l):?><span onclick="document.getElementById('nd').value=<?=$v?>" class="pre text-[10px] px-2 py-1 rounded bg-gray-700/60 text-gray-400"><?=$l?></span><?php endforeach;?></div></div>
<div><label class="block text-xs text-gray-400 mb-1">BW (Kbps)</label><input type="number" name="bw_limit" id="nb" value="0" class="inp" min="0">
<div class="flex flex-wrap gap-1 mt-1.5"><?php foreach([0=>'∞',512=>'512K',1024=>'1M',2048=>'2M',4096=>'4M'] as $v=>$l):?><span onclick="document.getElementById('nb').value=<?=$v?>" class="pre text-[10px] px-2 py-1 rounded bg-gray-700/60 text-gray-400"><?=$l?></span><?php endforeach;?></div></div>
</div>
<button type="submit" class="btn w-full py-2.5">Create</button>
</form></div></div>

<!-- Edit -->
<div id="em" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 mbg" onclick="this.classList.add('hidden')">
<div class="card rounded-2xl p-6 w-full max-w-lg" onclick="event.stopPropagation()">
<div class="flex items-center justify-between mb-4"><h2 class="text-lg font-bold text-white">✏️ <span id="en"></span></h2><button onclick="document.getElementById('em').classList.add('hidden')" class="text-gray-400 hover:text-white">✕</button></div>
<form method="POST" class="space-y-3"><input type="hidden" name="action" value="update_user"><input type="hidden" name="id" id="ei">
<div><label class="block text-xs text-gray-400 mb-1">Password (blank=keep)</label><div class="flex gap-2"><input type="text" name="password" id="ep" class="inp"><button type="button" onclick="document.getElementById('ep').value=rP()" class="btn px-3 py-2 text-sm">🔄</button></div></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="block text-xs text-gray-400 mb-1">Days</label><input type="number" name="days" id="ed" class="inp" min="1"></div>
<div><label class="block text-xs text-gray-400 mb-1">Max Conn</label><input type="number" name="max_conn" id="ec" class="inp" min="1" max="10"></div>
</div>
<div class="grid grid-cols-2 gap-3">
<div><label class="block text-xs text-gray-400 mb-1">Data (MB)</label><input type="number" name="data_limit" id="edl" class="inp" min="0">
<div class="flex flex-wrap gap-1 mt-1.5"><?php foreach([0=>'∞',1024=>'1G',5120=>'5G',10240=>'10G',51200=>'50G'] as $v=>$l):?><span onclick="document.getElementById('edl').value=<?=$v?>" class="pre text-[10px] px-2 py-1 rounded bg-gray-700/60 text-gray-400"><?=$l?></span><?php endforeach;?></div></div>
<div><label class="block text-xs text-gray-400 mb-1">BW (Kbps)</label><input type="number" name="bw_limit" id="eb" class="inp" min="0">
<div class="flex flex-wrap gap-1 mt-1.5"><?php foreach([0=>'∞',512=>'512K',1024=>'1M',2048=>'2M',4096=>'4M'] as $v=>$l):?><span onclick="document.getElementById('eb').value=<?=$v?>" class="pre text-[10px] px-2 py-1 rounded bg-gray-700/60 text-gray-400"><?=$l?></span><?php endforeach;?></div></div>
</div>
<div class="flex gap-2"><button type="submit" class="btn flex-1 py-2.5">Save</button><button type="submit" name="action" value="reset_data" class="btn-y px-4 py-2.5 text-white rounded-lg text-sm font-semibold">Reset Data</button></div>
</form></div></div>

<!-- QR -->
<div id="qm" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 mbg" onclick="this.classList.add('hidden')">
<div class="card rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
<div class="flex items-center justify-between mb-4"><h2 class="text-lg font-bold text-white">📱 <span id="qn"></span></h2><button onclick="document.getElementById('qm').classList.add('hidden')" class="text-gray-400 hover:text-white">✕</button></div>
<div id="qc" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
const UD=<?=json_encode(array_map(function($u){return['id'=>$u['id'],'username'=>$u['username'],'password'=>$u['password'],'expires_at'=>$u['expires_at'],'max_conn'=>$u['max_conn'],'data_limit'=>$u['data_limit'],'bw_limit'=>$u['bw_limit']];},array_values($users)))?>;
const SIP=<?=json_encode($serverIP)?>,SPT=<?=json_encode($sshPort)?>;

function rP(n=12){const c='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';let s='';for(let i=0;i<n;i++)s+=c[Math.floor(Math.random()*c.length)];return s}
function tP(id){const e=document.getElementById('p-'+id);e.textContent=e.textContent==='••••••'?e.dataset.p:'••••••'}
function cP(id){const e=document.getElementById('p-'+id);navigator.clipboard.writeText(e.dataset.p);e.textContent='✅';setTimeout(()=>e.textContent='••••••',1500)}

function sEd(id){
    const u=UD.find(x=>x.id==id);if(!u)return;
    document.getElementById('em').classList.remove('hidden');
    document.getElementById('en').textContent=u.username;
    document.getElementById('ei').value=u.id;
    document.getElementById('ep').value='';
    document.getElementById('ed').value=Math.max(Math.ceil((new Date(u.expires_at).getTime()-Date.now())/86400000),1);
    document.getElementById('ec').value=u.max_conn;
    document.getElementById('edl').value=u.data_limit;
    document.getElementById('eb').value=u.bw_limit;
}

function mkConfigs(u){
    const ep=encodeURIComponent(u.password);
    const port=parseInt(SPT);
    // NapsternetV JSON
    const npvObj={sshConfigType:"SSH-Direct",sni:"",tlsVersion:"DEFAULT",httpProxy:"",authenticateProxy:false,proxyUsername:"",proxyPassword:"",payload:"",dnsTTMode:"UDP",dnsServer:"",nameserver:"",publicKey:"",udpgwPort:0,remarks:u.username,sshHost:SIP,sshPort:port,sshUsername:u.username,sshPassword:u.password,udpgwTransparentDNS:true};
    // Rocket JSON
    const rktObj={host:SIP,port:port,username:u.username,password:u.password,remark:u.username,udpgw:"127.0.0.1:7300"};
    return[
        {name:'NetMod',icon:'🔧',uri:'ssh://'+u.username+':'+u.password+'@'+SIP+':'+SPT+'#'+u.username},
        {name:'NapsternetV',icon:'🌐',uri:'npvt-ssh://'+btoa(JSON.stringify(npvObj))},
        {name:'Rocket SSH',icon:'🚀',uri:'rocket-ssh://'+btoa(JSON.stringify(rktObj))},
        {name:'V2Box / MRZ',icon:'📦',uri:'ssh://'+u.username+':'+u.password+'@'+SIP+':'+SPT+'#'+u.username},
    ];
}

function sQR(id){
    const u=UD.find(x=>x.id==id);if(!u)return;
    document.getElementById('qm').classList.remove('hidden');
    document.getElementById('qn').textContent=u.username;
    const cfgs=mkConfigs(u);
    const c=document.getElementById('qc');
    c.innerHTML=cfgs.map((cfg,i)=>`
        <div class="bg-[#0a0e17]/50 border border-blue-500/10 rounded-xl p-4">
            <p class="font-semibold text-white text-sm mb-3">${cfg.icon} ${cfg.name}</p>
            <div class="flex justify-center mb-3"><img id="qi-${i}" class="rounded-lg" width="140" height="140" alt="QR"></div>
            <div class="bg-[#0a0e17] rounded-lg p-2 mb-3"><p class="text-[9px] text-gray-400 break-all font-mono max-h-12 overflow-y-auto" id="qu-${i}">${cfg.uri.replace(/</g,'&lt;')}</p></div>
            <button onclick="cQR(${i})" id="qb-${i}" class="w-full py-2 rounded-lg text-sm bg-blue-500/10 text-blue-400 border border-blue-500/20 hover:bg-blue-500/20">📋 Copy</button>
        </div>`).join('');
    setTimeout(()=>{
        cfgs.forEach((cfg,i)=>{
            QRCode.toDataURL(cfg.uri,{width:140,margin:1,errorCorrectionLevel:'L'},function(err,url){
                if(!err)document.getElementById('qi-'+i).src=url;
            });
        });
    },50);
}
function cQR(i){navigator.clipboard.writeText(document.getElementById('qu-'+i).textContent);document.getElementById('qb-'+i).textContent='✅ Copied!';setTimeout(()=>document.getElementById('qb-'+i).textContent='📋 Copy',1500)}
</script>

<?php elseif($page==='online'):?>
<div class="space-y-4"><h1 class="text-xl font-bold text-white">🟢 Online <span class="bdg bg-green-500/20 text-green-400 ml-2"><?=count($onlineUsers)?></span></h1>
<?php if(empty($onlineUsers)):?><div class="card rounded-2xl p-12 text-center text-gray-500 text-sm">Nobody online</div>
<?php else:?><div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
<?php foreach($onlineUsers as $u): $dl=daysLeft($u['expires_at']); $dp=$u['data_limit']>0?min(($u['data_used']/$u['data_limit'])*100,100):0;?>
<div class="card rounded-xl p-4">
<div class="flex items-center justify-between mb-3"><div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-green-400 pulse"></span><span class="font-semibold text-white text-sm"><?=htmlspecialchars($u['username'])?></span></div>
<form method="POST"><input type="hidden" name="action" value="kick_user"><input type="hidden" name="id" value="<?=$u['id']?>"><button type="submit" class="p-1 rounded hover:bg-red-500/20 text-red-400 text-xs">⚡Kick</button></form></div>
<div class="space-y-1.5 text-xs">
<div class="flex justify-between"><span class="text-gray-400">Conn</span><span class="text-cyan-400"><?=$u['cur_conn']?>/<?=$u['max_conn']?></span></div>
<div class="flex justify-between"><span class="text-gray-400">Time</span><span class="<?=$dl<=3?'text-yellow-400':'text-gray-300'?>"><?=$dl?>d</span></div>
<div class="flex justify-between"><span class="text-gray-400">Data</span><span class="text-gray-300"><?=fmtData($u['data_used'])?><?=$u['data_limit']>0?'/'.fmtData($u['data_limit']):''?></span></div>
<?php if($u['data_limit']>0):?><div class="h-1.5 bg-blue-500/20 rounded-full"><div class="h-full rounded-full" style="width:<?=$dp?>%;background:<?=$dp>90?'#ef4444':($dp>70?'#f59e0b':'#3b82f6')?>"></div></div><?php endif;?>
</div>
<?php $ips=array_filter(explode(',',$u['ips']));if($ips):?><div class="mt-2 pt-2 border-t border-gray-700/30"><div class="flex gap-1"><?php foreach($ips as $ip):?><span class="text-[10px] bg-gray-700/60 px-1.5 py-0.5 rounded text-gray-300 font-mono"><?=htmlspecialchars($ip)?></span><?php endforeach;?></div></div><?php endif;?>
</div><?php endforeach;?></div><?php endif;?></div>

<?php elseif($page==='settings'):?>
<div class="space-y-5 max-w-3xl"><h1 class="text-xl font-bold text-white">⚙️ Settings</h1>
<form method="POST"><input type="hidden" name="action" value="save_settings">
<div class="card rounded-2xl p-5 mb-5"><h3 class="text-base font-semibold text-white mb-3">🌐 SSH</h3>
<div class="grid grid-cols-2 gap-3">
<div><label class="block text-xs text-gray-400 mb-1">Port</label><input type="number" name="ssh_port" value="<?=htmlspecialchars($sshPort)?>" class="inp"></div>
<div><label class="block text-xs text-gray-400 mb-1">Server IP</label><input type="text" name="server_ip" value="<?=htmlspecialchars(getSetting('server_ip',''))?>" placeholder="<?=$serverIP?>" class="inp"></div>
</div></div>
<div class="card rounded-2xl p-5 mb-5"><h3 class="text-base font-semibold text-white mb-3">🔑 Admin</h3>
<div class="grid grid-cols-2 gap-3">
<div><label class="block text-xs text-gray-400 mb-1">User</label><input type="text" name="admin_user" value="<?=htmlspecialchars(getSetting('admin_user','admin'))?>" class="inp"></div>
<div><label class="block text-xs text-gray-400 mb-1">Pass</label><input type="text" name="admin_pass" value="<?=htmlspecialchars(getSetting('admin_pass','admin'))?>" class="inp"></div>
</div></div>
<div class="card rounded-2xl p-5 mb-5"><h3 class="text-base font-semibold text-white mb-3">🤖 Telegram</h3>
<div><label class="block text-xs text-gray-400 mb-1">Bot Token</label><input type="text" name="tg_token" value="<?=htmlspecialchars(getSetting('tg_token',''))?>" class="inp font-mono text-sm" placeholder="123456:ABC..."></div>
</div>
<button type="submit" class="btn px-6 py-2.5 text-sm">💾 Save</button>
</form>
<div class="card rounded-2xl p-5"><h3 class="text-base font-semibold text-white mb-3">💬 Chats</h3>
<form method="POST" class="flex gap-2 mb-3"><input type="hidden" name="action" value="add_chat"><input type="text" name="chat_id" class="inp flex-1" placeholder="Chat ID" required><button type="submit" class="btn px-4 py-2 text-sm">+</button></form>
<div class="flex flex-wrap gap-1.5 mb-4">
<?php foreach($tgChats as $ch):?><div class="flex items-center gap-1 bg-gray-700/60 px-2.5 py-1 rounded-full text-xs"><span class="text-gray-300"><?=htmlspecialchars($ch)?></span><form method="POST" class="inline"><input type="hidden" name="action" value="remove_chat"><input type="hidden" name="chat_id" value="<?=htmlspecialchars($ch)?>"><button type="submit" class="text-gray-500 hover:text-red-400 ml-0.5">✕</button></form></div><?php endforeach;?>
</div>
<form method="POST" class="inline"><input type="hidden" name="action" value="test_telegram"><button type="submit" class="btn px-4 py-2 text-sm">📤 Test</button></form>
</div>
<div class="card rounded-2xl p-5"><h3 class="text-base font-semibold text-white mb-3">💾 Backup</h3>
<div class="flex gap-3">
<form method="POST"><input type="hidden" name="action" value="backup"><button type="submit" class="btn-g px-4 py-2 text-white rounded-lg text-sm font-semibold">⬇️ SQL</button></form>
<form method="POST"><input type="hidden" name="action" value="telegram_backup"><button type="submit" class="btn px-4 py-2 text-sm">📤 TG</button></form>
</div></div></div>

<?php endif;?>
</main></div>
<script>
<?php if(in_array($page,['dashboard','online'])&&$isAdmin):?>setTimeout(()=>location.reload(),30000);<?php endif;?>
</script>
<?php endif;?>
</body></html>
