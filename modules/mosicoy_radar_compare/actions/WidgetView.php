<?php declare(strict_types = 0);

namespace Modules\MosicoyRadarCompare\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'indicators' => [],
            'series' => [],
            'summary' => ['hosts' => 0, 'indicators' => 0],
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

        // Patterns
        $patterns = [];
        if (!empty($this->fields_values['items'])) {
            foreach ($this->fields_values['items'] as $raw) {
                $clean = preg_replace('/^(\*|\d+):\s*/', '', (string) $raw);
                $clean = trim($clean);
                if ($clean !== '') $patterns[] = $clean;
            }
        }
        if (!$patterns) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        $max_axis = (int) ($this->fields_values['max_axis'] ?? 8);
        $patterns = array_slice($patterns, 0, $max_axis);

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

        // Max series
        $max_series = (int) ($this->fields_values['max_series'] ?? 6);
        $host_count = count($hosts);
        if ($host_count > $max_series) {
            $hosts = array_slice($hosts, 0, $max_series, true);
            $data['truncated'] = true;
            $data['truncated_total'] = $host_count;
        }

        // Get all items for those hosts
        try {
            $db_items = API::Item()->get([
                'output' => ['itemid', 'name', 'units', 'value_type', 'lastvalue', 'hostid'],
                'hostids' => array_keys($hosts),
                'monitored' => true,
                'filter' => ['value_type' => [0, 3]],
                'preservekeys' => true
            ]);
        }
        catch (\Exception $e) {
            $db_items = [];
        }

        if (!$db_items) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        // Group items by host
        $items_by_host = [];
        foreach ($db_items as $item) {
            $items_by_host[$item['hostid']][] = $item;
        }

        // For each pattern, find matching item per host
        $indicators = [];
        foreach ($patterns as $pattern) {
            $values_by_host = [];
            $max_val = 0;
            $unit = '';
            $sample_name = $pattern;

            foreach ($items_by_host as $hid => $items) {
                foreach ($items as $item) {
                    if ($this->_matchPattern($pattern, $item['name'])) {
                        $val = is_numeric($item['lastvalue']) ? (float) $item['lastvalue'] : 0;
                        $values_by_host[$hid] = $val;
                        if ($val > $max_val) $max_val = $val;
                        if ($unit === '' && !empty($item['units'])) $unit = $item['units'];
                        $sample_name = $item['name'];
                        break; // first match wins
                    }
                }
            }

            // Compute sensible max
            if ($unit === '%') {
                $axis_max = 100;
            }
            elseif ($max_val > 0) {
                $axis_max = ceil($max_val * 1.15);
            }
            else {
                $axis_max = 1;
            }

            $indicators[] = [
                'name' => $this->_shortLabel($pattern),
                'full_name' => $sample_name,
                'pattern' => $pattern,
                'unit' => $unit,
                'max' => $axis_max,
                'values' => $values_by_host
            ];
        }

        // Build series (one per host)
        $series = [];
        $colors = [];
        $ci = 0;
        foreach ($hosts as $hid => $host) {
            $values = [];
            foreach ($indicators as $ind) {
                $values[] = $ind['values'][$hid] ?? 0;
            }
            $series[] = [
                'hostid' => (string) $hid,
                'name' => (string) $host['name'],
                'values' => $values,
                'colorIndex' => $ci++
            ];
        }

        $data['indicators'] = $indicators;
        $data['series'] = $series;
        $data['summary']['hosts'] = count($series);
        $data['summary']['indicators'] = count($indicators);

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _matchPattern(string $pattern, string $subject): bool {
        $parts = preg_split('/([*?])/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            if ($part === '*') $regex .= '.*';
            elseif ($part === '?') $regex .= '.';
            else $regex .= preg_quote($part, '/');
        }
        return (bool) preg_match('/^' . $regex . '$/i', $subject);
    }

    private function _shortLabel(string $pattern): string {
        $s = trim($pattern);
        $s = preg_replace('/[*?]+$/', '', $s);
        $s = trim($s);
        if (strlen($s) > 22) $s = substr($s, 0, 20) . '…';
        return $s !== '' ? $s : 'axis';
    }
}
