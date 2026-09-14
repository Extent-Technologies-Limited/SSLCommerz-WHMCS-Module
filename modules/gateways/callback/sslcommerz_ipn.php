<?php

require_once __DIR__ . '/../sslcommerz/lib/Bootstrap.php';

SslCommerzBootstrap::loadWhmcs(true);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'POST_REQUIRED';
    exit;
}

$gateway = getGatewayVariables('sslcommerz');
if (empty($gateway['type'])) {
    http_response_code(503);
    echo 'MODULE_INACTIVE';
    exit;
}

$result = SslCommerzBootstrap::paymentProcessor($gateway)->process($_POST, 'ipn');
$response = SslCommerzCallbackResponse::ipn($result);
http_response_code($response['status']);
echo $response['body'];
exit;
