class WidgetMosicoyProblemTimeline extends CWidget {

    static SEVERITY = {
        0: { label: 'Not classified', short: 'NC',   color: '#97AAB3', textDark: false },
        1: { label: 'Information',    short: 'INFO', color: '#7499FF', textDark: false },
        2: { label: 'Warning',        short: 'WARN', color: '#FFC859', textDark: true  },
        3: { label: 'Average',        short: 'AVG',  color: '#FFA059', textDark: true  },
        4: { label: 'High',           short: 'HIGH', color: '#E97659', textDark: false },
        5: { label: 'Disaster',       short: 'CRIT', color: '#E45959', textDark: false }
    };

    onInitialize() {
        super.onInitialize();
        // ⚠️ DO NOT touch this._body — it's CWidget's own reference!
        this._timelineRoot = null;
        this._timelineToolbar = null;
        this._timelineBody = null;
        this._timelineFooter = null;
        this._problems = [];
        this._summary = {};
        this._now = 0;
        this._windowFrom = 0;
        this._windowTo = 0;
        this._fieldsValues = {};
    }

    onStart() {
        try {
            super.onStart();
        } catch (e) {
            console.error('Timeline onStart super failed:', e);
        }
        try {
            const root = this._body.querySelector('.mosicoy-timeline-container');
            if (root) {
                this._timelineRoot = root;
                this._timelineToolbar = root.querySelector('.mosicoy-timeline-toolbar');
                this._timelineBody = root.querySelector('.mosicoy-timeline-body');
                this._timelineFooter = root.querySelector('.mosicoy-timeline-footer');
            }
        } catch (e) {
            console.error('Timeline onStart init failed:', e);
        }
    }

    hasPadding() {
        return this.getViewMode() !== ZBX_WIDGET_VIEW_MODE_HIDDEN_HEADER;
    }

    setEditMode() {
        super.setEditMode();
    }

    processUpdateResponse(response) {
        try {
            this._problems = response.problems || [];
            this._summary = response.summary || {};
            this._now = response.now || Math.floor(Date.now() / 1000);
            this._windowFrom = response.window_from || (this._now - 21600);
            this._windowTo = response.window_to || this._now;
            this._fieldsValues = response.fields_values || {};
        } catch (e) {
            console.error('Timeline processUpdateResponse failed:', e);
        }
        this._context = { panel: {}, zabbix: {} };
        this.setContents(response);
    }

    setContents(response) {
        super.setContents(response);

        // ⚠️ Zabbix replaces this._body.innerHTML on every refresh!
        // Re-query the container EVERY time — never cache DOM refs.
        this._timelineRoot = this._body.querySelector('.mosicoy-timeline-container');
        if (!this._timelineRoot) return;
        this._timelineToolbar = this._timelineRoot.querySelector('.mosicoy-timeline-toolbar');
        this._timelineBody = this._timelineRoot.querySelector('.mosicoy-timeline-body');
        this._timelineFooter = this._timelineRoot.querySelector('.mosicoy-timeline-footer');

        try { this._render(); } catch (e) { console.error('mosicoy_problem_timeline render failed:', e); }
    }

    _render() {
        this._renderToolbar();
        this._renderBody();
        this._renderFooter();
    }

    _renderToolbar() {
        if (!this._timelineToolbar) return;
        const s = this._summary;
        const windowLabel = ['1h', '6h', '24h', '7d'][parseInt(this._fieldsValues.time_window ?? 1)] || '6h';

        // Count by severity
        const counts = [0, 0, 0, 0, 0, 0];
        this._problems.forEach(p => {
            if (p.severity >= 0 && p.severity <= 5) counts[p.severity]++;
        });

        // Build severity pills (from CRIT down to WARN, skip NC/INFO unless important)
        let sevPills = '';
        for (let i = 5; i >= 0; i--) {
            if (counts[i] > 0 && (i >= 2 || counts[i] > 0 && i < 2 && counts[2] === 0 && counts[3] === 0 && counts[4] === 0 && counts[5] === 0)) {
                const sv = WidgetMosicoyProblemTimeline.SEVERITY[i];
                const textColor = sv.textDark ? '#1a1a1f' : '#ffffff';
                sevPills += `<span class="tl-sev-pill" style="background:${sv.color}55; color:${sv.textDark ? '#8a5a00' : sv.color}; border-color:${sv.color}66">` +
                    `<span class="tl-sev-pill-dot" style="background:${sv.color}"></span>` +
                    `${counts[i]} ${sv.short}` +
                    `</span>`;
            }
        }

        this._timelineToolbar.innerHTML =
            `<div class="tl-tb-left">` +
                `<span class="tl-badge tl-badge-window">${windowLabel}</span>` +
                `<span class="tl-badge tl-badge-count">${s.total || 0} problems</span>` +
                sevPills +
                (s.acknowledged > 0 ? `<span class="tl-badge tl-badge-ack">${s.acknowledged} ack</span>` : '') +
            `</div>` +
            `<div class="tl-tb-right">` +
                `<div class="tl-axis-mini">` +
                    `<span>${this._timeLabel(this._windowFrom)}</span>` +
                    `<span class="tl-now-label">NOW</span>` +
                `</div>` +
            `</div>`;
    }

