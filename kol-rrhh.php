<?php
/**
 * Plugin Name: KOL RRHH
 * Author: ddmalqui
 * Description: Panel simple de RRHH (listado de personal) para KOL. Shortcode: [kol_rrhh]
 * Version: 1.0.2
 */

if (!defined('ABSPATH')) exit;

final class KOL_RRHH_Plugin {
  const VERSION = '1.0.3';
  const SHORTCODE = 'kol_rrhh';

  public function __construct(){
    add_action('wp_enqueue_scripts', [$this,'register_assets']);
    add_action('admin_enqueue_scripts', [$this,'register_assets']);
    add_action('wp_ajax_kol_rrhh_save_employee', [$this,'ajax_save_employee']);
    add_action('wp_ajax_kol_rrhh_get_sueldo_items', [$this,'ajax_get_sueldo_items']);
    add_action('wp_ajax_kol_rrhh_save_sueldo_item', [$this,'ajax_save_sueldo_item']);
    add_action('wp_ajax_kol_rrhh_get_desempeno_items', [$this,'ajax_get_desempeno_items']);
    add_action('wp_ajax_kol_rrhh_get_desempeno_locales', [$this,'ajax_get_desempeno_locales']);
    add_action('wp_ajax_kol_rrhh_save_desempeno_item', [$this,'ajax_save_desempeno_item']);
    add_action('wp_ajax_kol_rrhh_delete_desempeno_item', [$this,'ajax_delete_desempeno_item']);
    add_action('wp_ajax_kol_rrhh_get_fichaje_html', [$this,'ajax_get_fichaje_html']);
    add_action('wp_ajax_kol_rrhh_print_sueldo_item', [$this, 'ajax_print_sueldo_item']);
    add_action('wp_ajax_kol_rrhh_get_base', [$this,'ajax_get_base']);
    add_action('wp_ajax_kol_rrhh_get_comision', [$this,'ajax_get_comision']);
    add_shortcode(self::SHORTCODE, [$this,'shortcode']);
  }

  public function register_assets(){
    $base = plugin_dir_url(__FILE__);
    wp_register_style('kol-rrhh-style', $base.'style.css', [], self::VERSION);
    wp_register_script('kol-rrhh-js', $base.'rrhh.js', ['jquery'], self::VERSION, true);
    global $wpdb;

/* Locales */
$locales = $wpdb->get_col("
  SELECT nombre 
  FROM wp_kol_locales
  ORDER BY nombre
");

/* Áreas */
$areas = $wpdb->get_col("
  SELECT nombre
  FROM wp_kol_areas
  ORDER BY nombre
");

/* Horas (bandas) */
$horas_bandas = [];
$horas_table = $wpdb->prefix . 'kol_rrhh_horas_bandas';
$exists_horas = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $horas_table));
if ($exists_horas === $horas_table) {
  $cols_h = $wpdb->get_col("SHOW COLUMNS FROM {$horas_table}", 0);
  $cols_h = is_array($cols_h) ? $cols_h : [];

  $colHoras = in_array('horas', $cols_h, true) ? 'horas' : (in_array('cantidad', $cols_h, true) ? 'cantidad' : '');
  $colNombre = in_array('nombre', $cols_h, true) ? 'nombre' : (in_array('descripcion', $cols_h, true) ? 'descripcion' : '');

  if ($colHoras !== '') {
    $select = "SELECT {$colHoras} AS horas" . ($colNombre ? ", {$colNombre} AS nombre" : "") . " FROM {$horas_table} ORDER BY {$colHoras} ASC";
    $rows_h = $wpdb->get_results($select, ARRAY_A) ?: [];
    foreach ($rows_h as $r) {
      $h = isset($r['horas']) ? trim((string)$r['horas']) : '';
      if ($h === '') continue;
      $label = isset($r['nombre']) && trim((string)$r['nombre']) !== '' ? trim((string)$r['nombre']) : ($h . ' hs');
      $horas_bandas[] = ['value' => $h, 'label' => $label];
    }
  }
}

wp_localize_script('kol-rrhh-js', 'KOL_RRHH', [
  'ajaxurl' => admin_url('admin-ajax.php'),
  'nonce'   => wp_create_nonce('kol_rrhh_nonce'),
  'locales' => $locales ?: [],
  'areas'   => $areas ?: [],
  'horas_bandas' => $horas_bandas ?: [],
]);

  }

  /**
   * Devuelve un mapa asociativo MerchantID -> Nombre del comercio.
   * Se cachea para evitar consultar la tabla en cada request.
   *
   * Espera una tabla wp_kol_rrhh_clovers (o con prefix) con columnas tipo:
   * - merchant_id / num_comercio / comercio_id ...
   * - merchant_name / nombre / comercio_nombre ...
   */
  private function get_clover_merchant_map(){
    $cacheKey = 'kol_rrhh_clover_merchant_map_v1';
    $cached = get_transient($cacheKey);
    if (is_array($cached)) return $cached;

    global $wpdb;
    $table = $wpdb->prefix . 'kol_rrhh_clovers';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) {
      set_transient($cacheKey, [], 12 * HOUR_IN_SECONDS);
      return [];
    }

    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    $cols = is_array($cols) ? $cols : [];

    $colId = '';
    foreach (['merchant_id','num_comercio','comercio_id','merchant','id_comercio'] as $c){
      if (in_array($c, $cols, true)) { $colId = $c; break; }
    }
    $colName = '';
    foreach (['merchant_name','nombre','comercio_nombre','name','descripcion'] as $c){
      if (in_array($c, $cols, true)) { $colName = $c; break; }
    }

    if ($colId === '' || $colName === '') {
      set_transient($cacheKey, [], 12 * HOUR_IN_SECONDS);
      return [];
    }

    $rows = $wpdb->get_results("SELECT {$colId} AS mid, {$colName} AS mname FROM {$table}", ARRAY_A) ?: [];
    $map = [];
    foreach ($rows as $r){
      $mid = isset($r['mid']) ? trim((string)$r['mid']) : '';
      $mname = isset($r['mname']) ? trim((string)$r['mname']) : '';
      if ($mid === '' || $mname === '') continue;
      $map[$mid] = $mname;
    }

