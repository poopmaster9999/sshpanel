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
if ! grep -q "22.04" /etc/os-release 2>/dev/null; then
    echo -e "${YELLOW}[WARNING] This script is designed for Ubuntu 22.04. Proceed anyway? (y/n)${NC}"
    read -r answer
    if [ "$answer" != "y" ]; then
        exit 1
    fi
fi

PANEL_DIR="/opt/sshpanel"
PANEL_PORT=8080
DB_DIR="/opt/sshpanel/database"

echo -e "${GREEN}[1/8] Updating system packages...${NC}"
apt-get update -y
apt-get upgrade -y

echo -e "${GREEN}[2/8] Installing required packages...${NC}"
apt-get install -y \
    php8.1 \
    php8.1-cli \
    php8.1-sqlite3 \
    php8.1-mbstring \
    php8.1-xml \
    php8.1-curl \
    php8.1-gd \
    php8.1-zip \
    php8.1-json \
    php8.1-bcmath \
    sqlite3 \
    openssh-server \
    net-tools \
    curl \
    wget \
    unzip \
    cron \
    jq \
    vnstat \
    iptables \
    sudo \
    bc

echo -e "${GREEN}[3/8] Configuring SSH server...${NC}"
# Enable SSH if not already
systemctl enable ssh
systemctl start ssh

# Ensure password authentication is enabled
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication no/PasswordAuthentication yes/' /etc/ssh/sshd_config
systemctl restart ssh

echo -e "${GREEN}[4/8] Setting up panel directory...${NC}"
mkdir -p "$PANEL_DIR"
mkdir -p "$DB_DIR"
mkdir -p "$PANEL_DIR/backups"
mkdir -p "$PANEL_DIR/logs"

# Copy panel files
cp -r ./* "$PANEL_DIR/" 2>/dev/null || true
chmod -R 755 "$PANEL_DIR"
chmod 777 "$DB_DIR"
chmod 777 "$PANEL_DIR/backups"
chmod 777 "$PANEL_DIR/logs"

echo -e "${GREEN}[5/8] Initializing database...${NC}"
php "$PANEL_DIR/init_db.php"

echo -e "${GREEN}[6/8] Setting up traffic monitoring...${NC}"
# Create traffic monitoring script
cat > /usr/local/bin/ssh-traffic-monitor.sh << 'MONITOR_EOF'
#!/bin/bash
# SSH Traffic Monitor - runs every minute via cron
DB_PATH="/opt/sshpanel/database/panel.db"

if [ ! -f "$DB_PATH" ]; then
    exit 0
fi

# Get list of SSH users from database
USERS=$(sqlite3 "$DB_PATH" "SELECT username FROM ssh_users WHERE is_enabled=1;")

for user in $USERS; do
    # Check if user exists on system
    if id "$user" &>/dev/null; then
        # Count active SSH sessions
        CONNECTIONS=$(who | grep "^${user} " | wc -l)
        
        # Get connected IPs
        IPS=$(who | grep "^${user} " | awk '{print $5}' | tr -d '()' | sort -u | tr '\n' ',' | sed 's/,$//')
        
        IS_ONLINE=0
        if [ "$CONNECTIONS" -gt 0 ]; then
            IS_ONLINE=1
        fi
        
        # Update database
        sqlite3 "$DB_PATH" "UPDATE ssh_users SET current_connections=$CONNECTIONS, is_online=$IS_ONLINE, connected_ips='$IPS' WHERE username='$user';"
        
        if [ "$IS_ONLINE" -eq 1 ]; then
            sqlite3 "$DB_PATH" "UPDATE ssh_users SET last_connected=datetime('now') WHERE username='$user';"
        fi
        
        # Traffic accounting via iptables (bytes)
        BYTES_OUT=$(iptables -L OUTPUT -v -n -x 2>/dev/null | grep "owner UID match $(id -u $user 2>/dev/null)" | awk '{sum+=$2} END {print sum+0}')
        BYTES_IN=$(iptables -L INPUT -v -n -x 2>/dev/null | grep -c "$user" 2>/dev/null || echo "0")
        
        if [ "$BYTES_OUT" -gt 0 ]; then
            MB_USED=$(echo "scale=2; $BYTES_OUT / 1048576" | bc)
            sqlite3 "$DB_PATH" "UPDATE ssh_users SET data_used=data_used+$MB_USED WHERE username='$user';"
        fi
    fi
done

# Check and disable expired users
sqlite3 "$DB_PATH" "UPDATE ssh_users SET is_enabled=0 WHERE expires_at < datetime('now') AND is_enabled=1;"

# Disable expired users on system
EXPIRED=$(sqlite3 "$DB_PATH" "SELECT username FROM ssh_users WHERE is_enabled=0;")
for user in $EXPIRED; do
    if id "$user" &>/dev/null; then
        usermod -L "$user" 2>/dev/null
        # Kill their sessions
        pkill -u "$user" 2>/dev/null || true
    fi
done

# Check data limits
OVER_LIMIT=$(sqlite3 "$DB_PATH" "SELECT username FROM ssh_users WHERE data_limit > 0 AND data_used >= data_limit AND is_enabled=1;")
for user in $OVER_LIMIT; do
    if id "$user" &>/dev/null; then
        usermod -L "$user" 2>/dev/null
        pkill -u "$user" 2>/dev/null || true
        sqlite3 "$DB_PATH" "UPDATE ssh_users SET is_enabled=0 WHERE username='$user';"
    fi
done
MONITOR_EOF
chmod +x /usr/local/bin/ssh-traffic-monitor.sh

echo -e "${GREEN}[7/8] Setting up cron jobs...${NC}"
# Add cron job for traffic monitoring (every minute)
(crontab -l 2>/dev/null; echo "* * * * * /usr/local/bin/ssh-traffic-monitor.sh") | sort -u | crontab -

echo -e "${GREEN}[8/8] Creating systemd service...${NC}"
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

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     Installation Complete!                    ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${CYAN}Panel URL:${NC}       http://$SERVER_IP:$PANEL_PORT"
echo -e "${CYAN}Admin User:${NC}      admin"
echo -e "${CYAN}Admin Password:${NC}  admin"
echo -e "${CYAN}Panel Directory:${NC} $PANEL_DIR"
echo -e "${CYAN}Database:${NC}        $DB_DIR/panel.db"
echo -e "${CYAN}Backups:${NC}         $PANEL_DIR/backups/"
echo ""
echo -e "${YELLOW}⚠ IMPORTANT: Change the default admin password immediately!${NC}"
echo -e "${YELLOW}⚠ Open firewall: sudo ufw allow $PANEL_PORT/tcp${NC}"
echo ""
echo -e "${GREEN}Commands:${NC}"
echo "  Start panel:   sudo systemctl start sshpanel"
echo "  Stop panel:    sudo systemctl stop sshpanel"
echo "  Restart panel: sudo systemctl restart sshpanel"
echo "  Panel status:  sudo systemctl status sshpanel"
echo "  View logs:     tail -f $PANEL_DIR/logs/panel.log"
echo ""
