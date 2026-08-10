<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
requerir_login();

// ── Obtener PDU y verificar modo ──────────────────────────────
$uid = (int) $_SESSION['usuario_id'];
$usr_res = mysqli_query($conex, "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
$usr_row = mysqli_fetch_assoc($usr_res);

$codigo_pdu_usuario = $usr_row['codigo_pdu'] ?? '';

if (!empty($codigo_pdu_usuario)) {
    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu_usuario);
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.activo, l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.codigo_pdu = '$cod_sql' AND p.activo = 1 LIMIT 1");
} else {
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.activo, l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.activo = 1 ORDER BY p.id ASC LIMIT 1");
}

$pdu  = mysqli_fetch_assoc($pdu_res);
$modo = $pdu ? $pdu['modo'] : 'normal';

// Si ya es premium vigente, redirigir al dashboard
if ($modo === 'premium' && !empty($pdu['fecha_vencimiento'])
    && strtotime($pdu['fecha_vencimiento']) >= strtotime('today')) {
    mysqli_close($conex);
    redirigir_por_rol(rol_actual());
}

mysqli_close($conex);

$rol_actual = rol_actual();
$dashboard_destino = [
    'admin'    => 'dashboard_admin.php',
    'operator' => 'dashboard_operator.php',
    'viewer'   => 'dashboard_viewer.php',
][$rol_actual] ?? 'dashboard_admin.php';

