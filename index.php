<?php
// /clover/callback/index.php

header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__, 2); // ajusta si tu estructura no es /clover/callback
$wpLoad = $root . '/wp-load.php';
if (file_exists($wpLoad)) {
  require_once $wpLoad;
}

$code  = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
// Clover puede enviar state (recomendado) o merchant_id (según el flujo que uses)
$state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
$merchantParam = isset($_GET['merchant_id']) ? trim((string)$_GET['merchant_id']) : '';

if ($code === '') {
  http_response_code(400);
  echo "Falta parámetro code.";
  exit;
}
// MerchantId: preferimos state, si no viene usamos merchant_id
$merchantId = $state !== '' ? $state : $merchantParam;
if ($merchantId === '') {
  http_response_code(400);
  echo "Falta parámetro state o merchant_id (merchantId).";
  exit;
}

// Sanitizar merchantId (solo alfanumérico)
$merchantId = preg_replace('/[^A-Za-z0-9]/', '', $merchantId);
if ($merchantId === '') {
  http_response_code(400);
  echo "merchantId inválido.";
  exit;
}

$cloverBase = rtrim($root, '/\\') . '/clover/';
$secretsFile = $cloverBase . 'clover_secrets.php';
$tokensFile  = $cloverBase . 'clover_tokens.json';

if (!file_exists($secretsFile)) {
  http_response_code(500);
  echo "No existe clover_secrets.php en /clover/.";
  exit;
}

$secrets = include $secretsFile;
$clientId = $secrets['client_id'] ?? ($secrets['clientId'] ?? null);
$clientSecret = $secrets['client_secret'] ?? ($secrets['clientSecret'] ?? null);

if (!$clientId || !$clientSecret) {
  http_response_code(500);
  echo "Faltan client_id / client_secret en clover_secrets.php";
  exit;
}

// IMPORTANTE: que coincida EXACTO con el redirect_uri que usás al autorizar
$redirectUri = 'https://kolaccesorios.com/clover/callback';

$payload = [
  'client_id' => $clientId,
  'client_secret' => $clientSecret,
  'code' => $code,
  'grant_type' => 'authorization_code',
  'redirect_uri' => $redirectUri,
];

// Clover OAuth: algunos entornos aceptan JSON y otros form. Probamos JSON primero y si devuelve 415 reintentamos como form.
$oauthUrl = 'https://api.la.clover.com/oauth/v2/token';

$resp = null; $http = 0; $err = '';

$ch = curl_init($oauthUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Accept: application/json',
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
  CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($http === 415) {
  $ch = curl_init($oauthUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/x-www-form-urlencoded',
      'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => http_build_query($payload, '', '&'),
    CURLOPT_TIMEOUT => 30,
  ]);
  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
}

$data = json_decode($resp ?? '', true);

if ($http < 200 || $http >= 300 || !is_array($data) || empty($data['access_token'])) {
  http_response_code(500);
  echo "Error al pedir token. HTTP={$http}<br>";
  echo "CURL_ERR=" . htmlspecialchars($err) . "<br>";
  echo "RESP=" . htmlspecialchars($resp);
  exit;
}

// Cargar JSON actual (si no existe, crearlo)
$all = [];
if (file_exists($tokensFile)) {
  $raw = file_get_contents($tokensFile);
  $all = json_decode($raw ?: '', true);
  if (!is_array($all)) $all = [];
}

$all[$merchantId] = [
  'access_token' => $data['access_token'],
  'access_token_expiration' => (int)($data['access_token_expiration'] ?? 0),
  'refresh_token' => $data['refresh_token'] ?? '',
  'refresh_token_expiration' => (int)($data['refresh_token_expiration'] ?? 0),
  'updated_at' => time(),
];

// Guardar con lock para evitar corrupción si dos callbacks se ejecutan al mismo tiempo
$jsonOut = json_encode($all, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
file_put_contents($tokensFile, $jsonOut, LOCK_EX);

echo "OK. Tokens guardados para merchant {$merchantId}. Ya podés volver al plugin.";
exit;