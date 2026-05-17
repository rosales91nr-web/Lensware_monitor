// app.js - Lensware Pro (Versión Corregida)

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

// Inicialización
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    setupEventListeners();
    startAutoRefresh();
});

function setupEventListeners() {
    // Navegación
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const tab = item.dataset.tab;
            switchTab(tab);
        });
    });

    // Botón refresh
    document.getElementById('btn-refresh').addEventListener('click', () => {
        refreshData();
    });

    // Exportar
    document.getElementById('btn-export').addEventListener('click', () => {
        exportData('activity');
    });

    document.getElementById('export-breakages-btn')?.addEventListener('click', () => {
        exportData('breakages');
    });

    document.getElementById('btn-backups').addEventListener('click', () => {
        showBackups();
    });

    // Filtros de actividad
    document.getElementById('act-status')?.addEventListener('change', (e) => {
        activeFilters.status = e.target.value;
        currentPage = 1;
        renderActivity();
    });
    document.getElementById('act-device')?.addEventListener('change', (e) => {
        activeFilters.device = e.target.value;
        currentPage = 1;
        renderActivity();
    });
    document.getElementById('act-user')?.addEventListener('change', (e) => {
        activeFilters.user = e.target.value;
        currentPage = 1;
        renderActivity();
    });
    document.getElementById('act-side')?.addEventListener('change', (e) => {
        activeFilters.side = e.target.value;
        currentPage = 1;
        renderActivity();
    });
    document.getElementById('act-only-brea')?.addEventListener('change', (e) => {
        activeFilters.onlyBrea = e.target.checked;
        currentPage = 1;
        renderActivity();
    });
    document.getElementById('act-search')?.addEventListener('input', (e) => {
        activeFilters.search = e.target.value.toLowerCase();
        currentPage = 1;
        renderActivity();
    });
    document.getElementById('act-clear')?.addEventListener('click', () => {
        clearActivityFilters();
    });

    // Filtros de breakages
    document.getElementById('filter-job')?.addEventListener('input', () => renderBreakages());
    document.getElementById('filter-device')?.addEventListener('change', () => renderBreakages());
    document.getElementById('filter-user')?.addEventListener('change', () => renderBreakages());

    // Paginación
    document.getElementById('prev-page')?.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            renderActivity();
        }
    });
    document.getElementById('next-page')?.addEventListener('click', () => {
        currentPage++;
        renderActivity();
    });

    // Búsqueda global
    document.getElementById('global-search')?.addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase();
        globalSearch(query);
    });

    // Cerrar modales
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.closest('.modal').classList.remove('active');
        });
    });

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('active');
        });
    });
}

function switchTab(tabId) {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
    });
    document.querySelector(`.nav-item[data-tab="${tabId}"]`).classList.add('active');

    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    document.getElementById(`tab-${tabId}`).classList.add('active');

    const titles = {
        dashboard: 'Dashboard',
        breakages: 'Quiebras',
        activity: 'Actividad',
        devices: 'Dispositivos',
        operators: 'Operadores',
        search: 'Buscar'
    };
    document.getElementById('page-title').textContent = titles[tabId] || 'Dashboard';

    if (tabId === 'dashboard') {
        refreshDashboardCharts();
    }
}

function refreshDashboardCharts() {
    [window.statusChart, window.causesChart, window.hourChart, window.devicesChart].forEach(chart => {
        if (chart) {
            chart.resize?.();
            chart.update?.();
        }
    });
}

async function loadData() {
    try {
        const response = await fetch('api.php?action=data');
        const result = await response.json();

        if (result.success) {
            appData = result.data;
            appData.lastUpdate = new Date();
            updateUI();
            updateStatus(true);
            document.getElementById('file-info').textContent = `📄 Archivo: ${appData.filename}`;
            document.getElementById('last-update').textContent = formatTime(new Date());
            document.getElementById('backup-folder').textContent = `Carpeta de respaldos: ${appData.backup_folder || 'desconocida'}`;
            console.log('Datos cargados:', appData.stats);
        } else {
            updateStatus(false, result.error);
            console.error('Error:', result.error);
        }
    } catch (error) {
        console.error('Error loading data:', error);
        updateStatus(false, error.message);
    }
}

async function refreshData() {
    try {
        const response = await fetch('api.php?action=refresh');
        const result = await response.json();
        if (result.success) {
            await loadData();
        }
    } catch (error) {
        console.error('Error refreshing data:', error);
    }
}

