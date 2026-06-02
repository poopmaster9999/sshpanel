export default function App() {
  return (
    <div style={{
      minHeight: '100vh',
      background: '#0a0e17',
      color: '#e2e8f0',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      fontFamily: 'system-ui, sans-serif',
      padding: '20px',
      textAlign: 'center'
    }}>
      <div>
        <div style={{
          width: '80px',
          height: '80px',
          margin: '0 auto 20px',
          borderRadius: '20px',
          background: 'linear-gradient(135deg, #3b82f6, #8b5cf6)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          fontSize: '40px',
          boxShadow: '0 10px 40px rgba(59, 130, 246, 0.3)'
        }}>🖥️</div>
        <h1 style={{ fontSize: '2rem', fontWeight: 'bold', marginBottom: '10px' }}>SSH Panel Manager</h1>
        <p style={{ color: '#9ca3af', marginBottom: '30px' }}>PHP-based SSH User Management for Ubuntu 22.04</p>
        
        <div style={{
          background: 'linear-gradient(135deg, #1a2235, #111827)',
          border: '1px solid rgba(59, 130, 246, 0.2)',
          borderRadius: '16px',
          padding: '30px',
          maxWidth: '500px',
          textAlign: 'left'
        }}>
          <h2 style={{ fontSize: '1.1rem', fontWeight: '600', marginBottom: '15px' }}>📦 Installation on Ubuntu 22.04</h2>
          <div style={{
            background: '#0a0e17',
            borderRadius: '8px',
            padding: '15px',
            fontFamily: 'monospace',
            fontSize: '13px',
            color: '#10b981',
            marginBottom: '20px',
            overflowX: 'auto'
          }}>
            <div style={{ color: '#6b7280' }}># Copy files to server</div>
            <div>scp public/* root@your-server:/opt/sshpanel/</div>
            <br/>
            <div style={{ color: '#6b7280' }}># Run installer</div>
            <div>sudo bash /opt/sshpanel/install.sh</div>
            <br/>
            <div style={{ color: '#6b7280' }}># Or start manually</div>
            <div>php -S 0.0.0.0:8080 -t /opt/sshpanel/</div>
          </div>
          
          <h2 style={{ fontSize: '1.1rem', fontWeight: '600', marginBottom: '10px' }}>✅ Features</h2>
          <ul style={{ color: '#9ca3af', fontSize: '14px', lineHeight: '1.8', paddingLeft: '20px' }}>
            <li>Create/Delete SSH users (real system users)</li>
            <li>Time management (expiry in days)</li>
            <li>Data limits with auto-pause</li>
            <li>Bandwidth limiting</li>
            <li>Connection limits per user</li>
            <li>QR codes for V2Box, Rocket, NapsternetV, NetMod</li>
            <li>User portal (separate login)</li>
            <li>Telegram bot backups</li>
            <li>SQL backup/restore</li>
          </ul>
        </div>
        
        <p style={{ marginTop: '20px', color: '#6b7280', fontSize: '12px' }}>
          PHP files are in the <code style={{ background: '#1a2235', padding: '2px 6px', borderRadius: '4px' }}>public/</code> folder
        </p>
      </div>
    </div>
  );
}
