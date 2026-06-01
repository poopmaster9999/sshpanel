export interface SSHUser {
  id: string;
  username: string;
  password: string;
  createdAt: string;
  expiresAt: string;
  maxConnections: number;
  currentConnections: number;
  dataLimit: number; // in MB, 0 = unlimited
  dataUsed: number; // in MB
  bandwidthLimit: number; // in Kbps, 0 = unlimited
  isOnline: boolean;
  isEnabled: boolean;
  lastConnected: string | null;
  connectedIPs: string[];
}

export interface TelegramConfig {
  botToken: string;
  chatIds: string[];
  backupEnabled: boolean;
  backupInterval: number; // hours
  lastBackup: string | null;
}

export interface PanelSettings {
  sshPort: number;
  panelPort: number;
  adminUsername: string;
  adminPassword: string;
  telegramConfig: TelegramConfig;
}

export interface PanelStats {
  totalUsers: number;
  onlineUsers: number;
  totalDataUsed: number;
  activeConnections: number;
}

export interface BackupRecord {
  id: string;
  filename: string;
  createdAt: string;
  size: string;
  type: 'auto' | 'manual';
}
