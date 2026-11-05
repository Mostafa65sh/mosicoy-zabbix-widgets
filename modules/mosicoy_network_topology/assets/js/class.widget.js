class WidgetMosicoyNetworkTopology extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._chart = null;
        this._container = null;
        this._footer = null;
        this._nodes = [];
        this._links = [];
        this._categories = [];
        this._summary = {};
        this._fieldsValues = {};
        this._truncated = false;
        this._truncatedTotal = 0;
    }

    onStart() {
        super.onStart();
        this._container = this._body.querySelector('.mosicoy-topology-chart');
        this._footer = this._body.querySelector('.mosicoy-topology-footer');
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
        this._categories = response.categories || [];
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
        const newContainer = this._body.querySelector('.mosicoy-topology-chart');
        if (!newContainer) return;

        if (this._container !== newContainer) {
            if (this._chart) { try { this._chart.dispose(); } catch(e) {} this._chart = null; }
            this._container = newContainer;
        this._footer = this._body.querySelector('.mosicoy-topology-footer');
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
        } catch (e) { console.error('mosicoy_network_topology render failed:', e); }
    }

    _isDark() {
        return document.body.classList.contains('theme-dark')
            || document.body.classList.contains('dark-theme')
            || document.documentElement.getAttribute('theme') === 'dark';
    }

    _updateChart() {
        if (!this._chart) return;

        if (!this._nodes.length) {
            this._chart.clear();
            this._chart.setOption({
                backgroundColor: 'transparent',
                graphic: {
                    type: 'text',
                    left: 'center',
                    top: 'middle',
                    style: {
                        text: 'No hosts to display',
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

        const layoutMode = parseInt(this._fieldsValues.layout ?? 0);
        // mode 0 = force, 1 = circular, 2 = grid (via manual x/y + layout 'none')
        const layoutName = ['force', 'circular', 'none'][layoutMode] || 'force';
        const isGrid = layoutMode === 2;
        const nodeData = isGrid ? this._computeGridLayout() : this._nodes;

        // Build category colors
        const palette = ['#4f46e5', '#8b5cf6', '#06b6d4', '#ec4899', '#14b8a6', '#f59e0b', '#a855f7', '#0ea5e9', '#ef4444', '#22c55e'];
        const categories = this._categories.map((c, i) => ({
            name: c.name,
            itemStyle: { color: palette[i % palette.length] }
        }));

        const seriesConfig = {
            type: 'graph',
            layout: layoutName,
            roam: true,
            draggable: !isGrid,
            focusNodeAdjacency: true,
            categories: categories,
            data: nodeData,
            links: this._links,
            label: {
                show: true,
                position: 'right',
                color: labelColor,
                fontSize: 10,
                fontWeight: 500,
                formatter: (p) => this._shorten(p.data.name)
            },
            edgeSymbol: ['none', 'none'],
            lineStyle: {
                color: 'source',
                curveness: 0.1,
                opacity: 0.4,
                width: 1
            },
            emphasis: {
                focus: 'adjacency',
                label: {
                    show: true,
                    fontSize: 12,
                    fontWeight: 700
                },
                itemStyle: {
                    borderWidth: 3
                }
            },
            itemStyle: {
                borderWidth: 1,
                borderColor: 'transparent'
            },
            animationDuration: 1000,
            animationEasingUpdate: 'quinticInOut',
            zoom: 1
        };

        if (layoutName === 'force' && !isGrid) {
            seriesConfig.force = {
                repulsion: 320,
                edgeLength: 90,
                gravity: 0.08,
                friction: 0.85,
                layoutAnimation: true
            };
            seriesConfig.force.initLayout = 'circular';
        }

        if (layoutName === 'circular' && !isGrid) {
            seriesConfig.circular = { rotateLabel: true };
        }

        this._chart.clear();
        this._chart.setOption({
            backgroundColor: 'transparent',
            tooltip: {
                trigger: 'item',
                backgroundColor: isDark ? 'rgba(20,20,25,0.96)' : 'rgba(255,255,255,0.98)',
                borderColor: isDark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.1)',
                borderWidth: 1,
                padding: [10, 14],
                textStyle: { color: textColor, fontSize: 12 },
                formatter: (p) => {
                    if (p.dataType === 'edge') {
                        return '';
                    }
                    const d = p.data;
                    const sevLabels = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];
                    const status = d.is_ok
                        ? '<span style="color:#22c55e;font-weight:700">✓ OK</span>'
                        : `<span style="font-weight:700">${d.value} problem${d.value > 1 ? 's' : ''}</span> · ${sevLabels[d.worst]}`;
                    const groups = (d.groups && d.groups.length) ? d.groups.join(', ') : '—';
                    return `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${this._esc(d.name)}</div>` +
                           `<div style="margin-bottom:4px">${status}</div>` +
                           `<div style="opacity:0.7;font-size:11px">Groups: ${this._esc(groups)}</div>`;
                }
            },
            legend: {
                show: categories.length > 0,
                data: categories.map(c => c.name),
                bottom: 0,
                left: 'center',
                textStyle: { color: labelColor, fontSize: 10 },
                itemWidth: 10,
                itemHeight: 10,
                icon: 'circle'
            },
            series: [seriesConfig]
        }, true);
    }


    _computeGridLayout() {
        // Grid: arrange nodes in a responsive grid
        const n = this._nodes.length;
        if (n === 0) return [];

        // Approximate aspect ratio from container
        let w = 1000, h = 600;
        if (this._container) {
            w = Math.max(200, this._container.clientWidth || 1000);
            h = Math.max(200, this._container.clientHeight || 600);
        }
        const aspect = w / h;

        // Choose columns ≈ sqrt(n * aspect)
        const cols = Math.max(1, Math.round(Math.sqrt(n * aspect)));
        const rows = Math.ceil(n / cols);

        const cellW = w / cols;
        const cellH = h / rows;

        // Padding and coordinate scale (ECharts graph uses its own coordinate space)
        const scale = 1000;
        const xStep = (scale * 1.6) / Math.max(1, cols);
        const yStep = (scale * 1.2) / Math.max(1, rows);

        return this._nodes.map((node, i) => {
            const col = i % cols;
            const row = Math.floor(i / cols);
            // Center each cell
            const x = -scale * 0.8 + col * xStep + xStep / 2;
            const y = -scale * 0.6 + row * yStep + yStep / 2;
            return { ...node, x, y };
        });
    }

    _shorten(s) {
        if (!s) return '';
        return s.length > 18 ? s.substring(0, 16) + '…' : s;
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

        if (s.hosts) parts.push(`<span class="topo-stat"><b>${s.hosts}</b> hosts</span>`);
        if (s.with_problems) parts.push(`<span class="topo-stat topo-warn"><b>${s.with_problems}</b> with problems</span>`);
        if (s.total_problems) parts.push(`<span class="topo-stat topo-crit"><b>${s.total_problems}</b> total problems</span>`);

        if (this._truncated) {
            parts.push(`<span class="topo-stat topo-muted">showing ${s.hosts} of ${this._truncatedTotal}</span>`);
        }

        parts.push('<span class="topo-hint">drag · zoom · click node → problems</span>');

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