function updateUI() {
    const stats = appData.stats;
    
    // KPI
    document.getElementById('kpi-total').textContent = formatNumber(stats.total || 0);
    document.getElementById('kpi-jobs').textContent = formatNumber(stats.jobs_unicos || 0);
    document.getElementById('kpi-brea').textContent = formatNumber(stats.jobs_con_brea || 0);
    document.getElementById('kpi-rate').textContent = `${(stats.brea_tasa || 0).toFixed(1)}%`;
    document.getElementById('kpi-users').textContent = formatNumber(stats.usuarios || 0);
    document.getElementById('kpi-devices').textContent = formatNumber(stats.dispositivos || 0);
    const kpiEventsEl = document.getElementById('kpi-events');
    if (kpiEventsEl) kpiEventsEl.textContent = formatNumber(stats.eventos_brea || 0);
    const kpiLensesEl = document.getElementById('kpi-lenses');
    if (kpiLensesEl) kpiLensesEl.textContent = formatNumber(stats.lentes_tipos || 0);
    
    document.getElementById('brea-badge').textContent = stats.jobs_con_brea || 0;
    
    // Gauge
    const rate = stats.brea_tasa || 0;
    const circumference = 219.9;
    const offset = circumference * (1 - Math.min(rate / 100, 1));
    const gaugeArc = document.getElementById('gauge-arc');
    if (gaugeArc) gaugeArc.setAttribute('stroke-dashoffset', offset);
    const gaugePctEl = document.getElementById('gauge-pct');
    if (gaugePctEl) gaugePctEl.textContent = `${rate.toFixed(1)}%`;
    const gaugeJobsEl = document.getElementById('gauge-jobs');
    if (gaugeJobsEl) gaugeJobsEl.textContent = formatNumber(stats.jobs_con_brea || 0);
    const gaugeEventsEl = document.getElementById('gauge-events');
    if (gaugeEventsEl) gaugeEventsEl.textContent = formatNumber(stats.eventos_brea || 0);
    
    // Charts
    renderStatusChart(stats.por_status || {});
    renderCausesChart(stats.brea_causa || {});
    renderHourChart(stats.por_hora || Array(24).fill(0));
    renderDevicesChart(stats.por_device || {});
    
    refreshDashboardCharts();
    
    // Top devices list
    renderTopDevices(stats.por_device || {});
    
    // Tablas
    renderBreakages();
    renderActivity();
    renderDevices();
    renderOperators();
    
    // Filtros
    populateFilters();
}

const chartValueLabelsPlugin = {
    id: 'valueLabels',
    afterDatasetsDraw(chart, args, pluginOptions) {
        const ctx = chart.ctx;
        const chartType = chart.config.type;

        ctx.save();
        chart.data.datasets.forEach((dataset, datasetIndex) => {
            const meta = chart.getDatasetMeta(datasetIndex);
            meta.data.forEach((element, index) => {
                const value = dataset.data[index];
                if (value === null || value === undefined) return;

                const label = pluginOptions.formatter ? pluginOptions.formatter(value, index, dataset) : String(value);
                const fontSize = pluginOptions.fontSize || 10;
                ctx.font = `${pluginOptions.fontWeight || '600'} ${fontSize}px ${pluginOptions.fontFamily || 'Inter, Arial, sans-serif'}`;
                ctx.fillStyle = pluginOptions.color || '#1f2937';
                ctx.textBaseline = 'middle';

                let x = 0;
                let y = 0;
                if (chartType === 'doughnut' || chartType === 'pie') {
                    const radius = (element.innerRadius + element.outerRadius) / 2;
                    const angle = (element.startAngle + element.endAngle) / 2;
                    x = element.x + Math.cos(angle) * radius;
                    y = element.y + Math.sin(angle) * radius;
                    ctx.textAlign = 'center';
                } else if (chartType === 'line') {
                    x = element.x;
                    y = element.y - 12;
                    ctx.textAlign = 'center';
                } else if (chartType === 'bar') {
                    x = element.x + 10;
                    y = element.y;
                    ctx.textAlign = 'left';
                } else {
                    x = element.x;
                    y = element.y - 10;
                    ctx.textAlign = 'center';
                }

                ctx.fillText(label, x, y);
            });
        });
        ctx.restore();
    }
};

