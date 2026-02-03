/* KOL RRHH — Modal Alta/Edición + AJAX guardar + precarga de campos + VALIDACIONES */
(function () {
  // ✅ WP: usar AJAX_URL vía KOL_RRHH (frontend) o AJAX_URL (admin)
  const AJAX_URL = (typeof KOL_RRHH !== 'undefined' && KOL_RRHH && KOL_RRHH.ajaxurl)
    ? KOL_RRHH.ajaxurl
    : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
  const AJAX_NONCE = (typeof KOL_RRHH !== 'undefined' && KOL_RRHH && KOL_RRHH.nonce)
    ? KOL_RRHH.nonce
    : '';

  // cache de items sueldo (para editar sin recargar)
  let __LAST_SUELDO_ROWS__ = [];
  let __CURRENT_LEGAJO__ = 0;
  let __CURRENT_CLOVER_ID__ = '';
  let __CURRENT_CLOVER_PAIRS__ = [];
  let __VIEW_MODE__ = 'employees'; // employees | locales
  let __CURRENT_ULTIMO_INGRESO__ = '';
  let __CURRENT_BASE__ = 0;
  let __CURRENT_DESEMPENO_ROWS__ = [];

let __CURRENT_COMISION__ = 0;
const KOL_RRHH_ROLES = [
  'Auxiliar','Vendedor','Responsable vendedor','Responsable Global',
  'Responsable del local','Administracion','Tecnico','Redes',
  'Recursos Humanos','Entrenamiento','Responsable compras',
  'Responsable pedidos','Responsable stock'
];
const PRESENTISMO_FACTOR = 1 / 12;

  function buildParticipacionOptions(){
    const opts = [];
    for (let v = 0; v <= 10.0001; v += 0.5) {
      const value = v.toFixed(1);
      const label = value.replace('.', ',');
      opts.push(`<option value="${value}">${label}</option>`);
    }
    return `<option value="0.0">0,0</option>` + opts.slice(1).join('');
  }

  function normalizeParticipacion(v){
    const n = parseFloat(String(v ?? '0').replace(',', '.'));
    if (isNaN(n)) return '0.0';
    const clamped = Math.max(0, Math.min(10, n));
    return clamped.toFixed(1);
  }

  function formatParticipacionAR(v){
    return normalizeParticipacion(v).replace('.', ',');
  }


  function qs(id) { return document.getElementById(id); }

  function setText(id, v){
    const el = qs(id);
    if (!el) return;
    el.textContent = (v === null || v === undefined) ? '' : String(v);
  }

  function setVal(id, v){
    const el = qs(id);
    if (!el) return;
    el.value = (v === null || v === undefined) ? '' : String(v);
  }

  function getVal(id){
    const el = qs(id);
    return el ? String(el.value || '').trim() : '';
  }

  function getPeriodoMesISO(){
    const fin = getVal('kolrrhh-sueldo-periodo-fin');
    const ini = getVal('kolrrhh-sueldo-periodo-inicio');
    const ref = fin || ini;
    if (!ref) return '';
    const m = String(ref).match(/^(\d{4})-(\d{2})/);
    if (!m) return '';
    return `${m[1]}-${m[2]}`;
  }

  function validarPeriodoSueldo(fechaInicio, fechaFin) {
  if (!fechaInicio || !fechaFin) {
    return 'Completá la fecha de inicio y fin.';
  }

  const ini = new Date(fechaInicio + 'T00:00:00');
  const fin = new Date(fechaFin + 'T00:00:00');

  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);

  // 1️⃣ inicio no puede ser mayor que fin
  if (ini > fin) {
    return 'La fecha de inicio no puede ser mayor que la fecha de fin.';
  }

  // 2️⃣ mismo mes y mismo año
  if (
    ini.getMonth() !== fin.getMonth() ||
    ini.getFullYear() !== fin.getFullYear()
  ) {
    return 'El período debe pertenecer al mismo mes.';
  }

  // 3️⃣ no fechas futuras
  if (ini > hoy || fin > hoy) {
    return 'No se pueden cargar fechas posteriores a hoy.';
  }

  // 4️⃣ no más de 3 meses atrás
  const limite = new Date(hoy.getFullYear(), hoy.getMonth() - 3, 1);
  if (ini < limite) {
    return 'El período no puede ser anterior a 3 meses.';
  }

  return ''; // OK
}


  function showSueldoError(msg){
    const box = qs('kolrrhh-sueldo-error');
    if (!box) return;
    box.textContent = msg || 'Revisá los datos.';
    box.style.display = 'block';
  }

  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>'\"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#39;'}[c] || c));
  }

  function clearSueldoError(){
    const box = qs('kolrrhh-sueldo-error');
    if (!box) return;
    box.textContent = '';
    box.style.display = 'none';
  }

  function safeJsonParse(str) {
    try { return JSON.parse(str); } catch (e) { return null; }
  }

  function onlyDigits(s){ return String(s ?? '').replace(/\D+/g,''); }

  function dmyToISO(dmy){
    // "07/04/1984" -> "1984-04-07"
    const m = String(dmy||'').match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if(!m) return '';
    return `${m[3]}-${m[2]}-${m[1]}`;
  }

  function isoToDMY(iso){
    // "1984-04-07" -> "07/04/1984"
    const m = String(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if(!m) return '';
    return `${m[3]}/${m[2]}/${m[1]}`;
  }

  function normalizePhoneAR(raw){
    // deja dígitos y formatea simple:
    // +54 342 508-4132 (si tiene 10/11 dígitos locales, intenta agrupar)
    const d = onlyDigits(raw);

    // aceptar: 10 u 11 dígitos (sin 54) o 12/13 con 54/549
    let local = d;
    if(local.startsWith('549')) local = local.slice(3);
    else if(local.startsWith('54')) local = local.slice(2);

    // si empieza con 0, sacarlo (muchos lo guardan así)
    if(local.startsWith('0')) local = local.slice(1);

    // formato simple:
    // 10 dígitos: AAA NNN NNNN
    // 11 dígitos: AAAA NNN NNNN
    if(local.length === 10){
      return `+54 ${local.slice(0,3)} ${local.slice(3,6)}-${local.slice(6)}`;
    }
    if(local.length === 11){
      return `+54 ${local.slice(0,4)} ${local.slice(4,7)}-${local.slice(7)}`;
    }
    return raw;
  }

  function isValidPhoneAR(raw){
    const d = onlyDigits(raw);
    let local = d;
    if(local.startsWith('549')) local = local.slice(3);
    else if(local.startsWith('54')) local = local.slice(2);
    if(local.startsWith('0')) local = local.slice(1);
    return (local.length === 10 || local.length === 11);
  }

  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function closeInfoPopovers(exceptId){
    document.querySelectorAll('.kolrrhh-popover.is-open').forEach(pop => {
      if (exceptId && pop.id === exceptId) return;
      pop.classList.remove('is-open');
      pop.setAttribute('aria-hidden', 'true');
      const trigger = document.querySelector(`.kolrrhh-info-btn[aria-controls="${pop.id}"]`);
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
  }

  function toIntLegajo(v) {
    const n = String(v ?? '').replace(/\D+/g, '');
    return n ? parseInt(n, 10) : 0;
  }

  function formatLegajo4(n) {
    const x = parseInt(n || 0, 10);
    return String(isNaN(x) ? 0 : x).padStart(4, '0');
  }

  // Lee el máximo legajo de los items renderizados (fallback para "add")
  function getMaxLegajoFromDom() {
    const items = document.querySelectorAll('.kolrrhh-item[data-emp]');
    let max = 0;
    items.forEach(btn => {
      const emp = safeJsonParse(btn.getAttribute('data-emp') || '');
      const n = toIntLegajo(emp?.legajo);
      if (n > max) max = n;
    });
    return max;
  }

  // Normaliza por si tus keys vienen con nombres distintos
  function normalizeEmp(raw) {
    const e = raw || {};
    return {
      id: e.id ?? e.ID ?? '',
      nombre: e.nombre ?? e.name ?? e.Nombre ?? '',
      legajo: e.legajo ?? e.leg ?? e.Legajo ?? '',
      estado: e.estado ?? e.Estado ?? 'ACTIVO',

      telefono: e.telefono ?? e.Telefono ?? e.phone ?? '',
      dni: e.dni ?? e.DNI ?? '',
      cuil: e.cuil ?? e.CUIL ?? '',
      obra_social: e.obra_social ?? e.obraSocial ?? e['obra social'] ?? e.obra ?? '',
      direccion: e.direccion ?? e.Direccion ?? '',
      ciudad: e.ciudad ?? e.Ciudad ?? '',
      fecha_nacimiento: e.fecha_nacimiento ?? e.fechaNacimiento ?? e.nacimiento ?? e.Nacimiento ?? '',
      ultima_fecha_ingreso: e.ultima_fecha_ingreso ?? e.ultimaFechaIngreso ?? e.ultimo_ingreso ?? e.ultimoIngreso ?? '',
      categoria: e.categoria ?? e.Categoria ?? '',
      clover_employee_id: e.clover_employee_id ?? e.cloverEmployeeId ?? '',

      // suele venir como string largo (22 dígitos)
      cbu: e.cbu ?? e.CBU ?? '',

    };
  }

  function renderDetail(empRaw) {
    const el = qs('kolrrhh-detail');
    if (!el) return;

    const emp = normalizeEmp(empRaw);

    // Guardar Clover ID actual (para fichaje)
    __CURRENT_CLOVER_ID__ = String(emp?.clover_employee_id || '').trim();
    __CURRENT_ULTIMO_INGRESO__ = String(emp?.ultima_fecha_ingreso || '').trim();

    if (!emp || (!emp.id && !emp.nombre && !emp.legajo)) {
      el.innerHTML = '<div style="padding:14px;opacity:.7">Seleccioná un empleado</div>';
      return;
    }

    const nombre = String(emp.nombre ?? '').trim() || '—';
    const legajo = formatLegajo4(toIntLegajo(emp.legajo));
    const estado = String(emp.estado ?? '').trim() || '—';
    const estadoClass = (estado.toUpperCase() === 'ACTIVO') ? 'is-ok' : 'is-off';

    const field = (k, v) => `
      <div class="kolrrhh-field">
        <div class="kolrrhh-k">${escHtml(k)}</div>
        <div class="kolrrhh-v">${(v ?? '') === '' ? '—' : escHtml(v)}</div>
      </div>
    `;

    el.innerHTML = `
      <div class="kolrrhh-dh">
        <div>
          <div class="kolrrhh-name">${escHtml(nombre)}</div>
          <div class="kolrrhh-sub">Legajo ${escHtml(legajo)}</div>
        </div>
        <div class="kolrrhh-status ${estadoClass}">${escHtml(estado)}</div>
      </div>

      <div class="kolrrhh-grid">
        ${field('Teléfono', emp.telefono)}
        ${field('DNI', emp.dni)}
        ${field('CUIL', emp.cuil)}
        ${field('Obra social', emp.obra_social)}
        ${field('Dirección', emp.direccion)}
        ${field('Ciudad', emp.ciudad)}
        ${field('Nacimiento', emp.fecha_nacimiento)}
        ${field('Clover ID', emp.clover_employee_id || '')}
        ${field('Último ingreso', emp.ultima_fecha_ingreso)}
        ${field('CBU', emp.cbu)}
      </div>
    `;

    if (__FICHAJE_INIT__) {
      refreshFichajeMerchants();
      const hostF = document.getElementById('kolrrhh-fichaje-result');
      if (hostF) hostF.innerHTML = '<div class="kolrrhh-muted">Seleccioná mes y comercio, y presioná “Ver”.</div>';
    }
  }

  async function loadSueldoItemsForLegajo(legajoNum){
    const host = qs('kolrrhh-sueldo-items');
    __CURRENT_LEGAJO__ = legajoNum;

    // Carga desempeño al seleccionar empleado
    loadDesempenoForLegajo(legajoNum);

    if (host) host.textContent = 'Cargando items sueldo...';

    if (typeof KOL_RRHH === 'undefined' || !KOL_RRHH.ajaxurl) {
      if (host) host.textContent = 'Falta configuración AJAX (KOL_RRHH).';
      return;
    }

    try {
      const body = new URLSearchParams();
      body.set('action', 'kol_rrhh_get_sueldo_items');
      body.set('nonce', KOL_RRHH.nonce);
      body.set('legajo', String(legajoNum));

      const res = await fetch(KOL_RRHH.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      });

      const json = await res.json();
      if (!json || !json.success) {
        if (host) host.textContent = (json?.data?.message || 'Error al cargar items sueldo');
        return;
      }

      __LAST_SUELDO_ROWS__ = (json.data.rows || []);
      renderSueldoItemsStyled(__LAST_SUELDO_ROWS__, legajoNum);
    } catch (err) {
      console.error(err);
      if (host) host.textContent = 'Error de red/servidor al cargar.';
    }
  }

  // =====================
  // FICHAJE (Clover Shifts)
  // =====================
  let __FICHAJE_INIT__ = false;
  let __FICHAJE_LAST_MONTH__ = '';

  function getFichajeMonthOptions(){
    // Diciembre 2025 + todos los meses 2026
    const opts = [];
    opts.push({ value: '2025-12', label: 'Diciembre 2025' });

    const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    for (let m = 1; m <= 12; m++){
      const mm = String(m).padStart(2,'0');
      opts.push({ value: `2026-${mm}`, label: `${meses[m-1]} 2026` });
    }
    return opts;
  }

  function parseCloverPairs(raw){
    const out = [];
    const s = String(raw || '').trim();
    if (!s) return out;
    const pairs = s.split(',').map(x => String(x).trim()).filter(Boolean);
    for (const p of pairs){
      const parts = p.split(';').map(x => String(x).trim());
      if (parts.length !== 2) continue;
      const merchant = parts[0];
      const employee = parts[1];
      if (!merchant || !employee) continue;
      out.push({ merchant, employee, pair: `${merchant};${employee}` });
    }
    return out;
  }

  function refreshFichajeMerchants(){
    const sel = document.getElementById('kolrrhh-fichaje-merchant');
    const wrap = document.querySelector('.kolrrhh-fichaje-merchant-wrap');
    if (!sel || !wrap) return;

    // Mapa MerchantID -> Nombre (inyectado desde PHP via wp_localize_script)
    const merchantMap = (window.KOL_RRHH && typeof KOL_RRHH.merchant_map === 'object' && KOL_RRHH.merchant_map)
      ? KOL_RRHH.merchant_map
      : {};

    __CURRENT_CLOVER_PAIRS__ = parseCloverPairs(__CURRENT_CLOVER_ID__);

    // Si no hay pares, dejamos vacío y mostramos el select igual (para que se note el problema)
    sel.innerHTML = '';
    if (__CURRENT_CLOVER_PAIRS__.length === 0){
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '— Sin Clover ID —';
      sel.appendChild(opt);
      wrap.style.display = '';
      return;
    }

    // Si hay 1 solo, ocultamos el selector y lo seteamos igual
    if (__CURRENT_CLOVER_PAIRS__.length === 1){
      const only = __CURRENT_CLOVER_PAIRS__[0];
      const opt = document.createElement('option');
      opt.value = only.merchant;
      opt.textContent = (merchantMap[only.merchant] || only.merchant);
      sel.appendChild(opt);
      sel.value = only.merchant;
      wrap.style.display = 'none';
      return;
    }

    // Hay varios: mostramos select
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = 'Seleccioná un comercio…';
    sel.appendChild(opt0);

    for (const item of __CURRENT_CLOVER_PAIRS__){
      const opt = document.createElement('option');
      opt.value = item.merchant;
      opt.textContent = (merchantMap[item.merchant] || item.merchant);
      sel.appendChild(opt);
    }
    wrap.style.display = '';
    sel.value = '';
  }


  function initFichajeUI(){
    if (__FICHAJE_INIT__) return;

    const sel = document.getElementById('kolrrhh-fichaje-month');
    const selMerchant = document.getElementById('kolrrhh-fichaje-merchant');
    const btn = document.getElementById('kolrrhh-fichaje-load');
    const host = document.getElementById('kolrrhh-fichaje-result');

    if (!sel || !selMerchant || !btn || !host) return;

    // Cargar opciones
    const options = getFichajeMonthOptions();
    sel.innerHTML = '';
    options.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.value;
      opt.textContent = o.label;
      sel.appendChild(opt);
    });

    refreshFichajeMerchants();

    // Default: mes actual si está en la lista; si no, primero.
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth()+1).padStart(2,'0');
    const current = `${y}-${m}`;
    const hasCurrent = options.some(o => o.value === current);
    sel.value = hasCurrent ? current : options[0].value;

    btn.addEventListener('click', function(){
      loadFichajeForMonth(sel.value);
    });

    __FICHAJE_INIT__ = true;
  }

  function loadFichajeForMonth(month){
    const host = document.getElementById('kolrrhh-fichaje-result');
    if (!host) return;

    const m = String(month || '').trim();
    if (!m) {
      host.innerHTML = '<div class="kolrrhh-alert kolrrhh-alert-error">Seleccioná un mes válido.</div>';
      return;
    }

    host.innerHTML = '<div class="kolrrhh-muted">Cargando fichaje…</div>';

    const leg = Number(__CURRENT_LEGAJO__ || 0);
    if (!leg) {
      host.innerHTML = '<div class="kolrrhh-alert kolrrhh-alert-error">Seleccioná un empleado primero.</div>';
      return;
    }
    if (!__CURRENT_CLOVER_ID__) {
      host.innerHTML = '<div class="kolrrhh-alert kolrrhh-alert-error">Este empleado no tiene Clover ID cargado. Editalo y completá Clover ID con el formato MerchantID;EmployeeID.</div>';
      return;
    }

    // Merchant seleccionado (si hay varios comercios)
    const pairs = parseCloverPairs(__CURRENT_CLOVER_ID__);
    const selMerchant = document.getElementById('kolrrhh-fichaje-merchant');
    let merchantId = selMerchant ? String(selMerchant.value || '').trim() : '';

    if (pairs.length > 1 && !merchantId){
      host.innerHTML = '<div class="kolrrhh-alert kolrrhh-alert-error">Este empleado tiene más de un comercio. Seleccioná el Comercio para continuar.</div>';
      return;
    }
    if (pairs.length === 1){
      merchantId = pairs[0].merchant;
    }

    const fd = new FormData();
    fd.append('action', 'kol_rrhh_get_fichaje_html');
    fd.append('nonce', (window.KOL_RRHH && KOL_RRHH.nonce) ? KOL_RRHH.nonce : '');
    fd.append('month', m);
    fd.append('legajo', String(leg));

    
    fd.append('merchant_id', merchantId);
fetch((window.KOL_RRHH && KOL_RRHH.ajaxurl) ? KOL_RRHH.ajaxurl : '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    })
    .then(r => r.json())
    .then(json => {
      if (!json || json.success !== true) {
        const msg = (json && json.data && json.data.message) ? json.data.message : 'Error cargando fichaje.';
        const reconnectUrl = (json && json.data && json.data.reconnect_url) ? String(json.data.reconnect_url) : '';

        // Debug extra (viene desde PHP cuando falla el request a Clover)
        const http = (json && json.data && typeof json.data.http !== 'undefined') ? String(json.data.http) : '';
        const resp = (json && json.data && typeof json.data.resp !== 'undefined') ? String(json.data.resp) : '';
        const curlErr = (json && json.data && typeof json.data.curl_err !== 'undefined') ? String(json.data.curl_err) : '';

        let extra = '<div class="kolrrhh-muted" style="margin-top:8px; font-size:12px;">'
          + 'Si querés, en el próximo paso te armo el botón dentro del plugin “Reconectar merchant seleccionado” para no copiar/pegar URLs.'
          + '</div>';

        if (reconnectUrl) {
          extra = '<div style="margin-top:12px;">'
            + '<a class="kolrrhh-btn kolrrhh-btn-secondary" target="_blank" rel="noopener" href="'+escapeHtml(reconnectUrl)+'">'
            + 'Reconectar merchant seleccionado'
            + '</a>'
            + extra
            + '</div>';
        }

        let dbg = '';
        if (http || resp || curlErr) {
          dbg += '<details style="margin-top:10px;"><summary style="cursor:pointer;">Ver detalle técnico</summary>';
          if (http) dbg += '<div style="margin-top:6px; font-size:12px;"><b>HTTP:</b> ' + escapeHtml(http) + '</div>';
          if (curlErr) dbg += '<div style="margin-top:6px; font-size:12px;"><b>CURL:</b> ' + escapeHtml(curlErr) + '</div>';
          if (resp) dbg += '<pre style="white-space:pre-wrap; word-break:break-word; margin-top:8px; padding:10px; border-radius:10px; background:rgba(0,0,0,0.05); font-size:12px;">' + escapeHtml(resp).slice(0, 5000) + '</pre>';
          dbg += '</details>';
        }

        host.innerHTML = '<div class="kolrrhh-alert kolrrhh-alert-error">'+escapeHtml(msg)+ extra + dbg +'</div>';
        return;
      }
      host.innerHTML = (json.data && json.data.html) ? json.data.html : '<div class="kolrrhh-muted">Sin contenido.</div>';
      __FICHAJE_LAST_MONTH__ = m;
    })
    .catch(err => {
      host.innerHTML = '<div class="kolrrhh-alert kolrrhh-alert-error">Error de red: '+escapeHtml(String(err))+'</div>';
    });
  }

async function loadDesempenoForLegajo(legajoNum){
    const host = document.getElementById('kolrrhh-desempeno-items');
    if (host) host.textContent = 'Cargando desempeño...';

    if (typeof KOL_RRHH === 'undefined' || !KOL_RRHH.ajaxurl) {
      if (host) host.textContent = 'Falta configuración AJAX (KOL_RRHH).';
      return;
    }

    try{
      const body = new URLSearchParams();
      body.set('action', 'kol_rrhh_get_desempeno_items');
      body.set('nonce', KOL_RRHH.nonce);
      body.set('legajo', String(legajoNum));

      const res = await fetch(KOL_RRHH.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      });

      const json = await res.json();
      if (!json || !json.success) {
        if (host) host.textContent = (json?.data?.message || 'Error al cargar desempeño');
        return;
      }

      const rows = (json.data.rows || []);
      __CURRENT_DESEMPENO_ROWS__ = rows;
      renderDesempenoTable(rows);
      refreshPresentismoDesempeno();
    }catch(err){
      console.error(err);
      if (host) host.textContent = 'Error de red/servidor al cargar.';
    }
  }

  function formatMesLabel(mes){
    // soporta "2025-12", "2025-12-01", "12/2025"
    const s = String(mes || '').trim();
    const months = ['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC'];

    let m = s.match(/^(\d{4})-(\d{2})(?:-\d{2})?$/);
    if (m) {
      const yy = m[1], mm = parseInt(m[2],10);
      if (!yy || yy === '0000' || !(mm >= 1 && mm <= 12)) return '—';
      return `${months[mm-1] || m[2]} ${yy}`;
    }

    m = s.match(/^(\d{2})\/(\d{4})$/);
    if (m) {
      const mm = parseInt(m[1],10), yy = m[2];
      return `${months[mm-1] || m[1]} ${yy}`;
    }

    return s || '—';
  }

  function parseInasistencias(val){
    if (val === null || val === undefined || val === '') return [];
    if (Array.isArray(val)) return val;

    const s = String(val).trim();
    if (!s) return [];

    // intenta JSON ["2025-12-05","2025-12-15"] o ["05/12/2025",...]
    if ((s.startsWith('[') && s.endsWith(']')) || (s.startsWith('{') && s.endsWith('}'))) {
      try{
        const j = JSON.parse(s);
        if (Array.isArray(j)) return j;
      }catch(e){}
    }

    // fallback: "05/12/2025,15/12/2025"
    return s.split(',').map(x => x.trim()).filter(Boolean);
  }

  
