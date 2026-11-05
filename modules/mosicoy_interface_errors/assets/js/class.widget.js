class WidgetMosicoyInterfaceErrors extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._root = null;
        this._toolbar = null;
        this._list = null;
        this._footer = null;
        this._interfaces = [];
        this._summary = {};
        this._debug = [];
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
            this._interfaces = response.interfaces || [];
            this._summary = response.summary || {};
            this._debug = response.debug || [];
            this._fieldsValues = response.fields_values || {};
        }
        catch (e) { console.error('Interface Errors process failed:', e); }
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);
        this._requery();
        if (!this._root) return;
        try { this._render(); } catch (e) { console.error('Interface Errors render failed:', e); }
    }

    _requery() {
        this._root = this._body.querySelector('.mosicoy-ie-container');
        if (!this._root) return;
        this._toolbar = this._root.querySelector('.mosicoy-ie-toolbar');
        this._list = this._root.querySelector('.mosicoy-ie-list');
        this._footer = this._root.querySelector('.mosicoy-ie-footer');
    }

    _render() {
        this._renderToolbar();
        this._renderList();
        this._renderFooter();
    }

    _renderToolbar() {
        if (!this._toolbar) return;
        const s = this._summary;

        let parts = [];
        parts.push(`<span class="ie-badge ie-badge-count"><b>${s.interfaces || 0}</b> interfaces</span>`);

        if (s.with_errors > 0) {
            parts.push(`<span class="ie-badge ie-badge-errors"><b>${s.with_errors}</b> with errors</span>`);
        }
        if (s.with_discards > 0) {
            parts.push(`<span class="ie-badge ie-badge-discards"><b>${s.with_discards}</b> with discards</span>`);
        }
        if (s.total_errors > 0) {
            parts.push(`<span class="ie-badge ie-badge-total"><b>${this._fmtNum(s.total_errors)}</b> total errors</span>`);
        }

        this._toolbar.innerHTML = parts.join('');
    }

    _renderList() {
        if (!this._list) return;
        this._list.innerHTML = '';

        if (!this._interfaces.length) {
            const empty = document.createElement('div');
            empty.className = 'ie-empty';

            if (this._summary.interfaces === 0) {
                empty.innerHTML =
                    '<div class="ie-empty-glow"></div>' +
                    '<div class="ie-empty-icon">✓</div>' +
                    '<div class="ie-empty-title">All clean</div>' +
                    '<div class="ie-empty-sub">No errors or discards detected</div>';
            } else {
                empty.innerHTML =
                    '<div class="ie-empty-glow" style="background:radial-gradient(circle, rgba(99,102,241,0.15) 0%, transparent 70%)"></div>' +
                    '<div class="ie-empty-icon" style="color:#6366f1">i</div>' +
                    '<div class="ie-empty-title" style="color:#6366f1">No matching interfaces</div>' +
                    '<div class="ie-empty-sub">Check your filter settings</div>';
            }

            this._list.appendChild(empty);
            return;
        }

        const showHost = parseInt(this._fieldsValues.show_host ?? 1) === 1;
        const critThreshold = parseInt(this._fieldsValues.crit_threshold ?? 10) || 10;

        this._interfaces.forEach(iface => {
            const row = document.createElement('div');
            row.className = 'ie-row ie-sev-' + iface.severity;

            // Progress bar relative to threshold
            const totalIssues = iface.total_errors + iface.total_discards;
            const maxRef = Math.max(critThreshold * 3, totalIssues, 1);
            const barPct = Math.min(100, (totalIssues / maxRef) * 100);
            const barClass = iface.severity === 'crit' ? 'ie-bar-crit'
                : (iface.severity === 'warn' ? 'ie-bar-warn' : 'ie-bar-info');

            // Breakdown tags
            let breakdownHtml = '';
            if (iface.breakdown && iface.breakdown.length) {
                breakdownHtml = iface.breakdown
                    .filter(b => b.value > 0)
                    .map(b => {
                        const cls = b.type === 'error' ? 'ie-tag-err' : 'ie-tag-disc';
                        return `<span class="ie-tag ${cls}">${this._esc(this._shortMetric(b.metric))}: <b>${this._fmtNum(b.value)}</b></span>`;
                    })
                    .join('');
            }

            row.innerHTML =
                '<div class="ie-row-head">' +
                    '<div class="ie-identity">' +
                        '<span class="ie-sev-icon"></span>' +
                        (showHost ? `<span class="ie-host">${this._esc(iface.host)}</span>` : '') +
                        `<span class="ie-port">${this._esc(iface.port_short)}</span>` +
                        (iface.alias ? `<span class="ie-alias">${this._esc(iface.alias)}</span>` : '') +
                        `<span class="ie-status ${iface.is_up ? 'ie-status-up' : 'ie-status-down'}">` +
                            `${iface.is_up ? 'UP' : 'DOWN'}` +
                        '</span>' +
                    '</div>' +
                    '<div class="ie-totals">' +
                        (iface.total_errors > 0
                            ? '<div class="ie-total-item ie-total-err">' +
                                  '<div class="ie-total-label">errors</div>' +
                                  `<div class="ie-total-value">${this._fmtNum(iface.total_errors)}</div>` +
                              '</div>'
                            : '') +
                        (iface.total_discards > 0
                            ? '<div class="ie-total-item ie-total-disc">' +
                                  '<div class="ie-total-label">discards</div>' +
                                  `<div class="ie-total-value">${this._fmtNum(iface.total_discards)}</div>` +
                              '</div>'
                            : '') +
                    '</div>' +
                '</div>' +
                '<div class="ie-sev-bar">' +
                    `<div class="ie-sev-bar-fill ${barClass}" style="width:${barPct}%"></div>` +
                '</div>' +
                (breakdownHtml ? `<div class="ie-breakdown">${breakdownHtml}</div>` : '');

            if (iface.itemid) {
                row.onclick = () => {
                    window.open('history.php?action=showgraph&itemids[]=' + encodeURIComponent(iface.itemid), '_blank');
                };
                row.style.cursor = 'pointer';
            }

            this._list.appendChild(row);
        });
    }

    _renderFooter() {
        if (!this._footer) return;
        this._footer.innerHTML = '<span class="ie-hint">click any row → graph</span>';
    }

    _shortMetric(m) {
        return m
            .replace('In ', '↓ ')
            .replace('Out ', '↑ ')
            .replace(' errors', '')
            .replace(' discards', 'disc')
            .replace(' collisions', 'coll');
    }

    _fmtNum(v) {
        const n = Number(v) || 0;
        if (n === 0) return '0';
        const abs = Math.abs(n);
        if (abs >= 1e9) return (n / 1e9).toFixed(2) + 'G';
        if (abs >= 1e6) return (n / 1e6).toFixed(2) + 'M';
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
