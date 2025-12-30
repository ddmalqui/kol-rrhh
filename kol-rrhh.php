<?php
/**
 * Plugin Name: KOL RRHH
 * Author: ddmalqui
 * Description: Panel simple de RRHH (listado de personal) para KOL. Shortcode: [kol_rrhh]
 * Version: 1.0.2
 */

if (!defined('ABSPATH')) exit;

final class KOL_RRHH_Plugin {
  const VERSION = '1.0.2';
  const SHORTCODE = 'kol_rrhh';

  public function __construct(){
    add_action('wp_enqueue_scripts', [$this,'register_assets']);
    add_action('admin_enqueue_scripts', [$this,'register_assets']);
    add_action('wp_ajax_kol_rrhh_save_employee', [$this,'ajax_save_employee']);
    add_action('wp_ajax_kol_rrhh_get_sueldo_items', [$this,'ajax_get_sueldo_items']);
    add_action('wp_ajax_kol_rrhh_save_sueldo_item', [$this,'ajax_save_sueldo_item']);
    add_action('wp_ajax_kol_rrhh_get_desempeno_items', [$this,'ajax_get_desempeno_items']);


    add_shortcode(self::SHORTCODE, [$this,'shortcode']);
  }

  public function register_assets(){
    $base = plugin_dir_url(__FILE__);
    wp_register_style('kol-rrhh-style', $base.'style.css', [], self::VERSION);
    wp_register_script('kol-rrhh-js', $base.'rrhh.js', ['jquery'], self::VERSION, true);
    wp_localize_script('kol-rrhh-js', 'KOL_RRHH', ['ajaxurl' => admin_url('admin-ajax.php'),'nonce'   => wp_create_nonce('kol_rrhh_nonce'),
]);

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
              <button type="button" class="kolrrhh-tab" data-tab="t3">Pestaña 3</button>
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
                <div class="kolrrhh-muted">— Espacio reservado para contenido —</div>
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
    <div class="kolrrhh-modal-top is-only-close">
      <button class="kolrrhh-modal-x" data-close="1" aria-label="Cerrar">×</button>
    </div>

    <div class="kolrrhh-modal-body">
      <div id="kolrrhh-sueldo-error" class="kolrrhh-form-error" style="display:none;"></div>

      <input type="hidden" id="kolrrhh-sueldo-id" value="0" />
      <input type="hidden" id="kolrrhh-sueldo-legajo" value="" />

            <div class="kolrrhh-form-row" style="--cols:3;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Fecha inicio *</label>
          <input id="kolrrhh-sueldo-periodo-inicio" type="date" class="kolrrhh-modal-input" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Fecha fin *</label>
          <input id="kolrrhh-sueldo-periodo-fin" type="date" class="kolrrhh-modal-input" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Rol</label>
          <input id="kolrrhh-sueldo-rol" type="text" class="kolrrhh-modal-input" maxlength="120" />
        </div>
      </div>

      <div class="kolrrhh-form-row" style="--cols:2;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Transferencia</label>
          <input id="kolrrhh-sueldo-transferencia" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Créditos</label>
          <input id="kolrrhh-sueldo-creditos" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
      </div>

      <div class="kolrrhh-form-row" style="--cols:4;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Jornada</label>
          <input id="kolrrhh-sueldo-jornada" type="text" class="kolrrhh-modal-input" maxlength="80" placeholder="Ej: Completa / Media" />
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
          <input id="kolrrhh-sueldo-vac-tomadas" type="number" min="0" step="1" class="kolrrhh-modal-input" />
        </div>
      </div>

      <div class="kolrrhh-form-row" style="--cols:3;">
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Feriados</label>
          <input id="kolrrhh-sueldo-feriados" type="number" min="0" step="1" class="kolrrhh-modal-input" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Liquidación</label>
          <input id="kolrrhh-sueldo-liquidacion" type="text" inputmode="decimal" class="kolrrhh-modal-input kolrrhh-money" />
        </div>
        <div class="kolrrhh-form-field">
          <label class="kolrrhh-modal-label">Vac. no tomadas</label>
          <input id="kolrrhh-sueldo-vac-no-tomadas" type="number" min="0" step="1" class="kolrrhh-modal-input" />
        </div>
      </div>
    </div>

    <div class="kolrrhh-modal-actions">
      <button type="button" class="kolrrhh-btn" data-close="1">Cancelar</button>
      <button type="button" class="kolrrhh-btn kolrrhh-btn-primary" id="kolrrhh-sueldo-save">Guardar</button>
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
      "SELECT id, legajo, periodo_inicio, periodo_fin, rol,
              transferencia, creditos, jornada, bono, descuentos, vac_tomadas, feriados, liquidacion, vac_no_tomadas
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
      "SELECT id, legajo, mes, desempeno, inasistencias
       FROM {$table}
       WHERE legajo = %d
       ORDER BY mes DESC, id DESC",
      $legajo
    ),
    ARRAY_A
  );

  wp_send_json_success(['rows' => $rows ?: []]);
}