function renderStatusChart(data) {
    const ctx = document.getElementById('chart-status')?.getContext('2d');
    if (!ctx) return;
    
    const labels = Object.keys(data).map(s => STATUS_LABELS[s] || s);
    const values = Object.values(data);
    // Colores más vibrantes para cada estado
    const colorMap = {
        'BREA': '#EF4444', 'SGEN': '#3B82F6', 'SPOL': '#10B981',
        'EDGE': '#F59E0B', 'SENG': '#8B5CF6', 'SBLK': '#06B6D4',
        'TRAC': '#F97316', 'PREP': '#6366F1', 'PRNT': '#14B8A6',
        'PKRX': '#EC4899', 'WHRX': '#64748B', 'WHST': '#94A3B8'
    };
    const colors = Object.keys(data).map(s => colorMap[s] || '#3B82F6');
    
    if (window.statusChart) window.statusChart.destroy();
    
    window.statusChart = new Chart(ctx, {
        type: 'bar',
        data: { 
            labels, 
            datasets: [{ 
                data: values, 
                backgroundColor: colors.map(c => c + 'CC'),
                borderColor: colors,
                borderWidth: 1,
                borderRadius: 8,
                barPercentage: 0.7,
                categoryPercentage: 0.8
            }] 
        },
        plugins: [chartValueLabelsPlugin],
        options: {
            responsive: false,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { 
                legend: { display: false },
                tooltip: { 
                    backgroundColor: '#0F172A',
                    titleColor: '#F1F5F9',
                    bodyColor: '#CBD5E1',
                    callbacks: { label: (ctx) => `${ctx.raw.toLocaleString()} registros` }
                },
                valueLabels: {
                    color: '#1f2937',
                    fontSize: 10,
                    fontWeight: '600',
                    formatter: (value) => value.toLocaleString()
                }
            },
            scales: {
                x: { grid: { color: '#E2E8F0' }, ticks: { color: '#475569', font: { size: 10 } } },
                y: { grid: { display: false }, ticks: { color: '#475569', font: { size: 10, weight: '500' } } }
            }
        }
    });
    
    document.getElementById('status-meta').textContent = `${Object.keys(data).length} etapas`;
}

function renderCausesChart(data) {
    const ctx = document.getElementById('chart-causes')?.getContext('2d');
    if (!ctx) return;
    
    const entries = Object.entries(data).slice(0, 8);
    const labels = entries.map(e => e[0].length > 28 ? e[0].substring(0, 28) + '...' : e[0]);
    const values = entries.map(e => e[1]);
    // Paleta de colores vibrantes para las causas
    const vibrantColors = [
        '#EF4444', '#F97316', '#F59E0B', '#10B981', 
        '#06B6D4', '#3B82F6', '#8B5CF6', '#EC4899',
        '#6366F1', '#14B8A6', '#F43F5E', '#D946EF'
    ];
    
    if (window.causesChart) window.causesChart.destroy();
    
    window.causesChart = new Chart(ctx, {
        type: 'doughnut',
        data: { 
            labels, 
            datasets: [{ 
                data: values, 
                backgroundColor: vibrantColors.slice(0, entries.length),
                borderColor: 'white',
                borderWidth: 2,
                hoverOffset: 8,
                cutout: '60%'
            }] 
        },
        plugins: [chartValueLabelsPlugin],
        options: { 
            responsive: false, 
            maintainAspectRatio: false, 
            plugins: { 
                legend: { 
                    position: 'right', 
                    labels: { 
                        font: { size: 10, weight: '500' }, 
                        color: '#475569',
                        boxWidth: 10,
                        boxHeight: 10,
                        padding: 8
                    } 
                },
                tooltip: {
                    backgroundColor: '#0F172A',
                    callbacks: { label: (ctx) => `${ctx.label}: ${ctx.raw.toLocaleString()}` }
                },
                valueLabels: {
                    color: '#1f2937',
                    fontSize: 10,
                    fontWeight: '600',
                    formatter: (value) => value.toLocaleString()
                }
            } 
        }
    });
}

function renderHourChart(data) {
    const ctx = document.getElementById('chart-hour')?.getContext('2d');
    if (!ctx) return;
    
    const hours = Array.from({ length: 24 }, (_, i) => `${i.toString().padStart(2, '0')}:00`);
    const values = data;
    const maxValue = Math.max(...values, 1);
    
    if (window.hourChart) window.hourChart.destroy();
    
    window.hourChart = new Chart(ctx, {
        type: 'line',
        data: { 
            labels: hours, 
            datasets: [{ 
                data: values, 
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59,130,246,0.08)',
                borderWidth: 3,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#3B82F6',
                pointBorderColor: 'white',
                pointBorderWidth: 2,
                fill: true,
                tension: 0.3
            }] 
        },
        plugins: [chartValueLabelsPlugin],
        options: { 
            responsive: false, 
            maintainAspectRatio: false, 
            plugins: { 
                legend: { display: false },
                tooltip: { 
                    backgroundColor: '#0F172A',
                    callbacks: { label: (ctx) => `${ctx.raw.toLocaleString()} actividades` }
                },
                valueLabels: {
                    color: '#1f2937',
                    fontSize: 10,
                    fontWeight: '600',
                    formatter: (value) => value.toLocaleString()
                }
            },
            scales: {
                x: { 
                    grid: { display: false }, 
                    ticks: { color: '#475569', font: { size: 9 }, maxRotation: 45, stepSize: 2 }
                },
                y: { 
                    grid: { color: '#E2E8F0' }, 
                    ticks: { color: '#475569', font: { size: 10 } },
                    title: { display: true, text: 'Actividades', color: '#64748B', font: { size: 10 } }
                }
            }
        }
    });
}

