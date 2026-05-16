<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Lensware Pro - Monitor de Producción en Vivo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            overflow-x: hidden;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; }

        /* Layout */
        .app-container { display: flex; min-height: 100vh; }

        /* ========== SIDEBAR ========== */
        .sidebar {
            width: 260px;
            background: #0f172a;
            position: fixed;
            height: 100vh;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }

        .logo {
            padding: 24px 20px;
            border-bottom: 1px solid #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .logo i { font-size: 28px; color: #3b82f6; }
        .logo span { font-size: 18px; font-weight: 700; color: white; }
        .logo .pro {
            background: #3b82f6;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            margin-left: 6px;
        }
        .logo small { font-size: 9px; color: #64748b; margin-left: auto; }

        .nav-menu { flex: 1; padding: 20px 12px; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            color: #94a3b8;
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 4px;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 13px;
        }
        .nav-item i { width: 20px; font-size: 16px; }
        .nav-item:hover { background: #1e293b; color: white; }
        .nav-item.active { background: #3b82f6; color: white; }
        .nav-item .badge {
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 20px;
            margin-left: auto;
        }

        .sidebar-footer { padding: 16px; border-top: 1px solid #1e293b; }
        .monitor-status {
            background: #1e293b;
            border-radius: 10px;
            padding: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        .monitor-status i { font-size: 8px; }
        .monitor-status .online { color: #10b981; }
        .monitor-status .offline { color: #ef4444; }
        .monitor-status span { font-size: 12px; color: #cbd5e1; }

        .btn-refresh {
            width: 100%;
            padding: 10px;
            background: #3b82f6;
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 12px;
            transition: all 0.2s;
        }
        .btn-refresh:hover { background: #2563eb; }

        /* ========== MAIN CONTENT ========== */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px 28px;
            min-height: 100vh;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .page-title h1 { font-size: 22px; font-weight: 700; color: #0f172a; }
        .page-title p { font-size: 12px; color: #64748b; margin-top: 4px; }
        .top-actions { display: flex; align-items: center; gap: 12px; }
        .last-update {
            background: #f1f5f9;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            color: #475569;
        }
        .btn-icon {
            background: #f1f5f9;
            border: none;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            cursor: pointer;
            color: #475569;
            transition: all 0.2s;
        }
        .btn-icon:hover { background: #e2e8f0; color: #3b82f6; }

        /* Tabs */
        .tab-content { display: none; animation: fadeIn 0.2s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* ========== KPI CARDS ========== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        @media (max-width: 1400px) { .kpi-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

        .kpi-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -12px rgba(0,0,0,0.15); }
        
        .kpi-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .kpi-icon.blue { background: #eff6ff; color: #3b82f6; }
        .kpi-icon.teal { background: #ecfdf5; color: #10b981; }
        .kpi-icon.red { background: #fef2f2; color: #ef4444; }
        .kpi-icon.orange { background: #fffbeb; color: #f59e0b; }
        .kpi-icon.green { background: #ecfdf5; color: #059669; }
        .kpi-icon.purple { background: #f5f3ff; color: #8b5cf6; }
        .kpi-icon.indigo { background: #eef2ff; color: #6366f1; }
        .kpi-icon.pink { background: #fdf2f8; color: #ec4899; }

        .kpi-info h3 { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 6px; letter-spacing: 0.5px; }
        .kpi-info p { font-size: 26px; font-weight: 800; color: #0f172a; line-height: 1; }

        /* ========== CHARTS ========== */
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 1000px) { .charts-row { grid-template-columns: 1fr; } }

        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #e2e8f0;
            min-height: auto;
        }
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .chart-header h3 { font-size: 14px; font-weight: 700; color: #1e293b; }
        .chart-meta { font-size: 11px; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 20px; }
        .chart-card canvas { width: 100%; height: 260px !important; max-height: 260px !important; display: block; }

        /* ========== INFO ROW (GAUGE + TOP LIST) ========== */
        .info-row {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 24px;
            margin-top: 8px;
        }
        @media (max-width: 800px) { .info-row { grid-template-columns: 1fr; } }

        .gauge-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .gauge-container svg { width: 160px; height: auto; margin-bottom: 8px; }
        .gauge-stats { display: flex; justify-content: center; gap: 24px; margin-top: 12px; }
        .stat-label { font-size: 11px; color: #64748b; display: block; margin-bottom: 4px; }
        .stat-value { font-size: 22px; font-weight: 800; color: #0f172a; }

        .top-list-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #e2e8f0;
        }
        .top-list-card h3 { font-size: 14px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .top-list { display: flex; flex-direction: column; gap: 12px; }
        .top-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 0;
            cursor: pointer;
            transition: all 0.2s;
        }
        .top-item:hover { background: #f8fafc; padding-left: 8px; border-radius: 8px; }
        .top-name { font-weight: 600; font-size: 13px; width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .top-bar-progress { flex: 1; height: 6px; background: #e2e8f0; border-radius: 10px; overflow: hidden; }
        .top-bar-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); border-radius: 10px; transition: width 0.3s; }
        .top-value { font-weight: 700; font-size: 13px; color: #475569; min-width: 50px; text-align: right; }

        /* ========== FILTERS ========== */
        .filters-bar {
            background: white;
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            border: 1px solid #e2e8f0;
        }
        .filter-input, .filter-select {
            padding: 8px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 12px;
            background: white;
            min-width: 130px;
        }
        .filter-input:focus, .filter-select:focus { outline: none; border-color: #3b82f6; }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            cursor: pointer;
            padding: 6px 12px;
            background: #f8fafc;
            border-radius: 30px;
        }
        .btn-secondary {
            padding: 8px 18px;
            background: #f1f5f9;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s;
        }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-primary {
            padding: 8px 20px;
            background: #3b82f6;
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        .btn-primary:hover { background: #2563eb; }

        /* ========== TABLES ========== */
        .table-container {
            background: white;
            border-radius: 14px;
            overflow: auto;
            border: 1px solid #e2e8f0;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 750px;
        }
        .data-table th {
            text-align: left;
            padding: 12px 16px;
            background: #f8fafc;
            font-weight: 700;
            font-size: 12px;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        .data-table td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; }
        .data-table tr:hover td { background: #f8fafc; }
        .data-table tr.breakage td { background: #fef2f2; }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            margin-top: 24px;
        }
        .pagination button {
            padding: 8px 18px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            font-size: 12px;
            transition: all 0.2s;
        }
        .pagination button:hover:not(:disabled) { background: #3b82f6; color: white; border-color: #3b82f6; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Breakages Header */
        .breakages-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .breakages-header h2 { font-size: 18px; font-weight: 700; }
        .table-footer {
            padding: 12px 16px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #475569;
        }

        /* Search Bar */
        .search-bar {
            display: flex;
            align-items: center;
            gap: 14px;
            background: white;
            padding: 12px 24px;
            border-radius: 50px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        .search-bar:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .search-bar i { color: #94a3b8; font-size: 18px; }
        .search-input {
            flex: 1;
            border: none;
            font-size: 14px;
            padding: 8px 0;
            background: transparent;
        }
        .search-input:focus { outline: none; }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 520px;
            max-height: 85vh;
            overflow: hidden;
        }
        .modal-large { max-width: 880px; }
        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }
        .modal-header h2 { font-size: 16px; font-weight: 700; }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #94a3b8;
        }
        .modal-close:hover { color: #ef4444; }
        .modal-body { padding: 20px; overflow-y: auto; max-height: 65vh; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { width: 70px; }
            .sidebar span, .sidebar .badge, .sidebar-footer span, .logo span { display: none; }
            .main-content { margin-left: 70px; padding: 16px; }
            .kpi-grid { gap: 12px; }
            .charts-row { gap: 16px; }
            .chart-card canvas { width: 100%; height: 220px !important; max-height: 220px !important; }
        }

        /* Mejoras para gráficos */
canvas {
    filter: contrast(1.05) saturate(1.1);
}

.chart-card canvas {
    display: block;
    transition: all 0.3s ease;
}

/* Colores más vibrantes para badges */
.badge-status {
    font-weight: 600;
    letter-spacing: 0.3px;
}

/* Mejora del gauge */
#gauge-arc {
    filter: drop-shadow(0 2px 4px rgba(16,185,129,0.3));
    transition: stroke-dashoffset 0.5s ease;
}

/* Mejora de barras de progreso */
.top-bar-fill {
    background: linear-gradient(90deg, #3B82F6, #8B5CF6, #3B82F6);
    background-size: 200% 100%;
    animation: shimmer 2s ease infinite;
}

@keyframes shimmer {
    0% { background-position: 0% 0%; }
    100% { background-position: 200% 0%; }
}
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <i class="fas fa-glasses"></i>
                <span>LENSWARE<span class="pro">PRO</span></span>
                <small>v9.0</small>
            </div>
            <nav class="nav-menu">
                <a href="#" class="nav-item active" data-tab="dashboard"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
                <a href="#" class="nav-item" data-tab="breakages"><i class="fas fa-bug"></i><span>Quiebras</span><span class="badge" id="brea-badge">0</span></a>
                <a href="#" class="nav-item" data-tab="activity"><i class="fas fa-history"></i><span>Actividad</span></a>
                <a href="#" class="nav-item" data-tab="devices"><i class="fas fa-microchip"></i><span>Dispositivos</span></a>
                <a href="#" class="nav-item" data-tab="operators"><i class="fas fa-users"></i><span>Operadores</span></a>
                <a href="#" class="nav-item" data-tab="search"><i class="fas fa-search"></i><span>Buscar</span></a>
            </nav>
            <div class="sidebar-footer">
                <div class="monitor-status" id="monitor-status">
                    <i class="fas fa-circle" id="status-dot"></i>
                    <span id="status-text">Conectando...</span>
                </div>
                <button class="btn-refresh" id="btn-refresh">
                    <i class="fas fa-sync-alt"></i>
                    <span>Actualizar</span>
                </button>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="page-title">
                    <h1 id="page-title">Dashboard</h1>
                    <p id="file-info">Cargando datos...</p>
                </div>
                <div class="top-actions">
                    <div class="last-update"><i class="far fa-clock"></i> <span id="last-update">--:--:--</span></div>
                    <button class="btn-icon" id="btn-export" title="Exportar actividad"><i class="fas fa-download"></i></button>
                    <button class="btn-icon" id="btn-backups" title="Respaldos"><i class="fas fa-archive"></i></button>
                </div>
            </header>

            <!-- Dashboard Tab -->
            <div id="tab-dashboard" class="tab-content active">
                <!-- KPI Cards -->
                <div class="kpi-grid">
                    <div class="kpi-card"><div class="kpi-icon blue"><i class="fas fa-database"></i></div><div class="kpi-info"><h3>Total Registros</h3><p id="kpi-total">0</p></div></div>
                    <div class="kpi-card"><div class="kpi-icon teal"><i class="fas fa-briefcase"></i></div><div class="kpi-info"><h3>Jobs Únicos</h3><p id="kpi-jobs">0</p></div></div>
                    <div class="kpi-card"><div class="kpi-icon red"><i class="fas fa-exclamation-triangle"></i></div><div class="kpi-info"><h3>Jobs c/Quiebra</h3><p id="kpi-brea">0</p></div></div>
                    <div class="kpi-card"><div class="kpi-icon orange"><i class="fas fa-chart-line"></i></div><div class="kpi-info"><h3>Tasa Quiebra</h3><p id="kpi-rate">0%</p></div></div>
                    <div class="kpi-card"><div class="kpi-icon green"><i class="fas fa-user-check"></i></div><div class="kpi-info"><h3>Operadores</h3><p id="kpi-users">0</p></div></div>
                    <div class="kpi-card"><div class="kpi-icon purple"><i class="fas fa-microchip"></i></div><div class="kpi-info"><h3>Dispositivos</h3><p id="kpi-devices">0</p></div></div>
                    <!-- Eventos Quiebras removed by request -->
                    <!-- Tipos Lente removed by request -->
                </div>

                <!-- Charts Row 1 -->
                <div class="charts-row">
                    <div class="chart-card">
                        <div class="chart-header"><h3><i class="fas fa-chart-bar"></i> Actividad por Etapa</h3><span class="chart-meta" id="status-meta"></span></div>
                        <canvas id="chart-status" height="260" style="width:100%; height:260px;"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-header"><h3><i class="fas fa-chart-pie"></i> Top Causas de Quiebra</h3><span class="chart-meta"></span></div>
                        <canvas id="chart-causes" height="260" style="width:100%; height:260px;"></canvas>
                    </div>
                </div>

                <!-- Charts Row 2 -->
                <div class="charts-row">
                    <div class="chart-card">
                        <div class="chart-header"><h3><i class="fas fa-clock"></i> Actividad por Hora</h3></div>
                        <canvas id="chart-hour" height="260" style="width:100%; height:260px;"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-header"><h3><i class="fas fa-chart-simple"></i> Top Dispositivos</h3></div>
                        <canvas id="chart-devices" height="260" style="width:100%; height:260px;"></canvas>
                    </div>
                </div>

                <!-- Gauge & Top List removed -->
            </div>

            <!-- Breakages Tab -->
            <div id="tab-breakages" class="tab-content">
                <div class="breakages-header">
                    <h2><i class="fas fa-bug"></i> Registro de Quiebras</h2>
                    <button class="btn-primary" id="export-breakages-btn"><i class="fas fa-download"></i> Exportar</button>
                </div>
                <div class="filters-bar">
                    <input type="text" id="filter-job" placeholder="🔍 Buscar por Job o Causa..." class="filter-input">
                    <select id="filter-device" class="filter-select"><option value="">📟 Todos los dispositivos</option></select>
                    <select id="filter-user" class="filter-select"><option value="">👤 Todos los usuarios</option></select>
                </div>
                <div class="table-container">
                    <table class="data-table" id="breakages-table">
                        <thead><tr><th>Job</th><th>Fecha</th><th>Hora</th><th>OD/OI</th><th>Causa</th><th>Código</th><th>Usuario</th><th>Dispositivo</th><th>Lente</th></tr></thead>
                        <tbody id="breakages-tbody"></tbody>
                    </table>
                </div>
                <div class="table-footer"><span id="breakages-count">0</span> quiebras registradas</div>
            </div>

            <!-- Activity Tab -->
            <div id="tab-activity" class="tab-content">
                <div class="filters-bar">
                    <select id="act-status" class="filter-select"><option value="">📊 Todos los estados</option></select>
                    <select id="act-device" class="filter-select"><option value="">🖥️ Todos los dispositivos</option></select>
                    <select id="act-user" class="filter-select"><option value="">👥 Todos los usuarios</option></select>
                    <select id="act-side" class="filter-select"><option value="">👁️ Todos los lados</option><option value="R">OD (R)</option><option value="L">OI (L)</option></select>
                    <label class="checkbox-label"><input type="checkbox" id="act-only-brea"> ⚠️ Solo quiebras</label>
                    <input type="text" id="act-search" placeholder="🔍 Buscar..." class="filter-input" style="width:150px">
                    <button id="act-clear" class="btn-secondary">🗑️ Limpiar</button>
                </div>
                <div class="table-container">
                    <table class="data-table" id="activity-table">
                        <thead><tr><th>Job</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>OD/OI</th><th>Usuario</th><th>Dispositivo</th><th>Lente</th></tr></thead>
                        <tbody id="activity-tbody"></tbody>
                    </table>
                </div>
                <div class="pagination"><button id="prev-page" disabled>← Anterior</button><span id="page-info">Página 1</span><button id="next-page">Siguiente →</button></div>
            </div>

            <!-- Devices Tab -->
            <div id="tab-devices" class="tab-content">
                <div class="table-container">
                    <table class="data-table" id="devices-table">
                        <thead><tr><th>Dispositivo</th><th>Total</th><th>Jobs</th><th>Quiebras</th><th>Tasa</th></tr></thead>
                        <tbody id="devices-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Operators Tab -->
            <div id="tab-operators" class="tab-content">
                <div class="table-container">
                    <table class="data-table" id="operators-table">
                        <thead><tr><th>Operador</th><th>Registros</th><th>Jobs</th><th>Quiebras</th><th>Tasa</th><th>Dispositivos</th></tr></thead>
                        <tbody id="operators-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Search Tab -->
            <div id="tab-search" class="tab-content">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="global-search" placeholder="Buscar en Job, Usuario, Lente, Dispositivo, Causa..." class="search-input">
                </div>
                <div class="table-container">
                    <table class="data-table" id="search-table">
                        <thead><tr><th>Job</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>OD/OI</th><th>Usuario</th><th>Dispositivo</th><th>Info</th></tr></thead>
                        <tbody id="search-tbody"></tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="modal-backups" class="modal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-archive"></i> Respaldos</h2><button class="modal-close">&times;</button></div><div class="modal-body"><div id="backups-list"></div></div></div></div>
    <div id="modal-device" class="modal"><div class="modal-content modal-large"><div class="modal-header"><h2 id="modal-device-title">Detalle del Dispositivo</h2><button class="modal-close">&times;</button></div><div class="modal-body"><div id="device-details"></div></div></div></div>
    <div id="modal-detail" class="modal"><div class="modal-content"><div class="modal-header"><h2 id="detail-title">Detalle</h2><button class="modal-close">&times;</button></div><div class="modal-body" id="detail-body"></div></div></div>

    <script src="js/app.js"></script>
</body>
</html>