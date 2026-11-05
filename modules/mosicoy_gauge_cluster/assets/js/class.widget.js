class WidgetMosicoyGaugeCluster extends CWidget {
    onInitialize() {
        super.onInitialize();
        this._container = null;
        this._grid = null;
        this._items = [];
        this._fieldsValues = {};
        this._cardMap = new Map();
    }

    onStart() {
        super.onStart();
        this._container = this._body.querySelector('.mosicoy-gauge-container');
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
        this._container = this._body.querySelector('.mosicoy-gauge-container');
        if (!this._container) return;
        this._grid = null;
        this._cardMap = new Map();

        try { this._render(); } catch (e) { console.error('mosicoy_gauge_cluster render failed:', e); }
    }

    _render() {
        if (!this._container) return;
        if (!this._grid) {
            this._container.innerHTML = '';
            const style = parseInt(this._fieldsValues.cell_style ?? 1);
            const cls = ['style-compact', 'style-comfortable', 'style-detailed'][style] || 'style-comfortable';
            this._container.classList.add(cls);

            this._grid = document.createElement('div');
            this._grid.className = 'mosicoy-gauge-grid';
            this._container.appendChild(this._grid);
        }

        if (!this._items.length) {
            this._grid.innerHTML = '<div class="mosicoy-gauge-empty">No items matched — select hosts and items</div>';
            this._cardMap.clear();
            return;
        }

        const emptyEl = this._grid.querySelector('.mosicoy-gauge-empty');
        if (emptyEl) emptyEl.remove();

        const seen = new Set();

        this._items.forEach(item => {
            seen.add(item.itemid);
            let card = this._cardMap.get(item.itemid);
            if (!card) {
                card = this._createGaugeCard(item);
                this._cardMap.set(item.itemid, card);
                this._grid.appendChild(card);
            }
            this._updateGaugeCard(card, item);
        });

        this._cardMap.forEach((card, itemid) => {
            if (!seen.has(itemid)) {
                card.remove();
                this._cardMap.delete(itemid);
            }
        });
    }

    _createGaugeCard(item) {
        const card = document.createElement('div');
        card.className = 'mosicoy-gauge-card';
        card.setAttribute('data-itemid', item.itemid);

        card.innerHTML =
            '<div class="gauge-svg-wrap">' +
                '<svg class="gauge-svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet">' +
                    '<circle class="gauge-track" cx="50" cy="50" r="42" />' +
                    '<circle class="gauge-fill" cx="50" cy="50" r="42" />' +
                '</svg>' +
                '<div class="gauge-center">' +
                    '<div class="gauge-value">—</div>' +
                    '<div class="gauge-unit"></div>' +
                '</div>' +
            '</div>' +
            '<div class="gauge-name" title=""></div>' +
            '<div class="gauge-host"></div>' +
            '<div class="gauge-delta"></div>' +
            '<div class="gauge-status"></div>';

        card.onclick = () => {
            window.open('history.php?action=showgraph&itemids[]=' + encodeURIComponent(item.itemid), '_blank');
        };

        return card;
    }

    _updateGaugeCard(card, item) {
        const arcLength = 2 * Math.PI * 42 * 0.75;
        const circumference = 2 * Math.PI * 42;

        const ratio = item.no_data ? 0 : Math.max(0, Math.min(1, item.value / (item.max || 100)));
        const color = this._colorForRatio(ratio);
        const progress = ratio * arcLength;

        const fill = card.querySelector('.gauge-fill');
        if (fill) {
            fill.style.strokeDasharray = progress + ' ' + circumference;
            fill.style.stroke = color;
            fill.style.filter = (!item.no_data && ratio > 0.05) ? 'drop-shadow(0 0 4px ' + color + ')' : 'none';
        }

        const valueEl = card.querySelector('.gauge-value');
        const unitEl = card.querySelector('.gauge-unit');

        if (item.no_data) {
            valueEl.textContent = '—';
            unitEl.textContent = '';
        } else {
            const fmt = this._formatNumber(item.value, item.units);
            valueEl.textContent = fmt.number;
            unitEl.textContent = fmt.unit || '';
        }

        const nameEl = card.querySelector('.gauge-name');
        nameEl.textContent = item.name;
        nameEl.title = item.full_name || item.name;

        card.querySelector('.gauge-host').textContent = item.host;

        const deltaEl = card.querySelector('.gauge-delta');
        if (this._showDelta() && !item.no_data && item.prev_value !== null) {
            deltaEl.innerHTML = this._renderDelta(item);
        } else {
            deltaEl.innerHTML = '';
        }

        const statusEl = card.querySelector('.gauge-status');
        if (item.stale) {
            statusEl.textContent = 'STALE';
            statusEl.className = 'gauge-status status-stale';
        } else {
            statusEl.textContent = 'LIVE';
            statusEl.className = 'gauge-status status-live';
        }

        card.classList.toggle('gauge-no-data', item.no_data);
        card.classList.toggle('gauge-stale', item.stale);
        card.classList.toggle('gauge-critical', ratio >= 0.9 && !item.no_data);
    }

    _renderDelta(item) {
        const delta = item.value - item.prev_value;
        if (Math.abs(delta) < 0.0001) return '<span class="delta-neutral">no change</span>';
        const isUp = delta > 0;
        const arrow = isUp ? '▲' : '▼';
        const cls = isUp ? 'delta-up' : 'delta-down';
        const fmt = this._formatNumber(Math.abs(delta), item.units);
        return '<span class="' + cls + '">' + arrow + ' ' + this._esc(fmt.number + (fmt.unit ? ' ' + fmt.unit : '')) + '</span>';
    }

    _isDark() {
        return document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-theme') || document.documentElement.getAttribute('theme') === 'dark';
    }

    _colorForRatio(ratio) {
        const t = Math.max(0, Math.min(1, ratio));
        const isDark = this._isDark();
        const mode = this._colorMode();

        if (mode === 3) return isDark ? '#60a5fa' : '#3b82f6';
        if (mode === 2) {
            const hue = 210 + t * 60;
            return 'hsl(' + hue + ', 85%, ' + (isDark ? 65 : 50) + '%)';
        }
        if (mode === 1) {
            if (t < 0.3) return isDark ? '#60a5fa' : '#2563eb';
            if (t < 0.55) return isDark ? '#fcd34d' : '#d97706';
            if (t < 0.75) return isDark ? '#fb923c' : '#ea580c';
            if (t < 0.9) return isDark ? '#f87171' : '#dc2626';
            return isDark ? '#ef4444' : '#b91c1c';
        }

        if (t < 0.5) {
            const hue = 142 - (142 - 48) * (t / 0.5);
            return 'hsl(' + hue + ', 85%, ' + (isDark ? 60 : 45) + '%)';
        } else {
            const hue = 48 - 48 * ((t - 0.5) / 0.5);
            return 'hsl(' + hue + ', 85%, ' + (isDark ? 60 : 50) + '%)';
        }
    }

    _formatNumber(value, units) {
        if (value === null || value === undefined || isNaN(value)) return { number: '—', unit: '' };
        const n = Number(value);
        const abs = Math.abs(n);
        const u = units || '';

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
        if (u.indexOf('%') !== -1) return { number: n.toFixed(1), unit: '%' };
        if (u === 's' || u === 'sec') {
            if (abs >= 86400) return { number: (n / 86400).toFixed(1), unit: 'd' };
            if (abs >= 3600) return { number: (n / 3600).toFixed(1), unit: 'h' };
            if (abs >= 60) return { number: (n / 60).toFixed(1), unit: 'm' };
            return { number: n.toFixed(1), unit: 's' };
        }

        let num = n, unit = u;
        if (abs >= 1e9) { num = n / 1e9; unit = 'G' + u; }
        else if (abs >= 1e6) { num = n / 1e6; unit = 'M' + u; }
        else if (abs >= 1e3) { num = n / 1e3; unit = 'K' + u; }
        else if (abs >= 100) return { number: n.toFixed(0), unit: u };
        else if (abs >= 10) return { number: n.toFixed(1), unit: u };
        else if (abs >= 1) return { number: n.toFixed(2), unit: u };
        else if (abs < 0.01 && abs > 0) return { number: n.toExponential(2), unit: u };
        else return { number: n.toFixed(3), unit: u };
        return { number: num.toFixed(2), unit: unit };
    }

    _colorMode() { return parseInt(this._fieldsValues.color_mode ?? 0); }
    _showDelta() { return parseInt(this._fieldsValues.show_delta ?? 1) === 1; }
    _esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    onResize() { super.onResize(); }
    onDestroy() { this._cardMap.clear(); if (typeof super.onDestroy === 'function') super.onDestroy(); }
}
