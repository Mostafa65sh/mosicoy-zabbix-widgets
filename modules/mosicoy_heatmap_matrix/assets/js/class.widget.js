class WidgetMosicoyHeatmapMatrix extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._root = null;
        this._toolbar = null;
        this._scroll = null;
        this._footer = null;
        this._columns = [];
        this._rows = [];
        this._summary = {};
        this._truncated = false;
        this._truncatedTotal = 0;
        this._fieldsValues = {};
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
            this._columns = response.columns || [];
            this._rows = response.rows || [];
            this._summary = response.summary || {};
            this._truncated = !!response.truncated;
            this._truncatedTotal = response.truncated_total || 0;
            this._fieldsValues = response.fields_values || {};
        }
        catch (e) { console.error('Heatmap process failed:', e); }
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);
        // ⚠️ Re-query — Zabbix replaces body.innerHTML on every refresh
        this._requery();
        if (!this._root) return;
        try { this._render(); } catch (e) { console.error('Heatmap render failed:', e); }
    }

    _requery() {
        this._root = this._body.querySelector('.mosicoy-heatmap-container');
        if (!this._root) return;
        this._toolbar = this._root.querySelector('.mosicoy-heatmap-toolbar');
        this._scroll = this._root.querySelector('.mosicoy-heatmap-scroll');
        this._footer = this._root.querySelector('.mosicoy-heatmap-footer');
    }

    _render() {
        this._renderToolbar();
        this._renderGrid();
        this._renderFooter();
    }

    _renderToolbar() {
        if (!this._toolbar) return;
        const s = this._summary;
        const parts = [];
        parts.push(`<span class="hm-badge hm-badge-hosts"><b>${s.hosts || 0}</b> hosts</span>`);
        if (s.crit > 0) parts.push(`<span class="hm-badge hm-badge-crit"><b>${s.crit}</b> critical cells</span>`);
        if (s.warn > 0) parts.push(`<span class="hm-badge hm-badge-warn"><b>${s.warn}</b> warnings</span>`);
        if (this._truncated) parts.push(`<span class="hm-badge hm-badge-muted">showing ${s.hosts} of ${this._truncatedTotal}</span>`);
        this._toolbar.innerHTML = parts.join('');
    }

    _renderGrid() {
        if (!this._scroll) return;
        this._scroll.innerHTML = '';

        if (!this._rows.length || !this._columns.length) {
            this._scroll.innerHTML = '<div class="hm-empty">No data — select hosts and define columns</div>';
            return;
        }

        const compact = parseInt(this._fieldsValues.compact ?? 0) === 1;
        const showValues = parseInt(this._fieldsValues.show_values ?? 1) === 1;

        const table = document.createElement('table');
        table.className = 'hm-table' + (compact ? ' hm-compact' : '');

        // Header
        const thead = document.createElement('thead');
        const htr = document.createElement('tr');
        const cornerTh = document.createElement('th');
        cornerTh.className = 'hm-corner';
        cornerTh.textContent = 'Host';
        htr.appendChild(cornerTh);

        this._columns.forEach(col => {
            const th = document.createElement('th');
            th.className = 'hm-col-head';
            th.textContent = col.name;
            if (col.warn !== null || col.crit !== null) {
                th.title = `${col.pattern}\nWarn: ${col.warn ?? '—'}\nCrit: ${col.crit ?? '—'}\nDir: ${col.dir}`;
            }
            htr.appendChild(th);
        });

        thead.appendChild(htr);
        table.appendChild(thead);

        // Body
        const tbody = document.createElement('tbody');
        this._rows.forEach(row => {
            const tr = document.createElement('tr');

            const nameTd = document.createElement('td');
            nameTd.className = 'hm-host-cell';
            nameTd.innerHTML = `<span class="hm-host-name" title="${this._esc(row.host)}">${this._esc(row.host)}</span>`;
            tr.appendChild(nameTd);

            row.cells.forEach((cell, idx) => {
                const td = document.createElement('td');
                td.className = 'hm-cell';

                if (cell.severity === 'missing') {
                    td.classList.add('hm-empty-cell');
                    td.textContent = '·';
                    const col = this._columns[idx];
                    if (col) {
                        td.title = `No item matched pattern:\n${col.pattern}\n\nCheck the pattern in widget settings.`;
                    }
                } else if (cell.severity === 'none') {
                    td.classList.add('hm-empty-cell');
                    td.textContent = '—';
                    td.title = `${cell.matched_name || ''}\nValue: not numeric`;
                } else {
                    td.classList.add('hm-sev-' + cell.severity);
                    td.style.background = cell.color;
                    td.style.color = cell.text_color;

                    if (showValues) {
                        td.textContent = this._formatValue(cell.value, cell.units);
                    }

                    const col = this._columns[idx];
                    const suspiciousNote = cell.suspicious ? '\n⚠ This might be the wrong item — check the pattern' : '';
                    td.title = `${row.host}\n${cell.matched_name || cell.item_name}\nValue: ${this._formatValue(cell.value, cell.units)}\nSeverity: ${cell.severity.toUpperCase()}\nPattern: ${col.pattern}${suspiciousNote}`;

                    if (cell.itemid) {
                        td.onclick = () => {
                            window.open('history.php?action=showgraph&itemids[]=' + encodeURIComponent(cell.itemid), '_blank');
                        };
                        td.style.cursor = 'pointer';
                    }
                }

                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        this._scroll.appendChild(table);
    }

    _renderFooter() {
        if (!this._footer) return;
        const parts = [];
        parts.push('<span class="hm-hint">click any cell → graph</span>');

        // Show which items actually matched per column (use first row as reference)
        if (this._columns.length && this._rows.length) {
            let refRow = this._rows.find(r => r.cells.some(c => c.matched_name)) || this._rows[0];
            const matchDetails = [];
            let hasSuspicious = false;

            refRow.cells.forEach((cell, idx) => {
                const col = this._columns[idx];
                if (!col) return;
                const matchName = cell.matched_name || 'no match';
                if (cell.suspicious) hasSuspicious = true;
                matchDetails.push(`${col.name}→${matchName}${cell.suspicious ? ' ⚠' : ''}`);
            });

            const visibleText = `📊 ${this._columns.length} columns${hasSuspicious ? ' ⚠' : ''}`;
            parts.push(`<span class="hm-matches" title="${this._esc(matchDetails.join(' · '))}">${visibleText}</span>`);
        }

        this._footer.innerHTML = parts.join(' ');
    }

    _formatValue(v, units) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        const n = Number(v);
        const abs = Math.abs(n);
        const u = units || '';

        if (u === '%') return n.toFixed(0) + '%';
        if (/bps$/i.test(u)) {
            if (abs >= 1e9) return (n / 1e9).toFixed(1) + 'G';
            if (abs >= 1e6) return (n / 1e6).toFixed(1) + 'M';
            if (abs >= 1e3) return (n / 1e3).toFixed(1) + 'K';
            return n.toFixed(0);
        }
        if (/^[KMGT]?B$/i.test(u)) {
            if (abs >= 1e9) return (n / 1e9).toFixed(1) + 'G';
            if (abs >= 1e6) return (n / 1e6).toFixed(1) + 'M';
            if (abs >= 1e3) return (n / 1e3).toFixed(1) + 'K';
            return n.toFixed(0);
        }
        if (abs >= 1e9) return (n / 1e9).toFixed(1) + 'G';
        if (abs >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (abs >= 1e3) return (n / 1e3).toFixed(1) + 'K';
        if (abs >= 100) return n.toFixed(0);
        if (abs >= 10) return n.toFixed(1);
        if (abs >= 1) return n.toFixed(1);
        if (abs < 0.01 && abs > 0) return n.toExponential(1);
        return n.toFixed(2);
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
