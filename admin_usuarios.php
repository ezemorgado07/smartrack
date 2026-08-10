<?php
require_once 'auth.php';
require_once 'dbconn.php';
requerir_rol(array('admin'));

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// ── helper: contar admins activos ────────────────────────────
function contar_admins_activos($conex) {
    $r = mysqli_query($conex, "SELECT COUNT(*) AS total FROM users WHERE rol='admin' AND is_active=1");
    $row = mysqli_fetch_assoc($r);
    return (int) $row['total'];
}

// ══════════════════════════════════════════════════════════════
//  ACTION: CREATE
// ══════════════════════════════════════════════════════════════
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_usuarios.php?msg=err_csrf"); exit();
    }

    $nombre   = mysqli_real_escape_string($conex, trim($_POST['nombre']   ?? ''));
    $apellido = mysqli_real_escape_string($conex, trim($_POST['apellido'] ?? ''));
    $email    = mysqli_real_escape_string($conex, trim($_POST['email']    ?? ''));
    $username = mysqli_real_escape_string($conex, trim($_POST['username'] ?? ''));
    $pass     = $_POST['pass']      ?? '';
    $pass2    = $_POST['pass_conf'] ?? '';
    $rol      = mysqli_real_escape_string($conex, $_POST['rol'] ?? 'viewer');
    $must_cp  = isset($_POST['must_change']) ? 1 : 0;
    $roles_ok = array('admin', 'operator', 'viewer');

    if (!$nombre || !$apellido || !$email || !$username || !$pass || !$pass2) {
        header("Location: admin_usuarios.php?msg=err_empty"); exit();
    }
    if ($pass !== $pass2) {
        header("Location: admin_usuarios.php?msg=err_pass_mismatch"); exit();
    }
    if (!in_array($rol, $roles_ok)) {
        header("Location: admin_usuarios.php?msg=err_rol"); exit();
    }

    $chk_user = mysqli_query($conex, "SELECT id FROM users WHERE user='$username' LIMIT 1");
    if (mysqli_num_rows($chk_user) > 0) {
        header("Location: admin_usuarios.php?msg=err_user_exists"); exit();
    }
    $chk_email = mysqli_query($conex, "SELECT id FROM users WHERE email='$email' LIMIT 1");
    if (mysqli_num_rows($chk_email) > 0) {
        header("Location: admin_usuarios.php?msg=err_email_exists"); exit();
    }

    $hash     = password_hash($pass, PASSWORD_DEFAULT);
    $hash_sql = mysqli_real_escape_string($conex, $hash);

    mysqli_query($conex,
        "INSERT INTO users (nombre, apellido, email, user, pass, rol, must_change_password)
         VALUES ('$nombre','$apellido','$email','$username','$hash_sql','$rol',$must_cp)");

    header("Location: admin_usuarios.php?msg=ok_create"); exit();
}

// ══════════════════════════════════════════════════════════════
//  ACTION: CHANGE_ROL
// ══════════════════════════════════════════════════════════════
if ($action === 'change_rol' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_usuarios.php?msg=err_csrf"); exit();
    }

    $user_id   = (int) ($_POST['user_id']   ?? 0);
    $nuevo_rol = mysqli_real_escape_string($conex, $_POST['nuevo_rol'] ?? '');
    $roles_ok  = array('admin', 'operator', 'viewer');
    $mi_id     = (int) ($_SESSION['usuario_id'] ?? 0);

    if ($user_id === $mi_id) {
        header("Location: admin_usuarios.php?msg=err_self"); exit();
    }
    if (!in_array($nuevo_rol, $roles_ok)) {
        header("Location: admin_usuarios.php?msg=err_rol"); exit();
    }

    $res_actual = mysqli_query($conex, "SELECT rol FROM users WHERE id=$user_id LIMIT 1");
    $act_row    = mysqli_fetch_assoc($res_actual);
    if ($act_row && $act_row['rol'] === 'admin' && $nuevo_rol !== 'admin') {
        if (contar_admins_activos($conex) <= 1) {
            header("Location: admin_usuarios.php?msg=err_last_admin"); exit();
        }
    }

    mysqli_query($conex, "UPDATE users SET rol='$nuevo_rol' WHERE id=$user_id");
    header("Location: admin_usuarios.php?msg=ok_rol"); exit();
}

