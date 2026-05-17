// app.js - Lensware Pro v9.1 — con módulo Histórico de Backups

// ─────────────────────────────────────────────────────────────────────────────
// Estado global
// ─────────────────────────────────────────────────────────────────────────────
let appData = {
    records: [],
    stats: {},
    breakages: [],
    deviceStats: [],
    filename: '',
    lastUpdate: null
};

let currentPage = 1;
const PAGE_SIZE = 50;

let activeFilters = {
    status: '',
    device: '',
    user: '',
    side: '',
    onlyBrea: false,
    search: ''
};

// ─────────────────────────────────────────────────────────────────────────────
// Estado del módulo Histórico
// ─────────────────────────────────────────────────────────────────────────────
let histState = {
    backupsByDate: [],        // [{date, label, is_today, backup, all:[...]}, ...]
    selectedDate: null,       // string 'YYYY-MM-DD'
    selectedFile: null,       // filename del backup seleccionado
    data: null,               // datos cargados del backup
    chartStatus: null,
    chartCauses: null,
    chartHour: null,
    chartDevices: null,
};

let searchState = {
    query: '',
    data: null,
    allRecords: [],
    debounceTimer: null,
};

// ─────────────────────────────────────────────────────────────────────────────
// Inicialización
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    setupEventListeners();
    startAutoRefresh();
    loadHistBackupDays();      // cargar días disponibles en paralelo
});

