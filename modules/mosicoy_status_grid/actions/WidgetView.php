<?php declare(strict_types = 0);

namespace Modules\MosicoyStatusGrid\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'hosts' => [],
            'summary' => [
                'total' => 0,
                'ok' => 0,
                'warn' => 0,
                'crit' => 0,
                'problems' => 0
            ],
            'fields_values' => $this->fields_values,
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        // ==== HOSTS ====
        $host_options = [
            'output' => ['hostid', 'name', 'host', 'status'],
            'preservekeys' => true,
            'sortfield' => 'name'
        ];

        if ($this->isTemplateDashboard()) {
            if (empty($this->fields_values['override_hostid'])) {
                $this->setResponse(new CControllerResponseData($data));
                return;
            }
            $host_options['hostids'] = $this->fields_values['override_hostid'];
        }
        else {
            if (!empty($this->fields_values['groupids'])) {
                $host_options['groupids'] = $this->fields_values['groupids'];
            }
            if (!empty($this->fields_values['hostids'])) {
                $host_options['hostids'] = $this->fields_values['hostids'];
            }
            if (empty($host_options['groupids']) && empty($host_options['hostids'])) {
                $this->setResponse(new CControllerResponseData($data));
                return;
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

        $hostids = array_keys($hosts);

        // ==== TRIGGERS (active problems) ====
        try {
            $triggers = API::Trigger()->get([
                'output' => ['triggerid', 'description', 'priority', 'lastchange', 'value'],
                'selectHosts' => ['hostid'],
                'hostids' => $hostids,
                'filter' => ['value' => TRIGGER_VALUE_TRUE],
                'monitored' => true,
                'active' => true,
                'skipDependent' => true,
                'preservekeys' => true
            ]);
        }
        catch (\Exception $e) {
            $triggers = [];
        }

        // ==== AGGREGATE PER HOST ====
        $per_host = [];
        foreach ($hostids as $hid) {
            $per_host[$hid] = ['worst' => -1, 'count' => 0, 'triggers' => []];
        }

        foreach ($triggers as $t) {
            $sev = (int) $t['priority'];
            foreach ($t['hosts'] as $h) {
                $hid = $h['hostid'];
                if (!isset($per_host[$hid])) continue;

                $per_host[$hid]['count']++;
                if ($sev > $per_host[$hid]['worst']) {
                    $per_host[$hid]['worst'] = $sev;
                }
                if (count($per_host[$hid]['triggers']) < 3) {
                    $per_host[$hid]['triggers'][] = [
                        'description' => $t['description'],
                        'severity' => $sev,
                        'lastchange' => (int) $t['lastchange']
                    ];
                }
            }
        }

        // ==== BUILD HOSTS LIST ====
        foreach ($hosts as $hid => $host) {
            $info = $per_host[$hid] ?? ['worst' => -1, 'count' => 0, 'triggers' => []];
            $has_problems = $info['count'] > 0;
            $severity = $has_problems ? max(0, $info['worst']) : 0;

            $data['hosts'][] = [
                'hostid' => (string) $hid,
                'name' => (string) $host['name'],
                'host' => (string) $host['host'],
                'status' => (int) $host['status'],
                'severity' => $severity,
                'has_problems' => $has_problems,
                'problem_count' => $info['count'],
                'triggers' => $info['triggers']
            ];

            $data['summary']['total']++;
            $data['summary']['problems'] += $info['count'];

            if (!$has_problems) {
                $data['summary']['ok']++;
            }
            elseif ($severity >= 4) {
                $data['summary']['crit']++;
            }
            else {
                $data['summary']['warn']++;
            }
        }

        // ==== SORT ====
        $sort = (int) ($this->fields_values['sort_by'] ?? 0);
        usort($data['hosts'], function($a, $b) use ($sort) {
            if ($sort === 0) {
                if ($b['severity'] !== $a['severity']) {
                    return $b['severity'] - $a['severity'];
                }
                if ($b['problem_count'] !== $a['problem_count']) {
                    return $b['problem_count'] - $a['problem_count'];
                }
            }
            return strcasecmp($a['name'], $b['name']);
        });

        // ==== LIMIT ====
        $max = (int) ($this->fields_values['max_hosts'] ?? 100);
        if ($max > 0 && count($data['hosts']) > $max) {
            $data['hosts'] = array_slice($data['hosts'], 0, $max);
            $data['truncated'] = true;
            $data['truncated_total'] = $data['summary']['total'];
        }

        $this->setResponse(new CControllerResponseData($data));
    }
}
