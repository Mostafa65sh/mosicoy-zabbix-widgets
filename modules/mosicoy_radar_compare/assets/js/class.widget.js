class WidgetMosicoyRadarCompare extends CWidget {

    static PALETTES = {
        0: ['#4f46e5', '#06b6d4', '#8b5cf6', '#ec4899', '#14b8a6', '#f59e0b', '#ef4444', '#22c55e', '#a855f7', '#0ea5e9'],
        1: ['#0ea5e9', '#06b6d4', '#14b8a6', '#3b82f6', '#6366f1', '#8b5cf6', '#0d9488', '#0284c7'],
        2: ['#f97316', '#f59e0b', '#ef4444', '#dc2626', '#e11d48', '#ec4899', '#f43f5e', '#eab308']
    };

    onInitialize() {
        super.onInitialize();
        this._chart = null;
        this._container = null;
        this._footer = null;
        this._indicators = [];
        this._series = [];
        this._summary = {};
        this._fieldsValues = {};
        this._truncated = false;
        this._truncatedTotal = 0;
    }

    onStart() {
        super.onStart();
        this._container = this._body.querySelector('.mosicoy-radar-chart');
        this._footer = this._body.querySelector('.mosicoy-radar-footer');
    }

    hasPadding() {
        return this.getViewMode() !== ZBX_WIDGET_VIEW_MODE_HIDDEN_HEADER;
    }

    setEditMode() {
        super.setEditMode();
    }

    processUpdateResponse(response) {
        this._indicators = response.indicators || [];
        this._series = response.series || [];
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
        const newContainer = this._body.querySelector('.mosicoy-radar-chart');
        if (!newContainer) return;

        if (this._container !== newContainer) {
            if (this._chart) { try { this._chart.dispose(); } catch(e) {} this._chart = null; }
            this._container = newContainer;
        this._footer = this._body.querySelector('.mosicoy-radar-footer');
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
        } catch (e) { console.error('mosicoy_radar_compare render failed:', e); }
    }

    _isDark() {
        return document.body.classList.contains('theme-dark')
            || document.body.classList.contains('dark-theme')
            || document.documentElement.getAttribute('theme') === 'dark';
    }

    _getPalette() {
        const mode = parseInt(this._fieldsValues.color_mode ?? 0);
        return WidgetMosicoyRadarCompare.PALETTES[mode] || WidgetMosicoyRadarCompare.PALETTES[0];
    }

    _updateChart() {
        if (!this._chart) return;

        if (!this._indicators.length || !this._series.length) {
            this._chart.clear();
            this._chart.setOption({
                backgroundColor: 'transparent',
                graphic: {
                    type: 'text',
                    left: 'center',
                    top: 'middle',
                    style: {
                        text: this._indicators.length ? 'No series to compare' : 'No items matched',
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
        const labelColor = isDark ? 'rgba(232,232,236,0.9)' : 'rgba(42,42,47,0.85)';
        const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.08)';

        const palette = this._getPalette();
        const showLegend = parseInt(this._fieldsValues.show_legend ?? 1) === 1;
        const fillArea = parseInt(this._fieldsValues.fill_area ?? 1) === 1;

        // Indicators (axes)
        const indicators = this._indicators.map(ind => ({
            name: ind.name,
            max: ind.max,
            min: 0
        }));

        // Series data
        const seriesData = this._series.map((s, idx) => {
            const color = palette[s.colorIndex % palette.length];
            const item = {
                name: s.name,
                value: s.values,
                hostid: s.hostid,
                lineStyle: { color, width: 2 },
                itemStyle: { color },
                symbol: 'circle',
                symbolSize: 5,
                emphasis: { focus: 'self' }
            };
            if (fillArea) {
                item.areaStyle = { color, opacity: isDark ? 0.14 : 0.1 };
            }
            return item;
        });

        // Radar axis name formatter (with unit)
        const self = this;

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
                    const s = p.data;
                    let html = `<div style="font-weight:700;margin-bottom:8px;font-size:13px">${self._esc(s.name)}</div>`;
                    s.value.forEach((v, i) => {
                        const ind = self._indicators[i];
                        if (!ind) return;
                        const color = p.color;
                        const pct = ind.max > 0 ? Math.min(100, (v / ind.max) * 100).toFixed(0) : 0;
                        html += `<div style="display:flex;justify-content:space-between;gap:16px;margin:3px 0">` +
                                `<span style="opacity:0.85">${self._esc(ind.name)}</span>` +
                                `<span style="font-weight:600">${self._format(v, ind.unit)}</span>` +
                                `<span style="opacity:0.5;font-size:10px">${pct}%</span>` +
                                `</div>`;
                    });
                    return html;
                }
            },
            legend: {
                show: showLegend,
                data: this._series.map(s => s.name),
                bottom: 0,
                left: 'center',
                textStyle: { color: labelColor, fontSize: 11 },
                itemWidth: 12,
                itemHeight: 8,
                icon: 'roundRect',
                type: 'scroll'
            },
            radar: {
                indicator: indicators,
                center: ['50%', showLegend ? '46%' : '50%'],
                radius: showLegend ? '62%' : '72%',
                splitNumber: 4,
                axisName: {
                    color: labelColor,
                    fontSize: 11,
                    fontWeight: 500
                },
                splitLine: { lineStyle: { color: gridColor } },
                splitArea: {
                    show: true,
                    areaStyle: {
                        color: isDark
                            ? ['rgba(255,255,255,0.01)', 'rgba(255,255,255,0.03)']
                            : ['rgba(0,0,0,0.01)', 'rgba(0,0,0,0.02)']
                    }
                },
                axisLine: { lineStyle: { color: gridColor } }
            },
            series: [{
                type: 'radar',
                data: seriesData,
                emphasis: {
                    lineStyle: { width: 3 }
                }
            }],
            animation: true,
            animationDuration: 800,
            animationEasing: 'cubicOut'
        }, true);
    }

    _format(v, unit) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        const n = Number(v);
        const abs = Math.abs(n);
        const u = unit || '';

        if (u === '%') return n.toFixed(1) + '%';
        if (/^[KMGT]?B$/i.test(u)) {
            if (abs >= 1e9) return (n / 1e9).toFixed(2) + ' GB';
            if (abs >= 1e6) return (n / 1e6).toFixed(2) + ' MB';
            if (abs >= 1e3) return (n / 1e3).toFixed(2) + ' KB';
            return n.toFixed(0) + ' B';
        }
        if (/bps$/i.test(u)) {
            if (abs >= 1e9) return (n / 1e9).toFixed(2) + ' Gbps';
            if (abs >= 1e6) return (n / 1e6).toFixed(2) + ' Mbps';
            if (abs >= 1e3) return (n / 1e3).toFixed(2) + ' Kbps';
            return n.toFixed(0) + ' bps';
        }
        if (abs >= 1e9) return (n / 1e9).toFixed(2) + 'G' + (u ? ' ' + u : '');
        if (abs >= 1e6) return (n / 1e6).toFixed(2) + 'M' + (u ? ' ' + u : '');
        if (abs >= 1e3) return (n / 1e3).toFixed(2) + 'K' + (u ? ' ' + u : '');
        if (abs >= 100) return n.toFixed(0) + (u ? ' ' + u : '');
        if (abs >= 10) return n.toFixed(1) + (u ? ' ' + u : '');
        if (abs >= 1) return n.toFixed(2) + (u ? ' ' + u : '');
        if (abs < 0.01 && abs > 0) return n.toExponential(2) + (u ? ' ' + u : '');
        return n.toFixed(3) + (u ? ' ' + u : '');
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

        if (s.hosts) parts.push(`<span class="radar-stat"><b>${s.hosts}</b> hosts</span>`);
        if (s.indicators) parts.push(`<span class="radar-stat"><b>${s.indicators}</b> indicators</span>`);
        if (this._truncated) {
            parts.push(`<span class="radar-stat radar-muted">showing ${s.hosts} of ${this._truncatedTotal}</span>`);
        }
        parts.push('<span class="radar-hint">hover a polygon to compare</span>');

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
