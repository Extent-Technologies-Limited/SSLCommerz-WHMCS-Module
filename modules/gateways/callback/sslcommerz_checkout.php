<?php

require_once __DIR__ . '/../sslcommerz/lib/Bootstrap.php';
SslCommerzBootstrap::loadWhmcs(false);

header('Content-Type: application/json; charset=utf-8');
$gateway = getGatewayVariables('sslcommerz');
if (empty($gateway['type'])) {
    http_response_code(503);
    echo json_encode(array('status' => 'FAILED', 'data' => null, 'message' => 'SSLCommerz is not active.'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(array('status' => 'FAILED', 'data' => null, 'message' => 'POST required.'));
    exit;
}

$response = SslCommerzCheckoutEndpoint::popup($_POST, $gateway, SslCommerzBootstrap::checkoutService($gateway));
http_response_code($response['http_status']);
if ($response['http_status'] !== 200) {
    $logData = array('request' => SslCommerzSupport::redact($_POST));
    if (!empty($response['diagnostic'])) { $logData['diagnostic'] = $response['diagnostic']; }
    logTransaction($gateway['name'], $logData, 'Popup Session Initiation Failed');
}
echo json_encode($response['body']);
