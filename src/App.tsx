import React, { useState } from 'react';
import { Terminal, Shield, Users, Wifi, Database, QrCode, Bot, Settings, Clock, Download, ChevronDown, ChevronUp, Monitor, Key, Smartphone, BarChart3, ArrowRight, CheckCircle2, Copy, Check, Globe, Zap, HardDrive } from 'lucide-react';

const App: React.FC = () => {
  const [copiedCmd, setCopiedCmd] = useState<string | null>(null);
  const [expandedFaq, setExpandedFaq] = useState<number | null>(null);
  const [activeTab, setActiveTab] = useState<'install' | 'manual' | 'requirements'>('install');

  const copyCommand = (cmd: string, id: string) => {
    navigator.clipboard.writeText(cmd);
    setCopiedCmd(id);
    setTimeout(() => setCopiedCmd(null), 2000);
  };

  const features = [
    { icon: <Users size={24} />, title: 'User Management', desc: 'Create, edit, delete SSH users with full control. Set passwords manually or generate random ones.', color: 'from-blue-500 to-blue-600' },
    { icon: <Clock size={24} />, title: 'Time Management', desc: 'Set expiry per user in days. Auto-disable expired accounts. Visual countdown.', color: 'from-green-500 to-emerald-600' },
    { icon: <Database size={24} />, title: 'Traffic Control', desc: 'Set data limits per user (MB/GB). Monitor usage with progress bars. Auto-block on limit.', color: 'from-cyan-500 to-cyan-600' },
    { icon: <Wifi size={24} />, title: 'Connection Limits', desc: 'Set max simultaneous connections per user. See who is connected and from which IP.', color: 'from-purple-500 to-purple-600' },
    { icon: <QrCode size={24} />, title: 'QR Code System', desc: 'Generate QR codes & links for V2Box, Rocket Tunnel, NapsternetV, and NetMod.', color: 'from-pink-500 to-rose-600' },
    { icon: <Bot size={24} />, title: 'Telegram Bot', desc: 'Connect your bot for automated backups. Send SQL backups to multiple chat IDs.', color: 'from-yellow-500 to-amber-600' },
    { icon: <Shield size={24} />, title: 'User Portal', desc: 'Users login to see their time, data, QR codes — without accessing the admin panel.', color: 'from-indigo-500 to-indigo-600' },
    { icon: <BarChart3 size={24} />, title: 'Dashboard Overview', desc: 'See online users, top data consumers, expired accounts, and server stats at a glance.', color: 'from-orange-500 to-orange-600' },
    { icon: <Settings size={24} />, title: 'SSH Port Control', desc: 'Change SSH port from the panel. Auto-restarts SSH daemon. Firewall guidance.', color: 'from-teal-500 to-teal-600' },
    { icon: <Key size={24} />, title: 'Password Generator', desc: 'One-click random password generation with customizable length. Copy to clipboard.', color: 'from-red-500 to-red-600' },
    { icon: <HardDrive size={24} />, title: 'SQL Backups', desc: 'Download backups in .sql format. Restore from file. Full backup history.', color: 'from-slate-500 to-slate-600' },
    { icon: <Zap size={24} />, title: 'Bandwidth Limiting', desc: 'Set per-user bandwidth limits in Kbps. Control download speeds via iptables/tc.', color: 'from-violet-500 to-violet-600' },
  ];

  const requirements = [
    { pkg: 'php8.1 + extensions', desc: 'PHP runtime with sqlite3, curl, gd, mbstring' },
    { pkg: 'sqlite3', desc: 'Lightweight database engine' },
    { pkg: 'openssh-server', desc: 'SSH server daemon' },
    { pkg: 'net-tools', desc: 'Network monitoring (netstat)' },
    { pkg: 'iptables', desc: 'Firewall & traffic accounting' },
    { pkg: 'vnstat', desc: 'Network traffic monitoring' },
    { pkg: 'cron', desc: 'Scheduled task runner' },
    { pkg: 'curl & wget', desc: 'HTTP clients for Telegram API' },
    { pkg: 'bc', desc: 'Math calculator for traffic' },
    { pkg: 'jq', desc: 'JSON processor for scripts' },
  ];

  const faqs = [
    { q: 'What are the default login credentials?', a: 'Username: admin, Password: admin. Change these immediately after installation in Settings.' },
    { q: 'Does it work without root access?', a: 'The panel UI works without root, but creating system SSH users, changing ports, and traffic monitoring require root privileges.' },
    { q: 'How does traffic monitoring work?', a: 'A cron job runs every minute, checking active SSH sessions via `who` command and tracking traffic through iptables byte counters.' },
    { q: 'Can users access the admin panel?', a: 'No. Users have a separate login page (?page=user_login) that only shows their own account details, QR codes, and usage stats.' },
    { q: 'How do backups work?', a: 'Backups are exported as .sql files containing all users and settings. They can be downloaded manually or sent automatically to Telegram.' },
    { q: 'What apps are supported for QR codes?', a: 'V2Box, Rocket Tunnel, NapsternetV, and NetMod. Each user gets connection URIs and QR codes for all four apps.' },
  ];

  return (
    <div className="min-h-screen bg-[#0a0e17] text-gray-200">
      {/* Hero Section */}
      <div className="relative overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-blue-600/10 via-transparent to-purple-600/10" />
        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[600px] bg-blue-500/5 rounded-full blur-3xl" />

        <div className="relative max-w-6xl mx-auto px-4 pt-16 pb-20">
          <div className="text-center">
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-sm mb-8">
              <Globe size={16} />
              Ubuntu 22.04 LTS Compatible
            </div>

            <div className="flex justify-center mb-6">
              <div className="w-24 h-24 rounded-3xl bg-gradient-to-br from-blue-500 via-blue-600 to-purple-600 flex items-center justify-center shadow-2xl shadow-blue-500/30">
                <Terminal size={48} className="text-white" />
              </div>
            </div>

            <h1 className="text-5xl md:text-6xl font-extrabold text-white mb-4">
              SSH Panel <span className="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-400">Manager</span>
            </h1>
            <p className="text-xl text-gray-400 max-w-2xl mx-auto mb-8">
              A complete SSH user management panel for Ubuntu 22.04. Create users, manage time & traffic,
              generate QR codes, backup to Telegram — all from a beautiful web interface.
            </p>

            <div className="flex flex-wrap justify-center gap-4 mb-12">
              <a href="#install" className="px-8 py-3.5 rounded-xl font-semibold text-white bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-400 hover:to-blue-500 shadow-lg shadow-blue-500/25 transition-all flex items-center gap-2">
                <Download size={20} />
                Install Now
              </a>
              <a href="#features" className="px-8 py-3.5 rounded-xl font-semibold text-gray-300 bg-white/5 border border-white/10 hover:bg-white/10 transition-all flex items-center gap-2">
                View Features
                <ArrowRight size={18} />
              </a>
            </div>

            {/* Quick Stats */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-3xl mx-auto">
              {[
                { label: 'Pure PHP', value: 'No Node.js', icon: '🐘' },
                { label: 'Database', value: 'SQLite', icon: '💾' },
                { label: 'QR Apps', value: '4 Apps', icon: '📱' },
                { label: 'Backup', value: 'SQL + TG', icon: '☁️' },
              ].map((s, i) => (
                <div key={i} className="rounded-xl bg-white/5 border border-white/10 p-4 text-center">
                  <div className="text-2xl mb-1">{s.icon}</div>
                  <div className="text-lg font-bold text-white">{s.value}</div>
                  <div className="text-xs text-gray-500">{s.label}</div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Features Grid */}
      <section id="features" className="max-w-6xl mx-auto px-4 py-20">
        <div className="text-center mb-12">
          <h2 className="text-3xl font-bold text-white mb-3">Everything You Need</h2>
          <p className="text-gray-400">Full-featured SSH management in a single PHP application</p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {features.map((f, i) => (
            <div key={i} className="rounded-2xl bg-gradient-to-br from-[#1a2235]/80 to-[#111827]/90 border border-blue-500/10 p-6 hover:border-blue-500/25 transition-all hover:-translate-y-1">
              <div className={`w-12 h-12 rounded-xl bg-gradient-to-br ${f.color} flex items-center justify-center text-white mb-4 shadow-lg`}>
                {f.icon}
              </div>
              <h3 className="text-lg font-semibold text-white mb-2">{f.title}</h3>
              <p className="text-sm text-gray-400 leading-relaxed">{f.desc}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Screenshots / Preview */}
      <section className="max-w-6xl mx-auto px-4 py-16">
        <div className="text-center mb-12">
          <h2 className="text-3xl font-bold text-white mb-3">Panel Preview</h2>
          <p className="text-gray-400">Clean, modern dark interface</p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* Admin Panel Preview */}
          <div className="rounded-2xl overflow-hidden border border-blue-500/15 bg-[#111827]">
            <div className="bg-[#0d1321] px-4 py-3 flex items-center gap-2 border-b border-blue-500/10">
              <div className="flex gap-1.5">
                <div className="w-3 h-3 rounded-full bg-red-500/80" />
                <div className="w-3 h-3 rounded-full bg-yellow-500/80" />
                <div className="w-3 h-3 rounded-full bg-green-500/80" />
              </div>
              <span className="text-xs text-gray-500 ml-2">Admin Dashboard</span>
            </div>
            <div className="p-5 space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl bg-[#1a2235] border border-blue-500/10 p-3">
                  <p className="text-xs text-gray-500">Total Users</p>
                  <p className="text-2xl font-bold text-white">24</p>
                </div>
                <div className="rounded-xl bg-[#1a2235] border border-green-500/10 p-3">
                  <p className="text-xs text-gray-500">Online Now</p>
                  <p className="text-2xl font-bold text-green-400">8</p>
                </div>
              </div>
              <div className="rounded-xl bg-[#1a2235] border border-blue-500/10 p-3">
                <p className="text-xs text-gray-500 mb-2">Top Data Usage</p>
                {['user_ali', 'reza_vpn', 'sara_ssh'].map((name, i) => (
                  <div key={i} className="flex items-center gap-2 mb-1.5">
                    <span className="text-xs text-gray-400 w-16 truncate">{name}</span>
                    <div className="flex-1 h-2 rounded bg-blue-500/10">
                      <div className="h-full rounded bg-blue-500" style={{ width: `${90 - i * 25}%` }} />
                    </div>
                    <span className="text-xs text-cyan-400">{(3.2 - i * 0.8).toFixed(1)}GB</span>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* User Portal Preview */}
          <div className="rounded-2xl overflow-hidden border border-purple-500/15 bg-[#111827]">
            <div className="bg-[#0d1321] px-4 py-3 flex items-center gap-2 border-b border-purple-500/10">
              <div className="flex gap-1.5">
                <div className="w-3 h-3 rounded-full bg-red-500/80" />
                <div className="w-3 h-3 rounded-full bg-yellow-500/80" />
                <div className="w-3 h-3 rounded-full bg-green-500/80" />
              </div>
              <span className="text-xs text-gray-500 ml-2">User Account Portal</span>
            </div>
            <div className="p-5 space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl bg-[#1a2235] border border-green-500/10 p-3">
                  <p className="text-xs text-gray-500">Time Left</p>
                  <p className="text-2xl font-bold text-green-400">23 days</p>
                </div>
                <div className="rounded-xl bg-[#1a2235] border border-cyan-500/10 p-3">
                  <p className="text-xs text-gray-500">Data Used</p>
                  <p className="text-2xl font-bold text-cyan-400">1.2 GB</p>
                  <div className="h-1.5 rounded bg-blue-500/10 mt-1">
                    <div className="h-full rounded bg-blue-500" style={{ width: '24%' }} />
                  </div>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                {['📦 V2Box', '🚀 Rocket'].map((app, i) => (
                  <div key={i} className="rounded-xl bg-[#1a2235] border border-blue-500/10 p-3 text-center">
                    <p className="text-sm mb-2">{app}</p>
                    <div className="w-16 h-16 mx-auto bg-white rounded-lg flex items-center justify-center">
                      <QrCode size={40} className="text-gray-800" />
                    </div>
                    <button className="mt-2 text-xs text-blue-400">Copy Link</button>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Installation Section */}
      <section id="install" className="max-w-4xl mx-auto px-4 py-20">
        <div className="text-center mb-12">
          <h2 className="text-3xl font-bold text-white mb-3">Installation</h2>
          <p className="text-gray-400">Get up and running in under 2 minutes</p>
        </div>

        {/* Tabs */}
        <div className="flex gap-2 mb-6">
          {(['install', 'manual', 'requirements'] as const).map(tab => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={`px-5 py-2.5 rounded-lg text-sm font-medium transition-all ${
                activeTab === tab ? 'bg-blue-500/20 text-blue-400 border border-blue-500/30' : 'text-gray-400 hover:text-white bg-white/5'
              }`}
            >
              {tab === 'install' ? '⚡ Quick Install' : tab === 'manual' ? '📋 Manual Install' : '📦 Requirements'}
            </button>
          ))}
        </div>

        {activeTab === 'install' && (
          <div className="space-y-4">
            <div className="rounded-2xl bg-[#111827] border border-blue-500/15 overflow-hidden">
              <div className="bg-[#0d1321] px-4 py-3 flex items-center justify-between border-b border-blue-500/10">
                <span className="text-xs text-gray-500">Terminal — Quick Install</span>
                <span className="text-xs text-green-400">bash</span>
              </div>
              <div className="p-5 space-y-3 font-mono text-sm">
                {[
                  { cmd: 'sudo apt update && sudo apt install -y git', comment: '# Install git' },
                  { cmd: 'git clone https://github.com/your-repo/sshpanel.git /opt/sshpanel', comment: '# Download panel' },
                  { cmd: 'cd /opt/sshpanel && sudo bash install.sh', comment: '# Run installer' },
                ].map((line, i) => (
                  <div key={i} className="group">
                    <div className="text-gray-600 text-xs mb-0.5">{line.comment}</div>
                    <div className="flex items-center justify-between bg-[#0a0e17] rounded-lg px-4 py-2.5">
                      <div>
                        <span className="text-green-400">$ </span>
                        <span className="text-gray-300">{line.cmd}</span>
                      </div>
                      <button
                        onClick={() => copyCommand(line.cmd, `cmd-${i}`)}
                        className="text-gray-600 hover:text-white transition-colors ml-4"
                      >
                        {copiedCmd === `cmd-${i}` ? <Check size={16} className="text-green-400" /> : <Copy size={16} />}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="rounded-2xl bg-green-500/5 border border-green-500/20 p-5">
              <h4 className="text-green-400 font-semibold mb-2 flex items-center gap-2">
                <CheckCircle2 size={18} />
                After Installation
              </h4>
              <div className="text-sm text-gray-400 space-y-1">
                <p>• Panel URL: <code className="text-green-400 bg-green-500/10 px-2 py-0.5 rounded">http://YOUR_IP:8080</code></p>
                <p>• Admin Login: <code className="text-blue-400 bg-blue-500/10 px-2 py-0.5 rounded">admin / admin</code></p>
                <p>• User Portal: <code className="text-purple-400 bg-purple-500/10 px-2 py-0.5 rounded">http://YOUR_IP:8080/?page=user_login</code></p>
                <p>• <span className="text-yellow-400">⚠️ Change the default password immediately!</span></p>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'manual' && (
          <div className="rounded-2xl bg-[#111827] border border-blue-500/15 overflow-hidden">
            <div className="bg-[#0d1321] px-4 py-3 border-b border-blue-500/10">
              <span className="text-xs text-gray-500">Terminal — Manual Installation</span>
            </div>
            <div className="p-5 font-mono text-sm space-y-3">
              {[
                '# Update system',
                'sudo apt-get update -y && sudo apt-get upgrade -y',
                '',
                '# Install all required packages',
                'sudo apt-get install -y php8.1 php8.1-cli php8.1-sqlite3 \\',
                '    php8.1-mbstring php8.1-xml php8.1-curl php8.1-gd \\',
                '    php8.1-zip php8.1-json php8.1-bcmath sqlite3 \\',
                '    openssh-server net-tools curl wget unzip cron \\',
                '    jq vnstat iptables sudo bc',
                '',
                '# Create panel directory',
                'sudo mkdir -p /opt/sshpanel/database',
                'sudo mkdir -p /opt/sshpanel/backups',
                '',
                '# Copy panel files to /opt/sshpanel/',
                '# (copy all .php files from the public/ directory)',
                '',
                '# Initialize database',
                'sudo php /opt/sshpanel/init_db.php',
                '',
                '# Start panel',
                'sudo php -S 0.0.0.0:8080 -t /opt/sshpanel/',
                '',
                '# Open firewall',
                'sudo ufw allow 8080/tcp',
                'sudo ufw allow 22/tcp',
              ].map((line, i) => (
                <div key={i} className={line.startsWith('#') ? 'text-gray-600' : line === '' ? 'h-2' : 'text-gray-300'}>
                  {line && !line.startsWith('#') && <span className="text-green-400">$ </span>}
                  {line}
                </div>
              ))}
            </div>
          </div>
        )}

        {activeTab === 'requirements' && (
          <div className="rounded-2xl bg-[#111827] border border-blue-500/15 p-6">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {requirements.map((r, i) => (
                <div key={i} className="flex items-start gap-3 p-3 rounded-xl bg-[#0a0e17]/50">
                  <CheckCircle2 size={18} className="text-green-400 mt-0.5 flex-shrink-0" />
                  <div>
                    <p className="text-white font-medium text-sm">{r.pkg}</p>
                    <p className="text-gray-500 text-xs">{r.desc}</p>
                  </div>
                </div>
              ))}
            </div>
            <div className="mt-6 p-4 rounded-xl bg-yellow-500/5 border border-yellow-500/20">
              <p className="text-yellow-400 text-sm font-medium">System Requirements</p>
              <p className="text-gray-400 text-xs mt-1">Ubuntu 22.04 LTS • 512MB RAM minimum • Root access • Open ports (SSH + Panel)</p>
            </div>
          </div>
        )}
      </section>

      {/* Service Management */}
      <section className="max-w-4xl mx-auto px-4 py-12">
        <h3 className="text-xl font-bold text-white mb-6 text-center">Service Management</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {[
            { cmd: 'sudo systemctl start sshpanel', label: 'Start Panel', color: 'text-green-400' },
            { cmd: 'sudo systemctl stop sshpanel', label: 'Stop Panel', color: 'text-red-400' },
            { cmd: 'sudo systemctl restart sshpanel', label: 'Restart Panel', color: 'text-yellow-400' },
            { cmd: 'sudo systemctl status sshpanel', label: 'Check Status', color: 'text-blue-400' },
          ].map((item, i) => (
            <div key={i} className="flex items-center justify-between rounded-xl bg-[#111827] border border-blue-500/10 px-4 py-3">
              <div>
                <p className={`text-sm font-medium ${item.color}`}>{item.label}</p>
                <code className="text-xs text-gray-500 font-mono">{item.cmd}</code>
              </div>
              <button onClick={() => copyCommand(item.cmd, `svc-${i}`)} className="text-gray-600 hover:text-white">
                {copiedCmd === `svc-${i}` ? <Check size={16} className="text-green-400" /> : <Copy size={16} />}
              </button>
            </div>
          ))}
        </div>
      </section>

      {/* FAQ */}
      <section className="max-w-4xl mx-auto px-4 py-16">
        <h3 className="text-2xl font-bold text-white mb-8 text-center">FAQ</h3>
        <div className="space-y-3">
          {faqs.map((faq, i) => (
            <div key={i} className="rounded-xl bg-[#111827] border border-blue-500/10 overflow-hidden">
              <button
                onClick={() => setExpandedFaq(expandedFaq === i ? null : i)}
                className="w-full flex items-center justify-between px-5 py-4 text-left"
              >
                <span className="text-white font-medium text-sm">{faq.q}</span>
                {expandedFaq === i ? <ChevronUp size={18} className="text-gray-500" /> : <ChevronDown size={18} className="text-gray-500" />}
              </button>
              {expandedFaq === i && (
                <div className="px-5 pb-4 text-sm text-gray-400 border-t border-blue-500/5 pt-3">
                  {faq.a}
                </div>
              )}
            </div>
          ))}
        </div>
      </section>

      {/* File Structure */}
      <section className="max-w-4xl mx-auto px-4 py-12">
        <h3 className="text-xl font-bold text-white mb-6 text-center">Project Files</h3>
        <div className="rounded-2xl bg-[#111827] border border-blue-500/15 p-6 font-mono text-sm">
          <div className="text-gray-400 space-y-1">
            <div className="text-blue-400 font-bold">/opt/sshpanel/</div>
            {[
              ['├── index.php', 'Main entry point & full UI (router, pages, modals)'],
              ['├── config.php', 'Configuration constants'],
              ['├── init_db.php', 'Database schema initialization'],
              ['├── functions.php', 'Core functions (user mgmt, backups, telegram)'],
              ['├── api.php', 'JSON API endpoints'],
              ['├── install.sh', 'Automated installer script'],
              ['├── uninstall.sh', 'Uninstaller script'],
              ['├── requirements.txt', 'Full requirements list'],
              ['├── database/', ''],
              ['│   └── panel.db', 'SQLite database'],
              ['├── backups/', ''],
              ['│   └── *.sql', 'SQL backup files'],
              ['└── logs/', ''],
              ['    └── panel.log', 'Server logs'],
            ].map(([file, desc], i) => (
              <div key={i} className="flex gap-4">
                <span className="text-green-400/80 whitespace-pre">{file}</span>
                {desc && <span className="text-gray-600"># {desc}</span>}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-blue-500/10 py-12">
        <div className="max-w-6xl mx-auto px-4 text-center">
          <div className="flex justify-center items-center gap-3 mb-4">
            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
              <Terminal size={20} className="text-white" />
            </div>
            <span className="text-xl font-bold text-white">SSH Panel Manager</span>
          </div>
          <p className="text-gray-500 text-sm mb-6">
            Open source SSH user management for Ubuntu 22.04 LTS
          </p>
          <div className="flex justify-center gap-6 text-sm text-gray-500">
            <span className="flex items-center gap-1"><Monitor size={14} /> Ubuntu 22.04</span>
            <span className="flex items-center gap-1"><Smartphone size={14} /> 4 App QR Codes</span>
            <span className="flex items-center gap-1"><Database size={14} /> SQLite + SQL Backups</span>
            <span className="flex items-center gap-1"><Bot size={14} /> Telegram Bot</span>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default App;
