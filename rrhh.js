/* KOL RRHH — Modal Alta/Edición + AJAX guardar + precarga de campos + VALIDACIONES */
(function () {
  // cache de items sueldo (para editar sin recargar)
  let __LAST_SUELDO_ROWS__ = [];
  let __CURRENT_LEGAJO__ = 0;

  function qs(id) { return document.getElementById(id); }

  function setVal(id, v){
    const el = qs(id);
    if (!el) return;
    el.value = (v === null || v === undefined) ? '' : String(v);
  }

  function getVal(id){
    const el = qs(id);
    return el ? String(el.value || '').trim() : '';
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
      categoria: e.categoria ?? e.Categoria ?? '',
    };
  }

  function renderDetail(empRaw) {
    const el = qs('kolrrhh-detail');
    if (!el) return;

    const emp = normalizeEmp(empRaw);

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
      </div>
    `;
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
      renderDesempenoTable(rows);
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

    if (!rows || rows.length === 0){
      host.innerHTML = `<div class="kolrrhh-muted">Sin datos de desempeño para este legajo.</div>`;
      return;
    }

    const trs = rows.map(r => {
      const mes = formatMesLabel(r.mes);
      const des = (r.desempeno === null || r.desempeno === undefined || r.desempeno === '')
        ? '—'
        : `${String(r.desempeno).replace('.', ',')}%`;

      const ina = parseInasistencias(r.inasistencias);
      const inaHtml = (ina.length === 0)
        ? '<span class="kolrrhh-muted">—</span>'
        : ina.map(d => `<span class="kolrrhh-chip">${escapeHtml(String(d))}</span>`).join(' ');

      return `
        <tr>
          <td class="kolrrhh-td-mes">${escapeHtml(mes)}</td>
          <td class="kolrrhh-td-des">${escapeHtml(des)}</td>
          <td class="kolrrhh-td-ina">${inaHtml}</td>
        </tr>
      `;
    }).join('');

    host.innerHTML = `
      <table class="kolrrhh-dgrid">
        <thead>
          <tr>
            <th>Mes</th>
            <th>Desempeño</th>
            <th>Inasistencias</th>
          </tr>
        </thead>
        <tbody>${trs}</tbody>
      </table>
    `;
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
    setVal('kolrrhh-sueldo-rol', row?.rol || '');
    setVal('kolrrhh-sueldo-jornada', row?.jornada || '');

    setVal('kolrrhh-sueldo-transferencia', row?.transferencia ?? '');
    setVal('kolrrhh-sueldo-creditos', row?.creditos ?? '');
    setVal('kolrrhh-sueldo-bono', row?.bono ?? '');
    setVal('kolrrhh-sueldo-descuentos', row?.descuentos ?? '');
    setVal('kolrrhh-sueldo-liquidacion', row?.liquidacion ?? '');

    setVal('kolrrhh-sueldo-vac-tomadas', row?.vac_tomadas ?? 0);
    setVal('kolrrhh-sueldo-feriados', row?.feriados ?? 0);
    setVal('kolrrhh-sueldo-vac-no-tomadas', row?.vac_no_tomadas ?? 0);

    // money formatting (en blur ya se formatea)
    ['kolrrhh-sueldo-transferencia','kolrrhh-sueldo-creditos','kolrrhh-sueldo-bono','kolrrhh-sueldo-descuentos','kolrrhh-sueldo-liquidacion']
      .forEach(id => {
        const el = qs(id);
        if (el) { attachMoneyInput(el); el.dispatchEvent(new Event('blur')); }
      });

    clearSueldoError();

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');

    // focus
    const focusId = isEdit ? 'kolrrhh-sueldo-transferencia' : 'kolrrhh-sueldo-periodo-inicio';
    setTimeout(() => { const f = qs(focusId); if (f) f.focus(); }, 0);
  }

  function closeSueldoModal(){
    const modal = qs('kolrrhh-sueldo-modal');
    if(!modal) return;

    // reset
    setVal('kolrrhh-sueldo-id', '0');
    setVal('kolrrhh-sueldo-legajo', '');
    ['kolrrhh-sueldo-periodo-inicio','kolrrhh-sueldo-periodo-fin','kolrrhh-sueldo-rol','kolrrhh-sueldo-jornada',
     'kolrrhh-sueldo-transferencia','kolrrhh-sueldo-creditos','kolrrhh-sueldo-bono','kolrrhh-sueldo-descuentos','kolrrhh-sueldo-liquidacion',
     'kolrrhh-sueldo-vac-tomadas','kolrrhh-sueldo-feriados','kolrrhh-sueldo-vac-no-tomadas'
    ].forEach(id => setVal(id, ''));

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
            </div>

            <div class="kolrrhh-sueldo-role">${escapeHtml(rol)}</div>

            <div class="kolrrhh-sueldo-actions">
              <button type="button" class="kolrrhh-btn kolrrhh-btn-small" data-sueldo-edit="1" data-id="${r.id}">Editar</button>
            </div>
          </div>

          <!-- PAGO -->
          <div class="kolrrhh-sueldo-section">
            <div class="kolrrhh-sueldo-section-title">Pago</div>
            <table class="kolrrhh-sueldo-table">
              <thead>
                <tr>
                  <th>Transferencia</th>
                  <th>Créditos</th>
                </tr>
              </thead>
              <tbody>
                <tr>
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
                  <td>${escapeHtml(r.jornada || '—')}</td>
                  <td>${moneyAR(r.bono)}</td>
                  <td>${moneyAR(r.descuentos)}</td>
                  <td>${Number(r.vac_tomadas || 0)}</td>
                  <td>${Number(r.feriados || 0)}</td>
                  <td>${moneyAR(r.liquidacion)}</td>
                  <td>${Number(r.vac_no_tomadas || 0)}</td>
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
    const fEstado = qs('kolrrhh-modal-estado');       // select

    if (!modal || !title || !idEl || !legEl || !fNombre) return;

    const isEdit = mode === 'edit';
    const emp = normalizeEmp(empRaw);

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

      if (fEstado) fEstado.value = (emp.estado ?? 'ACTIVO');
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
      if (fEstado) fEstado.value = 'ACTIVO';
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
    // === Tabs (Items Sueldo / Desempeño / Pestaña 3) ===
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
    });

    const addBtn = qs('kolrrhh-add');
    if (addBtn) {
      addBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        openEmpModal('add', null);
      });
    }

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

        if (obra_social.length > 100) { alert('Obra social: máximo 100 caracteres'); return; }
        if (direccion.length > 100) { alert('Dirección: máximo 100 caracteres'); return; }

        if (typeof KOL_RRHH === 'undefined' || !KOL_RRHH.ajaxurl) {
          alert('Falta configuración AJAX (KOL_RRHH).');
          return;
        }

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
          body.set('estado', estado);

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
        payload.set('rol', getVal('kolrrhh-sueldo-rol'));
        payload.set('jornada', getVal('kolrrhh-sueldo-jornada'));

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
})();
