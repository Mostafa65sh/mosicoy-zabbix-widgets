<?php declare(strict_types = 0);

namespace Modules\MosicoySankeyFlow\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    const SEVERITY_LABELS = [
        0 => 'Not classified',
        1 => 'Information',
        2 => 'Warning',
        3 => 'Average',
        4 => 'High',
        5 => 'Disaster'
    ];

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'nodes' => [],
            'links' => [],
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

        // Host filter requirement
        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        // 1) Get hosts with their groups
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
            if (!empty($this->fields_values['groupids'])) {
                $host_options['groupids'] = $this->fields_values['groupids'];
            }
            if (!empty($this->fields_values['hostids'])) {
                $host_options['hostids'] = $this->fields_values['hostids'];
            }
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

        $data['summary']['hosts'] = count($hosts);

        // 2) Get active problems (triggers)
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

        // 3) Aggregate: host → severity counts
        $host_severity = [];
        foreach ($hosts as $hid => $h) {
            $host_severity[$hid] = array_fill(0, 6, 0);
        }

        foreach ($triggers as $t) {
            $sev = (int) $t['priority'];
            if ($sev < 0 || $sev > 5) $sev = 0;
            foreach ($t['hosts'] as $h) {
                $hid = $h['hostid'];
                if (isset($host_severity[$hid])) {
                    $host_severity[$hid][$sev]++;
                    $data['summary']['total_problems']++;
                }
            }
        }

        // 4) Filter + sort hosts: worst first, then by count
        $host_ranked = [];
        foreach ($hosts as $hid => $h) {
            $severities = $host_severity[$hid];
            $total = array_sum($severities);
            $worst = -1;
            foreach ($severities as $s => $c) {
                if ($c > 0) $worst = $s;
            }
            $host_ranked[] = [
                'hostid' => $hid,
                'name' => $h['name'],
                'groups' => $h['hostgroups'],
                'severities' => $severities,
                'total' => $total,
                'worst' => $worst
            ];
            if ($total > 0) $data['summary']['with_problems']++;
        }

        usort($host_ranked, function($a, $b) {
            if ($b['worst'] !== $a['worst']) return $b['worst'] - $a['worst'];
            if ($b['total'] !== $a['total']) return $b['total'] - $a['total'];
            return strcasecmp($a['name'], $b['name']);
        });

        // 5) Limit
        $max_hosts = (int) ($this->fields_values['max_hosts'] ?? 20);
        $truncated_total = count($host_ranked);
        if (count($host_ranked) > $max_hosts) {
            $host_ranked = array_slice($host_ranked, 0, $max_hosts);
            $data['truncated'] = true;
            $data['truncated_total'] = $truncated_total;
        }

        // 6) Build nodes + links
        $show_ok = (bool) ($this->fields_values['show_ok'] ?? 1);
        $show_groups = (bool) ($this->fields_values['show_groups'] ?? 1);

        $sev_colors = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];
        $ok_color = '#22c55e';
        $group_palette = ['#3b82f6', '#8b5cf6', '#06b6d4', '#ec4899', '#14b8a6', '#f59e0b', '#a855f7', '#0ea5e9'];

        $nodes = [];
        $links = [];
        $node_ids = [];

        // 6a) Severity nodes (right column, depth 2)
        foreach (self::SEVERITY_LABELS as $sev => $label) {
            $nodes[] = [
                'name' => 'sev_' . $sev,
                'displayName' => $label,
                'depth' => 2,
                'itemStyle' => ['color' => $sev_colors[$sev]]
            ];
        }

        if ($show_ok) {
            $nodes[] = [
                'name' => 'ok',
                'displayName' => 'OK',
                'depth' => 2,
                'itemStyle' => ['color' => $ok_color]
            ];
        }

        // 6b) Group nodes (left column, depth 0)
        $groups_used = [];
        if ($show_groups) {
            $gi = 0;
            foreach ($host_ranked as $h) {
                foreach ($h['groups'] as $g) {
                    $gid = $g['groupid'];
                    if (!isset($groups_used[$gid])) {
                        $color = $group_palette[$gi % count($group_palette)];
                        $groups_used[$gid] = [
                            'name' => 'group_' . $gid,
                            'displayName' => $g['name'],
                            'depth' => 0,
                            'itemStyle' => ['color' => $color]
                        ];
                        $gi++;
                        $nodes[] = $groups_used[$gid];
                    }
                }
            }
        }

        // 6c) Host nodes (middle, depth 1)
        foreach ($host_ranked as $h) {
            $worst_color = $h['worst'] >= 0 ? $sev_colors[$h['worst']] : $ok_color;
            $nodes[] = [
                'name' => 'host_' . $h['hostid'],
                'displayName' => $h['name'],
                'depth' => 1,
                'itemStyle' => ['color' => $worst_color],
                'hostid' => $h['hostid']
            ];
        }

        // 6d) Links: group → host (only if show_groups)
        if ($show_groups) {
            foreach ($host_ranked as $h) {
                $flow_value = max(1, $h['total']);
                foreach ($h['groups'] as $g) {
                    $gid = $g['groupid'];
                    if (isset($groups_used[$gid])) {
                        $links[] = [
                            'source' => 'group_' . $gid,
                            'target' => 'host_' . $h['hostid'],
                            'value' => $flow_value
                        ];
                    }
                }
            }
        }

        // 6e) Links: host → severity (or OK)
        foreach ($host_ranked as $h) {
            if ($h['total'] === 0) {
                if ($show_ok) {
                    $links[] = [
                        'source' => 'host_' . $h['hostid'],
                        'target' => 'ok',
                        'value' => 1
                    ];
                }
            }
            else {
                foreach ($h['severities'] as $sev => $count) {
                    if ($count > 0) {
                        $links[] = [
                            'source' => 'host_' . $h['hostid'],
                            'target' => 'sev_' . $sev,
                            'value' => $count
                        ];
                    }
                }
            }
        }

        $data['nodes'] = $nodes;
        $data['links'] = $links;

        $this->setResponse(new CControllerResponseData($data));
    }
}