function renderDevicesChart(data) {
    const ctx = document.getElementById('chart-devices')?.getContext('2d');
    if (!ctx) return;
    
    const entries = Object.entries(data).slice(0, 10);
    const labels = entries.map(e => e[0].length > 18 ? e[0].substring(0, 18) + '...' : e[0]);
    const values = entries.map(e => e[1]);
    // Gradiente para las barras
    const gradient = ctx.createLinearGradient(0, 0, 200, 0);
    gradient.addColorStop(0, '#8B5CF6');
    gradient.addColorStop(1, '#3B82F6');
    
    if (window.devicesChart) window.devicesChart.destroy();
    
    window.devicesChart = new Chart(ctx, {
        type: 'bar',
        data: { 
            labels, 
            datasets: [{ 
                data: values, 
                backgroundColor: gradient,
                borderRadius: 8,
                barPercentage: 0.7,
                categoryPercentage: 0.8
            }] 
        },
        plugins: [chartValueLabelsPlugin],
        options: { 
            responsive: false, 
            maintainAspectRatio: false, 
            indexAxis: 'y',
            plugins: { 
                legend: { display: false },
                tooltip: { 
                    backgroundColor: '#0F172A',
                    callbacks: { label: (ctx) => `${ctx.raw.toLocaleString()} registros` }
                },
                valueLabels: {
                    color: '#1f2937',
                    fontSize: 10,
                    fontWeight: '600',
                    formatter: (value) => value.toLocaleString()
                }
            },
            scales: {
                x: { grid: { color: '#E2E8F0' }, ticks: { color: '#475569', font: { size: 10 } } },
                y: { grid: { display: false }, ticks: { color: '#475569', font: { size: 10, weight: '500' } } }
            }
        }
    });
}

function renderTopDevices(data) {
    const container = document.getElementById('top-devices-list');
    if (!container) return;
    
    const entries = Object.entries(data).slice(0, 7);
    const max = entries[0]?.[1] || 1;
    
    container.innerHTML = entries.map(([name, value]) => `
        <div class="top-item" onclick="showDeviceDetail('${escapeHtml(name)}')" style="cursor:pointer">
            <span class="top-name">${escapeHtml(name)}</span>
            <div class="top-bar-progress">
                <div class="top-bar-fill" style="width: ${(value / max * 100)}%"></div>
            </div>
            <span class="top-value">${formatNumber(value)}</span>
        </div>
    `).join('');
}

function renderBreakages() {
    const tbody = document.getElementById('breakages-tbody');
    if (!tbody) return;
    
    const jobFilter = document.getElementById('filter-job')?.value.toLowerCase() || '';
    const deviceFilter = document.getElementById('filter-device')?.value || '';
    const userFilter = document.getElementById('filter-user')?.value || '';
    
    let filtered = [...(appData.breakages || [])];
    
    if (jobFilter) {
        filtered = filtered.filter(b => 
            b.job.toLowerCase().includes(jobFilter) || 
            (b.reason_descr || '').toLowerCase().includes(jobFilter)
        );
    }
    if (deviceFilter) filtered = filtered.filter(b => b.device === deviceFilter);
    if (userFilter) filtered = filtered.filter(b => b.user === userFilter);
    
    document.getElementById('breakages-count').textContent = formatNumber(filtered.length);
    
    tbody.innerHTML = filtered.map(b => `
        <tr class="${b.is_breakage ? 'breakage' : ''}" onclick="showDetail('${escapeHtml(b.job)}', '${escapeHtml(b.date_raw)}', '${escapeHtml(b.time_raw)}')" style="cursor:pointer">
            <td><strong>${escapeHtml(b.job)}</strong></td>
            <td>${escapeHtml(b.date_raw)}</td>
            <td>${escapeHtml(b.time_raw)}</td>
            <td><span class="badge-status" style="background:#FEF2F2; color:#EF4444;">${escapeHtml(b.side_label)}</span></td>
            <td>${escapeHtml((b.reason_descr || '—').substring(0, 50))}</td>
            <td><span style="font-family:monospace; font-size:11px;">${escapeHtml(b.reason || '—')}</span></td>
            <td>${escapeHtml(b.user || '—')}</td>
            <td>${escapeHtml(b.device || '—')}</td>
            <td>${escapeHtml((b.lens_desc || '—').substring(0, 40))}</td>
        </tr>
    `).join('') || '<tr><td colspan="9" style="text-align:center; padding:60px;">📭 No hay quiebras registradas</td></tr>';
}

