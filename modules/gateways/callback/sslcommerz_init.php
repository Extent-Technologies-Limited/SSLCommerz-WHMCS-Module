<?php

require_once __DIR__ . '/../sslcommerz/lib/Bootstrap.php';
SslCommerzBootstrap::loadWhmcs(false);

$gateway = getGatewayVariables('sslcommerz');
if (empty($gateway['type'])) {
    http_response_code(503);
    exit('SSLCommerz is not active.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST required.');
}

$response = SslCommerzCheckoutEndpoint::hosted($_POST, $gateway, SslCommerzBootstrap::checkoutService($gateway));
if (isset($response['location'])) {
    header('Location: ' . $response['location'], true, 303);
    exit;
}

$logData = array('request' => SslCommerzSupport::redact($_POST));
if (!empty($response['diagnostic'])) { $logData['diagnostic'] = $response['diagnostic']; }
logTransaction($gateway['name'], $logData, 'Session Initiation Failed');
http_response_code($response['http_status']);
echo htmlspecialchars($response['error'], ENT_QUOTES, 'UTF-8');
