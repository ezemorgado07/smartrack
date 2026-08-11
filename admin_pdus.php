<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
requerir_rol(array('admin'), $conex);

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// ══════════════════════════════════════════════════════════════
//  ACTION: TOGGLE_ACTIVE — activar/desactivar PDU
// ══════════════════════════════════════════════════════════════
if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_pdus.php?msg=err_csrf"); exit();
    }

    $codigo_pdu    = (isset($_POST['codigo_pdu']) && is_string($_POST['codigo_pdu']))
        ? mysqli_real_escape_string($conex, $_POST['codigo_pdu']) : '';
    $current_state = (int) ($_POST['current_state'] ?? 0);

    if (!$codigo_pdu) {
        header("Location: admin_pdus.php?msg=err_invalid"); exit();
    }

    $nuevo_estado = $current_state === 1 ? 0 : 1;
    mysqli_query($conex, "UPDATE pdus SET activo = $nuevo_estado WHERE codigo_pdu = '$codigo_pdu'");
    header("Location: admin_pdus.php?msg=ok_toggle"); exit();
}

// ══════════════════════════════════════════════════════════════
//  ACTION: ADD_LICENCIA — activar licencia premium
// ══════════════════════════════════════════════════════════════
if ($action === 'add_licencia' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_pdus.php?msg=err_csrf"); exit();
    }

    $codigo_pdu = (isset($_POST['codigo_pdu']) && is_string($_POST['codigo_pdu']))
        ? mysqli_real_escape_string($conex, $_POST['codigo_pdu']) : '';
    $duracion_años  = (int) ($_POST['duracion_años'] ?? 1);

    if (!$codigo_pdu || !in_array($duracion_años, [1, 3, 5])) {
        header("Location: admin_pdus.php?msg=err_invalid"); exit();
    }

    // Vencer licencias anteriores activas
    mysqli_query($conex,
        "UPDATE licencias SET estado = 'vencida'
         WHERE codigo_pdu = '$codigo_pdu' AND estado = 'activa'");

    // Crear nueva licencia desde hoy
    mysqli_query($conex,
        "INSERT INTO licencias (codigo_pdu, duracion_años, fecha_inicio, fecha_vencimiento, estado)
         VALUES ('$codigo_pdu', $duracion_años, CURDATE(), DATE_ADD(CURDATE(), INTERVAL $duracion_años YEAR), 'activa')");

    // Actualizar modo del PDU a premium
    mysqli_query($conex,
        "UPDATE pdus SET modo = 'premium' WHERE codigo_pdu = '$codigo_pdu'");

    header("Location: admin_pdus.php?msg=ok_licencia"); exit();
}

// ══════════════════════════════════════════════════════════════
//  ACTION: VINCULAR_USUARIO — asignar PDU a un usuario admin
// ══════════════════════════════════════════════════════════════
if ($action === 'vincular_usuario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_pdus.php?msg=err_csrf"); exit();
    }

    $codigo_pdu = (isset($_POST['codigo_pdu']) && is_string($_POST['codigo_pdu']))
        ? mysqli_real_escape_string($conex, $_POST['codigo_pdu']) : '';
    $user_id    = (int) ($_POST['user_id'] ?? 0);

    if (!$codigo_pdu || !$user_id) {
        header("Location: admin_pdus.php?msg=err_invalid"); exit();
    }

    mysqli_query($conex,
        "UPDATE users SET codigo_pdu = '$codigo_pdu' WHERE id = $user_id");

    header("Location: admin_pdus.php?msg=ok_vinculo"); exit();
}

