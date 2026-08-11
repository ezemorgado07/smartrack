<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
requerir_login();
enviar_headers_seguridad();

// ── Obtener PDU y verificar Premium ──────────────────────────
$uid = (int) $_SESSION['usuario_id'];
$usr_res = mysqli_query($conex, "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
$usr_row = mysqli_fetch_assoc($usr_res);

$codigo_pdu_usuario = $usr_row['codigo_pdu'] ?? '';

if (!empty($codigo_pdu_usuario)) {
    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu_usuario);
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.nombre,
                l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.codigo_pdu = '$cod_sql' AND p.activo = 1 LIMIT 1");
} else {
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.nombre,
                l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.user_id = $uid AND p.activo = 1 ORDER BY p.id ASC LIMIT 1");
}

$pdu = mysqli_fetch_assoc($pdu_res);
$modo = $pdu ? $pdu['modo'] : 'normal';

$licencia_vigente = ($modo === 'premium' && !empty($pdu['fecha_vencimiento']))
    ? strtotime($pdu['fecha_vencimiento']) >= strtotime('today')
    : false;
$es_premium = ($modo === 'premium' && $licencia_vigente);

// Sin Premium → redirigir al dashboard
if (!$es_premium) {
    mysqli_close($conex);
    $rol_actual = rol_actual();
    $destino = [
        'admin'    => 'dashboard_admin.php',
        'operator' => 'dashboard_operator.php',
        'viewer'   => 'dashboard_viewer.php',
    ][$rol_actual] ?? 'dashboard_admin.php';
    header("Location: $destino");
    exit();
}

$rol_actual        = rol_actual();
$codigo_pdu_activo = $pdu['codigo_pdu'];
$nombre_pdu        = $pdu['nombre'] ?? $codigo_pdu_activo;
$dashboard_destino = [
    'admin'    => 'dashboard_admin.php',
    'operator' => 'dashboard_operator.php',
    'viewer'   => 'dashboard_viewer.php',
][$rol_actual] ?? 'dashboard_admin.php';

mysqli_close($conex);

