<!-- bloque_selector_pdu.php — SmartRACK AucaTek
     Incluir con: include 'bloque_selector_pdu.php';
     Ubicación: dentro de .sr-page-header, ANTES del título principal.
     Requiere que la sesión ya esté iniciada y $_SESSION['usuario_id'] exista.
     El selector solo se renderiza si el usuario tiene más de 1 PDU activo.
     JS expuesto globalmente:
       - window.srPduActual → string codigo_pdu actualmente seleccionado
       - window.srCambiarPdu(codigo_pdu) → cambia el PDU activo y llama actualizarDashboard() -->
<div id="sr-pdu-selector-wrap">
    <!-- Se rellena dinámicamente por JS si hay > 1 PDU -->
</div>

<style>
/* ── Selector de PDU ─────────────────────────────────────────── */
#sr-pdu-selector-wrap {
    margin-bottom: 10px;
}
#sr-pdu-selector-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
#sr-pdu-selector-bar label {
    font-family: 'Montserrat', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: var(--at-text-muted);
    white-space: nowrap;
    margin: 0;
}
.sr-pdu-select {
    appearance: none;
    -webkit-appearance: none;
    background-color: #ffffff;
    border: 1.5px solid rgba(35,38,79,0.18);
    border-radius: 7px;
    padding: 6px 32px 6px 12px;
    font-family: 'Montserrat', sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: var(--at-navy);
    cursor: pointer;
    outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2323264f'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    min-width: 200px;
    max-width: 320px;
}
.sr-pdu-select:focus {
    border-color: var(--at-orange);
    box-shadow: 0 0 0 3px rgba(244,152,37,0.15);
}
.sr-pdu-select:hover {
    border-color: var(--at-celeste);
}

/* Badges de modo */
.sr-pdu-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px;
    border-radius: 20px;
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.02em;
    white-space: nowrap;
}
.sr-pdu-badge-premium {
    background: rgba(244,152,37,0.12);
    color: #b06800;
    border: 1px solid rgba(244,152,37,0.35);
}
.sr-pdu-badge-normal {
    background: rgba(95,125,190,0.12);
    color: #3d5899;
    border: 1px solid rgba(95,125,190,0.30);
}

/* Indicador online/offline */
.sr-pdu-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.sr-pdu-status-dot.online  { background: #276749; }
.sr-pdu-status-dot.offline { background: #F87171; }

/* Estado de carga */
.sr-pdu-loading {
    font-family: 'Montserrat', sans-serif;
    font-size: 12px;
    color: var(--at-text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.sr-pdu-loading i { animation: spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script>
// ── Selector de PDU — SmartRACK ───────────────────────────────
(function() {
    const wrap = document.getElementById('sr-pdu-selector-wrap');
    if (!wrap) return;

    // Clave de sessionStorage
    const SESSION_KEY = 'sr_codigo_pdu';

    // Exponer el PDU activo globalmente para que actualizarDashboard() lo use
    window.srPduActual = sessionStorage.getItem(SESSION_KEY) || '';

    // Mostrar spinner mientras carga
    wrap.innerHTML = '<div class="sr-pdu-loading"><i class="fas fa-circle-notch"></i> Cargando PDUs...</div>';

    fetch('get_pdus_usuario.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {

            // Si hay error o viene vacío
            if (!data || !data.success || !Array.isArray(data.pdus)) {
                wrap.innerHTML = '';
                return;
            }

            const pdus = data.pdus;

            // Si tiene 1 solo PDU: guardar en sessionStorage y no mostrar nada
            if (pdus.length <= 1) {
                if (pdus.length === 1) {
                    window.srPduActual = pdus[0].codigo_pdu;
                    sessionStorage.setItem(SESSION_KEY, pdus[0].codigo_pdu);
                }
                wrap.innerHTML = '';
                return;
            }

            // Determinar cuál PDU está activo:
            // 1. Lo que haya en sessionStorage
            // 2. Si no existe en la lista, usar el primero
            let pduActual = window.srPduActual;
            const existe = pdus.some(function(p) { return p.codigo_pdu === pduActual; });
            if (!pduActual || !existe) {
                pduActual = pdus[0].codigo_pdu;
            }
            window.srPduActual = pduActual;
            sessionStorage.setItem(SESSION_KEY, pduActual);

            // Sincronizar dashboard con el PDU guardado en sessionStorage
            // (puede diferir del que resolvió PHP en el servidor)
            if (typeof actualizarDashboard === 'function') {
                actualizarDashboard(window.srPduActual);
            }

            // Construir el select
            let optionsHtml = '';
            pdus.forEach(function(p) {
                const selected = p.codigo_pdu === pduActual ? 'selected' : '';
                const modoLabel = p.modo === 'premium' ? ' [Premium]' : ' [Normal]';
                const nombre = p.nombre ? p.nombre : ('PDU-' + p.codigo_pdu.substring(0, 8));
                optionsHtml += '<option value="' + p.codigo_pdu + '" ' + selected + '>'
                    + nombre + modoLabel
                    + '</option>';
            });

            // Calcular badge e indicador del PDU activo
            function getPduActivo() {
                return pdus.find(function(p) { return p.codigo_pdu === window.srPduActual; }) || pdus[0];
            }

            function renderBadgeYDot(pdu) {
                const dotClass = pdu.online ? 'online' : 'offline';
                const modeClass = pdu.modo === 'premium' ? 'sr-pdu-badge-premium' : 'sr-pdu-badge-normal';
                const modeLabel = pdu.modo === 'premium' ? 'Premium' : 'Normal';
                const modeIcon  = pdu.modo === 'premium'
                    ? '<i class="fas fa-star" style="font-size:9px;"></i>'
                    : '<i class="fas fa-circle" style="font-size:9px;"></i>';
                return '<span class="sr-pdu-status-dot ' + dotClass + '" title="' + (pdu.online ? 'Online' : 'Offline') + '"></span>'
                     + '<span class="sr-pdu-badge ' + modeClass + '">' + modeIcon + ' ' + modeLabel + '</span>';
            }

            const pduInicioActivo = getPduActivo();
            wrap.innerHTML =
                '<div id="sr-pdu-selector-bar">'
                + '<label for="sr-pdu-select"><i class="fas fa-server" style="color:var(--at-orange);margin-right:4px;"></i>PDU activo:</label>'
                + '<select class="sr-pdu-select" id="sr-pdu-select">' + optionsHtml + '</select>'
                + '<span id="sr-pdu-meta">' + renderBadgeYDot(pduInicioActivo) + '</span>'
                + '</div>';

            // Listener del cambio de PDU
            document.getElementById('sr-pdu-select').addEventListener('change', function() {
                const nuevoCodigo = this.value;
                window.srPduActual = nuevoCodigo;
                sessionStorage.setItem(SESSION_KEY, nuevoCodigo);

                // Actualizar badge e indicador sin recargar
                const pduSel = pdus.find(function(p) { return p.codigo_pdu === nuevoCodigo; });
                if (pduSel) {
                    const meta = document.getElementById('sr-pdu-meta');
                    if (meta) meta.innerHTML = renderBadgeYDot(pduSel);
                }

                // Llamar a actualizarDashboard si existe, pasando el codigo_pdu
                if (typeof actualizarDashboard === 'function') {
                    actualizarDashboard(window.srPduActual);
                }
            });

        })
        .catch(function() {
            // Error silencioso — el selector no aparece, el dashboard funciona igual
            wrap.innerHTML = '';
        });

})();
</script>
