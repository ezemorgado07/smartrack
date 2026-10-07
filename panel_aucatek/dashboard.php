<?php
require_once 'auth.php';
require_once 'dbconn.php';
panel_headers_seguridad();
panel_requerir_login();

// ── Cargar todos los PDUs con usuario y licencia ──────────────
$pdus = [];
$res = mysqli_query($conex,
    "SELECT p.id, p.codigo_pdu, p.mac_address, p.ip_local, p.modo, p.activo,
            p.ultimo_contacto,
            u.nombre AS user_nombre, u.apellido AS user_apellido,
            u.empresa, u.email, u.user AS username,
            l.estado AS lic_estado, l.fecha_vencimiento, l.duracion_años
     FROM pdus p
     LEFT JOIN users u ON u.codigo_pdu = p.codigo_pdu
     LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
     ORDER BY p.id ASC");
while ($row = mysqli_fetch_assoc($res)) { $pdus[] = $row; }

// ── Cargar todos los usuarios ─────────────────────────────────
$usuarios = [];
$res_u = mysqli_query($conex,
    "SELECT id, nombre, apellido, empresa, email, user, rol, codigo_pdu, created_at
     FROM users WHERE rol != 'superadmin' ORDER BY id DESC");
while ($row = mysqli_fetch_assoc($res_u)) { $usuarios[] = $row; }

// ── Estadísticas ──────────────────────────────────────────────
$total_pdus     = count($pdus);
$pdus_premium   = count(array_filter($pdus, fn($p) => $p['modo'] === 'premium'));
$total_usuarios = count($usuarios);
$pdus_activos   = count(array_filter($pdus, fn($p) => $p['activo'] == 1));

mysqli_close($conex);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(panel_csrf_token()); ?>">
    <title>Panel AucaTek — SmartRACK</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens ──────────────────────────────────────── */
        :root {
            --navy:        #23264f;
            --navy-deep:   #1a1d3a;
            --orange:      #f49825;
            --orange-hover:#e08820;
            --celeste:     #5f7dbe;

            --bg:          #f4f6fa;
            --surface:     #ffffff;
            --surface-alt: #f7f9fc;
            --border:      #e3e7f0;
            --border-strong:#d5dae8;

            --text:        #1f2433;
            --text-muted:  #6b7280;
            --text-invert: #ffffff;

            /* Estados semánticos — solo para significado, no decoración */
            --ok:          #276749;
            --ok-bg:       rgba(39,103,73,0.12);
            --danger:      #c0392b;
            --danger-bg:   rgba(192,57,43,0.10);
            --neutral-bg:  rgba(107,114,128,0.12);

            --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px;
            --sp-4: 16px; --sp-5: 24px; --sp-6: 32px;

            --radius:    10px;
            --radius-sm: 7px;
            --radius-pill: 20px;

            --shadow-sm: 0 1px 2px rgba(31,36,51,0.05);
            --shadow-md: 0 1px 3px rgba(31,36,51,0.08), 0 1px 2px rgba(31,36,51,0.04);
            --shadow-modal: 0 24px 60px rgba(15,18,45,0.35);

            --font: 'Montserrat', 'Segoe UI', system-ui, sans-serif;
            --font-mono: 'SFMono-Regular', 'Consolas', 'Menlo', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Topbar ─────────────────────────────────────────────── */
        .topbar {
            background: var(--navy);
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--sp-5);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.3px;
        }
        .topbar-brand .brand-icon {
            width: 30px; height: 30px;
            border-radius: 7px;
            background: rgba(244,152,37,0.15);
            display: flex; align-items: center; justify-content: center;
        }
        .topbar-brand .brand-icon i { color: var(--orange); font-size: 14px; }
        .topbar-brand span { color: var(--orange); }
        .topbar-brand .sep { color: rgba(255,255,255,0.35); font-weight: 400; }

        .topbar-right { display: flex; align-items: center; gap: var(--sp-4); }
        .topbar-user {
            font-size: 13px;
            color: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .topbar-user i { color: var(--orange); }
        .btn-salir {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.16);
            padding: 8px 15px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            font-family: var(--font);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: background 0.15s ease;
        }
        .btn-salir:hover { background: rgba(220,38,38,0.85); border-color: transparent; }

        /* ── Layout ─────────────────────────────────────────────── */
        .container { max-width: 1300px; margin: 0 auto; padding: var(--sp-6) var(--sp-5); }
        .page-head { margin-bottom: var(--sp-6); }
        .page-title { font-size: 24px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
        .page-sub { font-size: 14px; color: var(--text-muted); margin-top: var(--sp-1); }

        /* ── Estadísticas — jerarquía dominante ─────────────────── */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: var(--sp-4);
            margin-bottom: var(--sp-6);
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: var(--sp-5);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: var(--sp-4);
        }
        /* La primera card — PDUs totales — es la métrica ancla */
        .stat-card.is-primary {
            background: var(--navy);
            border-color: var(--navy);
        }
        .stat-card.is-primary .stat-num { color: #fff; }
        .stat-card.is-primary .stat-label { color: rgba(255,255,255,0.65); }
        .stat-card.is-primary .stat-icon { background: rgba(244,152,37,0.18); color: var(--orange); }

        .stat-icon {
            width: 46px; height: 46px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }
        .stat-icon.i-navy    { background: rgba(35,38,79,0.08);   color: var(--navy); }
        .stat-icon.i-orange  { background: rgba(244,152,37,0.12); color: var(--orange); }
        .stat-icon.i-celeste { background: rgba(95,125,190,0.14); color: var(--celeste); }

        .stat-num { font-size: 30px; font-weight: 700; color: var(--navy); line-height: 1; letter-spacing: -1px; }
        .stat-label { font-size: 13px; color: var(--text-muted); margin-top: var(--sp-1); font-weight: 500; }

        /* ── Tabs ───────────────────────────────────────────────── */
        .tabs {
            display: flex;
            gap: var(--sp-1);
            margin-bottom: var(--sp-5);
            border-bottom: 1px solid var(--border);
        }
        .tab {
            padding: 11px 20px;
            font-size: 14px;
            font-weight: 600;
            font-family: var(--font);
            color: var(--text-muted);
            cursor: pointer;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: color 0.15s ease;
        }
        .tab:hover { color: var(--navy); }
        .tab.active { color: var(--navy); border-bottom-color: var(--orange); }
        .tab:focus-visible { outline: 3px solid rgba(244,152,37,0.4); outline-offset: -3px; border-radius: 4px; }

        .panel { display: none; }
        .panel.active { display: block; }

        /* ── Card / tabla ───────────────────────────────────────── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th {
            padding: 12px 16px;
            text-align: left;
            color: var(--navy);
            font-weight: 700;
            font-size: 12px;
            background: var(--surface-alt);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        tbody td {
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--surface-alt); }

        .mono { font-family: var(--font-mono); font-size: 12.5px; }
        .cell-strong { font-weight: 600; color: var(--navy); }
        .cell-muted { color: var(--text-muted); font-size: 12px; }
        .sin-vincular { color: var(--text-muted); font-style: italic; }

        /* ── Badges (con ícono, no solo color — WCAG) ───────────── */
        .badge {
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: var(--radius-pill);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .badge i { font-size: 9px; }
        .badge-premium  { background: rgba(244,152,37,0.14); color: #a8660d; }
        .badge-normal   { background: rgba(95,125,190,0.14); color: #3d5899; }
        .badge-activo   { background: var(--ok-bg); color: var(--ok); }
        .badge-inactivo { background: var(--neutral-bg); color: var(--text-muted); }

        /* ── Botón acción ───────────────────────────────────────── */
        .btn-gen {
            background: var(--orange);
            color: #fff;
            border: none;
            padding: 7px 13px;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            font-weight: 600;
            font-family: var(--font);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: background 0.15s ease;
        }
        .btn-gen:hover:not(:disabled) { background: var(--orange-hover); }
        .btn-gen:disabled { opacity: 0.45; cursor: not-allowed; }
        .btn-gen:focus-visible { outline: 3px solid rgba(244,152,37,0.45); outline-offset: 2px; }

        /* ── Skeleton loaders ───────────────────────────────────── */
        .skeleton-wrap { display: block; }
        .skeleton-wrap.hidden { display: none; }
        .real-content.hidden { display: none; }
        .sk {
            background: linear-gradient(90deg, #e8ebf2 25%, #f1f3f8 50%, #e8ebf2 75%);
            background-size: 200% 100%;
            border-radius: 5px;
            animation: sk-shimmer 1.3s ease-in-out infinite;
        }
        @keyframes sk-shimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .sk-stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: var(--sp-5);
            display: flex;
            align-items: center;
            gap: var(--sp-4);
            box-shadow: var(--shadow-sm);
        }
        .sk-stat-icon { width: 46px; height: 46px; border-radius: 9px; flex-shrink: 0; }
        .sk-stat-num  { width: 54px; height: 28px; margin-bottom: 8px; }
        .sk-stat-lbl  { width: 90px; height: 12px; }
        .sk-row td { padding: 13px 16px; border-bottom: 1px solid var(--border); }
        .sk-line { height: 14px; }
        @media (prefers-reduced-motion: reduce) {
            .sk { animation: none; }
        }

        /* ── Modal ──────────────────────────────────────────────── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(15,18,45,0.55);
            backdrop-filter: blur(2px);
            z-index: 1000; align-items: center; justify-content: center; padding: var(--sp-5);
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: var(--surface);
            border-radius: 12px;
            padding: var(--sp-6);
            width: 100%; max-width: 440px;
            box-shadow: var(--shadow-modal);
        }
        .modal h3 {
            font-size: 17px; font-weight: 700; color: var(--navy);
            margin-bottom: var(--sp-1);
            display: flex; align-items: center; gap: 8px;
        }
        .modal h3 i { color: var(--orange); }
        .modal p { font-size: 13.5px; color: var(--text-muted); margin-bottom: var(--sp-5); }
        .modal label { display: block; font-size: 13px; font-weight: 600; color: var(--navy); margin-bottom: var(--sp-2); }
        .modal select {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid var(--border-strong); border-radius: var(--radius-sm);
            font-size: 14px; font-family: var(--font); margin-bottom: var(--sp-5);
            outline: none; background: var(--surface); color: var(--text);
        }
        .modal select:focus { border-color: var(--orange); box-shadow: 0 0 0 3px rgba(244,152,37,0.15); }
        .modal-info {
            background: var(--surface-alt);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 13px 14px; font-size: 13px; margin-bottom: var(--sp-5);
            line-height: 1.7;
        }
        .modal-info strong { color: var(--navy); }
        .modal-btns { display: flex; gap: var(--sp-3); }
        .btn-modal-cancel {
            flex: 1; padding: 11px;
            border: 1.5px solid var(--border-strong); background: var(--surface);
            color: var(--text-muted); border-radius: var(--radius-sm);
            font-weight: 600; cursor: pointer; font-size: 13.5px; font-family: var(--font);
            transition: background 0.15s ease;
        }
        .btn-modal-cancel:hover { background: var(--surface-alt); }
        .btn-modal-gen {
            flex: 1; padding: 11px;
            border: none; background: var(--orange); color: #fff;
            border-radius: var(--radius-sm); font-weight: 700; cursor: pointer;
            font-size: 13.5px; font-family: var(--font);
            transition: background 0.15s ease;
        }
        .btn-modal-gen:hover:not(:disabled) { background: var(--orange-hover); }
        .btn-modal-gen:disabled { opacity: 0.55; cursor: not-allowed; }
        .btn-modal-cancel:focus-visible, .btn-modal-gen:focus-visible {
            outline: 3px solid rgba(244,152,37,0.45); outline-offset: 2px;
        }

        .modal-feedback { margin-top: var(--sp-4); font-size: 13.5px; padding: 12px 14px; border-radius: var(--radius-sm); display: none; line-height: 1.5; }
        .modal-feedback.ok  { background: var(--ok-bg); color: var(--ok); border-left: 3px solid var(--ok); }
        .modal-feedback.err { background: var(--danger-bg); color: var(--danger); border-left: 3px solid var(--danger); }
        .codigo-generado {
            font-family: var(--font-mono); font-size: 15px;
            background: var(--navy); color: var(--orange);
            padding: 12px; border-radius: var(--radius-sm); text-align: center;
            margin-top: var(--sp-3); word-break: break-all; letter-spacing: 1px;
        }

        .empty-row td { text-align: center; color: var(--text-muted); padding: 28px; }

        a:focus-visible { outline: 3px solid rgba(244,152,37,0.5); outline-offset: 2px; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-brand">
            <span class="brand-icon" aria-hidden="true"><i class="fas fa-server"></i></span>
            Auca<span>Tek</span> <span class="sep">·</span> Panel
        </div>
        <div class="topbar-right">
            <span class="topbar-user">
                <i class="fas fa-user-shield" aria-hidden="true"></i>
                <?php echo htmlspecialchars($_SESSION['panel_nombre'] ?? $_SESSION['panel_user']); ?>
            </span>
            <a href="cerrar_sesion.php" class="btn-salir" aria-label="Cerrar sesión del panel">
                <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Salir
            </a>
        </div>
    </header>

    <main class="container">
        <div class="page-head">
            <h1 class="page-title">Administración SmartRACK</h1>
            <p class="page-sub">Gestión de dispositivos, usuarios y licencias Premium</p>
        </div>

        <!-- ── Skeletons de estadísticas ──────────────────────── -->
        <div class="stats skeleton-wrap" id="sk-stats" aria-hidden="true">
            <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="sk-stat-card">
                <div class="sk sk-stat-icon"></div>
                <div>
                    <div class="sk sk-stat-num"></div>
                    <div class="sk sk-stat-lbl"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- ── Estadísticas reales ────────────────────────────── -->
        <div class="stats real-content hidden" id="real-stats">
            <div class="stat-card is-primary">
                <div class="stat-icon" aria-hidden="true"><i class="fas fa-server"></i></div>
                <div>
                    <div class="stat-num"><?php echo $total_pdus; ?></div>
                    <div class="stat-label">PDUs totales</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon i-orange" aria-hidden="true"><i class="fas fa-award"></i></div>
                <div>
                    <div class="stat-num"><?php echo $pdus_premium; ?></div>
                    <div class="stat-label">PDUs Premium</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon i-navy" aria-hidden="true"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="stat-num"><?php echo $pdus_activos; ?></div>
                    <div class="stat-label">PDUs activos</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon i-celeste" aria-hidden="true"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-num"><?php echo $total_usuarios; ?></div>
                    <div class="stat-label">Usuarios</div>
                </div>
            </div>
        </div>

        <div class="tabs" role="tablist">
            <button class="tab active" data-panel="pdus" role="tab" aria-selected="true" aria-controls="panel-pdus">
                <i class="fas fa-server" aria-hidden="true"></i> Dispositivos y Licencias
            </button>
            <button class="tab" data-panel="usuarios" role="tab" aria-selected="false" aria-controls="panel-usuarios">
                <i class="fas fa-users" aria-hidden="true"></i> Usuarios
            </button>
        </div>

        <!-- PANEL PDUs -->
        <div class="panel active" id="panel-pdus" role="tabpanel">
            <!-- Skeleton tabla -->
            <div class="card skeleton-wrap" id="sk-table-pdus" aria-hidden="true">
                <div class="table-scroll">
                    <table>
                        <tbody>
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr class="sk-row">
                                <td><div class="sk sk-line" style="width:80%"></div></td>
                                <td><div class="sk sk-line" style="width:70%"></div></td>
                                <td><div class="sk sk-line" style="width:85%"></div></td>
                                <td><div class="sk sk-line" style="width:60%"></div></td>
                                <td><div class="sk sk-line" style="width:50%"></div></td>
                                <td><div class="sk sk-line" style="width:65%"></div></td>
                                <td><div class="sk sk-line" style="width:45%"></div></td>
                                <td><div class="sk sk-line" style="width:75%"></div></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card real-content hidden">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">PDU</th>
                                <th scope="col">MAC / IP</th>
                                <th scope="col">Cliente</th>
                                <th scope="col">Empresa</th>
                                <th scope="col">Modo</th>
                                <th scope="col">Licencia</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdus as $p): ?>
                            <tr>
                                <td><span class="mono cell-strong"><?php echo htmlspecialchars($p['codigo_pdu']); ?></span></td>
                                <td>
                                    <span class="mono"><?php echo htmlspecialchars($p['mac_address'] ?? '—'); ?></span><br>
                                    <span class="mono cell-muted"><?php echo htmlspecialchars($p['ip_local'] ?? '—'); ?></span>
                                </td>
                                <td>
                                    <?php if ($p['username']): ?>
                                        <span class="cell-strong"><?php echo htmlspecialchars(($p['user_nombre'] ?? '') . ' ' . ($p['user_apellido'] ?? '')); ?></span><br>
                                        <span class="cell-muted"><?php echo htmlspecialchars($p['email'] ?? ''); ?></span>
                                    <?php else: ?>
                                        <span class="sin-vincular">Sin vincular</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($p['empresa'] ?? '—'); ?></td>
                                <td>
                                    <?php if ($p['modo'] === 'premium'): ?>
                                        <span class="badge badge-premium"><i class="fas fa-star" aria-hidden="true"></i> Premium</span>
                                    <?php else: ?>
                                        <span class="badge badge-normal"><i class="fas fa-circle" aria-hidden="true"></i> Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['modo'] === 'premium' && $p['fecha_vencimiento']): ?>
                                        <span style="font-size:12.5px;">Vence <?php echo date('d/m/Y', strtotime($p['fecha_vencimiento'])); ?></span>
                                    <?php else: ?>
                                        <span class="cell-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['activo']): ?>
                                        <span class="badge badge-activo"><i class="fas fa-check" aria-hidden="true"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactivo"><i class="fas fa-minus" aria-hidden="true"></i> Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-gen"
                                        onclick="abrirModal('<?php echo htmlspecialchars($p['codigo_pdu']); ?>', '<?php echo htmlspecialchars($p['email'] ?? ''); ?>', '<?php echo htmlspecialchars(addslashes($p['user_nombre'] ?? '')); ?>')"
                                        <?php echo empty($p['email']) ? 'disabled title="El cliente debe registrarse primero"' : ''; ?>
                                        aria-label="Generar código Premium para el dispositivo <?php echo htmlspecialchars($p['codigo_pdu']); ?>">
                                        <i class="fas fa-key" aria-hidden="true"></i> Generar código
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pdus)): ?>
                            <tr class="empty-row"><td colspan="8">No hay PDUs registrados todavía.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PANEL USUARIOS -->
        <div class="panel" id="panel-usuarios" role="tabpanel">
            <div class="card real-content hidden">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Nombre</th>
                                <th scope="col">Usuario</th>
                                <th scope="col">Email</th>
                                <th scope="col">Empresa</th>
                                <th scope="col">PDU</th>
                                <th scope="col">Rol</th>
                                <th scope="col">Registro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td class="cell-strong"><?php echo htmlspecialchars(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')); ?></td>
                                <td><span class="mono"><?php echo htmlspecialchars($u['user']); ?></span></td>
                                <td><?php echo htmlspecialchars($u['email'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($u['empresa'] ?? '—'); ?></td>
                                <td><span class="mono cell-muted"><?php echo htmlspecialchars($u['codigo_pdu'] ?? '—'); ?></span></td>
                                <td><span class="badge badge-normal"><?php echo htmlspecialchars(ucfirst($u['rol'])); ?></span></td>
                                <td><?php echo $u['created_at'] ? date('d/m/Y', strtotime($u['created_at'])) : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($usuarios)): ?>
                            <tr class="empty-row"><td colspan="7">No hay usuarios registrados todavía.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL GENERAR CÓDIGO -->
    <div class="modal-overlay" id="modal-codigo" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal">
            <h3 id="modal-title"><i class="fas fa-key" aria-hidden="true"></i> Generar código Premium</h3>
            <p>Se generará un código de activación y se enviará automáticamente por mail al cliente.</p>

            <div class="modal-info">
                Dispositivo: <strong id="modal-pdu"></strong><br>
                Se enviará a: <strong id="modal-email"></strong>
            </div>

            <label for="modal-duracion">Duración de la licencia</label>
            <select id="modal-duracion">
                <option value="1">1 año</option>
                <option value="3">3 años</option>
                <option value="5">5 años</option>
            </select>

            <div class="modal-btns">
                <button class="btn-modal-cancel" onclick="cerrarModal()">Cancelar</button>
                <button class="btn-modal-gen" id="btn-generar" onclick="generarCodigo()">Generar y enviar</button>
            </div>

            <div class="modal-feedback" id="modal-feedback" role="status"></div>
        </div>
    </div>

    <script>
        // Ocultar skeletons y mostrar contenido real una vez cargado el DOM
        window.addEventListener('DOMContentLoaded', function() {
            requestAnimationFrame(function() {
                document.querySelectorAll('.skeleton-wrap').forEach(function(s) { s.classList.add('hidden'); });
                document.querySelectorAll('.real-content').forEach(function(r) { r.classList.remove('hidden'); });
            });
        });

        // Tabs
        document.querySelectorAll('.tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(function(t) {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');
                document.getElementById('panel-' + tab.dataset.panel).classList.add('active');
            });
        });

        var pduActual = '';
        function abrirModal(codigoPdu, email, nombre) {
            pduActual = codigoPdu;
            document.getElementById('modal-pdu').textContent = codigoPdu;
            document.getElementById('modal-email').textContent = email;
            document.getElementById('modal-feedback').style.display = 'none';
            document.getElementById('btn-generar').disabled = false;
            document.getElementById('btn-generar').textContent = 'Generar y enviar';
            document.getElementById('modal-codigo').classList.add('open');
        }
        function cerrarModal() {
            document.getElementById('modal-codigo').classList.remove('open');
        }
        function generarCodigo() {
            var btn = document.getElementById('btn-generar');
            var fb = document.getElementById('modal-feedback');
            var duracion = document.getElementById('modal-duracion').value;
            var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            btn.disabled = true;
            btn.textContent = 'Generando...';
            fb.style.display = 'none';

            fetch('generar_codigo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'codigo_pdu=' + encodeURIComponent(pduActual)
                    + '&duracion=' + encodeURIComponent(duracion)
                    + '&csrf_token=' + encodeURIComponent(csrf)
            })
            .then(r => r.json())
            .then(function(data) {
                if (data.success) {
                    fb.className = 'modal-feedback ok';
                    fb.style.display = 'block';
                    fb.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message
                        + '<div class="codigo-generado">' + data.codigo + '</div>';
                    btn.textContent = 'Generado';
                } else {
                    fb.className = 'modal-feedback err';
                    fb.style.display = 'block';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Error al generar el código.');
                    btn.disabled = false;
                    btn.textContent = 'Generar y enviar';
                }
            })
            .catch(function() {
                fb.className = 'modal-feedback err';
                fb.style.display = 'block';
                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error de red. Intentá nuevamente.';
                btn.disabled = false;
                btn.textContent = 'Generar y enviar';
            });
        }

        // Cerrar modal con Escape y clic fuera
        document.getElementById('modal-codigo').addEventListener('click', function(e) {
            if (e.target === this) cerrarModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cerrarModal();
        });
    </script>
</body>
</html>
