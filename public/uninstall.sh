#!/bin/bash
#=====================================================
#  SSH Panel Manager - Uninstaller
#  Run: sudo bash uninstall.sh
#=====================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${RED}"
echo "╔══════════════════════════════════════════════╗"
echo "║     SSH Panel Manager - Uninstaller          ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"

if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Please run as root: sudo bash uninstall.sh${NC}"
    exit 1
fi

echo -e "${YELLOW}This will remove the SSH Panel and all its data.${NC}"
echo -e "${YELLOW}SSH users created by the panel will NOT be deleted.${NC}"
echo ""
read -p "Are you sure? (yes/no): " answer

if [ "$answer" != "yes" ]; then
    echo "Cancelled."
    exit 0
fi

echo -e "${GREEN}[1/4] Stopping service...${NC}"
systemctl stop sshpanel 2>/dev/null
systemctl disable sshpanel 2>/dev/null

echo -e "${GREEN}[2/4] Removing service file...${NC}"
rm -f /etc/systemd/system/sshpanel.service
systemctl daemon-reload

echo -e "${GREEN}[3/4] Removing cron jobs...${NC}"
crontab -l 2>/dev/null | grep -v "ssh-traffic-monitor" | crontab -
rm -f /usr/local/bin/ssh-traffic-monitor.sh

echo -e "${GREEN}[4/4] Removing panel files...${NC}"
read -p "Remove panel directory /opt/sshpanel? (y/n): " rmdir
if [ "$rmdir" = "y" ]; then
    rm -rf /opt/sshpanel
    echo "Panel directory removed."
else
    echo "Panel directory kept."
fi

echo ""
echo -e "${GREEN}Uninstall complete.${NC}"
echo -e "${YELLOW}Note: System SSH users were NOT removed.${NC}"
echo -e "${YELLOW}To remove them manually: userdel USERNAME${NC}"