function renderDesempenoTable(rows){
  const host = document.getElementById('kolrrhh-desempeno-items');
  if (!host) return;

  const legajoNum = Number(__CURRENT_LEGAJO__ || 0);

  const head = `
    <div class="kolrrhh-sueldo-head">
      <div class="kolrrhh-sueldo-head-left">
        <div class="kolrrhh-sueldo-head-sub">Legajo: <strong>${legajoNum || '—'}</strong></div>
      </div>
      <div class="kolrrhh-sueldo-head-right">
        <button type="button" class="kolrrhh-btn kolrrhh-btn-secondary" id="kolrrhh-desempeno-add">+ Agregar desempeño</button>
      </div>
    </div>
  `;

  if (!rows || rows.length === 0){
    host.innerHTML = head + `<div class="kolrrhh-muted">Sin datos de desempeño para este legajo.</div>`;
    return;
  }

  const trs = rows.map(r => {
    const mes = formatMesLabel(r.mes);
    const des = (r.desempeno === null || r.desempeno === undefined || r.desempeno === '')
      ? '—'
      : `${String(r.desempeno).replace('.', ',')}%`;

    const inas = parseInasistencias(r.inasistencias);
    const inasHtml = (inas.length
      ? `<div class="kolrrhh-badgewrap">${inas.map(d => `<span class="kolrrhh-datebadge">${escapeHtml(String(d))}</span>`).join('')}</div>`
      : '<span class="kolrrhh-muted">—</span>'
    );

    return `
      <tr>
        <td>${escapeHtml(mes)}</td>
        <td style="white-space:nowrap;"><strong>${escapeHtml(des)}</strong></td>
        <td>${inasHtml}</td>
        <td style="white-space:nowrap;">
          <button type="button" class="kolrrhh-btn kolrrhh-btn-danger kolrrhh-btn-xs" data-desempeno-del="1" data-id="${r.id}">Eliminar</button>
        </td>
      </tr>
    `;
  }).join('');

  host.innerHTML = head + `
    <div class="kolrrhh-tablewrap">
      <table class="kolrrhh-table">
        <thead>
          <tr>
            <th>Mes</th>
            <th>Desempeño</th>
            <th>Inasistencias</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          ${trs}
        </tbody>
      </table>
    </div>
  `;
}


