<?php declare(strict_types = 0);

namespace Modules\MosicoyNetworkMap\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'nodes' => [],
            'links' => [],
            'debug' => [],
            'fields_values' => $this->fields_values ?: [],
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        try {
            $this->_collect($data);
        }
        catch (\Throwable $e) {
            $data['debug'][] = 'Error: ' . $e->getMessage();
            error_log('Mosicoy Network Map error: ' . $e->getMessage());
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _collect(array &$data): void {
        // 1) Parse links from textarea
        $raw = (string) ($this->fields_values['links'] ?? '');
        $links_parsed = [];
        $host_names = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            $parts = array_map('trim', explode(':', $line));
            if (count($parts) < 2) continue;

            $src = $parts[0];
            $dst = $parts[1];
            $label = $parts[2] ?? '';

            if ($src === '' || $dst === '' || $src === $dst) continue;

            $links_parsed[] = ['src' => $src, 'dst' => $dst, 'label' => $label];
            $host_names[$src] = true;
            $host_names[$dst] = true;
        }

        if (!$links_parsed) {
            $data['debug'][] = 'No valid links parsed from textarea';
            return;
        }

        $host_names = array_keys($host_names);
        $data['debug'][] = 'Parsed ' . count($links_parsed) . ' links, ' . count($host_names) . ' unique hosts';

        // 2) Get host info + severity
        try {
            $hosts = API::Host()->get([
                'output' => ['hostid', 'name', 'host'],
                'filter' => ['name' => $host_names],
                'preservekeys' => true
            ]);
        }
        catch (\Throwable $e) {
            $hosts = [];
        }

        // Also try to match by technical host name if name didn't work
        $missing = array_diff($host_names, array_column($hosts, 'name'));
        if ($missing) {
            try {
                $hosts2 = API::Host()->get([
                    'output' => ['hostid', 'name', 'host'],
                    'filter' => ['host' => $missing],
                    'preservekeys' => true
                ]);
                $hosts = $hosts + $hosts2;
            }
            catch (\Throwable $e) { /* ignore */ }
        }

        // Build name → hostid map
        $name_to_hostid = [];
        foreach ($hosts as $h) {
            $name_to_hostid[$h['name']] = $h['hostid'];
            if ($h['host'] !== $h['name']) {
                $name_to_hostid[$h['host']] = $h['hostid'];
            }
        }

        // 3) Get problems per host
        $severity = [];
        if ($hosts) {
            try {
                $triggers = API::Trigger()->get([
                    'output' => ['triggerid', 'priority'],
                    'selectHosts' => ['hostid'],
                    'hostids' => array_keys($hosts),
                    'filter' => ['value' => 1],
                    'monitored' => true,
                    'active' => true,
                    'skipDependent' => true
                ]);

                foreach ($triggers as $t) {
                    foreach ($t['hosts'] as $th) {
                        $hid = $th['hostid'];
                        $pri = (int) $t['priority'];
                        if (!isset($severity[$hid])) {
                            $severity[$hid] = ['worst' => -1, 'count' => 0];
                        }
                        $severity[$hid]['count']++;
                        if ($pri > $severity[$hid]['worst']) {
                            $severity[$hid]['worst'] = $pri;
                        }
                    }
                }
            }
            catch (\Throwable $e) { /* ignore */ }
        }

        // 4) Build nodes
        $max_nodes = (int) ($this->fields_values['max_nodes'] ?? 50);
        $sev_colors = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];
        $ok_color = '#22c55e';
        $size_by_problems = (int) ($this->fields_values['size_by_problems'] ?? 1) === 1;

        $seen = [];
        foreach ($host_names as $hname) {
            if (count($data['nodes']) >= $max_nodes) break;
            $hid = $name_to_hostid[$hname] ?? null;
            $sev_info = $hid && isset($severity[$hid]) ? $severity[$hid] : null;

            $worst = $sev_info ? $sev_info['worst'] : -1;
            $count = $sev_info ? $sev_info['count'] : 0;

            $color = $worst >= 0 ? $sev_colors[$worst] : $ok_color;
            $symbol_size = 30;
            if ($size_by_problems) {
                $symbol_size = 30 + min(40, $count * 6);
            }

            $data['nodes'][] = [
                'id' => (string) $hname,
                'name' => (string) $hname,
                'hostid' => $hid ? (string) $hid : '',
                'value' => $count,
                'severity' => $worst,
                'problem_count' => $count,
                'symbolSize' => $symbol_size,
                'itemStyle' => [
                    'color' => $color,
                    'borderColor' => $worst >= 4 ? $color : 'transparent',
                    'borderWidth' => $worst >= 4 ? 3 : 1,
                    'shadowBlur' => $worst >= 4 ? 15 : 0,
                    'shadowColor' => $worst >= 4 ? $color : 'transparent'
                ]
            ];
            $seen[$hname] = true;
        }

        // 5) Build links (only between existing nodes)
        $show_labels = (int) ($this->fields_values['show_link_labels'] ?? 1) === 1;
        foreach ($links_parsed as $lp) {
            if (!isset($seen[$lp['src']]) || !isset($seen[$lp['dst']])) continue;
            $data['links'][] = [
                'source' => (string) $lp['src'],
                'target' => (string) $lp['dst'],
                'value' => $lp['label'],
                'label' => $show_labels ? ['show' => true, 'formatter' => '{c}'] : ['show' => false],
                'lineStyle' => [
                    'color' => 'source',
                    'curveness' => 0.05,
                    'width' => 2,
                    'opacity' => 0.5
                ]
            ];
        }

        $data['debug'][] = 'Nodes: ' . count($data['nodes']) . ' · Links: ' . count($data['links']);
    }
}
