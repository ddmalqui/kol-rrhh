<?php
/**
 * Clover shifts -> HTML daily table (ALWAYS ON)
 * - Uses refresh_token stored per merchant in clover_tokens.json
 * - Auto-refreshes access_token
 * - Handles refresh_token rotation (saves new refresh_token if returned)
 *
 * Usage:
 *   /clover/get_shifts_always.php?merchant=DH84CJ0QBWFB1&employee=1702STFCB7TC4
 */

date_default_timezone_set('America/Argentina/Buenos_Aires');

$merchantId = trim((string)($_GET['merchant'] ?? 'DH84CJ0QBWFB1'));
$employeeId = trim((string)($_GET['employee'] ?? '1702STFCB7TC4'));

$secretsPath = __DIR__ . '/clover_secrets.php';
if (!file_exists($secretsPath)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Missing clover_secrets.php\n";
  exit;
}
$secrets = require $secretsPath;

$clientId     = $secrets['client_id'] ?? null;
$clientSecret = $secrets['client_secret'] ?? null;

if (!$clientId || !$clientSecret) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Missing client_id/client_secret in clover_secrets.php\n";
  exit;
}

$tokensFile = __DIR__ . '/clover_tokens.json';

function sanitize_json_relaxed($raw) {
  // Allows "// comments" and trailing commas in clover_tokens.json
  if ($raw === false || $raw === null) return '';
  // Remove UTF-8 BOM if present
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  // Remove // comments
  $raw = preg_replace('/\/\/.*$/m', '', $raw);
  // Remove trailing commas before } or ]
  $raw = preg_replace('/,\s*(\}|\])/m', '$1', $raw);
  return $raw;
}

function load_tokens_file($path) {
  if (!file_exists($path)) return [];
  $raw = file_get_contents($path);
  $raw = sanitize_json_relaxed($raw);
  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
}

