import React, { useState } from 'react';
import { PanelSettings } from '../types';
import { getSettings, saveSettings, createBackup, getBackups, exportJSON } from '../store';
import { Settings as SettingsIcon, Save, Download, Send, Plus, X, Key, Globe, Bot, Database, Clock } from 'lucide-react';

interface SettingsProps {
  onRefresh: () => void;
}

const Settings: React.FC<SettingsProps> = ({ onRefresh }) => {
  const [settings, setSettings] = useState<PanelSettings>(getSettings());
  const [backups] = useState(getBackups());
  const [saved, setSaved] = useState(false);
  const [newChatId, setNewChatId] = useState('');
  const [testSent, setTestSent] = useState(false);

  const handleSave = () => {
    saveSettings(settings);
    setSaved(true);
    setTimeout(() => setSaved(false), 2000);
    onRefresh();
  };

  const handleAddChatId = () => {
    if (!newChatId.trim()) return;
    setSettings(s => ({
      ...s,
      telegramConfig: {
        ...s.telegramConfig,
        chatIds: [...s.telegramConfig.chatIds, newChatId.trim()],
      },
    }));
    setNewChatId('');
  };

  const handleRemoveChatId = (index: number) => {
    setSettings(s => ({
      ...s,
      telegramConfig: {
        ...s.telegramConfig,
        chatIds: s.telegramConfig.chatIds.filter((_, i) => i !== index),
      },
    }));
  };

  const handleTestTelegram = () => {
    setTestSent(true);
    setTimeout(() => setTestSent(false), 3000);
  };

  const handleBackup = () => {
    createBackup();
    onRefresh();
  };

  return (
    <div className="fade-in space-y-6 max-w-4xl">
      <h1 className="text-2xl font-bold text-white flex items-center gap-3">
        <SettingsIcon className="text-accent" size={28} />
        Panel Settings
      </h1>

      {/* SSH Port Settings */}
      <div className="glass-card glow-border p-6">
        <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
          <Globe size={20} className="text-cyan-400" />
          SSH Configuration
        </h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm text-gray-400 mb-1">SSH Port</label>
            <input
              type="number"
              value={settings.sshPort}
              onChange={e => setSettings(s => ({ ...s, sshPort: Number(e.target.value) }))}
              className="input-field"
              min="1"
              max="65535"
            />
            <p className="text-xs text-gray-500 mt-1">Default: 22. Change requires SSH service restart.</p>
          </div>
          <div>
            <label className="block text-sm text-gray-400 mb-1">Panel Port</label>
            <input
              type="number"
              value={settings.panelPort}
              onChange={e => setSettings(s => ({ ...s, panelPort: Number(e.target.value) }))}
              className="input-field"
              min="1"
              max="65535"
            />
            <p className="text-xs text-gray-500 mt-1">Port for this web panel.</p>
          </div>
        </div>
      </div>

      {/* Admin Credentials */}
      <div className="glass-card glow-border p-6">
        <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
          <Key size={20} className="text-yellow-400" />
          Admin Credentials
        </h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm text-gray-400 mb-1">Admin Username</label>
            <input
              type="text"
              value={settings.adminUsername}
              onChange={e => setSettings(s => ({ ...s, adminUsername: e.target.value }))}
              className="input-field"
            />
          </div>
          <div>
            <label className="block text-sm text-gray-400 mb-1">Admin Password</label>
            <input
              type="password"
              value={settings.adminPassword}
              onChange={e => setSettings(s => ({ ...s, adminPassword: e.target.value }))}
              className="input-field"
            />
          </div>
        </div>
      </div>

      {/* Telegram Bot Settings */}
      <div className="glass-card glow-border p-6">
        <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
          <Bot size={20} className="text-blue-400" />
          Telegram Bot Configuration
        </h3>
        <div className="space-y-4">
          <div>
            <label className="block text-sm text-gray-400 mb-1">Bot Token</label>
            <input
              type="text"
              value={settings.telegramConfig.botToken}
              onChange={e => setSettings(s => ({ ...s, telegramConfig: { ...s.telegramConfig, botToken: e.target.value } }))}
              className="input-field font-mono text-sm"
              placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz"
            />
          </div>

          <div>
            <label className="block text-sm text-gray-400 mb-1">Chat IDs (Users for backup notifications)</label>
            <div className="flex gap-2 mb-2">
              <input
                type="text"
                value={newChatId}
                onChange={e => setNewChatId(e.target.value)}
                className="input-field"
                placeholder="Enter Telegram Chat ID"
                onKeyDown={e => e.key === 'Enter' && handleAddChatId()}
              />
              <button onClick={handleAddChatId} className="btn-primary flex items-center gap-1">
                <Plus size={16} />
                Add
              </button>
            </div>
            <div className="flex flex-wrap gap-2">
              {settings.telegramConfig.chatIds.map((id, index) => (
                <div key={index} className="flex items-center gap-1 bg-dark-600 px-3 py-1 rounded-full text-sm">
                  <span className="text-gray-300">{id}</span>
                  <button onClick={() => handleRemoveChatId(index)} className="text-gray-500 hover:text-red-400">
                    <X size={14} />
                  </button>
                </div>
              ))}
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="flex items-center gap-3">
              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={settings.telegramConfig.backupEnabled}
                  onChange={e => setSettings(s => ({ ...s, telegramConfig: { ...s.telegramConfig, backupEnabled: e.target.checked } }))}
                  className="sr-only"
                />
                <div className={`w-11 h-6 rounded-full transition-colors ${settings.telegramConfig.backupEnabled ? 'bg-blue-500' : 'bg-dark-500'}`}>
                  <div className={`w-5 h-5 bg-white rounded-full shadow transform transition-transform mt-0.5 ${settings.telegramConfig.backupEnabled ? 'translate-x-5.5 ml-0.5' : 'translate-x-0.5'}`} />
                </div>
              </label>
              <span className="text-gray-300 text-sm">Auto Backup to Telegram</span>
            </div>
            <div>
              <label className="block text-sm text-gray-400 mb-1">Backup Interval (hours)</label>
              <input
                type="number"
                value={settings.telegramConfig.backupInterval}
                onChange={e => setSettings(s => ({ ...s, telegramConfig: { ...s.telegramConfig, backupInterval: Number(e.target.value) } }))}
                className="input-field"
                min="1"
                max="168"
              />
            </div>
          </div>

          <button onClick={handleTestTelegram} className="btn-primary flex items-center gap-2">
            <Send size={16} />
            {testSent ? '✓ Test Message Sent!' : 'Send Test Message'}
          </button>
        </div>
      </div>

      {/* Backup Section */}
      <div className="glass-card glow-border p-6">
        <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
          <Database size={20} className="text-green-400" />
          Backup & Restore
        </h3>
        <div className="flex flex-wrap gap-3 mb-4">
          <button onClick={handleBackup} className="btn-success flex items-center gap-2">
            <Download size={16} />
            Download SQL Backup
          </button>
          <button onClick={exportJSON} className="btn-primary flex items-center gap-2">
            <Download size={16} />
            Export JSON
          </button>
        </div>

        {backups.length > 0 && (
          <div>
            <h4 className="text-sm text-gray-400 mb-2 flex items-center gap-1">
              <Clock size={14} />
              Backup History
            </h4>
            <div className="space-y-2">
              {backups.slice(0, 5).map(backup => (
                <div key={backup.id} className="flex items-center justify-between bg-dark-900/50 rounded-lg px-4 py-2 text-sm">
                  <div className="flex items-center gap-3">
                    <Database size={14} className="text-green-400" />
                    <span className="text-gray-300 font-mono text-xs">{backup.filename}</span>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-gray-500 text-xs">{backup.size}</span>
                    <span className={`badge ${backup.type === 'auto' ? 'badge-active' : 'badge-online'}`}>{backup.type}</span>
                    <span className="text-gray-500 text-xs">{new Date(backup.createdAt).toLocaleString()}</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Save Button */}
      <div className="flex justify-end">
        <button onClick={handleSave} className={`flex items-center gap-2 px-8 py-3 rounded-xl font-semibold text-white transition-all ${saved ? 'bg-green-500' : 'btn-primary'}`}>
          <Save size={18} />
          {saved ? '✓ Settings Saved!' : 'Save All Settings'}
        </button>
      </div>
    </div>
  );
};

export default Settings;
