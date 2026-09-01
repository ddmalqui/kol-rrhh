<?php
/**
 * Clover - Get Employee Shifts (simple test)
 * Employee: 1702STFCB7TC4
 * Merchant: DH84CJ0QBWFB1
 */

header('Content-Type: application/json; charset=utf-8');

// 1) CONFIGURACIÓN (PEGÁ TU ACCESS TOKEN ACÁ)
$accessToken = 'PEGAR_ACCESS_TOKEN_ACA';

// 2) DATOS FIJOS (como pediste)
$merchantId  = 'DH84CJ0QBWFB1';
$employeeId  = '1702STFCB7TC4';

// 3) Endpoint
$url = "https://api.la.clover.com/v3/merchants/{$merchantId}/employees/{$employeeId}/shifts";

// 4) cURL request
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPGET => true,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer {$accessToken}",
    "Accept: application/json",
  ],
  CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'cURL error',
    'detail' => $err,
  ], JSON_PRETTY_PRINT);
  exit;
}

// 5) Devolver tal cual respondió Clover, pero con código HTTP correcto
http_response_code($httpCode);

// Intento de parseo para devolver JSON bonito si aplica
$data = json_decode($response, true);
if (json_last_error() === JSON_ERROR_NONE) {
  echo json_encode([
    'ok' => ($httpCode >= 200 && $httpCode < 300),
    'http_code' => $httpCode,
    'merchant_id' => $merchantId,
    'employee_id' => $employeeId,
    'data' => $data,
  ], JSON_PRETTY_PRINT);
} else {
  // Si Clover devolvió texto plano
  echo json_encode([
    'ok' => ($httpCode >= 200 && $httpCode < 300),
    'http_code' => $httpCode,
    'merchant_id' => $merchantId,
    'employee_id' => $employeeId,
    'raw' => $response,
  ], JSON_PRETTY_PRINT);
}
