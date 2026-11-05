class WidgetMosicoyTopTalkers extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._root = null;
        this._toolbar = null;
        this._list = null;
        this._footer = null;
        this._talkers = [];
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
            this._talkers = response.talkers || [];
            this._summary = response.summary || {};
            this._debug = response.debug || [];
            this._fieldsValues = response.fields_values || {};
        }
        catch (e) { console.error('Top Talkers process failed:', e); }
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);
        this._requery();
        if (!this._root) return;
        try { this._render(); } catch (e) { console.error('Top Talkers render failed:', e); }
    }

    _requery() {
        this._root = this._body.querySelector('.mosicoy-tt-container');
        if (!this._root) return;
        this._toolbar = this._root.querySelector('.mosicoy-tt-toolbar');
        this._list = this._root.querySelector('.mosicoy-tt-list');
        this._footer = this._root.querySelector('.mosicoy-tt-footer');
    }

    _render() {
        this._renderToolbar();
        this._renderList();
        this._renderFooter();
    }

    _renderToolbar() {
        if (!this._toolbar) return;
        const s = this._summary;
        const totalRx = this._fmtRate(s.total_rx || 0);
        const totalTx = this._fmtRate(s.total_tx || 0);

        this._toolbar.innerHTML =
            `<span class="tt-badge tt-badge-count"><b>${this._talkers.length}</b> shown</span>` +
            `<span class="tt-badge tt-badge-hosts"><b>${s.hosts || 0}</b> hosts</span>` +
            `<span class="tt-badge tt-badge-rx">↓ ${totalRx}</span>` +
            `<span class="tt-badge tt-badge-tx">↑ ${totalTx}</span>`;
    }

    _renderList() {
        if (!this._list) return;
        this._list.innerHTML = '';

        if (!this._talkers.length) {
            const empty = document.createElement('div');
            empty.className = 'tt-empty';

            const title = document.createElement('div');
            title.textContent = 'No interfaces with traffic';
            title.style.fontWeight = '700';
            title.style.marginBottom = '10px';
            empty.appendChild(title);

            if (this._debug && this._debug.length) {
                const dbg = document.createElement('pre');
                dbg.style.cssText = 'text-align:left;font-size:10px;opacity:0.7;padding:8px;background:rgba(128,128,128,0.1);border-radius:6px;margin:0;white-space:pre-wrap;';
                dbg.textContent = this._debug.join('\n');
                empty.appendChild(dbg);
            }
            this._list.appendChild(empty);
            return;
        }

        const showHost = parseInt(this._fieldsValues.show_host ?? 1) === 1;
        const showUtil = parseInt(this._fieldsValues.show_utilization ?? 1) === 1;
        const unitMode = parseInt(this._fieldsValues.display_unit ?? 0);

        this._talkers.forEach((t, idx) => {
            const rank = idx + 1;
            const rankClass = rank <= 3 ? `tt-rank-${rank}` : 'tt-rank-other';

            const item = document.createElement('div');
            item.className = 'tt-item';
            item.title = this._tooltip(t);

            const pct = Math.round(t.relative * 100);
            const utilPct = t.utilization * 100;
            const utilClass = utilPct >= 80 ? 'tt-util-crit' : (utilPct >= 60 ? 'tt-util-warn' : 'tt-util-ok');

            // Severity (from utilization)
            const sevClass = utilPct >= 80 ? 'tt-sev-crit' : (utilPct >= 60 ? 'tt-sev-warn' : 'tt-sev-ok');

            item.innerHTML =
                `<div class="tt-rank ${rankClass}">${rank}</div>` +
                `<div class="tt-content">` +
                    `<div class="tt-header">` +
                        `<div class="tt-identity">` +
                            (showHost ? `<span class="tt-host">${this._esc(t.host)}</span>` : '') +
                            `<span class="tt-port">${this._esc(t.port_short)}</span>` +
                            (t.alias ? `<span class="tt-alias">${this._esc(t.alias)}</span>` : '') +
                        `</div>` +
                        `<div class="tt-metrics">` +
                            `<span class="tt-rx">↓ ${this._fmtRate(t.rx)}</span>` +
                            `<span class="tt-tx">↑ ${this._fmtRate(t.tx)}</span>` +
                        `</div>` +
                    `</div>` +
                    `<div class="tt-bar-wrap ${sevClass}">` +
                        `<div class="tt-bar" style="width:${pct}%"></div>` +
                    `</div>` +
                    (showUtil && t.speed > 0
                        ? `<div class="tt-util ${utilClass}">` +
                              `<span>${utilPct.toFixed(1)}% of ${this._fmtRate(t.speed)}</span>` +
                          `</div>`
                        : '') +
                `</div>`;

            if (t.itemid) {
                item.onclick = () => {
                    window.open('history.php?action=showgraph&itemids[]=' + encodeURIComponent(t.itemid), '_blank');
                };
                item.style.cursor = 'pointer';
            }

            this._list.appendChild(item);
        });
    }

    _renderFooter() {
        if (!this._footer) return;
        this._footer.innerHTML = '<span class="tt-hint">click any row → graph</span>';
    }

    _tooltip(t) {
        const lines = [`${t.host} · ${t.port}`];
        if (t.alias) lines.push(`Alias: ${t.alias}`);
        lines.push('');
        lines.push(`Status: ${t.is_up ? 'UP' : 'DOWN'}`);
        if (t.speed > 0) lines.push(`Link speed: ${this._fmtRate(t.speed)}`);
        lines.push(`↓ RX: ${this._fmtRate(t.rx)}`);
        lines.push(`↑ TX: ${this._fmtRate(t.tx)}`);
        if (t.speed > 0) lines.push(`Utilization: ${(t.utilization * 100).toFixed(1)}%`);
        return lines.join('\n');
    }

    _fmtRate(bps) {
        const n = Number(bps) || 0;
        if (n === 0) return '0';
        const abs = Math.abs(n);
        if (abs >= 1e12) return (n / 1e12).toFixed(2) + ' Tbps';
        if (abs >= 1e9) return (n / 1e9).toFixed(2) + ' Gbps';
        if (abs >= 1e6) return (n / 1e6).toFixed(1) + ' Mbps';
        if (abs >= 1e3) return (n / 1e3).toFixed(0) + ' Kbps';
        return n.toFixed(0) + ' bps';
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