function save_tokens_file($path, $all) {
  file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

function http_json($url, $payload, $headers = []) {
  $ch = curl_init($url);
  $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $h,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return [$http, $resp, $err];
}

function http_get($url, $headers = []) {
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

function ms_to_dt($ms) {
  $sec = (int) floor($ms / 1000);
  $dt = new DateTime("@$sec"); // UTC
  $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
  return $dt;
}
function fmt_hm($dt) { return $dt->format('H:i'); }
function fmt_day_key($dt) { return $dt->format('Y-m-d'); }

function day_label_es($dt) {
  $days = [
    'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles',
    'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
  ];
  $en = $dt->format('l');
  $es = $days[$en] ?? $en;
  return $es . ' ' . $dt->format('d');
}

function human_duration($seconds) {
  $seconds = max(0, (int)$seconds);
  $h = intdiv($seconds, 3600);
  $m = intdiv($seconds % 3600, 60);
  if ($h > 0) return sprintf('%dh %02dm', $h, $m);
  return sprintf('%dm', $m);
}

/**
 * Get a valid access token for merchant (refresh if needed).
 * Saves rotated refresh_token if returned.
 */
function get_access_token_for_merchant($merchantId, $clientId, $clientSecret, $tokensFile) {
  $merchantId = trim((string)$merchantId);
  $merchantId = trim((string)$merchantId);
  $all = load_tokens_file($tokensFile);
  $tok = $all[$merchantId] ?? null;

  if (!$tok || empty($tok['refresh_token'])) {
    return [false, null, "No refresh_token stored for merchant $merchantId. Reconnect this merchant via your OAuth connect flow."];
  }

  $now = time();
  $access = $tok['access_token'] ?? null;
  $accessExp = (int)($tok['access_token_expiration'] ?? 0);

  // If access missing or expiring soon -> refresh
  if (!$access || ($accessExp && $accessExp <= $now + 120)) {

    // Try /oauth/v2/refresh
    $payload = [
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
      'refresh_token' => $tok['refresh_token']
    ];
    list($httpR, $respR, $errR) = http_json('https://api.la.clover.com/oauth/v2/refresh', $payload);
    $dataR = json_decode($respR ?? '', true);

    // Fallback: /oauth/v2/token grant_type=refresh_token
    if (!($httpR >= 200 && $httpR < 300) || !is_array($dataR) || empty($dataR['access_token'])) {
      $payload2 = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $tok['refresh_token'],
        'grant_type' => 'refresh_token',
      ];
      list($httpR2, $respR2, $errR2) = http_json('https://api.la.clover.com/oauth/v2/token', $payload2);
      $dataR2 = json_decode($respR2 ?? '', true);

      if (!($httpR2 >= 200 && $httpR2 < 300) || !is_array($dataR2) || empty($dataR2['access_token'])) {
        // If refresh token is invalid -> force reconnect
        $msg = "Refresh token failed for merchant $merchantId. Likely rotated/invalid. Reconnect this merchant (OAuth) to store a new refresh_token.";
        $debug = [
          'try_refresh_http' => $httpR,
          'try_refresh_resp' => $respR,
          'try_token_http' => $httpR2,
          'try_token_resp' => $respR2,
        ];
        return [false, null, $msg . " DEBUG=" . json_encode($debug)];
      }

      $dataR = $dataR2;
    }

    // Save new tokens (and rotated refresh_token if present)
    $tok['access_token'] = $dataR['access_token'];
    if (!empty($dataR['access_token_expiration'])) $tok['access_token_expiration'] = (int)$dataR['access_token_expiration'];

    // IMPORTANT: refresh_token may rotate
    if (!empty($dataR['refresh_token'])) $tok['refresh_token'] = $dataR['refresh_token'];
    if (!empty($dataR['refresh_token_expiration'])) $tok['refresh_token_expiration'] = (int)$dataR['refresh_token_expiration'];

    $tok['updated_at'] = time();
    $all[$merchantId] = $tok;
    save_tokens_file($tokensFile, $all);

    $access = $tok['access_token'];
  }

  return [true, $access, null];
}


// ====== MAIN FLOW ======
/**
 * Supports:
 * - merchant + employee params (single pair), e.g. ?merchant=...&employee=...
 * - combo param with one or many pairs "MERCHANT;EMPLOYEE,MERCHANT;EMPLOYEE"
 *   e.g. ?combo=DH84CJ0QBWFB1;1702STFCB7TC4,D5SWREN9EV9D1;66AQCJVSGZBV0
 */
$pairs = [];

$comboStr = $_GET['combo'] ?? ($_GET['clover_employee_id'] ?? null);
if ($comboStr) {
  $chunks = array_filter(array_map('trim', explode(',', $comboStr)));
  foreach ($chunks as $ch) {
    $parts = array_map('trim', explode(';', $ch));
    if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
      $pairs[] = ['merchant' => $parts[0], 'employee' => $parts[1]];
    }
  }
}

// fallback: single pair from merchant/employee (or defaults)
if (count($pairs) === 0) {
  $pairs[] = ['merchant' => $merchantId, 'employee' => $employeeId];
}

$results = [];