ob_clean();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
    <title>Activar licencia Premium — SmartRACK</title>
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
                    <div class="text-white-50" style="font-size:11px;"><?php echo ucfirst($rol_actual); ?></div>
                </div>
            </div>
            <nav>
                <a class="sr-nav-link" href="<?php echo htmlspecialchars($dashboard_destino); ?>">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
                <?php if ($rol_actual !== 'viewer'): ?>
                <a class="sr-nav-link" href="<?php echo htmlspecialchars($dashboard_destino); ?>#control-tomas">
                    <i class="fas fa-plug"></i><span>Control de Tomas</span>
                </a>
                <?php endif; ?>
                <a class="sr-nav-link" href="<?php echo htmlspecialchars($dashboard_destino); ?>#logs">
                    <i class="fas fa-bell"></i><span>Alertas y Logs</span>
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
                <a class="sr-nav-link active" href="upgrade_premium.php">
                    <i class="fas fa-key"></i><span>Activar Premium</span>
                </a>
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
                <i class="fas fa-key" style="color:var(--at-orange);font-size:1.3rem;"></i>
                <h1 class="dash-main-title mb-0">Activar licencia Premium</h1>
            </div>
            <div class="small mt-1" style="color:var(--at-text-muted);">
                Ingresá el código que recibiste para activar el modo Premium en tu dispositivo
            </div>
        </div>

        <div class="sr-page-body">

            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-5">

                    <!-- Card principal -->
                    <div class="card" style="border-top:3px solid var(--at-orange);">
                        <div class="card-body p-4">

                            <div class="text-center mb-4">
                                <div style="width:56px;height:56px;border-radius:50%;background:rgba(244,152,37,0.12);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                                    <i class="fas fa-award" style="color:var(--at-orange);font-size:1.5rem;"></i>
                                </div>
                                <div style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:15px;color:var(--at-navy);">
                                    Canjear código Premium
                                </div>
                                <div class="small mt-1" style="color:#888;line-height:1.5;">
                                    Si recibiste un código de activación de AucaTek,<br>ingresalo abajo para activar el modo Premium.
                                </div>
                            </div>

                            <!-- Feedback -->
                            <div id="sr-feedback" style="display:none;margin-bottom:16px;"></div>

                            <!-- Campo de código -->
                            <div class="mb-3">
                                <label style="font-family:'Montserrat',sans-serif;font-size:12px;font-weight:600;color:var(--at-navy);display:block;margin-bottom:6px;">
                                    Código de activación
                                </label>
                                <input type="text"
                                       id="sr-input-codigo"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                       maxlength="36"
                                       style="width:100%;border:1.5px solid rgba(35,38,79,0.18);border-radius:7px;padding:10px 14px;font-family:monospace;font-size:14px;color:var(--at-navy);outline:none;box-sizing:border-box;transition:border-color .2s;">
                            </div>

                            <button type="button" id="sr-btn-activar"
                                    style="width:100%;background:var(--at-orange);color:#fff;border:none;border-radius:7px;padding:12px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                                <i class="fas fa-unlock-alt"></i>
                                <span id="sr-btn-text">Activar</span>
                            </button>

                            <div class="text-center mt-3">
                                <a href="<?php echo htmlspecialchars($dashboard_destino); ?>"
                                   style="font-size:12px;color:var(--at-celeste);text-decoration:none;font-family:'Montserrat',sans-serif;">
                                    <i class="fas fa-arrow-left me-1"></i>Volver al dashboard
                                </a>
                            </div>

                        </div>
                    </div>

                    <!-- Info -->
                    <div class="small text-center mt-3" style="color:#aaa;line-height:1.6;">
                        <i class="fas fa-info-circle me-1" style="color:var(--at-celeste);"></i>
                        ¿No tenés un código? Contactá a AucaTek en
                        <a href="mailto:info@aucatek.com.ar" style="color:var(--at-celeste);">info@aucatek.com.ar</a>
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

    var dashboardDestino = '<?php echo $dashboard_destino; ?>';
    var inputCodigo      = document.getElementById('sr-input-codigo');
    var btnActivar       = document.getElementById('sr-btn-activar');
    var btnText          = document.getElementById('sr-btn-text');
    var feedback         = document.getElementById('sr-feedback');

    function mostrarError(msg) {
        feedback.style.display = 'block';
        feedback.innerHTML = '<div style="background:#fef2f2;border-left:4px solid #F87171;border-radius:6px;padding:10px 14px;font-size:13px;color:#7f1d1d;">'
            + '<i class="fas fa-exclamation-circle me-2" style="color:#F87171;"></i>' + msg + '</div>';
    }

    function mostrarExito(msg) {
        feedback.style.display = 'block';
        feedback.innerHTML = '<div style="background:#f0fdf4;border-left:4px solid #22c55e;border-radius:6px;padding:10px 14px;font-size:13px;color:#14532d;">'
            + '<i class="fas fa-check-circle me-2" style="color:#22c55e;"></i>' + msg + '</div>';
    }

    // Focus estilo naranja en el input
    inputCodigo.addEventListener('focus', function() {
        this.style.borderColor = 'var(--at-orange)';
        this.style.boxShadow   = '0 0 0 3px rgba(244,152,37,0.15)';
    });
    inputCodigo.addEventListener('blur', function() {
        this.style.borderColor = 'rgba(35,38,79,0.18)';
        this.style.boxShadow   = 'none';
    });

    function activarCodigo() {
        var codigo = inputCodigo.value.trim();
        feedback.style.display = 'none';

        if (!codigo) {
            mostrarError('Ingresá el código de activación.');
            inputCodigo.focus();
            return;
        }

        btnActivar.disabled = true;
        btnText.textContent = 'Activando...';

        // Si hay selector de PDU activo, mandarlo en el POST
        var pduActual = (typeof window.srPduActual !== 'undefined') ? window.srPduActual : '';
        var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        var body = 'codigo_activacion=' + encodeURIComponent(codigo)
            + '&csrf_token=' + encodeURIComponent(csrfToken);
        if (pduActual) {
            body += '&codigo_pdu=' + encodeURIComponent(pduActual);
        }

        fetch('canjear_codigo.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                mostrarExito('Licencia Premium activada. Redirigiendo al dashboard...');
                setTimeout(function() { window.location.href = dashboardDestino; }, 2000);
            } else {
                mostrarError(data.error || 'Código inválido o ya utilizado.');
                btnActivar.disabled = false;
                btnText.textContent = 'Activar';
            }
        })
        .catch(function() {
            mostrarError('Error de red. Verificá tu conexión e intentá nuevamente.');
            btnActivar.disabled = false;
            btnText.textContent = 'Activar';
        });
    }

    btnActivar.addEventListener('click', activarCodigo);
    inputCodigo.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') activarCodigo();
    });

})();
</script>
</body>
</html>