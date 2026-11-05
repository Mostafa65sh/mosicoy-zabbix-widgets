<?php declare(strict_types = 0);

namespace Modules\MosicoyRoutingMonitor\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    const BGP_STATES = [
        1 => ['name' => 'Idle',        'severity' => 'crit'],
        2 => ['name' => 'Connect',     'severity' => 'crit'],
        3 => ['name' => 'Active',      'severity' => 'crit'],
        4 => ['name' => 'OpenSent',    'severity' => 'warn'],
        5 => ['name' => 'OpenConfirm', 'severity' => 'warn'],
        6 => ['name' => 'Established', 'severity' => 'ok']
    ];

    const OSPF_STATES = [
        1 => ['name' => 'Down',     'severity' => 'crit'],
        2 => ['name' => 'Attempt',  'severity' => 'crit'],
        3 => ['name' => 'Init',     'severity' => 'crit'],
        4 => ['name' => 'TwoWay',   'severity' => 'warn'],
        5 => ['name' => 'ExStart',  'severity' => 'warn'],
        6 => ['name' => 'Exchange', 'severity' => 'warn'],
        7 => ['name' => 'Loading',  'severity' => 'warn'],
        8 => ['name' => 'Full',     'severity' => 'ok']
    ];

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'peers' => [],
            'summary' => ['hosts' => 0, 'peers' => 0, 'established' => 0, 'problematic' => 0],
            'debug' => [],
            'fields_values' => $this->fields_values ?: [],
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        try {
            $this->_collect($data);
        }
        catch (\Throwable $e) {
            $data['debug'][] = 'Error: ' . $e->getMessage();
            error_log('Mosicoy Routing Monitor: ' . $e->getMessage());
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _collect(array &$data): void {
        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) {
            $data['debug'][] = 'No host filter';
            return;
        }

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
        if (!$hosts) { $data['debug'][] = 'No hosts found'; return; }

        $max_hosts = (int) ($this->fields_values['max_hosts'] ?? 10);
        if (count($hosts) > $max_hosts) $hosts = array_slice($hosts, 0, $max_hosts, true);
        $data['summary']['hosts'] = count($hosts);

        // Fetch items — include text types for state items
        try {
            $db_items = API::Item()->get([
                'output' => ['itemid', 'name', 'lastvalue', 'value_type', 'hostid', 'units'],
                'hostids' => array_keys($hosts),
                'monitored' => true,
                'filter' => ['value_type' => [0, 1, 3, 4]], // numeric + text
                'preservekeys' => true
            ]);
        }
        catch (\Throwable $e) { $data['debug'][] = 'Item API: ' . $e->getMessage(); return; }
        if (!$db_items) { $data['debug'][] = 'No items'; return; }

        $data['debug'][] = 'Total items: ' . count($db_items);

        // ═══ ENHANCED DEBUG — show what items actually exist ═══
        $bgp_samples = [];
        $ospf_samples = [];
        $neighbor_samples = [];
        $route_samples = [];
        $all_names_sample = [];

        foreach ($db_items as $it) {
            $n = (string) $it['name'];
            $nl = strtolower($n);
            if (stripos($n, 'bgp') !== false && count($bgp_samples) < 5) $bgp_samples[] = $n;
            if (stripos($n, 'ospf') !== false && count($ospf_samples) < 5) $ospf_samples[] = $n;
            if (stripos($n, 'neighbor') !== false && count($neighbor_samples) < 5) $neighbor_samples[] = $n;
            if ((stripos($n, 'route') !== false || stripos($n, 'adjacen') !== false) && count($route_samples) < 5) {
                $route_samples[] = $n;
            }
            if (count($all_names_sample) < 10 && preg_match('/state|status|peer|neighbor/i', $n)) {
                $all_names_sample[] = $n;
            }
        }

        if ($bgp_samples)    $data['debug'][] = '🔵 BGP items: ' . implode(' || ', $bgp_samples);
        if ($ospf_samples)   $data['debug'][] = '🟣 OSPF items: ' . implode(' || ', $ospf_samples);
        if ($neighbor_samples) $data['debug'][] = '🤝 Neighbor items: ' . implode(' || ', $neighbor_samples);
        if ($route_samples)  $data['debug'][] = '🛣️ Route items: ' . implode(' || ', $route_samples);
        if ($all_names_sample) $data['debug'][] = '📋 State/Peer samples: ' . implode(' || ', $all_names_sample);

        if (!$bgp_samples && !$ospf_samples && !$neighbor_samples && !$route_samples) {
            $data['debug'][] = '❌ NO OSPF/BGP/Neighbor/Route items found in this template!';
            $data['debug'][] = '👉 Hint: Check "Data collection → Hosts → Items → filter by \"ospf\""';
        }

        // Parse items — try multiple regex patterns
        $parsed = [];
        $matched = 0;

        foreach ($db_items as $item) {
            $name = trim((string) $item['name']);
            if ($name === '') continue;

            // Skip items that don't look like BGP/OSPF peers
            if (stripos($name, 'bgp') === false && stripos($name, 'ospf') === false) continue;

            $protocol = null;
            $peer = null;
            $alias = '';
            $metric = null;

            // ═══ FLEXIBLE PATTERNS — try many variants ═══
            $protocol = null;
            $peer = null;
            $alias = '';
            $metric = null;

            // Detect protocol and peer from various patterns
            $protocol_detect = null;
            if (preg_match('/\b(BGP|bgp)\b/', $name)) $protocol_detect = 'BGP';
            elseif (preg_match('/\b(OSPF|ospf)\b/', $name)) $protocol_detect = 'OSPF';

            if ($protocol_detect === null) continue;

            // Pattern A: "OSPF Neighbor 1.1.1.1(): State" or "BGP Peer 10.0.0.1(): State"
            if (preg_match('/(?:neighbor|peer)\s+([^\s(:]+(?:\.[0-9]+){0,3})\s*\(\s*([^)]*)\s*\)\s*:\s*(.+)$/i', $name, $m)) {
                $peer = trim($m[1]);
                $alias = trim($m[2]);
                $metric = trim($m[3]);
            }
            // Pattern B: "OSPF Neighbor 1.1.1.1: State"
            elseif (preg_match('/(?:neighbor|peer)\s+([^\s:]+)\s*:\s*(.+)$/i', $name, $m)) {
                $peer = trim($m[1]);
                $metric = trim($m[2]);
            }
            // Pattern C: "OSPF Neighbor State 1.1.1.1"
            elseif (preg_match('/(?:neighbor|peer)\s+(.+?)\s+state\s+(\S+)$/i', $name, $m)) {
                $metric = 'State';
                $peer = trim($m[2]);
            }
            // Pattern D: "1.1.1.1 (Neighbor): State"
            elseif (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s*(?:\(([^)]*)\))?\s*:\s*(.+)$/i', $name, $m)) {
                $peer = $m[1];
                $alias = trim($m[2] ?? '');
                $metric = trim($m[3]);
            }
            // Pattern E: "Peer State for 10.0.0.1"
            elseif (preg_match('/state\s+(?:for\s+)?(\d+\.\d+\.\d+\.\d+)$/i', $name, $m)) {
                $peer = $m[1];
                $metric = 'State';
            }
            else {
                continue;
            }

            $protocol = $protocol_detect;
            if ($peer === null || $peer === '') continue;

            $metric = trim($metric);
            $hid = $item['hostid'];

            if (!isset($parsed[$hid])) $parsed[$hid] = [];
            $peer_key = $protocol . '::' . $peer;
            if (!isset($parsed[$hid][$peer_key])) {
                $parsed[$hid][$peer_key] = [
                    'protocol' => $protocol,
                    'peer' => $peer,
                    'alias' => $alias,
                    'metrics' => []
                ];
            }
            $parsed[$hid][$peer_key]['metrics'][$metric] = $item;
            $matched++;
        }

        $data['debug'][] = 'Matched peer items: ' . $matched;

        if (!$parsed) {
            $data['debug'][] = 'No BGP/OSPF peer items parsed';
            return;
        }

        // Config
        $protocol_filter = (int) ($this->fields_values['proto_filter'] ?? 0);
        $hide_est = (int) ($this->fields_values['hide_established'] ?? 0) === 1;
        $show_host = (int) ($this->fields_values['show_host'] ?? 1) === 1;
        $max_peers = (int) ($this->fields_values['max_peers'] ?? 50);

        foreach ($parsed as $hid => $peers) {
            $host = $hosts[$hid] ?? null;
            if (!$host) continue;

            foreach ($peers as $pkey => $peer_data) {
                $protocol = $peer_data['protocol'];

                // Protocol filter
                if ($protocol_filter === 1 && $protocol !== 'BGP') continue;
                if ($protocol_filter === 2 && $protocol !== 'OSPF') continue;

                // Extract state
                $state_val = $this->_getValue($peer_data['metrics'], 'State');
                if ($state_val === null) $state_val = $this->_getValue($peer_data['metrics'], 'FSM State');
                if ($state_val === null) $state_val = $this->_getValue($peer_data['metrics'], 'FSM state');
                if ($state_val === null) $state_val = $this->_getValue($peer_data['metrics'], 'Operational status');
                if ($state_val === null) $state_val = $this->_getValue($peer_data['metrics'], 'Status');
                if ($state_val === null) $state_val = $this->_getValue($peer_data['metrics'], 'bgpPeerState');

                $state_info = $this->_parseState($state_val, $protocol);
                $severity = $state_info['severity'];

                if ($hide_est && $severity === 'ok') continue;

                // Extra info
                $uptime = $this->_getValue($peer_data['metrics'], 'Uptime');
                if ($uptime === null) $uptime = $this->_getValue($peer_data['metrics'], 'Peer Uptime');

                $remote_as = $this->_getValue($peer_data['metrics'], 'Remote AS');
                if ($remote_as === null) $remote_as = $this->_getValue($peer_data['metrics'], 'Peer Remote AS');
                if ($remote_as === null) $remote_as = $this->_getValue($peer_data['metrics'], 'bgpPeerRemoteAs');

                $prefixes = $this->_getValue($peer_data['metrics'], 'Accepted prefixes');
                if ($prefixes === null) $prefixes = $this->_getValue($peer_data['metrics'], 'Prefixes received');
                if ($prefixes === null) $prefixes = $this->_getValue($peer_data['metrics'], 'bgpPeerInUpdates');

                // Item ID for click
                $first_item = reset($peer_data['metrics']);

                // Extended metrics
                $msg_rcvd = $this->_getValue($peer_data['metrics'], 'Received messages');
                if ($msg_rcvd === null) $msg_rcvd = $this->_getValue($peer_data['metrics'], 'bgpPeerInTotalMessages');

                $msg_sent = $this->_getValue($peer_data['metrics'], 'Sent messages');
                if ($msg_sent === null) $msg_sent = $this->_getValue($peer_data['metrics'], 'bgpPeerOutTotalMessages');

                $data['peers'][] = [
                    'host' => (string) $host['name'],
                    'hostid' => (string) $hid,
                    'protocol' => $protocol,
                    'peer' => $peer_data['peer'],
                    'alias' => $peer_data['alias'],
                    'state_raw' => $state_val,
                    'state_name' => $state_info['name'],
                    'severity' => $severity,
                    'uptime_sec' => is_numeric($uptime) ? (int) $uptime : null,
                    'remote_as' => is_numeric($remote_as) ? (int) $remote_as : null,
                    'prefixes' => is_numeric($prefixes) ? (int) $prefixes : null,
                    'msg_rcvd' => is_numeric($msg_rcvd) ? (int) $msg_rcvd : null,
                    'msg_sent' => is_numeric($msg_sent) ? (int) $msg_sent : null,
                    'itemid' => $first_item ? (string) $first_item['itemid'] : ''
                ];

                $data['summary']['peers']++;
                if ($severity === 'ok') $data['summary']['established']++;
                else $data['summary']['problematic']++;
            }
        }

        // Count by protocol
        $data['summary']['bgp'] = 0;
        $data['summary']['ospf'] = 0;
        foreach ($data['peers'] as $p) {
            if ($p['protocol'] === 'BGP') $data['summary']['bgp']++;
            elseif ($p['protocol'] === 'OSPF') $data['summary']['ospf']++;
        }

        // Sort: crit first, then warn, then ok
        usort($data['peers'], function($a, $b) {
            $order = ['crit' => 0, 'warn' => 1, 'ok' => 2];
            $sa = $order[$a['severity']] ?? 3;
            $sb = $order[$b['severity']] ?? 3;
            if ($sa !== $sb) return $sa - $sb;
            return strcmp($a['peer'], $b['peer']);
        });

        if (count($data['peers']) > $max_peers) {
            $data['peers'] = array_slice($data['peers'], 0, $max_peers);
        }

        $data['debug'][] = 'Final: ' . count($data['peers']) . ' peers';
    }

    private function _parseState($raw, string $protocol): array {
        if ($raw === null) {
            return ['name' => 'Unknown', 'severity' => 'none'];
        }

        // Numeric state
        if (is_numeric($raw)) {
            $num = (int) $raw;
            $map = $protocol === 'BGP' ? self::BGP_STATES : self::OSPF_STATES;
            if (isset($map[$num])) {
                return $map[$num];
            }
            return ['name' => 'State ' . $num, 'severity' => 'warn'];
        }

        // Text state
        $str = strtolower(trim((string) $raw));

        if ($protocol === 'BGP') {
            if (strpos($str, 'establish') !== false) return ['name' => 'Established', 'severity' => 'ok'];
            if (strpos($str, 'idle') !== false) return ['name' => 'Idle', 'severity' => 'crit'];
            if (strpos($str, 'connect') !== false) return ['name' => 'Connect', 'severity' => 'crit'];
            if (strpos($str, 'active') !== false) return ['name' => 'Active', 'severity' => 'crit'];
            if (strpos($str, 'opensent') !== false) return ['name' => 'OpenSent', 'severity' => 'warn'];
            if (strpos($str, 'openconfirm') !== false) return ['name' => 'OpenConfirm', 'severity' => 'warn'];
        } else {
            if (strpos($str, 'full') !== false) return ['name' => 'Full', 'severity' => 'ok'];
            if (strpos($str, 'down') !== false) return ['name' => 'Down', 'severity' => 'crit'];
            if (strpos($str, 'attempt') !== false) return ['name' => 'Attempt', 'severity' => 'crit'];
            if (strpos($str, 'init') !== false) return ['name' => 'Init', 'severity' => 'crit'];
            if (strpos($str, '2way') !== false || strpos($str, 'twoway') !== false) return ['name' => 'TwoWay', 'severity' => 'warn'];
            if (strpos($str, 'exstart') !== false) return ['name' => 'ExStart', 'severity' => 'warn'];
            if (strpos($str, 'exchange') !== false) return ['name' => 'Exchange', 'severity' => 'warn'];
            if (strpos($str, 'loading') !== false) return ['name' => 'Loading', 'severity' => 'warn'];
        }

        return ['name' => (string) $raw, 'severity' => 'warn'];
    }

    private function _getValue(array $metrics, string $name) {
        $item = $metrics[$name] ?? null;
        if (!$item) return null;
        $v = $item['lastvalue'] ?? null;
        if ($v === null || $v === '') return null;
        return $v;
    }
}
