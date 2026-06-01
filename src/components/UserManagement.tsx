import React, { useState } from 'react';
import { SSHUser } from '../types';
import { addUser, deleteUser, updateUser, generatePassword } from '../store';
import { UserPlus, Trash2, Edit3, Eye, EyeOff, RefreshCw, Search, Copy, QrCode, Power, ChevronDown, ChevronUp } from 'lucide-react';
import UserQRCodes from './UserQRCodes';

interface UserManagementProps {
  users: SSHUser[];
  onRefresh: () => void;
}

const UserManagement: React.FC<UserManagementProps> = ({ users, onRefresh }) => {
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [showEditModal, setShowEditModal] = useState(false);
  const [showQRModal, setShowQRModal] = useState(false);
  const [selectedUser, setSelectedUser] = useState<SSHUser | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [showPasswords, setShowPasswords] = useState<Record<string, boolean>>({});
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [expandedUser, setExpandedUser] = useState<string | null>(null);
  const [sortBy, setSortBy] = useState<'username' | 'dataUsed' | 'expiresAt' | 'createdAt'>('createdAt');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

  // Create form state
  const [newUsername, setNewUsername] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newExpiry, setNewExpiry] = useState(30);
  const [newMaxConn, setNewMaxConn] = useState(1);
  const [newDataLimit, setNewDataLimit] = useState(0);
  const [newBandwidth, setNewBandwidth] = useState(0);

  const formatData = (mb: number): string => {
    if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`;
    return `${Math.round(mb)} MB`;
  };

  const getDaysLeft = (expiresAt: string): number => {
    const diff = new Date(expiresAt).getTime() - Date.now();
    return Math.ceil(diff / 86400000);
  };

  const handleCreate = () => {
    if (!newUsername.trim()) return;
    const expiresAt = new Date(Date.now() + newExpiry * 86400000).toISOString();
    addUser({
      username: newUsername.trim(),
      password: newPassword || generatePassword(),
      expiresAt,
      maxConnections: newMaxConn,
      dataLimit: newDataLimit,
      bandwidthLimit: newBandwidth,
      isEnabled: true,
    });
    setShowCreateModal(false);
    resetForm();
    onRefresh();
  };

  const handleDelete = (id: string) => {
    if (confirm('Are you sure you want to delete this user?')) {
      deleteUser(id);
      onRefresh();
    }
  };

  const handleToggleUser = (user: SSHUser) => {
    updateUser(user.id, { isEnabled: !user.isEnabled });
    onRefresh();
  };

  const handleEdit = (user: SSHUser) => {
    setSelectedUser(user);
    setNewUsername(user.username);
    setNewPassword(user.password);
    setNewMaxConn(user.maxConnections);
    setNewDataLimit(user.dataLimit);
    setNewBandwidth(user.bandwidthLimit);
    const days = getDaysLeft(user.expiresAt);
    setNewExpiry(days > 0 ? days : 1);
    setShowEditModal(true);
  };

  const handleSaveEdit = () => {
    if (!selectedUser) return;
    const expiresAt = new Date(Date.now() + newExpiry * 86400000).toISOString();
    updateUser(selectedUser.id, {
      username: newUsername.trim(),
      password: newPassword,
      expiresAt,
      maxConnections: newMaxConn,
      dataLimit: newDataLimit,
      bandwidthLimit: newBandwidth,
    });
    setShowEditModal(false);
    resetForm();
    onRefresh();
  };

  const resetForm = () => {
    setNewUsername('');
    setNewPassword('');
    setNewExpiry(30);
    setNewMaxConn(1);
    setNewDataLimit(0);
    setNewBandwidth(0);
    setSelectedUser(null);
  };

  const copyPassword = (id: string, password: string) => {
    navigator.clipboard.writeText(password);
    setCopiedId(id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  const filteredUsers = users
    .filter(u => u.username.toLowerCase().includes(searchTerm.toLowerCase()))
    .sort((a, b) => {
      let cmp = 0;
      if (sortBy === 'username') cmp = a.username.localeCompare(b.username);
      else if (sortBy === 'dataUsed') cmp = a.dataUsed - b.dataUsed;
      else if (sortBy === 'expiresAt') cmp = new Date(a.expiresAt).getTime() - new Date(b.expiresAt).getTime();
      else cmp = new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime();
      return sortDir === 'asc' ? cmp : -cmp;
    });

  const handleSort = (field: typeof sortBy) => {
    if (sortBy === field) {
      setSortDir(d => d === 'asc' ? 'desc' : 'asc');
    } else {
      setSortBy(field);
      setSortDir('asc');
    }
  };

  const SortIcon = ({ field }: { field: typeof sortBy }) => {
    if (sortBy !== field) return null;
    return sortDir === 'asc' ? <ChevronUp size={14} /> : <ChevronDown size={14} />;
  };

  return (
    <div className="fade-in space-y-5">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <h1 className="text-2xl font-bold text-white flex items-center gap-3">
          <UserPlus className="text-accent" size={28} />
          User Management
          <span className="text-sm font-normal text-gray-400 ml-2">({users.length} users)</span>
        </h1>
        <button onClick={() => { resetForm(); setShowCreateModal(true); }} className="btn-primary flex items-center gap-2">
          <UserPlus size={18} />
          Create User
        </button>
      </div>

      {/* Search Bar */}
      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" size={18} />
        <input
          type="text"
          placeholder="Search users..."
          value={searchTerm}
          onChange={e => setSearchTerm(e.target.value)}
          className="input-field pl-10"
        />
      </div>

      {/* Users Table */}
      <div className="glass-card glow-border overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-gray-400 text-left border-b border-blue-500/10 bg-dark-800/50">
                <th className="py-3 px-4 cursor-pointer select-none" onClick={() => handleSort('username')}>
                  <div className="flex items-center gap-1">Username <SortIcon field="username" /></div>
                </th>
                <th className="py-3 px-4">Password</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4 cursor-pointer select-none" onClick={() => handleSort('expiresAt')}>
                  <div className="flex items-center gap-1">Expires <SortIcon field="expiresAt" /></div>
                </th>
                <th className="py-3 px-4">Connections</th>
                <th className="py-3 px-4 cursor-pointer select-none" onClick={() => handleSort('dataUsed')}>
                  <div className="flex items-center gap-1">Data <SortIcon field="dataUsed" /></div>
                </th>
                <th className="py-3 px-4">Bandwidth</th>
                <th className="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredUsers.map(user => {
                const daysLeft = getDaysLeft(user.expiresAt);
                const isExpired = daysLeft <= 0;
                const dataPercent = user.dataLimit > 0 ? Math.min((user.dataUsed / user.dataLimit) * 100, 100) : 0;
                const isOverData = user.dataLimit > 0 && user.dataUsed >= user.dataLimit;

                return (
                  <React.Fragment key={user.id}>
                    <tr className="table-row">
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2">
                          <button onClick={() => setExpandedUser(expandedUser === user.id ? null : user.id)} className="text-gray-500 hover:text-white">
                            {expandedUser === user.id ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                          </button>
                          <span className="font-medium text-white">{user.username}</span>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-xs text-gray-400">
                            {showPasswords[user.id] ? user.password : '••••••••'}
                          </span>
                          <button onClick={() => setShowPasswords(p => ({ ...p, [user.id]: !p[user.id] }))} className="text-gray-500 hover:text-white">
                            {showPasswords[user.id] ? <EyeOff size={14} /> : <Eye size={14} />}
                          </button>
                          <button onClick={() => copyPassword(user.id, user.password)} className="text-gray-500 hover:text-accent">
                            <Copy size={14} />
                          </button>
                          {copiedId === user.id && <span className="text-xs text-green-400">Copied!</span>}
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        {user.isOnline ? (
                          <span className="badge badge-online flex items-center gap-1 w-fit">
                            <span className="pulse-dot inline-block w-1.5 h-1.5 rounded-full bg-green-400" />
                            Online
                          </span>
                        ) : isExpired ? (
                          <span className="badge badge-expired">Expired</span>
                        ) : !user.isEnabled ? (
                          <span className="badge badge-expired">Disabled</span>
                        ) : (
                          <span className="badge badge-offline">Offline</span>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        {isExpired ? (
                          <span className="text-red-400 font-medium">Expired {Math.abs(daysLeft)}d ago</span>
                        ) : daysLeft <= 3 ? (
                          <span className="text-yellow-400 font-medium">{daysLeft}d left</span>
                        ) : (
                          <span className="text-gray-300">{daysLeft}d left</span>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        <span className={`font-medium ${user.currentConnections >= user.maxConnections ? 'text-yellow-400' : 'text-cyan-400'}`}>
                          {user.currentConnections}
                        </span>
                        <span className="text-gray-500">/{user.maxConnections}</span>
                      </td>
                      <td className="py-3 px-4">
                        <div className="space-y-1">
                          <div className="flex justify-between text-xs">
                            <span className={isOverData ? 'text-red-400' : 'text-gray-300'}>
                              {formatData(user.dataUsed)}
                            </span>
                            <span className="text-gray-500">
                              {user.dataLimit > 0 ? formatData(user.dataLimit) : '∞'}
                            </span>
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
                      </td>
                      <td className="py-3 px-4 text-gray-400">
                        {user.bandwidthLimit > 0 ? `${user.bandwidthLimit} Kbps` : 'Unlimited'}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center justify-end gap-1">
                          <button onClick={() => { setSelectedUser(user); setShowQRModal(true); }} className="p-1.5 rounded hover:bg-purple-500/20 text-purple-400" title="QR Codes">
                            <QrCode size={16} />
                          </button>
                          <button onClick={() => handleEdit(user)} className="p-1.5 rounded hover:bg-blue-500/20 text-blue-400" title="Edit">
                            <Edit3 size={16} />
                          </button>
                          <button onClick={() => handleToggleUser(user)} className={`p-1.5 rounded ${user.isEnabled ? 'hover:bg-yellow-500/20 text-yellow-400' : 'hover:bg-green-500/20 text-green-400'}`} title={user.isEnabled ? 'Disable' : 'Enable'}>
                            <Power size={16} />
                          </button>
                          <button onClick={() => handleDelete(user.id)} className="p-1.5 rounded hover:bg-red-500/20 text-red-400" title="Delete">
                            <Trash2 size={16} />
                          </button>
                        </div>
                      </td>
                    </tr>
                    {expandedUser === user.id && (
                      <tr>
                        <td colSpan={8} className="px-4 py-3 bg-dark-900/50">
                          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                            <div>
                              <span className="text-gray-500">Created</span>
                              <p className="text-gray-300 mt-0.5">{new Date(user.createdAt).toLocaleDateString()}</p>
                            </div>
                            <div>
                              <span className="text-gray-500">Expires</span>
                              <p className="text-gray-300 mt-0.5">{new Date(user.expiresAt).toLocaleDateString()}</p>
                            </div>
                            <div>
                              <span className="text-gray-500">Last Connected</span>
                              <p className="text-gray-300 mt-0.5">{user.lastConnected ? new Date(user.lastConnected).toLocaleString() : 'Never'}</p>
                            </div>
                            <div>
                              <span className="text-gray-500">Connected IPs</span>
                              <p className="text-gray-300 mt-0.5">{user.connectedIPs.length > 0 ? user.connectedIPs.join(', ') : 'None'}</p>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
        {filteredUsers.length === 0 && (
          <div className="text-center py-12 text-gray-500">
            {searchTerm ? 'No users match your search' : 'No users yet. Click "Create User" to add one.'}
          </div>
        )}
      </div>

      {/* Create User Modal */}
      {showCreateModal && (
        <Modal title="Create New User" onClose={() => setShowCreateModal(false)}>
          <UserForm
            username={newUsername}
            setUsername={setNewUsername}
            password={newPassword}
            setPassword={setNewPassword}
            expiry={newExpiry}
            setExpiry={setNewExpiry}
            maxConn={newMaxConn}
            setMaxConn={setNewMaxConn}
            dataLimit={newDataLimit}
            setDataLimit={setNewDataLimit}
            bandwidth={newBandwidth}
            setBandwidth={setNewBandwidth}
            onSubmit={handleCreate}
            submitLabel="Create User"
          />
        </Modal>
      )}

      {/* Edit User Modal */}
      {showEditModal && selectedUser && (
        <Modal title={`Edit User: ${selectedUser.username}`} onClose={() => { setShowEditModal(false); resetForm(); }}>
          <UserForm
            username={newUsername}
            setUsername={setNewUsername}
            password={newPassword}
            setPassword={setNewPassword}
            expiry={newExpiry}
            setExpiry={setNewExpiry}
            maxConn={newMaxConn}
            setMaxConn={setNewMaxConn}
            dataLimit={newDataLimit}
            setDataLimit={setNewDataLimit}
            bandwidth={newBandwidth}
            setBandwidth={setNewBandwidth}
            onSubmit={handleSaveEdit}
            submitLabel="Save Changes"
          />
        </Modal>
      )}

      {/* QR Code Modal */}
      {showQRModal && selectedUser && (
        <Modal title={`Connection QR Codes: ${selectedUser.username}`} onClose={() => { setShowQRModal(false); setSelectedUser(null); }} wide>
          <UserQRCodes user={selectedUser} />
        </Modal>
      )}
    </div>
  );
};

// Modal Component
const Modal: React.FC<{ title: string; onClose: () => void; children: React.ReactNode; wide?: boolean }> = ({ title, onClose, children, wide }) => (
  <div className="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" onClick={onClose}>
    <div className={`glass-card glow-border p-6 w-full ${wide ? 'max-w-3xl' : 'max-w-lg'} max-h-[90vh] overflow-y-auto fade-in`} onClick={e => e.stopPropagation()}>
      <div className="flex items-center justify-between mb-5">
        <h2 className="text-xl font-bold text-white">{title}</h2>
        <button onClick={onClose} className="text-gray-400 hover:text-white text-xl">✕</button>
      </div>
      {children}
    </div>
  </div>
);

// User Form Component
interface UserFormProps {
  username: string; setUsername: (v: string) => void;
  password: string; setPassword: (v: string) => void;
  expiry: number; setExpiry: (v: number) => void;
  maxConn: number; setMaxConn: (v: number) => void;
  dataLimit: number; setDataLimit: (v: number) => void;
  bandwidth: number; setBandwidth: (v: number) => void;
  onSubmit: () => void;
  submitLabel: string;
}

const UserForm: React.FC<UserFormProps> = ({
  username, setUsername, password, setPassword, expiry, setExpiry,
  maxConn, setMaxConn, dataLimit, setDataLimit, bandwidth, setBandwidth,
  onSubmit, submitLabel
}) => (
  <div className="space-y-4">
    <div>
      <label className="block text-sm text-gray-400 mb-1">Username</label>
      <input type="text" value={username} onChange={e => setUsername(e.target.value)} className="input-field" placeholder="Enter username" />
    </div>
    <div>
      <label className="block text-sm text-gray-400 mb-1">Password</label>
      <div className="flex gap-2">
        <input type="text" value={password} onChange={e => setPassword(e.target.value)} className="input-field" placeholder="Enter password" />
        <button onClick={() => setPassword(generatePassword())} className="btn-primary flex items-center gap-1 whitespace-nowrap" title="Generate Random Password">
          <RefreshCw size={16} />
          Random
        </button>
      </div>
    </div>
    <div className="grid grid-cols-2 gap-4">
      <div>
        <label className="block text-sm text-gray-400 mb-1">Duration (days)</label>
        <input type="number" value={expiry} onChange={e => setExpiry(Number(e.target.value))} className="input-field" min="1" />
      </div>
      <div>
        <label className="block text-sm text-gray-400 mb-1">Max Connections</label>
        <input type="number" value={maxConn} onChange={e => setMaxConn(Number(e.target.value))} className="input-field" min="1" max="10" />
      </div>
    </div>
    <div className="grid grid-cols-2 gap-4">
      <div>
        <label className="block text-sm text-gray-400 mb-1">Data Limit (MB, 0 = unlimited)</label>
        <input type="number" value={dataLimit} onChange={e => setDataLimit(Number(e.target.value))} className="input-field" min="0" />
        <div className="flex gap-1 mt-1">
          {[0, 1024, 5120, 10240, 51200].map(v => (
            <button key={v} onClick={() => setDataLimit(v)} className="text-xs px-2 py-0.5 rounded bg-dark-600 text-gray-400 hover:text-white hover:bg-dark-500">
              {v === 0 ? '∞' : v >= 1024 ? `${v / 1024}GB` : `${v}MB`}
            </button>
          ))}
        </div>
      </div>
      <div>
        <label className="block text-sm text-gray-400 mb-1">Bandwidth (Kbps, 0 = unlimited)</label>
        <input type="number" value={bandwidth} onChange={e => setBandwidth(Number(e.target.value))} className="input-field" min="0" />
        <div className="flex gap-1 mt-1">
          {[0, 512, 1024, 2048, 4096].map(v => (
            <button key={v} onClick={() => setBandwidth(v)} className="text-xs px-2 py-0.5 rounded bg-dark-600 text-gray-400 hover:text-white hover:bg-dark-500">
              {v === 0 ? '∞' : v >= 1024 ? `${v / 1024}Mbps` : `${v}Kbps`}
            </button>
          ))}
        </div>
      </div>
    </div>
    <button onClick={onSubmit} className="btn-primary w-full mt-4 py-3 text-base">
      {submitLabel}
    </button>
  </div>
);

export default UserManagement;