foreach ($pairs as $p) {
  $mId = trim((string)$p['merchant']);
  $eId = trim((string)$p['employee']);

  $res = [
    'merchantId' => $mId,
    'employeeId' => $eId,
    'byDay' => [],
    'error' => null,
  ];

  // token for this merchant
  list($okTok, $accessToken, $errTok) = get_access_token_for_merchant($mId, $clientId, $clientSecret, $tokensFile);
  if (!$okTok) {
    $res['error'] = $errTok;
    $results[] = $res;
    continue;
  }

  // shifts for this employee (merchant-scoped)
  $shiftsUrl = "https://api.la.clover.com/v3/merchants/{$mId}/employees/{$eId}/shifts";
  list($httpS, $respS, $errS) = http_get($shiftsUrl, ["Authorization: Bearer {$accessToken}", "Accept: application/json"]);
  $dataS = json_decode($respS ?? '', true);

  if ($httpS < 200 || $httpS >= 300 || !is_array($dataS)) {
    $res['error'] = 'Shifts API error: HTTP ' . $httpS . ' ' . ($errS ? ('- ' . $errS) : '') . ' - ' . substr(($respS ?? ''), 0, 400);
    $results[] = $res;
    continue;
  }

  $elements = $dataS['elements'] ?? [];
  if (!is_array($elements)) $elements = [];

  // normalize shifts -> list
  $shifts = [];
  foreach ($elements as $e) {
    if (!isset($e['inTime'])) continue;
    $in = ms_to_dt($e['inTime']);
    $out = isset($e['outTime']) ? ms_to_dt($e['outTime']) : null;

    $shifts[] = [
      'id' => $e['id'] ?? null,
      'day_key' => fmt_day_key($in),
      'day_label' => day_label_es($in),
      'in_dt' => $in,
      'out_dt' => $out,
      'duration_sec' => ($out ? max(0, $out->getTimestamp() - $in->getTimestamp()) : 0),
    ];
  }

  // group by day
  $byDay = [];
  foreach ($shifts as $s) {
    $k = $s['day_key'];
    if (!isset($byDay[$k])) {
      $byDay[$k] = [
        'day_key' => $k,
        'day_label' => $s['day_label'],
        'items' => [],
        'total_sec' => 0
      ];
    }
    $byDay[$k]['items'][] = $s;
    $byDay[$k]['total_sec'] += $s['duration_sec'];
  }

  // sort days desc
  uksort($byDay, function($a, $b){ return strcmp($b, $a); });

  $res['byDay'] = $byDay;
  $results[] = $res;
}

// Render HTML
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Shifts</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:16px;background:#fafafa;color:#111}
  .card{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px;max-width:900px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px 8px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#666;text-align:left}
  .day{font-weight:700;font-size:18px}
  .rowshift{display:flex;gap:12px;align-items:center;margin:4px 0}
  .label{color:#666;font-size:12px}
  .time{font-weight:700;min-width:54px;display:inline-block}
  .arrow{color:#999}
  .total{font-weight:800}
  .pill{display:inline-block;padding:2px 8px;border:1px solid #eee;border-radius:999px;font-size:12px;color:#666;background:#fbfbfb}
</style>
</head>
<body>
<?php foreach ($results as $res): $byDay = $res['byDay']; ?>
  <div class="card" style="margin-bottom:14px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;">
      <div>
        <div class="pill">Merchant: <?=htmlspecialchars($res['merchantId'])?></div>
        <div class="pill">Employee: <?=htmlspecialchars($res['employeeId'])?></div>
      </div>
      <div class="pill">Timezone: America/Argentina/Buenos_Aires</div>
    </div>

<?php if (!empty($res['error'])): ?>
    <div style="margin:10px 0;padding:10px;border:1px solid #f1c0c0;background:#fff6f6;border-radius:12px;color:#8a1f1f;">
      <?=htmlspecialchars($res['error'])?>
    </div>
    <?php endif; ?>

    <table>
      <thead>
        <tr>
          <th style="width:160px;">Día</th>
          <th>Turnos</th>
          <th style="width:140px;">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($byDay) === 0): ?>
          <tr><td colspan="3">No hay turnos con inTime/outTime.</td></tr>
        <?php endif; ?>

        <?php foreach ($byDay as $day): ?>
          <tr>
            <td class="day"><?=htmlspecialchars($day['day_label'])?></td>
            <td>
              <?php
                usort($day['items'], function($a,$b){
                  return $a['in_dt']->getTimestamp() <=> $b['in_dt']->getTimestamp();
                });
              ?>
              <?php foreach ($day['items'] as $it): ?>
                <div class="rowshift">
                  <div>
                    <div class="label">Inicio</div>
                    <div class="time"><?=htmlspecialchars(fmt_hm($it['in_dt']))?></div>
                  </div>
                  <div class="arrow">→</div>
                  <div>
                    <div class="label">Fin</div>
                    <div class="time">
                      <?= $it['out_dt'] ? htmlspecialchars(fmt_hm($it['out_dt'])) : '—' ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </td>
            <td class="total"><?=htmlspecialchars(human_duration($day['total_sec']))?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>
</body>
</html>