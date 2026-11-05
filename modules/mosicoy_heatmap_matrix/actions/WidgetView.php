<?php declare(strict_types = 0);

namespace Modules\MosicoyHeatmapMatrix\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $data = [
            'name' => $this->getInput('name', $this->widget->getName()),
            'columns' => [],
            'rows' => [],
            'summary' => ['hosts' => 0, 'cells' => 0, 'crit' => 0, 'warn' => 0],
            'truncated' => false,
            'truncated_total' => 0,
            'fields_values' => $this->fields_values ?: [],
            'user' => ['debug_mode' => $this->getDebugMode()]
        ];

        try {
            $this->_collect($data);
        }
        catch (\Throwable $e) {
            error_log('Mosicoy Heatmap Matrix error: ' . $e->getMessage());
        }

        $this->setResponse(new CControllerResponseData($data));
    }

    private function _collect(array &$data): void {
        $has_filter = $this->isTemplateDashboard()
            ? !empty($this->fields_values['override_hostid'])
            : (!empty($this->fields_values['hostids']) || !empty($this->fields_values['groupids']));

        if (!$has_filter) return;

        // Parse columns
        $columns = $this->_parseColumns($this->fields_values['columns'] ?? '');
        if (!$columns) return;
        $data['columns'] = $columns;

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
        catch (\Throwable $e) { return; }
        if (!$hosts) return;

        // Limit
        $max = (int) ($this->fields_values['max_hosts'] ?? 50);
        $data['truncated_total'] = count($hosts);
        if (count($hosts) > $max) {
            $hosts = array_slice($hosts, 0, $max, true);
            $data['truncated'] = true;
        }

        // All matching items for all hosts (one API call)
        $all_patterns = [];
        foreach ($columns as $col) {
            $all_patterns[] = $col['pattern'];
        }

        try {
            $db_items = API::Item()->get([
                'output' => ['itemid', 'name', 'units', 'value_type', 'lastvalue', 'hostid'],
                'hostids' => array_keys($hosts),
                'monitored' => true,
                'filter' => ['value_type' => [0, 3]],
                'search' => ['name' => $all_patterns],
                'searchByAny' => true,
                'searchWildcardsEnabled' => true,
                'preservekeys' => true
            ]);
        }
        catch (\Throwable $e) { return; }

        if (!$db_items) return;

        // Group by host
        $by_host = [];
        foreach ($db_items as $item) {
            $by_host[$item['hostid']][] = $item;
        }

        // Build matrix rows
        $color_mode = (int) ($this->fields_values['color_mode'] ?? 0);

        foreach ($hosts as $hid => $host) {
            $cells = [];
            $worst_weight = -1;
            $crit_count = 0;

            foreach ($columns as $col) {
                $cell = $this->_findCell($by_host[$hid] ?? [], $col, $color_mode);
                $cells[] = $cell;

                if ($cell['severity'] === 'crit') {
                    $crit_count++;
                    if ($worst_weight < 3) $worst_weight = 3;
                }
                elseif ($cell['severity'] === 'warn' && $worst_weight < 2) {
                    $worst_weight = 2;
                }
                elseif ($cell['severity'] === 'ok' && $worst_weight < 1) {
                    $worst_weight = 1;
                }

                if ($cell['severity'] === 'crit') $data['summary']['crit']++;
                elseif ($cell['severity'] === 'warn') $data['summary']['warn']++;
                $data['summary']['cells']++;
            }

            $data['rows'][] = [
                'hostid' => (string) $hid,
                'host' => (string) $host['name'],
                'cells' => $cells,
                'worst_weight' => $worst_weight,
                'crit_count' => $crit_count
            ];
        }

        $data['summary']['hosts'] = count($data['rows']);

        // Sort
        $sort = (int) ($this->fields_values['sort_by'] ?? 0);
        usort($data['rows'], function($a, $b) use ($sort) {
            if ($sort === 1) return strcasecmp($a['host'], $b['host']);
            if ($sort === 2) {
                if ($b['crit_count'] !== $a['crit_count']) return $b['crit_count'] - $a['crit_count'];
                return strcasecmp($a['host'], $b['host']);
            }
            if ($b['worst_weight'] !== $a['worst_weight']) return $b['worst_weight'] - $a['worst_weight'];
            if ($b['crit_count'] !== $a['crit_count']) return $b['crit_count'] - $a['crit_count'];
            return strcasecmp($a['host'], $b['host']);
        });
    }

    private function _findCell(array $items, array $col, int $color_mode): array {
        // Collect all matching items
        $matches = [];
        foreach ($items as $item) {
            if ($this->_wildcardMatch($col['pattern'], $item['name'])) {
                $matches[] = $item;
            }
        }

        if (empty($matches)) {
            return [
                'value' => null, 'units' => '', 'severity' => 'missing',
                'color' => 'transparent', 'text_color' => '#999',
                'itemid' => '', 'item_name' => '', 'matched_name' => null,
                'pattern' => $col['pattern'], 'suspicious' => false
            ];
        }

        // Scoring context
        $pattern_lower = strtolower($col['pattern']);
        $explicit_swap   = strpos($pattern_lower, 'swap')   !== false;
        $explicit_cache  = strpos($pattern_lower, 'cache')  !== false;
        $explicit_buffer = strpos($pattern_lower, 'buffer') !== false;
        $pattern_means_used = strpos($pattern_lower, 'util')  !== false
                           || strpos($pattern_lower, 'used')  !== false
                           || strpos($pattern_lower, 'usage') !== false;

        $prefer_percent = ($col['warn'] !== null && $col['warn'] > 0 && $col['warn'] <= 100)
                       || ($col['crit'] !== null && $col['crit'] > 0 && $col['crit'] <= 100);

        // Score each match
        $scored = [];
        foreach ($matches as $m) {
            $score = 0;
            $name = strtolower((string) $m['name']);
            $units = trim((string) ($m['units'] ?? ''));

            // Exact match = strongest signal
            if (strcasecmp($col['pattern'], (string) $m['name']) === 0) {
                $score += 10000;
            }

            // Prefer % when thresholds look like %
            if ($prefer_percent && ($units === '%' || strpos($units, '%') !== false)) {
                $score += 1000;
            }

            // Penalize swap/cache/buffer when pattern doesn't ask for them
            if (!$explicit_swap   && strpos($name, 'swap')   !== false) $score -= 800;
            if (!$explicit_cache  && strpos($name, 'cache')  !== false) $score -= 500;
            if (!$explicit_buffer && strpos($name, 'buffer') !== false) $score -= 500;

            // Pattern wants "used" but item is "free/available" → penalize
            if ($pattern_means_used && (strpos($name, 'free ') === 0
                                     || strpos($name, 'free') !== false && strpos($pattern_lower, 'free') === false
                                     || strpos($name, 'avail') !== false)) {
                $score -= 600;
            }

            // Prefer shorter names (more specific)
            $score -= strlen($name);

            $scored[] = ['score' => $score, 'item' => $m];
        }

        usort($scored, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        $best_item = $scored[0]['item'];

        // Detect suspicious match (contains unintended keyword)
        $best_name_lower = strtolower((string) $best_item['name']);
        $suspicious = false;
        if (!$explicit_swap   && strpos($best_name_lower, 'swap')   !== false) $suspicious = true;
        if (!$explicit_cache  && strpos($best_name_lower, 'cache')  !== false) $suspicious = true;
        if (!$explicit_buffer && strpos($best_name_lower, 'buffer') !== false) $suspicious = true;

        $value = is_numeric($best_item['lastvalue'] ?? null)
            ? (float) $best_item['lastvalue']
            : null;
        $units = (string) ($best_item['units'] ?? '');

        if ($value === null) {
            return [
                'value' => null, 'units' => $units, 'severity' => 'none',
                'color' => '#e5e7eb', 'text_color' => '#666',
                'itemid' => (string) $best_item['itemid'], 'item_name' => $best_item['name'],
                'matched_name' => (string) $best_item['name'],
                'pattern' => $col['pattern'], 'suspicious' => $suspicious
            ];
        }

        $sev = $this->_calcSeverity($value, $col);

        $ratio = 0;
        if ($col['crit'] !== null && $col['crit'] > 0) {
            $ratio = max(0, min(1, $value / $col['crit']));
        }
        if ($col['dir'] === 'down') {
            $max_ref = ($col['warn'] !== null && $col['warn'] > 0) ? $col['warn'] * 1.5 : 100;
            $ratio = 1 - max(0, min(1, $value / $max_ref));
        }

        $color = $this->_colorFor($ratio, $sev, $color_mode);
        $text_color = ($sev === 'ok' && $ratio < 0.3) ? '#166534' : '#ffffff';

        return [
            'value' => $value, 'units' => $units, 'severity' => $sev,
            'color' => $color, 'text_color' => $text_color,
            'itemid' => (string) $best_item['itemid'], 'item_name' => $best_item['name'],
            'matched_name' => (string) $best_item['name'],
            'pattern' => $col['pattern'], 'suspicious' => $suspicious
        ];
    }

    private function _calcSeverity(float $v, array $col): string {
        $warn = $col['warn'];
        $crit = $col['crit'];
        $dir = $col['dir'];

        if ($dir === 'down') {
            if ($crit !== null && $v <= $crit) return 'crit';
            if ($warn !== null && $v <= $warn) return 'warn';
            return 'ok';
        }
        if ($crit !== null && $v >= $crit) return 'crit';
        if ($warn !== null && $v >= $warn) return 'warn';
        return 'ok';
    }

    private function _colorFor(float $ratio, string $sev, int $mode): string {
        if ($mode === 2) {
            // Cool blue
            $l = 90 - $ratio * 45;
            return "hsl(210, 75%, {$l}%)";
        }
        if ($mode === 1) {
            // Traffic light
            if ($sev === 'crit') return '#ef4444';
            if ($sev === 'warn') return '#f59e0b';
            return '#22c55e';
        }
        // Auto — smooth gradient
        $hue = 138 - 138 * pow($ratio, 1.6);
        $sat = 75 + $ratio * 10;
        $l = 50 + (1 - $ratio) * 5;
        return "hsl({$hue}, {$sat}%, {$l}%)";
    }

    private function _parseColumns($text): array {
        if (is_array($text)) {
            $text = implode("\n", $text);
        }
        $text = (string) $text;

        $columns = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            $parts = explode(':', $line);
            if (count($parts) < 2) continue;

            $pattern = trim($parts[0] ?? '');
            if ($pattern === '') continue;

            $name = trim($parts[1] ?? '');
            if ($name === '') $name = $pattern;

            $warn = isset($parts[2]) && is_numeric(trim($parts[2])) ? (float) trim($parts[2]) : null;
            $crit = isset($parts[3]) && is_numeric(trim($parts[3])) ? (float) trim($parts[3]) : null;
            $dir_raw = isset($parts[4]) ? strtolower(trim($parts[4])) : '';

            // Smart direction: if not explicit, detect from pattern
            if ($dir_raw === 'up' || $dir_raw === 'down') {
                $dir = $dir_raw;
            } else {
                $lower = strtolower($pattern);
                if (strpos($lower, 'free') !== false || strpos($lower, 'avail') !== false || strpos($lower, 'idle') !== false) {
                    $dir = 'down';
                } else {
                    $dir = 'up';
                }
            }

            $columns[] = [
                'pattern' => $pattern,
                'name' => $name,
                'warn' => $warn,
                'crit' => $crit,
                'dir' => $dir
            ];
        }
        return $columns;
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
