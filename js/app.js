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
    document.getElementById('filter-device')?.addEventListener('change',() => renderBreakages());
    document.getElementById('filter-user')?.addEventListener('change',  () => renderBreakages());

    // Paginación
    document.getElementById('prev-page')?.addEventListener('click', () => { if (currentPage > 1) { currentPage--; renderActivity(); } });
    document.getElementById('next-page')?.addEventListener('click', () => { currentPage++; renderActivity(); });

    // Búsqueda global
    document.getElementById('global-search')?.addEventListener('input', e => globalSearch(e.target.value.toLowerCase()));

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
    if (tabId === 'historico' && histState.backupsByDate.length === 0) loadHistBackupDays();
}

function refreshDashboardCharts() {
    [window.statusChart, window.causesChart, window.hourChart, window.devicesChart].forEach(c => {
        if (c) { c.resize?.(); c.update?.(); }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Carga de datos en vivo
// ─────────────────────────────────────────────────────────────────────────────
async function loadData() {
    try {
        const response = await fetch('api.php?action=data');
        const result = await response.json();
        if (result.success && result.data?.records?.length) {
            appData = result.data;
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
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
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
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 11 } } } } }
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
                datasets: [{ data: hourData, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.4, fill: true, borderWidth: 2, pointRadius: 3 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
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
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } }
            }
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
    fillSelect('filter-device', devices, '📟 Todos los dispositivos');
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
                  r.status_label?.toLowerCase().includes(q))) return false;
        }
        return true;
    });
}

function renderActivity() {
    const filtered = getFilteredRecords();
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
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(r.lens_desc)}">${escapeHtml(r.lens_desc)}</td>
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
    const dev  = document.getElementById('filter-device')?.value || '';
    const usr  = document.getElementById('filter-user')?.value  || '';
    const data = (appData.breakages || []).filter(r => {
        if (dev && r.device !== dev) return false;
        if (usr && r.user   !== usr) return false;
        if (q && !(r.job?.toLowerCase().includes(q) || r.reason_descr?.toLowerCase().includes(q) || r.reason?.toLowerCase().includes(q))) return false;
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
            <td>${escapeHtml(r.reason||'-')}</td>
            <td>${escapeHtml(r.user)}</td>
            <td>${escapeHtml(r.device)}</td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(r.lens_desc)}</td>
        </tr>`).join('');
    document.getElementById('breakages-count').textContent = formatNumber(data.length);
}

// ─────────────────────────────────────────────────────────────────────────────
// Render Devices
// ─────────────────────────────────────────────────────────────────────────────
function renderDevices() {
    const tbody = document.getElementById('devices-tbody');
    if (!tbody) return;
    tbody.innerHTML = (appData.deviceStats || []).map(d => `
        <tr onclick="showDeviceDetail('${escapeHtml(d.device)}')" style="cursor:pointer;">
            <td><strong>${escapeHtml(d.device)}</strong></td>
            <td>${formatNumber(d.total)}</td>
            <td>${formatNumber(d.jobs)}</td>
            <td style="color:#ef4444;font-weight:600;">${formatNumber(d.brea)}</td>
            <td><span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;background:${d.rate>5?'#fef2f2':d.rate>2?'#fffbeb':'#f0fdf4'};color:${d.rate>5?'#ef4444':d.rate>2?'#f59e0b':'#059669'};">${d.rate.toFixed(1)}%</span></td>
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
        if (!usersMap[u]) usersMap[u] = { jobs:new Set(), records:0, brea:0, devices:new Set() };
        usersMap[u].records++;
        usersMap[u].jobs.add(r.job);
        if (r.is_breakage) usersMap[u].brea++;
        if (r.device) usersMap[u].devices.add(r.device);
    });
    const rows = Object.entries(usersMap)
        .map(([u,d]) => ({ user:u, records:d.records, jobs:d.jobs.size, brea:d.brea, devices:d.devices.size, rate: d.jobs.size>0?(d.brea/d.jobs.size*100).toFixed(1):0 }))
        .sort((a,b)=>b.records-a.records);
    tbody.innerHTML = rows.map(r => `
        <tr>
            <td><strong>${escapeHtml(r.user)}</strong></td>
            <td>${formatNumber(r.records)}</td>
            <td>${formatNumber(r.jobs)}</td>
            <td style="color:#ef4444;font-weight:600;">${formatNumber(r.brea)}</td>
            <td>${r.rate}%</td>
            <td>${r.devices}</td>
        </tr>`).join('');
}

