import React, { useState } from 'react';
import { SSHUser } from '../types';
import { LogIn, User, Lock } from 'lucide-react';

interface UserLoginProps {
  onLogin: (user: SSHUser) => void;
  users: SSHUser[];
}

const UserLogin: React.FC<UserLoginProps> = ({ onLogin, users }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  const handleLogin = () => {
    const user = users.find(u => u.username === username && u.password === password);
    if (user) {
      setError('');
      onLogin(user);
    } else {
      setError('Invalid username or password');
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4" style={{ background: 'linear-gradient(135deg, #0a0e17 0%, #111827 50%, #0a0e17 100%)' }}>
      <div className="glass-card glow-border p-8 w-full max-w-md fade-in">
        <div className="text-center mb-8">
          <div className="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mb-4">
            <User size={32} className="text-white" />
          </div>
          <h1 className="text-2xl font-bold text-white">My Account</h1>
          <p className="text-gray-400 text-sm mt-1">Login to view your SSH account details</p>
        </div>

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
                placeholder="Your SSH username"
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
                placeholder="Your password"
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
            Login
          </button>
        </div>

        <p className="text-center text-gray-500 text-xs mt-6">
          Contact your administrator if you forgot your credentials
        </p>
      </div>
    </div>
  );
};

export default UserLogin;