function renderActivity() {
    const tbody = document.getElementById('activity-tbody');
    if (!tbody) return;
    
    let filtered = [...(appData.records || [])];
    
    if (activeFilters.status) filtered = filtered.filter(r => r.status === activeFilters.status);
    if (activeFilters.device) filtered = filtered.filter(r => r.device === activeFilters.device);
    if (activeFilters.user) filtered = filtered.filter(r => r.user === activeFilters.user);
    if (activeFilters.side) filtered = filtered.filter(r => r.side === activeFilters.side);
    if (activeFilters.onlyBrea) filtered = filtered.filter(r => r.is_breakage);
    if (activeFilters.search) {
        filtered = filtered.filter(r => 
            r.job.toLowerCase().includes(activeFilters.search) ||
            (r.lens_desc || '').toLowerCase().includes(activeFilters.search) ||
            (r.user || '').toLowerCase().includes(activeFilters.search)
        );
    }
    
    const total = filtered.length;
    const start = (currentPage - 1) * PAGE_SIZE;
    const end = start + PAGE_SIZE;
    const pageData = filtered.slice(start, end);
    
    document.getElementById('page-info').textContent = `Página ${currentPage} de ${Math.ceil(total / PAGE_SIZE) || 1}`;
    document.getElementById('prev-page').disabled = currentPage === 1;
    document.getElementById('next-page').disabled = end >= total;
    
    tbody.innerHTML = pageData.map(r => {
        const statusColor = STATUS_COLORS[r.status] || '#64748b';
        return `
            <tr class="${r.is_breakage ? 'breakage' : ''}" onclick="showDetail('${escapeHtml(r.job)}', '${escapeHtml(r.date_raw)}', '${escapeHtml(r.time_raw)}')" style="cursor:pointer">
                <td><strong>${escapeHtml(r.job)}</strong></td>
                <td>${escapeHtml(r.date_raw)}</td>
                <td>${escapeHtml(r.time_raw)}</td>
                <td><span class="badge-status" style="background:${statusColor}20; color:${statusColor};">${escapeHtml(r.status_label)}</span></td>
                <td>${escapeHtml(r.side_label)}</td>
                <td>${escapeHtml(r.user || '—')}</td>
                <td>${escapeHtml(r.device || '—')}</td>
                <td>${escapeHtml((r.lens_desc || '—').substring(0, 40))}</td>
            </tr>
        `;
    }).join('') || '<tr><td colspan="8" style="text-align:center; padding:60px;">📋 No hay registros</td></tr>';
}

function renderDevices() {
    const tbody = document.getElementById('devices-tbody');
    if (!tbody) return;
    
    console.log('Renderizando dispositivos:', appData.device_stats);
    
    let devices = [];
    
    // Verificar la estructura de los datos
    if (appData.device_stats && Array.isArray(appData.device_stats)) {
        devices = appData.device_stats;
    } else if (appData.stats && appData.stats.por_device) {
        // Convertir objeto a array
        devices = Object.entries(appData.stats.por_device).map(([name, total]) => ({
            name: name,
            total: total,
            jobs: 0,
            brea: 0,
            rate: 0
        }));
    }
    
    if (devices.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:60px;">🖥️ No hay dispositivos registrados</td></tr>';
        return;
    }
    
    tbody.innerHTML = devices.map(d => {
        const rate = d.rate || 0;
        const rateColor = rate > 15 ? '#ef4444' : (rate > 8 ? '#f59e0b' : '#10b981');
        return `
            <tr onclick="showDeviceDetail('${escapeHtml(d.name)}')" style="cursor:pointer">
                <td><strong><i class="fas fa-microchip" style="margin-right:8px; color:#8b5cf6;"></i>${escapeHtml(d.name)}</strong></td>
                <td>${formatNumber(d.total)}</td>
                <td>${formatNumber(d.jobs || 0)}</td>
                <td style="color:#ef4444; font-weight:600;">${formatNumber(d.brea || 0)}</td>
                <td style="font-weight:700; color:${rateColor};">${rate.toFixed(2)}%</td>
            </tr>
        `;
    }).join('');
}

function renderOperators() {
    const tbody = document.getElementById('operators-tbody');
    if (!tbody) return;
    
    const stats = appData.stats;
    const userStats = stats.por_user || {};
    
    const operators = Object.entries(userStats)
        .map(([user, total]) => ({
            user,
            total,
            brea: 0,
            rate: 0,
            devices: '—'
        }))
        .sort((a, b) => b.total - a.total)
        .slice(0, 50);
    
    if (operators.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:60px;">👤 No hay operadores registrados</td></tr>';
        return;
    }
    
    tbody.innerHTML = operators.map(o => `
        <tr>
            <td><strong><i class="fas fa-user-circle" style="margin-right:8px; color:#3b82f6;"></i>${escapeHtml(o.user)}</strong></td>
            <td>${formatNumber(o.total)}</td>
            <td>—</td>
            <td style="color:#ef4444;">${formatNumber(o.brea)}</td>
            <td>${o.rate.toFixed(2)}%</td>
            <td>${escapeHtml(o.devices)}</td>
        </tr>
    `).join('');
}