    set_transient($cacheKey, $map, 12 * HOUR_IN_SECONDS);
    return $map;
  }

  private function table_name(){
    global $wpdb;
    return $wpdb->prefix . 'kol_rrhh';
  }

  private function fetch_empleados(){
    global $wpdb;
    $table = $this->table_name();

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return [];

    // Activos primero, luego inactivos. Orden por legajo numérico asc.
    $sql = "
      SELECT *
      FROM {$table}
      ORDER BY
        CASE WHEN UPPER(estado)='ACTIVO' THEN 0 ELSE 1 END ASC,
        CAST(legajo AS UNSIGNED) ASC,
        id ASC
    ";
    return $wpdb->get_results($sql, ARRAY_A) ?: [];
  }

  private function initials($name){
    $name = trim((string)$name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    $ini = '';
    foreach ($parts as $p){
      if ($p === '') continue;
      $ini .= mb_strtoupper(mb_substr($p, 0, 1));
      if (mb_strlen($ini) >= 2) break;
    }
    return $ini ?: mb_strtoupper(mb_substr($name, 0, 1));
  }

  public function shortcode($atts = []){
    wp_enqueue_style('kol-rrhh-style');
    wp_enqueue_script('kol-rrhh-js');

    $empleados = $this->fetch_empleados();

    $activos = [];
    $otros   = [];
    foreach ($empleados as $e){
      $estado = strtoupper(trim((string)($e['estado'] ?? '')));
      if ($estado === 'ACTIVO') $activos[] = $e;
      else $otros[] = $e;
    }

    ob_start(); ?>
    <div class="kolrrhh-app" data-version="<?php echo esc_attr(self::VERSION); ?>">
      <div class="kolrrhh-shell">
        <div class="kolrrhh-left">
          <div class="kolrrhh-title">
            <div>
              <h2>Personal</h2>
            </div>
            <div class="kolrrhh-top-actions">
              <div class="kolrrhh-badge kolrrhh-badge-green" title="Activos"><?php echo esc_html(count($activos)); ?></div>
              <button type="button" class="kolrrhh-badge kolrrhh-store-btn" id="kolrrhh-open-locales" title="Ver locales" aria-label="Ver locales">
                <svg class="kolrrhh-store-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <path d="M3 10h18v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V10z"></path>
                  <path d="M4 10V6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v4"></path>
                  <path d="M7 21v-7h10v7"></path>
                  <path d="M2 10h20"></path>
                </svg>
              </button>
              <button type="button" class="kolrrhh-badge kolrrhh-badge-green kolrrhh-add" id="kolrrhh-add" title="Agregar personal">+</button>
            </div>
          </div>

          <div class="kolrrhh-left-scroll">
            <div class="kolrrhh-section">
              <div class="kolrrhh-section-h">Activos</div>
              <div class="kolrrhh-list" id="kolrrhh-list-activos">
                <?php echo $this->render_list($activos); ?>
              </div>
            </div>

            <div class="kolrrhh-section">
              <div class="kolrrhh-section-h">Inactivos</div>
              <div class="kolrrhh-list" id="kolrrhh-list-otros">
                <?php echo $this->render_list($otros); ?>
              </div>
            </div>
          </div>
        </div>

        <div class="kolrrhh-right">
          <div class="kolrrhh-card">
            <!-- El detalle se renderiza completo desde JS (opción A) -->
            <div class="kolrrhh-detail" id="kolrrhh-detail">
              <div class="kolrrhh-empty">
                <div class="kolrrhh-empty-dot"></div>
                <div>
                  <div class="kolrrhh-empty-h">Sin selección</div>
                  <div class="kolrrhh-empty-p">Hacé click en un empleado para ver información.</div>
                </div>
              </div>
            </div>

            <!-- Placeholder de pestañas (como referencia) -->
            <div class="kolrrhh-tabs">
              <button type="button" class="kolrrhh-tab is-active" data-tab="t1">Items Sueldo</button>
              <button type="button" class="kolrrhh-tab" data-tab="t2">Desempeno</button>
              <button type="button" class="kolrrhh-tab" data-tab="t3">Fichaje</button>
            </div>
            <div class="kolrrhh-tabpanes">
              <div class="kolrrhh-tabpane is-active" data-pane="t1">
                <div id="kolrrhh-sueldo-items" class="kolrrhh-muted">
                    Seleccioná un empleado…
                </div>
              </div>
              <div class="kolrrhh-tabpane" data-pane="t2">
                 <div id="kolrrhh-desempeno">
                    <div id="kolrrhh-desempeno-items" class="kolrrhh-muted">
                        Seleccioná un empleado para ver su desempeño.
                    </div>
                  </div>
              </div>
              <div class="kolrrhh-tabpane" data-pane="t3">
                <div class="kolrrhh-fichaje-ui">
  <div class="kolrrhh-fichaje-controls">
    <div class="kolrrhh-form-field" style="max-width: 260px;">
      <label class="kolrrhh-modal-label" style="margin-bottom:6px;">Mes</label>
      <select id="kolrrhh-fichaje-month" class="kolrrhh-modal-input"></select>
    </div>

    
    <div class="kolrrhh-form-field kolrrhh-fichaje-merchant-wrap" style="max-width: 320px;">
      <label class="kolrrhh-modal-label" style="margin-bottom:6px;">Comercio</label>
      <select id="kolrrhh-fichaje-merchant" class="kolrrhh-modal-input"></select>
    </div>
<div class="kolrrhh-form-field" style="max-width: 260px;">
      <label class="kolrrhh-modal-label" style="margin-bottom:6px;">&nbsp;</label>
      <button type="button" class="kolrrhh-btn kolrrhh-btn-primary" id="kolrrhh-fichaje-load">Ver</button>
    </div>
  </div>

  <div id="kolrrhh-fichaje-result" class="kolrrhh-muted">Seleccioná un mes y presioná “Ver”.</div>
</div>
              </div>
            </div>

            <div class="kolrrhh-footnote">
              * Esta pantalla está pensada para ser protegida por contraseña (o luego por usuario logueado).
            </div>
          </div>
        </div>
      </div>
    </div>

<!-- Modal Alta/Edición -->
<div class="kolrrhh-modal" id="kolrrhh-modal" aria-hidden="true">
  <div class="kolrrhh-modal-backdrop" data-close="1"></div>

  <div class="kolrrhh-modal-card" role="dialog" aria-modal="true" aria-labelledby="kolrrhh-modal-title">
    <div class="kolrrhh-modal-h">
      <div class="kolrrhh-modal-title" id="kolrrhh-modal-title">Legajo 0000</div>
      <button type="button" class="kolrrhh-modal-x" data-close="1" aria-label="Cerrar">×</button>
    </div>

    <div class="kolrrhh-modal-b">
      <div class="kolrrhh-grid">

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Nombre</div>
    <div class="kolrrhh-v">
      <input type="text" id="kolrrhh-modal-nombre" class="kolrrhh-modal-input"
        maxlength="100" placeholder="Nombre" autocomplete="off" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Teléfono</div>
    <div class="kolrrhh-v">
      <input type="tel" id="kolrrhh-modal-telefono" class="kolrrhh-modal-input"
        inputmode="tel" maxlength="18" placeholder="Ej: +54 342 508-4132" autocomplete="off" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">DNI</div>
    <div class="kolrrhh-v">
      <input type="text" id="kolrrhh-modal-dni" class="kolrrhh-modal-input"
        inputmode="numeric" maxlength="8" placeholder="Ej: 34827828" autocomplete="off" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">CUIL</div>
    <div class="kolrrhh-v">
      <input type="text" id="kolrrhh-modal-cuil" class="kolrrhh-modal-input"
        inputmode="numeric" maxlength="11" placeholder="Ej: 20348278283" autocomplete="off" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Obra social</div>
    <div class="kolrrhh-v">
      <input type="text" id="kolrrhh-modal-obra_social" class="kolrrhh-modal-input"
        maxlength="100" placeholder="Ej: OSPAT" autocomplete="off" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Dirección</div>
    <div class="kolrrhh-v">
      <input type="text" id="kolrrhh-modal-direccion" class="kolrrhh-modal-input"
        maxlength="100" placeholder="Ej: 4 de Enero 2359 P2 D3" autocomplete="off" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Ciudad</div>
    <div class="kolrrhh-v">
      <select id="kolrrhh-modal-ciudad" class="kolrrhh-modal-input">
        <option value="">Seleccionar…</option>
        <option>Buenos Aires</option>
        <option>Córdoba</option>
        <option>Rosario</option>
        <option>Santa Fe</option>
        <option>Mendoza</option>
        <option>La Plata</option>
        <option>Mar del Plata</option>
        <option>San Miguel de Tucumán</option>
        <option>Salta</option>
        <option>San Juan</option>
        <option>Resistencia</option>
        <option>Corrientes</option>
        <option>Posadas</option>
        <option>Paraná</option>
        <option>Neuquén</option>
        <option>Bahía Blanca</option>
        <option>San Salvador de Jujuy</option>
        <option>Formosa</option>
        <option>San Luis</option>
        <option>Río Cuarto</option>
        <option>Comodoro Rivadavia</option>
        <option>Río Gallegos</option>
        <option>Ushuaia</option>
        <option>Viedma</option>
        <option>Catamarca</option>
        <option>La Rioja</option>
        <option>Santiago del Estero</option>
        <option>Santa Rosa</option>
        <option>Rawson</option>
      </select>
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Nacimiento</div>
    <div class="kolrrhh-v">
      <input type="date" id="kolrrhh-modal-fecha_nacimiento" class="kolrrhh-modal-input" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Último ingreso</div>
    <div class="kolrrhh-v">
      <input type="date" id="kolrrhh-modal-ultima_fecha_ingreso" class="kolrrhh-modal-input" />
    </div>
  </div>

  <div class="kolrrhh-field">
    <div class="kolrrhh-k">Estado</div>
    <div class="kolrrhh-v">
      <select id="kolrrhh-modal-estado" class="kolrrhh-modal-input">
        <option value="ACTIVO">ACTIVO</option>
        <option value="PASIVO">PASIVO</option>
        <option value="EVENTUAL">EVENTUAL</option>
        <option value="PENDIENTE">PENDIENTE</option>
      </select>
    </div>
  </div>

<div class="kolrrhh-field">
  <div class="kolrrhh-k">Clover ID</div>
  <div class="kolrrhh-v">
    <input
      type="text"
      id="kolrrhh-modal-clover_employee_id"
      class="kolrrhh-modal-input"
      maxlength="255"
      placeholder="Ej: 3489489383,9448435,88238843"
      autocomplete="off"
    />
  </div>
</div>

<div class="kolrrhh-field">
  <div class="kolrrhh-k">CBU</div>
  <div class="kolrrhh-v">
    <input
      type="text"
      id="kolrrhh-modal-cbu"
      class="kolrrhh-modal-input"
      maxlength="30"
      placeholder="Ej: 2850371240095315610248"
      autocomplete="off"
    />
  </div>
</div>

</div>


      <!-- hidden -->
      <input type="hidden" id="kolrrhh-modal-id" />
      <input type="hidden" id="kolrrhh-modal-legajo" />
    </div>

    <div class="kolrrhh-modal-actions">
      <button type="button" class="kolrrhh-btn" data-close="1">Cancelar</button>
      <button type="button" class="kolrrhh-btn kolrrhh-btn-primary" id="kolrrhh-modal-save">Guardar</button>
    </div>
  </div>
</div>

<!-- Modal editar/agregar items sueldo -->
<div id="kolrrhh-sueldo-modal" class="kolrrhh-modal" aria-hidden="true">
  <div class="kolrrhh-modal-backdrop" data-close="1"></div>

  <div class="kolrrhh-modal-card kolrrhh-modal-card-wide">

    <div class="kolrrhh-modal-body">
      <div id="kolrrhh-sueldo-error" class="kolrrhh-form-error" style="display:none;"></div>

      <input type="hidden" id="kolrrhh-sueldo-id" value="0" />
      <input type="hidden" id="kolrrhh-sueldo-legajo" value="" />

<div class="kolrrhh-form-row" style="--cols:7;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Fecha inicio *</label>
          <input id="kolrrhh-sueldo-periodo-inicio" type="date" class="kolrrhh-modal-input" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Fecha fin *</label>
          <input id="kolrrhh-sueldo-periodo-fin" type="date" class="kolrrhh-modal-input" />
        </div>

        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Dias Trab.</label>
          <input id="kolrrhh-sueldo-dias-trabajo" type="text" inputmode="decimal" class="kolrrhh-modal-input" maxlength="5" placeholder="Ej: 22,5" />
        </div>

         <div class="kolrrhh-form-field">
  <label class="kolrrhh-modal-label">Área / Local</label>
  <select id="kolrrhh-sueldo-area" class="kolrrhh-modal-input"></select>
</div>

<div class="kolrrhh-form-field">
  <label class="kolrrhh-modal-label">Rol</label>
  <select id="kolrrhh-sueldo-rol" class="kolrrhh-modal-input"></select>
</div>


<div class="kolrrhh-form-field">
  <label class="kolrrhh-modal-label">Horas</label>
  <select id="kolrrhh-sueldo-horas" class="kolrrhh-modal-input"></select>
</div>

<div class="kolrrhh-form-field">
  <label class="kolrrhh-modal-label">Participación</label>
  <select id="kolrrhh-sueldo-participacion" class="kolrrhh-modal-input"></select>
</div>
      </div>

      <div class="kolrrhh-modal-section-title">Modo de Pago</div>

      <div class="kolrrhh-form-row" style="--cols:3;">
      <div class="kolrrhh-form-field">
        <label class="kolrrhh-modal-label">Efectivo</label>
        <input id="kolrrhh-sueldo-efectivo" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money nomodif" readonly style="background: rgba(0,0,0,.03);" />
      </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Transferencia</label>
          <input id="kolrrhh-sueldo-transferencia" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Créditos</label>
          <input id="kolrrhh-sueldo-creditos" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
      </div>

      <div class="kolrrhh-modal-section-title">Detalles</div>

      <div class="kolrrhh-form-row" style="--cols:7;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Jornada</label>
          <input id="kolrrhh-sueldo-jornada" type="text" class="kolrrhh-modal-input kolrrhh-money" maxlength="80" placeholder="Ej: Completa / Media" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Bono</label>
          <input id="kolrrhh-sueldo-bono" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Descuentos</label>
          <input id="kolrrhh-sueldo-descuentos" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Vac. tomadas</label>
          <input id="kolrrhh-sueldo-vac-tomadas" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Feriados</label>
          <input id="kolrrhh-sueldo-feriados" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Liquidación</label>
          <input id="kolrrhh-sueldo-liquidacion" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Vac.no tom.</label>
          <input id="kolrrhh-sueldo-vac-no-tomadas" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
      </div>


      <div class="kolrrhh-form-row" style="--cols:6;">
        <div class="kolrrhh-form-field kolrrhh-form-field--with-popover">
          <div class="kolrrhh-modal-label-row">
            <label class="kolrrhh-modal-label">Base</label>
            <button
              type="button"
              class="kolrrhh-info-btn"
              aria-label="Más información sobre base"
              aria-expanded="false"
              aria-controls="kolrrhh-base-popover"
            >
              i
            </button>
          </div>
          <div id="kolrrhh-base-popover" class="kolrrhh-popover" role="tooltip" aria-hidden="true">base:</div>
          <div id="kolrrhh-sueldo-base" class="kolrrhh-modal-input kolrrhh-modal-value">$0</div>
        </div>
        <div class="kolrrhh-form-field kolrrhh-form-field--with-popover">
          <div class="kolrrhh-modal-label-row">
            <label class="kolrrhh-modal-label">Antig.</label>
            <button
              type="button"
              class="kolrrhh-info-btn"
              aria-label="Más información sobre antigüedad"
              aria-expanded="false"
              aria-controls="kolrrhh-antig-popover"
            >
              i
            </button>
          </div>
          <div id="kolrrhh-antig-popover" class="kolrrhh-popover" role="tooltip" aria-hidden="true">
            Antigüedad = 0,01 × años completos × base × (días trabajados / 26).
          </div>
          <div id="kolrrhh-sueldo-antig" class="kolrrhh-modal-input kolrrhh-modal-value">$0</div>
        </div>
        <div class="kolrrhh-form-field kolrrhh-form-field--with-popover">
          <div class="kolrrhh-modal-label-row">
            <label class="kolrrhh-modal-label">Comisión</label>
            <button
              type="button"
              class="kolrrhh-info-btn"
              aria-label="Más información sobre comisión"
              aria-expanded="false"
              aria-controls="kolrrhh-comision-popover"
            >
              i
            </button>
          </div>
          <div id="kolrrhh-comision-popover" class="kolrrhh-popover" role="tooltip" aria-hidden="true">
            Comisión = ventas del mes × coeficiente × factor (0,01 o 0,005 si es Dep) × participación.
          </div>
          <div id="kolrrhh-sueldo-comision" class="kolrrhh-modal-input kolrrhh-modal-value">$0</div>
        </div>
        <div class="kolrrhh-form-field kolrrhh-form-field--with-popover">
          <div class="kolrrhh-modal-label-row">
            <label class="kolrrhh-modal-label">Desem. Pers.</label>
            <button
              type="button"
              class="kolrrhh-info-btn"
              aria-label="Más información sobre desempenoPersonal"
              aria-expanded="false"
              aria-controls="kolrrhh-desempeno-personal-popover"
            >
              i
            </button>
          </div>
          <div id="kolrrhh-desempeno-personal-popover" class="kolrrhh-popover" role="tooltip" aria-hidden="true">
            Desempeno Personal = base × desempeno personal.
          </div>
          <div id="kolrrhh-sueldo-desempeno-personal" class="kolrrhh-modal-input kolrrhh-modal-value">$0</div>
        </div>
        <div class="kolrrhh-form-field kolrrhh-form-field--with-popover">
          <div class="kolrrhh-modal-label-row">
            <label class="kolrrhh-modal-label">Rendimiento</label>
            <button
              type="button"
              class="kolrrhh-info-btn"
              aria-label="Más información sobre rendimiento"
              aria-expanded="false"
              aria-controls="kolrrhh-rendimiento-popover"
            >
              i
            </button>
          </div>
          <div id="kolrrhh-rendimiento-popover" class="kolrrhh-popover" role="tooltip" aria-hidden="true">
            Rendimiento = Rendimiento del local × participación × $300.000.
          </div>
          <div id="kolrrhh-sueldo-rendimiento" class="kolrrhh-modal-input kolrrhh-modal-value">$0</div>
        </div>
        <div class="kolrrhh-form-field kolrrhh-form-field--with-popover">
          <div class="kolrrhh-modal-label-row">
            <label class="kolrrhh-modal-label">No rem.</label>
            <button
              type="button"
              class="kolrrhh-info-btn"
              aria-label="Más información sobre no remunerativo"
              aria-expanded="false"
              aria-controls="kolrrhh-no-rem-popover"
            >
              i
            </button>
          </div>
          <div id="kolrrhh-no-rem-popover" class="kolrrhh-popover" role="tooltip" aria-hidden="true">
            No remunerativo = base × 0,6.
          </div>
          <div id="kolrrhh-sueldo-no-rem" class="kolrrhh-modal-input kolrrhh-modal-value">$0</div>
        </div>
      </div>
    </div>

    <div class="kolrrhh-modal-actions kolrrhh-sueldo-modal-actions">
      <div class="kolrrhh-sueldo-total">
        <span class="kolrrhh-sueldo-total-label">Total a cobrar</span>
        <span id="kolrrhh-sueldo-total-cobrar" class="kolrrhh-sueldo-total-value">$0,00</span>
      </div>
      <div class="kolrrhh-sueldo-actions">
        <button type="button" class="kolrrhh-btn" data-close="1">Cancelar</button>
        <button type="button" class="kolrrhh-btn kolrrhh-btn-primary" id="kolrrhh-sueldo-save">Guardar</button>
      </div>
    </div>
  </div>
</div>


    
<!-- Modal agregar desempeño -->
<div id="kolrrhh-desempeno-modal" class="kolrrhh-modal" aria-hidden="true">
  <div class="kolrrhh-modal-backdrop" data-close="1"></div>

  <div class="kolrrhh-modal-card kolrrhh-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="kolrrhh-desempeno-title">
  
    <div class="kolrrhh-modal-body">
      <div id="kolrrhh-desempeno-error" class="kolrrhh-form-error" style="display:none;"></div>

      <input type="hidden" id="kolrrhh-desempeno-legajo" value="" />

      <div class="kolrrhh-form-row" style="--cols:2;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Mes *</label>
          <select id="kolrrhh-desempeno-mes" class="kolrrhh-modal-input"></select>
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Desempeño (%) *</label>
          <input id="kolrrhh-desempeno-porcentaje" type="number" step="0.01" min="0" max="100"
                 class="kolrrhh-modal-input" placeholder="Ej: 10" />
        </div>
      </div>

      <div class="kolrrhh-form-row" style="--cols:2;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Fecha inasistencia</label>
          <input id="kolrrhh-desempeno-fecha" type="date" class="kolrrhh-modal-input" />
        </div>
        <div class="kolrrhh-form-field" style="display:flex; align-items:flex-end; gap:10px;">
          <button type="button" class="kolrrhh-btn kolrrhh-btn-secondary" id="kolrrhh-desempeno-add-fecha">Agregar</button>
          <div class="kolrrhh-muted" style="font-size:12px;">Podés agregar varias fechas.</div>
        </div>
      </div>

      <div class="kolrrhh-form-field">
        <label class="kolrrhh-modal-label">Inasistencias cargadas</label>
        <div id="kolrrhh-desempeno-fechas-list" class="kolrrhh-chips"></div>
      </div>
    </div>

    <div class="kolrrhh-modal-actions">
      <button type="button" class="kolrrhh-btn" data-close="1">Cancelar</button>
      <button type="button" class="kolrrhh-btn kolrrhh-btn-primary" id="kolrrhh-desempeno-save">Agregar desempeño</button>
    </div>
  </div>
</div>


<?php
    return ob_get_clean();
  }

  private function max_legajo_numeric(){
  global $wpdb;
  $table = $this->table_name();

  // asume legajo numérico o string numérico
  $max = $wpdb->get_var("SELECT MAX(CAST(legajo AS UNSIGNED)) FROM {$table}");
  return intval($max);
}

private function sueldos_items_table(){
  global $wpdb;
  return $wpdb->prefix . 'kol_rrhh_items_sueldos';
}

public function ajax_get_sueldo_items(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido']);
  }
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  $legajo = isset($_POST['legajo']) ? intval($_POST['legajo']) : 0;
  if ($legajo <= 0) wp_send_json_error(['message' => 'Legajo inválido']);

  global $wpdb;
  $table = $this->sueldos_items_table();

  // si la tabla no existe todavía, devolvemos vacío
  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists !== $table) {
    wp_send_json_success(['rows' => []]);
  }

  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT id, legajo, periodo_inicio, periodo_fin, dias_de_trabajo, area, rol, participacion, horas,
              efectivo, transferencia, creditos, jornada, bono, descuentos, vac_tomadas, feriados, liquidacion, vac_no_tomadas
       FROM {$table}
       WHERE legajo = %d
       ORDER BY periodo_inicio DESC, id DESC",
      $legajo
    ),
    ARRAY_A
  );

  wp_send_json_success(['rows' => $rows ?: []]);
}


