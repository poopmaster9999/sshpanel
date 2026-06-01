import { SSHUser, PanelSettings, BackupRecord } from './types';

const USERS_KEY = 'ssh_panel_users';
const SETTINGS_KEY = 'ssh_panel_settings';
const BACKUPS_KEY = 'ssh_panel_backups';

const defaultSettings: PanelSettings = {
  sshPort: 22,
  panelPort: 8080,
  adminUsername: 'admin',
  adminPassword: 'admin',
  telegramConfig: {
    botToken: '',
    chatIds: [],
    backupEnabled: false,
    backupInterval: 24,
    lastBackup: null,
  },
};

// Simulated connected IPs pool
const sampleIPs = [
  '192.168.1.45', '10.0.0.23', '172.16.0.88', '192.168.2.12',
  '10.0.1.55', '172.16.1.33', '192.168.3.77', '10.0.2.91',
  '203.0.113.50', '198.51.100.14', '192.0.2.88', '100.64.0.22',
];

function generateId(): string {
  return Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
}

function generatePassword(length = 12): string {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*';
  let password = '';
  for (let i = 0; i < length; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return password;
}

function generateUsername(): string {
  const prefixes = ['user', 'ssh', 'vpn', 'client', 'acc'];
  const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
  return `${prefix}_${Math.random().toString(36).substr(2, 5)}`;
}

// Initialize with demo data
function initializeDemoData(): SSHUser[] {
  const now = new Date();
  const demoUsers: SSHUser[] = [];

  const names = [
    'ali_tehran', 'reza_proxy', 'sara_vpn', 'mohammad_ssh',
    'fateme_net', 'hossein_v2', 'zahra_tun', 'amir_ssh',
    'mina_vpn', 'javad_proxy', 'neda_net', 'omid_ssh',
  ];

  for (let i = 0; i < names.length; i++) {
    const daysAgo = Math.floor(Math.random() * 30);
    const daysLeft = Math.floor(Math.random() * 60) - 10;
    const created = new Date(now.getTime() - daysAgo * 86400000);
    const expires = new Date(now.getTime() + daysLeft * 86400000);
    const isOnline = Math.random() > 0.5;
    const numConnections = isOnline ? Math.floor(Math.random() * 3) + 1 : 0;
    const connIPs: string[] = [];
    if (isOnline) {
      for (let j = 0; j < numConnections; j++) {
        connIPs.push(sampleIPs[Math.floor(Math.random() * sampleIPs.length)]);
      }
    }

    demoUsers.push({
      id: generateId(),
      username: names[i],
      password: generatePassword(10),
      createdAt: created.toISOString(),
      expiresAt: expires.toISOString(),
      maxConnections: Math.floor(Math.random() * 3) + 1,
      currentConnections: numConnections,
      dataLimit: [0, 1024, 2048, 5120, 10240][Math.floor(Math.random() * 5)],
      dataUsed: Math.floor(Math.random() * 4000),
      bandwidthLimit: [0, 512, 1024, 2048, 4096][Math.floor(Math.random() * 5)],
      isOnline,
      isEnabled: daysLeft > 0,
      lastConnected: isOnline ? now.toISOString() : new Date(now.getTime() - Math.random() * 86400000 * 5).toISOString(),
      connectedIPs: connIPs,
    });
  }

  return demoUsers;
}

export function getUsers(): SSHUser[] {
  const stored = localStorage.getItem(USERS_KEY);
  if (!stored) {
    const demo = initializeDemoData();
    localStorage.setItem(USERS_KEY, JSON.stringify(demo));
    return demo;
  }
  return JSON.parse(stored);
}

export function saveUsers(users: SSHUser[]): void {
  localStorage.setItem(USERS_KEY, JSON.stringify(users));
}

export function getSettings(): PanelSettings {
  const stored = localStorage.getItem(SETTINGS_KEY);
  if (!stored) {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(defaultSettings));
    return defaultSettings;
  }
  return JSON.parse(stored);
}

