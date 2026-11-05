<?php declare(strict_types = 0);

namespace Modules\MosicoyInterfaceErrors\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'interfaces' => [],
            'summary' => [
                'hosts' => 0,
                'interfaces' => 0,
                'with_errors' => 0,
                'with_discards' => 0,
                'total_errors' => 0,
                'total_discards' => 0
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
            error_log('Mosicoy Interface Errors: ' . $e->getMessage());
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _collect(array &$data): void {
        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) {
            $data['debug'][] = 'No host/group filter';
            return;
        }

        // Hosts
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
        catch (\Throwable $e) { $data['debug'][] = 'Host API: ' . $e->getMessage(); return; }
        if (!$hosts) { $data['debug'][] = 'No hosts'; return; }

        $max_hosts = (int) ($this->fields_values['max_hosts'] ?? 20);
        if (count($hosts) > $max_hosts) $hosts = array_slice($hosts, 0, $max_hosts, true);
        $data['summary']['hosts'] = count($hosts);

        // All items
        try {
            $db_items = API::Item()->get([
                'output' => ['itemid', 'name', 'lastvalue', 'value_type', 'hostid', 'units'],
                'hostids' => array_keys($hosts),
                'monitored' => true,
                'filter' => ['value_type' => [0, 3]],
                'preservekeys' => true
            ]);
        }
        catch (\Throwable $e) { $data['debug'][] = 'Item API: ' . $e->getMessage(); return; }
        if (!$db_items) { $data['debug'][] = 'No items'; return; }

        // Parse Interface items
        $parsed = [];
        foreach ($db_items as $item) {
            $name = trim((string) $item['name']);

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
        }

        if (!$parsed) { $data['debug'][] = 'No interface items'; return; }

        // Config
        $port_filter = trim((string) ($this->fields_values['port_filter'] ?? ''));
        $show_clean = (int) ($this->fields_values['show_clean'] ?? 0) === 1;
        $show_host = (int) ($this->fields_values['show_host'] ?? 1) === 1;
        $crit_thresh = (int) ($this->fields_values['crit_threshold'] ?? 10);
        $filter_mode = (int) ($this->fields_values['filter_mode'] ?? 0);
        $max_rows = (int) ($this->fields_values['max_rows'] ?? 30);

        // Metric names to look for (Cisco IOS + MikroTik templates)
        $error_metrics = [
            'In errors',
            'Out errors',
            'In CRC errors',
            'In frame errors',
            'In ignored errors',
            'In overrun errors',
            'In collisions',
            'Out collisions',
            'Late collisions'
        ];
        $discard_metrics = [
            'In discards',
            'Out discards'
        ];

        foreach ($parsed as $hid => $ports) {
            $host = $hosts[$hid] ?? null;
            if (!$host) continue;

            foreach ($ports as $port_name => $info) {
                if ($port_filter !== '') {
                    $match_against = $port_name . ($info['alias'] !== '' ? '(' . $info['alias'] . ')' : '');
                    if (!$this->_wildcardMatch($port_filter, $match_against)) continue;
                }

                // Sum errors
                $total_errors = 0;
                $total_discards = 0;
                $breakdown = [];

                foreach ($error_metrics as $mn) {
                    $v = $this->_getValue($info['metrics'], $mn);
                    if ($v !== null && $v > 0) {
                        $total_errors += $v;
                        $breakdown[] = ['metric' => $mn, 'value' => $v, 'type' => 'error'];
                    }
                }
                foreach ($discard_metrics as $mn) {
                    $v = $this->_getValue($info['metrics'], $mn);
                    if ($v !== null && $v > 0) {
                        $total_discards += $v;
                        $breakdown[] = ['metric' => $mn, 'value' => $v, 'type' => 'discard'];
                    }
                }

                // Status check
                $status = $this->_getValue($info['metrics'], 'Operational status');
                $is_up = (int) $status === 1;

                // Skip clean if configured
                $has_issues = ($total_errors > 0 || $total_discards > 0);
                if (!$has_issues && !$show_clean) continue;

                // Severity filter
                $severity = 'ok';
                if ($total_errors >= $crit_thresh) $severity = 'crit';
                elseif ($total_errors > 0) $severity = 'warn';
                elseif ($total_discards > 0) $severity = 'info';

                if ($filter_mode === 1 && $severity !== 'crit') continue;

                // Get itemid for click
                $first_item = null;
                foreach ($breakdown as $b) {
                    // Find the item
                    foreach ($info['metrics'] as $mn => $it) {
                        if ($mn === $b['metric']) {
                            $first_item = $it;
                            break 2;
                        }
                    }
                }

                $interfaces[] = [
                    'host' => (string) $host['name'],
                    'hostid' => (string) $hid,
                    'port' => $port_name,
                    'port_short' => $this->_shortName($port_name),
                    'alias' => $info['alias'],
                    'is_up' => $is_up,
                    'total_errors' => $total_errors,
                    'total_discards' => $total_discards,
                    'severity' => $severity,
                    'breakdown' => $breakdown,
                    'itemid' => $first_item ? (string) $first_item['itemid'] : ''
                ];

                $data['summary']['interfaces']++;
                if ($total_errors > 0) $data['summary']['with_errors']++;
                if ($total_discards > 0) $data['summary']['with_discards']++;
                $data['summary']['total_errors'] += $total_errors;
                $data['summary']['total_discards'] += $total_discards;
            }
        }

        // Sort: crit first, then by total errors desc
        usort($interfaces, function($a, $b) {
            $sev_order = ['crit' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
            $sa = $sev_order[$a['severity']] ?? 4;
            $sb = $sev_order[$b['severity']] ?? 4;
            if ($sa !== $sb) return $sa - $sb;
            $sumA = $a['total_errors'] + $a['total_discards'];
            $sumB = $b['total_errors'] + $b['total_discards'];
            return $sumB - $sumA;
        });

        // Limit
        if (count($interfaces) > $max_rows) {
            $interfaces = array_slice($interfaces, 0, $max_rows);
        }

        $data['interfaces'] = $interfaces;
        $data['debug'][] = 'Found ' . count($interfaces) . ' interfaces with issues';
    }

    private function _getValue(array $metrics, string $name): ?float {
        $item = $metrics[$name] ?? null;
        if (!$item) return null;
        $v = $item['lastvalue'] ?? null;
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        return (float) $v;
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