private function desempeno_table(){
  global $wpdb;
  return $wpdb->prefix . 'kol_rrhh_desempeno'; // => wp_kol_rrhh_desempeno
}

public function ajax_get_desempeno_items(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido']);
  }
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  $legajo = isset($_POST['legajo']) ? intval($_POST['legajo']) : 0;
  if ($legajo <= 0) wp_send_json_error(['message' => 'Legajo inválido']);

  global $wpdb;
  $table = $this->desempeno_table();

  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists !== $table) {
    wp_send_json_success(['rows' => []]);
  }

          // Ajustá nombres de columnas si difieren:
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT 
          id,
          legajo,
          DATE_FORMAT(mes, '%Y-%m-01') AS mes,
          desempeno,
          inasistencias
        FROM {$table}
        WHERE legajo = %d
        ORDER BY mes DESC, id DESC",
      $legajo
    ),
    ARRAY_A
  );

  wp_send_json_success(['rows' => $rows ?: []]);
}

public function ajax_get_desempeno_locales(){
  check_ajax_referer('kol_rrhh_nonce', 'nonce');
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  global $wpdb;
  $t_desempeno = $wpdb->prefix . 'kol_rrhh_desempeno_locales';
  $t_alt_desempeno = $wpdb->prefix . 'kol_rrhh_rendimiento_locales';
  $t_locales = $wpdb->prefix . 'kol_locales';

  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_desempeno));
  if ($exists !== $t_desempeno) {
    $exists_alt = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_alt_desempeno));
    if ($exists_alt !== $t_alt_desempeno) {
      wp_send_json_success(['rows' => []]);
    }
    $t_desempeno = $t_alt_desempeno;
  }

  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t_locales));
  if ($exists !== $t_locales) {
    wp_send_json_error(['message' => 'No existe la tabla de locales.']);
  }

  $cols_d = $wpdb->get_col("SHOW COLUMNS FROM {$t_desempeno}", 0) ?: [];
  $cols_l = $wpdb->get_col("SHOW COLUMNS FROM {$t_locales}", 0) ?: [];

  $colAnio = in_array('anio', $cols_d, true) ? 'anio' : (in_array('año', $cols_d, true) ? 'año' : '');
  $colMes = in_array('mes', $cols_d, true) ? 'mes' : '';
  $colLocalId = '';
  foreach (['local_id','id_local','locales_id','id_locales','local'] as $c){
    if (in_array($c, $cols_d, true)) { $colLocalId = $c; break; }
  }
  $colControl = '';
  foreach (['control_caja_pct','control_caja','control_pct'] as $c){
    if (in_array($c, $cols_d, true)) { $colControl = $c; break; }
  }
  $colObjetivos = '';
  foreach (['objetivos_pct','objetivo_pct','objetivos'] as $c){
    if (in_array($c, $cols_d, true)) { $colObjetivos = $c; break; }
  }
  $colCompras = '';
  foreach (['compras_pct','compra_pct','compras'] as $c){
    if (in_array($c, $cols_d, true)) { $colCompras = $c; break; }
  }
  $colTotal = '';
  foreach (['total_pct','total'] as $c){
    if (in_array($c, $cols_d, true)) { $colTotal = $c; break; }
  }
  $colComision = '';
  foreach (['comision_coef','comision','coeficiente_comision'] as $c){
    if (in_array($c, $cols_d, true)) { $colComision = $c; break; }
  }

  $colLocId = in_array('id', $cols_l, true) ? 'id' : '';
  foreach (['local_id','id_local','locales_id','id_locales'] as $c){
    if ($colLocId !== '') break;
    if (in_array($c, $cols_l, true)) { $colLocId = $c; break; }
  }
  $colLocName = '';
  foreach (['nombre','name','local','local_nombre','nombre_local','descripcion'] as $c){
    if (in_array($c, $cols_l, true)) { $colLocName = $c; break; }
  }

  $useLocalJoin = ($colLocId !== '' && $colLocName !== '');

  if (!$colAnio || !$colMes || !$colLocalId || !$colControl || !$colObjetivos || !$colCompras || !$colTotal || !$colComision || (!$useLocalJoin && !$colLocalId)){
    wp_send_json_error(['message' => 'No se pudieron detectar columnas necesarias en desempeño/locales.']);
  }

  $localNameExpr = $useLocalJoin ? "l.{$colLocName}" : "d.{$colLocalId}";

  $sql = "
    SELECT
      d.{$colAnio} AS anio,
      d.{$colMes} AS mes,
      {$localNameExpr} AS local_nombre,
      d.{$colControl} AS control_caja_pct,
      d.{$colObjetivos} AS objetivos_pct,
      d.{$colCompras} AS compras_pct,
      d.{$colTotal} AS total_pct,
      d.{$colComision} AS comision_coef
    FROM {$t_desempeno} d
    " . ($useLocalJoin ? "LEFT JOIN {$t_locales} l ON l.{$colLocId} = d.{$colLocalId}" : "") . "
    ORDER BY d.{$colAnio} DESC, d.{$colMes} DESC, {$localNameExpr} ASC
  ";

  $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
  wp_send_json_success(['rows' => $rows]);
}