// ===============================
// DESEMPEÑO: alta (sin editar UI) + eliminar
// ===============================
let __DESEMPENO_FECHAS__ = [];

function showDesempenoError(msg){
  const el = document.getElementById('kolrrhh-desempeno-error');
  if (!el) return;
  el.textContent = msg || 'Error';
  el.style.display = 'block';
}
function clearDesempenoError(){
  const el = document.getElementById('kolrrhh-desempeno-error');
  if (!el) return;
  el.textContent = '';
  el.style.display = 'none';
}

function renderDesempenoFechasList(){
  const host = document.getElementById('kolrrhh-desempeno-fechas-list');
  if (!host) return;

  if (!__DESEMPENO_FECHAS__ || __DESEMPENO_FECHAS__.length === 0){
    host.innerHTML = `<div class="kolrrhh-muted">— Sin fechas —</div>`;
    return;
  }

  host.innerHTML = __DESEMPENO_FECHAS__.map(d => {
    const safe = escapeHtml(String(d));
    return `<span class="kolrrhh-chip"><span class="kolrrhh-chip-txt">${safe}</span><button type="button" class="kolrrhh-chip-x" data-chip-remove="1" data-val="${safe}" aria-label="Quitar">×</button></span>`;
  }).join(' ');
}

function buildMesOptions(selectEl){
  if (!selectEl) return;
  const now = new Date();
  const months = ['01','02','03','04','05','06','07','08','09','10','11','12'];
  let html = '';

  // 18 meses hacia atrás + 6 hacia adelante
  for (let offset = -18; offset <= 6; offset++){
    const d = new Date(now.getFullYear(), now.getMonth() + offset, 1);
    const yy = d.getFullYear();
    const mm = months[d.getMonth()];
    // Guardamos el mes como primer día (YYYY-MM-01) para que funcione bien si la columna `mes` es DATE.
    const value = `${yy}-${mm}-01`;
    html += `<option value="${value}">${escapeHtml(formatMesLabel(value))}</option>`;
  }
  selectEl.innerHTML = html;
  // default: mes actual
  const cur = `${now.getFullYear()}-${months[now.getMonth()]}-01`;
  selectEl.value = cur;
}

function openDesempenoModal(legajo){
  const modal = document.getElementById('kolrrhh-desempeno-modal');
  if (!modal) return;

  clearDesempenoError();

  document.getElementById('kolrrhh-desempeno-legajo').value = String(legajo || '');
  const sel = document.getElementById('kolrrhh-desempeno-mes');
  buildMesOptions(sel);

  document.getElementById('kolrrhh-desempeno-porcentaje').value = '';
  document.getElementById('kolrrhh-desempeno-fecha').value = '';

  __DESEMPENO_FECHAS__ = [];
  renderDesempenoFechasList();

  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden','false');

  setTimeout(() => {
    const f = document.getElementById('kolrrhh-desempeno-porcentaje');
    if (f) f.focus();
  }, 0);
}

function closeDesempenoModal(){
  const modal = document.getElementById('kolrrhh-desempeno-modal');
  if (!modal) return;

  document.getElementById('kolrrhh-desempeno-legajo').value = '';
  document.getElementById('kolrrhh-desempeno-porcentaje').value = '';
  document.getElementById('kolrrhh-desempeno-fecha').value = '';
  __DESEMPENO_FECHAS__ = [];
  renderDesempenoFechasList();
  clearDesempenoError();

  modal.classList.remove('is-open');
  modal.setAttribute('aria-hidden','true');
}

// delegación clicks: abrir modal / agregar fecha / quitar fecha / eliminar fila
document.addEventListener('click', function(ev){
  // Abrir modal
  const add = ev.target.closest('#kolrrhh-desempeno-add');
  if (add) {
    ev.preventDefault();
    const leg = Number(__CURRENT_LEGAJO__ || 0);
    if (!leg) return;
    openDesempenoModal(leg);
    return;
  }

  // Agregar fecha
  const addFecha = ev.target.closest('#kolrrhh-desempeno-add-fecha');
  if (addFecha) {
    ev.preventDefault();
    clearDesempenoError();
    const d = (document.getElementById('kolrrhh-desempeno-fecha')?.value || '').trim();
    if (!d) { showDesempenoError('Elegí una fecha para agregar.'); return; }

    // Normalizamos como YYYY-MM-DD (ya viene así del input date)
    if (!__DESEMPENO_FECHAS__.includes(d)) __DESEMPENO_FECHAS__.push(d);
    __DESEMPENO_FECHAS__.sort();
    renderDesempenoFechasList();
    return;
  }

  // Quitar fecha (chip)
  const chipX = ev.target.closest('[data-chip-remove="1"]');
  if (chipX) {
    ev.preventDefault();
    const val = chipX.getAttribute('data-val') || '';
    __DESEMPENO_FECHAS__ = (__DESEMPENO_FECHAS__ || []).filter(x => String(x) !== String(val));
    renderDesempenoFechasList();
    return;
  }

  // Eliminar fila desempeño
  const del = ev.target.closest('[data-desempeno-del="1"]');
  if (del) {
    ev.preventDefault();
    const id = Number(del.getAttribute('data-id') || 0);
    if (!id) return;
    if (!confirm('¿Eliminar este registro de desempeño?')) return;

    const leg = Number(__CURRENT_LEGAJO__ || 0);
    if (!leg) return;

    const payload = new URLSearchParams();
    payload.set('action', 'kol_rrhh_delete_desempeno_item');
    payload.set('nonce', KOL_RRHH.nonce);
    payload.set('id', String(id));

    fetch(KOL_RRHH.ajaxurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: payload.toString()
    })
    .then(r => r.json())
    .then(json => {
      if (!json || !json.success) {
        alert(json?.data?.message || 'No se pudo eliminar.');
        return;
      }
      loadDesempenoForLegajo(leg);
    })
    .catch(() => alert('Error de red/servidor al eliminar.'));
    return;
  }
});