// ─────────────────────────────────────────────────────────────────────────────
// Event listeners
// ─────────────────────────────────────────────────────────────────────────────
function setupEventListeners() {
    // Navegación
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', e => {
            e.preventDefault();
            switchTab(item.dataset.tab);
        });
    });

    // Botón refresh
    document.getElementById('btn-refresh').addEventListener('click', () => refreshData());

    // Exportar
    document.getElementById('btn-export').addEventListener('click', () => exportData('activity'));
    document.getElementById('export-breakages-btn')?.addEventListener('click', () => exportData('breakages'));
    document.getElementById('btn-backups').addEventListener('click', () => showBackups());

    // Filtros de actividad
    document.getElementById('act-status')?.addEventListener('change', e => { activeFilters.status = e.target.value; currentPage = 1; renderActivity(); });
    document.getElementById('act-device')?.addEventListener('change', e => { activeFilters.device = e.target.value; currentPage = 1; renderActivity(); });
    document.getElementById('act-user')?.addEventListener('change',   e => { activeFilters.user   = e.target.value; currentPage = 1; renderActivity(); });
    document.getElementById('act-side')?.addEventListener('change',   e => { activeFilters.side   = e.target.value; currentPage = 1; renderActivity(); });
    document.getElementById('act-only-brea')?.addEventListener('change', e => { activeFilters.onlyBrea = e.target.checked; currentPage = 1; renderActivity(); });
    document.getElementById('act-search')?.addEventListener('input',  e => { activeFilters.search = e.target.value.toLowerCase(); currentPage = 1; renderActivity(); });
    document.getElementById('act-clear')?.addEventListener('click',   () => clearActivityFilters());

    // Filtros de breakages
    document.getElementById('filter-job')?.addEventListener('input',    () => renderBreakages());
    document.getElementById('filter-user')?.addEventListener('change',  () => renderBreakages());

    // Paginación
    document.getElementById('prev-page')?.addEventListener('click', () => { if (currentPage > 1) { currentPage--; renderActivity(); } });
    document.getElementById('next-page')?.addEventListener('click', () => { currentPage++; renderActivity(); });

    // Búsqueda global (Job + backups históricos)
    document.getElementById('global-search')?.addEventListener('input', e => {
        const q = e.target.value.trim();
        clearTimeout(searchState.debounceTimer);
        searchState.debounceTimer = setTimeout(() => globalSearch(q), 450);
    });

    // Cerrar modales
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.modal').classList.remove('active'));
    });
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('active'); });
    });

    // ── Histórico ──
    document.getElementById('hist-backup-select')?.addEventListener('change', e => {
        histState.selectedFile = e.target.value || null;
        document.getElementById('btn-hist-load').disabled = !histState.selectedFile;
    });

    document.getElementById('btn-hist-load')?.addEventListener('click', () => loadHistData());

    document.getElementById('btn-hist-reset')?.addEventListener('click', () => resetHistFilters());

    document.getElementById('btn-hist-close')?.addEventListener('click', () => {
        histState.data = null;
        document.getElementById('hist-banner').classList.add('hidden');
        renderHistEmpty();
        resetHistFilters();
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Navegación entre tabs
// ─────────────────────────────────────────────────────────────────────────────
function switchTab(tabId) {
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    document.querySelector(`.nav-item[data-tab="${tabId}"]`).classList.add('active');
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tabId}`).classList.add('active');

    const titles = {
        dashboard: 'Dashboard',
        breakages: 'Quiebras',
        activity: 'Actividad',
        devices: 'Dispositivos',
        operators: 'Operadores',
        search: 'Buscar',
        historico: 'Histórico de Backups',
        upload: 'Importar CSV'
    };
    document.getElementById('page-title').textContent = titles[tabId] || 'Dashboard';

    if (tabId === 'dashboard') refreshDashboardCharts();
    if (tabId === 'devices') renderDevices();
    if (tabId === 'operators') renderOperators();
    if (tabId === 'activity') renderActivity();
    if (tabId === 'historico' && histState.backupsByDate.length === 0) loadHistBackupDays();
    if (tabId === 'search' && searchState.query) renderSearchResults();
}

function refreshDashboardCharts() {
    [window.statusChart, window.causesChart, window.hourChart, window.devicesChart].forEach(c => {
        if (c) { c.resize?.(); c.update?.(); }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Normalizar payload API (snake_case → aliases usados en la UI)
// ─────────────────────────────────────────────────────────────────────────────
function normalizeAppData(data) {
    data.deviceStats = data.device_stats || data.deviceStats || [];
    return data;
}

// ─────────────────────────────────────────────────────────────────────────────
// Carga de datos en vivo
// ─────────────────────────────────────────────────────────────────────────────
async function loadData() {
    try {
        const response = await fetch('api.php?action=data');
        const result = await response.json();
        if (result.success && result.data?.records?.length) {
            appData = normalizeAppData(result.data);
            appData.lastUpdate = new Date();
            updateUI();
            updateStatus(true);
            const src = appData.data_source === 'backup' ? ' (desde respaldo)' : '';
            document.getElementById('file-info').textContent = `📄 Archivo: ${appData.filename}${src}`;
            document.getElementById('last-update').textContent = formatTime(new Date());
            document.getElementById('backup-folder').textContent = `Carpeta de respaldos: ${appData.backup_folder || 'desconocida'}`;
        } else {
            const msg = result.error || result.hint || 'Esperando CSV del monitor de Windows...';
            updateStatus(false, msg);
            document.getElementById('file-info').textContent = msg;
        }
    } catch (error) {
        updateStatus(false, error.message);
    }
}

async function refreshData() {
    try {
        const r = await fetch('api.php?action=refresh');
        const result = await r.json();
        if (result.success) await loadData();
    } catch (e) { console.error('Error refreshing:', e); }
}

// ─────────────────────────────────────────────────────────────────────────────
// UI principal (datos en vivo)
// ─────────────────────────────────────────────────────────────────────────────
function updateUI() {
    const stats = appData.stats;
    document.getElementById('kpi-total').textContent  = formatNumber(stats.total || 0);
    document.getElementById('kpi-jobs').textContent   = formatNumber(stats.jobs_unicos || 0);
    document.getElementById('kpi-brea').textContent   = formatNumber(stats.jobs_con_brea || 0);
    document.getElementById('kpi-rate').textContent   = `${(stats.brea_tasa || 0).toFixed(1)}%`;
    document.getElementById('kpi-users').textContent  = formatNumber(stats.usuarios || 0);
    document.getElementById('kpi-devices').textContent= formatNumber(stats.dispositivos || 0);
    document.getElementById('brea-badge').textContent = stats.jobs_con_brea || 0;

    renderCharts(stats);
    populateFilters();
    renderActivity();
    renderBreakages();
    renderDevices();
    renderOperators();
}

// ─────────────────────────────────────────────────────────────────────────────
// Gráficas — etiquetas de cantidad visibles
// ─────────────────────────────────────────────────────────────────────────────
if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
    Chart.register(ChartDataLabels);
}

function chartLabelFormatter(value) {
    if (value == null || value === 0) return '';
    return Number(value).toLocaleString('es-CR');
}

function getChartOptions(type, overrides = {}) {
    const datalabels = {
        display: (ctx) => {
            const v = ctx.dataset.data[ctx.dataIndex];
            return v != null && Number(v) > 0;
        },
        formatter: (v) => chartLabelFormatter(v),
        font: { weight: '700', size: 10 },
        padding: 2,
    };

    const opts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            datalabels,
        },
    };

    switch (type) {
        case 'bar':
            datalabels.anchor = 'end';
            datalabels.align = 'top';
            datalabels.color = '#1e293b';
            opts.scales = { y: { beginAtZero: true } };
            opts.layout = { padding: { top: 22 } };
            break;
        case 'bar-h':
            datalabels.anchor = 'end';
            datalabels.align = 'right';
            datalabels.color = '#1e293b';
            opts.indexAxis = 'y';
            opts.scales = { x: { beginAtZero: true } };
            opts.layout = { padding: { right: 36 } };
            break;
        case 'line':
            datalabels.anchor = 'end';
            datalabels.align = 'top';
            datalabels.color = '#1d4ed8';
            datalabels.offset = 4;
            opts.scales = { y: { beginAtZero: true } };
            opts.layout = { padding: { top: 20 } };
            break;
        case 'doughnut':
            datalabels.anchor = 'center';
            datalabels.align = 'center';
            datalabels.color = '#ffffff';
            opts.plugins.legend = { position: 'right', labels: { font: { size: 11 } } };
            delete opts.scales;
            break;
    }

    return Object.assign({}, opts, overrides);
}

// ─────────────────────────────────────────────────────────────────────────────
// Gráficas principales (en vivo)
// ─────────────────────────────────────────────────────────────────────────────
function renderCharts(stats) {
    // Status chart
    const statusEntries = Object.entries(stats.por_status || {}).sort((a,b)=>b[1]-a[1]);
    const statusLabels  = statusEntries.map(([k]) => STATUS_LABELS[k] || k);
    const statusValues  = statusEntries.map(([,v]) => v);
    const statusColors  = statusEntries.map(([k]) => STATUS_COLORS[k] || '#64748B');

    if (window.statusChart) window.statusChart.destroy();
    const ctxS = document.getElementById('chart-status')?.getContext('2d');
    if (ctxS) {
        window.statusChart = new Chart(ctxS, {
            type: 'bar',
            data: {
                labels: statusLabels,
                datasets: [{ data: statusValues, backgroundColor: statusColors, borderRadius: 6 }]
            },
            options: getChartOptions('bar'),
        });
    }
    document.getElementById('status-meta').textContent = `${statusEntries.length} estados`;

    // Causes chart
    const causeEntries = Object.entries(stats.brea_causa || {}).slice(0,8);
    if (window.causesChart) window.causesChart.destroy();
    const ctxC = document.getElementById('chart-causes')?.getContext('2d');
    if (ctxC && causeEntries.length) {
        window.causesChart = new Chart(ctxC, {
            type: 'doughnut',
            data: {
                labels: causeEntries.map(([k])=>k),
                datasets: [{ data: causeEntries.map(([,v])=>v), backgroundColor: ['#EF4444','#F59E0B','#3B82F6','#10B981','#8B5CF6','#EC4899','#06B6D4','#F97316'], borderWidth: 2 }]
            },
            options: getChartOptions('doughnut'),
        });
    }

    // Hour chart
    const hourData = stats.por_hora || Array(24).fill(0);
    if (window.hourChart) window.hourChart.destroy();
    const ctxH = document.getElementById('chart-hour')?.getContext('2d');
    if (ctxH) {
        window.hourChart = new Chart(ctxH, {
            type: 'line',
            data: {
                labels: Array.from({length:24},(_,i)=>`${i}:00`),
                datasets: [{ data: hourData, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.4, fill: true, borderWidth: 2, pointRadius: 4 }]
            },
            options: getChartOptions('line'),
        });
    }

    // Devices chart
    const devEntries = Object.entries(stats.por_device || {}).slice(0,10);
    if (window.devicesChart) window.devicesChart.destroy();
    const ctxD = document.getElementById('chart-devices')?.getContext('2d');
    if (ctxD && devEntries.length) {
        window.devicesChart = new Chart(ctxD, {
            type: 'bar',
            data: {
                labels: devEntries.map(([k])=>k),
                datasets: [{ data: devEntries.map(([,v])=>v), backgroundColor: '#8b5cf6', borderRadius: 6 }]
            },
            options: getChartOptions('bar-h'),
        });
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Populate filters
// ─────────────────────────────────────────────────────────────────────────────
function populateFilters() {
    const records = appData.records || [];
    const devices = [...new Set(records.map(r=>r.device).filter(Boolean))].sort();
    const users   = [...new Set(records.map(r=>r.user).filter(Boolean))].sort();
    const statuses= [...new Set(records.map(r=>r.status).filter(Boolean))].sort();

    fillSelect('act-device', devices, '🖥️ Todos los dispositivos');
    fillSelect('act-user', users, '👥 Todos los usuarios');
    fillSelect('act-status', statuses.map(s=>({ value:s, label: (STATUS_LABELS[s]||s)+' ('+s+')' })), '📊 Todos los estados');
    fillSelect('filter-user', users, '👤 Todos los usuarios');
}

function fillSelect(id, options, placeholder) {
    const el = document.getElementById(id);
    if (!el) return;
    const val = el.value;
    el.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>`;
    options.forEach(opt => {
        const o = document.createElement('option');
        if (typeof opt === 'object') { o.value = opt.value; o.textContent = opt.label; }
        else { o.value = opt; o.textContent = opt; }
        el.appendChild(o);
    });
    el.value = val;
}

// ─────────────────────────────────────────────────────────────────────────────
// Render Activity
// ─────────────────────────────────────────────────────────────────────────────
/** Corrige mojibake típico del CSV (Mï¿½ → MÓ, etc.) para mostrar en UI. */
function fixTextEncoding(value) {
    if (!value) return value;
    if (!/ï¿½|Ã[\x80-\xBF]|â€/.test(value)) return value;
    try {
        const bytes = Uint8Array.from(value, c => c.charCodeAt(0) & 0xff);
        const fixed = new TextDecoder('utf-8').decode(bytes);
        if (fixed && !/ï¿½/.test(fixed)) return fixed;
    } catch (_) { /* ignore */ }
    return value;
}

/** Solo columna Blank description del CSV (sin dispositivo ni código de quiebra). */
function formatBlankDescription(r) {
    let text = fixTextEncoding(r.blank_desc?.trim() || '');
    text = text.replace(/\s*·\s*Cód\.\s*\d+\s*$/i, '').trim();
    return text || '-';
}

/** Blank en modal: partes del CSV unidas con · (notas tras ---). */
function formatBlankDescriptionDetail(r) {
    const raw = formatBlankDescription(r);
    if (!raw || raw === '-') return '-';
    return raw
        .split(/\s*---\s*/)
        .map(s => s.trim())
        .filter(Boolean)
        .join(' · ');
}

function blankDescCellHtml(r) {
    const text = formatBlankDescription(r);
    return `<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeAttr(text)}">${escapeHtml(text)}</td>`;
}

function recordTimestamp(r) {
    const d = String(r.date_raw || '').trim();
    const t = String(r.time_raw || '00:00:00').trim();
    let y, mo, da;
    let m = d.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if (m) { da = +m[1]; mo = +m[2]; y = +m[3]; }
    else if ((m = d.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/))) { da = +m[1]; mo = +m[2]; y = +m[3]; }
    else if ((m = d.match(/^(\d{4})-(\d{2})-(\d{2})$/))) { y = +m[1]; mo = +m[2]; da = +m[3]; }
    else if ((m = d.match(/^(\d{4})(\d{2})(\d{2})$/))) { y = +m[1]; mo = +m[2]; da = +m[3]; }
    else return 0;
    const tp = t.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?/);
    const h = tp ? +tp[1] : 0, mi = tp ? +tp[2] : 0, s = tp && tp[3] ? +tp[3] : 0;
    return new Date(y, mo - 1, da, h, mi, s).getTime();
}

