import React from 'react';
import { SSHUser } from '../types';
import { getSettings } from '../store';
import { generateConnectionConfigs, copyToClipboard } from '../qrUtils';
import { QRCodeSVG } from 'qrcode.react';
import { Clock, Database, Wifi, Calendar, Shield, LogOut, Copy, Check, Activity } from 'lucide-react';

interface UserDashboardProps {
  user: SSHUser;
  onLogout: () => void;
}

const UserDashboard: React.FC<UserDashboardProps> = ({ user, onLogout }) => {
  const settings = getSettings();
  const configs = generateConnectionConfigs(user, settings, window.location.hostname || '0.0.0.0');
  const [copiedIndex, setCopiedIndex] = React.useState<number | null>(null);

  const daysLeft = Math.ceil((new Date(user.expiresAt).getTime() - Date.now()) / 86400000);
  const isExpired = daysLeft <= 0;
  const dataPercent = user.dataLimit > 0 ? Math.min((user.dataUsed / user.dataLimit) * 100, 100) : 0;

  const formatData = (mb: number): string => {
    if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`;
    return `${Math.round(mb)} MB`;
  };

  const handleCopy = async (uri: string, index: number) => {
    await copyToClipboard(uri);
    setCopiedIndex(index);
    setTimeout(() => setCopiedIndex(null), 2000);
  };

  const appColors: Record<string, string> = {
    v2box: '#3b82f6',
    rocket: '#ef4444',
    napsternet: '#10b981',
    netmod: '#8b5cf6',
  };

  return (
    <div className="min-h-screen p-4 md:p-8" style={{ background: 'linear-gradient(135deg, #0a0e17 0%, #111827 50%, #0a0e17 100%)' }}>
      <div className="max-w-4xl mx-auto fade-in">
        {/* Header */}
        <div className="flex items-center justify-between mb-6">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
              <Shield size={20} className="text-white" />
            </div>
            <div>
              <h1 className="text-xl font-bold text-white">Welcome, {user.username}</h1>
              <p className="text-sm text-gray-400">Your SSH Account Dashboard</p>
            </div>
          </div>
          <button onClick={onLogout} className="flex items-center gap-2 text-gray-400 hover:text-white transition-colors">
            <LogOut size={18} />
            <span className="hidden sm:inline">Logout</span>
          </button>
        </div>

        {/* Account Status */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <div className="stat-card p-4">
            <div className="flex items-center gap-2 text-gray-400 text-sm mb-2">
              <Clock size={16} />
              Time Remaining
            </div>
            <p className={`text-2xl font-bold ${isExpired ? 'text-red-400' : daysLeft <= 3 ? 'text-yellow-400' : 'text-green-400'}`}>
              {isExpired ? 'Expired' : `${daysLeft} days`}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              Expires: {new Date(user.expiresAt).toLocaleDateString()}
            </p>
          </div>

          <div className="stat-card p-4">
            <div className="flex items-center gap-2 text-gray-400 text-sm mb-2">
              <Database size={16} />
              Data Usage
            </div>
            <p className="text-2xl font-bold text-cyan-400">{formatData(user.dataUsed)}</p>
            <div className="mt-2">
              {user.dataLimit > 0 ? (
                <>
                  <div className="progress-bar mt-1">
                    <div
                      className="progress-fill"
                      style={{
                        width: `${dataPercent}%`,
                        background: dataPercent > 90 ? '#ef4444' : dataPercent > 70 ? '#f59e0b' : '#3b82f6',
                      }}
                    />
                  </div>
                  <p className="text-xs text-gray-500 mt-1">of {formatData(user.dataLimit)}</p>
                </>
              ) : (
                <p className="text-xs text-gray-500">Unlimited</p>
              )}
            </div>
          </div>

          <div className="stat-card p-4">
            <div className="flex items-center gap-2 text-gray-400 text-sm mb-2">
              <Wifi size={16} />
              Connections
            </div>
            <p className="text-2xl font-bold text-purple-400">
              {user.currentConnections}<span className="text-gray-500 text-lg">/{user.maxConnections}</span>
            </p>
            <p className="text-xs text-gray-500 mt-1">
              {user.isOnline ? (
                <span className="text-green-400 flex items-center gap-1">
                  <span className="pulse-dot inline-block w-1.5 h-1.5 rounded-full bg-green-400" />
                  Currently Online
                </span>
              ) : 'Offline'}
            </p>
          </div>

          <div className="stat-card p-4">
            <div className="flex items-center gap-2 text-gray-400 text-sm mb-2">
              <Activity size={16} />
              Bandwidth
            </div>
            <p className="text-2xl font-bold text-yellow-400">
              {user.bandwidthLimit > 0 ? `${user.bandwidthLimit}` : '∞'}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              {user.bandwidthLimit > 0 ? 'Kbps limit' : 'Unlimited'}
            </p>
          </div>
        </div>

        {/* Account Details */}
        <div className="glass-card glow-border p-5 mb-6">
          <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
            <Calendar size={20} className="text-accent" />
            Account Details
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div className="bg-dark-900/50 rounded-lg p-3">
              <span className="text-gray-500">Username</span>
              <p className="text-white font-medium mt-0.5">{user.username}</p>
            </div>
            <div className="bg-dark-900/50 rounded-lg p-3">
              <span className="text-gray-500">SSH Port</span>
              <p className="text-white font-medium mt-0.5">{settings.sshPort}</p>
            </div>
            <div className="bg-dark-900/50 rounded-lg p-3">
              <span className="text-gray-500">Account Created</span>
              <p className="text-white font-medium mt-0.5">{new Date(user.createdAt).toLocaleDateString()}</p>
            </div>
            <div className="bg-dark-900/50 rounded-lg p-3">
              <span className="text-gray-500">Account Status</span>
              <p className="mt-0.5">
                {isExpired ? (
                  <span className="badge badge-expired">Expired</span>
                ) : !user.isEnabled ? (
                  <span className="badge badge-expired">Disabled</span>
                ) : (
                  <span className="badge badge-active">Active</span>
                )}
              </p>
            </div>
          </div>
        </div>

        {/* QR Codes & Connection Links */}
        <div className="glass-card glow-border p-5">
          <h3 className="text-lg font-semibold text-white mb-4">
            📱 Connection Configs (QR Code & Links)
          </h3>
          <p className="text-sm text-gray-400 mb-4">
            Scan the QR code with your app or copy the connection link:
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {configs.map((config, index) => (
              <div
                key={config.app}
                className="glass-card p-4 rounded-xl border border-opacity-30"
                style={{ borderColor: appColors[config.app] + '50' }}
              >
                <div className="flex items-center gap-2 mb-3">
                  <span className="text-xl">{config.icon}</span>
                  <h4 className="font-semibold text-white">{config.name}</h4>
                </div>
                
                <div className="flex justify-center mb-3">
                  <div className="bg-white p-2.5 rounded-lg">
                    <QRCodeSVG value={config.uri} size={140} level="M" />
                  </div>
                </div>

                <div className="bg-dark-900 rounded-lg p-2 mb-3">
                  <p className="text-xs text-gray-400 break-all font-mono leading-relaxed max-h-14 overflow-y-auto">
                    {config.uri}
                  </p>
                </div>

                <button
                  onClick={() => handleCopy(config.uri, index)}
                  className="w-full flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-sm font-medium transition-all"
                  style={{
                    background: copiedIndex === index ? 'rgba(16,185,129,0.2)' : `${appColors[config.app]}20`,
                    color: copiedIndex === index ? '#10b981' : appColors[config.app],
                    border: `1px solid ${copiedIndex === index ? 'rgba(16,185,129,0.3)' : appColors[config.app] + '30'}`,
                  }}
                >
                  {copiedIndex === index ? <Check size={14} /> : <Copy size={14} />}
                  {copiedIndex === index ? 'Copied!' : 'Copy Connection Link'}
                </button>
              </div>
            ))}
          </div>
        </div>

        <p className="text-center text-gray-600 text-xs mt-6">
          SSH Panel Manager • Contact admin for support
        </p>
      </div>
    </div>
  );
};

export default UserDashboard;