// Guardar desempeño (AJAX)
const desempenoSaveBtn = document.getElementById('kolrrhh-desempeno-save');
if (desempenoSaveBtn) {
  desempenoSaveBtn.addEventListener('click', async function(ev){
    ev.preventDefault();
    clearDesempenoError();

    const legajo = Number(document.getElementById('kolrrhh-desempeno-legajo')?.value || 0);
    const mes = (document.getElementById('kolrrhh-desempeno-mes')?.value || '').trim();
    const porcentajeRaw = (document.getElementById('kolrrhh-desempeno-porcentaje')?.value || '').trim();

    if (!legajo) { showDesempenoError('Falta legajo. Volvé a seleccionar el empleado.'); return; }
    if (!mes) { showDesempenoError('Seleccioná un mes.'); return; }
    if (porcentajeRaw === '') { showDesempenoError('Completá el porcentaje de desempeño.'); return; }

    const pct = Number(String(porcentajeRaw).replace(',', '.'));
    if (Number.isNaN(pct) || pct < 0 || pct > 100) { showDesempenoError('El porcentaje debe estar entre 0 y 100.'); return; }

    const payload = new URLSearchParams();
    payload.set('action', 'kol_rrhh_save_desempeno_item');
    payload.set('nonce', KOL_RRHH.nonce);
    payload.set('legajo', String(legajo));
    payload.set('mes', mes);
    payload.set('desempeno', String(pct));
    payload.set('inasistencias', JSON.stringify(__DESEMPENO_FECHAS__ || []));

    try{
      const res = await fetch(KOL_RRHH.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
      });
      const json = await res.json();
      if (!json || !json.success) {
        showDesempenoError(json?.data?.message || 'No se pudo guardar.');
        return;
      }

      closeDesempenoModal();
      __CURRENT_LEGAJO__ = legajo;
      loadDesempenoForLegajo(legajo);
    }catch(err){
      console.error(err);
      showDesempenoError('Error de red/servidor al guardar.');
    }
  });
}



  function dateBadgeParts(iso){
    // iso "YYYY-MM-DD" -> { day:"11", mon:"NOV", year:"2025" }
    const m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if(!m) return { day:'—', mon:'', year:'' };
    const months = ['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC'];
    const mon = months[parseInt(m[2],10)-1] || '';
    return { year: m[1], mon, day: String(parseInt(m[3],10)) };
  }

  function formatDateDMY(iso){
    // "2024-11-01" -> "01/11/2024"
    const m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if(!m) return iso || '—';
    return `${m[3]}/${m[2]}/${m[1]}`;
  }

  function parseMoneyAR(str){
    if (str === null || str === undefined) return 0;
    let s = String(str).trim();
    if (!s) return 0;

    // sacamos $ y espacios
    s = s.replace(/\$/g,'').replace(/\s+/g,'');

    // miles/decimales: si hay coma, asumimos coma decimal
    if (s.includes(',')) {
      s = s.replace(/\./g,'');   // miles
      s = s.replace(/,/g,'.');    // decimal
    } else {
      // si no hay coma, dejamos el punto como decimal (si lo hubiese)
      s = s.replace(/\.(?=\d{3}(\D|$))/g,''); // elimina puntos miles 1.234
    }

    s = s.replace(/[^0-9.-]/g,'');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }

  function moneyAR(n){
    const v = (typeof n === 'string') ? parseMoneyAR(n) : (Number(n) || 0);
    const fmt = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    return '$' + fmt.format(v);
  }

  function attachMoneyInput(el){
    if (!el) return;

    const format = () => {
      const n = parseMoneyAR(el.value);
      el.value = n === 0 ? '' : moneyAR(n);
    };

    const toRaw = () => {
      const n = parseMoneyAR(el.value);
      const raw = String(n).replace('.', ',');
      el.value = n === 0 ? '' : raw;
    };

    el.addEventListener('focus', toRaw);
    el.addEventListener('blur', format);

    el.addEventListener('input', () => {
      let s = el.value;
      s = s.replace(/[^0-9,.-]/g,'');
      s = s.replace(/(?!^)-/g,''); // solo un -
      // normalizamos decimal a coma y dejamos una sola
      const firstComma = s.indexOf(',');
      const firstDot = s.indexOf('.');
      const decPos = (firstComma >= 0) ? firstComma : firstDot;
      if (decPos >= 0) {
        const intPart = s.slice(0, decPos).replace(/[,.]/g,'');
        const decPart = s.slice(decPos+1).replace(/[,.]/g,'');
        s = intPart + ',' + decPart;
      } else {
        s = s.replace(/[,.]/g,'');
      }
      el.value = s;
    });
  }

  function openSueldoModal(row, legajoNum){
    const modal = qs('kolrrhh-sueldo-modal');
    if(!modal) return;

    // set title
    const title = qs('kolrrhh-sueldo-title');
    const isEdit = !!(row && row.id);
    if (title) title.textContent = isEdit ? 'Editar item de sueldo' : 'Agregar item de sueldo';

    // hidden
    const idEl = qs('kolrrhh-sueldo-id');
    const legEl = qs('kolrrhh-sueldo-legajo');
    if (idEl) idEl.value = isEdit ? String(row.id) : '0';
    if (legEl) legEl.value = String(legajoNum || row?.legajo || '');

    // fields
    setVal('kolrrhh-sueldo-periodo-inicio', row?.periodo_inicio || '');
    setVal('kolrrhh-sueldo-periodo-fin', row?.periodo_fin || '');
    setVal('kolrrhh-sueldo-dias-trabajo', (row?.dias_de_trabajo ?? row?.diasTrab ?? '') );
    // máscara simple para Dias Trab.
    const diasEl = qs('kolrrhh-sueldo-dias-trabajo');
    if (diasEl && !diasEl.dataset.kolBind) {
      diasEl.dataset.kolBind = '1';
      diasEl.addEventListener('input', () => {
        let s = String(diasEl.value || '');
        s = s.replace(/[^0-9,\.]/g,'');
        // dejar solo un separador decimal
        const firstComma = s.indexOf(',');
        const firstDot = s.indexOf('.');
        const pos = (firstComma >= 0) ? firstComma : firstDot;
        if (pos >= 0) {
          const intPart = s.slice(0,pos).replace(/[^0-9]/g,'').slice(0,2);
          const decPart = s.slice(pos+1).replace(/[^0-9]/g,'').slice(0,2);
          s = intPart + ',' + decPart;
        } else {
          s = s.replace(/[^0-9]/g,'').slice(0,2);
        }
        diasEl.value = s;
      });
    }

    const rolSel = qs('kolrrhh-sueldo-rol');
    const areaSel = qs('kolrrhh-sueldo-area');
    const partSel = qs('kolrrhh-sueldo-participacion');
    const horasSel = qs('kolrrhh-sueldo-horas');

if (rolSel) {
  rolSel.innerHTML = `<option value="">Seleccionar rol</option>` +
    KOL_RRHH_ROLES.map(r =>
      `<option value="${r}">${r}</option>`
    ).join('');
  rolSel.value = row?.rol || '';
}

if (areaSel) {
  const locales = (KOL_RRHH.locales || []);
  const areas   = (KOL_RRHH.areas || []);

  const opciones = [
    ...locales.map(l => ({ value: l, label: l })),
    ...areas.map(a => ({ value: a, label: a }))
  ];

  areaSel.innerHTML =
    `<option value="">Seleccionar área / local</option>` +
    opciones.map(o =>
      `<option value="${o.value}">${o.label}</option>`
    ).join('');

  areaSel.value = row?.area || '';
}



if (horasSel) {
  const bandas = (KOL_RRHH.horas_bandas || []);
  horasSel.innerHTML =
    `<option value="">Seleccionar horas</option>` +
    bandas.map(b =>
      `<option value="${b.value}">${b.label}</option>`
    ).join('');
  horasSel.value = row?.horas ? String(row.horas) : '';
}



if (partSel) {
  partSel.innerHTML = buildParticipacionOptions();
  partSel.value = normalizeParticipacion(row?.participacion ?? '0');

   partSel.addEventListener('change', () => {
  const partRaw = getVal('kolrrhh-sueldo-participacion') || '0';
  const participacion = parseFloat(partRaw.replace(',', '.')) || 0;

  const areaSel = getVal('kolrrhh-sueldo-area');
  const factor = getComisionFactorByArea(areaSel);

  const comisionFinal =
    __CURRENT_COMISION__ * (participacion / 100) * factor;

  setText('kolrrhh-sueldo-comision', moneyAR(comisionFinal));
});

}

    setVal('kolrrhh-sueldo-jornada', row?.jornada || '');

    setVal('kolrrhh-sueldo-efectivo', row?.efectivo ?? '');
    setVal('kolrrhh-sueldo-transferencia', row?.transferencia ?? '');
    setVal('kolrrhh-sueldo-creditos', row?.creditos ?? '');
    setVal('kolrrhh-sueldo-bono', row?.bono ?? '');
    setVal('kolrrhh-sueldo-descuentos', row?.descuentos ?? '');
    setVal('kolrrhh-sueldo-liquidacion', row?.liquidacion ?? '');

    setVal('kolrrhh-sueldo-vac-tomadas', row?.vac_tomadas ?? '');
    setVal('kolrrhh-sueldo-feriados', row?.feriados ??'');
    setVal('kolrrhh-sueldo-vac-no-tomadas', row?.vac_no_tomadas ?? '');
    


    // money formatting (en blur ya se formatea)
    ['kolrrhh-sueldo-jornada','kolrrhh-sueldo-vac-tomadas','kolrrhh-sueldo-feriados','kolrrhh-sueldo-vac-no-tomadas',
      'kolrrhh-sueldo-efectivo','kolrrhh-sueldo-transferencia','kolrrhh-sueldo-creditos',
      'kolrrhh-sueldo-bono','kolrrhh-sueldo-descuentos','kolrrhh-sueldo-liquidacion']
      .forEach(id => {
        const el = qs(id);
        if (el) { attachMoneyInput(el); el.dispatchEvent(new Event('blur')); }
        calcularEfectivoAutomatico();
      });

    // reset labels calculados (UI)
    ['kolrrhh-sueldo-base','kolrrhh-sueldo-antig','kolrrhh-sueldo-comision','kolrrhh-sueldo-presentismo','kolrrhh-sueldo-desempeno','kolrrhh-sueldo-no-rem']
      .forEach(id => setText(id, '$0'));

    __CURRENT_DESEMPENO_ROWS__ = [];

    // ✅ Traer Base desde la tabla (según Rol + Horas)
    refreshBaseFromDB();
    refreshComisionFromDB();

    clearSueldoError();

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');

    // focus
    const focusId = isEdit ? 'kolrrhh-sueldo-efectivo' : 'kolrrhh-sueldo-periodo-inicio';
    setTimeout(() => { const f = qs(focusId); if (f) f.focus(); }, 0);
  }

  function validatePeriodo(inicio, fin){
  if (!inicio || !fin) return 'Completá fecha inicio y fin.';

  const dIni = new Date(inicio + 'T00:00:00');
  const dFin = new Date(fin + 'T00:00:00');
  const hoy  = new Date();
  hoy.setHours(0,0,0,0);

  // orden
  if (dIni > dFin) {
    return 'La fecha de inicio no puede ser mayor a la fecha de fin.';
  }

  // mismo mes y año
  if (
    dIni.getMonth() !== dFin.getMonth() ||
    dIni.getFullYear() !== dFin.getFullYear()
  ) {
    return 'El período debe pertenecer al mismo mes.';
  }

  // no fechas futuras
  if (dIni > hoy || dFin > hoy) {
    return 'No se pueden cargar fechas posteriores a hoy.';
  }

  // máximo 3 meses atrás
  const limite = new Date(hoy.getFullYear(), hoy.getMonth() - 3, 1);
  if (dIni < limite) {
    return 'El período no puede ser anterior a 3 meses.';
  }

  return '';
}