function globalSearch(query) {
    const tbody = document.getElementById('search-tbody');
    if (!tbody) return;
    
    if (!query) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:60px;">🔍 Escribe para buscar...</td></tr>';
        return;
    }
    
    const results = (appData.records || []).filter(r =>
        r.job.toLowerCase().includes(query) ||
        (r.user || '').toLowerCase().includes(query) ||
        (r.lens_desc || '').toLowerCase().includes(query) ||
        (r.device || '').toLowerCase().includes(query) ||
        (r.reason_descr || '').toLowerCase().includes(query)
    ).slice(0, 200);
    
    tbody.innerHTML = results.map(r => {
        const statusColor = STATUS_COLORS[r.status] || '#64748b';
        return `
            <tr class="${r.is_breakage ? 'breakage' : ''}" onclick="showDetail('${escapeHtml(r.job)}', '${escapeHtml(r.date_raw)}', '${escapeHtml(r.time_raw)}')" style="cursor:pointer">
                <td><strong>${escapeHtml(r.job)}</strong></td>
                <td>${escapeHtml(r.date_raw)}</td>
                <td>${escapeHtml(r.time_raw)}</td>
                <td><span class="badge-status" style="background:${statusColor}20; color:${statusColor};">${escapeHtml(r.status_label)}</span></td>
                <td>${escapeHtml(r.side_label)}</td>
                <td>${escapeHtml(r.user || '—')}</td>
                <td>${escapeHtml(r.device || '—')}</td>
                <td>${escapeHtml((r.lens_desc || '—').substring(0, 40))}</td>
            </tr>
        `;
    }).join('');
}

function populateFilters() {
    const stats = appData.stats;
    const devices = Object.keys(stats.por_device || {});
    const users = Object.keys(stats.por_user || {});
    const statuses = Object.keys(stats.por_status || {});
    
    populateSelect('act-status', statuses.map(s => ({ value: s, label: STATUS_LABELS[s] || s })), 'Todos los estados');
    populateSelect('act-device', devices.map(d => ({ value: d, label: d })), 'Todos los dispositivos');
    populateSelect('act-user', users.map(u => ({ value: u, label: u })), 'Todos los usuarios');
    
    const breakageDevices = Object.keys(stats.brea_device || {});
    const breakageUsers = Object.keys(stats.brea_por_user || {});
    populateSelect('filter-device', breakageDevices.map(d => ({ value: d, label: d })), 'Todos los dispositivos');
    populateSelect('filter-user', breakageUsers.map(u => ({ value: u, label: u })), 'Todos los usuarios');
}

function populateSelect(id, options, defaultLabel) {
    const select = document.getElementById(id);
    if (!select) return;
    
    select.innerHTML = `<option value="">${defaultLabel}</option>` +
        options.map(opt => `<option value="${escapeHtml(opt.value)}">${escapeHtml(opt.label)}</option>`).join('');
}

function clearActivityFilters() {
    activeFilters = { status: '', device: '', user: '', side: '', onlyBrea: false, search: '' };
    
    const selects = ['act-status', 'act-device', 'act-user', 'act-side'];
    selects.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    
    const onlyBrea = document.getElementById('act-only-brea');
    if (onlyBrea) onlyBrea.checked = false;
    
    const search = document.getElementById('act-search');
    if (search) search.value = '';
    
    currentPage = 1;
    renderActivity();
}

