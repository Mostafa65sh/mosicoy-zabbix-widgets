#!/bin/bash
# Mosicoy Zabbix Widgets — Quick Installer
# Author: Mostafa Shorgashti (m.shorgashti@gmail.com)

set -e

ZBX_MODULES="/usr/share/zabbix/modules"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   Mosicoy Zabbix Widgets Installer    ║${NC}"
echo -e "${GREEN}║   by Mostafa Shorgashti                ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════╝${NC}"
echo ""

# Check root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}❌ Please run as root (sudo ./install.sh)${NC}"
    exit 1
fi

# Check Zabbix modules dir
if [ ! -d "$ZBX_MODULES" ]; then
    echo -e "${RED}❌ Zabbix modules directory not found: $ZBX_MODULES${NC}"
    echo "   Edit ZBX_MODULES variable in this script if your path is different."
    exit 1
fi

echo -e "${YELLOW}📁 Target: $ZBX_MODULES${NC}"
echo ""

# Count widgets
WIDGETS=$(ls -d "$SCRIPT_DIR/modules"/mosicoy_* 2>/dev/null | wc -l)
echo -e "${YELLOW}🧩 Found $WIDGETS widgets to install${NC}"
echo ""

# Install each
INSTALLED=0
for widget_dir in "$SCRIPT_DIR/modules"/mosicoy_*; do
    [ -d "$widget_dir" ] || continue
    name=$(basename "$widget_dir")

    if [ -d "$ZBX_MODULES/$name" ]; then
        echo -e "${YELLOW}♻  Updating: $name${NC}"
        rm -rf "$ZBX_MODULES/$name"
    else
        echo -e "${GREEN}✨ Installing: $name${NC}"
    fi

    cp -r "$widget_dir" "$ZBX_MODULES/"
    INSTALLED=$((INSTALLED + 1))
done

echo ""
echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   ✅ Installed $INSTALLED widgets      ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════╝${NC}"
echo ""
echo -e "${YELLOW}📋 Next steps:${NC}"
echo "   1. Log in to Zabbix as Super Admin"
echo "   2. Go to: Administration → General → Modules"
echo "   3. Click 'Scan directory'"
echo "   4. Enable the Mosicoy widgets you want"
echo "   5. Go to a Dashboard → Edit → Add widget"
echo ""
echo -e "${GREEN}🎉 Done! Enjoy your modern dashboards!${NC}"
echo ""
echo -e "📧 m.shorgashti@gmail.com"
echo -e "🐙 https://github.com/Mostafa65sh"