public function ajax_get_base(){
  check_ajax_referer('kol_rrhh_nonce', 'nonce');

  global $wpdb;

  $rol   = isset($_POST['rol']) ? sanitize_text_field(wp_unslash($_POST['rol'])) : '';
  $horas = isset($_POST['horas']) ? sanitize_text_field(wp_unslash($_POST['horas'])) : '';

  if ($rol === '' || $horas === ''){
    wp_send_json_error(['message' => 'Falta rol u horas.']);
  }

  $t_basicos = $wpdb->prefix . 'kol_rrhh_basicos';
  $t_roles   = $wpdb->prefix . 'kol_rrhh_roles';
  $t_horas   = $wpdb->prefix . 'kol_rrhh_horas_bandas';

  // Validar existencia de tablas
  foreach ([$t_basicos, $t_roles, $t_horas] as $t){
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    if ($exists !== $t){
      wp_send_json_error(['message' => "No existe la tabla {$t}."]);
    }
  }

  // Detectar columnas (flexible)
  $cols_b = $wpdb->get_col("SHOW COLUMNS FROM {$t_basicos}", 0) ?: [];
  $cols_r = $wpdb->get_col("SHOW COLUMNS FROM {$t_roles}", 0) ?: [];
  $cols_h = $wpdb->get_col("SHOW COLUMNS FROM {$t_horas}", 0) ?: [];

  $colRoleIdB = '';
  foreach (['rol_id','role_id','id_rol'] as $c){ if (in_array($c,$cols_b,true)) { $colRoleIdB=$c; break; } }

  $colHorasIdB = '';
foreach (['banda_horas_id','hora_banda_id','horas_banda_id','banda_id','id_banda'] as $c){
  if (in_array($c,$cols_b,true)) { $colHorasIdB=$c; break; }
}
  $colBase = '';
  foreach (['base','basico','monto','valor'] as $c){ if (in_array($c,$cols_b,true)) { $colBase=$c; break; } }

  $colRoleId = in_array('id',$cols_r,true) ? 'id' : '';
  $colRoleName = '';
  foreach (['nombre','rol','name','descripcion'] as $c){ if (in_array($c,$cols_r,true)) { $colRoleName=$c; break; } }

  $colHorasId = in_array('id',$cols_h,true) ? 'id' : '';
  $colHorasVal = '';
  foreach (['horas','cantidad'] as $c){ if (in_array($c,$cols_h,true)) { $colHorasVal=$c; break; } }

  if (!$colRoleIdB || !$colHorasIdB || !$colBase || !$colRoleId || !$colRoleName || !$colHorasId || !$colHorasVal){
    wp_send_json_error(['message' => 'No se pudieron detectar columnas necesarias en basicos/roles/horas_bandas.']);
  }

  // Query: basicos -> roles (por nombre) + horas_bandas (por horas)
  $sql = "
    SELECT b.{$colBase} AS base
    FROM {$t_basicos} b
    INNER JOIN {$t_roles} r ON r.{$colRoleId} = b.{$colRoleIdB}
    INNER JOIN {$t_horas} h ON h.{$colHorasId} = b.{$colHorasIdB}
    WHERE r.{$colRoleName} = %s
      AND CAST(h.{$colHorasVal} AS CHAR) = %s
    LIMIT 1
  ";

  $row = $wpdb->get_row($wpdb->prepare($sql, $rol, (string)$horas), ARRAY_A);

  $base = 0;
  if ($row && isset($row['base'])){
    $base = floatval(str_replace(',', '.', (string)$row['base']));
  }

  wp_send_json_success(['base' => $base]);
}