// ══════════════════════════════════════════════════════════════
//  ACTION: FORCE_PASSWORD
// ══════════════════════════════════════════════════════════════
if ($action === 'force_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_usuarios.php?msg=err_csrf"); exit();
    }

    $user_id = (int) ($_POST['user_id'] ?? 0);
    mysqli_query($conex, "UPDATE users SET must_change_password=1 WHERE id=$user_id");
    header("Location: admin_usuarios.php?msg=ok_force"); exit();
}

// ══════════════════════════════════════════════════════════════
//  ACTION: TOGGLE_ACTIVE
// ══════════════════════════════════════════════════════════════
if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_usuarios.php?msg=err_csrf"); exit();
    }

    $user_id       = (int) ($_POST['user_id']       ?? 0);
    $current_state = (int) ($_POST['current_state'] ?? 0);
    $mi_id         = (int) ($_SESSION['usuario_id'] ?? 0);

    if ($user_id === $mi_id) {
        header("Location: admin_usuarios.php?msg=err_self"); exit();
    }

    if ($current_state === 1) {
        $res_r = mysqli_query($conex, "SELECT rol FROM users WHERE id=$user_id LIMIT 1");
        $row_r = mysqli_fetch_assoc($res_r);
        if ($row_r && $row_r['rol'] === 'admin' && contar_admins_activos($conex) <= 1) {
            header("Location: admin_usuarios.php?msg=err_last_admin"); exit();
        }
    }

    $nuevo_estado = $current_state === 1 ? 0 : 1;
    mysqli_query($conex, "UPDATE users SET is_active=$nuevo_estado WHERE id=$user_id");
    header("Location: admin_usuarios.php?msg=ok_toggle"); exit();
}

// ══════════════════════════════════════════════════════════════
//  ACTION: DELETE
// ══════════════════════════════════════════════════════════════
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf_token($csrf_token)) {
        header("Location: admin_usuarios.php?msg=err_csrf"); exit();
    }

    $user_id = (int) ($_POST['user_id'] ?? 0);
    $mi_id   = (int) ($_SESSION['usuario_id'] ?? 0);

    if ($user_id === $mi_id) {
        header("Location: admin_usuarios.php?msg=err_self"); exit();
    }

    $res_r = mysqli_query($conex, "SELECT rol FROM users WHERE id=$user_id LIMIT 1");
    $row_r = mysqli_fetch_assoc($res_r);
    if ($row_r && $row_r['rol'] === 'admin' && contar_admins_activos($conex) <= 1) {
        header("Location: admin_usuarios.php?msg=err_last_admin"); exit();
    }

    mysqli_query($conex, "DELETE FROM users WHERE id=$user_id");
    header("Location: admin_usuarios.php?msg=ok_delete"); exit();
}