// ══════════════════════════════════════════════════════════════
//  LIST — cargar PDUs con licencia y usuario vinculado
// ══════════════════════════════════════════════════════════════
$res_pdus = mysqli_query($conex,
    "SELECT p.id, p.codigo_pdu, p.mac_address, p.ip_local, p.nombre,
            p.modo, p.activo, p.fecha_registro, p.ultimo_contacto,
            l.duracion_años, l.fecha_vencimiento, l.estado AS lic_estado,
            u.user AS usuario_vinculado, u.id AS user_id_vinculado
     FROM pdus p
     LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
     LEFT JOIN users u ON u.codigo_pdu = p.codigo_pdu
     ORDER BY p.fecha_registro DESC");

// Cargar usuarios admin/operator para modal de vinculación
$res_usuarios = mysqli_query($conex,
    "SELECT id, user, nombre, apellido, rol FROM users
     WHERE is_active = 1 ORDER BY user ASC");
$usuarios = [];
while ($u = mysqli_fetch_assoc($res_usuarios)) { $usuarios[] = $u; }

$msg = $_GET['msg'] ?? '';
$mensajes = [
    'ok_toggle'   => ['success', 'Estado del PDU actualizado correctamente.'],
    'ok_licencia' => ['success', 'Licencia premium activada correctamente.'],
    'ok_vinculo'  => ['success', 'Usuario vinculado al PDU correctamente.'],
    'err_invalid' => ['danger',  'Datos inválidos. Intentá nuevamente.'],
    'err_csrf'    => ['danger',  'La sesión del formulario expiró o es inválida. Volvé a intentarlo.'],
];
ob_clean();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de PDUs — SmartRACK</title>
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
        <a href="dashboard_admin.php" id="sr-brand">
            <img src="assets/img/logo.png" alt="SmartRACK" style="height: 50px;">
        </a>
        <a href="cerrar_sesion.php" class="btn btn-sm btn-danger ms-auto" id="sr-salir">
            <i class="fas fa-sign-out-alt me-1"></i> Salir
        </a>
    </div>

    <!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
    <aside id="sr-sidebar">
        <div class="sr-sidebar-inner">
            <div class="d-flex align-items-center gap-2 mb-3 mt-2 px-3">
                <div class="user-avatar"><?php echo htmlspecialchars(inicial_usuario_actual()); ?></div>
                <div>
                    <div class="text-white fw-bold" style="font-family:'Montserrat',sans-serif; font-size:13px;">
                        <?php echo htmlspecialchars($_SESSION['usuario']); ?>
                    </div>
                    <div class="text-white-50" style="font-size:11px;">Administrador</div>
                </div>
            </div>
            <nav>
                <a class="sr-nav-link" href="dashboard_admin.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a class="sr-nav-link" href="dashboard_admin.php#control-tomas">
                    <i class="fas fa-plug"></i>
                    <span>Control de Tomas</span>
                </a>
                <a class="sr-nav-link" href="dashboard_admin.php#logs">
                    <i class="fas fa-bell"></i>
                    <span>Alertas y Logs</span>
                </a>
                <a class="sr-nav-link" href="#" id="link-gestion-usuarios">
                    <i class="fas fa-users-cog"></i>
                    <span>Gestión de Usuarios</span>
                </a>
                <a class="sr-nav-link active" href="admin_pdus.php">
                    <i class="fas fa-server"></i>
                    <span>Gestión de PDUs</span>
                </a>
                <hr class="sr-divider">
                <a class="sr-nav-link" href="cambiar_contra.php">
                    <i class="fas fa-key"></i>
                    <span>Cambiar Clave</span>
                </a>
                <a class="sr-nav-link sr-nav-link-danger" href="cerrar_sesion.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Cerrar Sesión</span>
                </a>
            </nav>
        </div>
    </aside>

    <!-- ══ CONTENIDO ════════════════════════════════════════════ -->
    <div id="sr-content">

        <div class="sr-page-header">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-server" style="color: var(--at-orange); font-size: 1.3rem;"></i>
                        <h1 class="dash-main-title mb-0">Gestión de PDUs</h1>
                    </div>
                    <div class="small mt-1" style="color: var(--at-text-muted);">
                        Dispositivos ePDU registrados en el sistema SmartRACK
                    </div>
                </div>
            </div>
        </div>

        <div class="sr-page-body">

            <!-- Feedback -->
            <?php if ($msg && isset($mensajes[$msg])): ?>
                <?php [$tipo, $texto] = $mensajes[$msg]; ?>
                <div class="alert alert-<?php echo $tipo; ?> alert-dismissible alert-auto" role="alert">
                    <?php if ($tipo === 'success'): ?>
                        <i class="fas fa-check-circle me-1" style="color:#f49825;"></i>
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle me-1"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($texto); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Tabla de PDUs -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title" style="font-family:'Montserrat',sans-serif;">
                        <i class="fas fa-list me-2"></i>PDUs registrados
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap mb-0">
                        <thead style="background:#f4f6f9;">
                            <tr>
                                <th>#</th>
                                <th>Nombre / ID</th>
                                <th>MAC / IP</th>
                                <th>Modo</th>
                                <th>Licencia</th>
                                <th>Estado</th>
                                <th>Online</th>
                                <th>Usuario vinculado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $n = 0;
                        while ($pdu = mysqli_fetch_assoc($res_pdus)):
                            $n++;

                            // Online si hubo contacto en los últimos 30 segundos
                            $online = !empty($pdu['ultimo_contacto'])
                                && (time() - strtotime($pdu['ultimo_contacto'])) <= 30;

                            // Licencia vigente
                            $lic_vigente = !empty($pdu['fecha_vencimiento'])
                                && strtotime($pdu['fecha_vencimiento']) >= strtotime('today');

                            $cod_short = substr($pdu['codigo_pdu'], 0, 12) . '...';
                        ?>
                        <tr>
                            <td><small class="text-muted"><?php echo $n; ?></small></td>
                            <td>
                                <div class="fw-bold" style="font-family:'Montserrat',sans-serif;">
                                    <?php echo htmlspecialchars($pdu['nombre'] ?? 'Sin nombre'); ?>
                                </div>
                                <small class="text-muted font-monospace" title="<?php echo htmlspecialchars($pdu['codigo_pdu']); ?>">
                                    <?php echo $cod_short; ?>
                                </small>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars($pdu['mac_address']); ?></code><br>
                                <small class="text-muted"><?php echo htmlspecialchars($pdu['ip_local']); ?></small>
                            </td>
                            <td>
                                <?php if ($pdu['modo'] === 'premium'): ?>
                                    <span class="badge" style="background:var(--at-orange);color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">
                                        Premium
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--at-celeste);color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">
                                        Normal
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pdu['modo'] === 'premium' && $lic_vigente): ?>
                                    <span class="badge" style="background:#276749;color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">
                                        Activa
                                    </span><br>
                                    <small class="text-muted">
                                        Vence: <?php echo date('d/m/Y', strtotime($pdu['fecha_vencimiento'])); ?>
                                        (<?php echo $pdu['duracion_años']; ?> año<?php echo $pdu['duracion_años'] > 1 ? 's' : ''; ?>)
                                    </small>
                                <?php elseif ($pdu['modo'] === 'premium' && !$lic_vigente): ?>
                                    <span class="badge" style="background:#F87171;color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">
                                        Vencida
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pdu['activo']): ?>
                                    <span class="badge" style="background:#276749;color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">Activo</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#F87171;color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($online): ?>
                                    <span class="status-dot status-dot-animated status-green"></span>
                                <?php else: ?>
                                    <span class="status-dot status-gray"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pdu['usuario_vinculado']): ?>
                                    <code><?php echo htmlspecialchars($pdu['usuario_vinculado']); ?></code>
                                <?php else: ?>
                                    <span class="text-muted small">Sin vincular</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Activar licencia premium -->
                                <button type="button"
                                        class="btn btn-outline btn-accion btn-cambiar-rol"
                                        title="Gestionar licencia"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modal-licencia"
                                        data-codigo="<?php echo htmlspecialchars($pdu['codigo_pdu'], ENT_QUOTES); ?>"
                                        data-nombre="<?php echo htmlspecialchars($pdu['nombre'] ?? 'PDU', ENT_QUOTES); ?>">
                                    <i class="fas fa-award"></i>
                                </button>

                                <!-- Vincular usuario -->
                                <button type="button"
                                        class="btn btn-outline btn-accion btn-cambiar-rol"
                                        title="Vincular usuario"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modal-vincular"
                                        data-codigo="<?php echo htmlspecialchars($pdu['codigo_pdu'], ENT_QUOTES); ?>"
                                        data-nombre="<?php echo htmlspecialchars($pdu['nombre'] ?? 'PDU', ENT_QUOTES); ?>"
                                        data-user-id="<?php echo (int)($pdu['user_id_vinculado'] ?? 0); ?>">
                                    <i class="fas fa-link"></i>
                                </button>

                                <!-- Toggle activo/inactivo -->
                                <form method="POST" action="admin_pdus.php?action=toggle_active" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
                                    <input type="hidden" name="codigo_pdu"    value="<?php echo htmlspecialchars($pdu['codigo_pdu']); ?>">
                                    <input type="hidden" name="current_state" value="<?php echo (int)$pdu['activo']; ?>">
                                    <button type="submit"
                                            class="btn btn-outline btn-accion <?php echo $pdu['activo'] ? 'btn-toggle-activo-desactivar' : 'btn-toggle-activo-activar'; ?>"
                                            title="<?php echo $pdu['activo'] ? 'Desactivar' : 'Activar'; ?> PDU"
                                            onclick="return confirm('<?php echo $pdu['activo'] ? 'Desactivar' : 'Activar'; ?> el PDU <?php echo htmlspecialchars($pdu['nombre'] ?? $cod_short, ENT_QUOTES); ?>?')">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($n === 0): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <i class="fas fa-server me-2"></i>No hay PDUs registrados aún. Los dispositivos aparecen aquí al presionar el botón físico de registro.
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-muted" style="font-size:12.5px; font-family:'Montserrat',sans-serif;">
                    Total: <strong><?php echo $n; ?></strong> PDU<?php echo $n !== 1 ? 's' : ''; ?> registrado<?php echo $n !== 1 ? 's' : ''; ?>
                </div>
            </div>

        </div>
    </div><!-- /sr-content -->
