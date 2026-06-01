import React, { useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { SSHUser } from '../types';
import { getSettings } from '../store';
import { generateConnectionConfigs, copyToClipboard } from '../qrUtils';
import { Copy, Check, ExternalLink } from 'lucide-react';

interface UserQRCodesProps {
  user: SSHUser;
}

const UserQRCodes: React.FC<UserQRCodesProps> = ({ user }) => {
  const settings = getSettings();
  const configs = generateConnectionConfigs(user, settings, window.location.hostname || '0.0.0.0');
  const [copiedIndex, setCopiedIndex] = useState<number | null>(null);

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
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
      {configs.map((config, index) => (
        <div
          key={config.app}
          className="glass-card p-4 rounded-xl border border-opacity-30"
          style={{ borderColor: appColors[config.app] + '50' }}
        >
          <div className="flex items-center gap-2 mb-3">
            <span className="text-2xl">{config.icon}</span>
            <h3 className="font-semibold text-white">{config.name}</h3>
          </div>
          
          <div className="flex justify-center mb-3">
            <div className="bg-white p-3 rounded-lg">
              <QRCodeSVG
                value={config.uri}
                size={160}
                level="M"
                includeMargin={false}
              />
            </div>
          </div>

          <div className="bg-dark-900 rounded-lg p-2 mb-3">
            <p className="text-xs text-gray-400 break-all font-mono leading-relaxed max-h-16 overflow-y-auto">
              {config.uri}
            </p>
          </div>

          <div className="flex gap-2">
            <button
              onClick={() => handleCopy(config.uri, index)}
              className="flex-1 flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-sm font-medium transition-all"
              style={{
                background: copiedIndex === index ? 'rgba(16,185,129,0.2)' : `${appColors[config.app]}20`,
                color: copiedIndex === index ? '#10b981' : appColors[config.app],
                border: `1px solid ${copiedIndex === index ? 'rgba(16,185,129,0.3)' : appColors[config.app] + '30'}`,
              }}
            >
              {copiedIndex === index ? <Check size={14} /> : <Copy size={14} />}
              {copiedIndex === index ? 'Copied!' : 'Copy Link'}
            </button>
            <button
              onClick={() => window.open(config.uri, '_blank')}
              className="flex items-center justify-center gap-1 py-2 px-3 rounded-lg text-sm font-medium bg-dark-600 text-gray-400 hover:text-white border border-dark-500"
            >
              <ExternalLink size={14} />
            </button>
          </div>
        </div>
      ))}
    </div>
  );
};

export default UserQRCodes;
