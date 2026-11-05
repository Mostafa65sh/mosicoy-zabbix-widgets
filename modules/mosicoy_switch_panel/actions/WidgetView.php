<?php declare(strict_types = 0);

namespace Modules\MosicoySwitchPanel\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'hosts' => [],
            'summary' => ['switches' => 0, 'ports' => 0, 'up' => 0, 'down' => 0, 'errors' => 0],
            'sparkline_debug' => [],
            'fields_values' => $this->fields_values ?: [],
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        try {
            $this->_collect($data);
        }
        catch (\Throwable $e) {
            $data['sparkline_debug'][] = 'Error: ' . $e->getMessage();
            error_log('Mosicoy Switch Panel error: ' . $e->getMessage());
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _collect(array &$data): void {
        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) return;

        $host_options = [
            'output' => ['hostid', 'name', 'host'],
            'preservekeys' => true,
            'monitored' => true,
            'sortfield' => 'name'
        ];
        if ($this->isTemplateDashboard()) {
            $host_options['hostids'] = $this->fields_values['override_hostid'];
        }
        else {
            if (!empty($this->fields_values['groupids'])) $host_options['groupids'] = $this->fields_values['groupids'];
            if (!empty($this->fields_values['hostids'])) $host_options['hostids'] = $this->fields_values['hostids'];
        }

        try { $hosts = API::Host()->get($host_options); }
        catch (\Throwable $e) { return; }
        if (!$hosts) return;

        $max_hosts = (int) ($this->fields_values['max_hosts'] ?? 5);
        if (count($hosts) > $max_hosts) $hosts = array_slice($hosts, 0, $max_hosts, true);

        try {
            $db_items = API::Item()->get([
                'output' => ['itemid', 'name', 'lastvalue', 'value_type', 'hostid', 'units'],
                'hostids' => array_keys($hosts),
                'monitored' => true,
                'filter' => ['value_type' => [0, 3]],
                'preservekeys' => true
            ]);
        }
        catch (\Throwable $e) { return; }
        if (!$db_items) return;

        $parsed = [];
        foreach ($db_items as $item) {
            $name = trim((string) $item['name']);
            if (preg_match('/^Interface\s+(.+?)\s*\(\s*([^)]*)\s*\)\s*:\s*(.+)$/i', $name, $m)) {
                $port = trim($m[1]); $alias = trim($m[2]); $metric = trim($m[3]);
            }
            elseif (preg_match('/^Interface\s+(.+?)\s*:\s*(.+)$/i', $name, $m)) {
                $port = trim($m[1]); $alias = ''; $metric = trim($m[2]);
            }
            else { continue; }

            $hid = $item['hostid'];
            if (!isset($parsed[$hid])) $parsed[$hid] = [];
            if (!isset($parsed[$hid][$port])) {
                $parsed[$hid][$port] = ['alias' => $alias, 'metrics' => []];
            }
            $parsed[$hid][$port]['metrics'][$metric] = $item;
        }

        if (!$parsed) return;

        $warn = (int) ($this->fields_values['warn_threshold'] ?? 60);
        $crit = (int) ($this->fields_values['crit_threshold'] ?? 80);
        $show_down = (int) ($this->fields_values['show_down'] ?? 1) === 1;
        $show_mgmt = (int) ($this->fields_values['show_management'] ?? 0) === 1;
        $show_sparklines = (int) ($this->fields_values['show_sparklines'] ?? 1) === 1;
        $port_filter = trim((string) ($this->fields_values['port_filter'] ?? ''));

        $error_metric_names = [
            'In errors', 'Out errors', 'In discards', 'Out discards',
            'In CRC errors', 'In collisions', 'Late collisions', 'In frame errors'
        ];

        foreach ($hosts as $hid => $host) {
            if (!isset($parsed[$hid])) continue;

            $ports = [];
            foreach ($parsed[$hid] as $port_name => $info) {
                if ($port_filter !== '') {
                    $match_against = $port_name . ($info['alias'] !== '' ? '(' . $info['alias'] . ')' : '');
                    if (!$this->_wildcardMatch($port_filter, $match_against)) continue;
                }

                $status_raw = $this->_getMetricValue($info['metrics'], 'Operational status');
                $rx = $this->_getMetricValue($info['metrics'], 'Bits received');
                $tx = $this->_getMetricValue($info['metrics'], 'Bits sent');
                $speed_item = $this->_getMetricItem($info['metrics'], 'Speed');

                $status = ((int) $status_raw === 1) ? 'up' : 'down';
                if ($status_raw === null) {
                    $status = ($rx !== null || $tx !== null) ? 'up' : 'down';
                }

                if ($status === 'down' && !$show_down) continue;

                $speed_bps = null;
                if ($speed_item && $speed_item['lastvalue'] !== null) {
                    $speed_bps = (float) $speed_item['lastvalue'];
                    $u = strtolower((string) ($speed_item['units'] ?? ''));
                    if ($u === 'mbps' || $u === 'mb/s' || $u === 'mbit/s') $speed_bps *= 1_000_000;
                    elseif ($u === 'gbps' || $u === 'gb/s' || $u === 'gbit/s') $speed_bps *= 1_000_000_000;
                    elseif ($speed_bps > 0 && $speed_bps < 100000) $speed_bps *= 1_000_000;
                }
                if ($speed_bps === null) $speed_bps = $this->_inferSpeed($port_name);

                $category = $this->_classifyPort($port_name, $speed_bps);
                if ($category === 'management' && !$show_mgmt) continue;

                $utilization = 0;
                $severity = 'ok';
                if ($status === 'up' && $speed_bps > 0) {
                    $max_rate = max((float) ($rx ?? 0), (float) ($tx ?? 0));
                    $utilization = min(1.0, $max_rate / $speed_bps);
                    $pct = $utilization * 100;
                    if ($pct >= $crit) $severity = 'crit';
                    elseif ($pct >= $warn) $severity = 'warn';
                } elseif ($status === 'down') {
                    $severity = 'down';
                }

                // ═══ Error metrics ═══
                $total_errors = 0;
                $total_discards = 0;
                $error_breakdown = [];
                foreach ($error_metric_names as $mn) {
                    $v = $this->_getMetricValue($info['metrics'], $mn);
                    if ($v !== null && $v > 0) {
                        $error_breakdown[$mn] = $v;
                        if (stripos($mn, 'discard') !== false) $total_discards += $v;
                        else $total_errors += $v;
                    }
                }
                $has_errors = ($total_errors + $total_discards) > 0;

                // ═══ Direction indicator ═══
                $direction = 'idle';
                $rx_v = (float) ($rx ?? 0);
                $tx_v = (float) ($tx ?? 0);
                if ($rx_v > $tx_v * 1.5) $direction = 'rx';
                elseif ($tx_v > $rx_v * 1.5) $direction = 'tx';
                elseif ($rx_v > 0 || $tx_v > 0) $direction = 'balanced';

                $rx_item = $info['metrics']['Bits received'] ?? null;
                $tx_item = $info['metrics']['Bits sent'] ?? null;
                $rx_itemid = $rx_item ? (string) $rx_item['itemid'] : '';
                $tx_itemid = $tx_item ? (string) $tx_item['itemid'] : '';

                $ports[] = [
                    'name' => $port_name,
                    'short_name' => $this->_shortName($port_name),
                    'alias' => $info['alias'],
                    'status' => $status,
                    'status_raw' => (int) $status_raw,
                    'rx' => $rx !== null ? (float) $rx : null,
                    'tx' => $tx !== null ? (float) $tx : null,
                    'speed' => $speed_bps,
                    'speed_label' => $this->_speedLabel((float) $speed_bps),
                    'category' => $category,
                    'utilization' => $utilization,
                    'util_pct' => round($utilization * 100, 1),
                    'severity' => $severity,
                    'direction' => $direction,
                    'errors' => $total_errors,
                    'discards' => $total_discards,
                    'has_errors' => $has_errors,
                    'error_breakdown' => $error_breakdown,
                    'itemid' => $rx_itemid,
                    'rx_itemid' => $rx_itemid,
                    'tx_itemid' => $tx_itemid,
                    'sparkline' => []
                ];

                $data['summary']['ports']++;
                if ($status === 'up') $data['summary']['up']++;
                else $data['summary']['down']++;
                if ($has_errors) $data['summary']['errors']++;
            }

            if (!$ports) continue;

            usort($ports, function($a, $b) {
                return strnatcasecmp($a['name'], $b['name']);
            });

            $up_count = count(array_filter($ports, function($p) { return $p['status'] === 'up'; }));

            $data['hosts'][] = [
                'hostid' => (string) $hid,
                'name' => (string) $host['name'],
                'ports' => $ports,
                'summary' => [
                    'total' => count($ports),
                    'up' => $up_count,
                    'down' => count($ports) - $up_count
                ]
            ];

            $data['summary']['switches']++;
        }

        if ($show_sparklines) {
            $this->_attachSparklines($data, $show_sparklines);
        }
    }

    private function _attachSparklines(array &$data, bool $enabled): void {
        if (!$enabled) return;

        $rx_ids = [];
        $tx_ids = [];
        foreach ($data['hosts'] as $host) {
            foreach ($host['ports'] as $port) {
                if ($port['status'] !== 'up') continue;
                if (!empty($port['rx_itemid'])) $rx_ids[] = $port['rx_itemid'];
                if (!empty($port['tx_itemid'])) $tx_ids[] = $port['tx_itemid'];
            }
        }
        $rx_ids = array_values(array_unique($rx_ids));
        $tx_ids = array_values(array_unique($tx_ids));
        if (!$rx_ids && !$tx_ids) return;

        $now = time();
        $time_from = $now - 3600;

        try {
            $items_info = API::Item()->get([
                'output' => ['itemid', 'value_type'],
                'itemids' => array_merge($rx_ids, $tx_ids),
                'preservekeys' => true
            ]);
        }
        catch (\Throwable $e) { $items_info = []; }

        $by_type = ['float' => [], 'uint' => []];
        foreach ($items_info as $iid => $ii) {
            if ((int) $ii['value_type'] === 0) $by_type['float'][] = $iid;
            elseif ((int) $ii['value_type'] === 3) $by_type['uint'][] = $iid;
        }

        $hist_data = [];
        foreach (['float' => 0, 'uint' => 3] as $type_name => $type_val) {
            $ids = $by_type[$type_name];
            if (!$ids) continue;
            try {
                $records = API::History()->get([
                    'output' => ['itemid', 'clock', 'value'],
                    'itemids' => $ids,
                    'history' => $type_val,
                    'time_from' => $time_from,
                    'time_till' => $now,
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit' => count($ids) * 40
                ]);
                foreach ($records as $r) {
                    $hist_data[$r['itemid']][] = (float) $r['value'];
                }
            }
            catch (\Throwable $e) { /* ignore */ }
        }

        foreach ($data['hosts'] as &$host) {
            foreach ($host['ports'] as &$port) {
                if ($port['status'] !== 'up') continue;
                $rx_data = $hist_data[$port['rx_itemid']] ?? [];
                $tx_data = $hist_data[$port['tx_itemid']] ?? [];
                $merged = [];
                if (!empty($rx_data) && !empty($tx_data)) {
                    $maxLen = max(count($rx_data), count($tx_data));
                    for ($i = 0; $i < $maxLen; $i++) {
                        $merged[] = max($rx_data[$i] ?? 0, $tx_data[$i] ?? 0);
                    }
                } elseif (!empty($rx_data)) $merged = $rx_data;
                elseif (!empty($tx_data)) $merged = $tx_data;

                if (count($merged) > 20) {
                    $step = count($merged) / 20;
                    $down = [];
                    for ($i = 0; $i < 20; $i++) {
                        $down[] = $merged[(int) ($i * $step)];
                    }
                    $merged = $down;
                }
                $port['sparkline'] = $merged;
            }
            unset($port);
        }
        unset($host);
    }

    private function _speedLabel(float $bps): string {
        if ($bps >= 100_000_000_000) return '100G';
        if ($bps >= 40_000_000_000) return '40G';
        if ($bps >= 25_000_000_000) return '25G';
        if ($bps >= 10_000_000_000) return '10G';
        if ($bps >= 1_000_000_000) return '1G';
        if ($bps >= 100_000_000) return '100M';
        if ($bps >= 10_000_000) return '10M';
        if ($bps > 0) return 'Link';
        return '';
    }

    private function _getMetricValue(array $metrics, string $name) {
        $item = $metrics[$name] ?? null;
        if (!$item) return null;
        $v = $item['lastvalue'] ?? null;
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? (float) $v : null;
    }

    private function _getMetricItem(array $metrics, string $name): ?array {
        return $metrics[$name] ?? null;
    }

    private function _classifyPort(string $name, ?float $speed_bps): string {
        if (preg_match('/^(vlan|loopback|mgmt|management|bvi|tunnel|port-channel|po\d|bundle-ether|lo\d|vl\d|null|nu\d)/i', $name)) return 'management';
        if ($speed_bps !== null && $speed_bps >= 10_000_000_000) return 'uplink';
        if (preg_match('/^(te|ten|twe|twentyfivegig|fortygig|fo|hu|hundredgig|xg|25g|40g|100g|sfp)/i', $name)) return 'sfp';
        return 'copper';
    }

    private function _inferSpeed(string $name): float {
        $n = strtolower($name);
        if (preg_match('/^(hu|hundredgig|100g)/', $n)) return 100_000_000_000;
        if (preg_match('/^(fo|fortygig|40g)/', $n))    return 40_000_000_000;
        if (preg_match('/^(twe|twentyfivegig|25g)/', $n)) return 25_000_000_000;
        if (preg_match('/^(te|ten|10g)/', $n))         return 10_000_000_000;
        if (preg_match('/^(gi|gigabitethernet|ge)/', $n)) return 1_000_000_000;
        if (preg_match('/^(fa|fastethernet)/', $n))    return 100_000_000;
        if (preg_match('/^(et|ethernet)/', $n))        return 10_000_000;
        return 1_000_000_000;
    }

    private function _shortName(string $name): string {
        $map = [
            'GigabitEthernet' => 'Gi', 'TenGigabitEthernet' => 'Te',
            'TwentyFiveGigE' => 'Twe', 'FortyGigabitEthernet' => 'Fo',
            'HundredGigE' => 'Hu', 'FastEthernet' => 'Fa',
            'Ethernet' => 'Et', 'Port-channel' => 'Po',
            'Loopback' => 'Lo', 'MgmtEth' => 'Mg', 'Bundle-Ether' => 'BE',
        ];
        foreach ($map as $full => $short) {
            if (strncasecmp($name, $full, strlen($full)) === 0) {
                return $short . substr($name, strlen($full));
            }
        }
        return $name;
    }

    private function _wildcardMatch(string $pattern, string $subject): bool {
        $parts = preg_split('/([*?])/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            if ($part === '*') $regex .= '.*';
            elseif ($part === '?') $regex .= '.';
            else $regex .= preg_quote($part, '/');
        }
        return (bool) preg_match('/^' . $regex . '$/i', $subject);
    }
}
