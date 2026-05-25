<?php
// ─── AUTENTICACIÓN DE ACCESO ───────────────────────────────────────────────
session_start();

define('MASTER_PASSWORD_HASH', password_hash('JimLab*Lensware#_', PASSWORD_BCRYPT));

// Verificar hash fijo (evita comparación directa de texto plano)
$_CORRECT_HASH = '$2y$10$'; // se usa password_verify abajo

$loginError = '';

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['master_key'])) {
    if (password_verify($_POST['master_key'], MASTER_PASSWORD_HASH)) {
        $_SESSION['lensware_auth'] = true;
        $_SESSION['lensware_time'] = time();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = 'Clave incorrecta. Intenta de nuevo.';
    }
}

// Sesión expira en 8 horas
if (isset($_SESSION['lensware_auth']) && (time() - ($_SESSION['lensware_time'] ?? 0)) > 28800) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['lensware_auth'])) {
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lensware Pro — Acceso</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        /* Fondo animado */
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 20% 30%, rgba(59,130,246,0.12) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 70%, rgba(139,92,246,0.10) 0%, transparent 60%);
            pointer-events: none;
        }
        .login-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 24px;
            padding: 48px 44px 44px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 32px 80px rgba(0,0,0,0.5);
            position: relative;
            z-index: 1;
        }
        .login-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
        }
        .login-logo-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            box-shadow: 0 8px 20px rgba(59,130,246,0.35);
            flex-shrink: 0;
        }
        .login-logo-text h1 {
            font-size: 20px;
            font-weight: 800;
            color: white;
            line-height: 1.1;
        }
        .login-logo-text span {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }
        .login-title {
            font-size: 15px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
        }
        .login-subtitle {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 28px;
        }
        .input-group {
            position: relative;
            margin-bottom: 16px;
        }
        .input-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #475569;
            font-size: 15px;
            pointer-events: none;
            transition: color 0.2s;
        }
        .input-group:focus-within i {
            color: #3b82f6;
        }
        .input-group input {
            width: 100%;
            padding: 14px 48px 14px 44px;
            background: #0f172a;
            border: 1.5px solid #334155;
            border-radius: 12px;
            color: white;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            letter-spacing: 0.02em;
        }
        .input-group input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .input-group input::placeholder { color: #475569; }
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #475569;
            cursor: pointer;
            font-size: 15px;
            padding: 4px;
            transition: color 0.2s;
        }
        .toggle-password:hover { color: #94a3b8; }
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 16px rgba(59,130,246,0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }
        .btn-login:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(59,130,246,0.45);
        }
        .btn-login:active { transform: translateY(0); }
        .error-msg {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .login-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 11px;
            color: #334155;
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">
        <div class="login-logo-icon"><i class="fas fa-eye"></i></div>
        <div class="login-logo-text">
            <h1>Lensware Pro</h1>
            <span>Monitor de Producción</span>
        </div>
    </div>
    <p class="login-title">Acceso restringido</p>
    <p class="login-subtitle">Ingresa la clave maestra para continuar.</p>

    <?php if ($loginError): ?>
    <div class="error-msg"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="input-group">
            <i class="fas fa-lock"></i>
            <input type="password" name="master_key" id="master_key"
                   placeholder="Clave maestra" autofocus required>
            <button type="button" class="toggle-password" onclick="togglePwd()" tabindex="-1">
                <i class="fas fa-eye" id="pwd-icon"></i>
            </button>
        </div>
        <button type="submit" class="btn-login">
            <i class="fas fa-sign-in-alt"></i> Ingresar al panel
        </button>
    </form>
    <div class="login-footer">Sistema de Monitoreo Lensware &nbsp;·&nbsp; Rosalesdev91</div>
</div>
<script>
function togglePwd() {
    const inp = document.getElementById('master_key');
    const ico = document.getElementById('pwd-icon');
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fas fa-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'fas fa-eye'; }
}
</script>
</body>
</html>
<?php
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Lensware Pro - Monitor de Producción en Vivo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            overflow-x: hidden;
        }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; }

        .app-container { display: flex; min-height: 100vh; }

        :root {
            --sidebar-w-collapsed: 72px;
            --sidebar-w-expanded: 260px;
        }

        /* ========== SIDEBAR (colapsado → expande al hover) ========== */
        .sidebar {
            width: var(--sidebar-w-collapsed);
            background: #0f172a;
            position: fixed;
            height: 100vh;
            display: flex;
            flex-direction: column;
            z-index: 200;
            overflow: hidden;
            transition: width 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                        box-shadow 0.28s ease;
            box-shadow: 2px 0 12px rgba(0, 0, 0, 0.08);
        }
        .sidebar:hover {
            width: var(--sidebar-w-expanded);
            box-shadow: 8px 0 32px rgba(0, 0, 0, 0.22);
        }

        .logo {
            padding: 20px 16px;
            border-bottom: 1px solid #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 72px;
            flex-shrink: 0;
        }
        .logo i {
            font-size: 26px;
            color: #3b82f6;
            flex-shrink: 0;
            width: 40px;
            text-align: center;
        }
        .logo-brand {
            display: flex;
            flex-direction: column;
            gap: 2px;
            opacity: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.22s ease 0.04s, width 0.28s ease;
        }
        .sidebar:hover .logo-brand {
            opacity: 1;
            width: auto;
            flex: 1;
        }
        .logo span { font-size: 16px; font-weight: 700; color: white; line-height: 1.2; }
        .logo .pro {
            background: #3b82f6;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 9px;
            margin-left: 4px;
            vertical-align: middle;
        }
        .logo small { font-size: 9px; color: #64748b; }

        .nav-menu {
            flex: 1;
            padding: 16px 10px;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            color: #94a3b8;
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 4px;
            transition: background 0.2s, color 0.2s;
            font-weight: 500;
            font-size: 13px;
            position: relative;
            white-space: nowrap;
        }
        .nav-item i {
            width: 24px;
            font-size: 17px;
            text-align: center;
            flex-shrink: 0;
        }
        .nav-item .nav-label {
            opacity: 0;
            width: 0;
            overflow: hidden;
            transition: opacity 0.2s ease 0.05s, width 0.28s ease;
        }
        .sidebar:hover .nav-item .nav-label {
            opacity: 1;
            width: auto;
            flex: 1;
        }
        .sidebar:not(:hover) .nav-item {
            justify-content: center;
            padding-left: 12px;
            padding-right: 12px;
        }
        .nav-item:hover { background: #1e293b; color: white; }
        .nav-item.active { background: #3b82f6; color: white; }
        .nav-item .badge {
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 20px;
            margin-left: auto;
            flex-shrink: 0;
            opacity: 0;
            width: 0;
            overflow: hidden;
            transition: opacity 0.2s, width 0.28s;
        }
        .sidebar:hover .nav-item .badge {
            opacity: 1;
            width: auto;
        }
        .sidebar:not(:hover) .nav-item[data-tab="breakages"] .badge {
            position: absolute;
            top: 8px;
            left: 28px;
            width: 8px;
            height: 8px;
            min-width: 8px;
            padding: 0;
            font-size: 0;
            opacity: 1;
            border-radius: 50%;
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #475569;
            letter-spacing: 1px;
            padding: 12px 14px 6px;
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height 0.25s, opacity 0.2s, padding 0.25s;
        }
        .sidebar:hover .nav-section-label {
            max-height: 40px;
            opacity: 1;
            padding: 12px 14px 6px;
        }

        .sidebar-footer {
            padding: 12px 10px 16px;
            border-top: 1px solid #1e293b;
            flex-shrink: 0;
        }
        .monitor-status {
            background: #1e293b;
            border-radius: 10px;
            padding: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            justify-content: center;
            min-height: 40px;
        }
        .sidebar:hover .monitor-status { justify-content: flex-start; }
        .monitor-status i { font-size: 8px; flex-shrink: 0; }
        .monitor-status .online { color: #10b981; }
        .monitor-status .offline { color: #ef4444; }
        .monitor-status .status-label {
            font-size: 12px;
            color: #cbd5e1;
            opacity: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.2s, width 0.28s;
        }
        .sidebar:hover .monitor-status .status-label {
            opacity: 1;
            width: auto;
        }

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
            transition: background 0.2s;
        }
        .btn-refresh .btn-label {
            opacity: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.2s, width 0.28s;
        }
        .sidebar:hover .btn-refresh .btn-label {
            opacity: 1;
            width: auto;
        }
        .btn-refresh:hover { background: #2563eb; }

        /* ========== MAIN CONTENT ========== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-w-collapsed);
            padding: 20px 28px 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar:hover ~ .main-content {
            margin-left: var(--sidebar-w-expanded);
        }

        /* ========== FIRMA ========== */
        .firma {
            margin-top: auto;
            text-align: center;
            padding: 28px 20px 32px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            background: linear-gradient(180deg, transparent 0%, #f8fafc 100%);
        }
        .firma p {
            margin-top: 6px;
            font-size: 12px;
            color: #94a3b8;
            font-weight: 400;
        }
        .main-body { flex: 1; width: 100%; }

        @media (max-width: 768px) {
            .main-content { padding: 16px 14px 0; }
            .sidebar:hover ~ .main-content { margin-left: var(--sidebar-w-collapsed); }
            .sidebar:hover { width: var(--sidebar-w-expanded); position: fixed; }
        }

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
        .last-update { background: #f1f5f9; padding: 8px 16px; border-radius: 30px; font-size: 12px; color: #475569; }
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
        @media (max-width: 900px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

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
            flex-shrink: 0;
        }
        .kpi-icon.blue   { background: #eff6ff; color: #3b82f6; }
        .kpi-icon.teal   { background: #ecfdf5; color: #10b981; }
        .kpi-icon.red    { background: #fef2f2; color: #ef4444; }
        .kpi-icon.orange { background: #fffbeb; color: #f59e0b; }
        .kpi-icon.green  { background: #ecfdf5; color: #059669; }
        .kpi-icon.purple { background: #f5f3ff; color: #8b5cf6; }
        .kpi-info h3 { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 6px; letter-spacing: 0.5px; }
        .kpi-info p  { font-size: 26px; font-weight: 800; color: #0f172a; line-height: 1; }

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
            overflow: hidden;
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
        .chart-card .chart-wrap canvas { height: 100% !important; max-height: 240px !important; }
        #hist-content .chart-card { min-height: 280px; }
        #top-jobs-brea-table tbody tr.top-job-row:hover { background: #eff6ff; }
        #top-jobs-brea-wrap { margin-top: 8px; }
        .chart-card-wide { grid-column: 1 / -1; }
        .chart-card-wide canvas { height: 300px !important; max-height: 300px !important; }
        .chart-empty {
            text-align: center;
            padding: 48px 20px;
            color: #94a3b8;
            font-size: 14px;
        }
        .chart-empty.hidden { display: none; }

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

        .table-container.table-scroll {
            max-height: min(440px, 55vh);
            overflow: auto;
        }
        .table-container.table-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            box-shadow: 0 1px 0 #e2e8f0;
        }
        .table-footer.pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 12px 16px;
            background: white;
            border: 1px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 14px 14px;
            font-size: 13px;
            color: #64748b;
        }
        .table-footer.pagination button {
            padding: 6px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
        }
        .table-footer.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .table-footer.pagination button:not(:disabled):hover { border-color: #3b82f6; color: #1d4ed8; }

        .chart-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .chart-type-select {
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            color: #475569;
            cursor: pointer;
            max-width: 110px;
        }
        .charts-prefs-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding: 10px 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 12px;
            color: #64748b;
        }
        .charts-prefs-bar label { font-weight: 700; color: #475569; }

        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-brea { background: #fef2f2; color: #ef4444; border: 1px solid #fca5a5; }

        .table-footer {
            padding: 12px 20px;
            font-size: 12px;
            color: #64748b;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            border-radius: 0 0 14px 14px;
        }
        .pagination {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            background: white;
            border-radius: 0 0 14px 14px;
            border-top: 1px solid #e2e8f0;
        }
        .pagination button {
            padding: 6px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .pagination button:hover:not(:disabled) { background: #3b82f6; color: white; border-color: #3b82f6; }
        .pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .pagination span { font-size: 12px; color: #64748b; }

        /* ========== BREAKAGES ========== */
        .breakages-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .breakages-header h2 { font-size: 18px; font-weight: 700; }

        /* ========== MODALS ========== */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal.active { display: flex; z-index: 1100; }
        .modal-content {
            position: relative;
            z-index: 1101;
            background: white;
            border-radius: 20px;
            width: 100%;
            max-width: 600px;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .modal-large { max-width: 900px; }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 { font-size: 18px; font-weight: 700; }
        .modal-close {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover { background: #e2e8f0; }
        .modal-body { padding: 24px; overflow-y: auto; }

        /* ========== SEARCH ========== */
        .search-bar {
            position: relative;
            margin-bottom: 20px;
        }
        .search-bar i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .search-input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            background: white;
        }
        .search-input:focus { outline: none; border-color: #3b82f6; }

        .search-status {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .search-status.hidden { display: none; }
        .search-status.loading { background: #eff6ff; color: #1d4ed8; }
        .search-status.ok { background: #f0fdf4; color: #166534; }
        .search-status.empty { background: #f8fafc; color: #64748b; }
        .search-status.error { background: #fef2f2; color: #991b1b; }

        .search-job-summary {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 20px;
            color: white;
        }
        .search-job-summary.hidden { display: none; }
        .search-job-summary h2 { font-size: 22px; font-weight: 800; margin-bottom: 8px; }
        .search-job-summary p { font-size: 13px; color: #94a3b8; }
        .search-job-summary .search-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 14px;
        }
        .search-job-summary .search-meta span {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .search-source-block {
            margin-bottom: 24px;
        }
        .search-source-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        .search-source-header h3 {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .search-source-header .tag {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #475569;
        }
        .search-source-header .tag.live { background: #dbeafe; color: #1d4ed8; }
        .search-source-header .tag.backup { background: #fef3c7; color: #b45309; }

        /* ========== UPLOAD ========== */
        .upload-container {
            max-width: 680px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .upload-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 20px;
            padding: 48px 32px;
            text-align: center;
            transition: all 0.2s;
            background: white;
            cursor: pointer;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .upload-zone i { font-size: 48px; color: #94a3b8; margin-bottom: 16px; display: block; }
        .upload-zone h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .upload-zone p { font-size: 13px; color: #64748b; margin-bottom: 20px; }
        .upload-zone input[type="file"] { display: none; }
        .upload-filename {
            display: none;
            align-items: center;
            gap: 10px;
            background: #ecfdf5;
            padding: 12px 20px;
            border-radius: 14px;
            font-size: 13px;
            color: #166534;
            font-weight: 600;
        }
        .upload-filename.visible { display: flex; }
        .upload-progress {
            display: none;
            background: #f1f5f9;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .upload-progress.visible { display: block; }
        .upload-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 10px;
            width: 0%;
            transition: width 0.3s;
        }
        .upload-result {
            display: none;
            padding: 16px 20px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .upload-result.visible { display: flex; }
        .upload-result.success { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
        .upload-result.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .btn-upload-send {
            padding: 14px 28px;
            background: #3b82f6;
            border: none;
            border-radius: 14px;
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            transition: all 0.2s;
        }
        .btn-upload-send:hover:not(:disabled) { background: #2563eb; }
        .btn-upload-send:disabled { opacity: 0.5; cursor: not-allowed; }
        .upload-info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            font-size: 13px;
            color: #475569;
            line-height: 1.8;
        }
        .upload-info-box h4 { font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 10px; }
        .upload-info-box code {
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            color: #1e293b;
        }

        /* ========== HISTÓRICO TAB ========== */
        .hist-panel {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .hist-panel h3 {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hist-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
        }
        .hist-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .hist-field label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
        }
        .hist-field select,
        .hist-field input[type="date"],
        .hist-field input[type="number"] {
            padding: 9px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            min-width: 160px;
        }
        .hist-field select:focus,
        .hist-field input:focus { outline: none; border-color: #3b82f6; }
        .hist-hour-range {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hist-hour-range input { min-width: 80px; width: 80px; }
        .hist-hour-range span { font-size: 12px; color: #64748b; font-weight: 600; }
        .btn-hist-load {
            padding: 9px 22px;
            background: #3b82f6;
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            height: fit-content;
        }
        .btn-hist-load:hover:not(:disabled) { background: #2563eb; }
        .btn-hist-load:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-hist-reset {
            padding: 9px 16px;
            background: #f1f5f9;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
            height: fit-content;
        }
        .btn-hist-reset:hover { background: #e2e8f0; }

        .hist-status-bar {
            margin-top: 14px;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hist-status-bar.loading { background: #eff6ff; color: #1d4ed8; }
        .hist-status-bar.loaded  { background: #f0fdf4; color: #166534; }
        .hist-status-bar.error   { background: #fef2f2; color: #991b1b; }
        .hist-status-bar.hidden  { display: none; }

        .hist-viewing-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .hist-viewing-banner.hidden { display: none; }
        .hist-banner-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .hist-banner-info i { font-size: 20px; color: #f59e0b; }
        .hist-banner-text h4 { font-size: 13px; font-weight: 700; color: white; }
        .hist-banner-text p  { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .btn-hist-close {
            padding: 7px 16px;
            background: #ef4444;
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .btn-hist-close:hover { background: #dc2626; }

        .hist-mode-toggle {
            display: inline-flex;
            gap: 6px;
            padding: 4px;
            background: #f1f5f9;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .hist-mode-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            background: transparent;
            color: #64748b;
            transition: all 0.2s;
        }
        .hist-mode-btn.active {
            background: white;
            color: #1d4ed8;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .hist-mode-panel.hidden { display: none !important; }
        .hist-day-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
            width: 100%;
        }
        .hist-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .hist-preset-btn {
            padding: 6px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
        }
        .hist-preset-btn:hover { border-color: #3b82f6; color: #1d4ed8; }
        .hist-date-input {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            min-width: 150px;
        }
        .hist-compare-table { margin-bottom: 24px; }
        .hist-compare-table h3 { font-size: 15px; font-weight: 700; margin-bottom: 12px; color: #1e293b; }

        /* KPI mini para histórico */
        .hist-kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        @media (max-width: 1200px) { .hist-kpi-row { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 700px)  { .hist-kpi-row { grid-template-columns: repeat(2, 1fr); } }

        .hist-kpi-card {
            background: white;
            border-radius: 14px;
            padding: 14px 16px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .hist-kpi-card h4 { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 8px; }
        .hist-kpi-card p  { font-size: 28px; font-weight: 800; color: #0f172a; line-height: 1; }
        .hist-kpi-card.red p { color: #ef4444; }

        .hist-charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 900px) { .hist-charts-row { grid-template-columns: 1fr; } }

        .hist-empty {
            text-align: center;
            padding: 80px 20px;
            color: #94a3b8;
        }
        .hist-empty i { font-size: 56px; margin-bottom: 16px; display: block; }
        .hist-empty h3 { font-size: 18px; font-weight: 700; color: #475569; margin-bottom: 8px; }
        .hist-empty p  { font-size: 13px; }

        /* Spinner */
        @keyframes spin { to { transform: rotate(360deg); } }
        .fa-spin-custom { animation: spin 0.8s linear infinite; }
        
        /* Backup day card list */
        .day-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .day-chip {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.15s;
            background: #f1f5f9;
            color: #475569;
        }
        .day-chip:hover { background: #e2e8f0; }
        .day-chip.today { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .day-chip.selected { background: #3b82f6; color: white; border-color: #3b82f6; }
        .day-chip.daily   { position: relative; }
        .day-chip.daily::after {
            content: '✓';
            font-size: 9px;
            position: absolute;
            top: -3px;
            right: -3px;
            background: #10b981;
            color: white;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
<div class="app-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">
            <i class="fas fa-glasses"></i>
            <div class="logo-brand">
                <span>LENSWARE<span class="pro">MONITOR PRO</span></span>
                <small>v1.0</small>
            </div>
        </div>
        <nav class="nav-menu">
            <div class="nav-section-label">En Vivo</div>
            <a href="#" class="nav-item active" data-tab="dashboard" title="Dashboard"><i class="fas fa-chart-pie"></i><span class="nav-label">Dashboard</span></a>
            <a href="#" class="nav-item" data-tab="breakages" title="Quiebras"><i class="fas fa-bug"></i><span class="nav-label">Quiebras</span><span class="badge" id="brea-badge">0</span></a>
            <a href="#" class="nav-item" data-tab="activity" title="Actividad"><i class="fas fa-history"></i><span class="nav-label">Actividad</span></a>
            <a href="#" class="nav-item" data-tab="devices" title="Dispositivos"><i class="fas fa-microchip"></i><span class="nav-label">Equipos</span></a>
            <a href="#" class="nav-item" data-tab="operators" title="Operadores"><i class="fas fa-users"></i><span class="nav-label">Operadores</span></a>
            <a href="#" class="nav-item" data-tab="search" title="Buscar"><i class="fas fa-search"></i><span class="nav-label">Buscar</span></a>
            <div class="nav-section-label" style="margin-top:8px;">Análisis</div>
            <a href="#" class="nav-item" data-tab="historico" title="Histórico"><i class="fas fa-calendar-alt"></i><span class="nav-label">Histórico</span></a>
            <a href="#" class="nav-item" data-tab="upload" title="Importar CSV"><i class="fas fa-upload"></i><span class="nav-label">Importar CSV</span></a>
        </nav>
        <div class="sidebar-footer">
            <div class="monitor-status" id="monitor-status">
                <i class="fas fa-circle" id="status-dot"></i>
                <span id="status-text" class="status-label">Conectando...</span>
            </div>
            <button class="btn-refresh" id="btn-refresh" title="Actualizar datos">
                <i class="fas fa-sync-alt"></i>
                <span class="btn-label">Actualizar</span>
            </button>
            <form method="POST" style="margin-top:8px;">
                <button type="submit" name="logout" value="1"
                    class="btn-refresh"
                    style="background:#ef4444;width:100%;"
                    title="Cerrar sesión"
                    onclick="return confirm('¿Cerrar sesión?')">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="btn-label">Cerrar sesión</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="main-body">
        <!-- Top Bar -->
        <header class="top-bar">
            <div class="page-title">
                <h1 id="page-title">Dashboard</h1>
                <p id="file-info">Cargando datos desde REPORTS...</p>
                <p id="reports-folder" style="font-size:11px;color:#64748b;margin-top:2px;">📂 REPORTS: \\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS</p>
                <p id="backup-folder" style="font-size:11px;color:#94a3b8;margin-top:2px;">Carpeta de respaldos: cargando...</p>
            </div>
            <div class="top-actions">
                <div class="last-update"><i class="far fa-clock"></i> <span id="last-update">--:--:--</span></div>
                <button class="btn-icon" id="btn-export" title="Exportar actividad"><i class="fas fa-download"></i></button>
                <button class="btn-icon" id="btn-backups" title="Respaldos"><i class="fas fa-archive"></i></button>
            </div>
        </header>

        <!-- =================== DASHBOARD TAB =================== -->
        <div id="tab-dashboard" class="tab-content active">
            <div class="kpi-grid">
                <div class="kpi-card"><div class="kpi-icon blue"><i class="fas fa-database"></i></div><div class="kpi-info"><h3>Total Registros</h3><p id="kpi-total">0</p></div></div>
                <div class="kpi-card"><div class="kpi-icon teal"><i class="fas fa-briefcase"></i></div><div class="kpi-info"><h3>Jobs Únicos</h3><p id="kpi-jobs">0</p></div></div>
                <div class="kpi-card"><div class="kpi-icon red"><i class="fas fa-exclamation-triangle"></i></div><div class="kpi-info"><h3>Órdenes c/Quiebra</h3><p id="kpi-brea">0</p><small style="color:#94a3b8;font-size:11px;">Órdenes únicas</small></div></div>
                <div class="kpi-card"><div class="kpi-icon red" style="background:#fff1f2;"><i class="fas fa-bolt" style="color:#dc2626;"></i></div><div class="kpi-info"><h3>Eventos Quiebra</h3><p id="kpi-brea-eventos">0</p><small style="color:#94a3b8;font-size:11px;">Incidentes en el período</small></div></div>
                <div class="kpi-card"><div class="kpi-icon red" style="background:#fef2f2;"><i class="fas fa-eye-slash" style="color:#dc2626;"></i></div><div class="kpi-info"><h3>Total Lentes Quebrados</h3><p id="kpi-lentes-brea">0</p><small style="color:#94a3b8;font-size:11px;">Lentes individuales</small></div></div>
                <div class="kpi-card"><div class="kpi-icon orange"><i class="fas fa-chart-line"></i></div><div class="kpi-info"><h3>Tasa Quiebra</h3><p id="kpi-rate">0%</p></div></div>
                <div class="kpi-card"><div class="kpi-icon green"><i class="fas fa-user-check"></i></div><div class="kpi-info"><h3>Operadores</h3><p id="kpi-users">0</p></div></div>
                <div class="kpi-card"><div class="kpi-icon purple"><i class="fas fa-microchip"></i></div><div class="kpi-info"><h3>Equipos</h3><p id="kpi-devices">0</p></div></div>
            </div>
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-header"><h3><i class="fas fa-chart-bar"></i> Actividad por Etapa</h3><div class="chart-header-actions"><select class="chart-type-select" data-chart-key="status" title="Tipo de gráfica"><option value="bar">Barras</option><option value="doughnut">Pastel</option><option value="line">Líneas</option></select><span class="chart-meta" id="status-meta"></span></div></div>
                    <canvas id="chart-status" height="260" style="width:100%;height:260px;"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-header"><h3><i class="fas fa-chart-pie"></i> Causas de Quiebra</h3><div class="chart-header-actions"><select class="chart-type-select" data-chart-key="causes"><option value="doughnut">Pastel</option><option value="bar">Barras</option><option value="line">Líneas</option></select><span class="chart-meta" id="causes-meta">Top 10</span></div></div>
                    <canvas id="chart-causes" height="260" style="width:100%;height:260px;"></canvas>
                </div>
            </div>
            <div class="charts-prefs-bar">
                <label><i class="fas fa-chart-area"></i> Gráficas</label>
                <span>Cada tarjeta permite elegir barras, pastel o líneas. La preferencia se guarda en este navegador.</span>
            </div>
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-header"><h3><i class="fas fa-clock"></i> Actividad por Hora</h3><div class="chart-header-actions"><select class="chart-type-select" data-chart-key="hour"><option value="line">Líneas</option><option value="bar">Barras</option><option value="doughnut">Pastel</option></select></div></div>
                    <canvas id="chart-hour" height="260" style="width:100%;height:260px;"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-header"><h3><i class="fas fa-chart-simple"></i> Top Equipos</h3><div class="chart-header-actions"><select class="chart-type-select" data-chart-key="devices"><option value="bar-h">Barras H</option><option value="bar">Barras</option><option value="line">Líneas</option></select></div></div>
                    <canvas id="chart-devices" height="260" style="width:100%;height:260px;"></canvas>
                </div>
            </div>
            <div class="charts-row">
                <div class="chart-card chart-card-wide">
                    <div class="chart-header">
                        <h3><i class="fas fa-trophy" style="color:#ef4444;"></i> Top órdenes con quiebras</h3>
                        <span class="chart-meta" id="top-jobs-brea-meta"></span>
                    </div>
                    <p id="top-jobs-brea-empty" class="chart-empty hidden">Sin quiebras registradas en este período</p>
                    <div class="table-container table-scroll" id="top-jobs-brea-wrap">
                        <table class="data-table" id="top-jobs-brea-table">
                            <thead><tr><th>#</th><th>Job / Orden</th><th>Eventos quiebra</th><th></th></tr></thead>
                            <tbody id="top-jobs-brea-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- =================== BREAKAGES TAB =================== -->
        <div id="tab-breakages" class="tab-content">
            <div class="breakages-header">
                <h2><i class="fas fa-bug"></i> Registro de Quiebras</h2>
                <button class="btn-primary" id="export-breakages-btn"><i class="fas fa-download"></i> Exportar</button>
            </div>
            <div class="filters-bar">
                <input type="text" id="filter-job" placeholder="🔍 Buscar por Job o Causa..." class="filter-input">
                <select id="filter-user" class="filter-select"><option value="">👤 Todos los usuarios</option></select>
            </div>
            <div class="table-container table-scroll">
                <table class="data-table" id="breakages-table">
                    <thead><tr><th>Job</th><th>Fecha</th><th>Hora</th><th>OD/OI</th><th>Causa</th><th>Usuario</th><th>Lente</th><th>Blank description</th></tr></thead>
                    <tbody id="breakages-tbody"></tbody>
                </table>
            </div>
            <div class="table-footer pagination" id="brea-pagination">
                <button type="button" id="brea-prev-page">← Anterior</button>
                <span id="brea-page-info">Página 1</span>
                <button type="button" id="brea-next-page">Siguiente →</button>
            </div>
            <div class="table-footer"><span id="breakages-count">0</span> órdenes únicas &nbsp;|&nbsp; <span id="breakages-eventos-count">0</span> eventos &nbsp;|&nbsp; <span id="breakages-lentes-count">0</span> lentes quebrados</div>
        </div>

        <!-- =================== ACTIVITY TAB =================== -->
        <div id="tab-activity" class="tab-content">
            <div class="filters-bar">
                <select id="act-status" class="filter-select"><option value="">📊 Todos los estados</option></select>
                <select id="act-device" class="filter-select"><option value="">🖥️ Todos los equiposs</option></select>
                <select id="act-user" class="filter-select"><option value="">👥 Todos los usuarios</option></select>
                <select id="act-side" class="filter-select"><option value="">👁️ Todos los lados</option><option value="R">OD (R)</option><option value="L">OI (L)</option></select>
                <label class="checkbox-label"><input type="checkbox" id="act-only-brea"> ⚠️ Solo quiebras</label>
                <input type="text" id="act-search" placeholder="🔍 Buscar..." class="filter-input" style="width:150px">
                <button id="act-clear" class="btn-secondary">🗑️ Limpiar</button>
            </div>
            <div class="table-container table-scroll">
                <table class="data-table" id="activity-table">
                    <thead><tr><th>Job</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>OD/OI</th><th>Usuario</th><th>Dispositivo</th><th>Lente</th><th>Blank description</th></tr></thead>
                    <tbody id="activity-tbody"></tbody>
                </table>
            </div>
            <div class="pagination">
                <button id="prev-page" disabled>← Anterior</button>
                <span id="page-info">Página 1</span>
                <button id="next-page">Siguiente →</button>
            </div>
        </div>

        <!-- =================== DEVICES TAB =================== -->
        <div id="tab-devices" class="tab-content">
            <div class="table-container table-scroll">
                <table class="data-table" id="devices-table">
                    <thead><tr><th>Dispositivo</th><th>Total</th><th>Jobs</th><th>Prom. x hora</th><th>Disponibilidad</th><th>Órdenes c/quiebra</th><th>Eventos</th></tr></thead>
                    <tbody id="devices-tbody"></tbody>
                </table>
            </div>
        </div>

        <!-- =================== OPERATORS TAB =================== -->
        <div id="tab-operators" class="tab-content">
            <div class="table-container table-scroll">
                <table class="data-table" id="operators-table">
                    <thead><tr><th>Operador</th><th>Registros</th><th>Jobs</th><th>Equipos</th></tr></thead>
                    <tbody id="operators-tbody"></tbody>
                </table>
            </div>
        </div>

        <!-- =================== SEARCH TAB =================== -->
        <div id="tab-search" class="tab-content">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="global-search" placeholder="Buscar por número de Job (incluye backups de días anteriores)..." class="search-input">
            </div>
            <div id="search-status" class="search-status hidden">
                <i class="fas fa-circle-notch fa-spin-custom" id="search-status-icon"></i>
                <span id="search-status-text"></span>
            </div>
            <div id="search-job-summary" class="search-job-summary hidden"></div>
            <div id="search-results">
                <p style="text-align:center;padding:48px 20px;color:#94a3b8;font-size:14px;">
                    Ingresa un número de Job para ver todo su historial en datos en vivo y en respaldos de días anteriores.
                </p>
            </div>
        </div>

        <!-- =================== HISTÓRICO TAB =================== -->
        <div id="tab-historico" class="tab-content">

            <!-- Banner cuando hay datos históricos cargados -->
            <div class="hist-viewing-banner hidden" id="hist-banner">
                <div class="hist-banner-info">
                    <i class="fas fa-calendar-check"></i>
                    <div class="hist-banner-text">
                        <h4 id="hist-banner-title">Viendo datos históricos</h4>
                        <p id="hist-banner-sub">Archivo: —</p>
                    </div>
                </div>
                <button class="btn-hist-close" id="btn-hist-close">
                    <i class="fas fa-times"></i> Volver al en vivo
                </button>
            </div>

            <!-- Panel de selección -->
            <div class="hist-panel">
                <h3><i class="fas fa-filter" style="color:#3b82f6;"></i> Seleccionar período</h3>

                <div class="hist-mode-toggle">
                    <button type="button" class="hist-mode-btn active" data-hist-mode="day" id="hist-mode-day-btn">Un día</button>
                    <button type="button" class="hist-mode-btn" data-hist-mode="range" id="hist-mode-range-btn">Rango / Mes</button>
                </div>

                <div class="hist-controls">

                    <div id="hist-mode-day" class="hist-mode-panel hist-day-fields">
                    <!-- Selector de fecha rápida por día disponible -->
                    <div class="hist-field" style="flex:1;min-width:280px;">
                        <label>📅 Calendario</label>
                        <div id="hist-day-picker" class="day-picker">
                            <span style="font-size:12px;color:#94a3b8;padding:6px 0;">Cargando backups...</span>
                        </div>
                    </div>

                    <!-- Selector de backup específico del día elegido -->
                    <div class="hist-field">
                        <label>Backup del día</label>
                        <select id="hist-backup-select" class="filter-select" style="min-width:260px;">
                            <option value="">— Seleccionar día primero —</option>
                        </select>
                    </div>
                    </div>

                    <div id="hist-mode-range" class="hist-mode-panel hidden" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;width:100%;">
                        <div class="hist-field">
                            <label>Desde</label>
                            <input type="date" id="hist-date-from" class="hist-date-input">
                        </div>
                        <div class="hist-field">
                            <label>Hasta</label>
                            <input type="date" id="hist-date-to" class="hist-date-input">
                        </div>
                        <div class="hist-field">
                            <label>Atajos</label>
                            <div class="hist-presets">
                                <button type="button" class="hist-preset-btn" data-hist-preset="7">7 días</button>
                                <button type="button" class="hist-preset-btn" data-hist-preset="30">30 días</button>
                                <button type="button" class="hist-preset-btn" data-hist-preset="month">Este mes</button>
                            </div>
                        </div>
                    </div>

                    <!-- Rango horario -->
                    <div class="hist-field">
                        <label>Rango de hora (opcional)</label>
                        <div class="hist-hour-range">
                            <input type="number" id="hist-hour-from" min="0" max="23" placeholder="De (0)" title="Hora inicio (0-23)">
                            <span>a</span>
                            <input type="number" id="hist-hour-to" min="0" max="23" placeholder="A (23)" title="Hora fin (0-23)">
                        </div>
                    </div>

                    <!-- Botones -->
                    <button class="btn-hist-load" id="btn-hist-load" disabled>
                        <i class="fas fa-search"></i> Visualizar
                    </button>
                    <button class="btn-hist-reset" id="btn-hist-reset" title="Limpiar filtros histórico">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>

                <!-- Barra de estado -->
                <div class="hist-status-bar hidden" id="hist-status-bar">
                    <i class="fas fa-circle-notch fa-spin-custom" id="hist-status-icon"></i>
                    <span id="hist-status-text">Cargando...</span>
                </div>
            </div>

            <!-- Contenido histórico (vacío por defecto) -->
            <div id="hist-content">
                <div class="hist-empty">
                    <i class="fas fa-calendar-alt" style="color:#cbd5e1;"></i>
                    <h3>Selecciona un día para comenzar</h3>
                    <p>Elige un día de producción arriba. <strong>Hoy</strong> puede usar datos en vivo o un respaldo puntual.<br>
                       Días anteriores usan el respaldo diario oficial (23:59) cuando existe.</p>
                </div>
            </div>
        </div>

        <!-- =================== UPLOAD TAB =================== -->
        <div id="tab-upload" class="tab-content">
            <div class="upload-container">
                <div class="upload-zone" id="upload-zone">
                    <i class="fas fa-file-csv"></i>
                    <h3>Importar CSV manualmente (opcional)</h3>
                    <p>El dashboard lee automáticamente desde <strong>REPORTS</strong> en la red.<br>
                       Usa esta pestaña solo si quieres cargar un archivo distinto sin esperar al monitor.</p>
                    <button class="btn-primary" onclick="document.getElementById('csv-file-input').click()">
                        <i class="fas fa-folder-open"></i> Seleccionar archivo
                    </button>
                    <input type="file" id="csv-file-input" accept=".csv">
                </div>
                <div class="upload-filename" id="upload-filename">
                    <i class="fas fa-file-csv" style="color:#10b981;"></i>
                    <span id="upload-filename-text">archivo.csv</span>
                </div>
                <div class="upload-progress" id="upload-progress">
                    <div class="upload-progress-bar" id="upload-progress-bar"></div>
                </div>
                <div class="upload-result" id="upload-result">
                    <i id="upload-result-icon" class="fas fa-check-circle"></i>
                    <span id="upload-result-text"></span>
                </div>
                <button class="btn-upload-send" id="btn-upload-send" disabled>
                    <i class="fas fa-cloud-upload-alt"></i> Subir y procesar CSV
                </button>
                <div class="upload-info-box">
                    <h4><i class="fas fa-info-circle" style="color:#3b82f6;"></i> Archivos aceptados</h4>
                    Prefijos válidos: <code>UNI_PROD_ALL_ACT_</code> · <code>UNI_PROD_SIMPLE_ACT_</code><br>
                    Formato: <code>.csv</code> · Codificación: UTF-8, ISO-8859-1 o Windows-1252<br>
                    Tamaño máximo: <code>50 MB</code><br><br>
                    <strong>Carpeta automática (producción):</strong><br>
                    <code>\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS</code><br>
                    Lensware escribe ahí los CSV; el monitor local los detecta cada pocos segundos.
                </div>
            </div>
        </div>

        <!-- Firma -->
        <div class="firma">
            Sistema de Monitoreo Lensware | © <?php echo date("Y"); ?>
            <p>Desarrollado Por: Nestor Rosales | Rosalesdev91</p>
        </div>
        </div>
    </main>
</div>

<!-- Modals -->
<div id="modal-backups" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-archive"></i> Respaldos</h2>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body"><div id="backups-list"></div></div>
    </div>
</div>
<div id="modal-device" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2 id="modal-device-title">Detalle del Dispositivo</h2>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body"><div id="device-details"></div></div>
    </div>
</div>
<div id="modal-detail" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2 id="detail-title">Detalle</h2>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="detail-body"></div>
    </div>
</div>
<div id="modal-job-history" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2 id="modal-job-history-title"><i class="fas fa-history"></i> Historial de orden</h2>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="job-history-body">
            <p style="text-align:center;color:#94a3b8;padding:24px;">Cargando historial...</p>
        </div>
    </div>
</div>

<script src="js/app.js"></script>
<script>
// ========== UPLOAD CSV ==========
(function() {
    const zone      = document.getElementById('upload-zone');
    const input     = document.getElementById('csv-file-input');
    const nameBox   = document.getElementById('upload-filename');
    const nameText  = document.getElementById('upload-filename-text');
    const sendBtn   = document.getElementById('btn-upload-send');
    const progress  = document.getElementById('upload-progress');
    const progBar   = document.getElementById('upload-progress-bar');
    const result    = document.getElementById('upload-result');
    const resultIcon= document.getElementById('upload-result-icon');
    const resultText= document.getElementById('upload-result-text');
    let selectedFile = null;

    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) setFile(file);
    });
    input.addEventListener('change', () => { if (input.files[0]) setFile(input.files[0]); });

    function setFile(file) {
        selectedFile = file;
        nameText.textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
        nameBox.classList.add('visible');
        sendBtn.disabled = false;
        hideResult();
    }

    sendBtn.addEventListener('click', async () => {
        if (!selectedFile) return;
        sendBtn.disabled = true;
        progress.classList.add('visible');
        progBar.style.width = '20%';
        hideResult();
        const secret = document.querySelector('meta[name="upload-secret"]')?.content || '';
        try {
            progBar.style.width = '30%';
            const data = selectedFile.size > 512 * 1024
                ? await uploadCsvInChunks(selectedFile, secret)
                : await uploadCsvDirect(selectedFile, secret);
            progBar.style.width = '100%';
            setTimeout(() => {
                progress.classList.remove('visible'); progBar.style.width = '0%';
                if (data.success) {
                    showResult('success', '✅ ' + (data.message || 'CSV importado correctamente'));
                    setTimeout(() => { if (typeof loadData === 'function') loadData(); }, 800);
                } else {
                    showResult('error', '❌ ' + (data.error || 'Error al subir el archivo'));
                }
                sendBtn.disabled = false;
            }, 400);
        } catch(err) {
            progress.classList.remove('visible');
            showResult('error', '❌ Error de conexión: ' + err.message);
            sendBtn.disabled = false;
        }
    });

    async function uploadCsvDirect(file, secret) {
        const formData = new FormData();
        formData.append('csv_file', file);
        if (secret && secret !== 'changeme') formData.append('secret', secret);
        const headers = (secret && secret !== 'changeme') ? { 'X-Upload-Secret': secret } : {};
        const response = await fetch('api.php?action=upload_csv', { method:'POST', headers, body: formData });
        return await response.json();
    }

    async function uploadCsvInChunks(file, secret) {
        const CHUNK_SIZE = 200 * 1024;
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const uploadId = (self.crypto?.randomUUID?.() || `u${Date.now()}-${Math.random().toString(36).slice(2,8)}`);
        let result = null;

        for (let index = 0; index < totalChunks; index++) {
            const chunk = file.slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE);
            const headers = {
                'Content-Type': 'application/octet-stream'
            };
            if (secret && secret !== 'changeme') {
                headers['X-Upload-Secret'] = secret;
            }
            const params = new URLSearchParams({
                filename: file.name,
                upload_id: uploadId,
                chunk_index: String(index),
                chunk_count: String(totalChunks),
                chunk_size: String(chunk.size)
            });

            let attempt = 0;
            while (attempt < 3) {
                const response = await fetch(`api.php?action=upload_csv_chunk&${params.toString()}`, {
                    method: 'POST',
                    headers,
                    body: chunk
                });
                result = await response.json().catch(() => null);
                if (response.ok && result && result.success) {
                    break;
                }
                attempt += 1;
                if (attempt >= 3) {
                    throw new Error(result?.error || `Error al subir el fragmento ${index + 1}`);
                }
                await new Promise(resolve => setTimeout(resolve, 500));
            }
            progBar.style.width = `${30 + Math.round(60 * (index + 1) / totalChunks)}%`;
        }
        return result;
    }

    function showResult(type, msg) {
        result.className = 'upload-result visible ' + type;
        resultIcon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-times-circle';
        resultText.textContent = msg;
    }
    function hideResult() { result.classList.remove('visible'); }
})();
</script>
</body>
</html>