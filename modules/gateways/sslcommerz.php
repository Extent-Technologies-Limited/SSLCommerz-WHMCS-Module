<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/sslcommerz/lib/Bootstrap.php';

function sslcommerz_MetaData()
{
    return array(
        'DisplayName' => 'SSLCommerz Payment Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function sslcommerz_config()
{
    return array(
        'FriendlyName' => array('Type' => 'System', 'Value' => 'SSLCommerz Payment Gateway'),
        'store_id' => array('FriendlyName' => 'Store ID', 'Type' => 'text', 'Size' => '40'),
        'store_password' => array('FriendlyName' => 'Store Password', 'Type' => 'password', 'Size' => '40'),
        'testmode' => array('FriendlyName' => 'Test Mode', 'Type' => 'yesno', 'Description' => 'Enable SSLCommerz Sandbox mode.'),
        'ui_mode' => array(
            'FriendlyName' => 'Checkout UI Mode',
            'Type' => 'dropdown',
            'Options' => array(
                'legacy_popup' => 'Legacy EasyCheckout Popup',
                'legacy_hosted' => 'Legacy Hosted Redirect',
                'new_hosted' => 'New Hosted Checkout',
            ),
            'Default' => 'legacy_popup',
            'Description' => 'Legacy remains the upgrade default. New Hosted uses the production pay.sslcommerz.com experience.',
        ),
        'transid_source' => array(
            'FriendlyName' => 'WHMCS Transaction ID',
            'Type' => 'dropdown',
            'Options' => array('tran_id' => 'Merchant Transaction ID', 'bank_tran_id' => 'Bank Transaction ID'),
            'Default' => 'tran_id',
            'Description' => 'Both identifiers are retained in the SSLCommerz ledger.',
        ),
        'button_text' => array('FriendlyName' => 'Payment Button Label', 'Type' => 'text', 'Size' => '30', 'Default' => 'Pay with SSLCommerz'),
    );
}

function sslcommerz_link($params)
{
    $mode = SslCommerzSupport::checkoutMode(isset($params['ui_mode']) ? $params['ui_mode'] : 'legacy_popup');
    $invoiceId = (int) $params['invoiceid'];
    $expires = time() + 900;
    $token = SslCommerzSupport::checkoutToken($invoiceId, $expires, (string) $params['store_password']);
    $base = rtrim((string) $params['systemurl'], '/');
    $button = !empty($params['button_text']) ? (string) $params['button_text'] : (isset($params['langpaynow']) ? (string) $params['langpaynow'] : 'Pay Now');

    if ($mode !== 'legacy_popup') {
        $action = $base . '/modules/gateways/callback/sslcommerz_init.php';
        return '<form method="post" action="' . sslcommerz_escape($action) . '">'
            . '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">'
            . '<input type="hidden" name="expires" value="' . $expires . '">'
            . '<input type="hidden" name="checkout_token" value="' . sslcommerz_escape($token) . '">'
            . '<input type="hidden" name="ui_mode" value="' . sslcommerz_escape($mode) . '">'
            . '<button type="submit" class="btn btn-success">' . sslcommerz_escape($button) . '</button>'
            . '</form>';
    }

    $endpoint = $base . '/modules/gateways/callback/sslcommerz_checkout.php';
    $embed = isset($params['testmode']) && $params['testmode'] === 'on'
        ? 'https://sandbox.sslcommerz.com/embed.min.js'
        : 'https://seamless-epay.sslcommerz.com/embed.min.js';
    $postData = array('invoice_id' => $invoiceId, 'expires' => $expires, 'checkout_token' => $token);
    $json = json_encode($postData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return '<button type="button" class="btn btn-success" id="sslczPayBtn"'
        . ' token="' . sslcommerz_escape($token) . '" postdata="" order="' . $invoiceId . '"'
        . ' endpoint="' . sslcommerz_escape($endpoint) . '">' . sslcommerz_escape($button) . '</button>'
        . '<script>(function(window,document){'
        . 'var button=document.getElementById("sslczPayBtn");if(button){button.postdata=' . $json . ';}'
        . 'var loader=function(){var script=document.createElement("script"),first=document.getElementsByTagName("script")[0];'
        . 'script.src=' . json_encode($embed) . '+"?"+Math.random().toString(36).substring(7);first.parentNode.insertBefore(script,first);};'
        . 'if(window.addEventListener){window.addEventListener("load",loader,false);}else{window.attachEvent("onload",loader);}'
        . '})(window,document);</script>';
}

function sslcommerz_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sslcommerz_refund($params)
{
    if (!class_exists('SslCommerzRefundService')) {
        require_once __DIR__ . '/sslcommerz/lib/RefundService.php';
    }
    return SslCommerzRefundService::fromWhmcs($params)->refund($params);
}