public function ajax_get_comision(){
  check_ajax_referer('kol_rrhh_nonce', 'nonce');

  global $wpdb;

  $area = isset($_POST['area']) ? sanitize_text_field(wp_unslash($_POST['area'])) : '';
  $anio = isset($_POST['anio']) ? intval($_POST['anio']) : 0;
  $mes  = isset($_POST['mes']) ? intval($_POST['mes']) : 0;

  if ($area === '' || $anio <= 0 || $mes <= 0 || $mes > 12){
    wp_send_json_error(['message' => 'Parámetros inválidos (area/anio/mes).']);
  }

  // Tablas
  $t_locales = $wpdb->prefix . 'kol_locales';
  $t_ventas  = $wpdb->prefix . 'kol_ventas_mensuales';
  $t_rend    = $wpdb->prefix . 'kol_rrhh_rendimiento_locales';

  foreach ([$t_locales, $t_ventas, $t_rend] as $t){
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    if ($exists !== $t){
      wp_send_json_error(['message' => "No existe la tabla {$t}."]);
    }
  }

  // Columnas (flexible)
  $cols_l = $wpdb->get_col("SHOW COLUMNS FROM {$t_locales}", 0) ?: [];
  $cols_v = $wpdb->get_col("SHOW COLUMNS FROM {$t_ventas}", 0) ?: [];
  $cols_r = $wpdb->get_col("SHOW COLUMNS FROM {$t_rend}", 0) ?: [];

  $colLocId   = in_array('id', $cols_l, true) ? 'id' : '';
  $colLocName = in_array('nombre', $cols_l, true) ? 'nombre' : (in_array('name', $cols_l, true) ? 'name' : '');

  $colVentaLoc = '';
  foreach (['local_id','local','id_local','locales_id','id_locales'] as $c){
    if (in_array($c, $cols_v, true)) { $colVentaLoc = $c; break; }
  }
  $colVentaAnio = in_array('anio', $cols_v, true) ? 'anio' : (in_array('año', $cols_v, true) ? 'año' : '');
  $colVentaMes  = in_array('mes', $cols_v, true) ? 'mes' : '';
  $colVentaMonto = '';
  foreach (['ventas','monto','importe','total'] as $c){
    if (in_array($c, $cols_v, true)) { $colVentaMonto = $c; break; }
  }

  $colRendAnio = in_array('anio', $cols_r, true) ? 'anio' : (in_array('año', $cols_r, true) ? 'año' : '');
  $colRendMes  = in_array('mes', $cols_r, true) ? 'mes' : '';
  $colRendLoc = '';
  foreach (['local_id','id_local','locales_id','id_locales','local'] as $c){
    if (in_array($c, $cols_r, true)) { $colRendLoc = $c; break; }
  }
  $colRendComision = '';
  foreach (['comision_coef','comision','coeficiente_comision'] as $c){
    if (in_array($c, $cols_r, true)) { $colRendComision = $c; break; }
  }

  if (!$colLocId || !$colLocName || !$colVentaLoc || !$colVentaAnio || !$colVentaMes || !$colVentaMonto){
    wp_send_json_error(['message' => 'No se pudieron detectar columnas necesarias en locales/ventas_mensuales.']);
  }
  if (!$colRendAnio || !$colRendMes || !$colRendLoc || !$colRendComision){
    wp_send_json_error(['message' => 'No se pudieron detectar columnas necesarias en rendimiento_locales.']);
  }

  $local_id = $wpdb->get_var(
    $wpdb->prepare("SELECT {$colLocId} FROM {$t_locales} WHERE {$colLocName} = %s LIMIT 1", $area)
  );

  if (!$local_id){
    wp_send_json_success(['ventas' => 0, 'comision_coef' => 0, 'comision' => 0]);
  }

  $sql = "
    SELECT v.{$colVentaMonto} AS ventas
    FROM {$t_ventas} v
    WHERE v.{$colVentaLoc} = %d
      AND v.{$colVentaAnio} = %d
      AND v.{$colVentaMes} = %d
    LIMIT 1
  ";

  $row = $wpdb->get_row($wpdb->prepare($sql, $local_id, $anio, $mes), ARRAY_A);

  $ventas = 0;
  if ($row && isset($row['ventas'])){
    $ventas = floatval(str_replace(',', '.', (string)$row['ventas']));
  }

  $row = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT r.{$colRendComision} AS comision_coef
       FROM {$t_rend} r
       WHERE r.{$colRendLoc} = %d
         AND r.{$colRendAnio} = %d
         AND r.{$colRendMes} = %d
       LIMIT 1",
      $local_id,
      $anio,
      $mes
    ),
    ARRAY_A
  );

  $comision_coef = 0;
  if ($row && isset($row['comision_coef'])){
    $comision_coef = floatval(str_replace(',', '.', (string)$row['comision_coef']));
  }

  $comision = $ventas * $comision_coef;

  wp_send_json_success([
    'ventas' => $ventas,
    'comision_coef' => $comision_coef,
    'comision' => $comision,
  ]);
}
public function ajax_save_desempeno_item(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido']);
  }
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  $legajo = isset($_POST['legajo']) ? intval($_POST['legajo']) : 0;
  $mes = isset($_POST['mes']) ? sanitize_text_field($_POST['mes']) : '';
  $desempeno = isset($_POST['desempeno']) ? floatval(str_replace(',', '.', $_POST['desempeno'])) : null;
  $inasistencias = isset($_POST['inasistencias']) ? wp_unslash($_POST['inasistencias']) : '';

  if ($legajo <= 0) wp_send_json_error(['message' => 'Legajo inválido']);
  if ($mes === '') wp_send_json_error(['message' => 'Mes requerido']);

  // Normalizamos mes a formato YYYY-MM (y luego lo almacenamos como YYYY-MM-01)
  if (preg_match('/^(\d{4})-(\d{2})/', $mes, $m)) {
    $mes = $m[1] . '-' . $m[2];
  } elseif (preg_match('/^(\d{2})\/(\d{4})$/', $mes, $m)) {
    $mes = $m[2] . '-' . $m[1];
  } else {
    wp_send_json_error(['message' => 'Formato de mes inválido']);
  }

  // Para evitar que MySQL convierta a 0000-00-00 cuando la columna es DATE,
  // guardamos el mes como el primer día del mes.
  $mes_store = $mes . '-01';

  // Inasistencias: se guarda como JSON array string
  $arr = [];
  if ($inasistencias !== '') {
    $decoded = json_decode($inasistencias, true);
    if (is_array($decoded)) {
      $arr = $decoded;
    } else {
      // fallback: "dd/mm/yyyy,dd/mm/yyyy"
      $arr = array_filter(array_map('trim', explode(',', (string)$inasistencias)));
    }
  }
  $inasistencias_json = wp_json_encode(array_values($arr));

  global $wpdb;
  $table = $this->desempeno_table();

  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists !== $table) {
    wp_send_json_error(['message' => 'La tabla de desempeño no existe']);
  }

  // Si ya existe ese mes para ese legajo, lo actualizamos (evitamos duplicados)
  // Compatibilidad: si hay registros viejos guardados como YYYY-MM, también los detectamos.
  $existing_id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$table} WHERE legajo=%d AND (mes=%s OR mes=%s) LIMIT 1",
    $legajo,
    $mes,
    $mes_store
  ));

  if ($existing_id) {
    $ok = $wpdb->update(
      $table,
      [
        'desempeno' => $desempeno,
        'inasistencias' => $inasistencias_json
      ],
      ['id' => intval($existing_id)],
      ['%f','%s'],
      ['%d']
    );
    if ($ok === false) wp_send_json_error(['message' => 'No se pudo actualizar']);
    wp_send_json_success(['id' => intval($existing_id), 'updated' => 1]);
  }

  $ok = $wpdb->insert(
    $table,
    [
      'legajo' => $legajo,
        'mes' => $mes_store,
      'desempeno' => $desempeno,
      'inasistencias' => $inasistencias_json
    ],
    ['%d','%s','%f','%s']
  );

  if (!$ok) {
    wp_send_json_error(['message' => 'No se pudo insertar']);
  }

  wp_send_json_success(['id' => intval($wpdb->insert_id), 'created' => 1]);
}

public function ajax_delete_desempeno_item(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido']);
  }
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
  if ($id <= 0) wp_send_json_error(['message' => 'ID inválido']);

  global $wpdb;
  $table = $this->desempeno_table();

  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists !== $table) {
    wp_send_json_error(['message' => 'La tabla de desempeño no existe']);
  }

  $ok = $wpdb->delete($table, ['id' => $id], ['%d']);
  if ($ok === false) {
    wp_send_json_error(['message' => 'No se pudo eliminar']);
  }

  wp_send_json_success(['deleted' => 1]);
}








public function ajax_save_sueldo_item(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido']);
  }
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  $inicio = sanitize_text_field($_POST['periodo_inicio'] ?? '');
$fin    = sanitize_text_field($_POST['periodo_fin'] ?? '');
$rol    = sanitize_text_field($_POST['rol'] ?? '');
$area   = sanitize_text_field($_POST['area'] ?? '');

if (!$inicio || !$fin) {
  wp_send_json_error(['message' => 'Faltan fechas del período.']);
}

$di = strtotime($inicio);
$df = strtotime($fin);
$hoy = strtotime(date('Y-m-d'));

if ($di > $df) {
  wp_send_json_error(['message' => 'Fecha inicio mayor a fecha fin.']);
}

if (date('Y-m', $di) !== date('Y-m', $df)) {
  wp_send_json_error(['message' => 'El período debe ser del mismo mes.']);
}

if ($di > $hoy || $df > $hoy) {
  wp_send_json_error(['message' => 'No se permiten fechas futuras.']);
}

$limite = strtotime(date('Y-m-01', strtotime('-3 months')));
if ($di < $limite) {
  wp_send_json_error(['message' => 'El período supera los 3 meses permitidos.']);
}

if (!$rol || !$area) {
  wp_send_json_error(['message' => 'Rol y área son obligatorios.']);
}

  $id     = isset($_POST['id']) ? intval($_POST['id']) : 0;
  $legajo = isset($_POST['legajo']) ? intval($_POST['legajo']) : 0;

  $periodo_inicio = isset($_POST['periodo_inicio']) ? sanitize_text_field($_POST['periodo_inicio']) : '';
  $periodo_fin    = isset($_POST['periodo_fin']) ? sanitize_text_field($_POST['periodo_fin']) : '';

  // Días de trabajo (0-99.99) permitido decimal
  $dias_raw = isset($_POST['dias_de_trabajo']) ? sanitize_text_field($_POST['dias_de_trabajo']) : '';
  $dias_raw = trim($dias_raw);
  $dias_raw = str_replace(',', '.', $dias_raw);
  if ($dias_raw === '') {
    $dias_de_trabajo = 0;
  } else {
    if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $dias_raw)) {
      wp_send_json_error(['message' => 'Dias Trab. inválido (máx 2 dígitos y decimal opcional).']);
    }
    $dias_de_trabajo = floatval($dias_raw);
    if ($dias_de_trabajo < 0 || $dias_de_trabajo >= 100) {
      wp_send_json_error(['message' => 'Dias Trab. fuera de rango (0 a 99,99).']);
    }
  }


  if ($legajo <= 0) wp_send_json_error(['message' => 'Legajo inválido']);
  if (!$periodo_inicio || !$periodo_fin) wp_send_json_error(['message' => 'Periodo inicio/fin son obligatorios']);

  // validación simple YYYY-MM-DD
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodo_inicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodo_fin)) {
    wp_send_json_error(['message' => 'Formato de fecha inválido']);
  }
  if (strtotime($periodo_inicio) > strtotime($periodo_fin)) {
    wp_send_json_error(['message' => 'El periodo inicio no puede ser mayor al fin']);
  }

  $rol      = isset($_POST['rol']) ? sanitize_text_field($_POST['rol']) : '';
  // Participación: decimal 0.00 a 1.00 (paso 0.05 en la UI)
  $participacion = isset($_POST['participacion']) ? floatval(str_replace(',', '.', sanitize_text_field($_POST['participacion']))) : 0;
  if ($participacion < 0) $participacion = 0;
  if ($participacion > 1) $participacion = 1;

  $area = isset($_POST['area']) ? sanitize_text_field($_POST['area']) : '';

  $horas_raw = sanitize_text_field($_POST['horas'] ?? '');
  $horas_raw = str_replace(',', '.', $horas_raw);
  $horas = floatval($horas_raw);
  if ($horas < 0) $horas = 0;

  // Dinero: viene como string, convertimos a número (float)
  $to_num = function($v){
    $v = is_string($v) ? $v : '';
    $v = str_replace(['$', ' ', '.'], ['', '', ''], $v); // sacamos separadores miles
    $v = str_replace(',', '.', $v); // coma decimal -> punto
    if ($v === '' || $v === null) return 0;
    return floatval($v);
  };


  $efectivo     = $to_num($_POST['efectivo'] ?? '');
  $transferencia = $to_num($_POST['transferencia'] ?? '');
  $creditos      = $to_num($_POST['creditos'] ?? '');
  $bono          = $to_num($_POST['bono'] ?? '');
  $descuentos    = $to_num($_POST['descuentos'] ?? '');
  $liquidacion   = $to_num($_POST['liquidacion'] ?? '');

  $jornada  = $to_num($_POST['jornada'] ?? '');
  $vac_tomadas      = $to_num($_POST['vac_tomadas'] ?? '');
  $feriados         = $to_num($_POST['feriados'] ?? '');
  $vac_no_tomadas   = $to_num($_POST['vac_no_tomadas'] ?? '');

  global $wpdb;
  $table = $this->sueldos_items_table();

  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists !== $table) {
    wp_send_json_error(['message' => 'Tabla de sueldos no existe']);
  }

  $data = [
    'legajo' => $legajo,
    'periodo_inicio' => $periodo_inicio,
    'periodo_fin' => $periodo_fin,
    'dias_de_trabajo' => $dias_de_trabajo,
    'rol' => $rol,
    'participacion' => $participacion,
    'area' => $area,
    'horas' => $horas,
    'efectivo' => $efectivo,
    'transferencia' => $transferencia,
    'creditos' => $creditos,
    'jornada' => $jornada,
    'bono' => $bono,
    'descuentos' => $descuentos,
    'vac_tomadas' => $vac_tomadas,
    'feriados' => $feriados,
    'liquidacion' => $liquidacion,
    'vac_no_tomadas' => $vac_no_tomadas
  ];
