<?php

require_once __DIR__ . '/../sslcommerz/lib/Bootstrap.php';

SslCommerzBootstrap::loadWhmcs(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'POST required.';
    exit;
}

$gateway = getGatewayVariables('sslcommerz');
if (empty($gateway['type'])) {
    http_response_code(503);
    echo 'SSLCommerz is not active.';
    exit;
}

$systemUrl = !empty($gateway['systemurl'])
    ? $gateway['systemurl']
    : \WHMCS\Config\Setting::getValue('SystemURL');
$result = SslCommerzBootstrap::paymentProcessor($gateway)->process($_POST, 'browser');
$response = SslCommerzCallbackResponse::browser($result, $systemUrl);

http_response_code($response['status']);
header('Cache-Control: no-store');
header('Location: ' . $response['location']);
exit;
