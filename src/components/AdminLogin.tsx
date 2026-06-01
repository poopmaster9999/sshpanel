import React, { useState } from 'react';
import { getSettings } from '../store';
import { Shield, User, Lock, LogIn, Terminal } from 'lucide-react';

interface AdminLoginProps {
  onLogin: () => void;
  onSwitchToUser: () => void;
}

const AdminLogin: React.FC<AdminLoginProps> = ({ onLogin, onSwitchToUser }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  const handleLogin = () => {
    const settings = getSettings();
    if (username === settings.adminUsername && password === settings.adminPassword) {
      onLogin();
    } else {
      setError('Invalid admin credentials');
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4" style={{ background: 'linear-gradient(135deg, #0a0e17 0%, #111827 50%, #0a0e17 100%)' }}>
      <div className="w-full max-w-md fade-in">
        {/* Logo and Title */}
        <div className="text-center mb-8">
          <div className="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 via-blue-600 to-purple-600 flex items-center justify-center mb-4 shadow-lg shadow-blue-500/20">
            <Terminal size={40} className="text-white" />
          </div>
          <h1 className="text-3xl font-bold text-white">SSH Panel</h1>
          <p className="text-gray-400 mt-1">Administration Dashboard</p>
        </div>

        {/* Login Card */}
        <div className="glass-card glow-border p-8">
          <h2 className="text-lg font-semibold text-white mb-6 flex items-center gap-2">
            <Shield size={20} className="text-accent" />
            Admin Login
          </h2>

          <div className="space-y-4">
            <div>
              <label className="block text-sm text-gray-400 mb-1">Username</label>
              <div className="relative">
                <User className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" size={18} />
                <input
                  type="text"
                  value={username}
                  onChange={e => { setUsername(e.target.value); setError(''); }}
                  className="input-field pl-10"
                  placeholder="Admin username"
                  onKeyDown={e => e.key === 'Enter' && handleLogin()}
                />
              </div>
            </div>

            <div>
              <label className="block text-sm text-gray-400 mb-1">Password</label>
              <div className="relative">
                <Lock className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" size={18} />
                <input
                  type="password"
                  value={password}
                  onChange={e => { setPassword(e.target.value); setError(''); }}
                  className="input-field pl-10"
                  placeholder="Admin password"
                  onKeyDown={e => e.key === 'Enter' && handleLogin()}
                />
              </div>
            </div>

            {error && (
              <div className="text-red-400 text-sm bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-center">
                {error}
              </div>
            )}

            <button onClick={handleLogin} className="btn-primary w-full py-3 text-base flex items-center justify-center gap-2">
              <LogIn size={18} />
              Login to Panel
            </button>
          </div>

          <div className="mt-6 pt-4 border-t border-dark-500 text-center">
            <p className="text-gray-500 text-sm mb-2">Are you an SSH user?</p>
            <button onClick={onSwitchToUser} className="text-accent hover:text-accent-light text-sm font-medium transition-colors">
              Login to My Account →
            </button>
          </div>
        </div>

        <p className="text-center text-gray-600 text-xs mt-6">
          SSH Panel Manager v1.0 • Default: admin / admin
        </p>
      </div>
    </div>
  );
};

export default AdminLogin;