</div><!-- /wrapper -->

<!-- MODAL: GESTIONAR LICENCIA -->
<div class="modal fade" id="modal-licencia" tabindex="-1" aria-labelledby="modal-lic-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <form method="POST" action="admin_pdus.php?action=add_licencia">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
        <input type="hidden" id="lic-codigo" name="codigo_pdu" value="">
        <div class="modal-header" style="background: var(--at-navy);">
          <h5 class="modal-title text-white" id="modal-lic-title" style="font-family:'Montserrat',sans-serif;">
            <i class="fas fa-award me-2" style="color:#f49825;"></i>
            Activar Licencia Premium
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            PDU: <strong id="lic-nombre-label"></strong>
          </p>
          <div class="mb-3">
            <label class="form-label" for="lic-tipo">Duración de la licencia <span class="text-danger">*</span></label>
            <select id="lic-tipo" name="duracion_años" class="form-select" required>
              <option value="1">1 año</option>
              <option value="3">3 años</option>
              <option value="5">5 años</option>
            </select>
          </div>
          <div class="alert" style="background:#1a2a4a;border-left:3px solid var(--at-orange);font-size:12px;color:rgba(255,255,255,0.75);">
            <i class="fas fa-info-circle me-1" style="color:var(--at-orange);"></i>
            Esto activará el modo Premium en el PDU y habilitará la telemetría completa. Las licencias anteriores serán reemplazadas.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn" style="background:#f49825; color:#fff; font-weight:700; font-family:'Montserrat',sans-serif;">
            <i class="fas fa-save me-1"></i> Activar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: VINCULAR USUARIO -->