$formats = ['%d','%s','%s','%f','%s','%f','%s','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f'];

  if ($id > 0) {
    $ok = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
    if ($ok === false) wp_send_json_error(['message' => 'No se pudo actualizar']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, legajo, periodo_inicio, periodo_fin, dias_de_trabajo, rol, participacion, area, horas, efectivo, transferencia, creditos, jornada, bono, descuentos, vac_tomadas, feriados, liquidacion, vac_no_tomadas FROM {$table} WHERE id = %d", $id), ARRAY_A);
    wp_send_json_success(['row' => $row]);
  } else {
    $ok = $wpdb->insert($table, $data, $formats);
    if (!$ok) wp_send_json_error(['message' => 'No se pudo insertar']);
    $new_id = intval($wpdb->insert_id);
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, legajo, periodo_inicio, periodo_fin, dias_de_trabajo, rol, participacion, area, horas, efectivo, transferencia, creditos, jornada, bono, descuentos, vac_tomadas, feriados, liquidacion, vac_no_tomadas FROM {$table} WHERE id = %d", $new_id), ARRAY_A);
    wp_send_json_success(['row' => $row]);
  }
}


public function ajax_save_employee(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido']);
  }

  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  // Ajustá capability si querés (recomendado)
  // if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sin permisos']);

  global $wpdb;
  $table = $this->table_name();

  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists !== $table) {
    wp_send_json_error(['message' => 'Tabla no encontrada']);
  }

  $mode   = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : '';
  $nombre = isset($_POST['nombre']) ? sanitize_text_field($_POST['nombre']) : '';
  $telefono = isset($_POST['telefono']) ? sanitize_text_field($_POST['telefono']) : '';
$dni      = isset($_POST['dni']) ? sanitize_text_field($_POST['dni']) : '';
$cuil     = isset($_POST['cuil']) ? sanitize_text_field($_POST['cuil']) : '';
$obra     = isset($_POST['obra_social']) ? sanitize_text_field($_POST['obra_social']) : '';
$dir      = isset($_POST['direccion']) ? sanitize_text_field($_POST['direccion']) : '';
$ciudad   = isset($_POST['ciudad']) ? sanitize_text_field($_POST['ciudad']) : '';
$nac      = isset($_POST['fecha_nacimiento']) ? sanitize_text_field($_POST['fecha_nacimiento']) : '';
$ult      = isset($_POST['ultima_fecha_ingreso']) ? sanitize_text_field($_POST['ultima_fecha_ingreso']) : '';
$estado   = isset($_POST['estado']) ? sanitize_text_field($_POST['estado']) : 'ACTIVO';
$cbu      = isset($_POST['cbu']) ? sanitize_text_field($_POST['cbu']) : '';
$cbu      = preg_replace('/\D+/', '', $cbu);
$clover_employee_id = isset($_POST['clover_employee_id']) ? sanitize_text_field($_POST['clover_employee_id']) : '';
$clover_employee_id = trim($clover_employee_id);

// opcional (recomendado): normalizar espacios alrededor de comas
$clover_employee_id = preg_replace('/\s*,\s*/', ',', $clover_employee_id);

  $id     = isset($_POST['id']) ? intval($_POST['id']) : 0;

  if ($nombre === '') {
    wp_send_json_error(['message' => 'Nombre vacío']);
  }

  if ($mode === 'edit') {
    if ($id <= 0) wp_send_json_error(['message' => 'ID inválido']);

    $updated = $wpdb->update(
  $table,
  [
    'nombre' => $nombre,
    'telefono' => $telefono,
    'dni' => $dni,
    'cuil' => $cuil,
    'obra_social' => $obra,
    'direccion' => $dir,
    'ciudad' => $ciudad,
    'fecha_nacimiento' => $nac,
    'ultima_fecha_ingreso' => $ult,
    'estado' => $estado,
    'clover_employee_id' => $clover_employee_id,
    'cbu' => $cbu,
  ],
  ['id' => $id],
  ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'],
  ['%d']
);


    if ($updated === false) {
      wp_send_json_error(['message' => 'Error al actualizar']);
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if (!$row) wp_send_json_error(['message' => 'No encontrado']);

    wp_send_json_success(['emp' => $row]);
  }

  if ($mode === 'add') {
    // Legajo secuencial en servidor (seguro)
    $next = $this->max_legajo_numeric() + 1;

   $ok = $wpdb->insert(
  $table,
  [
    'nombre' => $nombre,
    'legajo' => (string)$next,
    'estado' => $estado ? $estado : 'ACTIVO',
    'telefono' => $telefono,
    'dni' => $dni,
    'cuil' => $cuil,
    'obra_social' => $obra,
    'direccion' => $dir,
    'ciudad' => $ciudad,
    'fecha_nacimiento' => $nac,
    'ultima_fecha_ingreso' => $ult,
    'clover_employee_id' => $clover_employee_id,
    'cbu' => $cbu,
  ],
  ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
);


    if (!$ok) {
      wp_send_json_error(['message' => 'Error al insertar']);
    }

    $new_id = intval($wpdb->insert_id);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $new_id), ARRAY_A);

    wp_send_json_success(['emp' => $row]);
  }

  wp_send_json_error(['message' => 'Modo inválido']);
}


  private function render_list($rows){
    if (!$rows) return '<div class="kolrrhh-muted">— Sin registros —</div>';

    $html = '';
    foreach ($rows as $e){
      $id     = (int)($e['id'] ?? 0);
      $nombre = (string)($e['nombre'] ?? '');
      $legajo = (string)($e['legajo'] ?? '');
      $estado = (string)($e['estado'] ?? '');
      $ini    = $this->initials($nombre);

      $payload = [
        'id' => $id,
        'nombre' => $nombre,
        'legajo' => $legajo,
        'estado' => $estado,
        'telefono' => (string)($e['telefono'] ?? ''),
        'dni' => (string)($e['dni'] ?? ''),
        'cuil' => (string)($e['cuil'] ?? ''),
        'obra_social' => (string)($e['obra_social'] ?? ''),
        'categoria' => (string)($e['categoria'] ?? ''),
        'direccion' => (string)($e['direccion'] ?? ''),
        'ciudad' => (string)($e['ciudad'] ?? ''),
        'fecha_nacimiento' => (string)($e['fecha_nacimiento'] ?? ''),
        'ultima_fecha_ingreso' => (string)($e['ultima_fecha_ingreso'] ?? ''),
        'vinculo_para_antiguedad' => (string)($e['vinculo_para_antiguedad'] ?? ''),
        'clover_employee_id' => (string)($e['clover_employee_id'] ?? ''),
        'cbu' => (string)($e['cbu'] ?? ''),
      ];

      $html .= sprintf(
        '<button class="kolrrhh-item" type="button" data-emp="%s">
          <span class="kolrrhh-leftcol">
            <span class="kolrrhh-avatar" aria-hidden="true">%s</span>
            <span class="kolrrhh-meta">
              <span class="kolrrhh-name">%s</span>
              <span class="kolrrhh-sub">Legajo %s · %s</span>
            </span>
          </span>
          <span class="kolrrhh-right-actions">
            <span class="kolrrhh-pill %s">%s</span>
            <span class="kolrrhh-edit-icon" role="button" tabindex="0" title="Editar" data-action="edit" data-emp-id="%d">✏</span>
          </span>
        </button>',
        esc_attr(wp_json_encode($payload, JSON_UNESCAPED_UNICODE)),
        esc_html($ini),
        esc_html($nombre),
        esc_html(str_pad(preg_replace('/\D+/', '', $legajo), 4, '0', STR_PAD_LEFT)),
        esc_html($estado ?: '—'),
        (strtoupper(trim($estado)) === 'ACTIVO') ? 'is-ok' : 'is-off',
        esc_html($estado ?: '—'),
        $id
      );
    }
    return $html;
  }
  private function load_clover_secrets(){
    // Soporta varios formatos de clover_secrets.php:
    // 1) return ['client_id'=>..., 'client_secret'=>..., 'refresh_token'=>...]
    // 2) define('CLOVER_CLIENT_ID', ...), etc.
    // 3) $client_id = '...'; $client_secret='...'; $refresh_token='...';
	    // rtrim con / y \\ (escape correcto)
	    $cloverBase = rtrim(ABSPATH, '/\\') . '/clover/';
    $path = $cloverBase . 'clover_secrets.php';
    if (!file_exists($path)) {
      return ['ok'=>false, 'message'=>"No existe clover_secrets.php en /clover/."];
    }

    $included = @include $path;

    $cid = null; $csec = null; $rtok = null;

    if (is_array($included)) {
      // acepta snake_case y camelCase
      $cid  = $included['client_id'] ?? ($included['clientId'] ?? null);
      $csec = $included['client_secret'] ?? ($included['clientSecret'] ?? null);
      $rtok = $included['refresh_token'] ?? ($included['refreshToken'] ?? null);
    }

    // Si el archivo no "returnea" un array, puede definir variables/constantes:
    if (!$cid && defined('CLOVER_CLIENT_ID'))     $cid  = constant('CLOVER_CLIENT_ID');
    if (!$csec && defined('CLOVER_CLIENT_SECRET')) $csec = constant('CLOVER_CLIENT_SECRET');
    if (!$rtok && defined('CLOVER_REFRESH_TOKEN')) $rtok = constant('CLOVER_REFRESH_TOKEN');

    // Variables en scope global (si el include las seteó)
    if (!$cid && isset($GLOBALS['client_id'])) $cid = $GLOBALS['client_id'];
    if (!$csec && isset($GLOBALS['client_secret'])) $csec = $GLOBALS['client_secret'];
    if (!$rtok && isset($GLOBALS['refresh_token'])) $rtok = $GLOBALS['refresh_token'];

    // También soporta $secrets = [...]
    if ((!$cid || !$csec) && isset($GLOBALS['secrets']) && is_array($GLOBALS['secrets'])) {
      $s = $GLOBALS['secrets'];
      if (!$cid)  $cid  = $s['client_id'] ?? ($s['clientId'] ?? null);
      if (!$csec) $csec = $s['client_secret'] ?? ($s['clientSecret'] ?? null);
      if (!$rtok) $rtok = $s['refresh_token'] ?? ($s['refreshToken'] ?? null);
    }

    if (!$cid || !$csec) {
      return ['ok'=>false, 'message'=>"Faltan credenciales en clover_secrets.php (client_id / client_secret)."];
    }

    return ['ok'=>true, 'client_id'=>$cid, 'client_secret'=>$csec, 'refresh_token'=>$rtok];
  }

  private function clover_http_json($url, $payload, $headers = []){
    $ch = curl_init($url);
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $h,
      CURLOPT_POSTFIELDS => wp_json_encode($payload),
      CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$http, $resp, $err];
  }

  private function clover_http_form($url, $payload, $headers = []){
  $ch = curl_init($url);

  $h = array_merge([
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json'
  ], $headers);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $h,
    CURLOPT_POSTFIELDS => http_build_query($payload, '', '&'),
    CURLOPT_TIMEOUT => 30,
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  return [$http, $resp, $err];
}


  private function clover_http_get($url, $headers = []){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPGET => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$http, $resp, $err];
  }

  private function ms_to_dt($ms){
    if (!$ms) return null;
    $sec = (int) floor(((int)$ms) / 1000);
    $dt = new DateTime("@{$sec}");
    $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
    return $dt;
  }

  private function fmt_hm($dt){
    return $dt ? $dt->format('H:i') : '';
  }

  private function fmt_day_key($dt){
    return $dt ? $dt->format('Y-m-d') : '';
  }

  private function day_label_es($dt){
    if (!$dt) return '';
    $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $idx = (int)$dt->format('w');
    return $dias[$idx] . ' ' . $dt->format('d/m');
  }

  private function human_duration($sec){
    $sec = max(0, (int)$sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) return sprintf('%dh %02dm', $h, $m);
    return sprintf('%dm', $m);
  }

  public function ajax_get_fichaje_html(){
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
      wp_send_json_error(['message' => 'Nonce inválido']);
    }

