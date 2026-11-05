class WidgetMosicoySankeyFlow extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._chart = null;
        this._container = null;
        this._footer = null;
        this._nodes = [];
        this._links = [];
        this._summary = {};
        this._fieldsValues = {};
        this._truncated = false;
        this._truncatedTotal = 0;
    }

    onStart() {
        super.onStart();
        this._container = this._body.querySelector('.mosicoy-sankey-chart');
        this._footer = this._body.querySelector('.mosicoy-sankey-footer');
    }

    hasPadding() {
        return this.getViewMode() !== ZBX_WIDGET_VIEW_MODE_HIDDEN_HEADER;
    }

    setEditMode() {
        super.setEditMode();
    }

    processUpdateResponse(response) {
        this._nodes = response.nodes || [];
        this._links = response.links || [];
        this._summary = response.summary || {};
        this._fieldsValues = response.fields_values || {};
        this._truncated = !!response.truncated;
        this._truncatedTotal = response.truncated_total || 0;
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);

        // ⚠️ super.setContents() replaces body.innerHTML. Re-query + reinit.
        const newContainer = this._body.querySelector('.mosicoy-sankey-chart');
        if (!newContainer) return;

        if (this._container !== newContainer) {
            if (this._chart) { try { this._chart.dispose(); } catch(e) {} this._chart = null; }
            this._container = newContainer;
        this._footer = this._body.querySelector('.mosicoy-sankey-footer');
        }

        if (!this._chart) {
            this._chart = echarts.init(this._container, null, { renderer: 'canvas', useDirtyRect: true });
            // Re-bind click handler for drill-down
            this._chart.on('click', (params) => {
                if (params.dataType === 'node' && params.data && params.data.hostid) {
                    window.open(`zabbix.php?action=problem.view&filter_set=1&filter_hostids[0]=${encodeURIComponent(params.data.hostid)}`, '_blank');
                }
            });
        }

        try {
            this._updateChart();
            if (this._updateFooter) this._updateFooter();
        } catch (e) { console.error('mosicoy_sankey_flow render failed:', e); }
    }

    _isDark() {
        return document.body.classList.contains('theme-dark')
            || document.body.classList.contains('dark-theme')
            || document.documentElement.getAttribute('theme') === 'dark';
    }

    _updateChart() {
        if (!this._chart) return;

        if (!this._nodes.length || !this._links.length) {
            this._chart.clear();
            this._chart.setOption({
                backgroundColor: 'transparent',
                graphic: {
                    type: 'text',
                    left: 'center',
                    top: 'middle',
                    style: {
                        text: 'No problems detected',
                        fill: this._isDark() ? '#a0a0a8' : '#666',
                        fontSize: 14,
                        fontFamily: 'sans-serif'
                    }
                }
            });
            return;
        }

        const isDark = this._isDark();
        const textColor = isDark ? '#e8e8ec' : '#2a2a2f';
        const labelColor = isDark ? 'rgba(232,232,236,0.85)' : 'rgba(42,42,47,0.85)';

        const series = [{
            type: 'sankey',
            left: 8,
            right: 8,
            top: 12,
            bottom: 12,
            nodeGap: 10,
            nodeWidth: 16,
            nodeAlign: 'left',
            emphasis: { focus: 'adjacency' },
            data: this._nodes,
            links: this._links,
            lineStyle: {
                color: 'gradient',
                curveness: 0.5,
                opacity: isDark ? 0.35 : 0.28
            },
            label: {
                color: labelColor,
                fontSize: 11,
                fontWeight: 500,
                formatter: (p) => this._shorten(p.data.displayName || p.name)
            },
            itemStyle: {
                borderWidth: 0
            },
            levels: [
                {
                    depth: 0,
                    itemStyle: { borderColor: 'transparent' },
                    label: { position: 'right', fontSize: 12, fontWeight: 700 }
                },
                {
                    depth: 1,
                    label: { position: 'right', fontSize: 11 }
                },
                {
                    depth: 2,
                    itemStyle: { borderColor: 'transparent' },
                    label: { position: 'left', fontSize: 12, fontWeight: 700 }
                }
            ]
        }];

        this._chart.clear();
        this._chart.setOption({
            backgroundColor: 'transparent',
            tooltip: {
                trigger: 'item',
                backgroundColor: isDark ? 'rgba(20,20,25,0.96)' : 'rgba(255,255,255,0.98)',
                borderColor: isDark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.1)',
                borderWidth: 1,
                padding: [8, 12],
                textStyle: { color: textColor, fontSize: 12 },
                formatter: (p) => {
                    if (p.dataType === 'edge') {
                        const src = this._displayName(p.data.source);
                        const dst = this._displayName(p.data.target);
                        return `<div style="font-weight:600;margin-bottom:4px">${this._esc(src)} → ${this._esc(dst)}</div>` +
                               `<div>Flow: <b>${p.data.value}</b></div>`;
                    }
                    const name = p.data.displayName || p.name;
                    return `<div style="font-weight:600">${this._esc(name)}</div>` +
                           `<div style="margin-top:4px;opacity:0.7">Total: <b>${p.value || 0}</b></div>`;
                }
            },
            series
        }, true);
    }

    _displayName(id) {
        const node = this._nodes.find(n => n.name === id);
        return node ? (node.displayName || id) : id;
    }

    _shorten(s) {
        if (!s) return '';
        return s.length > 22 ? s.substring(0, 20) + '…' : s;
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    _updateFooter() {
        if (!this._footer) return;
        const s = this._summary;
        const parts = [];

        if (s.hosts) parts.push(`<span class="sankey-stat"><b>${s.hosts}</b> hosts</span>`);
        if (s.with_problems) parts.push(`<span class="sankey-stat sankey-warn"><b>${s.with_problems}</b> with problems</span>`);
        if (s.total_problems) parts.push(`<span class="sankey-stat sankey-crit"><b>${s.total_problems}</b> total problems</span>`);

        if (this._truncated) {
            parts.push(`<span class="sankey-stat sankey-muted">showing ${this._nodes.filter(n=>n.depth===1).length} of ${this._truncatedTotal}</span>`);
        }

        this._footer.innerHTML = parts.join('');
    }

    onResize() {
        super.onResize();
        if (this._chart) this._chart.resize();
    }

    onDestroy() {
        if (this._chart) {
            this._chart.dispose();
            this._chart = null;
        }
        if (typeof super.onDestroy === 'function') super.onDestroy();
    }
}