ob_clean();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar Datos — SmartRACK</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="antialiased dash-page">
<div class="wrapper">

    <!-- ══ NAVBAR ═══════════════════════════════════════════════ -->
    <div id="sr-navbar">
        <button id="sidebarToggle" type="button" title="Colapsar menú">
            <i class="fas fa-bars"></i>
        </button>
        <a href="<?php echo htmlspecialchars($dashboard_destino); ?>" id="sr-brand">
            <img src="assets/img/logo.png" alt="SmartRACK" style="height:50px;">
        </a>
        <a href="cerrar_sesion.php" class="btn btn-sm btn-danger ms-auto" id="sr-salir">
            <i class="fas fa-sign-out-alt me-1"></i> Salir
        </a>
    </div>

    <!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
    <aside id="sr-sidebar">
        <div class="sr-sidebar-inner">
            <div class="d-flex align-items-center gap-2 mb-3 mt-2 px-3">
                <div class="user-avatar"><?php echo inicial_usuario_actual(); ?></div>
                <div>
                    <div class="text-white fw-bold" style="font-family:'Montserrat',sans-serif;font-size:13px;">
                        <?php echo htmlspecialchars($_SESSION['usuario']); ?>
                    </div>
                    <div class="text-white-50" style="font-size:11px;"><?php echo htmlspecialchars(ucfirst($rol_actual)); ?></div>
                </div>
            </div>
            <nav>
                <a class="sr-nav-link" href="<?php echo htmlspecialchars($dashboard_destino); ?>">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
                <a class="sr-nav-link" href="<?php echo htmlspecialchars($dashboard_destino); ?>#control-tomas">
                    <i class="fas fa-plug"></i><span>Control de Tomas</span>
                </a>
                <a class="sr-nav-link" href="<?php echo htmlspecialchars($dashboard_destino); ?>#logs">
                    <i class="fas fa-bell"></i><span>Alertas y Logs</span>
                </a>
                <a class="sr-nav-link active" href="exportar_datos.php">
                    <i class="fas fa-download"></i><span>Exportar Datos</span>
                </a>
                <?php if ($rol_actual === 'admin'): ?>
                <a class="sr-nav-link" href="admin_usuarios.php">
                    <i class="fas fa-users-cog"></i><span>Gestión de Usuarios</span>
                </a>
                <a class="sr-nav-link" href="admin_pdus.php">
                    <i class="fas fa-server"></i><span>Gestión de PDUs</span>
                </a>
                <?php endif; ?>
                <hr class="sr-divider">
                <a class="sr-nav-link" href="cambiar_contra.php">
                    <i class="fas fa-lock"></i><span>Cambiar Clave</span>
                </a>
                <a class="sr-nav-link sr-nav-link-danger" href="cerrar_sesion.php">
                    <i class="fas fa-sign-out-alt"></i><span>Cerrar Sesión</span>
                </a>
            </nav>
        </div>
    </aside>

    <!-- ══ CONTENIDO ════════════════════════════════════════════ -->
    <div id="sr-content">

        <div class="sr-page-header">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-download" style="color:var(--at-orange);font-size:1.3rem;"></i>
                <h1 class="dash-main-title mb-0">Exportar Datos</h1>
            </div>
            <div class="small mt-1" style="color:var(--at-text-muted);">
                Descargá el historial de telemetría en formato CSV
            </div>
        </div>

        <div class="sr-page-body">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-6">

                    <div class="card" style="border-top:3px solid var(--at-orange);">
                        <div class="card-body p-4">

                            <!-- PDU activo -->
                            <div class="d-flex align-items-center gap-2 mb-4 p-3"
                                 style="background:#f4f6fb;border-radius:8px;border:1px solid #e8eaf0;">
                                <i class="fas fa-server" style="color:var(--at-orange);"></i>
                                <div>
                                    <div style="font-family:'Montserrat',sans-serif;font-size:12px;font-weight:700;color:var(--at-navy);">
                                        PDU activo
                                    </div>
                                    <div id="pdu-label" class="small" style="color:#666;">
                                        <?php echo htmlspecialchars($nombre_pdu); ?>
                                    </div>
                                </div>
                                <span class="badge ms-auto" style="background:var(--at-orange);color:#fff;font-family:'Montserrat',sans-serif;font-size:10px;">Premium</span>
                            </div>

                            <!-- Feedback -->
                            <div id="sr-feedback" style="display:none;margin-bottom:16px;"></div>

                            <!-- Tipo de exportación -->
                            <div class="mb-3">
                                <label style="font-family:'Montserrat',sans-serif;font-size:12px;font-weight:600;color:var(--at-navy);display:block;margin-bottom:6px;">
                                    Tipo de datos
                                </label>
                                <select id="sr-tipo"
                                        style="width:100%;border:1.5px solid rgba(35,38,79,0.18);border-radius:7px;padding:10px 14px;font-family:'Montserrat',sans-serif;font-size:13px;color:var(--at-navy);outline:none;background:#fff;">
                                    <option value="pzem">Solo PZEM — Electricidad (V, A, W, PF, Hz, kWh)</option>
                                    <option value="aht10">Solo AHT10 — Ambiente (Temperatura y Humedad)</option>
                                    <option value="ambos">Ambos sensores</option>
                                </select>
                            </div>

                            <!-- Rango de fechas -->
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <label style="font-family:'Montserrat',sans-serif;font-size:12px;font-weight:600;color:var(--at-navy);display:block;margin-bottom:6px;">
                                        Fecha desde
                                    </label>
                                    <input type="date" id="sr-desde"
                                           style="width:100%;border:1.5px solid rgba(35,38,79,0.18);border-radius:7px;padding:10px 12px;font-family:'Montserrat',sans-serif;font-size:13px;color:var(--at-navy);outline:none;box-sizing:border-box;">
                                </div>
                                <div class="col-6">
                                    <label style="font-family:'Montserrat',sans-serif;font-size:12px;font-weight:600;color:var(--at-navy);display:block;margin-bottom:6px;">
                                        Fecha hasta
                                    </label>
                                    <input type="date" id="sr-hasta"
                                           style="width:100%;border:1.5px solid rgba(35,38,79,0.18);border-radius:7px;padding:10px 12px;font-family:'Montserrat',sans-serif;font-size:13px;color:var(--at-navy);outline:none;box-sizing:border-box;">
                                </div>
                            </div>

                            <button type="button" id="sr-btn-exportar"
                                    style="width:100%;background:var(--at-orange);color:#fff;border:none;border-radius:7px;padding:12px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                                <i class="fas fa-download"></i>
                                <span id="sr-btn-text">Descargar CSV</span>
                            </button>

                            <div class="text-center mt-3">
                                <a href="<?php echo htmlspecialchars($dashboard_destino); ?>"
                                   style="font-size:12px;color:var(--at-celeste);text-decoration:none;font-family:'Montserrat',sans-serif;">
                                    <i class="fas fa-arrow-left me-1"></i>Volver al dashboard
                                </a>
                            </div>

                        </div>
                    </div>

                    <div class="small text-center mt-3" style="color:#aaa;line-height:1.6;">
                        <i class="fas fa-info-circle me-1" style="color:var(--at-celeste);"></i>
                        El rango maximo de exportacion es de 90 dias. Los datos se exportan en formato CSV (UTF-8).
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script>
(function() {

    // Sidebar toggle
    var toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }

    // Defaults de fechas: último mes
    var hoy   = new Date();
    var desde = new Date(hoy);
    desde.setDate(desde.getDate() - 30);

    function fmt(d) {
        return d.toISOString().split('T')[0];
    }
    document.getElementById('sr-desde').value = fmt(desde);
    document.getElementById('sr-hasta').value = fmt(hoy);

    function mostrarError(msg) {
        var fb = document.getElementById('sr-feedback');
        fb.style.display = 'block';
        fb.innerHTML = '<div style="background:#fef2f2;border-left:4px solid #F87171;border-radius:6px;padding:10px 14px;font-size:13px;color:#7f1d1d;">'
            + '<i class="fas fa-exclamation-circle me-2" style="color:#F87171;"></i>' + msg + '</div>';
    }

    function ocultarError() {
        document.getElementById('sr-feedback').style.display = 'none';
    }

    document.getElementById('sr-btn-exportar').addEventListener('click', function() {
        ocultarError();

        var tipo  = document.getElementById('sr-tipo').value;
        var desde = document.getElementById('sr-desde').value;
        var hasta = document.getElementById('sr-hasta').value;

        if (!desde || !hasta) {
            mostrarError('Debés seleccionar ambas fechas.');
            return;
        }

        var dDesde = new Date(desde);
        var dHasta = new Date(hasta);

        if (dDesde > dHasta) {
            mostrarError('La fecha "desde" no puede ser mayor a la fecha "hasta".');
            return;
        }

        var diffDias = Math.ceil((dHasta - dDesde) / (1000 * 60 * 60 * 24));
        if (diffDias > 90) {
            mostrarError('El rango no puede superar los 90 dias. Selecciona un periodo mas acotado.');
            return;
        }

        // PDU activo: usar selector si existe, sino el del PHP
        var pduActual = (typeof window.srPduActual !== 'undefined' && window.srPduActual)
            ? window.srPduActual
            : '<?php echo htmlspecialchars($codigo_pdu_activo, ENT_QUOTES); ?>';

        var btnText = document.getElementById('sr-btn-text');
        var btn     = document.getElementById('sr-btn-exportar');
        btn.disabled    = true;
        btnText.textContent = 'Generando...';

        // Usar form submit para forzar descarga de archivo
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'descargar_csv.php';
        form.style.display = 'none';

        [['tipo', tipo], ['desde', desde], ['hasta', hasta], ['codigo_pdu', pduActual]].forEach(function(par) {
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = par[0];
            inp.value = par[1];
            form.appendChild(inp);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

        setTimeout(function() {
            btn.disabled = false;
            btnText.textContent = 'Descargar CSV';
        }, 2000);
    });

})();
</script>
</body>
</html>