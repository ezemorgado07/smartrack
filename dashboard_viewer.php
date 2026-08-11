<?php
require_once 'auth.php';
require_once 'dbconn.php';
requerir_rol(array('viewer'));
enviar_headers_seguridad();

// ── Obtener PDU y modo del usuario ───────────────────────────
$uid     = (int) $_SESSION['usuario_id'];
$usr_res = mysqli_query($conex, "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
$usr_row = mysqli_fetch_assoc($usr_res);

if (!empty($usr_row['codigo_pdu'])) {
    $cod_sql = mysqli_real_escape_string($conex, $usr_row['codigo_pdu']);
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.activo, p.ip_local, p.ultimo_contacto,
                l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.codigo_pdu = '$cod_sql' AND p.activo = 1 LIMIT 1");
} else {
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.activo, p.ip_local, p.ultimo_contacto,
                l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.activo = 1 ORDER BY p.id ASC LIMIT 1");
}
$pdu       = mysqli_fetch_assoc($pdu_res);
$device_id = $pdu ? (int) $pdu['id'] : 1;
$modo      = $pdu ? $pdu['modo'] : 'normal';
$pdu_online = $pdu && !empty($pdu['ultimo_contacto'])
    ? (time() - strtotime($pdu['ultimo_contacto'])) <= 30 : false;

// Asegurar $cod_sql en el path del fallback dev
if (empty($cod_sql)) {
    $cod_sql = mysqli_real_escape_string($conex, $pdu['codigo_pdu'] ?? '');
}

$licencia_vigente = ($modo === 'premium' && !empty($pdu['fecha_vencimiento']))
    ? strtotime($pdu['fecha_vencimiento']) >= strtotime('today') : false;
$es_premium = ($modo === 'premium' && $licencia_vigente);

$telem    = null;
$labels   = array(); $potencia = array(); $voltaje = array();

if ($es_premium) {
    $res_telem = mysqli_query($conex,
        "SELECT * FROM telemetry_pzem WHERE codigo_pdu = '$cod_sql'
         ORDER BY reading_timestamp DESC LIMIT 1");
    $telem = mysqli_fetch_assoc($res_telem);
    $res_chart = mysqli_query($conex,
        "SELECT reading_timestamp, power_w, voltage_v FROM telemetry_pzem
         WHERE codigo_pdu = '$cod_sql' ORDER BY reading_timestamp DESC LIMIT 48");
    while ($row = mysqli_fetch_assoc($res_chart)) {
        $labels[]   = date('H:i', strtotime($row['reading_timestamp']));
        $potencia[] = (float) $row['power_w'];
        $voltaje[]  = (float) $row['voltage_v'];
    }
    $labels   = array_reverse($labels);
    $potencia = array_reverse($potencia);
    $voltaje  = array_reverse($voltaje);
}

$res_outlets = mysqli_query($conex,
    "SELECT * FROM outlets WHERE codigo_pdu = '$cod_sql' ORDER BY outlet_number ASC");
$outlets = array();
while ($o = mysqli_fetch_assoc($res_outlets)) { $outlets[] = $o; }

$res_logs = mysqli_query($conex,
    "SELECT * FROM event_logs WHERE device_id = $device_id
     ORDER BY event_timestamp DESC LIMIT 10");
mysqli_close($conex);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(generar_csrf_token()); ?>">
    <title>SmartRACK — Monitoreo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="antialiased dash-page">
<div class="wrapper">

    <!-- ══ NAVBAR custom ════════════════════════════════════════ -->
    <div id="sr-navbar">
        <button id="sidebarToggle" type="button" title="Colapsar menú">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard_viewer.php" id="sr-brand">
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
                <div class="user-avatar"><?php echo inicial_usuario_actual(); ?></div>
                <div>
                    <div class="text-white fw-bold" style="font-family:'Montserrat',sans-serif;font-size:13px;">
                        <?php echo htmlspecialchars($_SESSION['usuario']); ?>
                    </div>
                    <div class="text-white-50" style="font-size:11px;">Viewer</div>
                </div>
            </div>
            <nav>
                <a class="sr-nav-link active" href="dashboard_viewer.php">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
                <a class="sr-nav-link" href="#logs">
                    <i class="fas fa-bell"></i><span>Alertas y Logs</span>
                </a>
                <?php if ($es_premium): ?>
                <a class="sr-nav-link" href="exportar_datos.php">
                    <i class="fas fa-download"></i><span>Exportar Datos</span>
                </a>
                <?php endif; ?>
                <hr class="sr-divider">
                <a class="sr-nav-link" href="cambiar_contra.php">
                    <i class="fas fa-key"></i><span>Cambiar Clave</span>
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
            <?php include 'bloque_selector_pdu.php'; ?>
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-eye" style="color:var(--at-orange);font-size:1.3rem;"></i>
                <h1 class="dash-main-title mb-0">Vista de Monitoreo</h1>
                <span class="badge ms-2" style="background:var(--at-navy);color:#fff;font-family:'Montserrat',sans-serif;">Solo Lectura</span>
            </div>
            <div class="small mt-1" style="color:var(--at-text-muted);">
                Última lectura: <?php echo $telem ? htmlspecialchars($telem['reading_timestamp']) : 'N/D'; ?>
            </div>
        </div>

        <div class="sr-page-body">

            <!-- Banner de alertas activas -->
            <div id="sr-alertas-banner" style="display:none;margin-bottom:18px;">
                <div style="background:#fef2f2;border-left:4px solid #F87171;border-radius:8px;padding:14px 20px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fas fa-exclamation-triangle" style="color:#F87171;font-size:1.1rem;"></i>
                        <strong style="font-family:'Montserrat',sans-serif;font-size:13px;color:#7f1d1d;">Alertas activas en el dispositivo</strong>
                    </div>
                    <div id="sr-alertas-lista" style="font-size:13px;color:#7f1d1d;"></div>
                </div>
            </div>

            <!-- Métricas PZEM — solo premium -->
<?php if (!$es_premium): ?>
    <?php include 'bloque_premium_upgrade.php'; ?>
<?php else: ?>
            <div class="row row-deck row-cards mb-4">
            <?php
            $metricas = array(
                array('id'=>'val-voltage','label'=>'Voltaje',        'val'=>$telem?number_format($telem['voltage_v'],1):'--',    'unit'=>'V',  'icon'=>'fa-bolt',        'cls'=>'metric-card-1','icls'=>'metric-icon-1'),
                array('id'=>'val-current','label'=>'Corriente',      'val'=>$telem?number_format($telem['current_a'],2):'--',    'unit'=>'A',  'icon'=>'fa-tint',        'cls'=>'metric-card-2','icls'=>'metric-icon-2'),
                array('id'=>'val-power',  'label'=>'Potencia',       'val'=>$telem?number_format($telem['power_w'],0):'--',      'unit'=>'W',  'icon'=>'fa-fire',        'cls'=>'metric-card-3','icls'=>'metric-icon-3'),
                array('id'=>'val-pf',     'label'=>'Factor Potencia','val'=>$telem?number_format($telem['power_factor'],3):'--', 'unit'=>'',   'icon'=>'fa-chart-line',  'cls'=>'metric-card-4','icls'=>'metric-icon-4'),
                array('id'=>'val-freq',   'label'=>'Frecuencia',     'val'=>$telem?number_format($telem['frequency_hz'],2):'--', 'unit'=>'Hz', 'icon'=>'fa-wave-square', 'cls'=>'metric-card-5','icls'=>'metric-icon-5'),
                array('id'=>'val-energy', 'label'=>'Consumo Acum.',  'val'=>$telem?number_format($telem['energy_kwh'],3):'--',   'unit'=>'kWh','icon'=>'fa-battery-full','cls'=>'metric-card-6','icls'=>'metric-icon-6'),
            );
            foreach ($metricas as $m):
            ?>
            <div class="col-sm-6 col-lg-2">
                <div class="card <?php echo $m['cls']; ?>">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center mb-2 gap-2">
                            <i class="fas <?php echo $m['icon']; ?> <?php echo $m['icls']; ?>"></i>
                            <span class="metric-label"><?php echo $m['label']; ?></span>
                        </div>
                        <div class="metric-value" id="<?php echo $m['id']; ?>">
                            <?php echo $m['val']; ?>
                            <?php if ($m['unit']): ?><span class="metric-unit"><?php echo $m['unit']; ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Tomas — solo lectura, sin toggles funcionales -->
            <div class="mb-3">
                <div class="section-title">
                    <i class="fas fa-plug" style="color:var(--at-orange);"></i>
                    Estado de Tomas
                    <span class="badge ms-2" style="background:var(--at-navy);color:#fff;font-size:10px;">
                        <i class="fas fa-lock me-1"></i>Solo Lectura
                    </span>
                </div>
            </div>
            <div class="row row-cards mb-4">
                <?php foreach ($outlets as $outlet): $num=(int)$outlet['outlet_number']; ?>
                <div class="col-sm-6 col-lg">
                    <div class="card outlet-card" style="cursor:default;">
                        <div class="card-body text-center p-3">
                            <div class="outlet-label">
                                <i class="fas fa-plug me-1"></i><?php echo htmlspecialchars($outlet['label']); ?>
                            </div>
                            <div class="my-2">
                                <span class="status-dot <?php echo $outlet['is_on']?'status-green':'status-gray'; ?>" id="dot-toma-<?php echo $num; ?>"></span>
                                <span class="fw-bold ms-1 <?php echo $outlet['is_on']?'outlet-status-on':'outlet-status-off'; ?>" id="status-text-<?php echo $num; ?>">
                                    <?php echo $outlet['is_on']?'● ON':'● OFF'; ?>
                                </span>
                            </div>
                            <label class="form-check form-switch d-flex justify-content-center mt-2" style="opacity:0.35;pointer-events:none;">
                                <input class="form-check-input" type="checkbox" disabled <?php echo $outlet['is_on']?'checked':''; ?>>
                            </label>
                            <small style="color:var(--at-text-muted);font-size:11px;">
                                <i class="fas fa-lock me-1"></i>Sin permisos
                            </small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Gráfico + Resumen — solo premium -->
            <?php if ($es_premium): ?>
            <div class="row row-cards mb-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header" style="border-bottom:2px solid var(--at-orange);">
                            <h3 class="card-title section-title mb-0">
                                <i class="fas fa-chart-area" style="color:var(--at-orange);"></i>
                                Consumo — Últimas 24 hs
                            </h3>
                        </div>
                        <div class="card-body"><canvas id="chartConsumo" height="100"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title section-title mb-0">
                                <i class="fas fa-server" style="color:var(--at-navy);"></i>
                                Estado del Dispositivo
                            </h3>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-5" style="color:var(--at-text-muted);">Dispositivo</dt><dd class="col-7 fw-bold">ePDU SmartRACK</dd>
                                <dt class="col-5" style="color:var(--at-text-muted);">Estado</dt>
                                <dd class="col-7">
                                    <span class="badge <?php echo $pdu_online ? 'bg-success' : 'bg-danger'; ?>" id="badge-pdu-online">
                                        <?php echo $pdu_online ? 'Online' : 'Offline'; ?>
                                    </span>
                                </dd>
                                <dt class="col-5" style="color:var(--at-text-muted);">Modo</dt>
                                <dd class="col-7">
                                    <span class="badge" id="badge-pdu-modo" style="background:<?php echo $es_premium ? 'var(--at-orange)' : 'var(--at-celeste)'; ?>;color:#fff;">
                                        <?php echo $es_premium ? 'Premium' : 'Normal'; ?>
                                    </span>
                                </dd>
                                <dt class="col-5" style="color:var(--at-text-muted);">Tomas ON</dt><dd class="col-7 fw-bold"><?php echo count(array_filter($outlets,fn($o)=>$o['is_on']));?> / 5</dd>
                                <dt class="col-5" style="color:var(--at-text-muted);">Energía</dt><dd class="col-7 fw-bold"><?php echo $telem?number_format($telem['energy_kwh'],3):'--';?> kWh</dd>
                                <dt class="col-5" style="color:var(--at-text-muted);">IP Local</dt><dd class="col-7 fw-bold"><?php echo htmlspecialchars($pdu['ip_local'] ?? '—'); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>

            <!-- Log -->
            <div class="card mb-4" id="logs">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h3 class="card-title section-title mb-0">
                        <i class="fas fa-bell" style="color:var(--at-orange);"></i>
                        Alertas y Logs del Sistema
                    </h3>
                    <span class="small" style="color:var(--at-text-muted);">Últimos 10 eventos</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter table-hover card-table">
                        <thead><tr><th>Fecha / Hora</th><th>Tipo</th><th>Severidad</th><th>Descripción</th></tr></thead>
                        <tbody id="logs-tbody">
                            <?php
                            $type_cls=array('alert'=>'badge-type-alert','control'=>'badge-type-control','network'=>'badge-type-network','system'=>'badge-type-system');
                            $sev_cls=array('critical'=>'badge-sev-critical','warning'=>'badge-sev-warning','info'=>'badge-sev-info');
                            while($log=mysqli_fetch_assoc($res_logs)):?>
                            <tr>
                                <td class="small" style="color:var(--at-text-muted);"><?php echo date('d/m/Y H:i',strtotime($log['event_timestamp']));?></td>
                                <td><span class="badge <?php echo $type_cls[$log['event_type']]??'badge-type-system';?>"><?php echo htmlspecialchars($log['event_type']);?></span></td>
                                <td><span class="badge <?php echo $sev_cls[$log['severity']]??'badge-sev-info';?>"><?php echo htmlspecialchars($log['severity']);?></span></td>
                                <td><?php echo htmlspecialchars($log['message']);?></td>
                            </tr>
                            <?php endwhile;?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels=<?php echo json_encode($labels);?>;
const potencia=<?php echo json_encode($potencia);?>;
const voltaje=<?php echo json_encode($voltaje);?>;

const canvasConsumo = document.getElementById('chartConsumo');
if (canvasConsumo) new Chart(canvasConsumo.getContext('2d'),{
    type:'line',
    data:{labels:labels,datasets:[
        {label:'Potencia (W)',data:potencia,borderColor:'#f49825',backgroundColor:'rgba(244,152,37,0.10)',borderWidth:2,pointRadius:2,yAxisID:'yW',tension:0.3,fill:true},
        {label:'Voltaje (V)', data:voltaje, borderColor:'#23264f',backgroundColor:'rgba(35,38,79,0.05)', borderWidth:2,pointRadius:2,yAxisID:'yV',tension:0.3}
    ]},
    options:{responsive:true,interaction:{mode:'index',intersect:false},scales:{
        yW:{type:'linear',position:'left', title:{display:true,text:'Watts'}},
        yV:{type:'linear',position:'right',title:{display:true,text:'Voltios'},grid:{drawOnChartArea:false}}
    }}
});

function actualizarDashboard(codigoPdu){
    const pdu = codigoPdu || window.srPduActual || '';
    const url = 'get_telemetry.php' + (pdu ? '?codigo_pdu=' + encodeURIComponent(pdu) : '');
    fetch(url).then(r=>r.json()).then(function(d){
        if(!d||!d.success)return;
        if(d.outlets){
            d.outlets.forEach(function(o){
                const dot=document.getElementById('dot-toma-'+o.outlet_number);
                const text=document.getElementById('status-text-'+o.outlet_number);
                if(!dot)return;
                if(o.is_on){dot.classList.remove('status-gray');dot.classList.add('status-green');if(text){text.textContent='● ON';text.className='fw-bold ms-1 outlet-status-on';}}
                else{dot.classList.remove('status-green');dot.classList.add('status-gray');if(text){text.textContent='● OFF';text.className='fw-bold ms-1 outlet-status-off';}}
            });
        }
        if(d.modo==='premium'&&d.telemetry){
            const t=d.telemetry;
            const vv=document.getElementById('val-voltage');const vc=document.getElementById('val-current');
            const vp=document.getElementById('val-power');const vpf=document.getElementById('val-pf');
            const vf=document.getElementById('val-freq');const ve=document.getElementById('val-energy');
            if(vv)vv.innerHTML=parseFloat(t.voltage_v).toFixed(1)+' <span class="metric-unit">V</span>';
            if(vc)vc.innerHTML=parseFloat(t.current_a).toFixed(2)+' <span class="metric-unit">A</span>';
            if(vp)vp.innerHTML=parseFloat(t.power_w).toFixed(0)+' <span class="metric-unit">W</span>';
            if(vpf)vpf.innerHTML=parseFloat(t.power_factor).toFixed(3);
            if(vf)vf.innerHTML=parseFloat(t.frequency_hz).toFixed(2)+' <span class="metric-unit">Hz</span>';
            if(ve)ve.innerHTML=parseFloat(t.energy_kwh).toFixed(3)+' <span class="metric-unit">kWh</span>';
        }
        var bannerAlertas=document.getElementById('sr-alertas-banner');
        var listaAlertas=document.getElementById('sr-alertas-lista');
        if(bannerAlertas&&Array.isArray(d.alertas_activas)&&d.alertas_activas.length>0){
            bannerAlertas.style.display='block';
            listaAlertas.innerHTML=d.alertas_activas.map(function(al){
                return '<div style="margin-bottom:4px;"><i class="fas fa-circle" style="font-size:6px;vertical-align:middle;margin-right:6px;"></i>'+escHtml(al.mensaje)+' — <strong>'+al.valor_detectado+'</strong> (umbral: '+al.umbral_configurado+')</div>';
            }).join('');
        }else if(bannerAlertas){bannerAlertas.style.display='none';}
        const badgeOnline=document.getElementById('badge-pdu-online');
        const badgeModo=document.getElementById('badge-pdu-modo');
        if(badgeOnline){badgeOnline.textContent=d.online?'Online':'Offline';badgeOnline.className=d.online?'badge bg-success':'badge bg-danger';}
        if(badgeModo){badgeModo.textContent=d.modo==='premium'?'Premium':'Normal';badgeModo.style.background=d.modo==='premium'?'var(--at-orange)':'var(--at-celeste)';}

        // Mensaje de período de gracia
        var graciaMsg=document.getElementById('sr-gracia-msg');
        var graciaDir=document.getElementById('sr-gracia-dias');
        if(graciaMsg){
            if(d.en_gracia&&d.dias_gracia_restantes>0){graciaMsg.style.display='block';if(graciaDir)graciaDir.textContent=d.dias_gracia_restantes;}
            else{graciaMsg.style.display='none';}
        }

        const logsUrl='get_logs.php'+(pdu?'?codigo_pdu='+encodeURIComponent(pdu):'');
        fetch(logsUrl).then(function(r){return r.json();}).then(function(ld){
            if(!ld||!ld.success||!Array.isArray(ld.logs))return;
            const tbody=document.getElementById('logs-tbody');
            if(!tbody)return;
            const typeCls={alert:'badge-type-alert',control:'badge-type-control',network:'badge-type-network',system:'badge-type-system'};
            const sevCls={critical:'badge-sev-critical',warning:'badge-sev-warning',info:'badge-sev-info'};
            if(ld.logs.length===0){tbody.innerHTML='<tr><td colspan="4" class="text-center text-muted small py-3">Sin eventos registrados.</td></tr>';return;}
            tbody.innerHTML=ld.logs.map(function(log){
                const tc=typeCls[log.event_type]||'badge-type-system';
                const sc=sevCls[log.severity]||'badge-sev-info';
                const dt=new Date(log.event_timestamp.replace(' ','T'));
                const fec=dt.toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric'})+' '+dt.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'});
                return '<tr><td class="small" style="color:var(--at-text-muted);">'+fec+'</td>'
                    +'<td><span class="badge '+tc+'">'+escHtml(log.event_type)+'</span></td>'
                    +'<td><span class="badge '+sc+'">'+escHtml(log.severity)+'</span></td>'
                    +'<td>'+escHtml(log.message)+'</td></tr>';
            }).join('');
        }).catch(function(){});
    });
}

function escHtml(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
actualizarDashboard();
setInterval(actualizarDashboard,10000);

document.getElementById('sidebarToggle').addEventListener('click',function(){
    document.body.classList.toggle('sidebar-collapsed');
    const icon=this.querySelector('i');
    if(document.body.classList.contains('sidebar-collapsed')){
        icon.classList.replace('fa-bars','fa-chevron-right');
        this.title='Expandir menú';
    } else {
        icon.classList.replace('fa-chevron-right','fa-bars');
        this.title='Colapsar menú';
    }
});

// ── Navegación activa en sidebar ──────────────────────────────
(function() {
    const navLinks = document.querySelectorAll(".sr-nav-link");

    function clearActive() {
        navLinks.forEach(function(l) { l.classList.remove("active"); });
    }

    const dashLink = document.querySelector(
        ".sr-nav-link[href=\"dashboard_admin.php\"], " +
        ".sr-nav-link[href=\"dashboard_operator.php\"], " +
        ".sr-nav-link[href=\"dashboard_viewer.php\"]"
    );

    const anchorSections = [];
    navLinks.forEach(function(link) {
        const href = link.getAttribute("href");
        if (href && href.startsWith("#") && href.length > 1) {
            const section = document.getElementById(href.substring(1));
            if (section) anchorSections.push({ link: link, section: section });
        }
    });

    function updateActive() {
        const triggerY = 56 + 80;
        if (window.scrollY < 100) {
            clearActive();
            if (dashLink) dashLink.classList.add("active");
            return;
        }
        let current = null;
        anchorSections.forEach(function(item) {
            const rect = item.section.getBoundingClientRect();
            if (rect.top <= triggerY) { current = item; }
        });
        if (current) {
            clearActive();
            current.link.classList.add("active");
        } else {
            clearActive();
            if (dashLink) dashLink.classList.add("active");
        }
    }

    let ticking = false;
    window.addEventListener("scroll", function() {
        if (!ticking) {
            requestAnimationFrame(function() { updateActive(); ticking = false; });
            ticking = true;
        }
    });

    anchorSections.forEach(function(item) {
        item.link.addEventListener("click", function() {
            clearActive();
            item.link.classList.add("active");
        });
    });

    updateActive();
})();
</script>
</body>
</html>