public function ajax_save_sueldo_item(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kol_rrhh_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido']);
  }
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'No autorizado']);
  }

  $id     = isset($_POST['id']) ? intval($_POST['id']) : 0;
  $legajo = isset($_POST['legajo']) ? intval($_POST['legajo']) : 0;

  $periodo_inicio = isset($_POST['periodo_inicio']) ? sanitize_text_field($_POST['periodo_inicio']) : '';
  $periodo_fin    = isset($_POST['periodo_fin']) ? sanitize_text_field($_POST['periodo_fin']) : '';

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
  $jornada  = isset($_POST['jornada']) ? sanitize_text_field($_POST['jornada']) : '';

  // Dinero: viene como string, convertimos a número (float)
  $to_num = function($v){
    $v = is_string($v) ? $v : '';
    $v = str_replace(['$', ' ', '.'], ['', '', ''], $v); // sacamos separadores miles
    $v = str_replace(',', '.', $v); // coma decimal -> punto
    if ($v === '' || $v === null) return 0;
    return floatval($v);
  };

  $transferencia = $to_num($_POST['transferencia'] ?? '');
  $creditos      = $to_num($_POST['creditos'] ?? '');
  $bono          = $to_num($_POST['bono'] ?? '');
  $descuentos    = $to_num($_POST['descuentos'] ?? '');
  $liquidacion   = $to_num($_POST['liquidacion'] ?? '');

  $vac_tomadas      = isset($_POST['vac_tomadas']) ? intval($_POST['vac_tomadas']) : 0;
  $feriados         = isset($_POST['feriados']) ? intval($_POST['feriados']) : 0;
  $vac_no_tomadas   = isset($_POST['vac_no_tomadas']) ? intval($_POST['vac_no_tomadas']) : 0;

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
    'rol' => $rol,
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

  $formats = ['%d','%s','%s','%s','%f','%f','%s','%f','%f','%d','%d','%f','%d'];

  if ($id > 0) {
    $ok = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
    if ($ok === false) wp_send_json_error(['message' => 'No se pudo actualizar']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, legajo, periodo_inicio, periodo_fin, rol, transferencia, creditos, jornada, bono, descuentos, vac_tomadas, feriados, liquidacion, vac_no_tomadas FROM {$table} WHERE id = %d", $id), ARRAY_A);
    wp_send_json_success(['row' => $row]);
  } else {
    $ok = $wpdb->insert($table, $data, $formats);
    if (!$ok) wp_send_json_error(['message' => 'No se pudo insertar']);
    $new_id = intval($wpdb->insert_id);
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, legajo, periodo_inicio, periodo_fin, rol, transferencia, creditos, jornada, bono, descuentos, vac_tomadas, feriados, liquidacion, vac_no_tomadas FROM {$table} WHERE id = %d", $new_id), ARRAY_A);
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
  ],
  ['id' => $id],
  ['%s','%s','%s','%s','%s','%s','%s','%s'],
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
    'estado' => 'ACTIVO',
    'telefono' => $telefono,
    'dni' => $dni,
    'cuil' => $cuil,
    'obra_social' => $obra,
    'direccion' => $dir,
    'ciudad' => $ciudad,
    'fecha_nacimiento' => $nac,
  ],
  ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
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
}

new KOL_RRHH_Plugin();
