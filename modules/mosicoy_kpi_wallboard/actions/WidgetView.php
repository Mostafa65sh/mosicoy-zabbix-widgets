<?php declare(strict_types = 0);

namespace Modules\MosicoyKpiWallboard\Actions;

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

        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) {
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        // Parse threshold rules from textarea
        $threshold_rules = $this->_parseThresholdRules((string) ($this->fields_values['threshold_rules'] ?? ''));

        $options = [
            'output' => ['itemid', 'name', 'key_', 'units', 'value_type', 'lastvalue', 'prevvalue', 'lastclock', 'hostid'],
            'webitems' => true,
            'preservekeys' => true,
            'selectHosts' => ['name'],
            'selectTags' => ['tag', 'value'],
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

        $max_items = (int) ($this->fields_values['max_items'] ?? 8);
        $show_sparkline = (int) ($this->fields_values['show_sparkline'] ?? 1) === 1;
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

            $last = $item['lastvalue'] !== null && $item['lastvalue'] !== '' ? (float) $item['lastvalue'] : null;
            $prev = $item['prevvalue'] !== null && $item['prevvalue'] !== '' ? (float) $item['prevvalue'] : null;
            $units = (string) ($item['units'] ?? '');
            $lastclock = (int) ($item['lastclock'] ?? 0);

            // Sparkline history
            $sparkline = [];
            if ($show_sparkline) {
                try {
                    $hist = API::History()->get([
                        'output' => ['clock', 'value'],
                        'itemids' => [$itemid],
                        'history' => (int) $item['value_type'],
                        'time_from' => $now - 3600,
                        'time_till' => $now,
                        'sortfield' => 'clock',
                        'sortorder' => 'ASC',
                        'limit' => 30
                    ]);
                    foreach ($hist as $h) {
                        $sparkline[] = (float) $h['value'];
                    }
                }
                catch (\Exception $e) {
                    $sparkline = [];
                }
            }

            // ═══════════════════════════════════════════════════════════════
            // THRESHOLD RESOLUTION
            // Priority: 1) Item tags, 2) Pattern rules, 3) Default (handled in JS)
            // ═══════════════════════════════════════════════════════════════
            $warn = null;
            $crit = null;
            $dir = 'up';
            $threshold_source = 'none';

            // 1) Item tags (highest priority - per-item override)
            foreach (($item['tags'] ?? []) as $tag) {
                $tn = strtolower(trim($tag['tag'] ?? ''));
                $tv = trim($tag['value'] ?? '');
                if ($tv === '') continue;
                if ($tn === 'mosicoy_warn' && is_numeric($tv)) {
                    $warn = (float) $tv;
                    $threshold_source = 'item_tag';
                }
                elseif ($tn === 'mosicoy_crit' && is_numeric($tv)) {
                    $crit = (float) $tv;
                    $threshold_source = 'item_tag';
                }
                elseif ($tn === 'mosicoy_dir' && in_array(strtolower($tv), ['up', 'down'], true)) {
                    $dir = strtolower($tv);
                    $threshold_source = 'item_tag';
                }
            }

            // 2) Pattern rules (only if item tags didn't provide both)
            if (($warn === null || $crit === null) && !empty($threshold_rules)) {
                $rule = $this->_matchRule($threshold_rules, (string) ($item['name'] ?? ''));
                if ($rule) {
                    if ($warn === null && $rule['warn'] !== null) {
                        $warn = $rule['warn'];
                        $threshold_source = 'pattern';
                    }
                    if ($crit === null && $rule['crit'] !== null) {
                        $crit = $rule['crit'];
                        $threshold_source = 'pattern';
                    }
                    if ($rule['dir'] !== null) {
                        $dir = $rule['dir'];
                    }
                }
            }

            $spark_stats = null;
            if (!empty($sparkline)) {
                $spark_stats = [
                    'min' => min($sparkline),
                    'max' => max($sparkline),
                    'avg' => array_sum($sparkline) / count($sparkline)
                ];
            }

            $data['items'][] = [
                'itemid' => (string) $itemid,
                'name' => $raw_name !== '' ? $raw_name : (string) $itemid,
                'full_name' => (string) ($item['name'] ?? ''),
                'host' => $host,
                'units' => $units,
                'value' => $last,
                'prev_value' => $prev,
                'lastclock' => $lastclock,
                'stale' => $lastclock > 0 && ($now - $lastclock) > 300,
                'no_data' => $last === null,
                'sparkline' => $sparkline,
                'spark_stats' => $spark_stats,
                'warn_threshold' => $warn,
                'crit_threshold' => $crit,
                'direction' => $dir,
                'threshold_source' => $threshold_source
            ];

            if (count($data['items']) >= $max_items) break;
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    /**
     * Parse threshold rules: one line per rule
     * Format: pattern:warn:crit[:dir]
     * Examples:
     *   *CPU*:70:90
     *   *Memory*:80:95
     *   *free*:20:10:down
     *   *latency*:500:2000
     */
    private function _parseThresholdRules(string $text): array {
        $rules = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            $parts = explode(':', $line);
            if (count($parts) < 2) continue;

            $pattern = trim($parts[0] ?? '');
            if ($pattern === '') continue;

            $warn = isset($parts[1]) && is_numeric(trim($parts[1])) ? (float) trim($parts[1]) : null;
            $crit = isset($parts[2]) && is_numeric(trim($parts[2])) ? (float) trim($parts[2]) : null;
            $dir_raw = isset($parts[3]) ? strtolower(trim($parts[3])) : '';
            $dir = in_array($dir_raw, ['up', 'down'], true) ? $dir_raw : null;

            if ($warn === null && $crit === null) continue;

            $rules[] = [
                'pattern' => $pattern,
                'warn' => $warn,
                'crit' => $crit,
                'dir' => $dir
            ];
        }
        return $rules;
    }

    private function _matchRule(array $rules, string $subject): ?array {
        foreach ($rules as $rule) {
            if ($this->_wildcardMatch($rule['pattern'], $subject)) {
                return $rule;
            }
        }
        return null;
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