<div class="modal fade" id="modal-vincular" tabindex="-1" aria-labelledby="modal-vinc-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <form method="POST" action="admin_pdus.php?action=vincular_usuario">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
        <input type="hidden" id="vinc-codigo" name="codigo_pdu" value="">
        <div class="modal-header" style="background: var(--at-navy);">
          <h5 class="modal-title text-white" id="modal-vinc-title" style="font-family:'Montserrat',sans-serif;">
            <i class="fas fa-link me-2" style="color:#f49825;"></i>
            Vincular Usuario al PDU
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            PDU: <strong id="vinc-nombre-label"></strong>
          </p>
          <div class="mb-0">
            <label class="form-label" for="vinc-user">Usuario <span class="text-danger">*</span></label>
            <select id="vinc-user" name="user_id" class="form-select" required>
              <option value="">— Seleccioná un usuario —</option>
              <?php foreach ($usuarios as $u): ?>
              <option value="<?php echo (int)$u['id']; ?>">
                <?php echo htmlspecialchars($u['user'] . ' (' . ucfirst($u['rol']) . ')'); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn" style="background:#f49825; color:#fff; font-weight:700; font-family:'Montserrat',sans-serif;">
            <i class="fas fa-save me-1"></i> Vincular
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- Modal verificacion de identidad — DEBE ir antes del script -->
<div id="modal-verify-overlay" style="display:none;position:fixed;inset:0;background:rgba(10,15,45,0.75);backdrop-filter:blur(4px);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:rgba(255,255,255,0.08);border:1px solid rgba(244,152,37,0.45);border-radius:12px;backdrop-filter:blur(18px);padding:36px 40px 32px;width:100%;max-width:400px;box-shadow:0 16px 48px rgba(0,0,0,0.55);">
        <div style="text-align:center;margin-bottom:20px;">
            <img src="assets/img/logo.png" alt="AucaTek" style="width:110px;height:auto;">
        </div>
        <h4 style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:17px;color:#fff;text-align:center;margin-bottom:4px;">Verificación de identidad</h4>
        <p style="font-family:'Montserrat',sans-serif;font-size:12px;color:rgba(255,255,255,0.55);text-align:center;margin-bottom:20px;">Ingresá tu contraseña para acceder a la gestión de usuarios.</p>
        <div id="modal-error" style="display:none;align-items:center;gap:7px;background:rgba(197,48,48,0.18);border:1px solid rgba(248,113,113,0.40);border-left:3px solid #F87171;border-radius:5px;padding:9px 12px;margin-bottom:14px;font-family:'Montserrat',sans-serif;font-size:12px;color:#fff;">
            <i class="fas fa-exclamation-circle" style="color:#F87171;flex-shrink:0;"></i>
            <span>Contraseña incorrecta. Intentá nuevamente.</span>
        </div>
        <label style="display:block;color:#fff;font-family:'Montserrat',sans-serif;font-size:12px;font-weight:600;margin-bottom:6px;">Contraseña</label>
        <div style="position:relative;margin-bottom:8px;">
            <input type="password" id="modal-password" placeholder="••••••••"
                   style="width:100%;background:rgba(255,255,255,0.10);border:1px solid rgba(255,255,255,0.25);border-radius:6px;color:#fff;height:44px;padding:10px 44px 10px 14px;font-family:'Montserrat',sans-serif;font-size:14px;outline:none;box-sizing:border-box;">
            <button type="button" id="modal-toggle-pass" style="position:absolute;top:50%;right:12px;transform:translateY(-50%);background:none;border:none;padding:0;color:rgba(255,255,255,0.4);font-size:14px;cursor:pointer;">
                <i class="fas fa-eye" id="modal-pass-icon"></i>
            </button>
        </div>
        <button type="button" id="modal-btn-confirm" style="width:100%;background:#f49825;color:#fff;border:none;border-radius:6px;padding:12px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:14px;cursor:pointer;margin-top:6px;display:flex;align-items:center;justify-content:center;gap:8px;">
            <i class="fas fa-unlock-alt"></i>
            <span id="modal-btn-text">Verificar y acceder</span>
        </button>
        <button type="button" id="modal-btn-cancel" style="width:100%;background:transparent;color:rgba(255,255,255,0.5);border:none;padding:10px;font-family:'Montserrat',sans-serif;font-size:13px;cursor:pointer;margin-top:4px;">
            Cancelar
        </button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script>