    _renderBody() {
        if (!this._timelineBody) return;

        if (!this._problems.length) {
            this._timelineBody.innerHTML = '<div class="tl-empty">No active problems in this time window</div>';
            return;
        }

        const total = this._problems.length;
        this._timelineBody.innerHTML = '';

        const list = document.createElement('div');
        list.className = 'tl-list';

        const nowLine = document.createElement('div');
        nowLine.className = 'tl-now-line';
        list.appendChild(nowLine);

        const windowSpan = Math.max(1, this._windowTo - this._windowFrom);

        // Count how many problems started before the window (for info)
        let startedBefore = 0;
        this._problems.forEach(p => {
            if (p.start < this._windowFrom) startedBefore++;
        });

        this._problems.forEach((p, idx) => {
            const sev = WidgetMosicoyProblemTimeline.SEVERITY[p.severity] || WidgetMosicoyProblemTimeline.SEVERITY[0];
            const startPct = Math.max(0, ((p.start - this._windowFrom) / windowSpan) * 100);
            const endPct = Math.min(100, ((p.end - this._windowFrom) / windowSpan) * 100);
            const widthPct = Math.max(0.5, endPct - startPct);

            const row = document.createElement('div');
            row.className = 'tl-row' + (p.acknowledged ? ' tl-ack' : '') + (p.resolved ? ' tl-resolved' : '');
            if (idx === total - 1) row.classList.add('tl-last');

            const label = document.createElement('div');
            label.className = 'tl-label';
            const textCls = sev.textDark ? ' tl-sev-text-dark' : '';
            label.innerHTML =
                `<div class="tl-label-top">` +
                    `<span class="tl-sev-badge${textCls}" style="background:${sev.color}">${sev.short}</span>` +
                    `<span class="tl-host">${this._esc(p.host)}</span>` +
                `</div>` +
                `<span class="tl-name" title="${this._esc(p.name)}">${this._esc(p.name)}</span>`;

            const track = document.createElement('div');
            track.className = 'tl-track';

            const bar = document.createElement('div');
            bar.className = 'tl-bar';
            const startedBefore = p.start <= this._windowFrom;
            if (startedBefore) {
                bar.classList.add('tl-bar-old');
            }
            bar.style.left = startedBefore ? '0%' : startPct + '%';
            bar.style.width = startedBefore
                ? Math.max(0.5, endPct) + '%'
                : widthPct + '%';
            bar.style.background = this._barGradient(sev.color, p.resolved);
            if (!p.resolved) bar.classList.add('tl-active');
            bar.title = `${p.host} — ${p.name}\nSeverity: ${sev.label}\nStarted: ${this._timeLabel(p.start)}\nDuration: ${this._durationLabel(p.duration)}${p.acknowledged ? '\n✓ Acknowledged' : ''}`;
            bar.onclick = (e) => {
                e.stopPropagation();
                const url = `zabbix.php?action=problem.view&filter_set=1&filter_eventid[]=${encodeURIComponent(p.eventid)}`;
                window.open(url, '_blank');
            };
            track.appendChild(bar);

            const dur = document.createElement('div');
            dur.className = 'tl-duration';
            dur.innerHTML = (p.acknowledged ? '<span class="tl-ack-badge">✓</span> ' : '') + this._durationLabel(p.duration);

            row.appendChild(label);
            row.appendChild(track);
            row.appendChild(dur);
            list.appendChild(row);
        });

        this._timelineBody.appendChild(list);
    }

    _renderFooter() {
        if (!this._timelineFooter) return;
        const active = this._problems.filter(p => !p.resolved).length;
        const resolved = this._problems.length - active;
        const parts = [];
        if (active) parts.push(`<span class="tl-stat tl-active-stat"><b>${active}</b> active</span>`);
        if (resolved) parts.push(`<span class="tl-stat"><b>${resolved}</b> resolved</span>`);
        parts.push('<span class="tl-hint">click a bar → problems</span>');
        this._timelineFooter.innerHTML = parts.join('');
    }

    _barGradient(color, resolved) {
        if (resolved) {
            return `linear-gradient(90deg, ${color}55, ${color}33)`;
        }
        return `linear-gradient(90deg, ${color}, ${color}cc)`;
    }

    _durationLabel(sec) {
        if (sec < 60) return sec + 's';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        if (sec < 86400) {
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            return h + 'h' + (m > 0 ? ' ' + m + 'm' : '');
        }
        const d = Math.floor(sec / 86400);
        const h = Math.floor((sec % 86400) / 3600);
        return d + 'd' + (h > 0 ? ' ' + h + 'h' : '');
    }

    _timeLabel(ts) {
        const d = new Date(ts * 1000);
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        return hh + ':' + mm;
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    onResize() {
        try { super.onResize(); } catch (e) { /* ignore */ }
        // Re-render on tab switch / panel resize — fixes blank widget when hidden
        try {
            if (this._timelineRoot && this._problems.length > 0) {
                this._render();
            }
        } catch (e) {
            console.error('Timeline onResize render failed:', e);
        }
    }

    onDestroy() {
        try {
            if (typeof super.onDestroy === 'function') super.onDestroy();
        } catch (e) { /* ignore */ }
    }
}