function getFilteredRecords() {
    return (appData.records || []).filter(r => {
        if (activeFilters.status && r.status !== activeFilters.status) return false;
        if (activeFilters.device && r.device !== activeFilters.device) return false;
        if (activeFilters.user   && r.user   !== activeFilters.user)   return false;
        if (activeFilters.side   && r.side   !== activeFilters.side)   return false;
        if (activeFilters.onlyBrea && !r.is_breakage) return false;
        if (activeFilters.search) {
            const q = activeFilters.search;
            if (!(r.job?.toLowerCase().includes(q) ||
                  r.user?.toLowerCase().includes(q) ||
                  r.device?.toLowerCase().includes(q) ||
                  r.lens_desc?.toLowerCase().includes(q) ||
                  r.blank_desc?.toLowerCase().includes(q) ||
                  r.status_label?.toLowerCase().includes(q))) return false;
        }
        return true;
    });
}

function renderActivity() {
    const filtered = getFilteredRecords().sort((a, b) => recordTimestamp(b) - recordTimestamp(a));
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    const slice = filtered.slice((currentPage-1)*PAGE_SIZE, currentPage*PAGE_SIZE);
    const tbody = document.getElementById('activity-tbody');
    if (!tbody) return;
    tbody.innerHTML = slice.map(r => `
        <tr class="${r.is_breakage ? 'breakage' : ''}" onclick="showDetail('${escapeHtml(r.job)}','${escapeHtml(r.date_raw)}','${escapeHtml(r.time_raw)}')" style="cursor:pointer;">
            <td><strong>${escapeHtml(r.job)}</strong></td>
            <td>${escapeHtml(r.date_raw)}</td>
            <td>${escapeHtml(r.time_raw)}</td>
            <td><span class="badge-status ${r.is_breakage?'badge-brea':''}" style="${!r.is_breakage?'background:#f1f5f9;':''}">
                ${escapeHtml(r.status_label)}</span></td>
            <td>${escapeHtml(r.side_label)}</td>
            <td>${escapeHtml(r.user)}</td>
            <td>${escapeHtml(r.device)}</td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeAttr(r.lens_desc)}">${escapeHtml(r.lens_desc)}</td>
            ${blankDescCellHtml(r)}
        </tr>`).join('');
    document.getElementById('page-info').textContent = `Página ${currentPage} de ${totalPages} (${formatNumber(total)} registros)`;
    document.getElementById('prev-page').disabled = currentPage === 1;
    document.getElementById('next-page').disabled = currentPage >= totalPages;
}