export function saveSettings(settings: PanelSettings): void {
  localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
}

export function getBackups(): BackupRecord[] {
  const stored = localStorage.getItem(BACKUPS_KEY);
  if (!stored) return [];
  return JSON.parse(stored);
}

export function saveBackups(backups: BackupRecord[]): void {
  localStorage.setItem(BACKUPS_KEY, JSON.stringify(backups));
}

export function addUser(user: Omit<SSHUser, 'id' | 'createdAt' | 'currentConnections' | 'dataUsed' | 'isOnline' | 'lastConnected' | 'connectedIPs'>): SSHUser {
  const users = getUsers();
  const newUser: SSHUser = {
    ...user,
    id: generateId(),
    createdAt: new Date().toISOString(),
    currentConnections: 0,
    dataUsed: 0,
    isOnline: false,
    lastConnected: null,
    connectedIPs: [],
  };
  users.push(newUser);
  saveUsers(users);
  return newUser;
}

export function updateUser(id: string, updates: Partial<SSHUser>): SSHUser | null {
  const users = getUsers();
  const index = users.findIndex(u => u.id === id);
  if (index === -1) return null;
  users[index] = { ...users[index], ...updates };
  saveUsers(users);
  return users[index];
}

export function deleteUser(id: string): boolean {
  const users = getUsers();
  const filtered = users.filter(u => u.id !== id);
  if (filtered.length === users.length) return false;
  saveUsers(filtered);
  return true;
}

export function createBackup(): BackupRecord {
  const users = getUsers();

  // Create SQL-format backup
  const sqlStatements: string[] = [
    '-- SSH Panel Backup',
    `-- Generated at: ${new Date().toISOString()}`,
    '',
    'CREATE TABLE IF NOT EXISTS ssh_users (',
    '  id TEXT PRIMARY KEY,',
    '  username TEXT NOT NULL,',
    '  password TEXT NOT NULL,',
    '  created_at TEXT NOT NULL,',
    '  expires_at TEXT NOT NULL,',
    '  max_connections INTEGER DEFAULT 1,',
    '  data_limit INTEGER DEFAULT 0,',
    '  data_used INTEGER DEFAULT 0,',
    '  bandwidth_limit INTEGER DEFAULT 0,',
    '  is_enabled INTEGER DEFAULT 1',
    ');',
    '',
    'DELETE FROM ssh_users;',
    '',
  ];

  users.forEach(u => {
    sqlStatements.push(
      `INSERT INTO ssh_users (id, username, password, created_at, expires_at, max_connections, data_limit, data_used, bandwidth_limit, is_enabled) VALUES ('${u.id}', '${u.username}', '${u.password}', '${u.createdAt}', '${u.expiresAt}', ${u.maxConnections}, ${u.dataLimit}, ${u.dataUsed}, ${u.bandwidthLimit}, ${u.isEnabled ? 1 : 0});`
    );
  });

  const sqlContent = sqlStatements.join('\n');
  const blob = new Blob([sqlContent], { type: 'application/sql' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const filename = `ssh_panel_backup_${new Date().toISOString().replace(/[:.]/g, '-')}.sql`;
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);

  const backup: BackupRecord = {
    id: generateId(),
    filename,
    createdAt: new Date().toISOString(),
    size: `${(sqlContent.length / 1024).toFixed(1)} KB`,
    type: 'manual',
  };

  const backups = getBackups();
  backups.unshift(backup);
  saveBackups(backups);

  return backup;
}

export function exportJSON(): void {
  const users = getUsers();
  const settings = getSettings();
  const data = JSON.stringify({ users, settings }, null, 2);
  const blob = new Blob([data], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `ssh_panel_export_${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
  a.click();
  URL.revokeObjectURL(url);
}

export { generatePassword, generateUsername, generateId };
