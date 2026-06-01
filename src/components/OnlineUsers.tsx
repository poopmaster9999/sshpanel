import React from 'react';
import { SSHUser } from '../types';
import { Wifi, Monitor, Clock, Database, MapPin, Power } from 'lucide-react';
import { updateUser } from '../store';

interface OnlineUsersProps {
  users: SSHUser[];
  onRefresh: () => void;
}

const OnlineUsers: React.FC<OnlineUsersProps> = ({ users, onRefresh }) => {
  const onlineUsers = users.filter(u => u.isOnline);
  const totalConnections = onlineUsers.reduce((sum, u) => sum + u.currentConnections, 0);

  const formatData = (mb: number): string => {
    if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`;
    return `${Math.round(mb)} MB`;
  };

  const handleKickUser = (user: SSHUser) => {
    updateUser(user.id, { isOnline: false, currentConnections: 0, connectedIPs: [] });
    onRefresh();
  };

  return (
    <div className="fade-in space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-white flex items-center gap-3">
          <Wifi className="text-green-400" size={28} />
          Online Users
          <span className="badge badge-online ml-2">{onlineUsers.length} online</span>
        </h1>
        <div className="text-sm text-gray-400">
          {totalConnections} total connections
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="stat-card p-4">
          <div className="flex items-center gap-2 text-gray-400 text-sm">
            <Monitor size={16} />
            Online Users
          </div>
          <p className="text-2xl font-bold text-green-400 mt-1">{onlineUsers.length}</p>
        </div>
        <div className="stat-card p-4">
          <div className="flex items-center gap-2 text-gray-400 text-sm">
            <Wifi size={16} />
            Active Connections
          </div>
          <p className="text-2xl font-bold text-cyan-400 mt-1">{totalConnections}</p>
        </div>
        <div className="stat-card p-4">
          <div className="flex items-center gap-2 text-gray-400 text-sm">
            <Database size={16} />
            Combined Data Usage
          </div>
          <p className="text-2xl font-bold text-purple-400 mt-1">
            {formatData(onlineUsers.reduce((sum, u) => sum + u.dataUsed, 0))}
          </p>
        </div>
      </div>

      {/* Online Users Grid */}
      {onlineUsers.length === 0 ? (
        <div className="glass-card glow-border p-12 text-center">
          <Wifi size={48} className="text-gray-600 mx-auto mb-4" />
          <p className="text-gray-500 text-lg">No users currently online</p>
          <p className="text-gray-600 text-sm mt-1">Users will appear here when they connect</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {onlineUsers.map(user => {
            const daysLeft = Math.ceil((new Date(user.expiresAt).getTime() - Date.now()) / 86400000);
            const dataPercent = user.dataLimit > 0 ? Math.min((user.dataUsed / user.dataLimit) * 100, 100) : 0;

            return (
              <div key={user.id} className="glass-card glow-border p-4 rounded-xl">
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <span className="pulse-dot inline-block w-2.5 h-2.5 rounded-full bg-green-400" />
                    <h3 className="font-semibold text-white">{user.username}</h3>
                  </div>
                  <button
                    onClick={() => handleKickUser(user)}
                    className="p-1.5 rounded hover:bg-red-500/20 text-red-400 transition-colors"
                    title="Kick User"
                  >
                    <Power size={16} />
                  </button>
                </div>

                <div className="space-y-2 text-sm">
                  <div className="flex items-center justify-between">
                    <span className="text-gray-400 flex items-center gap-1"><Wifi size={14} /> Connections</span>
                    <span className="text-cyan-400 font-medium">{user.currentConnections}/{user.maxConnections}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-gray-400 flex items-center gap-1"><Clock size={14} /> Time Left</span>
                    <span className={`font-medium ${daysLeft <= 3 ? 'text-yellow-400' : 'text-gray-300'}`}>{daysLeft}d</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-gray-400 flex items-center gap-1"><Database size={14} /> Data Used</span>
                    <span className="text-gray-300">{formatData(user.dataUsed)}</span>
                  </div>
                  {user.dataLimit > 0 && (
                    <div className="progress-bar">
                      <div
                        className="progress-fill"
                        style={{
                          width: `${dataPercent}%`,
                          background: dataPercent > 90 ? '#ef4444' : dataPercent > 70 ? '#f59e0b' : '#3b82f6',
                        }}
                      />
                    </div>
                  )}
                </div>

                {user.connectedIPs.length > 0 && (
                  <div className="mt-3 pt-3 border-t border-dark-600">
                    <p className="text-xs text-gray-500 flex items-center gap-1 mb-1">
                      <MapPin size={12} />
                      Connected IPs
                    </p>
                    <div className="flex flex-wrap gap-1">
                      {user.connectedIPs.map((ip, i) => (
                        <span key={i} className="text-xs bg-dark-600 px-2 py-0.5 rounded text-gray-400 font-mono">{ip}</span>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
};

export default OnlineUsers;
