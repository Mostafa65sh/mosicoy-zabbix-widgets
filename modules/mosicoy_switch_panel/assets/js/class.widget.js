class WidgetMosicoySwitchPanel extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._root = null;
        this._hosts = [];
        this._summary = {};
        this._fieldsValues = {};
        this._sparklineDebug = [];
        this._activeFilter = 'all'; // all | up | down | errors
    }

    onStart() {
        try { super.onStart(); } catch (e) { /* ignore */ }
        this._requery();
    }

    hasPadding() {
        return this.getViewMode() !== ZBX_WIDGET_VIEW_MODE_HIDDEN_HEADER;
    }

    setEditMode() {
        super.setEditMode();
    }

    processUpdateResponse(response) {
        try {
            this._hosts = response.hosts || [];
            this._summary = response.summary || {};
            this._fieldsValues = response.fields_values || {};
            this._sparklineDebug = response.sparkline_debug || [];
        }
        catch (e) { console.error('Switch Panel process failed:', e); }
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);
        this._requery();
        if (!this._root) return;
        try { this._render(); } catch (e) { console.error('Switch Panel render failed:', e); }
    }

    _requery() {
        this._root = this._body.querySelector('.mosicoy-switch-panel-container');
    }

    _render() {
        if (!this._root) return;
        this._root.innerHTML = '';

        if (!this._hosts.length) {
            const empty = document.createElement('div');
            empty.className = 'sp-empty';
            empty.textContent = 'No ports to display';
            this._root.appendChild(empty);
            return;
        }

        const compact = parseInt(this._fieldsValues.compact ?? 0) === 1;
        if (compact) this._root.classList.add('sp-compact');
        else this._root.classList.remove('sp-compact');

        // Filters toolbar
        this._root.appendChild(this._renderFilters());

        this._hosts.forEach(host => {
            this._root.appendChild(this._renderHost(host));
        });

        this._root.appendChild(this._renderLegend());
    }

    _renderFilters() {
        const bar = document.createElement('div');
        bar.className = 'sp-filters';

        const filters = [
            { id: 'all', label: 'All', count: this._summary.ports || 0, cls: 'spf-all' },
            { id: 'up', label: 'Up', count: this._summary.up || 0, cls: 'spf-up' },
            { id: 'down', label: 'Down', count: this._summary.down || 0, cls: 'spf-down' },
            { id: 'errors', label: 'Errors', count: this._summary.errors || 0, cls: 'spf-err' }
        ];

        filters.forEach(f => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sp-filter ' + f.cls + (this._activeFilter === f.id ? ' active' : '');
            btn.innerHTML =
                `<span class="spf-label">${f.label}</span>` +
                `<span class="spf-count">${f.count}</span>`;
            btn.onclick = () => {
                this._activeFilter = f.id;
                this._render();
            };
            bar.appendChild(btn);
        });

        return bar;
    }

    _portMatchesFilter(port) {
        if (this._activeFilter === 'all') return true;
        if (this._activeFilter === 'up') return port.status === 'up';
        if (this._activeFilter === 'down') return port.status === 'down';
        if (this._activeFilter === 'errors') return port.has_errors;
        return true;
    }

    _renderHost(host) {
        const section = document.createElement('div');
        section.className = 'sp-host';

        const filteredPorts = host.ports.filter(p => this._portMatchesFilter(p));
        const total = host.summary.total || 1;
        const upPct = (host.summary.up / total) * 100;
        const downPct = (host.summary.down / total) * 100;

        const header = document.createElement('div');
        header.className = 'sp-host-header';
        header.innerHTML =
            `<div class="sp-host-title">` +
                `<span class="sp-host-name">${this._esc(host.name)}</span>` +
                `<span class="sp-host-summary">` +
                    `<span class="sp-sum-total">${total} ports</span>` +
                    `<span class="sp-sum-up">${host.summary.up} up</span>` +
                    `<span class="sp-sum-down">${host.summary.down} down</span>` +
                `</span>` +
            `</div>` +
            `<div class="sp-host-bar" title="${host.summary.up} up · ${host.summary.down} down">` +
                `<div class="sp-host-bar-up" style="width:${upPct}%"></div>` +
                `<div class="sp-host-bar-down" style="width:${downPct}%"></div>` +
            `</div>`;
        section.appendChild(header);

        if (!filteredPorts.length) {
            const noMatch = document.createElement('div');
            noMatch.className = 'sp-no-match';
            noMatch.textContent = 'No ports match the current filter';
            section.appendChild(noMatch);
            return section;
        }

        const groups = { uplink: [], sfp: [], copper: [], management: [] };
        filteredPorts.forEach(p => {
            if (groups[p.category]) groups[p.category].push(p);
        });

        const labels = {
            uplink: { icon: '⚡', name: 'Uplink' },
            sfp: { icon: '🔌', name: 'SFP' },
            copper: { icon: '🔗', name: 'Copper' },
            management: { icon: '⚙', name: 'Management' }
        };

        for (const cat of ['uplink', 'sfp', 'copper', 'management']) {
            const ports = groups[cat];
            if (!ports.length) continue;

            const groupEl = document.createElement('div');
            groupEl.className = 'sp-group sp-group-' + cat;

            const gh = document.createElement('div');
            gh.className = 'sp-group-header';
            gh.innerHTML =
                `<span class="sp-group-label">${labels[cat].icon} ${labels[cat].name}</span>` +
                `<span class="sp-group-count">${ports.length}</span>`;
            groupEl.appendChild(gh);

            const grid = document.createElement('div');
            grid.className = 'sp-grid sp-grid-' + cat;
            ports.forEach(port => grid.appendChild(this._renderTile(port)));
            groupEl.appendChild(grid);

            section.appendChild(groupEl);
        }

        return section;
    }

    _renderTile(port) {
        const tile = document.createElement('div');
        tile.className = 'sp-tile sp-sev-' + port.severity;
        if (port.status === 'down') tile.classList.add('sp-down');
        if (port.category === 'uplink') tile.classList.add('sp-uplink');
        if (port.has_errors) tile.classList.add('sp-has-errors');

        const rxTxt = port.rx !== null ? this._fmtRate(port.rx) : '0';
        const txTxt = port.tx !== null ? this._fmtRate(port.tx) : '0';
        const portNum = this._shortNum(port.short_name);

        const ledClass = port.status === 'up'
            ? 'sp-led sp-led-' + port.severity
            : 'sp-led sp-led-off';

        // Error badge
        let errorBadge = '';
        if (port.has_errors) {
            const totalErr = (port.errors || 0) + (port.discards || 0);
            const badgeCls = port.errors >= 10 ? 'sp-err-badge sp-err-high' : 'sp-err-badge sp-err-low';
            errorBadge = `<span class="${badgeCls}" title="${port.errors} errors, ${port.discards} discards">${this._fmtNum(totalErr)}</span>`;
        }

        // Speed tag
        const speedTag = port.speed_label
            ? `<span class="sp-speed-tag">${port.speed_label}</span>`
            : '';

        // Direction indicator
        let dirIcon = '';
        if (port.status === 'up') {
            if (port.direction === 'rx') dirIcon = '<span class="sp-dir sp-dir-rx" title="RX dominant">↓</span>';
            else if (port.direction === 'tx') dirIcon = '<span class="sp-dir sp-dir-tx" title="TX dominant">↑</span>';
            else if (port.direction === 'balanced') dirIcon = '<span class="sp-dir sp-dir-bal" title="Balanced">⇅</span>';
        }

        const utilPct = Math.round(port.utilization * 100);

        let body = '';
        if (port.status === 'up') {
            body =
                `<div class="sp-rates">` +
                    `<span class="sp-rx" title="RX">↓${rxTxt}</span>` +
                    `<span class="sp-tx" title="TX">↑${txTxt}</span>` +
                `</div>` +
                `<div class="sp-bar-row">` +
                    `<div class="sp-bar"><div class="sp-bar-fill sp-fill-${port.severity}" style="width:${utilPct}%"></div></div>` +
                    `<span class="sp-util-pct">${utilPct}%</span>` +
                `</div>` +
                this._renderSparkline(port);
        } else {
            body = `<div class="sp-offline">offline</div>`;
        }

        tile.innerHTML =
            `<div class="sp-tile-top">` +
                `<span class="sp-port-num">${this._esc(portNum)}</span>` +
                `<span class="sp-tile-top-right">` +
                    speedTag +
                    dirIcon +
                    errorBadge +
                    `<span class="${ledClass}"></span>` +
                `</span>` +
            `</div>` +
            body;

        tile.title = this._tooltip(port);

        if (port.itemid) {
            tile.onclick = () => {
                window.open('history.php?action=showgraph&itemids[]=' + encodeURIComponent(port.itemid), '_blank');
            };
        }

        return tile;
    }

    _renderSparkline(port) {
        const showSpark = parseInt(this._fieldsValues.show_sparklines ?? 1) === 1;
        if (!showSpark) return '';

        const sl = port.sparkline;
        if (!sl || !Array.isArray(sl) || sl.length < 2) return '';

        const w = 100, h = 14;
        const min = Math.min(...sl);
        const max = Math.max(...sl);
        const range = max - min || 1;
        const n = sl.length;

        const points = [];
        sl.forEach((v, i) => {
            const x = (i / (n - 1)) * w;
            const y = h - ((v - min) / range) * (h - 2) - 1;
            points.push([x, y]);
        });

        let path = `M${points[0][0].toFixed(1)},${points[0][1].toFixed(1)}`;
        for (let i = 0; i < points.length - 1; i++) {
            const p0 = points[i - 1] || points[i];
            const p1 = points[i];
            const p2 = points[i + 1];
            const p3 = points[i + 2] || p2;
            const cp1x = p1[0] + (p2[0] - p0[0]) / 6;
            const cp1y = p1[1] + (p2[1] - p0[1]) / 6;
            const cp2x = p2[0] - (p3[0] - p1[0]) / 6;
            const cp2y = p2[1] - (p3[1] - p1[1]) / 6;
            path += ` C${cp1x.toFixed(1)},${cp1y.toFixed(1)} ${cp2x.toFixed(1)},${cp2y.toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
        }

        const areaPath = path + ` L ${w},${h} L 0,${h} Z`;

        const first = sl[0];
        const last = sl[sl.length - 1];
        const trendClass = last > first * 1.1 ? 'spark-up' : (last < first * 0.9 ? 'spark-down' : 'spark-flat');

        const gradId = 'sg' + Math.random().toString(36).slice(2, 10);

        return `<div class="sp-spark">` +
            `<svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" class="sp-spark-svg ${trendClass}">` +
                `<defs><linearGradient id="${gradId}" x1="0" y1="0" x2="0" y2="1">` +
                    `<stop offset="0%" stop-color="currentColor" stop-opacity="0.3"/>` +
                    `<stop offset="100%" stop-color="currentColor" stop-opacity="0"/>` +
                `</linearGradient></defs>` +
                `<path d="${areaPath}" fill="url(#${gradId})" class="sp-spark-area"/>` +
                `<path d="${path}" class="sp-spark-line"/>` +
            `</svg>` +
        `</div>`;
    }

    _renderLegend() {
        const legend = document.createElement('div');
        legend.className = 'sp-legend';
        legend.innerHTML =
            `<span class="sp-legend-item"><span class="sp-legend-dot sp-legend-ok"></span>up &lt; 60%</span>` +
            `<span class="sp-legend-item"><span class="sp-legend-dot sp-legend-warn"></span>busy 60-80%</span>` +
            `<span class="sp-legend-item"><span class="sp-legend-dot sp-legend-crit"></span>critical &gt; 80%</span>` +
            `<span class="sp-legend-item"><span class="sp-legend-dot sp-legend-off"></span>down</span>`;

        if (this._sparklineDebug && this._sparklineDebug.length) {
            const hasSparklines = this._sparklineDebug.some(d => d.indexOf('filled=') === 0 && d.indexOf('filled=0') !== 0);
            if (!hasSparklines) {
                const dbg = document.createElement('span');
                dbg.className = 'sp-debug-info';
                dbg.style.cssText = 'font-size:9px;opacity:0.5;margin-left:12px;';
                dbg.title = this._sparklineDebug.join('\n');
                dbg.textContent = '⚠ sparkline: ' + this._sparklineDebug.join(' · ');
                legend.appendChild(dbg);
            }
        }

        return legend;
    }

    _shortNum(shortName) {
        return shortName
            .replace(/^(Gi|Te|Twe|Fo|Hu|Fa|Et|Po|Lo|Mg|BE|Xe|Eth|sfp)/i, '')
            .trim() || shortName;
    }

    _tooltip(port) {
        const lines = [port.name];
        if (port.alias) lines.push(`Alias: ${port.alias}`);
        lines.push('');
        lines.push(`Status: ${port.status.toUpperCase()}`);
        if (port.speed > 0) lines.push(`Speed: ${this._fmtRate(port.speed)}bps (${port.speed_label})`);

        if (port.status === 'up') {
            lines.push(`↓ RX: ${this._fmtRate(port.rx || 0)}bps`);
            lines.push(`↑ TX: ${this._fmtRate(port.tx || 0)}bps`);
            lines.push(`Utilization: ${(port.utilization * 100).toFixed(1)}%`);

            if (port.direction === 'rx') lines.push(`Dominant: RX (receive-heavy)`);
            else if (port.direction === 'tx') lines.push(`Dominant: TX (send-heavy)`);
            else if (port.direction === 'balanced') lines.push(`Traffic: balanced`);
        }

        if (port.has_errors) {
            lines.push('');
            lines.push(`⚠ Errors: ${port.errors} · Discards: ${port.discards}`);
            Object.entries(port.error_breakdown || {}).forEach(([k, v]) => {
                if (v > 0) lines.push(`  · ${k}: ${v}`);
            });
        }

        lines.push('');
        lines.push('Click → graph');
        return lines.join('\n');
    }

    _fmtRate(v) {
        if (v === null || v === undefined) return '0';
        const n = Number(v);
        if (isNaN(n) || n === 0) return '0';
        if (n < 1000) return n.toFixed(0);
        if (n < 1e6) return (n / 1e3).toFixed(0) + 'K';
        if (n < 1e9) return (n / 1e6).toFixed(1) + 'M';
        if (n < 1e12) return (n / 1e9).toFixed(1) + 'G';
        return (n / 1e12).toFixed(2) + 'T';
    }

    _fmtNum(v) {
        const n = Number(v) || 0;
        if (n === 0) return '0';
        const abs = Math.abs(n);
        if (abs >= 1e9) return (n / 1e9).toFixed(1) + 'G';
        if (abs >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (abs >= 1e3) return (n / 1e3).toFixed(1) + 'K';
        return n.toFixed(0);
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    onResize() {
        try { super.onResize(); } catch (e) { /* ignore */ }
    }

    onDestroy() {
        try { if (typeof super.onDestroy === 'function') super.onDestroy(); } catch (e) { /* ignore */ }
    }
}
