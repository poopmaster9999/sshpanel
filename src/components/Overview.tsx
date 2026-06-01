import React from 'react';
import { SSHUser } from '../types';
import { Users, Wifi, Database, Activity, TrendingUp, Clock, Shield, AlertTriangle } from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, PieChart, Pie, Cell } from 'recharts';

interface OverviewProps {
  users: SSHUser[];
}

const Overview: React.FC<OverviewProps> = ({ users }) => {
  const totalUsers = users.length;
  const onlineUsers = users.filter(u => u.isOnline).length;
  const totalDataUsed = users.reduce((sum, u) => sum + u.dataUsed, 0);
  const activeConnections = users.reduce((sum, u) => sum + u.currentConnections, 0);
  const expiredUsers = users.filter(u => new Date(u.expiresAt) < new Date()).length;
  const enabledUsers = users.filter(u => u.isEnabled).length;

  const topDataUsers = [...users]
    .sort((a, b) => b.dataUsed - a.dataUsed)
    .slice(0, 6)
    .map(u => ({ name: u.username.length > 10 ? u.username.slice(0, 10) + '..' : u.username, data: Math.round(u.dataUsed) }));

  const statusData = [
    { name: 'Online', value: onlineUsers, color: '#10b981' },
    { name: 'Offline', value: totalUsers - onlineUsers - expiredUsers, color: '#6b7280' },
    { name: 'Expired', value: expiredUsers, color: '#ef4444' },
  ].filter(d => d.value > 0);

  const onlineList = users.filter(u => u.isOnline);

  const formatData = (mb: number): string => {
    if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`;
    return `${Math.round(mb)} MB`;
  };

  return (
    <div className="fade-in space-y-6">
      <div className="flex items-center justify-between mb-2">
        <h1 className="text-2xl font-bold text-white flex items-center gap-3">
          <Activity className="text-accent" size={28} />
          Dashboard Overview
        </h1>
        <span className="text-sm text-gray-400">
          Last updated: {new Date().toLocaleTimeString()}
        </span>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="stat-card p-5">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-gray-400 text-sm">Total Users</p>
              <p className="text-3xl font-bold text-white mt-1">{totalUsers}</p>
              <p className="text-xs text-green-400 mt-1">{enabledUsers} active</p>
            </div>
            <div className="w-12 h-12 rounded-xl bg-blue-500/15 flex items-center justify-center">
              <Users className="text-blue-400" size={24} />
            </div>
          </div>
        </div>

        <div className="stat-card p-5">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-gray-400 text-sm">Online Now</p>
              <p className="text-3xl font-bold text-green-400 mt-1">{onlineUsers}</p>
              <p className="text-xs text-gray-400 mt-1">{activeConnections} connections</p>
            </div>
            <div className="w-12 h-12 rounded-xl bg-green-500/15 flex items-center justify-center">
              <Wifi className="text-green-400" size={24} />
            </div>
          </div>
        </div>

        <div className="stat-card p-5">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-gray-400 text-sm">Total Data Used</p>
              <p className="text-3xl font-bold text-cyan-400 mt-1">{formatData(totalDataUsed)}</p>
              <p className="text-xs text-gray-400 mt-1">across all users</p>
            </div>
            <div className="w-12 h-12 rounded-xl bg-cyan-500/15 flex items-center justify-center">
              <Database className="text-cyan-400" size={24} />
            </div>
          </div>
        </div>

        <div className="stat-card p-5">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-gray-400 text-sm">Expired Users</p>
              <p className="text-3xl font-bold text-red-400 mt-1">{expiredUsers}</p>
              <p className="text-xs text-yellow-400 mt-1">need attention</p>
            </div>
            <div className="w-12 h-12 rounded-xl bg-red-500/15 flex items-center justify-center">
              <AlertTriangle className="text-red-400" size={24} />
            </div>
          </div>
        </div>
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Top Data Users */}
        <div className="glass-card glow-border p-5 lg:col-span-2">
          <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
            <TrendingUp size={20} className="text-accent" />
            Top Data Usage
          </h3>
          <ResponsiveContainer width="100%" height={250}>
            <BarChart data={topDataUsers}>
              <XAxis dataKey="name" tick={{ fill: '#9ca3af', fontSize: 12 }} />
              <YAxis tick={{ fill: '#9ca3af', fontSize: 12 }} tickFormatter={(v) => `${v}MB`} />
              <Tooltip
                contentStyle={{ background: '#1a2235', border: '1px solid rgba(59,130,246,0.3)', borderRadius: '8px', color: '#e2e8f0' }}
                formatter={(value) => [`${formatData(Number(value))}`, 'Usage']}
              />
              <Bar dataKey="data" fill="#3b82f6" radius={[6, 6, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>

        {/* Status Pie */}
        <div className="glass-card glow-border p-5">
          <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
            <Shield size={20} className="text-accent" />
            User Status
          </h3>
          <ResponsiveContainer width="100%" height={200}>
            <PieChart>
              <Pie
                data={statusData}
                cx="50%"
                cy="50%"
                innerRadius={50}
                outerRadius={80}
                paddingAngle={5}
                dataKey="value"
              >
                {statusData.map((entry, index) => (
                  <Cell key={`cell-${index}`} fill={entry.color} />
                ))}
              </Pie>
              <Tooltip
                contentStyle={{ background: '#1a2235', border: '1px solid rgba(59,130,246,0.3)', borderRadius: '8px', color: '#e2e8f0' }}
              />
            </PieChart>
          </ResponsiveContainer>
          <div className="flex justify-center gap-4 mt-2">
            {statusData.map((entry) => (
              <div key={entry.name} className="flex items-center gap-1.5 text-xs">
                <div className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: entry.color }} />
                <span className="text-gray-400">{entry.name} ({entry.value})</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Online Users Table */}
      <div className="glass-card glow-border p-5">
        <h3 className="text-lg font-semibold text-white mb-4 flex items-center gap-2">
          <Clock size={20} className="text-green-400" />
          Currently Online Users
          <span className="badge badge-online ml-2">{onlineUsers} online</span>
        </h3>
        {onlineList.length === 0 ? (
          <p className="text-gray-500 text-center py-8">No users currently online</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-gray-400 text-left border-b border-blue-500/10">
                  <th className="pb-3 px-3">Username</th>
                  <th className="pb-3 px-3">Connections</th>
                  <th className="pb-3 px-3">Connected IPs</th>
                  <th className="pb-3 px-3">Data Used</th>
                  <th className="pb-3 px-3">Data Limit</th>
                  <th className="pb-3 px-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {onlineList.map(user => (
                  <tr key={user.id} className="table-row">
                    <td className="py-3 px-3 font-medium text-white">{user.username}</td>
                    <td className="py-3 px-3">
                      <span className="text-cyan-400">{user.currentConnections}</span>
                      <span className="text-gray-500">/{user.maxConnections}</span>
                    </td>
                    <td className="py-3 px-3">
                      <div className="flex flex-wrap gap-1">
                        {user.connectedIPs.map((ip, i) => (
                          <span key={i} className="text-xs bg-dark-600 px-2 py-0.5 rounded text-gray-300">{ip}</span>
                        ))}
                      </div>
                    </td>
                    <td className="py-3 px-3 text-cyan-400">{formatData(user.dataUsed)}</td>
                    <td className="py-3 px-3 text-gray-400">{user.dataLimit > 0 ? formatData(user.dataLimit) : '∞'}</td>
                    <td className="py-3 px-3">
                      <span className="pulse-dot inline-block w-2 h-2 rounded-full bg-green-400 mr-2" />
                      <span className="text-green-400 text-xs font-medium">ONLINE</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};

export default Overview;
