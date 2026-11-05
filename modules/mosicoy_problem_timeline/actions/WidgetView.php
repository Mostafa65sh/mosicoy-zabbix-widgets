<?php declare(strict_types = 0);

namespace Modules\MosicoyProblemTimeline\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        // Always provide safe defaults, even if everything fails
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'problems' => [],
            'now' => time(),
            'window_from' => time() - 6 * 3600,
            'window_to' => time(),
            'summary' => ['total' => 0, 'acknowledged' => 0],
            'fields_values' => $this->fields_values ?: [],
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        try {
            $this->_collect($data);
        }
        catch (\Throwable $e) {
            // Never crash the dashboard — log and return safe defaults
            error_log('Mosicoy Timeline error: ' . $e->getMessage());
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _collect(array &$data): void {
        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) {
            return;
        }

        $window_sec = 6 * 3600;
        switch ((int) ($this->fields_values['time_window'] ?? 1)) {
            case 0: $window_sec = 3600; break;
            case 1: $window_sec = 6 * 3600; break;
            case 2: $window_sec = 24 * 3600; break;
            case 3: $window_sec = 7 * 86400; break;
        }

        $now = time();
        $time_from = $now - $window_sec;
        $data['window_from'] = $time_from;
        $data['window_to'] = $now;
        $data['now'] = $now;

        // ⚠️ NO time_from — we want ALL currently active problems
        // regardless of when they started. We'll clamp them visually to the window.
        $options = [
            'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged', 'r_eventid'],
            'selectHosts' => ['hostid', 'name'],
            'sortfield' => ['clock'],
            'sortorder' => 'DESC',
            'source' => 0,
            'object' => 0,
            'value' => 1,
            'limit' => 500
        ];

        if ($this->isTemplateDashboard()) {
            $options['hostids'] = $this->fields_values['override_hostid'];
        }
        else {
            if (!empty($this->fields_values['groupids'])) $options['groupids'] = $this->fields_values['groupids'];
            if (!empty($this->fields_values['hostids'])) $options['hostids'] = $this->fields_values['hostids'];
        }

        if ((int) ($this->fields_values['show_acknowledged'] ?? 1) === 0) {
            $options['acknowledged'] = false;
        }

        try {
            $events = API::Event()->get($options);
        }
        catch (\Throwable $e) {
            return;
        }
        if (!$events) return;

        // Trigger info
        $triggerids = [];
        foreach ($events as $e) {
            if ((int) $e['objectid'] > 0) $triggerids[] = $e['objectid'];
        }
        $trigger_info = [];
        if ($triggerids) {
            try {
                $triggers = API::Trigger()->get([
                    'output' => ['triggerid', 'description', 'priority'],
                    'triggerids' => array_unique($triggerids),
                    'preservekeys' => true
                ]);
                foreach ($triggers as $t) {
                    $trigger_info[$t['triggerid']] = [
                        'description' => $t['description'],
                        'priority' => (int) $t['priority']
                    ];
                }
            }
            catch (\Throwable $e) { /* ignore */ }
        }

        $max = (int) ($this->fields_values['max_problems'] ?? 50);

        foreach ($events as $e) {
            $start_clock = (int) $e['clock'];
            $end_clock = $now;

            if ($start_clock < $time_from) $start_clock = $time_from;
            if ($end_clock <= $start_clock) $end_clock = $start_clock + 1;
            if ($end_clock > $now) $end_clock = $now;

            $trigger = $trigger_info[$e['objectid']] ?? null;
            $severity = $trigger ? $trigger['priority'] : (int) $e['severity'];
            $trigger_desc = $trigger ? $trigger['description'] : (string) ($e['name'] ?? '');
            $host = $e['hosts'][0] ?? ['hostid' => '0', 'name' => 'Unknown'];

            $data['problems'][] = [
                'eventid' => (string) $e['eventid'],
                'hostid' => (string) $host['hostid'],
                'host' => (string) $host['name'],
                'name' => (string) $trigger_desc,
                'severity' => $severity,
                'start' => $start_clock,
                'end' => $end_clock,
                'duration' => $end_clock - $start_clock,
                'resolved' => false,
                'acknowledged' => (int) $e['acknowledged'] === 1,
                'is_active' => true
            ];

            $data['summary']['total']++;
            if ((int) $e['acknowledged'] === 1) $data['summary']['acknowledged']++;

            if (count($data['problems']) >= $max) break;
        }

        $sort = (int) ($this->fields_values['sort_by'] ?? 0);
        usort($data['problems'], function($a, $b) use ($sort) {
            if ($sort === 1) return $b['duration'] - $a['duration'];
            if ($sort === 2) return strcasecmp($a['host'], $b['host']);
            return $b['severity'] - $a['severity'];
        });
    }
}