function clearActivityFilters() {
    activeFilters = { status:'', device:'', user:'', side:'', onlyBrea:false, search:'' };
    ['act-status','act-device','act-user','act-side'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    const cb = document.getElementById('act-only-brea'); if (cb) cb.checked=false;
    const s  = document.getElementById('act-search');    if (s)  s.value='';
    currentPage = 1;
    renderActivity();
}

// ─────────────────────────────────────────────────────────────────────────────
// Render Breakages
// ─────────────────────────────────────────────────────────────────────────────
function renderBreakages() {
    const q    = document.getElementById('filter-job')?.value.toLowerCase() || '';
    const usr  = document.getElementById('filter-user')?.value  || '';
    const data = (appData.breakages || []).filter(r => {
        if (usr && r.user !== usr) return false;
        const blankText = formatBlankDescription(r).toLowerCase();
        if (q && !(r.job?.toLowerCase().includes(q) || r.reason_descr?.toLowerCase().includes(q) || blankText.includes(q))) return false;
        return true;
    });
    const tbody = document.getElementById('breakages-tbody');
    if (!tbody) return;
    tbody.innerHTML = data.map(r => `
        <tr class="breakage" onclick="showDetail('${escapeHtml(r.job)}','${escapeHtml(r.date_raw)}','${escapeHtml(r.time_raw)}')" style="cursor:pointer;">
            <td><strong>${escapeHtml(r.job)}</strong></td>
            <td>${escapeHtml(r.date_raw)}</td>
            <td>${escapeHtml(r.time_raw)}</td>
            <td>${escapeHtml(r.side_label)}</td>
            <td style="color:#ef4444;font-weight:600;">${escapeHtml(r.reason_descr||'-')}</td>
            <td>${escapeHtml(r.user)}</td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeAttr(r.lens_desc)}">${escapeHtml(r.lens_desc)}</td>
            ${blankDescCellHtml(r)}
        </tr>`).join('');
    document.getElementById('breakages-count').textContent = formatNumber(data.length);
}

// ─────────────────────────────────────────────────────────────────────────────
// Render Devices
// ─────────────────────────────────────────────────────────────────────────────
function renderDevices() {
    const tbody = document.getElementById('devices-tbody');
    if (!tbody) return;
    const devices = appData.device_stats || appData.deviceStats || [];
    tbody.innerHTML = devices.map(d => `
        <tr onclick="showDeviceDetail('${escapeHtml(d.device)}')" style="cursor:pointer;">
            <td><strong>${escapeHtml(d.device)}</strong></td>
            <td>${formatNumber(d.total)}</td>
            <td>${formatNumber(d.jobs)}</td>
        </tr>`).join('');
}

// ─────────────────────────────────────────────────────────────────────────────
// Render Operators
// ─────────────────────────────────────────────────────────────────────────────
function renderOperators() {
    const tbody = document.getElementById('operators-tbody');
    if (!tbody) return;
    const records  = appData.records || [];
    const usersMap = {};
    records.forEach(r => {
        const u = r.user || 'Desconocido';
        if (!usersMap[u]) usersMap[u] = { jobs: new Set(), records: 0, devices: new Set() };
        usersMap[u].records++;
        usersMap[u].jobs.add(r.job);
        if (r.device) usersMap[u].devices.add(r.device);
    });
    const rows = Object.entries(usersMap)
        .map(([u, d]) => ({ user: u, records: d.records, jobs: d.jobs.size, devices: d.devices.size }))
        .sort((a, b) => b.records - a.records);
    tbody.innerHTML = rows.map(r => `
        <tr>
            <td><strong>${escapeHtml(r.user)}</strong></td>
            <td>${formatNumber(r.records)}</td>
            <td>${formatNumber(r.jobs)}</td>
            <td>${r.devices}</td>
        </tr>`).join('');
}

// ─────────────────────────────────────────────────────────────────────────────
// Búsqueda de Job (en vivo + backups históricos)
// ─────────────────────────────────────────────────────────────────────────────
function showSearchStatus(type, text) {
    const bar  = document.getElementById('search-status');
    const icon = document.getElementById('search-status-icon');
    const span = document.getElementById('search-status-text');
    if (!bar) return;
    bar.className = `search-status ${type}`;
    span.textContent = text;
    icon.className = type === 'loading'
        ? 'fas fa-circle-notch fa-spin-custom'
        : (type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-info-circle');
}

function searchRecordRowHtml(r) {
    const src = r._source_label ? `<span style="font-size:10px;color:#64748b;display:block;margin-top:2px;">${escapeHtml(r._source_label)}</span>` : '';
    return `
        <tr class="${r.is_breakage ? 'breakage' : ''}" onclick="showDetail('${escapeAttr(r.job)}','${escapeAttr(r.date_raw)}','${escapeAttr(r.time_raw)}')" style="cursor:pointer;">
            <td><strong>${escapeHtml(r.job)}</strong>${src}</td>
            <td>${escapeHtml(r.date_raw)}</td>
            <td>${escapeHtml(r.time_raw)}</td>
            <td><span class="badge-status ${r.is_breakage?'badge-brea':''}" style="${!r.is_breakage?'background:#f1f5f9;':''}">${escapeHtml(r.status_label)}</span></td>
            <td>${escapeHtml(r.side_label)}</td>
            <td>${escapeHtml(r.user)}</td>
            <td>${escapeHtml(r.device)}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeAttr(r.lens_desc)}">${escapeHtml(r.lens_desc || '-')}</td>
            ${blankDescCellHtml(r)}
        </tr>`;
}

function renderSearchResults() {
    const container = document.getElementById('search-results');
    const summary   = document.getElementById('search-job-summary');
    if (!container) return;

    const data = searchState.data;
    if (!data || !data.sources?.length) {
        if (summary) summary.classList.add('hidden');
        container.innerHTML = `<p style="text-align:center;padding:48px 20px;color:#94a3b8;font-size:14px;">
            Ingresa un número de Job para ver todo su historial en datos en vivo y en respaldos de días anteriores.
        </p>`;
        return;
    }

    const jobsFound = new Set();
    data.sources.forEach(s => s.records.forEach(r => jobsFound.add(r.job)));

    if (summary) {
        summary.classList.remove('hidden');
        summary.innerHTML = `
            <h2><i class="fas fa-briefcase"></i> Job ${escapeHtml(data.job_query)}</h2>
            <p>${formatNumber(data.total_records)} registro(s) en ${data.sources_count} fuente(s) de datos</p>
            <div class="search-meta">
                ${[...jobsFound].map(j => `<span>Job ${escapeHtml(j)}</span>`).join('')}
                <span>${data.sources_count} período(s)</span>
            </div>`;
    }

    const tableHead = `<thead><tr>
        <th>Job</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>OD/OI</th>
        <th>Usuario</th><th>Dispositivo</th><th>Lente</th><th>Blank description</th>
    </tr></thead>`;

    container.innerHTML = data.sources.map(source => `
        <div class="search-source-block">
            <div class="search-source-header">
                <h3><i class="fas fa-${source.is_live ? 'broadcast-tower' : 'archive'}"></i> ${escapeHtml(source.label)}</h3>
                <span class="tag ${source.is_live ? 'live' : 'backup'}">${formatNumber(source.records.length)} reg.</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    ${tableHead}
                    <tbody>${source.records.map(r => searchRecordRowHtml(r)).join('')}</tbody>
                </table>
            </div>
        </div>
    `).join('');
}

async function globalSearch(q) {
    searchState.query = q;
    const container = document.getElementById('search-results');
    const summary   = document.getElementById('search-job-summary');

    if (!q || q.length < 2) {
        searchState.data = null;
        searchState.allRecords = [];
        const statusBar = document.getElementById('search-status');
        if (statusBar) statusBar.classList.add('hidden');
        if (summary) summary.classList.add('hidden');
        if (container) {
            container.innerHTML = `<p style="text-align:center;padding:48px 20px;color:#94a3b8;font-size:14px;">
                Ingresa al menos 2 caracteres del número de Job.
            </p>`;
        }
        return;
    }

    showSearchStatus('loading', `Buscando Job «${q}» en datos en vivo y backups históricos...`);
    document.getElementById('search-status')?.classList.remove('hidden');

    try {
        const r = await fetch(`api.php?action=search_job&job=${encodeURIComponent(q)}`);
        const result = await r.json();

        if (!result.success) {
            searchState.data = result.data || null;
            searchState.allRecords = [];
            showSearchStatus('error', result.error || 'Sin resultados');
            if (summary) summary.classList.add('hidden');
            if (container) {
                container.innerHTML = `<p style="text-align:center;padding:48px 20px;color:#94a3b8;">${escapeHtml(result.error || 'No se encontraron registros.')}</p>`;
            }
            return;
        }

        searchState.data = result.data;
        searchState.allRecords = [];
        result.data.sources.forEach(source => {
            source.records.forEach(r => {
                searchState.allRecords.push({
                    ...r,
                    _source_label: source.label,
                    _source_id: source.id,
                    _is_live: source.is_live,
                });
            });
        });

        showSearchStatus('ok', `✅ ${formatNumber(result.data.total_records)} registros en ${result.data.sources_count} fuente(s)`);
        renderSearchResults();
    } catch (e) {
        showSearchStatus('error', `Error: ${e.message}`);
        if (container) container.innerHTML = `<p style="text-align:center;padding:40px;color:#ef4444;">Error de conexión</p>`;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Device detail modal
// ─────────────────────────────────────────────────────────────────────────────
async function showDeviceDetail(deviceName) {
    try {
        const r = await fetch(`api.php?action=device&name=${encodeURIComponent(deviceName)}`);
        const result = await r.json();
        if (!result.success) return;
        const data = result.details;
        const hourData = data.hour_distribution || Array(24).fill(0);
        const modal = document.getElementById('modal-device');
        document.getElementById('modal-device-title').textContent = `📟 ${deviceName}`;
        document.getElementById('device-details').innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;">
                ${[['Total registros',data.total_records,'#3b82f6'],['Jobs únicos',data.total_jobs,'#10b981'],['Quiebras',data.breakages,'#ef4444']]
                  .map(([l,v,c])=>`<div style="background:#f8fafc;border-radius:12px;padding:16px;text-align:center;"><div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;margin-bottom:6px;">${l}</div><div style="font-size:32px;font-weight:800;color:${c};">${formatNumber(v)}</div></div>`).join('')}
            </div>
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:20px;">
                <h4 style="font-size:13px;font-weight:700;margin-bottom:12px;">Actividad por hora</h4>
                <canvas id="device-hour-chart" height="160" style="width:100%;height:160px;"></canvas>
            </div>
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;overflow:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:300px;">
                    <thead><tr style="background:#f8fafc;">
                        <th style="padding:10px 12px;text-align:left;">Job</th>
                        <th style="padding:10px 12px;text-align:center;">Total</th>
                        <th style="padding:10px 12px;text-align:center;color:#ef4444;">Quiebras</th>
                    </tr></thead>
                    <tbody>
                    ${Object.entries(data.jobs||{}).slice(0,30).map(([job,d])=>`
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px 12px;font-family:monospace;font-weight:600;">${escapeHtml(job)}</td>
                            <td style="padding:10px 12px;text-align:center;">${formatNumber(d.total)}</td>
                            <td style="padding:10px 12px;text-align:center;color:#ef4444;font-weight:600;">${formatNumber(d.brea)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>`;
        modal.classList.add('active');
        setTimeout(() => {
            const ctx = document.getElementById('device-hour-chart')?.getContext('2d');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: { labels: Array.from({length:24},(_,i)=>`${i}:00`), datasets: [{ data: hourData, backgroundColor: '#3b82f6', borderRadius: 6 }] },
                    options: getChartOptions('bar'),
                });
            }
        }, 100);
    } catch(e) { console.error(e); }
}

// ─────────────────────────────────────────────────────────────────────────────
// Show detail modal
// ─────────────────────────────────────────────────────────────────────────────
function showDetail(job, date, time) {
    const pool = [...(appData.records || []), ...(searchState.allRecords || [])];
    const breaRecord = pool.find(r => r.job === job && r.date_raw === date && r.time_raw === time && r.is_breakage);
    const allMatches = pool.filter(r => r.job === job && r.date_raw === date && r.time_raw === time);
    const record = breaRecord || allMatches.find(r=>r.is_breakage) || allMatches[0];
    if (!record) return;
    const modal = document.getElementById('modal-detail');
    document.getElementById('detail-title').textContent = `${record.is_breakage?'⚠️ QUIEBRA':'📋 Registro'} - Job ${record.job}`;
    document.getElementById('detail-body').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:16px;">
            <div style="background:#f8fafc;padding:16px;border-radius:16px;">
                <div style="display:grid;grid-template-columns:120px 1fr;gap:12px;">
                    ${createDetailRow('Job',record.job,true)}
                    ${createDetailRow('Fecha',record.date_raw)}
                    ${createDetailRow('Hora',record.time_raw)}
                    ${createDetailRow('Status',`${record.status} — ${record.status_label}`)}
                    ${createDetailRow('Usuario',record.user)}
                    ${createDetailRow('Dispositivo',record.device)}
                    ${createDetailRow('Lado',record.side_label)}
                </div>
            </div>
            ${(record.lens_desc || record.blank_desc || record.index_val != null)?`<div style="background:#f8fafc;padding:16px;border-radius:16px;"><h4 style="margin-bottom:12px;color:#3b82f6;">Lente y blank</h4><div style="display:grid;grid-template-columns:120px 1fr;gap:12px;">${createDetailRow('Lente',record.lens_desc)}${createDetailRow('Blank description',formatBlankDescriptionDetail(record))}${record.index_val!=null?createDetailRow('Índice',record.index_val.toFixed(3)):''}</div></div>`:''}
            ${record.is_breakage?`<div style="background:#fef2f2;padding:16px;border-radius:16px;"><h4 style="margin-bottom:12px;color:#ef4444;">⚠️ Quiebra</h4><div style="display:grid;grid-template-columns:120px 1fr;gap:12px;">${createDetailRow('Causa',record.reason_descr,false,true)}${createDetailRow('Código',record.reason,false,true)}${record.dep?createDetailRow('Dep. BR/RM',record.dep):''}</div></div>`:''}
        </div>`;
    modal.classList.add('active');
}

function createDetailRow(label, value, mono=false, danger=false) {
    if (!value && value!==0) return '';
    return `<div style="font-size:12px;font-weight:600;color:#64748b;">${escapeHtml(label)}:</div>
            <div style="font-size:13px;${mono?'font-family:monospace;font-weight:600;':''}${danger?'color:#ef4444;':''}">${escapeHtml(String(value))}</div>`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Backups modal
// ─────────────────────────────────────────────────────────────────────────────
async function showBackups() {
    try {
        const r = await fetch('api.php?action=backups');
        const result = await r.json();
        if (!result.success) return;
        const backups = result.backups;
        const container = document.getElementById('backups-list');
        if (!Array.isArray(backups)||backups.length===0) {
            container.innerHTML = '<div style="text-align:center;padding:60px;"><i class="fas fa-archive" style="font-size:48px;color:#cbd5e1;"></i><p style="margin-top:16px;">No hay respaldos guardados</p></div>';
        } else {
            container.innerHTML = `
                <div style="font-size:12px;color:#475569;margin-bottom:14px;">Carpeta: ${escapeHtml(appData?.backup_folder||'desconocida')}</div>
                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:#f8fafc;">
                        <th style="padding:10px;text-align:left;font-size:12px;">Archivo</th>
                        <th style="padding:10px;text-align:left;font-size:12px;">Tamaño</th>
                        <th style="padding:10px;text-align:left;font-size:12px;">Fecha</th>
                    </tr></thead>
                    <tbody>${backups.map(b=>`
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="padding:10px;font-family:monospace;font-size:11px;">${escapeHtml(b.filename||b.name||'')} ${b.is_daily?'<span style="background:#ecfdf5;color:#059669;font-size:10px;padding:2px 6px;border-radius:6px;font-family:sans-serif;">diario</span>':''}</td>
                            <td style="padding:10px;">${formatFileSize(b.size)}</td>
                            <td style="padding:10px;">${escapeHtml(b.modified)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
        }
        document.getElementById('modal-backups').classList.add('active');
    } catch(e) { console.error(e); }
}

// ─────────────────────────────────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════════════════════
//  MÓDULO HISTÓRICO DE BACKUPS
// ══════════════════════════════════════════════════════════════════════════════
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Carga los días disponibles (agrupados por fecha) desde el endpoint backups_by_date
 * y renderiza el selector de días (chips) y el select de backups.
 */
async function loadHistBackupDays() {
    const picker = document.getElementById('hist-day-picker');
    if (!picker) return;
    picker.innerHTML = '<span style="font-size:12px;color:#94a3b8;">Cargando...</span>';
    try {
        const r = await fetch('api.php?action=backups_by_date');
        const result = await r.json();
        if (!result.success || !result.data.length) {
            picker.innerHTML = '<span style="font-size:12px;color:#94a3b8;">No hay backups disponibles.</span>';
            return;
        }
        histState.backupsByDate = result.data;
        renderHistDayChips();
        // Pre-seleccionar hoy (último backup) o el día más reciente disponible
        const todayEntry = histState.backupsByDate.find(d => d.is_today)
            || histState.backupsByDate[0];
        if (todayEntry) selectHistDay(todayEntry.date);
    } catch(e) {
        picker.innerHTML = '<span style="font-size:12px;color:#ef4444;">Error al cargar backups.</span>';
    }
}

/**
 * Dibuja los chips de días disponibles.
 * Hoy aparece primero y en azul. Días anteriores muestran DD/MM.
 */
function renderHistDayChips() {
    const picker = document.getElementById('hist-day-picker');
    if (!picker) return;
    picker.innerHTML = histState.backupsByDate.map(dayObj => {
        const isSelected = dayObj.date === histState.selectedDate;
        const isToday    = dayObj.is_today;
        const hasDaily   = dayObj.all?.some(b => b.filename?.includes('_2359_'));
        return `<span
            class="day-chip ${isToday?'today':''} ${isSelected?'selected':''} ${hasDaily&&!isToday?'daily':''}"
            data-date="${escapeAttr(dayObj.date)}"
            title="${isToday?'Hoy — backup más reciente':(hasDaily?'Backup diario 23:59 disponible':'Backup disponible')}"
            onclick="selectHistDay('${escapeAttr(dayObj.date)}')">
            ${escapeHtml(isToday ? '📅 Hoy' : dayObj.label)}
        </span>`;
    }).join('');
}

/**
 * Al hacer clic en un chip de día: muestra los backups disponibles de ese día
 * en el select (solo para hoy se muestran todos; para días pasados solo el diario o el más reciente).
 */
function selectHistDay(date) {
    histState.selectedDate = date;
    histState.selectedFile = null;
    renderHistDayChips(); // re-render para marcar el seleccionado

    const dayObj = histState.backupsByDate.find(d => d.date === date);
    const select = document.getElementById('hist-backup-select');
    if (!select || !dayObj) return;

    select.innerHTML = '';

    if (dayObj.is_today) {
        // Hoy: mostrar todos los backups del día para elegir momento exacto
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = `— ${dayObj.all.length} backup(s) disponibles —`;
        select.appendChild(opt);
        dayObj.all.forEach(b => {
            const o = document.createElement('option');
            o.value = b.filename;
            const hour = b.modified ? b.modified.substring(11,16) : '';
            o.textContent = `${hour} — ${b.filename} (${formatFileSize(b.size)})`;
            select.appendChild(o);
        });
        // Pre-seleccionar el primero (más reciente)
        if (dayObj.all.length > 0) {
            select.value = dayObj.all[0].filename;
            histState.selectedFile = dayObj.all[0].filename;
        }
    } else {
        // Día anterior: el backup recomendado ya está en dayObj.backup
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = `— Seleccionar backup —`;
        select.appendChild(opt);

        dayObj.all.forEach(b => {
            const o = document.createElement('option');
            o.value = b.filename;
            const isDaily = b.filename?.includes('_2359_');
            o.textContent = `${isDaily ? '⭐ Diario 23:59' : '🕐 ' + (b.modified?.substring(11,16)||'')} — ${formatFileSize(b.size)}`;
            select.appendChild(o);
        });

        // Pre-seleccionar el recomendado (diario o el más reciente)
        const recommended = dayObj.backup;
        if (recommended) {
            select.value = recommended.filename;
            histState.selectedFile = recommended.filename;
        }
    }

    document.getElementById('btn-hist-load').disabled = !histState.selectedFile;
}

/**
 * Carga datos del backup seleccionado aplicando los filtros de hora.
 */
async function loadHistData() {
    if (!histState.selectedFile) return;

    const hourFrom = document.getElementById('hist-hour-from')?.value?.trim() || '';
    const hourTo   = document.getElementById('hist-hour-to')?.value?.trim()   || '';

    // Validar rango horario
    if (hourFrom !== '' && hourTo !== '') {
        if (parseInt(hourFrom) > parseInt(hourTo)) {
            showHistStatus('error', '⚠️ La hora de inicio no puede ser mayor que la hora de fin.');
            return;
        }
    }

    showHistStatus('loading', 'Cargando backup...');
    document.getElementById('btn-hist-load').disabled = true;

    let url = `api.php?action=backup_data&file=${encodeURIComponent(histState.selectedFile)}`;
    if (hourFrom !== '') url += `&hour_from=${encodeURIComponent(hourFrom)}`;
    if (hourTo   !== '') url += `&hour_to=${encodeURIComponent(hourTo)}`;

    // Solo filtrar por fecha si el backup puede contener otro día (nombre distinto al chip)
    const backupDayMatch = histState.selectedFile?.match(/BACKUP_(\d{4})(\d{2})(\d{2})_/);
    const backupDay = backupDayMatch
        ? `${backupDayMatch[1]}-${backupDayMatch[2]}-${backupDayMatch[3]}`
        : null;
    if (histState.selectedDate && backupDay && backupDay !== histState.selectedDate) {
        url += `&date_filter=${encodeURIComponent(histState.selectedDate)}`;
    }

    try {
        const r = await fetch(url);
        const result = await r.json();

        if (!result.success) {
            showHistStatus('error', `❌ ${result.error || 'Error al cargar el backup.'}`);
            document.getElementById('btn-hist-load').disabled = false;
            return;
        }

        histState.data = result.data;
        document.getElementById('btn-hist-load').disabled = false;

        // Construir descripción del contexto
        const dayObj = histState.backupsByDate.find(d => d.date === histState.selectedDate);
        const dayLabel = dayObj ? (dayObj.is_today ? 'Hoy' : dayObj.label) : histState.selectedDate;
        let rangeLabel = '';
        if (hourFrom !== '' && hourTo !== '') rangeLabel = ` · ${hourFrom}:00 – ${hourTo}:59`;
        else if (hourFrom !== '') rangeLabel = ` · desde ${hourFrom}:00`;
        else if (hourTo   !== '') rangeLabel = ` · hasta ${hourTo}:59`;

        const stats = result.data.stats;
        showHistStatus('loaded', `✅ ${formatNumber(stats.total)} registros · ${formatNumber(stats.jobs_unicos)} jobs · ${formatNumber(stats.jobs_con_brea)} quiebras`);

        // Banner
        document.getElementById('hist-banner-title').textContent = `Visualizando: ${dayLabel}${rangeLabel}`;
        document.getElementById('hist-banner-sub').textContent   = `Archivo: ${histState.selectedFile}`;
        document.getElementById('hist-banner').classList.remove('hidden');

        renderHistContent(result.data);
    } catch(e) {
        showHistStatus('error', `❌ Error de conexión: ${e.message}`);
        document.getElementById('btn-hist-load').disabled = false;
    }
}

/**
 * Renderiza el contenido histórico: KPIs, gráficas y tabla de quiebras.
 */
function renderHistContent(data) {
    const stats    = data.stats;
    const breakages= data.breakages || [];
    const records  = data.records   || [];

    const container = document.getElementById('hist-content');
    container.innerHTML = `
        <!-- KPIs -->
        <div class="hist-kpi-row">
            <div class="hist-kpi-card"><h4>Total Registros</h4><p>${formatNumber(stats.total)}</p></div>
            <div class="hist-kpi-card"><h4>Jobs Únicos</h4><p>${formatNumber(stats.jobs_unicos)}</p></div>
            <div class="hist-kpi-card red"><h4>Jobs c/Quiebra</h4><p>${formatNumber(stats.jobs_con_brea)}</p></div>
            <div class="hist-kpi-card"><h4>Tasa Quiebra</h4><p>${stats.brea_tasa.toFixed(1)}%</p></div>
            <div class="hist-kpi-card"><h4>Operadores</h4><p>${formatNumber(stats.usuarios)}</p></div>
            <div class="hist-kpi-card"><h4>Dispositivos</h4><p>${formatNumber(stats.dispositivos)}</p></div>
        </div>

        <!-- Gráficas -->
        <div class="hist-charts-row">
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-chart-bar"></i> Actividad por Etapa</h3></div>
                <canvas id="hist-chart-status" height="240" style="width:100%;height:240px;"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-clock"></i> Actividad por Hora</h3></div>
                <canvas id="hist-chart-hour" height="240" style="width:100%;height:240px;"></canvas>
            </div>
        </div>
        <div class="hist-charts-row">
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-chart-pie"></i> Causas de Quiebra</h3></div>
                <canvas id="hist-chart-causes" height="240" style="width:100%;height:240px;"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-microchip"></i> Top Dispositivos</h3></div>
                <canvas id="hist-chart-devices" height="240" style="width:100%;height:240px;"></canvas>
            </div>
        </div>

        <!-- Tabla de quiebras -->
        <div style="margin-top:4px;">
            <div class="breakages-header" style="margin-bottom:14px;">
                <h2 style="font-size:16px;"><i class="fas fa-bug" style="color:#ef4444;"></i> Quiebras del período (${formatNumber(breakages.length)})</h2>
            </div>
            ${breakages.length === 0
                ? '<div style="background:white;border-radius:14px;padding:40px;text-align:center;color:#94a3b8;border:1px solid #e2e8f0;">Sin quiebras en este período ✅</div>'
                : `<div class="table-container">
                    <table class="data-table">
                        <thead><tr><th>Job</th><th>Fecha</th><th>Hora</th><th>OD/OI</th><th>Causa</th><th>Usuario</th><th>Lente</th><th>Blank description</th></tr></thead>
                        <tbody>
                        ${breakages.map(r=>`
                            <tr class="breakage">
                                <td><strong>${escapeHtml(r.job)}</strong></td>
                                <td>${escapeHtml(r.date_raw)}</td>
                                <td>${escapeHtml(r.time_raw)}</td>
                                <td>${escapeHtml(r.side_label)}</td>
                                <td style="color:#ef4444;font-weight:600;">${escapeHtml(r.reason_descr||'-')}</td>
                                <td>${escapeHtml(r.user)}</td>
                                <td>${escapeHtml(r.lens_desc)}</td>
                                ${blankDescCellHtml(r)}
                            </tr>`).join('')}
                        </tbody>
                    </table>
                  </div>`
            }
        </div>

        <!-- Tabla de actividad resumida por dispositivo -->
        <div style="margin-top:20px;">
            <div class="breakages-header" style="margin-bottom:14px;">
                <h2 style="font-size:16px;"><i class="fas fa-microchip" style="color:#8b5cf6;"></i> Resumen por Dispositivo</h2>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead><tr><th>Dispositivo</th><th>Total Reg.</th><th>Jobs</th></tr></thead>
                    <tbody>
                    ${(data.device_stats||[]).map(d=>`
                        <tr>
                            <td><strong>${escapeHtml(d.device)}</strong></td>
                            <td>${formatNumber(d.total)}</td>
                            <td>${formatNumber(d.jobs)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `;

    // Dibujar gráficas históricas
    setTimeout(() => renderHistCharts(stats), 100);
}

/**
 * Destruye las gráficas históricas anteriores y dibuja nuevas.
 */
function renderHistCharts(stats) {
    // Status
    const statusEntries = Object.entries(stats.por_status || {}).sort((a,b)=>b[1]-a[1]);
    if (histState.chartStatus) histState.chartStatus.destroy();
    const ctxS = document.getElementById('hist-chart-status')?.getContext('2d');
    if (ctxS) {
        histState.chartStatus = new Chart(ctxS, {
            type: 'bar',
            data: {
                labels: statusEntries.map(([k])=>STATUS_LABELS[k]||k),
                datasets: [{ data: statusEntries.map(([,v])=>v), backgroundColor: statusEntries.map(([k])=>STATUS_COLORS[k]||'#64748B'), borderRadius: 6 }]
            },
            options: getChartOptions('bar'),
        });
    }

    // Hour
    const hourData = stats.por_hora || Array(24).fill(0);
    if (histState.chartHour) histState.chartHour.destroy();
    const ctxH = document.getElementById('hist-chart-hour')?.getContext('2d');
    if (ctxH) {
        histState.chartHour = new Chart(ctxH, {
            type: 'line',
            data: {
                labels: Array.from({length:24},(_,i)=>`${i}:00`),
                datasets: [{ data: hourData, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,0.1)', tension:0.4, fill:true, borderWidth:2, pointRadius:4 }]
            },
            options: getChartOptions('line'),
        });
    }

    // Causes
    const causeEntries = Object.entries(stats.brea_causa || {}).slice(0,8);
    if (histState.chartCauses) histState.chartCauses.destroy();
    const ctxC = document.getElementById('hist-chart-causes')?.getContext('2d');
    if (ctxC && causeEntries.length) {
        histState.chartCauses = new Chart(ctxC, {
            type: 'doughnut',
            data: {
                labels: causeEntries.map(([k])=>k),
                datasets: [{ data: causeEntries.map(([,v])=>v), backgroundColor:['#EF4444','#F59E0B','#3B82F6','#10B981','#8B5CF6','#EC4899','#06B6D4','#F97316'], borderWidth:2 }]
            },
            options: getChartOptions('doughnut'),
        });
    } else if (ctxC && !causeEntries.length) {
        const parent = document.getElementById('hist-chart-causes')?.parentElement;
        if (parent) parent.innerHTML += '<div style="text-align:center;padding:20px;color:#94a3b8;font-size:12px;">Sin quiebras en este período</div>';
    }

    // Devices
    const devEntries = Object.entries(stats.por_device || {}).slice(0,10);
    if (histState.chartDevices) histState.chartDevices.destroy();
    const ctxD = document.getElementById('hist-chart-devices')?.getContext('2d');
    if (ctxD && devEntries.length) {
        histState.chartDevices = new Chart(ctxD, {
            type: 'bar',
            data: {
                labels: devEntries.map(([k])=>k),
                datasets: [{ data: devEntries.map(([,v])=>v), backgroundColor:'#8b5cf6', borderRadius:6 }]
            },
            options: getChartOptions('bar-h'),
        });
    }
}

/**
 * Muestra u oculta la barra de estado del histórico.
 */
function showHistStatus(type, text) {
    const bar  = document.getElementById('hist-status-bar');
    const icon = document.getElementById('hist-status-icon');
    const span = document.getElementById('hist-status-text');
    if (!bar) return;
    bar.className = `hist-status-bar ${type}`;
    span.textContent = text;
    if (type === 'loading') {
        icon.className = 'fas fa-circle-notch fa-spin-custom';
    } else if (type === 'loaded') {
        icon.className = 'fas fa-check-circle';
    } else {
        icon.className = 'fas fa-exclamation-circle';
    }
}

/**
 * Limpia el panel histórico y vuelve al estado vacío.
 */
function resetHistFilters() {
    histState.selectedDate = null;
    histState.selectedFile = null;
    histState.data = null;
    renderHistDayChips();
    const sel = document.getElementById('hist-backup-select');
    if (sel) sel.innerHTML = '<option value="">— Seleccionar día primero —</option>';
    const hf = document.getElementById('hist-hour-from'); if (hf) hf.value = '';
    const ht = document.getElementById('hist-hour-to');   if (ht) ht.value = '';
    document.getElementById('btn-hist-load').disabled = true;
    const bar = document.getElementById('hist-status-bar');
    if (bar) bar.className = 'hist-status-bar hidden';
    renderHistEmpty();
}

function renderHistEmpty() {
    document.getElementById('hist-content').innerHTML = `
        <div class="hist-empty">
            <i class="fas fa-calendar-alt" style="color:#cbd5e1;"></i>
            <h3>Selecciona un día para comenzar</h3>
            <p>Elige un backup de la lista superior para visualizar datos históricos.<br>
               Hoy muestra el backup más reciente. Días anteriores muestran el respaldo diario oficial (23:59).</p>
        </div>`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
async function exportData(type) {
    window.location.href = `api.php?action=export&type=${type}`;
}

function startAutoRefresh() {
    setInterval(() => refreshData(), 30000);
}

function updateStatus(online, error='') {
    const dot  = document.getElementById('status-dot');
    const text = document.getElementById('status-text');
    if (online) { dot.className='fas fa-circle online'; text.textContent='🟢 Monitor activo'; }
    else         { dot.className='fas fa-circle offline'; text.textContent=error||'🔴 Desconectado'; }
}

function formatNumber(num) { return num?.toLocaleString()||'0'; }
function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    if (bytes<1024)    return bytes+' B';
    if (bytes<1048576) return (bytes/1024).toFixed(1)+' KB';
    return (bytes/1048576).toFixed(1)+' MB';
}
function formatTime(date) { return date.toLocaleTimeString('es-CR'); }

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function escapeAttr(str) {
    if (!str) return '';
    return String(str).replace(/'/g, "\\'");
}

// ─────────────────────────────────────────────────────────────────────────────
// Constantes globales de estado/color
// ─────────────────────────────────────────────────────────────────────────────
const STATUS_LABELS = {
    'SBLK':'Bloqueo','PREP':'Calculado','SGEN':'Generado','PRNT':'Impreso',
    'EDGE':'Bisel/Edging','TRAC':'Trazado','SPOL':'Pulido','SENG':'Laser/Grabado',
    'PKRX':'Validación RX','WHRX':'Almacén Bases','WHST':'Almacén Term.','BREA':'QUIEBRA'
};
const STATUS_COLORS = {
    'BREA':'#EF4444','SGEN':'#2563EB','SPOL':'#10B981',
    'EDGE':'#F59E0B','SENG':'#8B5CF6','SBLK':'#06B6D4',
    'TRAC':'#F97316','PREP':'#3B82F6','PRNT':'#14B8A6',
    'PKRX':'#EC4899','WHRX':'#64748B','WHST':'#94A3B8'
};