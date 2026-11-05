<?php declare(strict_types = 0);

namespace Modules\MosicoyTopTalkers\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'talkers' => [],
            'summary' => [
                'hosts' => 0,
                'interfaces' => 0,
                'total_rx' => 0,
                'total_tx' => 0
            ],
            'debug' => [],
            'fields_values' => $this->fields_values ?: [],
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        try {
            $this->_collect($data);
        }
        catch (\Throwable $e) {
            $data['debug'][] = 'Error: ' . $e->getMessage();
            error_log('Mosicoy Top Talkers error: ' . $e->getMessage());
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _collect(array &$data): void {
        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) {
            $data['debug'][] = 'No host/group filter set';
            return;
        }

        // 1) Get hosts
        $host_options = [
            'output' => ['hostid', 'name'],
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
        catch (\Throwable $e) {
            $data['debug'][] = 'Host API error: ' . $e->getMessage();
            return;
        }
        if (!$hosts) {
            $data['debug'][] = 'No hosts found';
            return;
        }

        // Limit max hosts
        $max_hosts = (int) ($this->fields_values['max_hosts'] ?? 20);
        if (count($hosts) > $max_hosts) {
            $hosts = array_slice($hosts, 0, $max_hosts, true);
        }
        $data['summary']['hosts'] = count($hosts);

        // 2) Get all numeric items for these hosts — filter in PHP
        try {
            $db_items = API::Item()->get([
                'output' => ['itemid', 'name', 'lastvalue', 'value_type', 'hostid', 'units'],
                'hostids' => array_keys($hosts),
                'monitored' => true,
                'filter' => ['value_type' => [0, 3]],
                'preservekeys' => true
            ]);
        }
        catch (\Throwable $e) {
            $data['debug'][] = 'Item API error: ' . $e->getMessage();
            return;
        }
        if (!$db_items) {
            $data['debug'][] = 'No items found';
            return;
        }

        // 3) Parse Interface items — group by host + port
        $parsed = [];
        $matched = 0;

        foreach ($db_items as $item) {
            $name = trim((string) $item['name']);

            // Pattern: Interface <name>(<alias>): <metric>
            if (preg_match('/^Interface\s+(.+?)\s*\(\s*([^)]*)\s*\)\s*:\s*(.+)$/i', $name, $m)) {
                $port = trim($m[1]);
                $alias = trim($m[2]);
                $metric = trim($m[3]);
            }
            elseif (preg_match('/^Interface\s+(.+?)\s*:\s*(.+)$/i', $name, $m)) {
                $port = trim($m[1]);
                $alias = '';
                $metric = trim($m[2]);
            }
            else {
                continue;
            }

            $hid = $item['hostid'];
            if (!isset($parsed[$hid])) $parsed[$hid] = [];
            if (!isset($parsed[$hid][$port])) {
                $parsed[$hid][$port] = ['alias' => $alias, 'metrics' => []];
            }
            $parsed[$hid][$port]['metrics'][$metric] = $item;
            $matched++;
        }

        if (!$parsed) {
            $data['debug'][] = 'No matching interface items';
            return;
        }

        // 4) Config
        $top_n = (int) ($this->fields_values['top_n'] ?? 10);
        $metric_mode = (int) ($this->fields_values['metric'] ?? 0);
        $port_filter = trim((string) ($this->fields_values['port_filter'] ?? ''));
        $hide_down = (int) ($this->fields_values['hide_down'] ?? 1) === 1;
        $show_utilization = (int) ($this->fields_values['show_utilization'] ?? 1) === 1;
        $show_host = (int) ($this->fields_values['show_host'] ?? 1) === 1;

        // 5) Build talker list
        $talkers = [];
        foreach ($parsed as $hid => $ports) {
            $host = $hosts[$hid] ?? null;
            if (!$host) continue;

            foreach ($ports as $port_name => $info) {
                // Port filter
                if ($port_filter !== '') {
                    $match_against = $port_name . ($info['alias'] !== '' ? '(' . $info['alias'] . ')' : '');
                    if (!$this->_wildcardMatch($port_filter, $match_against)) continue;
                }

                // Extract metrics
                $status_val = $this->_getValue($info['metrics'], 'Operational status');
                $rx = $this->_getValue($info['metrics'], 'Bits received');
                $tx = $this->_getValue($info['metrics'], 'Bits sent');
                $speed = $this->_getSpeed($info['metrics'], $port_name);

                // Status
                $is_up = (int) $status_val === 1;
                if ($status_val === null) {
                    // Infer from rx/tx presence
                    $is_up = ($rx !== null || $tx !== null);
                }
                if (!$is_up && $hide_down) continue;

                // Skip interfaces with no data at all
                if ($rx === null && $tx === null) continue;

                $rx = $rx ?? 0;
                $tx = $tx ?? 0;

                // Sort value
                switch ($metric_mode) {
                    case 1: $sort_val = $rx + $tx; break;
                    case 2: $sort_val = $rx; break;
                    case 3: $sort_val = $tx; break;
                    default: $sort_val = max($rx, $tx);
                }

                // Utilization
                $utilization = 0;
                if ($speed > 0) {
                    $utilization = min(1.0, max($rx, $tx) / $speed);
                }

                // Extract itemid for click action
                $rx_item = $info['metrics']['Bits received'] ?? null;
                $tx_item = $info['metrics']['Bits sent'] ?? null;
                $itemid = $rx_item ? (string) $rx_item['itemid'] : ($tx_item ? (string) $tx_item['itemid'] : '');

                $talkers[] = [
                    'host' => (string) $host['name'],
                    'hostid' => (string) $hid,
                    'port' => $port_name,
                    'port_short' => $this->_shortName($port_name),
                    'alias' => $info['alias'],
                    'rx' => (float) $rx,
                    'tx' => (float) $tx,
                    'speed' => (float) $speed,
                    'utilization' => $utilization,
                    'is_up' => $is_up,
                    'sort_val' => $sort_val,
                    'itemid' => $itemid
                ];

                $data['summary']['interfaces']++;
                $data['summary']['total_rx'] += $rx;
                $data['summary']['total_tx'] += $tx;
            }
        }

        if (!$talkers) {
            $data['debug'][] = 'No talkers after filtering';
            return;
        }

        // 6) Sort by sort_val DESC
        usort($talkers, function($a, $b) {
            return $b['sort_val'] <=> $a['sort_val'];
        });

        // 7) Trim to top N
        $talkers = array_slice($talkers, 0, $top_n);

        // 8) Compute max for progress bar (use top 1's value)
        $max_val = $talkers[0]['sort_val'] ?? 1;
        if ($max_val <= 0) $max_val = 1;

        foreach ($talkers as &$t) {
            $t['relative'] = $t['sort_val'] / $max_val;
        }
        unset($t);

        $data['talkers'] = $talkers;
        $data['debug'][] = 'Talkers: ' . count($talkers) . ' of ' . $data['summary']['interfaces'] . ' interfaces';
    }

    private function _getValue(array $metrics, string $name): ?float {
        $item = $metrics[$name] ?? null;
        if (!$item) return null;
        $v = $item['lastvalue'] ?? null;
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        return (float) $v;
    }

    private function _getSpeed(array $metrics, string $port_name): float {
        $item = $metrics['Speed'] ?? null;
        if ($item && $item['lastvalue'] !== null && is_numeric($item['lastvalue'])) {
            $speed = (float) $item['lastvalue'];
            $u = strtolower((string) ($item['units'] ?? ''));
            if ($u === 'mbps' || $u === 'mb/s' || $u === 'mbit/s') $speed *= 1_000_000;
            elseif ($u === 'gbps' || $u === 'gb/s' || $u === 'gbit/s') $speed *= 1_000_000_000;
            elseif ($speed > 0 && $speed < 100000) $speed *= 1_000_000; // assume Mbps
            return $speed;
        }
        return $this->_inferSpeed($port_name);
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
            'GigabitEthernet'      => 'Gi',
            'TenGigabitEthernet'   => 'Te',
            'TwentyFiveGigE'       => 'Twe',
            'FortyGigabitEthernet' => 'Fo',
            'HundredGigE'          => 'Hu',
            'FastEthernet'         => 'Fa',
            'Ethernet'             => 'Et',
            'Port-channel'         => 'Po',
            'Loopback'             => 'Lo',
            'MgmtEth'              => 'Mg',
            'Bundle-Ether'         => 'BE',
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
