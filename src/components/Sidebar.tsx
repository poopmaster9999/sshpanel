import React from 'react';
import { LayoutDashboard, Users, Settings, LogOut, Terminal, Wifi, Menu, X } from 'lucide-react';

export type Page = 'overview' | 'users' | 'online' | 'settings';

interface SidebarProps {
  currentPage: Page;
  onNavigate: (page: Page) => void;
  onLogout: () => void;
  onlineCount: number;
  totalUsers: number;
  mobileOpen: boolean;
  onToggleMobile: () => void;
}

const Sidebar: React.FC<SidebarProps> = ({ currentPage, onNavigate, onLogout, onlineCount, totalUsers, mobileOpen, onToggleMobile }) => {
  const navItems: { id: Page; label: string; icon: React.ReactNode; badge?: number }[] = [
    { id: 'overview', label: 'Dashboard', icon: <LayoutDashboard size={20} /> },
    { id: 'users', label: 'User Management', icon: <Users size={20} />, badge: totalUsers },
    { id: 'online', label: 'Online Users', icon: <Wifi size={20} />, badge: onlineCount },
    { id: 'settings', label: 'Settings', icon: <Settings size={20} /> },
  ];

  const sidebarContent = (
    <>
      {/* Logo */}
      <div className="p-5 border-b border-blue-500/10">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center shadow-lg shadow-blue-500/20">
            <Terminal size={22} className="text-white" />
          </div>
          <div>
            <h1 className="text-lg font-bold text-white">SSH Panel</h1>
            <p className="text-xs text-gray-500">v1.0 Manager</p>
          </div>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 py-4 px-3 space-y-1">
        {navItems.map(item => (
          <button
            key={item.id}
            onClick={() => { onNavigate(item.id); onToggleMobile(); }}
            className={`sidebar-item w-full flex items-center justify-between gap-3 px-4 py-3 rounded-lg text-sm font-medium ${
              currentPage === item.id ? 'active' : 'text-gray-400 hover:text-white'
            }`}
          >
            <div className="flex items-center gap-3">
              {item.icon}
              {item.label}
            </div>
            {item.badge !== undefined && (
              <span className={`text-xs px-2 py-0.5 rounded-full ${
                currentPage === item.id ? 'bg-blue-500/20 text-blue-400' : 'bg-dark-600 text-gray-500'
              }`}>
                {item.badge}
              </span>
            )}
          </button>
        ))}
      </nav>

      {/* Server Info */}
      <div className="p-4 mx-3 mb-3 rounded-xl bg-dark-900/50 border border-blue-500/10">
        <div className="flex items-center justify-between text-xs mb-2">
          <span className="text-gray-500">Server Status</span>
          <span className="flex items-center gap-1 text-green-400">
            <span className="pulse-dot inline-block w-1.5 h-1.5 rounded-full bg-green-400" />
            Online
          </span>
        </div>
        <div className="flex items-center justify-between text-xs">
          <span className="text-gray-500">Ubuntu 22.04 LTS</span>
          <span className="text-gray-400">{onlineCount} active</span>
        </div>
      </div>

      {/* Logout */}
      <div className="p-3 border-t border-blue-500/10">
        <button
          onClick={onLogout}
          className="w-full flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-red-400 hover:bg-red-500/10 transition-colors"
        >
          <LogOut size={20} />
          Logout
        </button>
      </div>
    </>
  );

  return (
    <>
      {/* Mobile toggle */}
      <button
        onClick={onToggleMobile}
        className="lg:hidden fixed top-4 left-4 z-50 p-2 rounded-lg bg-dark-800 border border-blue-500/20 text-white"
      >
        {mobileOpen ? <X size={20} /> : <Menu size={20} />}
      </button>

      {/* Mobile overlay */}
      {mobileOpen && (
        <div className="lg:hidden fixed inset-0 z-30 bg-black/60" onClick={onToggleMobile} />
      )}

      {/* Sidebar */}
      <aside className={`fixed lg:static top-0 left-0 z-40 h-screen w-64 flex flex-col transition-transform duration-300 ${
        mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
      }`} style={{ background: 'linear-gradient(180deg, #111827 0%, #0d1321 100%)', borderRight: '1px solid rgba(59, 130, 246, 0.1)' }}>
        {sidebarContent}
      </aside>
    </>
  );
};

export default Sidebar;