(function () {

    // Sidebar toggle
    var toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }

    // Poblar modal Licencia
    document.querySelectorAll('[data-bs-target="#modal-licencia"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('lic-codigo').value = this.dataset.codigo;
            document.getElementById('lic-nombre-label').textContent = this.dataset.nombre;
        });
    });

    // Poblar modal Vincular
    document.querySelectorAll('[data-bs-target="#modal-vincular"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('vinc-codigo').value = this.dataset.codigo;
            document.getElementById('vinc-nombre-label').textContent = this.dataset.nombre;
            var sel = document.getElementById('vinc-user');
            var uid = this.dataset.userId;
            if (uid && sel) sel.value = uid;
        });
    });

    // Auto-dismiss alertas
    var autoAlert = document.querySelector('.alert-auto');
    if (autoAlert) {
        setTimeout(function () {
            autoAlert.style.transition = 'opacity 0.4s ease';
            autoAlert.style.opacity = '0';
            setTimeout(function () { autoAlert.remove(); }, 400);
        }, 3500);
    }

    // Modal verificacion de identidad
    var overlay    = document.getElementById('modal-verify-overlay');
    var passInput  = document.getElementById('modal-password');
    var errorBox   = document.getElementById('modal-error');
    var btnConfirm = document.getElementById('modal-btn-confirm');
    var btnCancel  = document.getElementById('modal-btn-cancel');
    var btnText    = document.getElementById('modal-btn-text');
    var togglePass = document.getElementById('modal-toggle-pass');
    var passIcon   = document.getElementById('modal-pass-icon');

    function abrirModal() {
        passInput.value = '';
        errorBox.style.display = 'none';
        btnConfirm.disabled = false;
        btnText.textContent = 'Verificar y acceder';
        overlay.style.display = 'flex';
        setTimeout(function() { passInput.focus(); }, 120);
    }

    function cerrarModal() {
        overlay.style.display = 'none';
        passInput.value = '';
        errorBox.style.display = 'none';
    }

    document.getElementById('link-gestion-usuarios').addEventListener('click', function(e) {
        e.preventDefault();
        abrirModal();
    });

    btnCancel.addEventListener('click', cerrarModal);

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) cerrarModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.style.display === 'flex') cerrarModal();
    });

    passInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') btnConfirm.click();
    });

    togglePass.addEventListener('click', function() {
        var isPass = passInput.type === 'password';
        passInput.type = isPass ? 'text' : 'password';
        passIcon.className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
    });

    btnConfirm.addEventListener('click', function() {
        var pass = passInput.value.trim();
        if (!pass) { passInput.focus(); return; }

        btnConfirm.disabled = true;
        btnText.textContent = 'Verificando...';
        errorBox.style.display = 'none';

        fetch('verify_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'password=' + encodeURIComponent(pass)
        })
        .then(r => r.json())
        .then(function(data) {
            if (data.success) {
                btnText.textContent = 'Accediendo...';
                window.location.href = 'admin_usuarios.php';
            } else {
                errorBox.style.display = 'flex';
                passInput.value = '';
                passInput.focus();
                btnConfirm.disabled = false;
                btnText.textContent = 'Verificar y acceder';
            }
        })
        .catch(function() {
            errorBox.style.display = 'flex';
            btnConfirm.disabled = false;
            btnText.textContent = 'Verificar y acceder';
        });
    });

})();
</script>
</body>
</html>
<?php mysqli_close($conex); ?>