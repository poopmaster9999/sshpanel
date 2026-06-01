import { SSHUser, PanelSettings } from './types';

export interface ConnectionConfig {
  name: string;
  app: string;
  uri: string;
  icon: string;
}

export function generateConnectionConfigs(user: SSHUser, settings: PanelSettings, serverIP: string = '0.0.0.0'): ConnectionConfig[] {
  const port = settings.sshPort;
  
  // V2Box SSH config
  const v2boxConfig = `ssh://${user.username}:${user.password}@${serverIP}:${port}#${user.username}_SSH`;
  
  // Rocket Tunnel config  
  const rocketConfig = `rocket://ssh?server=${serverIP}&port=${port}&username=${user.username}&password=${encodeURIComponent(user.password)}&remark=${user.username}`;
  
  // NapsternetV config
  const napsterConfig = `napsternetv://ssh=${serverIP}:${port}@${user.username}:${encodeURIComponent(user.password)}#${user.username}`;
  
  // NetMod config
  const netmodConfig = `netmod://ssh?host=${serverIP}&port=${port}&user=${user.username}&pass=${encodeURIComponent(user.password)}&name=${user.username}`;

  return [
    { name: 'V2Box', app: 'v2box', uri: v2boxConfig, icon: '📦' },
    { name: 'Rocket Tunnel', app: 'rocket', uri: rocketConfig, icon: '🚀' },
    { name: 'NapsternetV', app: 'napsternet', uri: napsterConfig, icon: '🌐' },
    { name: 'NetMod', app: 'netmod', uri: netmodConfig, icon: '🔧' },
  ];
}

export function copyToClipboard(text: string): Promise<boolean> {
  return navigator.clipboard.writeText(text).then(() => true).catch(() => {
    // Fallback
    const textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
    return true;
  });
}
