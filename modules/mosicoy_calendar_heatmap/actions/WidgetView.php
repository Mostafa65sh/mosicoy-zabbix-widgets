<?php declare(strict_types = 0);

namespace Modules\MosicoyCalendar\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'history' => [],
            'items_meta' => [],
            'fields_values' => $this->fields_values,
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        $has_items = !empty($this->fields_values['items']);
        $has_host_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_items && !$has_host_filter) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        $options = [
            'output' => ['itemid', 'value_type', 'name', 'key_', 'units', 'hostid'],
            'webitems' => true,
            'preservekeys' => true,
            'selectHosts' => ['name'],
            'sortfield' => 'name'
        ];

        if ($this->isTemplateDashboard() && !empty($this->fields_values['override_hostid'])) {
            $options['hostids'] = $this->fields_values['override_hostid'];
        }
        else {
            if (!empty($this->fields_values['groupids'])) {
                $options['groupids'] = $this->fields_values['groupids'];
            }
            if (!empty($this->fields_values['hostids'])) {
                $options['hostids'] = $this->fields_values['hostids'];
            }
        }

        if ($has_items) {
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

        $tp = $this->fields_values['time_period'] ?? [];
        $time_from = is_numeric($tp['from_ts'] ?? null) ? (int) $tp['from_ts'] : time() - 30 * 86400;
        $time_till = is_numeric($tp['to_ts'] ?? null) ? (int) $tp['to_ts'] : time();

        $aggregate = (int) ($this->fields_values['aggregate'] ?? 1);

        foreach ($db_items as $itemid => $item) {
            $raw_name = trim((string) ($item['name'] ?? ''));
            $key = trim((string) ($item['key_'] ?? ''));

            if ($raw_name === '') {
                $raw_name = $key !== '' ? $key : ('Item ' . $itemid);
            }

            $data['items_meta'][$itemid] = [
                'name' => $raw_name,
                'key' => $key,
                'host' => $item['hosts'][0]['name'] ?? '',
                'units' => (string) ($item['units'] ?? '')
            ];

            if (!in_array((int) $item['value_type'], [0, 3], true)) {
                $data['history'][$itemid] = [];
                continue;
            }

            try {
                $history = API::History()->get([
                    'output' => ['clock', 'value'],
                    'itemids' => [$itemid],
                    'history' => (int) $item['value_type'],
                    'time_from' => $time_from,
                    'time_till' => $time_till,
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit' => 50000
                ]);

                $by_day = [];
                foreach ($history as $point) {
                    $day = date('Y-m-d', (int) $point['clock']);
                    $val = (float) $point['value'];

                    if (!isset($by_day[$day])) {
                        $by_day[$day] = ['sum' => 0, 'count' => 0, 'min' => $val, 'max' => $val];
                    }
                    $by_day[$day]['sum'] += $val;
                    $by_day[$day]['count']++;
                    if ($val < $by_day[$day]['min']) $by_day[$day]['min'] = $val;
                    if ($val > $by_day[$day]['max']) $by_day[$day]['max'] = $val;
                }

                $result = [];
                foreach ($by_day as $day => $agg) {
                    switch ($aggregate) {
                        case 0: $v = $agg['sum']; break;
                        case 1: $v = $agg['count'] > 0 ? $agg['sum'] / $agg['count'] : 0; break;
                        case 2: $v = $agg['max']; break;
                        case 3: $v = $agg['min']; break;
                        default: $v = $agg['sum'] / max($agg['count'], 1);
                    }
                    $result[] = [$day, round($v, 4)];
                }

                $data['history'][$itemid] = $result;
            }
            catch (\Exception $e) {
                $data['history'][$itemid] = [];
            }
        }

        $this->setResponse(new CControllerResponseData($data));
    }
}
