<?php declare(strict_types = 0);

namespace Modules\MosicoyNetworkTopology\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'nodes' => [],
            'links' => [],
            'categories' => [],
            'summary' => [
                'hosts' => 0,
                'with_problems' => 0,
                'total_problems' => 0
            ],
            'truncated' => false,
            'truncated_total' => 0,
            'fields_values' => $this->fields_values,
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        // 1) Get hosts + groups
        $host_options = [
            'output' => ['hostid', 'name', 'status'],
            'selectHostGroups' => ['groupid', 'name'],
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

        try {
            $hosts = API::Host()->get($host_options);
        }
        catch (\Exception $e) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        if (!$hosts) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        // 2) Get active problems
        try {
            $triggers = API::Trigger()->get([
                'output' => ['triggerid', 'priority'],
                'selectHosts' => ['hostid'],
                'hostids' => array_keys($hosts),
                'filter' => ['value' => TRIGGER_VALUE_TRUE],
                'monitored' => true,
                'active' => true,
                'skipDependent' => true
            ]);
        }
        catch (\Exception $e) {
            $triggers = [];
        }

        // 3) Aggregate per host
        $host_severity = [];
        foreach ($hosts as $hid => $h) {
            $host_severity[$hid] = array_fill(0, 6, 0);
        }

        foreach ($triggers as $t) {
            $sev = (int) $t['priority'];
            if ($sev < 0 || $sev > 5) $sev = 0;
            foreach ($t['hosts'] as $h) {
                if (isset($host_severity[$h['hostid']])) {
                    $host_severity[$h['hostid']][$sev]++;
                    $data['summary']['total_problems']++;
                }
            }
        }

        // 4) Get severity filter
        $filter = $this->fields_values['severity_filter'] ?? [];
        if (!is_array($filter) || empty($filter)) {
            $filter = [0, 1, 2, 3, 4, 5];
        }
        $filter = array_map('intval', $filter);

        // 5) Build host list with computed worst + filtered count
        $host_list = [];
        foreach ($hosts as $hid => $h) {
            $sevs = $host_severity[$hid];
            $total_all = array_sum($sevs);
            $total_filtered = 0;
            $worst_filtered = -1;
            for ($i = 0; $i < 6; $i++) {
                if (in_array($i, $filter, true)) {
                    $total_filtered += $sevs[$i];
                    if ($sevs[$i] > 0) $worst_filtered = $i;
                }
            }

            // Include host only if it has problems in filter OR it's OK (worst==-1 with total_all==0)
            $is_ok = ($total_all === 0);
            $include = $is_ok || ($total_filtered > 0);

            if (!$include) continue;

            $host_list[] = [
                'hostid' => $hid,
                'name' => $h['name'],
                'groups' => $h['hostgroups'],
                'worst' => $is_ok ? -1 : $worst_filtered,
                'total' => $is_ok ? 0 : $total_filtered,
                'ok' => $is_ok
            ];
        }

        // Sort: worst first
        usort($host_list, function($a, $b) {
            if ($b['worst'] !== $a['worst']) return $b['worst'] - $a['worst'];
            if ($b['total'] !== $a['total']) return $b['total'] - $a['total'];
            return strcasecmp($a['name'], $b['name']);
        });

        // Limit
        $max = (int) ($this->fields_values['max_hosts'] ?? 50);
        $data['truncated_total'] = count($host_list);
        if (count($host_list) > $max) {
            $host_list = array_slice($host_list, 0, $max);
            $data['truncated'] = true;
        }

        $data['summary']['hosts'] = count($host_list);
        $data['summary']['with_problems'] = count(array_filter($host_list, function($h) { return !$h['ok']; }));

        // 6) Build categories (groups)
        $group_map = [];
        foreach ($host_list as $h) {
            foreach ($h['groups'] as $g) {
                if (!isset($group_map[$g['groupid']])) {
                    $group_map[$g['groupid']] = $g['name'];
                }
            }
        }
        $categories = [];
        $category_index = [];
        $gi = 0;
        foreach ($group_map as $gid => $gname) {
            $categories[] = ['name' => $gname];
            $category_index[$gid] = $gi++;
        }
        $data['categories'] = $categories;

        // 7) Build nodes
        $severity_colors = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];
        $ok_color = '#22c55e';

        foreach ($host_list as $h) {
            $first_group = $h['groups'][0]['groupid'] ?? null;
            $cat_idx = $first_group !== null ? ($category_index[$first_group] ?? 0) : 0;
            $color = $h['ok'] ? $ok_color : $severity_colors[max(0, $h['worst'])];

            // Node size: base 20 + problem count * 4 (max 60)
            $size = $h['ok'] ? 22 : min(60, 22 + $h['total'] * 4);

            $data['nodes'][] = [
                'id' => (string) $h['hostid'],
                'name' => $h['name'],
                'category' => $cat_idx,
                'symbolSize' => $size,
                'value' => $h['total'],
                'hostid' => (string) $h['hostid'],
                'worst' => $h['worst'],
                'is_ok' => $h['ok'],
                'groups' => array_map(function($g) { return $g['name']; }, $h['groups']),
                'itemStyle' => [
                    'color' => $color,
                    'borderColor' => $h['ok'] ? 'transparent' : $color,
                    'borderWidth' => $h['worst'] >= 4 ? 3 : 1,
                    'shadowBlur' => $h['worst'] >= 4 ? 15 : 0,
                    'shadowColor' => $h['worst'] >= 4 ? $color : 'transparent'
                ]
            ];
        }

        // 8) Build links (star topology within each group)
        $group_members = [];
        foreach ($host_list as $h) {
            foreach ($h['groups'] as $g) {
                $group_members[$g['groupid']][] = $h['hostid'];
            }
        }

        $link_seen = [];
        foreach ($group_members as $gid => $members) {
            if (count($members) < 2) continue;
            $hub = $members[0];
            for ($i = 1; $i < count($members); $i++) {
                $src = (string) $hub;
                $dst = (string) $members[$i];
                $key = min($src, $dst) . '|' . max($src, $dst);
                if (isset($link_seen[$key])) continue;
                $link_seen[$key] = true;

                $data['links'][] = [
                    'source' => $src,
                    'target' => $dst,
                    'lineStyle' => [
                        'color' => 'source',
                        'width' => 1,
                        'opacity' => 0.4,
                        'curveness' => 0.1
                    ]
                ];
            }
        }

        $this->setResponse(new CControllerResponseData($data));
    }
}