// ══════════════════════════════════════════════════════════════
//  LIST — cargar usuarios
// ══════════════════════════════════════════════════════════════
$res_usuarios = mysqli_query($conex,
    "SELECT id, nombre, apellido, email, user, rol, is_active, must_change_password, last_login_at, created_at
     FROM users
     ORDER BY created_at DESC");

$mi_id = (int) ($_SESSION['usuario_id'] ?? 0);

$msg = $_GET['msg'] ?? '';
$mensajes = array(
    'ok_create'        => array('success', 'Usuario creado correctamente.'),
    'ok_rol'           => array('success', 'Rol actualizado correctamente.'),
    'ok_force'         => array('success', 'Se forzó el cambio de contraseña.'),
    'ok_toggle'        => array('success', 'Estado del usuario actualizado.'),
    'ok_delete'        => array('success', 'Usuario eliminado correctamente.'),
    'err_self'         => array('danger',  'No podés realizar esta acción sobre tu propio usuario.'),
    'err_last_admin'   => array('danger',  'No podés eliminar, desactivar ni cambiar el rol del único administrador activo.'),
    'err_user_exists'  => array('danger',  'El nombre de usuario ya está en uso.'),
    'err_email_exists' => array('danger',  'El email ya está registrado.'),
    'err_pass_mismatch'=> array('danger',  'Las contraseñas no coinciden.'),
    'err_empty'        => array('danger',  'Todos los campos son obligatorios.'),
    'err_rol'          => array('danger',  'Rol no válido.'),
    'err_csrf'         => array('danger',  'La sesión del formulario expiró o es inválida. Volvé a intentarlo.'),
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios — SmartRACK</title>
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
                <a class="sr-nav-link active" href="admin_usuarios.php">
                    <i class="fas fa-users-cog"></i>
                    <span>Gestión de Usuarios</span>
                </a>
                <a class="sr-nav-link" href="admin_pdus.php">
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
                        <i class="fas fa-users-cog" style="color: var(--at-orange); font-size: 1.3rem;"></i>
                        <h1 class="dash-main-title mb-0">Gestión de Usuarios</h1>
                    </div>
                    <div class="small mt-1" style="color: var(--at-text-muted);">
                        Administrá los accesos al portal SmartRACK
                    </div>
                </div>
                <button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#modal-crear"
                        style="background: var(--at-orange); color:#fff; font-weight:700; font-family:'Montserrat',sans-serif;">
                    <i class="fas fa-user-plus me-1"></i> Nuevo Usuario
                </button>
            </div>
        </div>

        <div class="sr-page-body">

            <!-- Feedback sin emojis — íconos FA -->
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

            <!-- Tabla -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title" style="font-family:'Montserrat',sans-serif;">
                        <i class="fas fa-list me-2"></i>Usuarios del sistema
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap mb-0">
                        <thead style="background:#f4f6f9;">
                            <tr>
                                <th>#</th>
                                <th>Nombre completo</th>
                                <th>Usuario</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Cambio clave</th>
                                <th>Último login</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $n = 0;
                            while ($u = mysqli_fetch_assoc($res_usuarios)):
                                $n++;
                                $es_yo = ((int)$u['id'] === $mi_id);
                                $rol_badge = match($u['rol']) {
                                    'admin'    => 'badge-admin',
                                    'operator' => 'badge-operator',
                                    default    => 'badge-viewer'
                                };
                                $last_login = $u['last_login_at']
                                    ? date('d/m/Y H:i', strtotime($u['last_login_at']))
                                    : 'Nunca';
                            ?>
                            <tr>
                                <td><small class="text-muted"><?php echo $n; ?></small></td>
                                <td>
                                    <?php echo htmlspecialchars($u['nombre'] . ' ' . $u['apellido']); ?>
                                    <?php if ($es_yo): ?>
                                        <span class="badge bg-light text-dark ms-1" style="font-size:10px;">yo</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo htmlspecialchars($u['user']); ?></code></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <span class="badge <?php echo $rol_badge; ?>">
                                        <?php echo ucfirst($u['rol']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge" style="background:#276749;color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">Activo</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#F87171;color:#fff;font-family:'Montserrat',sans-serif;font-size:11px;padding:4px 9px;border-radius:4px;">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <!-- Badges tipográficos para must_change_password — sin emojis -->
                                <td class="text-center">
                                    <?php if ($u['must_change_password']): ?>
                                        <span class="badge" style="background:#f49825; color:#fff; font-family:'Montserrat',sans-serif; font-size:11px; padding:4px 9px; border-radius:4px;">Pendiente</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#23264f; color:#fff; font-family:'Montserrat',sans-serif; font-size:11px; padding:4px 9px; border-radius:4px;">Al día</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo $last_login; ?></small></td>
                                <td>
                                    <!-- Cambiar rol — celeste #5f7dbe -->
                                    <button type="button" class="btn btn-outline btn-accion btn-cambiar-rol"
                                            title="Cambiar rol"
                                            data-bs-toggle="modal" data-bs-target="#modal-cambiar-rol"
                                            data-uid="<?php echo (int)$u['id']; ?>"
                                            data-username="<?php echo htmlspecialchars($u['user'], ENT_QUOTES); ?>"
                                            data-rol="<?php echo htmlspecialchars($u['rol'], ENT_QUOTES); ?>"
                                            <?php echo $es_yo ? 'disabled' : ''; ?>>
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>

                                    <!-- Forzar clave — celeste #5f7dbe -->
                                    <form method="POST" action="admin_usuarios.php?action=force_password" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <button type="submit" class="btn btn-outline btn-accion btn-forzar-clave" title="Forzar cambio de contraseña"
                                                onclick="return confirm('Forzar cambio de contraseña para <?php echo htmlspecialchars($u['user'], ENT_QUOTES); ?>?')">
                                            <i class="fas fa-key"></i>
                                        </button>
                                    </form>

                                    <!-- Toggle activo/inactivo — naranja si activo (desactivar), verde si inactivo (activar) -->
                                    <form method="POST" action="admin_usuarios.php?action=toggle_active" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <input type="hidden" name="current_state" value="<?php echo (int)$u['is_active']; ?>">
                                        <button type="submit"
                                                class="btn btn-outline btn-accion <?php echo $u['is_active'] ? 'btn-toggle-activo-desactivar' : 'btn-toggle-activo-activar'; ?>"
                                                title="<?php echo $u['is_active'] ? 'Desactivar' : 'Activar'; ?> usuario"
                                                <?php echo $es_yo ? 'disabled' : ''; ?>
                                                onclick="return confirm('<?php echo $u['is_active'] ? 'Desactivar' : 'Activar'; ?> al usuario <?php echo htmlspecialchars($u['user'], ENT_QUOTES); ?>?')">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>

                                    <!-- Eliminar — rojo #F87171 -->
                                    <form method="POST" action="admin_usuarios.php?action=delete" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <button type="submit" class="btn btn-outline btn-accion btn-eliminar" title="Eliminar usuario"
                                                <?php echo $es_yo ? 'disabled' : ''; ?>
                                                onclick="return confirm('¿Estás seguro? Esta acción no se puede deshacer.')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if ($n === 0): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    <i class="fas fa-users me-2"></i>No hay usuarios registrados.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-muted" style="font-size:12.5px; font-family:'Montserrat',sans-serif;">
                    Total: <strong><?php echo $n; ?></strong> usuario<?php echo $n !== 1 ? 's' : ''; ?>
                </div>
            </div>

        </div>
    </div><!-- /sr-content -->
</div><!-- /wrapper -->

<!-- MODAL: CREAR USUARIO -->
<div class="modal fade" id="modal-crear" tabindex="-1" aria-labelledby="modal-crear-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="admin_usuarios.php?action=create" id="form-crear" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
        <div class="modal-header" style="background: var(--at-navy);">
          <h5 class="modal-title text-white" id="modal-crear-title" style="font-family:'Montserrat',sans-serif;">
            <i class="fas fa-user-plus me-2" style="color:#f49825;"></i>Nuevo Usuario
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label" for="c-nombre">Nombre <span class="text-danger">*</span></label>
              <input type="text" id="c-nombre" name="nombre" class="form-control" placeholder="Carlos" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label" for="c-apellido">Apellido <span class="text-danger">*</span></label>
              <input type="text" id="c-apellido" name="apellido" class="form-control" placeholder="Gómez" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="c-email">Email <span class="text-danger">*</span></label>
            <input type="email" id="c-email" name="email" class="form-control" placeholder="usuario@empresa.com" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="c-user">Usuario <span class="text-danger">*</span></label>
            <input type="text" id="c-user" name="username" class="form-control" placeholder="carlos_gomez" required>
            <small class="text-muted">Debe ser único. Sin espacios.</small>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label" for="c-pass">Contraseña <span class="text-danger">*</span></label>
              <input type="password" id="c-pass" name="pass" class="form-control" placeholder="Mínimo 8 caracteres" minlength="8" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label" for="c-pass2">Confirmar clave <span class="text-danger">*</span></label>
              <input type="password" id="c-pass2" name="pass_conf" class="form-control" placeholder="Repetir contraseña" required>
            </div>
          </div>
          <div id="crear-pass-err" class="alert alert-danger py-1 px-2" style="font-size:13px; display:none;">
            Las contraseñas no coinciden.
          </div>
          <div class="mb-3">
            <label class="form-label" for="c-rol">Rol <span class="text-danger">*</span></label>
            <select id="c-rol" name="rol" class="form-select" required>
              <option value="viewer" selected>Viewer — solo lectura</option>
              <option value="operator">Operator — control de tomas</option>
              <option value="admin">Admin — acceso total</option>
            </select>
          </div>
          <div class="mb-0 form-check">
            <input type="checkbox" class="form-check-input" id="c-must" name="must_change" checked>
            <label class="form-check-label" for="c-must">
              Forzar cambio de contraseña en el primer login
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn" style="background:#f49825; color:#fff; font-weight:700; font-family:'Montserrat',sans-serif;">
            <i class="fas fa-save me-1"></i> Crear Usuario
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: CAMBIAR ROL -->
<div class="modal fade" id="modal-cambiar-rol" tabindex="-1" aria-labelledby="modal-rol-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <form method="POST" action="admin_usuarios.php?action=change_rol">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
        <input type="hidden" id="cr-uid" name="user_id" value="">
        <div class="modal-header" style="background: var(--at-navy);">
          <h5 class="modal-title text-white" id="modal-rol-title" style="font-family:'Montserrat',sans-serif;">
            <i class="fas fa-exchange-alt me-2" style="color:#f49825;"></i>
            Cambiar Rol — <span id="cr-username-label" class="fw-normal"></span>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Usuario</label>
            <p class="form-control-plaintext fw-bold mb-0" id="cr-username-display"></p>
          </div>
          <div class="mb-3">
            <label class="form-label">Rol actual</label>
            <div class="rol-badge-display">
              <span id="cr-rol-badge" class="badge"></span>
            </div>
          </div>
          <div class="mb-0">
            <label class="form-label" for="cr-nuevo-rol">Nuevo rol <span class="text-danger">*</span></label>
            <select id="cr-nuevo-rol" name="nuevo_rol" class="form-select" required>
              <option value="admin">Admin</option>
              <option value="operator">Operator</option>
              <option value="viewer">Viewer</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn" style="background:#f49825; color:#fff; font-weight:700; font-family:'Montserrat',sans-serif;">
            <i class="fas fa-save me-1"></i> Guardar Cambio
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script>
(function () {

    // ── Toggle de sidebar, igual que en el resto de los dashboards ──
    var toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }

    // ── Poblar modal Cambiar Rol ─────────────────────────────
    var rolBadgeClass = { admin: 'badge-admin', operator: 'badge-operator', viewer: 'badge-viewer' };
    var rolLabel      = { admin: 'Admin', operator: 'Operator', viewer: 'Viewer' };

    document.querySelectorAll('.btn-cambiar-rol').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var uid  = this.dataset.uid;
            var user = this.dataset.username;
            var rol  = this.dataset.rol;

            document.getElementById('cr-uid').value = uid;
            document.getElementById('cr-username-label').textContent = user;
            document.getElementById('cr-username-display').textContent = user;

            var badge = document.getElementById('cr-rol-badge');
            badge.className = 'badge ' + (rolBadgeClass[rol] || 'badge-viewer');
            badge.textContent = rolLabel[rol] || rol;

            document.getElementById('cr-nuevo-rol').value = rol;
        });
    });

    // ── Validación contraseñas en modal Crear ────────────────
    var formCrear = document.getElementById('form-crear');
    var passErr   = document.getElementById('crear-pass-err');
    var passInput = document.getElementById('c-pass');
    var pass2Input = document.getElementById('c-pass2');

    formCrear.addEventListener('submit', function (e) {
        if (passInput.value !== pass2Input.value) {
            e.preventDefault();
            passErr.style.display = 'block';
            pass2Input.focus();
        } else {
            passErr.style.display = 'none';
        }
    });

    [passInput, pass2Input].forEach(function (input) {
        input.addEventListener('input', function () {
            passErr.style.display = (passInput.value !== pass2Input.value) ? 'block' : 'none';
        });
    });

    // ── Auto-dismiss alertas ─────────────────────────────────
    var autoAlert = document.querySelector('.alert-auto');
    if (autoAlert) {
        setTimeout(function () {
            autoAlert.style.transition = 'opacity 0.4s ease';
            autoAlert.style.opacity = '0';
            setTimeout(function () { autoAlert.remove(); }, 400);
        }, 3500);
    }

})();
</script>
</body>
</html>
<?php mysqli_close($conex); ?>