function parseISODateLocal(iso) {
  // Acepta "YYYY-MM-DD" (input type=date) o "DD/MM/YYYY" (formato guardado en WP)
  if (!iso) return null;
  let s = String(iso).trim();
  if (!s) return null;

  // Si viene DD/MM/YYYY lo convertimos a ISO
  if (/^\d{2}\/\d{2}\/\d{4}$/.test(s)) {
    s = dmyToISO(s); // función ya definida arriba
  }

  // Si no quedó en ISO, no podemos calcular
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;

  const d = new Date(s + 'T00:00:00');
  return isNaN(d.getTime()) ? null : d;
}

function yearsVencidos(ingresoISO, refISO) {
  const ini = parseISODateLocal(ingresoISO);
  const ref = parseISODateLocal(refISO) || new Date(); // fallback hoy
  if (!ini) return 0;

  let y = ref.getFullYear() - ini.getFullYear();

  // Si todavía no llegó el aniversario en el año de referencia, restar 1
  const refMonth = ref.getMonth(), refDay = ref.getDate();
  const iniMonth = ini.getMonth(), iniDay = ini.getDate();
  if (refMonth < iniMonth || (refMonth === iniMonth && refDay < iniDay)) {
    y--;
  }

  return Math.max(0, y);
}

function refreshAntigFromState(){
  const ingreso = String(__CURRENT_ULTIMO_INGRESO__ || '').trim();

  if (!ingreso || !__CURRENT_BASE__) {
    setText('kolrrhh-sueldo-antig', '$0');
    return;
  }

  // Tomamos como referencia la fecha FIN del período si existe; si no, hoy
  const refISO = getVal('kolrrhh-sueldo-periodo-fin') || '';

  const years = yearsVencidos(ingreso, refISO);
  const antig = Number(__CURRENT_BASE__ || 0) * 0.01 * years;

  setText(
    'kolrrhh-sueldo-antig',
    (typeof moneyAR === 'function') ? moneyAR(antig) : ('$' + antig.toFixed(2))
  );
}

async function refreshBaseFromDB(){
  const rol = getVal('kolrrhh-sueldo-rol');
  const horas = getVal('kolrrhh-sueldo-horas');

  // Si falta algo, dejamos $0
  if (!rol || !horas){
    __CURRENT_BASE__ = 0;
    setText('kolrrhh-sueldo-base', '$0');
    refreshAntigFromState();
    refreshPresentismoDesempeno();
    return;
  }

  try{
    const fd = new FormData();
    fd.append('action', 'kol_rrhh_get_base');
    fd.append('nonce', (window.KOL_RRHH && KOL_RRHH.nonce) ? KOL_RRHH.nonce : '');
    fd.append('rol', rol);
    fd.append('horas', horas);

    const res = await fetch(KOL_RRHH.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();

    if (!json || !json.success){
    __CURRENT_BASE__ = 0;
    setText('kolrrhh-sueldo-base', '$0');
    refreshAntigFromState();
    refreshPresentismoDesempeno();
    return;
  }

    const base = Number(json.data?.base || 0);

    __CURRENT_BASE__ = base;

    // Usá tu formateador existente si lo tenés.
    // En tu render usás moneyAR(...), así que lo reutilizo:
    setText('kolrrhh-sueldo-base', (typeof moneyAR === 'function') ? moneyAR(base) : ('$' + base));
    refreshAntigFromState();
    refreshPresentismoDesempeno();
  }catch(e){
    console.error(e);
    __CURRENT_BASE__ = 0;
    setText('kolrrhh-sueldo-base', '$0');
    refreshAntigFromState();
    refreshPresentismoDesempeno();
  }
}

function getComisionFactorByArea(area) {
  if (!area) return 0;

  // Normalizamos por las dudas
  const a = String(area).trim();

  // Depósito
  if (a === 'Dep') {
    return 0.009;
  }

  // Locales con 1%
  const locales01 = [
    'Local 15',
    'Local 34',
    'Local 55',
    'Lujan',
    'Osi',
    'Sh',
    'Sol',
    'Stoto',
    'Urb'
  ];
  if (locales01.includes(a)) {
    return 0.01;
  }

  // Áreas sin comisión
  const areasSinComision = [
    'Administracion',
    'Entrenamiento',
    'Logistica',
    'Redes'
  ];
  if (areasSinComision.includes(a)) {
    return 0;
  }

  // Default seguro
  return 0;
}


async function refreshComisionFromDB(){
  try{
    if (typeof KOL_RRHH === 'undefined' || !KOL_RRHH.ajaxurl) return;

    const area = getVal('kolrrhh-sueldo-area');
    const fin  = getVal('kolrrhh-sueldo-periodo-fin') || getVal('kolrrhh-sueldo-periodo-inicio');

    // Si no hay datos, dejamos 0.
    if (!area || !fin){
      __CURRENT_COMISION__ = 0;
      setText('kolrrhh-sueldo-comision', '$0');
      return;
    }

    // Solo aplica para "locales" (no para "áreas" tipo Logística, etc.)
    const locales = (KOL_RRHH.locales || []);
    const esLocal = locales.includes(area);
    if (!esLocal){
      __CURRENT_COMISION__ = 0;
      setText('kolrrhh-sueldo-comision', '$0');
      return;
    }

    const m = String(fin).match(/^(\d{4})-(\d{2})/);
    if (!m){
      __CURRENT_COMISION__ = 0;
      setText('kolrrhh-sueldo-comision', '$0');
      return;
    }

    const anio = Number(m[1]);
    const mes  = Number(m[2]);

    const body = new URLSearchParams();
    body.append('action', 'kol_rrhh_get_comision');
    body.append('nonce', AJAX_NONCE);
    body.append('area', area);
    body.append('anio', String(anio));
    body.append('mes', String(mes));

    const res = await fetch(AJAX_URL, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString() });
    const json = await res.json();

    const ventas = (json && json.success && json.data && json.data.ventas !== undefined)
      ? Number(json.data.ventas || 0)
      : 0;

   __CURRENT_COMISION__ = isFinite(ventas) ? ventas : 0;

// 👉 aplicar participación
const partRaw = getVal('kolrrhh-sueldo-participacion') || '0';
const participacion = parseFloat(partRaw.replace(',', '.')) || 0;

// participacion es % (0.0 a 10.0)
const areaSel = getVal('kolrrhh-sueldo-area');
const factor = getComisionFactorByArea(areaSel);

const comisionFinal =
  __CURRENT_COMISION__ * (participacion / 100) * factor;

// Mostrar
const elId = 'kolrrhh-sueldo-comision';
if (typeof moneyAR === 'function'){
  setText(elId, moneyAR(comisionFinal));
} else {
  setText(elId, '$' + String(comisionFinal));
}

  } catch(e){
    __CURRENT_COMISION__ = 0;
    setText('kolrrhh-sueldo-comision', '$0');
  }
}

async function fetchDesempenoRows(legajoNum){
  if (!legajoNum) return [];
  if (typeof KOL_RRHH === 'undefined' || !KOL_RRHH.ajaxurl) return [];

  const body = new URLSearchParams();
  body.set('action', 'kol_rrhh_get_desempeno_items');
  body.set('nonce', KOL_RRHH.nonce);
  body.set('legajo', String(legajoNum));

  const res = await fetch(KOL_RRHH.ajaxurl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: body.toString()
  });

  const json = await res.json();
  if (!json || !json.success || !json.data || !Array.isArray(json.data.rows)) return [];
  return json.data.rows;
}

