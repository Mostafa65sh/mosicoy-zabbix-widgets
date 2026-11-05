class WidgetMosicoyNetworkMap extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._chart = null;
        this._container = null;
        this._footer = null;
        this._nodes = [];
        this._links = [];
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
            this._nodes = response.nodes || [];
            this._links = response.links || [];
            this._debug = response.debug || [];
            this._fieldsValues = response.fields_values || {};
        }
        catch (e) { console.error('NetMap process failed:', e); }
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);

        const newContainer = this._body.querySelector('.mosicoy-netmap-chart');
        if (!newContainer) return;

        if (this._container !== newContainer) {
            if (this._chart) { try { this._chart.dispose(); } catch (e) {} this._chart = null; }
            this._container = newContainer;
            this._footer = this._body.querySelector('.mosicoy-netmap-footer');
        }

        if (!this._chart) {
            this._chart = echarts.init(this._container, null, {
                renderer: 'canvas',
                useDirtyRect: true
            });
            this._chart.on('click', (params) => {
                if (params.dataType === 'node' && params.data && params.data.hostid) {
                    const url = `zabbix.php?action=problem.view&filter_set=1&filter_hostids[0]=${encodeURIComponent(params.data.hostid)}`;
                    window.open(url, '_blank');
                }
            });
        }

        try {
            this._updateChart();
            this._updateFooter();
        } catch (e) {
            console.error('NetMap render failed:', e);
        }
    }

    _requery() {
        const newContainer = this._body.querySelector('.mosicoy-netmap-chart');
        if (newContainer && this._container !== newContainer && this._chart) {
            try { this._chart.dispose(); } catch (e) {}
            this._chart = null;
        }
        if (newContainer) this._container = newContainer;
        this._footer = this._body.querySelector('.mosicoy-netmap-footer');
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
            const debugText = this._debug.length
                ? 'No nodes to display\n\n' + this._debug.join('\n')
                : 'No nodes — edit widget and define links';

            this._chart.setOption({
                backgroundColor: 'transparent',
                graphic: {
                    type: 'text',
                    left: 'center',
                    top: 'middle',
                    style: {
                        text: debugText,
                        fill: this._isDark() ? '#a0a0a8' : '#666',
                        fontSize: 12,
                        fontFamily: 'monospace',
                        lineHeight: 18
                    }
                }
            });
            return;
        }

        const isDark = this._isDark();
        const textColor = isDark ? '#e8e8ec' : '#2a2a2f';
        const labelColor = isDark ? 'rgba(232,232,236,0.9)' : 'rgba(42,42,47,0.85)';

        const layoutMode = parseInt(this._fieldsValues.layout ?? 0);
        const layoutName = ['force', 'circular', 'none'][layoutMode] || 'force';
        const isHierarchical = layoutMode === 2;

        const series = {
            type: 'graph',
            layout: isHierarchical ? 'none' : layoutName,
            roam: true,
            draggable: true,
            focusNodeAdjacency: true,
            data: this._nodes,
            links: this._links,
            label: {
                show: true,
                position: 'bottom',
                color: labelColor,
                fontSize: 11,
                fontWeight: 600,
                formatter: (p) => this._shorten(p.data.name)
            },
            edgeSymbol: ['none', 'arrow'],
            edgeSymbolSize: [0, 8],
            lineStyle: {
                color: 'source',
                curveness: 0.05,
                opacity: 0.5,
                width: 2
            },
            emphasis: {
                focus: 'adjacency',
                label: { show: true, fontSize: 13, fontWeight: 800 },
                lineStyle: { width: 3, opacity: 0.9 }
            },
            itemStyle: { borderWidth: 1, borderColor: 'transparent' },
            animationDuration: 1000,
            animationEasingUpdate: 'quinticInOut',
            symbol: 'circle',
            zoom: 1
        };

        if (layoutName === 'force' && !isHierarchical) {
            series.force = {
                repulsion: 400,
                edgeLength: 130,
                gravity: 0.05,
                friction: 0.85,
                layoutAnimation: true
            };
        }

        if (isHierarchical) {
            // Manual hierarchical positioning by depth
            series.data = this._computeHierarchicalLayout(this._nodes, this._links);
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
                        const label = p.data.value || '';
                        return `<div style="font-weight:600">${this._esc(p.data.source)} → ${this._esc(p.data.target)}</div>` +
                               (label ? `<div style="margin-top:4px;opacity:0.75">${this._esc(label)}</div>` : '');
                    }
                    const d = p.data;
                    const sevLabels = ['Not classified','Information','Warning','Average','High','Disaster'];
                    const status = d.problem_count > 0
                        ? `<span style="font-weight:700">${d.problem_count} problem${d.problem_count > 1 ? 's' : ''}</span> · ${sevLabels[d.severity] || ''}`
                        : '<span style="color:#22c55e;font-weight:700">✓ OK</span>';
                    return `<div style="font-weight:700;font-size:13px;margin-bottom:6px">${this._esc(d.name)}</div>` + status;
                }
            },
            series: [series]
        }, true);
    }

    _computeHierarchicalLayout(nodes, links) {
        // Assign depth: source of a link = higher (smaller depth number), target = lower
        const depth = {};
        nodes.forEach(n => depth[n.id] = 0);

        // Iterative relaxation (5 passes to handle multi-level)
        for (let pass = 0; pass < 5; pass++) {
            links.forEach(l => {
                const src_depth = depth[l.source] ?? 0;
                const dst_depth = depth[l.target] ?? 0;
                if (dst_depth <= src_depth) {
                    depth[l.target] = src_depth + 1;
                }
            });
        }

        const maxDepth = Math.max(...Object.values(depth), 0);
        const byDepth = {};
        nodes.forEach(n => {
            const d = depth[n.id] || 0;
            if (!byDepth[d]) byDepth[d] = [];
            byDepth[d].push(n);
        });

        const W = 1000, H = 600;
        return nodes.map(n => {
            const d = depth[n.id] || 0;
            const idx = byDepth[d].indexOf(n);
            const total = byDepth[d].length;
            const x = total > 1 ? (idx / (total - 1)) * W - W/2 : 0;
            const y = maxDepth > 0 ? (d / maxDepth) * H - H/2 : 0;
            return { ...n, x, y };
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
        const parts = [];
        parts.push(`<span class="nm-stat"><b>${this._nodes.length}</b> nodes</span>`);
        parts.push(`<span class="nm-stat"><b>${this._links.length}</b> links</span>`);

        const problemNodes = this._nodes.filter(n => n.problem_count > 0).length;
        if (problemNodes > 0) {
            parts.push(`<span class="nm-stat nm-crit"><b>${problemNodes}</b> with problems</span>`);
        }

        parts.push('<span class="nm-hint">drag · zoom · click node → problems</span>');

        this._footer.innerHTML = parts.join('');
    }

    onResize() {
        try { super.onResize(); } catch (e) { /* ignore */ }
        if (this._chart) {
            try { this._chart.resize(); } catch (e) { /* ignore */ }
        }
    }

    onDestroy() {
        if (this._chart) {
            try { this._chart.dispose(); } catch (e) {}
            this._chart = null;
        }
        try { if (typeof super.onDestroy === 'function') super.onDestroy(); } catch (e) {}
    }
}
