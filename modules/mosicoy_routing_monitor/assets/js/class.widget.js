class WidgetMosicoyRoutingMonitor extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._root = null;
        this._toolbar = null;
        this._list = null;
        this._footer = null;
        this._peers = [];
        this._summary = {};
        this._debug = [];
        this._fieldsValues = {};
        this._protoFilter = 'all'; // all | bgp | ospf
        this._statusFilter = 'all'; // all | ok | problem
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
            this._peers = response.peers || [];
            this._summary = response.summary || {};
            this._debug = response.debug || [];
            this._fieldsValues = response.fields_values || {};
        }
        catch (e) { console.error('Routing Monitor process failed:', e); }
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);
        this._requery();
        if (!this._root) return;
        try { this._render(); } catch (e) { console.error('Routing Monitor render failed:', e); }
    }

    _requery() {
        this._root = this._body.querySelector('.mosicoy-rm-container');
        if (!this._root) return;
        this._toolbar = this._root.querySelector('.mosicoy-rm-toolbar');
        this._list = this._root.querySelector('.mosicoy-rm-list');
        this._footer = this._root.querySelector('.mosicoy-rm-footer');
    }

    _render() {
        this._renderToolbar();
        this._renderFilters();
        this._renderList();
        this._renderFooter();
    }

    _renderToolbar() {
        if (!this._toolbar) return;
        const s = this._summary;
        const parts = [];

        parts.push(`<span class="rm-badge rm-badge-total">` +
            `<span class="rm-badge-icon">⬤</span>` +
            `<b>${s.peers || 0}</b> peers` +
        `</span>`);

        if (s.established > 0) {
            parts.push(`<span class="rm-badge rm-badge-ok"><b>${s.established}</b> established</span>`);
        }
        if (s.problematic > 0) {
            parts.push(`<span class="rm-badge rm-badge-crit"><b>${s.problematic}</b> problematic</span>`);
        }

        this._toolbar.innerHTML = parts.join('');
    }

    _renderFilters() {
        // Insert filters bar after toolbar
        let filtersBar = this._root.querySelector('.rm-filters');
        if (!filtersBar) {
            filtersBar = document.createElement('div');
            filtersBar.className = 'rm-filters';
            this._toolbar.parentNode.insertBefore(filtersBar, this._toolbar.nextSibling);
        }

        const s = this._summary;
        const bgpCount = s.bgp || 0;
        const ospfCount = s.ospf || 0;
        const okCount = s.established || 0;
        const problemCount = s.problematic || 0;

        // Protocol filter row
        const protoRow = document.createElement('div');
        protoRow.className = 'rm-frow';
        protoRow.innerHTML = '<span class="rm-flabel">Protocol:</span>';

        const protoButtons = [
            { id: 'all', label: 'All', count: s.peers || 0, cls: 'rmf-all' },
            { id: 'bgp', label: 'BGP', count: bgpCount, cls: 'rmf-bgp' },
            { id: 'ospf', label: 'OSPF', count: ospfCount, cls: 'rmf-ospf' }
        ];

        protoButtons.forEach(b => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rm-filter ' + b.cls + (this._protoFilter === b.id ? ' active' : '');
            btn.innerHTML = `<span class="rmf-label">${b.label}</span><span class="rmf-count">${b.count}</span>`;
            btn.onclick = () => {
                this._protoFilter = b.id;
                this._render();
            };
            protoRow.appendChild(btn);
        });
        filtersBar.innerHTML = '';
        filtersBar.appendChild(protoRow);

        // Status filter row
        const statusRow = document.createElement('div');
        statusRow.className = 'rm-frow';
        statusRow.innerHTML = '<span class="rm-flabel">Status:</span>';

        const statusButtons = [
            { id: 'all', label: 'All', count: s.peers || 0, cls: 'rms-all' },
            { id: 'ok', label: '✓ Established', count: okCount, cls: 'rms-ok' },
            { id: 'problem', label: '⚠ Problem', count: problemCount, cls: 'rms-problem' }
        ];

        statusButtons.forEach(b => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rm-filter ' + b.cls + (this._statusFilter === b.id ? ' active' : '');
            btn.innerHTML = `<span class="rmf-label">${b.label}</span><span class="rmf-count">${b.count}</span>`;
            btn.onclick = () => {
                this._statusFilter = b.id;
                this._render();
            };
            statusRow.appendChild(btn);
        });
        filtersBar.appendChild(statusRow);
    }

    _peerMatchesFilters(peer) {
        if (this._protoFilter !== 'all' && peer.protocol.toLowerCase() !== this._protoFilter) return false;
        if (this._statusFilter === 'ok' && peer.severity !== 'ok') return false;
        if (this._statusFilter === 'problem' && peer.severity === 'ok') return false;
        return true;
    }

    _renderList() {
        if (!this._list) return;
        this._list.innerHTML = '';

        if (!this._peers.length) {
            const empty = document.createElement('div');
            empty.className = 'rm-empty';

            if (this._debug && this._debug.length) {
                empty.innerHTML =
                    '<div class="rm-empty-icon">🔍</div>' +
                    '<div class="rm-empty-title">No routing peers found</div>' +
                    '<div class="rm-empty-sub">Check the debug info below or verify your template has BGP/OSPF items</div>';

                const dbg = document.createElement('pre');
                dbg.style.cssText = 'text-align:left;font-size:10px;opacity:0.65;max-width:100%;overflow:auto;padding:8px;background:rgba(128,128,128,0.1);border-radius:6px;margin-top:12px;white-space:pre-wrap;word-break:break-all;width:100%;';
                dbg.textContent = this._debug.join('\n');
                empty.appendChild(dbg);
            } else {
                empty.innerHTML =
                    '<div class="rm-empty-icon">🛰️</div>' +
                    '<div class="rm-empty-title">No BGP/OSPF peers</div>' +
                    '<div class="rm-empty-sub">Pick hosts with routing protocol monitoring</div>';
            }

            this._list.appendChild(empty);
            return;
        }

        const filtered = this._peers.filter(p => this._peerMatchesFilters(p));

        if (!filtered.length) {
            const empty = document.createElement('div');
            empty.className = 'rm-empty-filtered';
            empty.textContent = 'No peers match the current filter';
            this._list.appendChild(empty);
            return;
        }

        const showHost = parseInt(this._fieldsValues.show_host ?? 1) === 1;

        filtered.forEach(peer => {
            const row = document.createElement('div');
            row.className = 'rm-row rm-sev-' + peer.severity;

            const protoIcon = peer.protocol === 'BGP' ? '🌐' : '🔗';
            const stateIcon = this._stateIcon(peer.severity);

            // Info bits with icons
            const infoBits = [];
            if (peer.remote_as) infoBits.push({ icon: '🏷', text: `AS${peer.remote_as}` });
            if (peer.uptime_sec !== null && peer.uptime_sec > 0) {
                infoBits.push({ icon: '⏱', text: this._formatUptime(peer.uptime_sec) });
            }
            if (peer.prefixes !== null) {
                infoBits.push({ icon: '📊', text: `${this._fmtNum(peer.prefixes)} prefixes` });
            }

            row.innerHTML =
                `<div class="rm-row-head">` +
                    `<div class="rm-identity">` +
                        `<span class="rm-proto-icon rm-proto-${peer.protocol.toLowerCase()}">${protoIcon}</span>` +
                        `<span class="rm-proto rm-proto-${peer.protocol.toLowerCase()}">${peer.protocol}</span>` +
                        `<span class="rm-peer">${this._esc(peer.peer)}</span>` +
                        (showHost ? `<span class="rm-host">${this._esc(peer.host)}</span>` : '') +
                        (peer.alias ? `<span class="rm-alias">${this._esc(peer.alias)}</span>` : '') +
                    `</div>` +
                    `<span class="rm-state rm-state-${peer.severity}">` +
                        `<span class="rm-state-icon">${stateIcon}</span>` +
                        `${this._esc(peer.state_name)}` +
                    `</span>` +
                `</div>` +
                (infoBits.length
                    ? `<div class="rm-info">` +
                        infoBits.map(b => `<span class="rm-info-chip"><span class="rm-info-icon">${b.icon}</span>${this._esc(b.text)}</span>`).join('') +
                      `</div>`
                    : '');

            row.title = this._tooltip(peer);

            if (peer.itemid) {
                row.onclick = () => {
                    window.open('history.php?action=showgraph&itemids[]=' + encodeURIComponent(peer.itemid), '_blank');
                };
                row.style.cursor = 'pointer';
            }

            this._list.appendChild(row);
        });
    }

    _stateIcon(severity) {
        if (severity === 'ok') return '✓';
        if (severity === 'warn') return '⚠';
        if (severity === 'crit') return '✕';
        return '?';
    }

    _renderFooter() {
        if (!this._footer) return;
        const filtered = this._peers.filter(p => this._peerMatchesFilters(p));
        const total = filtered.length;
        const all = this._peers.length;

        this._footer.innerHTML =
            `<span class="rm-hint">` +
                (total < all ? `Showing <b>${total}</b> of ${all} peers · ` : '') +
                `click any peer → graph` +
            `</span>`;
    }

    _formatUptime(sec) {
        if (sec === null || sec === undefined) return '';
        const n = Math.abs(Number(sec));
        if (n < 60) return n + 's';
        if (n < 3600) return Math.floor(n / 60) + 'm';
        if (n < 86400) {
            const h = Math.floor(n / 3600);
            const m = Math.floor((n % 3600) / 60);
            return h + 'h' + (m > 0 ? ' ' + m + 'm' : '');
        }
        const d = Math.floor(n / 86400);
        const h = Math.floor((n % 86400) / 3600);
        return d + 'd' + (h > 0 ? ' ' + h + 'h' : '');
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

    _tooltip(peer) {
        const lines = [];
        lines.push(`${peer.protocol}: ${peer.peer}`);
        if (peer.alias) lines.push(`Alias: ${peer.alias}`);
        lines.push(`Host: ${peer.host}`);
        lines.push('');
        lines.push(`State: ${peer.state_name}`);
        if (peer.remote_as) lines.push(`Remote AS: ${peer.remote_as}`);
        if (peer.uptime_sec !== null) lines.push(`Uptime: ${this._formatUptime(peer.uptime_sec)}`);
        if (peer.prefixes !== null) lines.push(`Prefixes: ${peer.prefixes}`);
        if (peer.msg_rcvd !== null) lines.push(`Messages received: ${this._fmtNum(peer.msg_rcvd)}`);
        if (peer.msg_sent !== null) lines.push(`Messages sent: ${this._fmtNum(peer.msg_sent)}`);
        lines.push('');
        lines.push('Click → graph');
        return lines.join('\n');
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
