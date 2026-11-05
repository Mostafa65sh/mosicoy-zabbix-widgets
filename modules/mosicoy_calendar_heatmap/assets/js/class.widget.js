class WidgetMosicoyCalendar extends CWidget {

    static PALETTES = {
        0: ['#eef7ff', '#a8d5f7', '#4ba3e3', '#1e6fb8', '#0d3d6b'],
        1: ['#fff7ed', '#fdd0a2', '#fd8d3c', '#d94801', '#7f2704'],
        2: ['#ebedf0', '#9be9a8', '#40c463', '#30a14e', '#216e39'],
        3: ['#ecfdf5', '#86efac', '#22c55e', '#15803d', '#14532d'],
        4: ['#fef2f2', '#fca5a5', '#ef4444', '#dc2626', '#991b1b']
    };

    onInitialize() {
        super.onInitialize();
        this._chart = null;
        this._container = null;
        this._header = null;
        this._tabsContainer = null;
        this._footer = null;
        this._history = {};
        this._itemsMeta = {};
        this._fieldsValues = {};
        this._currentTab = 0;
        this._autoRotateTimer = null;
    }

    processUpdateResponse(response) {
        this._history = response.history || {};
        this._itemsMeta = response.items_meta || {};
        this._fieldsValues = response.fields_values || {};
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);

        // ⚠️ super.setContents() replaces body.innerHTML. Re-query + rebuild.
        const newContainer = this._body.querySelector('.mosicoy-calendar-chart');
        if (!newContainer) return;

        if (this._container !== newContainer) {
            if (this._chart) { try { this._chart.dispose(); } catch(e) {} this._chart = null; }
            this._buildLayout();
            if (!this._container) return;
            this._chart = echarts.init(this._container, null, { renderer: 'canvas', useDirtyRect: true });
        }

        const ids = Object.keys(this._itemsMeta);
        if (this._currentTab >= ids.length) this._currentTab = 0;

        this._renderHeader();
        this._renderTabs();
        this._updateChart();
        this._startAutoRotate();
    }

    _buildLayout() {
        const container = this._body.querySelector('.mosicoy-calendar-container');
        if (!container) return;
        container.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'mosicoy-header';
        container.appendChild(header);
        this._header = header;

        const tabs = document.createElement('div');
        tabs.className = 'mosicoy-tabs';
        container.appendChild(tabs);
        this._tabsContainer = tabs;

        const chart = document.createElement('div');
        chart.className = 'mosicoy-calendar-chart';
        container.appendChild(chart);
        this._container = chart;

        const footer = document.createElement('div');
        footer.className = 'mosicoy-footer';
        container.appendChild(footer);
        this._footer = footer;
    }

    /**
     * Strip host prefix from item name like "srv-01: CPU utilization"
     * Also strip {HOST.NAME} macro if unexpanded
     */
    _cleanName(name, host) {
        if (!name) return '';
        let clean = name;
        clean = clean.replace(/^\{HOST\.NAME\}\s*[:/]\s*/i, '');
        clean = clean.replace(/^\{HOST\.NAME\}\s+/i, '');
        if (host) {
            const esc = host.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            clean = clean.replace(new RegExp('^' + esc + '\\s*[:/]\\s*', 'i'), '');
        }
        clean = clean.trim();
        return clean !== '' ? clean : name;
    }

    _getDisplayInfo(idx) {
        const ids = Object.keys(this._itemsMeta);
        const itemId = ids[idx];
        if (!itemId) return { itemName: '', host: '', full: '' };
        const meta = this._itemsMeta[itemId];
        const itemName = this._cleanName(meta.name || '', meta.host || '');
        return {
            itemName,
            host: meta.host || '',
            full: itemName && meta.host ? `${meta.host} · ${itemName}` : (itemName || meta.host || '')
        };
    }

    _renderHeader() {
        if (!this._header) return;
        const ids = Object.keys(this._itemsMeta);
        if (!ids.length) {
            this._header.innerHTML = '';
            return;
        }

        const info = this._getDisplayInfo(this._currentTab);
        const total = ids.length;
        const counter = total > 1 ? `<span class="mosicoy-counter">${this._currentTab + 1} / ${total}</span>` : '';

        this._header.innerHTML =
            `<div class="mosicoy-header-left">` +
                `<div class="mosicoy-header-item">${this._esc(info.itemName || 'Item')}</div>` +
                `<div class="mosicoy-header-host">${this._esc(info.host || '')}</div>` +
            `</div>` +
            `<div class="mosicoy-header-right">${counter}</div>`;
    }

    _renderTabs() {
        if (!this._tabsContainer) return;
        const ids = Object.keys(this._itemsMeta);
        this._tabsContainer.innerHTML = '';

        if (ids.length <= 1) {
            this._tabsContainer.style.display = 'none';
            return;
        }
        this._tabsContainer.style.display = 'flex';

        ids.forEach((id, idx) => {
            const info = this._getDisplayInfo(idx);
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'mosicoy-tab' + (idx === this._currentTab ? ' active' : '');
            tab.title = info.full;
            tab.innerHTML = `<span class="mosicoy-tab-dot"></span>` +
                `<span class="mosicoy-tab-label">${this._esc(info.itemName || info.host || 'Item')}</span>`;
            tab.onclick = () => {
                this._currentTab = idx;
                this._renderHeader();
                this._renderTabs();
                this._updateChart();
                this._restartAutoRotate();
            };
            this._tabsContainer.appendChild(tab);
        });
    }

    _startAutoRotate() {
        this._stopAutoRotate();
        const ids = Object.keys(this._itemsMeta);
        if (ids.length <= 1) return;
        this._autoRotateTimer = setInterval(() => {
            this._currentTab = (this._currentTab + 1) % ids.length;
            this._renderHeader();
            this._renderTabs();
            this._updateChart();
        }, 8000);
    }

    _stopAutoRotate() {
        if (this._autoRotateTimer) {
            clearInterval(this._autoRotateTimer);
            this._autoRotateTimer = null;
        }
    }

    _restartAutoRotate() {
        this._stopAutoRotate();
        this._startAutoRotate();
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    _isDarkTheme() {
        if (document.body.classList.contains('theme-dark')) return true;
        if (document.body.classList.contains('dark-theme')) return true;
        if (document.documentElement.getAttribute('theme') === 'dark') return true;
        return false;
    }

    _updateChart() {
        if (!this._chart) return;

        const isDark = this._isDarkTheme();
        const subtle = isDark ? '#a0a0a8' : '#666';
        const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.07)';
        const textColor = isDark ? '#e8e8ec' : '#2a2a2f';

        const ids = Object.keys(this._history);
        if (!ids.length) return this._showMessage('No data', subtle);

        const itemId = ids[this._currentTab] ?? ids[0];
        const rawData = this._history[itemId] || [];
        const meta = this._itemsMeta[itemId] || {};

        if (!rawData.length) {
            return this._showMessage('No historical data in this range', subtle);
        }

        const heatData = rawData
            .map(([date, val]) => [date, Number(val)])
            .filter(d => !isNaN(d[1]));

        if (!heatData.length) {
            return this._showMessage('No numeric data', subtle);
        }

        const values = heatData.map(d => d[1]);
        const minVal = Math.min(...values);
        const maxVal = Math.max(...values);
        const sumVal = values.reduce((a, b) => a + b, 0);
        const avgVal = sumVal / values.length;

        const peakEntry = heatData.reduce((a, b) => b[1] > a[1] ? b : a, heatData[0]);
        const lowEntry = heatData.reduce((a, b) => b[1] < a[1] ? b : a, heatData[0]);

        const dates = heatData.map(d => d[0]).sort();
        const range = [dates[0], dates[dates.length - 1]];

        const palette = WidgetMosicoyCalendar.PALETTES[this._fieldsValues.color_palette ?? 0]
            || WidgetMosicoyCalendar.PALETTES[0];

        const units = meta.units || '';

        // Mark peak day
        const peakDate = peakEntry[0];
        const markedData = heatData.map(d => {
            if (d[0] === peakDate && heatData.length > 1) {
                return { value: d, itemStyle: { borderColor: '#ef4444', borderWidth: 2 } };
            }
            return d;
        });

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
                    const date = p.value[0];
                    const v = p.value[1];
                    const pct = maxVal > minVal ? ((v - minVal) / (maxVal - minVal) * 100).toFixed(0) : 100;
                    return `<div style="font-weight:600;margin-bottom:6px;font-size:13px">${date}</div>` +
                           `<div style="display:flex;gap:8px;align-items:center;font-size:12px">` +
                           `<span style="width:10px;height:10px;border-radius:2px;background:${p.color}"></span>` +
                           `<span style="font-weight:600">${this._format(v, units)}</span>` +
                           `<span style="opacity:0.55">${pct}%</span>` +
                           `</div>`;
                }
            },
            visualMap: {
                min: minVal,
                max: maxVal,
                calculable: false,
                orient: 'horizontal',
                left: 'center',
                bottom: 0,
                inRange: { color: palette },
                textStyle: { color: subtle, fontSize: 10 },
                itemWidth: 10,
                itemHeight: 120,
                precision: 0,
                formatter: (v) => this._format(v, '')
            },
            calendar: {
                top: 8,
                bottom: 34,
                left: 40,
                right: 16,
                range: range,
                cellSize: ['auto', 'auto'],
                yearLabel: { show: false },
                monthLabel: {
                    color: subtle,
                    fontSize: 11,
                    margin: 10,
                    fontWeight: 600
                },
                dayLabel: {
                    color: subtle,
                    fontSize: 9,
                    firstDay: 1,
                    nameMap: ['S', 'M', 'T', 'W', 'T', 'F', 'S']
                },
                itemStyle: {
                    color: 'transparent',
                    borderColor: gridColor,
                    borderWidth: 1.5,
                    borderRadius: 3
                },
                splitLine: { show: false }
            },
            series: [{
                type: 'heatmap',
                coordinateSystem: 'calendar',
                data: markedData,
                itemStyle: { borderRadius: 3 },
                emphasis: {
                    itemStyle: {
                        shadowBlur: 12,
                        shadowColor: 'rgba(0,0,0,0.3)',
                        borderColor: isDark ? '#fff' : '#000',
                        borderWidth: 1.5
                    }
                },
                animationDuration: 700,
                animationEasing: 'cubicOut'
            }]
        }, true);

        this._updateFooter({
            min: minVal,
            max: maxVal,
            avg: avgVal,
            sum: sumVal,
            days: heatData.length,
            units,
            peakDate: peakEntry[0],
            lowDate: lowEntry[0]
        });
    }

    _updateFooter(stats) {
        if (!this._footer) return;
        if (!stats) {
            this._footer.innerHTML = '';
            return;
        }
        const agg = parseInt(this._fieldsValues.aggregate ?? 1);
        const aggLabel = ['Σ', 'avg', 'max', 'min'][agg] || 'avg';

        this._footer.innerHTML =
            `<div class="mosicoy-stat"><span class="mosicoy-stat-label">${aggLabel}</span>` +
            `<span class="mosicoy-stat-value">${this._format(stats.avg, stats.units)}</span></div>` +
            `<div class="mosicoy-stat mosicoy-stat-peak"><span class="mosicoy-stat-label">▲ peak</span>` +
            `<span class="mosicoy-stat-value">${this._format(stats.max, stats.units)}</span>` +
            `<span class="mosicoy-stat-date">${stats.peakDate.slice(5)}</span></div>` +
            `<div class="mosicoy-stat mosicoy-stat-low"><span class="mosicoy-stat-label">▼ low</span>` +
            `<span class="mosicoy-stat-value">${this._format(stats.min, stats.units)}</span>` +
            `<span class="mosicoy-stat-date">${stats.lowDate.slice(5)}</span></div>` +
            `<div class="mosicoy-stat mosicoy-stat-days"><span class="mosicoy-stat-label">days</span>` +
            `<span class="mosicoy-stat-value">${stats.days}</span></div>`;
    }

    _showMessage(text, color) {
        this._chart.clear();
        this._chart.setOption({
            backgroundColor: 'transparent',
            graphic: {
                type: 'text',
                left: 'center',
                top: 'middle',
                style: { text, fill: color, fontSize: 13, fontFamily: 'sans-serif' }
            }
        });
        if (this._footer) this._footer.innerHTML = '';
    }

    _format(v, units) {
        if (v === null || v === undefined) return 'N/A';
        const n = Number(v);
        if (isNaN(n)) return String(v);
        const abs = Math.abs(n);
        let out;
        if (abs >= 1e9) out = (n / 1e9).toFixed(2) + 'G';
        else if (abs >= 1e6) out = (n / 1e6).toFixed(2) + 'M';
        else if (abs >= 1e3) out = (n / 1e3).toFixed(2) + 'K';
        else if (abs < 0.01 && abs > 0) out = n.toExponential(2);
        else out = n.toFixed(2);
        return out + (units ? ' ' + units : '');
    }

    onResize() {
        super.onResize();
        if (this._chart) this._chart.resize();
    }

    onDestroy() {
        this._stopAutoRotate();
        if (this._chart) {
            this._chart.dispose();
            this._chart = null;
        }
        if (typeof super.onDestroy === 'function') super.onDestroy();
    }
}
