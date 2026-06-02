#!/bin/bash
#=====================================================
#  SSH Panel Manager - Ubuntu 22.04 Installer
#  Run: sudo bash install.sh
#=====================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}"
echo "╔══════════════════════════════════════════════════╗"
echo "║       SSH Panel Manager - Installer              ║"
echo "║            Ubuntu 22.04 LTS                      ║"
echo "╚══════════════════════════════════════════════════╝"
echo -e "${NC}"

if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERROR] Please run as root: sudo bash install.sh${NC}"
    exit 1
fi

PANEL_DIR="/opt/sshpanel"
PANEL_PORT="${1:-8080}"

echo -e "${GREEN}[1/6] Installing packages...${NC}"
apt-get update -y
apt-get install -y php8.1 php8.1-cli php8.1-sqlite3 php8.1-curl sqlite3 openssh-server curl

echo -e "${GREEN}[2/6] Setting up directories...${NC}"
mkdir -p "$PANEL_DIR/database"
mkdir -p "$PANEL_DIR/backups"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp "$SCRIPT_DIR/index.php" "$PANEL_DIR/" 2>/dev/null || echo "Copy index.php manually"
chmod -R 755 "$PANEL_DIR"
chmod 777 "$PANEL_DIR/database"
chmod 777 "$PANEL_DIR/backups"

echo -e "${GREEN}[3/6] Configuring SSH...${NC}"
systemctl enable ssh
systemctl start ssh
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication no/PasswordAuthentication yes/' /etc/ssh/sshd_config
systemctl restart ssh

echo -e "${GREEN}[4/6] Creating sshusers group...${NC}"
groupadd -f sshusers

echo -e "${GREEN}[5/6] Setting up traffic monitor...${NC}"
cat > /usr/local/bin/sshpanel-monitor.sh << 'EOF'
#!/bin/bash
DB="/opt/sshpanel/database/panel.db"
[ ! -f "$DB" ] && exit 0

for user in $(sqlite3 "$DB" "SELECT username FROM users WHERE is_enabled=1;" 2>/dev/null); do
    [ ! $(id -u "$user" 2>/dev/null) ] && continue
    CONN=$(who | grep -c "^${user} " 2>/dev/null || echo 0)
    IPS=$(who | grep "^${user} " | grep -oP '\(\K[^)]+' | sort -u | tr '\n' ',' | sed 's/,$//')
    ON=$([[ "$CONN" -gt 0 ]] && echo 1 || echo 0)
    sqlite3 "$DB" "UPDATE users SET cur_conn=$CONN, is_online=$ON, ips='$IPS' WHERE username='$user';" 2>/dev/null
done

# Pause expired
sqlite3 "$DB" "UPDATE users SET is_enabled=0 WHERE expires_at < datetime('now') AND is_enabled=1;" 2>/dev/null
for user in $(sqlite3 "$DB" "SELECT username FROM users WHERE is_enabled=0;" 2>/dev/null); do
    passwd -l "$user" 2>/dev/null
    pkill -u "$user" 2>/dev/null
done

# Pause over data limit
for user in $(sqlite3 "$DB" "SELECT username FROM users WHERE data_limit > 0 AND data_used >= data_limit AND is_enabled=1;" 2>/dev/null); do
    passwd -l "$user" 2>/dev/null
    pkill -u "$user" 2>/dev/null
    sqlite3 "$DB" "UPDATE users SET is_enabled=0 WHERE username='$user';" 2>/dev/null
done
EOF
chmod +x /usr/local/bin/sshpanel-monitor.sh
(crontab -l 2>/dev/null | grep -v "sshpanel-monitor"; echo "* * * * * /usr/local/bin/sshpanel-monitor.sh") | crontab -

echo -e "${GREEN}[6/6] Creating systemd service...${NC}"
cat > /etc/systemd/system/sshpanel.service << EOF
[Unit]
Description=SSH Panel Manager
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=$PANEL_DIR
ExecStart=/usr/bin/php -S 0.0.0.0:$PANEL_PORT -t $PANEL_DIR
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable sshpanel
systemctl start sshpanel

SERVER_IP=$(hostname -I | awk '{print $1}')

echo ""
echo -e "${GREEN}════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}     Installation Complete!${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${CYAN}Panel URL:${NC}       http://$SERVER_IP:$PANEL_PORT"
echo -e "${CYAN}User Portal:${NC}     http://$SERVER_IP:$PANEL_PORT/?page=user_login"
echo -e "${CYAN}Admin Login:${NC}     admin / admin"
echo ""
echo -e "${YELLOW}⚠ Change the default password immediately!${NC}"
echo ""
echo -e "Commands:"
echo "  sudo systemctl start sshpanel"
echo "  sudo systemctl stop sshpanel"
echo "  sudo systemctl restart sshpanel"
echo "  sudo systemctl status sshpanel"
echo ""