async function refreshPresentismoDesempeno(){
  const legajo = Number(getVal('kolrrhh-sueldo-legajo') || __CURRENT_LEGAJO__ || 0);
  const mesISO = getPeriodoMesISO();

  if (!legajo || !mesISO || !__CURRENT_BASE__) {
    setText('kolrrhh-sueldo-presentismo', '$0');
    setText('kolrrhh-sueldo-desempeno', '$0');
    return;
  }

  try{
    if (!__CURRENT_DESEMPENO_ROWS__ || __CURRENT_DESEMPENO_ROWS__.length === 0) {
      __CURRENT_DESEMPENO_ROWS__ = await fetchDesempenoRows(legajo);
    }

    const row = (__CURRENT_DESEMPENO_ROWS__ || []).find(r => {
      const m = String(r.mes || '').match(/^(\d{4})-(\d{2})/);
      if (!m) return false;
      return `${m[1]}-${m[2]}` === mesISO;
    });

    if (!row) {
      setText('kolrrhh-sueldo-presentismo', '$0');
      setText('kolrrhh-sueldo-desempeno', '$0');
      return;
    }

    const inas = parseInasistencias(row.inasistencias);
    const presentismo = (inas.length === 0)
      ? (__CURRENT_BASE__ * PRESENTISMO_FACTOR)
      : 0;

    const desPct = Number(String(row.desempeno ?? '').replace(',', '.')) || 0;
    const desempeno = __CURRENT_BASE__ * (desPct / 100);

    setText('kolrrhh-sueldo-presentismo', moneyAR(presentismo));
    setText('kolrrhh-sueldo-desempeno', moneyAR(desempeno));
  }catch(e){
    console.error(e);
    setText('kolrrhh-sueldo-presentismo', '$0');
    setText('kolrrhh-sueldo-desempeno', '$0');
  }
}




  function closeSueldoModal(){
    const modal = qs('kolrrhh-sueldo-modal');
    if(!modal) return;

    // reset
    setVal('kolrrhh-sueldo-id', '0');
    setVal('kolrrhh-sueldo-legajo', '');
    setVal('kolrrhh-sueldo-area', '');


    ['kolrrhh-sueldo-periodo-inicio','kolrrhh-sueldo-periodo-fin','kolrrhh-sueldo-dias-trabajo','kolrrhh-sueldo-rol','kolrrhh-sueldo-participacion','kolrrhh-sueldo-jornada',
     'kolrrhh-sueldo-efectivo','kolrrhh-sueldo-transferencia','kolrrhh-sueldo-creditos','kolrrhh-sueldo-bono','kolrrhh-sueldo-descuentos','kolrrhh-sueldo-liquidacion',
     'kolrrhh-sueldo-vac-tomadas','kolrrhh-sueldo-feriados','kolrrhh-sueldo-vac-no-tomadas'
    ].forEach(id => setVal(id, ''));

    // reset labels calculados
    ['kolrrhh-sueldo-base','kolrrhh-sueldo-antig','kolrrhh-sueldo-comision','kolrrhh-sueldo-presentismo','kolrrhh-sueldo-desempeno','kolrrhh-sueldo-no-rem']
      .forEach(id => setText(id, '$0'));

    clearSueldoError();

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
  }

  function renderSueldoItemsStyled(rows, legajoNum){
    const host = qs('kolrrhh-sueldo-items');
    if (!host) return;

    const head = `
      <div class="kolrrhh-sueldo-head">
        <div class="kolrrhh-sueldo-head-left">
          <div class="kolrrhh-sueldo-head-title">Items de sueldo</div>
          <div class="kolrrhh-sueldo-head-sub">Legajo: <strong>${legajoNum || '—'}</strong></div>
        </div>
        <div class="kolrrhh-sueldo-head-right">
          <button type="button" class="kolrrhh-btn kolrrhh-btn-secondary" id="kolrrhh-sueldo-add">+ Agregar item</button>
        </div>
      </div>
    `;

    host.dataset.legajo = String(legajoNum || '');

    if (!rows || !rows.length) {
      host.innerHTML = head + `<div class="kolrrhh-muted">Este empleado no tiene items de sueldo cargados.</div>`;
      return;
    }

    host.innerHTML = head + rows.map(r => {
      const a = dateBadgeParts(r.periodo_inicio);
      const b = dateBadgeParts(r.periodo_fin);
      const rol = (r.rol || '—').toString().toUpperCase();
      const area = r.area ? r.area : '—';
      const part = formatParticipacionAR(r.participacion ?? '0');

      return `
        <div class="kolrrhh-sueldo-card" data-sueldo-id="${r.id}">
          <div class="kolrrhh-sueldo-card-headrow">
            <div class="kolrrhh-period-badge">
              <div class="kolrrhh-datebox">
                <div class="kolrrhh-datebox-mon">${escapeHtml(a.mon)}</div>
                <div class="kolrrhh-datebox-day">${escapeHtml(a.day)}</div>
                <div class="kolrrhh-datebox-year">${escapeHtml(a.year)}</div>
              </div>
              <div class="kolrrhh-datebox-arrow">→</div>
              <div class="kolrrhh-datebox">
                <div class="kolrrhh-datebox-mon">${escapeHtml(b.mon)}</div>
                <div class="kolrrhh-datebox-day">${escapeHtml(b.day)}</div>
                <div class="kolrrhh-datebox-year">${escapeHtml(b.year)}</div>
              </div>
                          <div class="kolrrhh-sueldo-days" style="margin-left:12px;white-space:nowrap;font-size:12px;opacity:.85;">Dias Trab.: <strong>${escapeHtml(String(r.dias_de_trabajo ?? ''))}</strong></div>
            </div>

        <div class="kolrrhh-sueldo-role">
  <div class="kolrrhh-sueldo-area">${escapeHtml(area)}</div>
  <div class="kolrrhh-sueldo-cargo">${escapeHtml(rol)} <span class="kolrrhh-sueldo-part">${escapeHtml(part)}</span></div>
</div>


            <div class="kolrrhh-sueldo-actions">
              <button type="button" class="kolrrhh-btn kolrrhh-btn-small" data-sueldo-edit="1" data-id="${r.id}">Editar</button>
              <button type="button" class="kolrrhh-btn kolrrhh-btn-secondary" data-sueldo-print="1" data-id="${r.id}">PDF</button>
            </div>
          </div>

          <!-- PAGO -->
          <div class="kolrrhh-sueldo-section">
            <div class="kolrrhh-sueldo-section-title">Pago</div>
            <table class="kolrrhh-sueldo-table">
              <thead>
                <tr>
                  <th>Efectivo</th>
                  <th>Transferencia</th>
                  <th>Créditos</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>${moneyAR(r.efectivo)}</td>
                  <td>${moneyAR(r.transferencia)}</td>
                  <td>${moneyAR(r.creditos)}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- DETALLES -->
          <div class="kolrrhh-sueldo-section">
            <div class="kolrrhh-sueldo-section-title">Detalles</div>
            <table class="kolrrhh-sueldo-table kolrrhh-sueldo-table-details">
              <thead>
                <tr>
                  <th>Jornada</th>
                  <th>Bono</th>
                  <th>Descuentos</th>
                  <th>Vac. Tomadas</th>
                  <th>Feriados</th>
                  <th>Liquidación</th>
                  <th>Vac. No tomadas</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>${escapeHtml(r.jornada)}</td>
                  <td>${moneyAR(r.bono)}</td>
                  <td>${moneyAR(r.descuentos)}</td>
                  <td>${moneyAR(r.vac_tomadas)}</td>
                  <td>${moneyAR(r.feriados)}</td>
                  <td>${moneyAR(r.liquidacion)}</td>
                  <td>${moneyAR(r.vac_no_tomadas)}</td>
                </tr>
              </tbody>
            </table>
          </div>

        </div>
      `;
    }).join('');
  }

  function openEmpModal(mode, empRaw) {
    const modal = qs('kolrrhh-modal');
    const title = qs('kolrrhh-modal-title');

    const idEl = qs('kolrrhh-modal-id');
    const legEl = qs('kolrrhh-modal-legajo');

    const fNombre = qs('kolrrhh-modal-nombre');
    const fTel = qs('kolrrhh-modal-telefono');
    const fDni = qs('kolrrhh-modal-dni');
    const fCuil = qs('kolrrhh-modal-cuil');
    const fOS = qs('kolrrhh-modal-obra_social');
    const fDir = qs('kolrrhh-modal-direccion');
    const fCiu = qs('kolrrhh-modal-ciudad');          // ahora debería ser <select>
    const fNac = qs('kolrrhh-modal-fecha_nacimiento');// ahora debería ser <input type="date">
    const fUlt = qs('kolrrhh-modal-ultima_fecha_ingreso'); // <input type="date">
    const fEstado = qs('kolrrhh-modal-estado');       // select
    const fClover = qs('kolrrhh-modal-clover_employee_id');
    const fCBU = qs('kolrrhh-modal-cbu');


    if (!modal || !title || !idEl || !legEl || !fNombre) return;

    const isEdit = mode === 'edit';
    const emp = normalizeEmp(empRaw);

    // Guardar Clover ID actual (para fichaje)
    __CURRENT_CLOVER_ID__ = String(emp?.clover_employee_id || '').trim();
    __CURRENT_ULTIMO_INGRESO__ = String(emp?.ultima_fecha_ingreso || '').trim();

    let legajoNum = 0;

    if (isEdit) {
      legajoNum = toIntLegajo(emp.legajo);
      idEl.value = emp.id ?? '';

      fNombre.value = (emp.nombre ?? '').slice(0, 100);
      if (fTel) fTel.value = emp.telefono ?? '';

      if (fDni) fDni.value = onlyDigits(emp.dni ?? '').slice(0, 8);
      if (fCuil) fCuil.value = onlyDigits(emp.cuil ?? '').slice(0, 11);

      if (fOS) fOS.value = (emp.obra_social ?? '').slice(0, 100);
      if (fDir) fDir.value = (emp.direccion ?? '').slice(0, 100);

      if (fCiu) fCiu.value = emp.ciudad ?? '';

      if (fNac) {
        const v = emp.fecha_nacimiento ?? '';
        // si viene DD/MM/AAAA lo pasamos a ISO para el date picker
        fNac.value = v.includes('/') ? dmyToISO(v) : v;
      }

      if (fUlt) {
        const v = emp.ultima_fecha_ingreso ?? '';
        // si viene DD/MM/AAAA lo pasamos a ISO para el date picker
        fUlt.value = v.includes('/') ? dmyToISO(v) : v;
      }

      if (fEstado) fEstado.value = (emp.estado ?? 'ACTIVO');
      if (fClover) fClover.value = emp.clover_employee_id ?? '';
      if (fCBU) fCBU.value = onlyDigits(emp.cbu ?? '').slice(0, 30);

    } else {
      legajoNum = getMaxLegajoFromDom() + 1;
      idEl.value = '';

      fNombre.value = '';
      if (fTel) fTel.value = '';
      if (fDni) fDni.value = '';
      if (fCuil) fCuil.value = '';
      if (fOS) fOS.value = '';
      if (fDir) fDir.value = '';
      if (fCiu) fCiu.value = '';
      if (fNac) fNac.value = '';
      if (fUlt) fUlt.value = '';
      if (fEstado) fEstado.value = 'ACTIVO';
      if (fClover) fClover.value = '';
      if (fCBU) fCBU.value = '';

    }

    const legStr = formatLegajo4(legajoNum);
    title.textContent = `Legajo ${legStr}`;
    legEl.value = legStr;

    modal.dataset.mode = mode;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    setTimeout(() => fNombre.focus(), 0);
  }

  function closeEmpModal() {
    const modal = qs('kolrrhh-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  // ========== EVENTS ==========

  document.addEventListener('click', function (e) {
    // Edit icon
    const edit = e.target.closest('.kolrrhh-edit-icon');
    if (edit) {
      e.preventDefault();
      e.stopPropagation();

      const item = edit.closest('.kolrrhh-item');
      const payload = item ? item.getAttribute('data-emp') : null;
      const emp = payload ? safeJsonParse(payload) : null;

      openEmpModal('edit', emp);
      return;
    }

    // Edit sueldo card (legacy)
    const sueldoEdit = e.target.closest('.kolrrhh-sueldo-edit');
    if (sueldoEdit) {
      e.preventDefault();
      const payload = sueldoEdit.getAttribute('data-sueldo');
      const row = payload ? safeJsonParse(payload) : null;
      openSueldoModal(row);
      return;
    }

    // Click on employee item => render detail
    const btn = e.target.closest('.kolrrhh-item');
    if (btn) {
      // ✅ Si estamos en la vista "Locales", al seleccionar un empleado volvemos
      // automáticamente a la vista normal (tabs + panes).
      if (__VIEW_MODE__ === 'locales') {
        __VIEW_MODE__ = 'employees';
        const tabs = document.querySelector('.kolrrhh-tabs');
        const panes = document.querySelector('.kolrrhh-tabpanes');
        if (tabs) tabs.classList.remove('kolrrhh-hidden');
        if (panes) panes.classList.remove('kolrrhh-hidden');
      }

      document.querySelectorAll('.kolrrhh-item.is-selected').forEach(x => x.classList.remove('is-selected'));
      btn.classList.add('is-selected');

      const payload = btn.getAttribute('data-emp');
      const emp = safeJsonParse(payload);
      renderDetail(emp);

      const legajoNum = toIntLegajo(emp?.legajo);
      if (legajoNum > 0) loadSueldoItemsForLegajo(legajoNum);

      return;
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    // === Tabs (Items Sueldo / Desempeño / Fichaje) ===
    document.addEventListener('click', function(ev){
      const tab = ev.target.closest('.kolrrhh-tab');
      if (!tab) return;
      ev.preventDefault();

      const key = tab.getAttribute('data-tab');
      if (!key) return;

      document.querySelectorAll('.kolrrhh-tab').forEach(b => b.classList.remove('is-active'));
      tab.classList.add('is-active');

      document.querySelectorAll('.kolrrhh-tabpane').forEach(p => {
        p.classList.toggle('is-active', p.getAttribute('data-pane') === key);
      });

      // ✅ IMPORTANTE: al entrar a Desempeño (t2), recargo por si cambió algo
      if (key === 't2' && __CURRENT_LEGAJO__ > 0) {
        loadDesempenoForLegajo(__CURRENT_LEGAJO__);
      }

      // ✅ Al entrar a Fichaje (t3), cargo contenido desde el plugin
      if (key === 't3') {
         initFichajeUI();
       }

    });

    document.addEventListener('click', function(ev){
      const trigger = ev.target.closest('.kolrrhh-info-btn');
      if (trigger) {
        ev.preventDefault();
        const targetId = trigger.getAttribute('aria-controls');
        const pop = targetId ? qs(targetId) : null;
        if (!pop) return;
        const isOpen = pop.classList.contains('is-open');
        closeInfoPopovers(targetId);
        if (!isOpen) {
          pop.classList.add('is-open');
          pop.setAttribute('aria-hidden', 'false');
          trigger.setAttribute('aria-expanded', 'true');
        } else {
          pop.classList.remove('is-open');
          pop.setAttribute('aria-hidden', 'true');
          trigger.setAttribute('aria-expanded', 'false');
        }
        return;
      }

      if (!ev.target.closest('.kolrrhh-popover')) {
        closeInfoPopovers();
      }
    });

    document.addEventListener('keydown', function(ev){
      if (ev.key === 'Escape') closeInfoPopovers();
    });

    const addBtn = qs('kolrrhh-add');
    if (addBtn) {
      addBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        openEmpModal('add', null);
      });
    }

    // === LOCALES: botón en el header izquierdo ===
    const localesBtn = qs('kolrrhh-open-locales');

    function toggleTabs(show){
      const tabs = document.querySelector('.kolrrhh-tabs');
      const panes = document.querySelector('.kolrrhh-tabpanes');
      if (tabs) tabs.classList.toggle('kolrrhh-hidden', !show);
      if (panes) panes.classList.toggle('kolrrhh-hidden', !show);
    }

    function clearEmployeeSelection(){
      document.querySelectorAll('.kolrrhh-item.is-selected').forEach(el => el.classList.remove('is-selected'));
    }

    function renderEmptyDetail(){
      const el = qs('kolrrhh-detail');
      if (!el) return;
      el.innerHTML = `
        <div class="kolrrhh-empty">
          <div class="kolrrhh-empty-dot"></div>
          <div>
            <div class="kolrrhh-empty-h">Sin selección</div>
            <div class="kolrrhh-empty-p">Hacé click en un empleado para ver información.</div>
          </div>
        </div>
      `;
    }

    function renderLocalesPanel(){
      const el = qs('kolrrhh-detail');
      if (!el) return;

      const list = (typeof KOL_RRHH !== 'undefined' && Array.isArray(KOL_RRHH.locales)) ? KOL_RRHH.locales : [];
      const rows = (list && list.length)
        ? list.map((name, i) => `
            <div class="kolrrhh-locales-row">
              <div class="kolrrhh-locales-name">${escapeHtml(String(name || '—'))}</div>
              <div class="kolrrhh-locales-chip">LOCAL</div>
            </div>
          `).join('')
        : `<div class="kolrrhh-muted" style="padding:14px;">No hay locales cargados.</div>`;

      el.innerHTML = `
        <div class="kolrrhh-locales-head">
          <div>
            <div class="kolrrhh-locales-title">Locales</div>
            <div class="kolrrhh-locales-sub">Listado de locales (solo vista por ahora)</div>
          </div>
          <button type="button" class="kolrrhh-btn kolrrhh-btn-secondary kolrrhh-btn-small" id="kolrrhh-locales-back">Volver</button>
        </div>
        <div class="kolrrhh-locales-list">${rows}</div>
      `;
    }

    if (localesBtn) {
      localesBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        __VIEW_MODE__ = 'locales';
        clearEmployeeSelection();
        toggleTabs(false);
        renderLocalesPanel();
      });
    }

    // Volver desde el panel de locales
    document.addEventListener('click', function(ev){
      const back = ev.target.closest('#kolrrhh-locales-back');
      if (!back) return;
      ev.preventDefault();
      __VIEW_MODE__ = 'employees';
      toggleTabs(true);
      renderEmptyDetail();
    });

    // ====== INPUT GUARDS / MASKS ======

    // DNI solo números, max 8
    const dniEl = qs('kolrrhh-modal-dni');
    if (dniEl) {
      dniEl.addEventListener('input', () => {
        dniEl.value = onlyDigits(dniEl.value).slice(0, 8);
      });
    }

    // CUIL solo números, max 11
    const cuilEl = qs('kolrrhh-modal-cuil');
    if (cuilEl) {
      cuilEl.addEventListener('input', () => {
        cuilEl.value = onlyDigits(cuilEl.value).slice(0, 11);
      });
    }

    // Teléfono: formatear al salir (blur)
    const telEl = qs('kolrrhh-modal-telefono');
    if (telEl) {
      telEl.addEventListener('blur', () => {
        const v = telEl.value.trim();
        if (v) telEl.value = normalizePhoneAR(v);
      });
    }

    // Nombre max 100 (por si no está el maxlength)
    const nombreEl = qs('kolrrhh-modal-nombre');
    if (nombreEl) {
      nombreEl.addEventListener('input', () => {
        if (nombreEl.value.length > 100) nombreEl.value = nombreEl.value.slice(0, 100);
      });
    }

    // Obra Social y Dirección max 100
    const osEl = qs('kolrrhh-modal-obra_social');
    if (osEl) {
      osEl.addEventListener('input', () => {
        if (osEl.value.length > 100) osEl.value = osEl.value.slice(0, 100);
      });
    }
    const dirEl = qs('kolrrhh-modal-direccion');
    if (dirEl) {
      dirEl.addEventListener('input', () => {
        if (dirEl.value.length > 100) dirEl.value = dirEl.value.slice(0, 100);
      });
    }

    // Close modal (X/cancelar)
    document.addEventListener('click', function (ev) {
      // 1) si el click fue dentro del modal de sueldo, cerramos SOLO ese
      const sueldoClose = ev.target.closest('#kolrrhh-sueldo-modal [data-close="1"]');
      if (sueldoClose) {
        ev.preventDefault();
        closeSueldoModal();
        return;
      }


      const desClose = ev.target.closest('#kolrrhh-desempeno-modal [data-close="1"]');
      if (desClose) {
        ev.preventDefault();
        closeDesempenoModal();
        return;
      }

      // 2) si el click fue dentro del modal empleado, cerramos ese
      const empClose = ev.target.closest('#kolrrhh-modal [data-close="1"]');
      if (empClose) {
        ev.preventDefault();
        closeEmpModal();
        return;
      }
    });

    // Close with ESC
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      closeEmpModal();
      closeSueldoModal();
    });

    // Save AJAX (empleado)
    const saveBtn = qs('kolrrhh-modal-save');
    if (saveBtn) {
      saveBtn.addEventListener('click', async function () {
        const modal = qs('kolrrhh-modal');
        const mode = (modal?.dataset?.mode) || 'add';

        const nombre = (qs('kolrrhh-modal-nombre')?.value || '').trim();
        let telefono = (qs('kolrrhh-modal-telefono')?.value || '').trim();
        let dni = (qs('kolrrhh-modal-dni')?.value || '').trim();
        let cuil = (qs('kolrrhh-modal-cuil')?.value || '').trim();
        const obra_social = (qs('kolrrhh-modal-obra_social')?.value || '').trim();
        const direccion = (qs('kolrrhh-modal-direccion')?.value || '').trim();
        const ciudad = (qs('kolrrhh-modal-ciudad')?.value || '').trim(); // select
        const estado = (qs('kolrrhh-modal-estado')?.value || 'ACTIVO').trim();

        // Nacimiento: date input (ISO) -> DD/MM/AAAA
        const nacISO = (qs('kolrrhh-modal-fecha_nacimiento')?.value || '').trim();
        const fecha_nacimiento = nacISO ? isoToDMY(nacISO) : '';

        // Último ingreso: date input (ISO) -> DD/MM/AAAA
        const ultISO = (qs('kolrrhh-modal-ultima_fecha_ingreso')?.value || '').trim();
        const ultima_fecha_ingreso = ultISO ? isoToDMY(ultISO) : '';

        // CBU: solo números
        let cbu = (qs('kolrrhh-modal-cbu')?.value || '').trim();
        cbu = onlyDigits(cbu).slice(0, 30);

        const empId = (qs('kolrrhh-modal-id')?.value || '').trim();

        // ==== VALIDACIONES ====
        if (!nombre) { alert('Ingresá un nombre.'); return; }
        if (nombre.length > 100) { alert('Nombre: máximo 100 caracteres'); return; }

        dni = onlyDigits(dni).slice(0, 8);
        cuil = onlyDigits(cuil).slice(0, 11);

        if (telefono) {
          telefono = normalizePhoneAR(telefono);
          if (!isValidPhoneAR(telefono)) {
            alert('Teléfono inválido. Usá 10 u 11 dígitos (ej: +54 342 508-4132).');
            return;
          }
        }

        if (dni && (dni.length < 7 || dni.length > 8)) {
          alert('DNI inválido (7 u 8 dígitos).');
          return;
        }

        if (cuil && cuil.length !== 11) {
          alert('CUIL inválido (debe tener 11 dígitos, solo números).');
          return;
        }

        if (cbu && cbu.length < 22) {
          // no bloquea por completo (hay casos de CBU/CVU con 22 dígitos),
          // pero al menos avisamos
          alert('CBU incompleto (normalmente son 22 dígitos).');
          return;
        }

        if (obra_social.length > 100) { alert('Obra social: máximo 100 caracteres'); return; }
        if (direccion.length > 100) { alert('Dirección: máximo 100 caracteres'); return; }

        if (typeof KOL_RRHH === 'undefined' || !KOL_RRHH.ajaxurl) {
          alert('Falta configuración AJAX (KOL_RRHH).');
          return;
        }

        const rolSel = qs('kolrrhh-sueldo-rol');
if (rolSel) rolSel.addEventListener('change', refreshBaseFromDB);

const horasSel = qs('kolrrhh-sueldo-horas');
if (horasSel) horasSel.addEventListener('change', refreshBaseFromDB);


const iniSel = qs('kolrrhh-sueldo-periodo-inicio');
if (iniSel) iniSel.addEventListener('change', () => {
  refreshComisionFromDB();
  refreshPresentismoDesempeno();
});

const areaSel = qs('kolrrhh-sueldo-area');
if (areaSel) areaSel.addEventListener('change', refreshComisionFromDB);
if (areaSel) {
  areaSel.addEventListener('change', () => {
    const partRaw = getVal('kolrrhh-sueldo-participacion') || '0';
    const participacion = parseFloat(partRaw.replace(',', '.')) || 0;

    const factor = getComisionFactorByArea(areaSel.value);

    const comisionFinal =
      __CURRENT_COMISION__ * (participacion / 100) * factor;

    setText('kolrrhh-sueldo-comision', moneyAR(comisionFinal));
  });
}

const finSel = qs('kolrrhh-sueldo-periodo-fin');
if (finSel) finSel.addEventListener('change', () => {
  refreshAntigFromState();
  refreshComisionFromDB();
  refreshPresentismoDesempeno();
});

        saveBtn.disabled = true;
        const oldText = saveBtn.textContent;
        saveBtn.textContent = 'Guardando...';

        try {
          const body = new URLSearchParams();
          body.set('action', 'kol_rrhh_save_employee');
          body.set('nonce', KOL_RRHH.nonce);
          body.set('mode', mode);
          body.set('id', empId);

          body.set('nombre', nombre);
          body.set('telefono', telefono);
          body.set('dni', dni);
          body.set('cuil', cuil);
          body.set('obra_social', obra_social);
          body.set('direccion', direccion);
          body.set('ciudad', ciudad);
          body.set('fecha_nacimiento', fecha_nacimiento);
          body.set('ultima_fecha_ingreso', ultima_fecha_ingreso);
          body.set('estado', estado);
          body.set('cbu', cbu);
          let clover_employee_id = (qs('kolrrhh-modal-clover_employee_id')?.value || '').trim();
          clover_employee_id = clover_employee_id.replace(/\s*,\s*/g, ','); // opcional, mismo criterio que en PHP

          body.set('clover_employee_id', clover_employee_id);

          const res = await fetch(KOL_RRHH.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
          });

          const json = await res.json();

          if (!json || !json.success) {
            alert(json?.data?.message || 'Error al guardar');
            return;
          }

          const empDb = json.data.emp || {};
          const payload = normalizeEmp(empDb);

          // Actualizar UI
          if (mode === 'edit') {
            const icon = document.querySelector(`.kolrrhh-edit-icon[data-emp-id="${payload.id}"]`);
            const item = icon ? icon.closest('.kolrrhh-item') : null;

            if (item) {
              item.setAttribute('data-emp', JSON.stringify(payload));

              const nameEl = item.querySelector('.kolrrhh-name');
              if (nameEl) nameEl.textContent = payload.nombre;

              // Si el item estaba seleccionado, refrescá el detail
              if (item.classList.contains('is-selected')) {
                renderDetail(payload);
              }
            }
          } else {
            // Para add: recargamos (simple y consistente)
            location.reload();
            return;
          }

          closeEmpModal();
        } catch (err) {
          console.error(err);
          alert('Error de red/servidor al guardar.');
        } finally {
          saveBtn.disabled = false;
          saveBtn.textContent = oldText;
        }
      });
    }

    // === SUELDO: botón Agregar y Editar en cards ===
    const sueldoHost = qs('kolrrhh-sueldo-items');

    document.addEventListener('click', function(ev){
      // Agregar nuevo item
      const add = ev.target.closest('#kolrrhh-sueldo-add');
      if (add) {
        ev.preventDefault();
        const leg = Number((sueldoHost?.dataset?.legajo) || 0);
        if (!leg) return;
        openSueldoModal(null, leg);
        return;
      }

      // Editar item (delegación)
      const edit = ev.target.closest('[data-sueldo-edit="1"]');
      if (edit) {
        ev.preventDefault();
        const leg = Number((sueldoHost?.dataset?.legajo) || 0);
        const id = Number(edit.getAttribute('data-id') || 0);
        if (!leg || !id) return;

        const row = (__LAST_SUELDO_ROWS__ || []).find(x => Number(x.id) === id);
        if (!row) {
          // fallback: recargamos y reintentamos una vez
          loadSueldoItemsForLegajo(leg).then(() => {
            const r2 = (__LAST_SUELDO_ROWS__ || []).find(x => Number(x.id) === id);
            if (r2) openSueldoModal(r2, leg);
          });
          return;
        }

        openSueldoModal(row, leg);
        return;
      }

      // PDF / Imprimir (delegación)
      const print = ev.target.closest('[data-sueldo-print="1"]');
      if (print) {
        ev.preventDefault();

        const id = Number(print.getAttribute('data-id') || 0);
        if (!id) return;

        const url = `${KOL_RRHH.ajaxurl}?action=kol_rrhh_print_sueldo_item&nonce=${encodeURIComponent(KOL_RRHH.nonce)}&id=${id}`;
        window.open(url, '_blank');
        return;
      }
    });

    // Guardar item sueldo (AJAX)
    const sueldoSaveBtn = qs('kolrrhh-sueldo-save');
    if (sueldoSaveBtn) {
      sueldoSaveBtn.addEventListener('click', async function(ev){
        ev.preventDefault();

        const legajo = Number(getVal('kolrrhh-sueldo-legajo'));
        const id = Number(getVal('kolrrhh-sueldo-id') || 0);

        const periodo_inicio = getVal('kolrrhh-sueldo-periodo-inicio');
        const periodo_fin = getVal('kolrrhh-sueldo-periodo-fin');
        const dias_de_trabajo_raw = getVal('kolrrhh-sueldo-dias-trabajo');

        const errorPeriodo = validarPeriodoSueldo(periodo_inicio, periodo_fin);
        // Validar Dias Trab. (0-99.99, max 2 dígitos + decimal opcional)
        const diasNorm = String(dias_de_trabajo_raw || '').trim().replace(',', '.');
        if (diasNorm !== '') {
          if (!/^\d{1,2}(?:\.\d{1,2})?$/.test(diasNorm)) { showSueldoError('Dias Trab. inválido (máx 2 dígitos y decimal opcional).'); return; }
          const diasNum = parseFloat(diasNorm);
          if (!isFinite(diasNum) || diasNum < 0 || diasNum >= 100) { showSueldoError('Dias Trab. fuera de rango (0 a 99,99).'); return; }
        }

        if (errorPeriodo) {
          showSueldoError(errorPeriodo);
          return;
        }


        if (!legajo) { showSueldoError('Falta legajo. Volvé a seleccionar el empleado.'); return; }
        if (!periodo_inicio || !periodo_fin) { showSueldoError('Periodo inicio y fin son obligatorios.'); return; }
        if (new Date(periodo_inicio) > new Date(periodo_fin)) { showSueldoError('El periodo inicio no puede ser mayor al fin.'); return; }

        const payload = new URLSearchParams();
        payload.set('action', 'kol_rrhh_save_sueldo_item');
        payload.set('nonce', KOL_RRHH.nonce);
        payload.set('id', String(id || 0));
        payload.set('legajo', String(legajo));

        payload.set('periodo_inicio', periodo_inicio);
        payload.set('periodo_fin', periodo_fin);
        payload.set('dias_de_trabajo', String(dias_de_trabajo_raw || '').trim());
        payload.set('rol', getVal('kolrrhh-sueldo-rol'));
        payload.set('horas', getVal('kolrrhh-sueldo-horas'));
        payload.set('participacion', getVal('kolrrhh-sueldo-participacion') || '0.0');
        payload.set('area', getVal('kolrrhh-sueldo-area'));
        payload.set('jornada', getVal('kolrrhh-sueldo-jornada'));

        payload.set('efectivo', getVal('kolrrhh-sueldo-efectivo'));
        payload.set('transferencia', getVal('kolrrhh-sueldo-transferencia'));
        payload.set('creditos', getVal('kolrrhh-sueldo-creditos'));
        payload.set('bono', getVal('kolrrhh-sueldo-bono'));
        payload.set('descuentos', getVal('kolrrhh-sueldo-descuentos'));
        payload.set('liquidacion', getVal('kolrrhh-sueldo-liquidacion'));

        payload.set('vac_tomadas', getVal('kolrrhh-sueldo-vac-tomadas') || '0');
        payload.set('feriados', getVal('kolrrhh-sueldo-feriados') || '0');
        payload.set('vac_no_tomadas', getVal('kolrrhh-sueldo-vac-no-tomadas') || '0');

        clearSueldoError();
        sueldoSaveBtn.disabled = true;
        sueldoSaveBtn.classList.add('is-loading');

        try{
          const res = await fetch(KOL_RRHH.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString()
          });
          const json = await res.json();
          if (!json || !json.success) {
            showSueldoError(json?.data?.message || 'Error al guardar el item.');
            return;
          }
          closeSueldoModal();
          await loadSueldoItemsForLegajo(legajo);
        }catch(err){
          console.error(err);
          showSueldoError('Error de red/servidor al guardar.');
        }finally{
          sueldoSaveBtn.disabled = false;
          sueldoSaveBtn.classList.remove('is-loading');
        }
      });
    }

    // Auto-seleccionar el primero
    const first = document.querySelector('#kolrrhh-list-activos .kolrrhh-item') ||
                  document.querySelector('#kolrrhh-list-otros .kolrrhh-item');
    if (first) first.click();
  });

 /* === LÓGICA DE CÁLCULO AUTOMÁTICO DE EFECTIVO === */