async function showDeviceDetail(deviceName) {
    try {
        const response = await fetch(`api.php?action=device&name=${encodeURIComponent(deviceName)}`);
        const result = await response.json();
        
        if (result.success) {
            const details = result.details;
            const modal = document.getElementById('modal-device');
            const title = document.getElementById('modal-device-title');
            const body = document.getElementById('device-details');
            
            title.textContent = `🖥️ ${deviceName}`;
            
            const hourData = details.hour_distribution || Array(24).fill(0);
            const totalRecords = details.total_records || 0;
            const totalJobs = details.total_jobs || 0;
            const breakages = details.breakages || 0;
            const rate = totalJobs > 0 ? (breakages / totalJobs * 100) : 0;
            
            body.innerHTML = `
                <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:24px">
                    <div style="background:#f8fafc; padding:16px; border-radius:16px; text-align:center">
                        <div style="font-size:28px; font-weight:800; color:#3b82f6;">${formatNumber(totalRecords)}</div>
                        <div style="font-size:11px; color:#64748b;">Registros</div>
                    </div>
                    <div style="background:#f8fafc; padding:16px; border-radius:16px; text-align:center">
                        <div style="font-size:28px; font-weight:800; color:#10b981;">${formatNumber(totalJobs)}</div>
                        <div style="font-size:11px; color:#64748b;">Jobs únicos</div>
                    </div>
                    <div style="background:#fef2f2; padding:16px; border-radius:16px; text-align:center">
                        <div style="font-size:28px; font-weight:800; color:#ef4444;">${formatNumber(breakages)}</div>
                        <div style="font-size:11px; color:#64748b;">Quiebras</div>
                    </div>
                    <div style="background:#f8fafc; padding:16px; border-radius:16px; text-align:center">
                        <div style="font-size:28px; font-weight:800; color:${rate > 15 ? '#ef4444' : (rate > 8 ? '#f59e0b' : '#10b981')};">${rate.toFixed(1)}%</div>
                        <div style="font-size:11px; color:#64748b;">Tasa quiebra</div>
                    </div>
                </div>
                <div style="margin-bottom:24px">
                    <h4 style="margin-bottom:16px; font-size:14px;"><i class="fas fa-chart-line"></i> Actividad por Hora</h4>
                    <div style="height:200px"><canvas id="device-hour-chart"></canvas></div>
                </div>
                <div>
                    <h4 style="margin-bottom:16px; font-size:14px;"><i class="fas fa-briefcase"></i> Top Jobs (${Math.min(30, Object.keys(details.jobs || {}).length)} más activos)</h4>
                    <div style="max-height:300px; overflow:auto; border-radius:12px; border:1px solid #e2e8f0;">
                        <table style="width:100%; font-size:12px; border-collapse:collapse;">
                            <thead style="background:#f8fafc; position:sticky; top:0;">
                                <tr><th style="padding:12px; text-align:left;">Job</th><th style="padding:12px; text-align:center;">Registros</th><th style="padding:12px; text-align:center;">Quiebras</th></tr>
                            </thead>
                            <tbody>
                                ${Object.entries(details.jobs || {}).slice(0, 30).map(([job, data]) => `
                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                        <td style="padding:10px 12px; font-family:monospace; font-size:11px;">${escapeHtml(job)}</td>
                                        <td style="padding:10px 12px; text-align:center;">${formatNumber(data.total)}</td>
                                        <td style="padding:10px 12px; text-align:center; color:#ef4444; font-weight:600;">${formatNumber(data.brea)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
            
            setTimeout(() => {
                const ctx = document.getElementById('device-hour-chart')?.getContext('2d');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: Array.from({ length: 24 }, (_, i) => `${i}:00`),
                            datasets: [{ data: hourData, backgroundColor: '#3b82f6', borderRadius: 6 }]
                        },
                        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } } }
                    });
                }
            }, 100);
        } else {
            console.error('Error loading device details:', result.error);
        }
    } catch (error) {
        console.error('Error loading device details:', error);
    }
}

