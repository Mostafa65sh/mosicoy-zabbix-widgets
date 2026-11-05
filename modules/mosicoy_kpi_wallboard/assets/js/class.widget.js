class WidgetMosicoyKpiWallboard extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._container = null;
        this._items = [];
        this._fieldsValues = {};
        this._cardMap = new Map();
        this._animFrames = new Map();
    }

    onStart() {
        super.onStart();
        this._container = this._body.querySelector('.mosicoy-kpi-container');
    }

    hasPadding() {
        return this.getViewMode() !== ZBX_WIDGET_VIEW_MODE_HIDDEN_HEADER;
    }

    setEditMode() {
        super.setEditMode();
    }

    processUpdateResponse(response) {
        this._items = response.items || [];
        this._fieldsValues = response.fields_values || {};
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);

        // ⚠️ Zabbix replaces this._body.innerHTML on every refresh!
        // Re-query the container EVERY time — never cache DOM refs.
        this._container = this._body.querySelector('.mosicoy-kpi-container');
        if (!this._container) return;
        this._cardMap = new Map();
        this._animFrames.forEach(f => cancelAnimationFrame(f));
        this._animFrames.clear();

        try { this._render(); } catch (e) { console.error('mosicoy_kpi_wallboard render failed:', e); }
    }

    _render() {
        if (!this._container) return;

        const styleMode = parseInt(this._fieldsValues.cell_style ?? 1);
        const styleClass = ['kpi-compact', 'kpi-comfortable', 'kpi-detailed'][styleMode] || 'kpi-comfortable';

        if (!this._container.classList.contains(styleClass)) {
            this._container.classList.remove('kpi-compact', 'kpi-comfortable', 'kpi-detailed');
            this._container.classList.add(styleClass);
        }

        if (!this._items.length) {
            this._container.innerHTML = '<div class="kpi-empty">No items matched — select hosts and items in widget settings</div>';
            this._cardMap.clear();
            return;
        }

        const emptyEl = this._container.querySelector('.kpi-empty');
        if (emptyEl) emptyEl.remove();

        let grid = this._container.querySelector('.kpi-grid');
        if (!grid) {
            this._container.innerHTML = '';
            grid = document.createElement('div');
            grid.className = 'kpi-grid';
            this._container.appendChild(grid);
        }

        // Sort if requested
        let items = this._items.slice();
        if (parseInt(this._fieldsValues.sort_by_severity ?? 1) === 1) {
            items.sort((a, b) => {
                const sa = this._getSeverityWeight(a);
                const sb = this._getSeverityWeight(b);
                if (sa !== sb) return sb - sa;
                // Then by value descending (bigger = worse)
                const va = Number(a.value) || 0;
                const vb = Number(b.value) || 0;
                return vb - va;
            });
        }

        // Reorder DOM to match sorted order
        const seen = new Set();
        items.forEach((item, idx) => {
            seen.add(item.itemid);
            let card = this._cardMap.get(item.itemid);
            if (!card) {
                card = this._createKpiCard(item);
                this._cardMap.set(item.itemid, card);
            }
            // Append to move if not in correct position
            const currentChild = grid.children[idx];
            if (currentChild !== card) {
                if (currentChild) {
                    grid.insertBefore(card, currentChild);
                } else {
                    grid.appendChild(card);
                }
            }
            this._updateKpiCard(card, item);
        });

        this._cardMap.forEach((card, itemid) => {
            if (!seen.has(itemid)) {
                card.remove();
                this._cardMap.delete(itemid);
            }
        });
    }

    _createKpiCard(item) {
        const card = document.createElement('div');
        card.className = 'kpi-card';
        card.setAttribute('data-itemid', item.itemid);

        card.innerHTML =
            '<div class="kpi-top">' +
                '<div class="kpi-host"></div>' +
                '<div class="kpi-top-right">' +
                    '<span class="kpi-badge"></span>' +
                    '<span class="kpi-status-dot"></span>' +
                '</div>' +
            '</div>' +
            '<div class="kpi-item-name"></div>' +
            '<div class="kpi-value-row">' +
                '<span class="kpi-value" data-target="0">0</span>' +
                '<span class="kpi-unit"></span>' +
            '</div>' +
            '<div class="kpi-progress"><div class="kpi-progress-fill"></div></div>' +
            '<div class="kpi-meta-row">' +
                '<span class="kpi-delta"></span>' +
                '<span class="kpi-spark-wrap">' +
                    '<span class="kpi-spark"></span>' +
                    '<span class="kpi-spark-label">1H</span>' +
                '</span>' +
            '</div>' +
            '<div class="kpi-stats"></div>' +
            '<div class="kpi-footer">' +
                '<span class="kpi-time"></span>' +
                '<span class="kpi-graph-hint">→ graph</span>' +
            '</div>';

        card.onclick = () => {
            window.open('history.php?action=showgraph&itemids[]=' + encodeURIComponent(item.itemid), '_blank');
        };

        return card;
    }

    _cleanItemName(name, host) {
        if (!name) return '';
        let clean = name;
        clean = clean.replace(/^\{HOST\.NAME\}\s*[:/]?\s*/i, '');
        if (host) {
            const esc = host.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            clean = clean.replace(new RegExp('^' + esc + '\\s*[:/]\\s*', 'i'), '');
        }
        return clean.trim() || name;
    }

    _getSeverity(item) {
        if (item.no_data) return { level: 'neutral', label: 'NO DATA', weight: 0 };

        const v = Number(item.value) || 0;
        const dir = item.direction || 'up';

        // Compute effective thresholds
        let warn = item.warn_threshold;
        let crit = item.crit_threshold;

        // Fallback to % defaults if not set
        const isPercent = (item.units || '').trim() === '%';
        if (warn === null || warn === undefined) {
            warn = isPercent ? 65 : null;
        }
        if (crit === null || crit === undefined) {
            crit = isPercent ? 85 : null;
        }

        if (warn === null && crit === null) {
            return { level: 'neutral', label: 'NORMAL', weight: 1 };
        }

        if (dir === 'down') {
            // Lower is worse (e.g., free memory)
            if (crit !== null && v <= crit) return { level: 'crit', label: 'CRITICAL', weight: 3 };
            if (warn !== null && v <= warn) return { level: 'warn', label: 'WARNING', weight: 2 };
        } else {
            // Higher is worse (e.g., CPU usage)
            if (crit !== null && v >= crit) return { level: 'crit', label: 'CRITICAL', weight: 3 };
            if (warn !== null && v >= warn) return { level: 'warn', label: 'WARNING', weight: 2 };
        }

        return { level: 'ok', label: 'HEALTHY', weight: 1 };
    }

    _getTrend(item) {
        // Detect trend from sparkline: compare first half to second half
        const sl = item.sparkline;
        if (!sl || sl.length < 4) return null;

        const mid = Math.floor(sl.length / 2);
        let firstSum = 0, secondSum = 0;
        for (let i = 0; i < mid; i++) firstSum += sl[i];
        for (let i = mid; i < sl.length; i++) secondSum += sl[i];

        const firstAvg = firstSum / mid;
        const secondAvg = secondSum / (sl.length - mid);

        if (firstAvg === 0) return secondAvg > 0 ? { dir: 'up', pct: 100 } : { dir: 'flat', pct: 0 };

        const change = ((secondAvg - firstAvg) / Math.abs(firstAvg)) * 100;

        if (change > 5) return { dir: 'up', pct: change };
        if (change < -5) return { dir: 'down', pct: change };
        return { dir: 'flat', pct: change };
    }

    _getSeverityWeight(item) {
        return this._getSeverity(item).weight;
    }

    _getStats(sparkline) {
        if (!sparkline || !sparkline.length) return null;
        let min = Infinity, max = -Infinity, sum = 0;
        for (const v of sparkline) {
            if (v < min) min = v;
            if (v > max) max = v;
            sum += v;
        }
        return { min, max, avg: sum / sparkline.length };
    }

    _ago(ts) {
        if (!ts) return '';
        const sec = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (sec < 5) return 'just now';
        if (sec < 60) return sec + 's ago';
        if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
        return Math.floor(sec / 86400) + 'd ago';
    }

    _updateKpiCard(card, item) {
        const sev = this._getSeverity(item);
        const isPercent = (item.units || '').trim() === '%';

        card.title = (item.full_name || item.name) + (item.host ? '\n' + item.host : '');

        // Severity classes
        card.classList.remove('kpi-sev-ok', 'kpi-sev-warn', 'kpi-sev-crit', 'kpi-sev-neutral');
        card.classList.add('kpi-sev-' + sev.level);

        // Host name
        card.querySelector('.kpi-host').textContent = item.host || '—';

        // Badge
        const badgeEl = card.querySelector('.kpi-badge');
        badgeEl.className = 'kpi-badge kpi-badge-' + sev.level;
        badgeEl.textContent = sev.label;

        // Dot
        const dotEl = card.querySelector('.kpi-status-dot');
        if (sev.level !== 'neutral' && sev.level !== 'ok') {
            dotEl.style.display = 'inline-block';
            dotEl.className = 'kpi-status-dot kpi-dot-' + sev.level;
        } else if (sev.level === 'ok') {
            dotEl.style.display = 'inline-block';
            dotEl.className = 'kpi-status-dot kpi-dot-ok';
        } else {
            dotEl.style.display = 'none';
        }

        // Item name
        card.querySelector('.kpi-item-name').textContent = this._cleanItemName(item.name, item.host);

        // Value
        const valueEl = card.querySelector('.kpi-value');
        const unitEl = card.querySelector('.kpi-unit');
        if (item.no_data) {
            valueEl.textContent = '—';
            unitEl.textContent = '';
        } else {
            const fmt = this._format(item.value, item.units);
            valueEl.textContent = fmt.number;
            unitEl.textContent = fmt.unit || '';
            this._animateValue(valueEl, item.itemid, item.value, item.units);
        }

        // Progress bar (only for %)
        const progressEl = card.querySelector('.kpi-progress');
        const progressFill = card.querySelector('.kpi-progress-fill');
        if (isPercent && !item.no_data) {
            progressEl.style.display = 'block';
            const pct = Math.max(0, Math.min(100, item.value));
            progressFill.style.width = pct + '%';
            progressFill.className = 'kpi-progress-fill kpi-fill-' + sev.level;
        } else {
            progressEl.style.display = 'none';
        }

        // Delta
        const deltaEl = card.querySelector('.kpi-delta');
        if (parseInt(this._fieldsValues.show_delta ?? 1) === 1 && !item.no_data && item.prev_value !== null) {
            deltaEl.innerHTML = this._renderDelta(item);
        } else {
            deltaEl.innerHTML = '';
        }

        // Sparkline
        const sparkEl = card.querySelector('.kpi-spark');
        const sparkLabelEl = card.querySelector('.kpi-spark-label');
        const hasSpark = parseInt(this._fieldsValues.show_sparkline ?? 1) === 1 && item.sparkline && item.sparkline.length > 1;
        if (hasSpark) {
            sparkEl.innerHTML = this._renderSparkline(item.sparkline, item);
            sparkLabelEl.style.display = 'inline';
        } else {
            sparkEl.innerHTML = '';
            sparkLabelEl.style.display = 'none';
        }

        // Stats row (min / avg / max)
        const statsEl = card.querySelector('.kpi-stats');
        if (parseInt(this._fieldsValues.show_stats ?? 1) === 1 && hasSpark) {
            const stats = this._getStats(item.sparkline);
            if (stats) {
                const fMin = this._format(stats.min, item.units);
                const fAvg = this._format(stats.avg, item.units);
                const fMax = this._format(stats.max, item.units);
                const u = fMin.unit || '';
                statsEl.innerHTML =
                    `<span class="kpi-stat"><span class="kpi-stat-label">min</span><span class="kpi-stat-val">${this._esc(fMin.number)}${u ? '<span class="kpi-stat-u">'+this._esc(u)+'</span>' : ''}</span></span>` +
                    `<span class="kpi-stat"><span class="kpi-stat-label">avg</span><span class="kpi-stat-val">${this._esc(fAvg.number)}${u ? '<span class="kpi-stat-u">'+this._esc(u)+'</span>' : ''}</span></span>` +
                    `<span class="kpi-stat"><span class="kpi-stat-label">max</span><span class="kpi-stat-val">${this._esc(fMax.number)}${u ? '<span class="kpi-stat-u">'+this._esc(u)+'</span>' : ''}</span></span>`;
                statsEl.style.display = 'flex';
            } else {
                statsEl.style.display = 'none';
            }
        } else {
            statsEl.style.display = 'none';
        }

        // Time ago
        const timeEl = card.querySelector('.kpi-time');
        if (item.lastclock) {
            timeEl.textContent = this._ago(item.lastclock);
        } else {
            timeEl.textContent = '';
        }

        card.classList.toggle('kpi-stale', item.stale);
    }

    _animateValue(el, itemid, targetValue, units) {
        if (this._animFrames.has(itemid)) {
            cancelAnimationFrame(this._animFrames.get(itemid));
        }

        const fromValue = parseFloat(el.getAttribute('data-target') || '0');
        const toValue = Number(targetValue);
        if (isNaN(toValue)) {
            el.textContent = '—';
            return;
        }

        const start = isFinite(fromValue) && Math.abs(toValue - fromValue) < Math.abs(toValue) * 100 ? fromValue : 0;
        const duration = 900;
        const startTime = performance.now();
        const self = this;

        const step = (now) => {
            const t = Math.min(1, (now - startTime) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            const current = start + (toValue - start) * eased;
            el.textContent = self._format(current, units).number;

            if (t < 1) {
                self._animFrames.set(itemid, requestAnimationFrame(step));
            } else {
                el.textContent = self._format(toValue, units).number;
                el.setAttribute('data-target', toValue);
                self._animFrames.delete(itemid);
            }
        };

        this._animFrames.set(itemid, requestAnimationFrame(step));
    }

    _renderDelta(item) {
        const trend = this._getTrend(item);

        // Prefer 1h avg comparison (more meaningful than single prev)
        let refValue = item.prev_value;
        let refLabel = 'vs prev';
        if (item.spark_stats && item.spark_stats.avg !== undefined) {
            refValue = item.spark_stats.avg;
            refLabel = 'vs 1h avg';
        }

        if (refValue === null || refValue === undefined) {
            return '<span class="delta-neutral">─</span> <span class="delta-label">no ref</span>';
        }

        const delta = item.value - refValue;
        if (Math.abs(delta) < 0.0001) {
            return '<span class="delta-neutral">─</span> <span class="delta-label">stable</span>';
        }

        const isUp = delta > 0;
        const arrow = isUp ? '▲' : '▼';
        const cls = isUp ? 'delta-up' : 'delta-down';
        const pct = refValue !== 0 ? (delta / Math.abs(refValue)) * 100 : null;
        const fmt = this._format(Math.abs(delta), item.units);
        const valTxt = fmt.number + (fmt.unit ? ' ' + fmt.unit : '');
        const pctTxt = pct !== null ? ` (${Math.abs(pct).toFixed(1)}%)` : '';

        let trendBadge = '';
        if (trend && trend.dir === 'up' && trend.pct > 10) {
            trendBadge = ' <span class="trend-badge trend-up">↗ RISING</span>';
        } else if (trend && trend.dir === 'down' && trend.pct < -10) {
            trendBadge = ' <span class="trend-badge trend-down">↘ DROPPING</span>';
        }

        return '<span class="' + cls + '">' + arrow + ' ' + this._esc(valTxt) + pctTxt + '</span>' +
               ' <span class="delta-label">' + refLabel + '</span>' + trendBadge;
    }

    _renderSparkline(values, item) {
        const w = 100, h = 22;
        const min = Math.min(...values);
        const max = Math.max(...values);
        const range = max - min || 1;
        const n = values.length;

        const points = [];
        values.forEach((v, i) => {
            const x = (i / (n - 1)) * w;
            const y = h - ((v - min) / range) * (h - 4) - 2;
            points.push([x, y]);
        });

        // Smooth Catmull-Rom -> Bezier
        const linePath = this._smoothPath(points);
        const areaPath = linePath + ` L ${w},${h} L 0,${h} Z`;

        const lastVal = values[n - 1];
        const firstVal = values[0];
        const trend = lastVal >= firstVal ? 'spark-up' : 'spark-down';
        const lastY = points[n - 1][1];

        const lastIdx = n - 1;
        const minIdx = values.indexOf(min);
        const maxIdx = values.indexOf(max);
        const minPt = points[minIdx];
        const maxPt = points[maxIdx];

        let markers = '';
        if (minIdx !== lastIdx && minIdx !== 0) {
            markers += `<circle cx="${minPt[0].toFixed(2)}" cy="${minPt[1].toFixed(2)}" r="1.8" class="spark-min" />`;
        }
        if (maxIdx !== lastIdx && maxIdx !== 0) {
            markers += `<circle cx="${maxPt[0].toFixed(2)}" cy="${maxPt[1].toFixed(2)}" r="1.8" class="spark-max" />`;
        }

        // Threshold line (if we have custom crit threshold and it's in range)
        let thresholdLine = '';
        const crit = item && item.crit_threshold;
        if (crit !== null && crit !== undefined && crit >= min && crit <= max) {
            const ty = h - ((crit - min) / range) * (h - 4) - 2;
            thresholdLine = `<line x1="0" y1="${ty.toFixed(2)}" x2="${w}" y2="${ty.toFixed(2)}" class="spark-thresh" />`;
        }

        const gradId = 'sg' + Math.random().toString(36).slice(2, 10);

        return `<svg class="spark-svg ${trend}" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">` +
                `<defs><linearGradient id="${gradId}" x1="0" y1="0" x2="0" y2="1">` +
                `<stop offset="0%" stop-color="currentColor" stop-opacity="0.28"/>` +
                `<stop offset="100%" stop-color="currentColor" stop-opacity="0"/>` +
                `</linearGradient></defs>` +
                thresholdLine +
                `<path d="${areaPath}" class="spark-area" fill="url(#${gradId})" />` +
                `<path d="${linePath}" />` +
                markers +
                `<circle cx="${w}" cy="${lastY.toFixed(2)}" r="2.2" />` +
                `</svg>`;
    }

    _smoothPath(points) {
        if (points.length < 2) {
            return points.length ? `M${points[0][0]},${points[0][1]}` : '';
        }
        let d = `M${points[0][0].toFixed(2)},${points[0][1].toFixed(2)}`;
        for (let i = 0; i < points.length - 1; i++) {
            const p0 = points[i - 1] || points[i];
            const p1 = points[i];
            const p2 = points[i + 1];
            const p3 = points[i + 2] || p2;
            const cp1x = p1[0] + (p2[0] - p0[0]) / 6;
            const cp1y = p1[1] + (p2[1] - p0[1]) / 6;
            const cp2x = p2[0] - (p3[0] - p1[0]) / 6;
            const cp2y = p2[1] - (p3[1] - p1[1]) / 6;
            d += ` C${cp1x.toFixed(2)},${cp1y.toFixed(2)} ${cp2x.toFixed(2)},${cp2y.toFixed(2)} ${p2[0].toFixed(2)},${p2[1].toFixed(2)}`;
        }
        return d;
    }

    _format(value, units) {
        if (value === null || value === undefined || isNaN(value)) return { number: '—', unit: '' };
        const n = Number(value);
        const abs = Math.abs(n);
        const u = units || '';

        if (u === '%') return { number: n.toFixed(1), unit: '%' };
        if (/^[KMGT]?B$/i.test(u)) {
            if (abs >= 1e12) return { number: (n / 1e12).toFixed(2), unit: 'TB' };
            if (abs >= 1e9) return { number: (n / 1e9).toFixed(2), unit: 'GB' };
            if (abs >= 1e6) return { number: (n / 1e6).toFixed(2), unit: 'MB' };
            if (abs >= 1e3) return { number: (n / 1e3).toFixed(2), unit: 'KB' };
            return { number: n.toFixed(0), unit: 'B' };
        }
        if (/^[KMGT]?B\/s$/i.test(u)) {
            if (abs >= 1e12) return { number: (n / 1e12).toFixed(2), unit: 'TB/s' };
            if (abs >= 1e9) return { number: (n / 1e9).toFixed(2), unit: 'GB/s' };
            if (abs >= 1e6) return { number: (n / 1e6).toFixed(2), unit: 'MB/s' };
            if (abs >= 1e3) return { number: (n / 1e3).toFixed(2), unit: 'KB/s' };
            return { number: n.toFixed(2), unit: 'B/s' };
        }
        if (/bps$/i.test(u)) {
            if (abs >= 1e9) return { number: (n / 1e9).toFixed(2), unit: 'Gbps' };
            if (abs >= 1e6) return { number: (n / 1e6).toFixed(2), unit: 'Mbps' };
            if (abs >= 1e3) return { number: (n / 1e3).toFixed(2), unit: 'Kbps' };
            return { number: n.toFixed(0), unit: 'bps' };
        }

        let num = n, unit = u;
        if (abs >= 1e9) { num = n / 1e9; unit = 'G'; }
        else if (abs >= 1e6) { num = n / 1e6; unit = 'M'; }
        else if (abs >= 1e3) { num = n / 1e3; unit = 'K'; }
        else if (abs >= 100) return { number: n.toFixed(0), unit: u };
        else if (abs >= 10) return { number: n.toFixed(1), unit: u };
        else if (abs >= 1) return { number: n.toFixed(2), unit: u };
        else if (abs < 0.01 && abs > 0) return { number: n.toExponential(1), unit: u };
        else return { number: n.toFixed(3), unit: u };

        return { number: num.toFixed(1), unit: unit };
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    onResize() { super.onResize(); }

    onDestroy() {
        this._animFrames.forEach(frame => cancelAnimationFrame(frame));
        this._animFrames.clear();
        this._cardMap.clear();
        if (typeof super.onDestroy === 'function') super.onDestroy();
    }
}

// bugfix: variable typo fix
class _Fix {}