// Leer Clover ID del empleado seleccionado (formato: MerchantID;EmployeeID)
$legajo = isset($_POST['legajo']) ? intval($_POST['legajo']) : 0;
if ($legajo <= 0) {
  wp_send_json_error(['message' => 'Seleccioná un empleado primero.']);
}

global $wpdb;
$empTable = $this->table_name();

$cloverIdRaw = $wpdb->get_var($wpdb->prepare(
  "SELECT clover_employee_id FROM {$empTable} WHERE CAST(legajo AS UNSIGNED) = %d OR legajo = %s LIMIT 1",
  $legajo,
  (string)$legajo
));

$cloverIdRaw = trim((string)$cloverIdRaw);
if ($cloverIdRaw === '') {
  wp_send_json_error(['message' => 'Este empleado no tiene Clover ID cargado. Editalo y completá Clover ID con el formato MerchantID;EmployeeID.']);
}

// Clover puede venir con 1 o varios pares separados por coma: MerchantID;EmployeeID
$pairsRaw = array_filter(array_map('trim', explode(',', $cloverIdRaw)));
$pairs = [];
foreach ($pairsRaw as $p){
  $pp = array_map('trim', explode(';', $p));
  if (count($pp) !== 2) continue;
  if ($pp[0] === '' || $pp[1] === '') continue;
  $pairs[] = ['merchant' => $pp[0], 'employee' => $pp[1]];
}
if (empty($pairs)){
  wp_send_json_error(['message' => 'Clover ID inválido. Formato esperado: MerchantID;EmployeeID (ej: DH84CJ0QBWFB1;1702STFCB7TC4).']);
}

// Merchant seleccionado (opcional)
$requestedMerchant = isset($_POST['merchant_id']) ? sanitize_text_field($_POST['merchant_id']) : '';
$merchantId = '';
$employeeId = '';

if ($requestedMerchant !== '') {
  foreach ($pairs as $pair){
    if ($pair['merchant'] === $requestedMerchant){
      $merchantId = $pair['merchant'];
      $employeeId = $pair['employee'];
      break;
    }
  }
  if ($merchantId === '' || $employeeId === ''){
    wp_send_json_error(['message' => 'El comercio seleccionado no coincide con el Clover ID de este empleado. Revisá el campo Clover ID.']);
  }
} else {
  if (count($pairs) > 1){
    wp_send_json_error(['message' => 'Este empleado tiene más de un comercio. Seleccioná el Comercio antes de visualizar el fichaje.']);
  }
  $merchantId = $pairs[0]['merchant'];
  $employeeId = $pairs[0]['employee'];
}


    // Mes seleccionado (formato esperado: YYYY-MM). Si viene vacío, usamos el mes actual.
    $month = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : '';
    if (!$month) {
      $month = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m');
    }
$secrets = $this->load_clover_secrets();
    if (empty($secrets['ok'])) {
      wp_send_json_error(['message' => $secrets['message'] ?? 'Error leyendo clover_secrets.php']);
    }

    $clientId = $secrets['client_id'];
    $clientSecret = $secrets['client_secret'];

    // URL para “reconectar” un merchant (volver a iniciar OAuth) sin copiar/pegar.
    // Lo usamos para mostrar un botón cuando falla el refresh_token.
    $redirectUri = home_url('/clover/callback');
    $build_reconnect_url = function($mid) use ($clientId, $redirectUri) {
      $mid = trim((string)$mid);
      if ($mid === '') return '';
      return 'https://www.la.clover.com/oauth/v2/merchants/' . rawurlencode($mid)
        . '?client_id=' . rawurlencode($clientId)
        . '&redirect_uri=' . rawurlencode($redirectUri)
        . '&response_type=code'
        . '&state=' . rawurlencode($mid);
    };

// Tokens por merchant (como get_shifts_always.php)
	$cloverBase = rtrim(ABSPATH, '/\\') . '/clover/';
$tokensFile = $cloverBase . 'clover_tokens.json';
if (!file_exists($tokensFile)) {
  wp_send_json_error(['message' => 'No se encontró clover_tokens.json en /clover/.']);
}

$load_tokens_file = function($path) {
  if (!file_exists($path)) return [];
  $raw = file_get_contents($path);
  if ($raw === false) return [];

  // Soporta JSON con BOM, comentarios // o /* */, y comas colgantes
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM
  $raw = preg_replace('!\/\*.*?\*\/!s', '', $raw);
  $raw = preg_replace('/\/\/.*$/m', '', $raw);
  $raw = preg_replace('/,\s*([\]}])/m', '$1', $raw);

  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
};
$save_tokens_file = function($path, $all) {
  file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
};

$get_access_token_for_merchant = function($merchantId) use ($clientId, $clientSecret, $tokensFile, $load_tokens_file, $save_tokens_file) {
  $all = $load_tokens_file($tokensFile);
  $tok = $all[$merchantId] ?? null;

  if (!$tok || empty($tok['refresh_token'])) {
    return [false, null, "No hay refresh_token guardado para el merchant {$merchantId}. Re-conectá ese merchant con el flujo OAuth para regenerarlo."];
  }

  $now = time();
  $access = $tok['access_token'] ?? null;
  $accessExp = (int)($tok['access_token_expiration'] ?? 0);
  $refreshExp = (int)($tok['refresh_token_expiration'] ?? 0);

  // Si el refresh token ya venció (Clover suele rotarlo o invalidarlo), hay que re-conectar ese merchant.
  if ($refreshExp && $refreshExp <= $now + 60) {
    return [false, null, "El refresh_token del merchant {$merchantId} está vencido o por vencer. Re-conectá ese merchant con el flujo OAuth para regenerarlo."];
  }

  // Si no hay access o está por vencer -> refrescar
  // IMPORTANTE: Clover OAuth espera x-www-form-urlencoded. Usamos /oauth/v2/token con grant_type=refresh_token.
  if (!$access || ($accessExp && $accessExp <= $now + 120)) {

    $payload = [
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
      'refresh_token' => $tok['refresh_token'],
      'grant_type' => 'refresh_token',
    ];

    list($httpR, $respR, $errR) = $this->clover_http_json('https://api.la.clover.com/oauth/v2/token', $payload);
    // Compat: algunos entornos aceptan JSON (y rechazan form). Si devuelve 415, reintentamos como x-www-form-urlencoded.
    if ((int)$httpR === 415) {
      list($httpR, $respR, $errR) = $this->clover_http_form('https://api.la.clover.com/oauth/v2/token', $payload);
    }
    $dataR = json_decode($respR ?? '', true);

    if (!($httpR >= 200 && $httpR < 300) || !is_array($dataR) || empty($dataR['access_token'])) {
      $msg = "Refresh token falló para merchant {$merchantId}. Probablemente rotó/expiró. Re-conectá el merchant.";
      $debug = [
        'try_token_http' => $httpR,
        'try_token_resp' => $respR,
        'curl_err' => $errR,
      ];
      return [false, null, $msg . " DEBUG=" . json_encode($debug)];
    }

    // Guardar tokens (y refresh_token rotado si viene)
    $tok['access_token'] = $dataR['access_token'];
    if (!empty($dataR['access_token_expiration'])) $tok['access_token_expiration'] = (int)$dataR['access_token_expiration'];
    if (!empty($dataR['refresh_token'])) $tok['refresh_token'] = $dataR['refresh_token'];
    if (!empty($dataR['refresh_token_expiration'])) $tok['refresh_token_expiration'] = (int)$dataR['refresh_token_expiration'];

    $tok['updated_at'] = time();
    $all[$merchantId] = $tok;
    $save_tokens_file($tokensFile, $all);

    $access = $tok['access_token'];
  }

  return [true, $access, null];
};

