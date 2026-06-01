#!/bin/bash
#=====================================================
#  SSH Panel Manager - Ubuntu 22.04 Installer
#  Run: sudo bash install.sh
#=====================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}"
echo "╔══════════════════════════════════════════════╗"
echo "║       SSH Panel Manager - Installer          ║"
echo "║            Ubuntu 22.04 LTS                  ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERROR] Please run as root: sudo bash install.sh${NC}"
    exit 1
fi

# Check Ubuntu version
if ! grep -q "22" /etc/os-release 2>/dev/null; then
    echo -e "${YELLOW}[WARNING] This script is designed for Ubuntu 22.04. Proceed anyway? (y/n)${NC}"
    read -r answer
    if [ "$answer" != "y" ]; then
        exit 1
    fi
fi

PANEL_DIR="/opt/sshpanel"
PANEL_PORT="${1:-8080}"
DB_DIR="/opt/sshpanel/database"

echo -e "${GREEN}[1/9] Updating system packages...${NC}"
apt-get update -y

echo -e "${GREEN}[2/9] Installing required packages...${NC}"
apt-get install -y \
    php8.1 \
    php8.1-cli \
    php8.1-sqlite3 \
    php8.1-mbstring \
    php8.1-xml \
    php8.1-curl \
    php8.1-gd \
    php8.1-zip \
    sqlite3 \
    openssh-server \
    net-tools \
    curl \
    wget \
    unzip \
    cron \
    bc \
    sudo

# Start cron if not running
systemctl enable cron
systemctl start cron

echo -e "${GREEN}[3/9] Configuring SSH server...${NC}"
systemctl enable ssh
systemctl start ssh

# Enable password authentication
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication no/PasswordAuthentication yes/' /etc/ssh/sshd_config
systemctl restart ssh

echo -e "${GREEN}[4/9] Setting up panel directory...${NC}"
mkdir -p "$PANEL_DIR"
mkdir -p "$DB_DIR"
mkdir -p "$PANEL_DIR/backups"
mkdir -p "$PANEL_DIR/logs"