function showDetail(job, date, time) {
    // Buscar primero en appData.breakages: tiene side_label consolidado (OD+OI) correcto
    // y garantiza que siempre se muestra el registro BREA, no otro status del mismo instante.
    const breaRecord = (appData.breakages || []).find(
        r => r.job === job && r.date_raw === date && r.time_raw === time
    );
    // Fallback: en registros generales, preferir BREA sobre otros status si hay varios en el mismo instante
    const allMatches = (appData.records || []).filter(
        r => r.job === job && r.date_raw === date && r.time_raw === time
    );
    const record = breaRecord
        || allMatches.find(r => r.is_breakage)
        || allMatches[0];
    if (!record) return;
    
    const modal = document.getElementById('modal-detail');
    const title = document.getElementById('detail-title');
    const body = document.getElementById('detail-body');
    
    title.textContent = `${record.is_breakage ? '⚠️ QUIEBRA' : '📋 Registro'} - Job ${record.job}`;
    
    body.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:16px;">
            <div style="background:#f8fafc; padding:16px; border-radius:16px;">
                <div style="display:grid; grid-template-columns:120px 1fr; gap:12px;">
                    ${createDetailRow('Job', record.job, true)}
                    ${createDetailRow('Fecha', record.date_raw)}
                    ${createDetailRow('Hora', record.time_raw)}
                    ${createDetailRow('Status', `${record.status} — ${record.status_label}`)}
                    ${createDetailRow('Usuario', record.user)}
                    ${createDetailRow('Dispositivo', record.device)}
                    ${createDetailRow('Lado', record.side_label)}
                </div>
            </div>
            ${record.lens_desc ? `
            <div style="background:#f8fafc; padding:16px; border-radius:16px;">
                <h4 style="margin-bottom:12px; color:#3b82f6;"><i class="fas fa-lens"></i> Detalle de Lente</h4>
                <div style="display:grid; grid-template-columns:120px 1fr; gap:12px;">
                    ${createDetailRow('Lente', record.lens_desc)}
                    ${createDetailRow('Blank', record.blank_desc)}
                    ${record.index_val ? createDetailRow('Índice', record.index_val.toFixed(3)) : ''}
                </div>
            </div>
            ` : ''}
            ${record.is_breakage && record.reason_descr ? `
            <div style="background:#fef2f2; padding:16px; border-radius:16px;">
                <h4 style="margin-bottom:12px; color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Quiebra</h4>
                <div style="display:grid; grid-template-columns:120px 1fr; gap:12px;">
                    ${createDetailRow('Causa', record.reason_descr, false, true)}
                    ${createDetailRow('Código', record.reason, false, true)}
                    ${createDetailRow('Dep. BR/RM', record.dep)}
                </div>
            </div>
            ` : ''}
        </div>
    `;
    
    modal.classList.add('active');
}

function createDetailRow(label, value, mono = false, danger = false) {
    if (!value && value !== 0) return '';
    return `
        <div style="font-size:12px; font-weight:600; color:#64748b;">${escapeHtml(label)}:</div>
        <div style="font-size:13px; ${mono ? 'font-family:monospace; font-weight:600;' : ''} ${danger ? 'color:#ef4444;' : ''}">${escapeHtml(String(value))}</div>
    `;
}

async function showBackups() {
    try {
        const response = await fetch('api.php?action=backups');
        const result = await response.json();
        
        if (result.success) {
            const backups = result.backups;
            const container = document.getElementById('backups-list');
            
            if (!Array.isArray(backups) || backups.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:60px;"><i class="fas fa-archive" style="font-size:48px; color:#cbd5e1;"></i><p style="margin-top:16px;">No hay respaldos guardados</p></div>';
            } else {
                const backupFolderText = appData?.backup_folder ? `Carpeta de respaldos: ${escapeHtml(appData.backup_folder)}` : 'Carpeta de respaldos: desconocida';
                container.innerHTML = `
                    <div style="font-size:12px; color:#475569; margin-bottom:14px;">${backupFolderText}</div>
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="padding:12px; text-align:left;">Archivo</th>
                                <th style="padding:12px; text-align:left;">Tamaño</th>
                                <th style="padding:12px; text-align:left;">Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${backups.map(b => {
                                const filename = b.filename || b.name || '';
                                return `
                                <tr style="border-bottom:1px solid #e2e8f0;">
                                    <td style="padding:12px; font-family:monospace; font-size:11px;">${escapeHtml(filename)}</td>
                                    <td style="padding:12px;">${formatFileSize(b.size)}</td>
                                    <td style="padding:12px;">${escapeHtml(b.modified)}</td>
                                </tr>
                            `;
                            }).join('')}
                        </tbody>
                    </table>
                `;
            }
            
            document.getElementById('modal-backups').classList.add('active');
        }
    } catch (error) {
        console.error('Error loading backups:', error);
    }
}

function downloadBackup(filename) {
    alert('Función de descarga de respaldo en desarrollo');
}

async function exportData(type) {
    window.location.href = `api.php?action=export&type=${type}`;
}

function startAutoRefresh() {
    setInterval(() => {
        refreshData();
    }, 30000);
}

function updateStatus(online, error = '') {
    const dot = document.getElementById('status-dot');
    const text = document.getElementById('status-text');
    
    if (online) {
        dot.className = 'fas fa-circle online';
        text.textContent = '🟢 Monitor activo';
    } else {
        dot.className = 'fas fa-circle offline';
        text.textContent = error || '🔴 Desconectado';
    }
}

function formatNumber(num) {
    return num?.toLocaleString() || '0';
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function formatTime(date) {
    return date.toLocaleTimeString('es-CR');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Constantes globales
const STATUS_LABELS = {
    'SBLK': 'Bloqueo', 'PREP': 'Calculado', 'SGEN': 'Generado', 'PRNT': 'Impreso',
    'EDGE': 'Bisel/Edging', 'TRAC': 'Trazado', 'SPOL': 'Pulido', 'SENG': 'Laser/Grabado',
    'PKRX': 'Validación RX', 'WHRX': 'Almacén Bases', 'WHST': 'Almacén Term.', 'BREA': 'QUIEBRA'
};

const STATUS_COLORS = {
    'BREA': '#EF4444', 'SGEN': '#2563EB', 'SPOL': '#10B981',
    'EDGE': '#F59E0B', 'SENG': '#8B5CF6', 'SBLK': '#06B6D4',
    'TRAC': '#F97316', 'PREP': '#3B82F6', 'PRNT': '#14B8A6',
    'PKRX': '#EC4899', 'WHRX': '#64748B', 'WHST': '#94A3B8'
};