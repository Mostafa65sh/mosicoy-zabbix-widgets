<?php declare(strict_types = 0);

namespace Modules\MosicoyGaugeCluster\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'items' => [],
            'fields_values' => $this->fields_values,
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        // Host filter requirement
        if ($this->isTemplateDashboard()) {
            if (empty($this->fields_values['override_hostid'])) {
                $this->setResponse(new CControllerResponseData($data));
                return;
            }
        }
        else if (empty($this->fields_values['hostids']) && empty($this->fields_values['groupids'])) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        $options = [
            'output' => ['itemid', 'name', 'key_', 'units', 'value_type', 'lastvalue', 'prevvalue', 'lastclock'],
            'webitems' => true,
            'preservekeys' => true,
            'selectHosts' => ['name'],
            'sortfield' => 'name',
            'monitored' => true
        ];

        if ($this->isTemplateDashboard()) {
            $options['hostids'] = $this->fields_values['override_hostid'];
        }
        else {
            if (!empty($this->fields_values['groupids'])) $options['groupids'] = $this->fields_values['groupids'];
            if (!empty($this->fields_values['hostids'])) $options['hostids'] = $this->fields_values['hostids'];
        }

        if (!empty($this->fields_values['items'])) {
            $patterns = [];
            foreach ($this->fields_values['items'] as $pattern) {
                $clean = preg_replace('/^(\*|\d+):\s*/', '', (string) $pattern);
                if ($clean !== '') $patterns[] = $clean;
            }
            if ($patterns) {
                $options['search'] = ['name' => $patterns];
                $options['searchByAny'] = true;
                $options['searchWildcardsEnabled'] = true;
            }
        }

        try {
            $db_items = API::Item()->get($options);
        }
        catch (\Exception $e) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        if (!$db_items) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        $fixed_max = (float) ($this->fields_values['fixed_max'] ?? 0);
        $max_items = (int) ($this->fields_values['max_items'] ?? 16);
        $now = time();

        foreach ($db_items as $itemid => $item) {
            if (!in_array((int) $item['value_type'], [0, 3], true)) continue;

            $host = $item['hosts'][0]['name'] ?? '';
            $raw_name = trim((string) ($item['name'] ?? ''));

            if ($host !== '') {
                $clean = preg_replace('/^' . preg_quote($host, '/') . '\s*[:\/]\s*/i', '', $raw_name);
                $clean = preg_replace('/^\{HOST\.NAME\}\s*[:\/]\s*/i', '', (string) $clean);
                if (trim((string) $clean) !== '') $raw_name = trim((string) $clean);
            }

            $last = $item['lastvalue'] !== null && $item['lastvalue'] !== ''
                ? (float) $item['lastvalue'] : null;
            $prev = $item['prevvalue'] !== null && $item['prevvalue'] !== ''
                ? (float) $item['prevvalue'] : null;
            $units = (string) ($item['units'] ?? '');
            $lastclock = (int) ($item['lastclock'] ?? 0);

            // Determine max
            $max = 100.0;
            if ($fixed_max > 0) {
                $max = $fixed_max;
            }
            elseif (strpos($units, '%') !== false) {
                $max = 100.0;
            }
            elseif ($last !== null && $last != 0) {
                $max = abs($last) * 1.5;
            }

            $data['items'][] = [
                'itemid' => (string) $itemid,
                'name' => $raw_name !== '' ? $raw_name : (string) $itemid,
                'full_name' => (string) ($item['name'] ?? ''),
                'host' => $host,
                'units' => $units,
                'value' => $last,
                'prev_value' => $prev,
                'max' => $max,
                'lastclock' => $lastclock,
                'stale' => $lastclock > 0 && ($now - $lastclock) > 300,
                'no_data' => $last === null
            ];

            if (count($data['items']) >= $max_items) break;
        }

        $this->setResponse(new CControllerResponseData($data));
    }
}