// 1. Esta función limpia los puntos y comas para que se puedan sumar
function limpiarMontoKOL(id) {
  const el = document.getElementById(id);
  if (!el || !el.value) return 0;

  let v = String(el.value);

  // sacar $ y espacios
  v = v.replace(/\$/g, '').replace(/\s/g, '');

  // sacar separadores de miles y normalizar decimal
  v = v.replace(/\./g, '').replace(',', '.');

  // dejar sólo dígitos, signo menos y punto decimal
  v = v.replace(/[^0-9.-]/g, '');

  const n = parseFloat(v);
  return isNaN(n) ? 0 : n;
}

// 2. La función principal que hace la cuenta
function calcularEfectivoAutomatico() {
    // Sumas (Haberes)
    const jornada     = limpiarMontoKOL('kolrrhh-sueldo-jornada');
    const bono        = limpiarMontoKOL('kolrrhh-sueldo-bono');
    const vacTomadas  = limpiarMontoKOL('kolrrhh-sueldo-vac-tomadas');
    const feriados    = limpiarMontoKOL('kolrrhh-sueldo-feriados');
    const liquidacion = limpiarMontoKOL('kolrrhh-sueldo-liquidacion');
    const vacNoTom    = limpiarMontoKOL('kolrrhh-sueldo-vac-no-tomadas');

    // Restas (Deducciones / Otros pagos)
    const descuentos  = limpiarMontoKOL('kolrrhh-sueldo-descuentos');
    const trans       = limpiarMontoKOL('kolrrhh-sueldo-transferencia');
    const creditos    = limpiarMontoKOL('kolrrhh-sueldo-creditos');

    // FÓRMULA SOLICITADA
    const total = (jornada + bono + vacTomadas + feriados + liquidacion + vacNoTom) - (descuentos + trans + creditos);

    const campoEfectivo = document.getElementById('kolrrhh-sueldo-efectivo');
    if (campoEfectivo) {
        // Formateamos el resultado para que coincida con el resto (Ej: 1.500,50)
        campoEfectivo.value = total.toLocaleString('es-AR', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        });
    }
}

// 3. Escuchamos los cambios en todos los campos involucrados
const idsInputsSueldo = [
    'kolrrhh-sueldo-jornada', 'kolrrhh-sueldo-bono', 'kolrrhh-sueldo-descuentos',
    'kolrrhh-sueldo-vac-tomadas', 'kolrrhh-sueldo-feriados', 'kolrrhh-sueldo-liquidacion',
    'kolrrhh-sueldo-vac-no-tomadas', 'kolrrhh-sueldo-transferencia', 'kolrrhh-sueldo-creditos'
];

idsInputsSueldo.forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        // Usamos 'input' para que el cálculo sea instantáneo al escribir
        el.addEventListener('input', calcularEfectivoAutomatico);
    }
});
})();
