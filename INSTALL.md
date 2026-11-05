# Installation Guide

## Requirements

- Zabbix 7.0 or newer
- PHP 8.0+
- Web server (Apache/Nginx) with modules directory writable
- Root or sudo access to the server

## Quick Install (recommended)

1. Clone or download the repository:

    git clone https://github.com/Mostafa65sh/mosicoy-zabbix-widgets.git
    cd mosicoy-zabbix-widgets

2. Run the installer as root:

    sudo ./install.sh

## Manual Install

Copy all widgets to Zabbix modules directory:

    cp -r modules/mosicoy_* /usr/share/zabbix/modules/
    chown -R www-data:www-data /usr/share/zabbix/modules/

## Activate in Zabbix

1. Log in as **Super Admin**
2. Go to **Administration -> General -> Modules**
3. Click **Scan directory** (top-right button)
4. Find each **Mosicoy** module in the list
5. Click on it and press **Enable**

## Add Widget to Dashboard

1. Go to **Dashboards**
2. Open a dashboard or create new
3. Click **Edit dashboard**
4. Click **Add widget**
5. Search for **Mosicoy** in the widget list
6. Configure hosts/items and Save

## Troubleshooting

### Widgets don't appear after Scan directory

Check file permissions:

    chown -R www-data:www-data /usr/share/zabbix/modules/
    ls -la /usr/share/zabbix/modules/mosicoy_*

### Error: "Failed to update widget properties"

1. Make sure the widget module is **Enabled**
2. Clear Zabbix cache:

    rm -rf /var/cache/zabbix/*
    systemctl restart apache2

### Widget shows blank

1. Open browser console (F12) for JavaScript errors
2. Refresh with Ctrl+Shift+R
3. Check that hosts and items are selected in widget settings

## Uninstall

Remove all Mosicoy modules:

    rm -rf /usr/share/zabbix/modules/mosicoy_*
    rm -rf /var/cache/zabbix/*
    systemctl restart apache2

## Support

- GitHub: https://github.com/Mostafa65sh
- Email: m.shorgashti@gmail.com
- LinkedIn: https://linkedin.com/in/mostafashorgashti
- Website: https://2ping.ir | https://livekadeh.com