// ─────────────────────────────────────────────────────────────────────────────
// Global search
// ─────────────────────────────────────────────────────────────────────────────
function globalSearch(q) {
    const tbody = document.getElementById('search-tbody');
    if (!tbody) return;
    if (!q) { tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">Ingresa un término para buscar</td></tr>'; return; }
    const filtered = (appData.records || []).filter(r =>
        r.job?.toLowerCase().includes(q) ||
        r.user?.toLowerCase().includes(q) ||
        r.device?.toLowerCase().includes(q) ||
        r.lens_desc?.toLowerCase().includes(q) ||
        r.reason_descr?.toLowerCase().includes(q)
    ).slice(0,200);
    tbody.innerHTML = filtered.length ? filtered.map(r => `
        <tr class="${r.is_breakage?'breakage':''}" onclick="showDetail('${escapeHtml(r.job)}','${escapeHtml(r.date_raw)}','${escapeHtml(r.time_raw)}')" style="cursor:pointer;">
            <td><strong>${escapeHtml(r.job)}</strong></td>
            <td>${escapeHtml(r.date_raw)}</td>
            <td>${escapeHtml(r.time_raw)}</td>
            <td><span class="badge-status ${r.is_breakage?'badge-brea':''}" style="${!r.is_breakage?'background:#f1f5f9;':''}">${escapeHtml(r.status_label)}</span></td>
            <td>${escapeHtml(r.side_label)}</td>
            <td>${escapeHtml(r.user)}</td>
            <td>${escapeHtml(r.device)}</td>
            <td>${escapeHtml(r.reason_descr||r.lens_desc||'-')}</td>
        </tr>`).join('')
    : '<tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">Sin resultados para "'+escapeHtml(q)+'"</td></tr>';
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
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });
            }
        }, 100);
    } catch(e) { console.error(e); }
}

// ─────────────────────────────────────────────────────────────────────────────
// Show detail modal
// ─────────────────────────────────────────────────────────────────────────────
function showDetail(job, date, time) {
    const breaRecord = (appData.breakages||[]).find(r=>r.job===job&&r.date_raw===date&&r.time_raw===time);
    const allMatches = (appData.records||[]).filter(r=>r.job===job&&r.date_raw===date&&r.time_raw===time);
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
            ${record.lens_desc?`<div style="background:#f8fafc;padding:16px;border-radius:16px;"><h4 style="margin-bottom:12px;color:#3b82f6;">Lente</h4><div style="display:grid;grid-template-columns:120px 1fr;gap:12px;">${createDetailRow('Lente',record.lens_desc)}${createDetailRow('Blank',record.blank_desc)}${record.index_val?createDetailRow('Índice',record.index_val.toFixed(3)):''}</div></div>`:''}
            ${record.is_breakage&&record.reason_descr?`<div style="background:#fef2f2;padding:16px;border-radius:16px;"><h4 style="margin-bottom:12px;color:#ef4444;">⚠️ Quiebra</h4><div style="display:grid;grid-template-columns:120px 1fr;gap:12px;">${createDetailRow('Causa',record.reason_descr,false,true)}${createDetailRow('Código',record.reason,false,true)}${createDetailRow('Dep. BR/RM',record.dep)}</div></div>`:''}
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
                        <thead><tr><th>Job</th><th>Fecha</th><th>Hora</th><th>OD/OI</th><th>Causa</th><th>Código</th><th>Usuario</th><th>Dispositivo</th><th>Lente</th></tr></thead>
                        <tbody>
                        ${breakages.map(r=>`
                            <tr class="breakage">
                                <td><strong>${escapeHtml(r.job)}</strong></td>
                                <td>${escapeHtml(r.date_raw)}</td>
                                <td>${escapeHtml(r.time_raw)}</td>
                                <td>${escapeHtml(r.side_label)}</td>
                                <td style="color:#ef4444;font-weight:600;">${escapeHtml(r.reason_descr||'-')}</td>
                                <td>${escapeHtml(r.reason||'-')}</td>
                                <td>${escapeHtml(r.user)}</td>
                                <td>${escapeHtml(r.device)}</td>
                                <td>${escapeHtml(r.lens_desc)}</td>
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
                    <thead><tr><th>Dispositivo</th><th>Total Reg.</th><th>Jobs</th><th>Quiebras</th><th>Tasa</th></tr></thead>
                    <tbody>
                    ${(data.device_stats||[]).map(d=>`
                        <tr>
                            <td><strong>${escapeHtml(d.device)}</strong></td>
                            <td>${formatNumber(d.total)}</td>
                            <td>${formatNumber(d.jobs)}</td>
                            <td style="color:#ef4444;font-weight:600;">${formatNumber(d.brea)}</td>
                            <td><span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;background:${d.rate>5?'#fef2f2':d.rate>2?'#fffbeb':'#f0fdf4'};color:${d.rate>5?'#ef4444':d.rate>2?'#f59e0b':'#059669'};">${d.rate.toFixed(1)}%</span></td>
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
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
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
                datasets: [{ data: hourData, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,0.1)', tension:0.4, fill:true, borderWidth:2, pointRadius:3 }]
            },
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
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
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'right',labels:{font:{size:11}}}} }
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
            options: { responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}} }
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