# Copy panel files
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp "$SCRIPT_DIR"/*.php "$PANEL_DIR/" 2>/dev/null || true
cp "$SCRIPT_DIR"/*.txt "$PANEL_DIR/" 2>/dev/null || true
cp "$SCRIPT_DIR"/*.sh "$PANEL_DIR/" 2>/dev/null || true

chmod -R 755 "$PANEL_DIR"
chmod 777 "$DB_DIR"
chmod 777 "$PANEL_DIR/backups"
chmod 777 "$PANEL_DIR/logs"

echo -e "${GREEN}[5/9] Initializing database...${NC}"
cd "$PANEL_DIR"
php init_db.php

echo -e "${GREEN}[6/9] Creating sshusers group...${NC}"
groupadd -f sshusers

echo -e "${GREEN}[7/9] Setting up traffic monitoring...${NC}"
cat > /usr/local/bin/ssh-panel-monitor.sh << 'MONITOR_EOF'
#!/bin/bash
# SSH Panel Traffic Monitor - runs every minute via cron

DB_PATH="/opt/sshpanel/database/panel.db"

if [ ! -f "$DB_PATH" ]; then
    exit 0
fi

# Get all enabled users
USERS=$(sqlite3 "$DB_PATH" "SELECT username FROM ssh_users WHERE is_enabled=1;" 2>/dev/null)

for user in $USERS; do
    # Skip if user doesn't exist on system
    if ! id "$user" &>/dev/null; then
        continue
    fi

    # Count SSH sessions for this user using 'who'
    CONNECTIONS=$(who | grep -c "^${user} " 2>/dev/null || echo "0")
    
    # Get connected IPs
    IPS=$(who | grep "^${user} " | grep -oP '\(\K[^)]+' | sort -u | tr '\n' ',' | sed 's/,$//')
    
    IS_ONLINE=0
    if [ "$CONNECTIONS" -gt 0 ]; then
        IS_ONLINE=1
    fi
    
    # Update online status
    sqlite3 "$DB_PATH" "UPDATE ssh_users SET current_connections=$CONNECTIONS, is_online=$IS_ONLINE, connected_ips='$IPS' WHERE username='$user';" 2>/dev/null
    
    if [ "$IS_ONLINE" -eq 1 ]; then
        sqlite3 "$DB_PATH" "UPDATE ssh_users SET last_connected=datetime('now') WHERE username='$user';" 2>/dev/null
    fi
    
    # Traffic accounting using /proc/net/dev or iptables
    # Get UID
    UID_NUM=$(id -u "$user" 2>/dev/null)
    if [ -n "$UID_NUM" ]; then
        # Check iptables for traffic (if rules exist)
        BYTES=$(iptables -L OUTPUT -v -n -x 2>/dev/null | grep "owner UID match $UID_NUM" | awk '{sum+=$2} END {print sum+0}')
        if [ "$BYTES" -gt 0 ]; then
            MB_USED=$(echo "scale=4; $BYTES / 1048576" | bc 2>/dev/null || echo "0")
            if [ "$MB_USED" != "0" ]; then
                sqlite3 "$DB_PATH" "UPDATE ssh_users SET data_used=data_used+$MB_USED WHERE username='$user';" 2>/dev/null
                # Reset iptables counter
                iptables -Z OUTPUT 2>/dev/null
            fi
        fi
    fi
done

# Pause expired users
EXPIRED=$(sqlite3 "$DB_PATH" "SELECT username FROM ssh_users WHERE expires_at < datetime('now') AND is_enabled=1;" 2>/dev/null)
for user in $EXPIRED; do
    if id "$user" &>/dev/null; then
        passwd -l "$user" 2>/dev/null
        pkill -KILL -u "$user" 2>/dev/null
    fi
    sqlite3 "$DB_PATH" "UPDATE ssh_users SET is_enabled=0 WHERE username='$user';" 2>/dev/null
done

# Pause users over data limit
OVER_LIMIT=$(sqlite3 "$DB_PATH" "SELECT username FROM ssh_users WHERE data_limit > 0 AND data_used >= data_limit AND is_enabled=1;" 2>/dev/null)
for user in $OVER_LIMIT; do
    if id "$user" &>/dev/null; then
        passwd -l "$user" 2>/dev/null
        pkill -KILL -u "$user" 2>/dev/null
    fi
    sqlite3 "$DB_PATH" "UPDATE ssh_users SET is_enabled=0 WHERE username='$user';" 2>/dev/null
done
MONITOR_EOF
chmod +x /usr/local/bin/ssh-panel-monitor.sh

echo -e "${GREEN}[8/9] Setting up cron jobs...${NC}"
# Remove old cron entries and add new one
(crontab -l 2>/dev/null | grep -v "ssh-panel-monitor"; echo "* * * * * /usr/local/bin/ssh-panel-monitor.sh >/dev/null 2>&1") | crontab -

echo -e "${GREEN}[9/9] Creating systemd service...${NC}"
cat > /etc/systemd/system/sshpanel.service << SVCEOF
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
StandardOutput=append:$PANEL_DIR/logs/panel.log
StandardError=append:$PANEL_DIR/logs/panel_error.log

[Install]
WantedBy=multi-user.target
SVCEOF

systemctl daemon-reload
systemctl enable sshpanel
systemctl start sshpanel

# Get server IP
SERVER_IP=$(hostname -I | awk '{print $1}')

# Run initial sync
/usr/local/bin/ssh-panel-monitor.sh 2>/dev/null || true

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     Installation Complete!                    ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${CYAN}Panel URL:${NC}       http://$SERVER_IP:$PANEL_PORT"
echo -e "${CYAN}User Portal:${NC}     http://$SERVER_IP:$PANEL_PORT/?page=user_login"
echo -e "${CYAN}Admin Login:${NC}     admin / admin"
echo -e "${CYAN}Panel Directory:${NC} $PANEL_DIR"
echo -e "${CYAN}Database:${NC}        $DB_DIR/panel.db"
echo ""
echo -e "${YELLOW}⚠ IMPORTANT: Change the default admin password immediately!${NC}"
echo ""
echo -e "${GREEN}Commands:${NC}"
echo "  Start panel:   sudo systemctl start sshpanel"
echo "  Stop panel:    sudo systemctl stop sshpanel"
echo "  Restart panel: sudo systemctl restart sshpanel"
echo "  Panel status:  sudo systemctl status sshpanel"
echo "  View logs:     tail -f $PANEL_DIR/logs/panel.log"
echo ""
echo -e "${GREEN}Firewall (if using ufw):${NC}"
echo "  sudo ufw allow $PANEL_PORT/tcp"
echo "  sudo ufw allow 22/tcp"
echo ""
