class WidgetMosicoyStatusGrid extends CWidget {
    static SEVERITY = {
        '-1': { key: 'ok', bg: '#0d3d20', fg: '#2ecc71', bar: '#22c55e', label: 'OK' },
        '0':  { key: 'info', bg: '#3a4553', fg: '#97AAB3', bar: '#97AAB3', label: 'Not classified' },
        '1':  { key: 'info', bg: '#1a3155', fg: '#7499FF', bar: '#7499FF', label: 'Information' },
        '2':  { key: 'warn', bg: '#4a3c10', fg: '#FFC859', bar: '#FFC859', label: 'Warning' },
        '3':  { key: 'warn', bg: '#4a2c10', fg: '#FFA059', bar: '#FFA059', label: 'Average' },
        '4':  { key: 'crit', bg: '#4a1a10', fg: '#E97659', bar: '#E97659', label: 'High' },
        '5':  { key: 'crit', bg: '#4a0a0a', fg: '#E45959', bar: '#E45959', label: 'Disaster' }
    };

    onInitialize() {
        super.onInitialize();
        this._container = null;
        this._grid = null;
        this._hosts = [];
        this._summary = {};
        this._fieldsValues = {};
        this._truncated = false;
        this._truncatedTotal = 0;
    }

    onStart() {
        super.onStart();
        this._container = this._body.querySelector('.mosicoy-status-container');
    }

    hasPadding() {
        return this.getViewMode() !== ZBX_WIDGET_VIEW_MODE_HIDDEN_HEADER;
    }

    setEditMode() {
        super.setEditMode();
    }

    processUpdateResponse(response) {
        this._hosts = response.hosts || [];
        this._summary = response.summary || {};
        this._fieldsValues = response.fields_values || {};
        this._truncated = !!response.truncated;
        this._truncatedTotal = response.truncated_total || 0;
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);

        // ⚠️ Zabbix replaces this._body.innerHTML on every refresh!
        // Re-query the container EVERY time — never cache DOM refs.
        this._container = this._body.querySelector('.mosicoy-status-container');
        if (!this._container) return;

        try { this._render(); } catch (e) { console.error('mosicoy_status_grid render failed:', e); }
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    _severityMeta(sev, hasProblems) {
        const key = hasProblems ? String(Math.max(0, sev)) : '-1';
        return WidgetMosicoyStatusGrid.SEVERITY[key] || WidgetMosicoyStatusGrid.SEVERITY['-1'];
    }

    _render() {
        if (!this._container) return;
        const cellStyle = parseInt(this._fieldsValues.cell_style ?? 1);
        const hostStyleClass = ['cell-compact', 'cell-comfortable', 'cell-detailed'][cellStyle] || 'cell-comfortable';

        this._container.innerHTML = '';
        this._container.classList.remove('cell-compact', 'cell-comfortable', 'cell-detailed');
        this._container.classList.add(hostStyleClass);

        const header = document.createElement('div');
        header.className = 'mosicoy-header';

        const s = this._summary;
        const total = s.total || 0;
        const ok = s.ok || 0;
        const warn = s.warn || 0;
        const crit = s.crit || 0;

        let pillsHtml = '';
        if (total > 0) {
            pillsHtml =
                `<span class="mosicoy-pill pill-ok"><span class="mosicoy-pill-dot"></span>${ok} OK</span>` +
                `<span class="mosicoy-pill pill-warn"><span class="mosicoy-pill-dot"></span>${warn} WARN</span>` +
                `<span class="mosicoy-pill pill-crit"><span class="mosicoy-pill-dot"></span>${crit} CRIT</span>`;
        }

        header.innerHTML =
            `<div class="mosicoy-header-left">` +
                `<div class="mosicoy-header-title">Server Health</div>` +
                `<div class="mosicoy-header-count">${total} hosts</div>` +
            `</div>` +
            `<div class="mosicoy-header-right">${pillsHtml}</div>`;

        this._container.appendChild(header);

        if (!this._hosts.length) {
            const empty = document.createElement('div');
            empty.className = 'mosicoy-empty';
            empty.textContent = 'No hosts selected or no problems detected';
            this._container.appendChild(empty);
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'mosicoy-grid';
        this._container.appendChild(grid);

        this._hosts.forEach(host => {
            const meta = this._severityMeta(host.severity, host.has_problems);
            const cell = document.createElement('div');
            cell.className = 'mosicoy-cell sev-' + meta.key;
            if (host.has_problems && host.severity >= 4) cell.classList.add('pulse');
            cell.style.setProperty('--cell-bg', meta.bg);
            cell.style.setProperty('--cell-fg', meta.fg);
            cell.style.setProperty('--cell-bar', meta.bar);

            cell.title = this._buildTooltip(host, meta);

            let badgeHtml = host.has_problems ? `<div class="mosicoy-cell-badge">${host.problem_count}</div>` : '';
            let metaHtml = host.has_problems ? `<div class="mosicoy-cell-meta">${this._esc(meta.label)}</div>` : `<div class="mosicoy-cell-meta mosicoy-cell-ok">All OK</div>`;

            cell.innerHTML =
                `<div class="mosicoy-cell-bar"></div>` +
                `<div class="mosicoy-cell-body">` +
                    `<div class="mosicoy-cell-top"><div class="mosicoy-cell-name">${this._esc(host.name || host.host)}</div>${badgeHtml}</div>` +
                    metaHtml +
                `</div>`;

            cell.onclick = () => {
                const url = `zabbix.php?action=problem.view&filter_set=1&filter_hostids[0]=${encodeURIComponent(host.hostid)}`;
                window.open(url, '_blank');
            };

            grid.appendChild(cell);
        });

        if (this._truncated) {
            const note = document.createElement('div');
            note.className = 'mosicoy-truncated';
            note.textContent = `Showing ${this._hosts.length} of ${this._truncatedTotal} hosts (increase "Max hosts" in settings)`;
            this._container.appendChild(note);
        }
    }

    _buildTooltip(host, meta) {
        const lines = [host.name || host.host];
        if (host.host && host.host !== host.name) lines.push(`(${host.host})`);
        if (!host.has_problems) {
            lines.push('', '✓ All OK');
        } else {
            lines.push('', `${meta.label} · ${host.problem_count} problem${host.problem_count > 1 ? 's' : ''}`);
            if (host.triggers && host.triggers.length) {
                lines.push('');
                host.triggers.forEach(t => {
                    const sevMeta = this._severityMeta(t.severity, true);
                    const age = this._age(t.lastchange);
                    lines.push(`• [${sevMeta.label}] ${t.description} (${age})`);
                });
                if (host.problem_count > 3) lines.push(`... and ${host.problem_count - 3} more`);
            }
        }
        return lines.join('\n');
    }

    _age(lastchange) {
        if (!lastchange) return '';
        const sec = Math.max(0, Math.floor(Date.now() / 1000) - lastchange);
        if (sec < 60) return sec + 's';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h';
        return Math.floor(sec / 86400) + 'd';
    }

    onResize() { super.onResize(); }
    onDestroy() { if (typeof super.onDestroy === 'function') super.onDestroy(); }
}