// 1) obtener access_token válido para el merchant
list($okTok, $accessToken, $errTok) = $get_access_token_for_merchant($merchantId);
if (!$okTok) {
  $reconnectUrl = $build_reconnect_url($merchantId);
  wp_send_json_error([
    'message' => $errTok,
    'merchant_id' => $merchantId,
    'reconnect_url' => $reconnectUrl,
  ]);
}


    // 2) get shifts
    $shiftsUrl = "https://api.la.clover.com/v3/merchants/{$merchantId}/employees/{$employeeId}/shifts";
    list($httpS, $respS, $errS) = $this->clover_http_get($shiftsUrl, [
      "Authorization: Bearer {$accessToken}",
      "Accept: application/json"
    ]);

    $dataS = json_decode($respS ?? '', true);
    if ($httpS < 200 || $httpS >= 300 || !is_array($dataS)) {
      wp_send_json_error([
        'message' => 'Shifts request failed',
        'http' => $httpS,
        'resp' => $respS,
        'curl_err' => $errS
      ]);
    }

    $elements = $dataS['elements'] ?? [];
    $items = [];

    foreach ($elements as $s) {
      $inTime  = $s['inTime'] ?? null;
      $outTime = $s['outTime'] ?? null;
      if (!$inTime) continue;

      $inDt  = $this->ms_to_dt($inTime);
      $outDt = $outTime ? $this->ms_to_dt($outTime) : null;

      

      // Filtrar por mes seleccionado
      if ($inDt && $inDt->format('Y-m') !== $month) {
        continue;
      }
$dayKey = $this->fmt_day_key($inDt);

      $durSec = 0;
      if ($inDt && $outDt) {
        $durSec = max(0, $outDt->getTimestamp() - $inDt->getTimestamp());
      }

      $items[] = [
        'day_key' => $dayKey,
        'in_dt'   => $inDt,
        'out_dt'  => $outDt,
        'dur_sec' => $durSec,
      ];
    }

    // group by day
    $byDayMap = [];
    foreach ($items as $it) {
      $k = $it['day_key'] ?: 's/d';
      if (!isset($byDayMap[$k])) {
        $byDayMap[$k] = [
          'day_key' => $k,
          'day_label' => $it['in_dt'] ? $this->day_label_es($it['in_dt']) : $k,
          'items' => [],
          'total_sec' => 0,
        ];
      }
      $byDayMap[$k]['items'][] = $it;
      $byDayMap[$k]['total_sec'] += (int)$it['dur_sec'];
    }

    // sort days desc (latest first)
    krsort($byDayMap);
    $byDay = array_values($byDayMap);

    ob_start(); ?>
      <div class="kolrrhh-fichaje">
        <div class="kolrrhh-fichaje-head">
          <div>
            <div class="pill">Merchant: <?php echo esc_html($merchantId); ?></div>
            <div class="pill">Employee: <?php echo esc_html($employeeId); ?></div>
          </div>
          <div class="pill">Timezone: America/Argentina/Buenos_Aires</div>
          <div class="pill">Mes: <?php echo esc_html($month); ?></div>
        </div>

        <table class="kolrrhh-fichaje-table">
          <thead>
            <tr>
              <th style="width:160px;">Día</th>
              <th>Turnos</th>
              <th style="width:140px;">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($byDay)): ?>
              <tr><td colspan="3">No hay turnos con inTime/outTime.</td></tr>
            <?php else: ?>
              <?php foreach ($byDay as $day): ?>
                <tr>
                  <td class="day"><?php echo esc_html($day['day_label']); ?></td>
                  <td>
                    <?php
                      usort($day['items'], function($a,$b){
                        return $a['in_dt']->getTimestamp() <=> $b['in_dt']->getTimestamp();
                      });
                    ?>
                    <?php foreach ($day['items'] as $it): ?>
                      <div class="rowshift">
                        <div>
                          <div class="time"><?php echo esc_html($this->fmt_hm($it['in_dt'])); ?></div>
                        </div>
                        <div class="arrow">→</div>
                        <div>
                          <div class="time"><?php echo $it['out_dt'] ? esc_html($this->fmt_hm($it['out_dt'])) : '—'; ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </td>
                  <td class="total"><?php echo esc_html($this->human_duration($day['total_sec'])); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
  }

  public function ajax_print_sueldo_item(){
  if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'kol_rrhh_nonce')) {
    wp_die('Nonce inválido');
  }
  if (!is_user_logged_in()) wp_die('No autorizado');

  $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
  if ($id <= 0) wp_die('ID inválido');

  global $wpdb;
  $table = $this->sueldos_items_table();

  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
  if (!$row) wp_die('Item no encontrado');

  // Buscar datos básicos del empleado por legajo (ajustá si tu tabla tiene otro nombre)
  $emp = $wpdb->get_row($wpdb->prepare(
    "SELECT nombre, legajo, dni, cuil FROM {$this->table_name()} WHERE legajo=%d LIMIT 1",
    intval($row['legajo'])
  ), ARRAY_A);

  $nombre = $emp['nombre'] ?? '—';
  $legajo = $emp['legajo'] ?? ($row['legajo'] ?? '—');

  // helpers
  $fmt = function($n){
    $n = (float)$n;
    return '$' . number_format($n, 0, ',', '.'); // sin centavos para que quede como tus pantallas
  };

  $periodo_inicio = $row['periodo_inicio'] ?? '';
  $periodo_fin    = $row['periodo_fin'] ?? '';
  $mes_label = $periodo_inicio ? strtoupper(date_i18n('F Y', strtotime($periodo_inicio))) : strtoupper(date_i18n('F Y'));

  $efectivo = (float)($row['efectivo'] ?? 0);
  $transfer = (float)($row['transferencia'] ?? 0);
  $creditos = (float)($row['creditos'] ?? 0);

  $total_pago = $efectivo + $transfer + $creditos;

  header('Content-Type: text/html; charset=UTF-8');

  echo $this->render_print_html([
    'nombre' => $nombre,
    'legajo' => $legajo,
    'mes_label' => $mes_label,
    'periodo_inicio' => $periodo_inicio,
    'periodo_fin' => $periodo_fin,
    'rol' => $row['rol'] ?? '',
    'area' => $row['area'] ?? '',
    'efectivo' => $efectivo,
    'transferencia' => $transfer,
    'creditos' => $creditos,
    'total_pago' => $total_pago,
    'fmt' => $fmt,
    'row' => $row,
  ]);

  exit;
}

private function render_print_html($d){
  $fmt = $d['fmt'];
  $row = $d['row'];

  // Solo mostramos filas si hay dato (para tu idea “lo que no tengo no lo coloco”)
  $maybeRow = function($label, $value) use ($fmt){
    if ($value === null || $value === '' || (is_numeric($value) && (float)$value == 0)) return '';
    $v = is_numeric($value) ? $fmt($value) : esc_html($value);
    return "<tr><td>{$label}</td><td class='right'>{$v}</td></tr>";
  };

  ob_start(); ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Recibo <?php echo esc_html($d['nombre']); ?> - <?php echo esc_html($d['mes_label']); ?></title>
  <style>
    /* ====== Print setup ====== */
    @page { size: A4; margin: 14mm; }
    body { font-family: Arial, Helvetica, sans-serif; color:#111; font-size: 12px; }
    .sheet { max-width: 780px; margin: 0 auto; }
    .top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; border-bottom:1px solid #ddd; padding-bottom:12px; margin-bottom:14px; }
    .brand { font-weight: 800; font-size: 14px; letter-spacing:.02em; }
    .muted { color:#555; }
    .h1 { font-weight: 900; font-size: 16px; margin: 2px 0 4px; }
    .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; margin-top:10px; }
    .kv { border:1px solid #e2e2e2; border-radius:10px; padding:10px 12px; }
    .kv .k { font-weight: 800; color:#555; font-size: 11px; text-transform: uppercase; letter-spacing:.06em; margin-bottom:4px; }
    .kv .v { font-weight: 800; font-size: 13px; }
    .section { margin-top: 14px; }
    .section-title { font-weight: 900; text-transform: uppercase; letter-spacing:.08em; font-size: 11px; color:#666; margin-bottom:8px; }
    table { width:100%; border-collapse: collapse; }
    th, td { border:1px solid #e6e6e6; padding:10px; }
    th { background:#f6f6f6; font-weight: 900; text-transform: uppercase; font-size: 11px; letter-spacing:.06em; }
    .right { text-align:right; }
    .center { text-align:center; }
    .note { margin-top: 12px; line-height: 1.45; }
    .sign { display:flex; justify-content:space-between; gap: 18px; margin-top: 200px; }
    .line { width: 48%; border-top: 1px solid #111; padding-top: 6px; text-align:center; font-weight:700; }
    .small { font-size: 11px; }
    .printbar { display:flex; justify-content:flex-end; margin: 8px 0 14px; }
    .btn { background:#111; color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:800; cursor:pointer; }
    @media print {
      .printbar { display:none; }
      body { margin: 0; }
    }
  </style>
</head>
<body>
  <div class="sheet page">
  <div class="sheet">

    <div class="printbar">
      <button class="btn" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <div class="top">
      <div>
        <div class="brand">KOL ACCESORIOS</div>
        <div class="h1"><?php echo esc_html($d['nombre']); ?></div>
        <div class="muted">Legajo: <strong><?php echo esc_html($d['legajo']); ?></strong></div>
      </div>
      <div class="muted">
        <div><strong>Período:</strong> <?php echo esc_html($d['mes_label']); ?></div>
        <?php if ($d['periodo_inicio'] && $d['periodo_fin']): ?>
          <div class="small"><?php echo esc_html($d['periodo_inicio']); ?> → <?php echo esc_html($d['periodo_fin']); ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid2">
      <?php if (!empty($d['area'])): ?>
        <div class="kv"><div class="k">Área / Local</div><div class="v"><?php echo esc_html($d['area']); ?></div></div>
      <?php endif; ?>
      <?php if (!empty($d['rol'])): ?>
        <div class="kv"><div class="k">Rol</div><div class="v"><?php echo esc_html($d['rol']); ?></div></div>
      <?php endif; ?>
    </div>

    <div class="section">
      <div class="section-title">Pago</div>
      <table>
        <thead>
          <tr>
            <th class="center">Efectivo</th>
            <th class="center">Transferencia</th>
            <th class="center">Créditos</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="center"><?php echo $fmt($d['efectivo']); ?></td>
            <td class="center"><?php echo $fmt($d['transferencia']); ?></td>
            <td class="center"><?php echo $fmt($d['creditos']); ?></td>
          </tr>
        </tbody>
      </table>
      <div class="note"><strong>Total pagado:</strong> <?php echo $fmt($d['total_pago']); ?></div>
    </div>

    <div class="section">
      <div class="section-title">Detalles</div>
      <table>
        <thead>
          <tr><th>Concepto</th><th class="right">Monto</th></tr>
        </thead>
        <tbody>
          <?php
            echo $maybeRow('Jornada', $row['jornada'] ?? 0);
            echo $maybeRow('Bono', $row['bono'] ?? 0);
            echo $maybeRow('Descuentos', $row['descuentos'] ?? 0);
            echo $maybeRow('Vac. tomadas', $row['vac_tomadas'] ?? 0);
            echo $maybeRow('Feriados', $row['feriados'] ?? 0);
            echo $maybeRow('Liquidación', $row['liquidacion'] ?? 0);
            echo $maybeRow('Vac. no tomadas', $row['vac_no_tomadas'] ?? 0);
          ?>
        </tbody>
      </table>
    </div>

    <div class="note">
      Yo <strong><?php echo esc_html($d['nombre']); ?></strong> recibí de <strong>KOL ACCESORIOS</strong>
      los haberes correspondientes al período <strong><?php echo esc_html($d['mes_label']); ?></strong>.
    </div>
    
    <div class="sign">
      <div class="line">Firma Empleado</div>
      <div class="line">Firma Empleador</div>
    </div>

  </div>
      </div>
</body>
</html>
<?php
  return ob_get_clean();
}


}

new KOL_RRHH_Plugin();