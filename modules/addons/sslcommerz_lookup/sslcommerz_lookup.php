<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../gateways/sslcommerz/lib/Support.php';
require_once __DIR__ . '/../../gateways/sslcommerz/lib/ApiClient.php';
require_once __DIR__ . '/../../gateways/sslcommerz/lib/Ledger.php';
require_once __DIR__ . '/lib/LookupService.php';
require_once __DIR__ . '/lib/Renderer.php';
require_once __DIR__ . '/lib/PageState.php';

function sslcommerz_lookup_config()
{
    return array(
        'name' => 'SSLCommerz Transaction Lookup',
        'description' => 'Search the local payment ledger, refresh transaction data through the v4 API, export CSV, and query refund status.',
        'version' => '1.0.0',
        'author' => 'Extent Technologies Limited',
        'fields' => array(
            'store_id' => array(
                'FriendlyName' => 'Store ID', 'Type' => 'text', 'Size' => '40',
                'Description' => 'SSLCommerz store ID used for remote lookup.',
            ),
            'store_passwd' => array(
                'FriendlyName' => 'Store Password', 'Type' => 'password', 'Size' => '40',
                'Description' => 'SSLCommerz store password. It is never rendered in lookup results.',
            ),
            'testmode' => array(
                'FriendlyName' => 'Test Mode', 'Type' => 'yesno',
                'Description' => 'Query the SSLCommerz sandbox instead of production.',
            ),
        ),
    );
}

function sslcommerz_lookup_output($vars)
{
    $moduleLink = !empty($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=sslcommerz_lookup';
    $rows = array();
    $detail = null;
    $refund = null;
    $notice = null;
    $request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $screen = SslCommerzLookupPageState::screen($request);
    $recentLimit = SslCommerzLookupPageState::recentLimit(isset($request['recent_limit']) ? $request['recent_limit'] : 20);

    try {
        $api = new SslCommerzApiClient(array(
            'store_id' => isset($vars['store_id']) ? $vars['store_id'] : '',
            'store_password' => isset($vars['store_passwd']) ? $vars['store_passwd'] : '',
            'testmode' => isset($vars['testmode']) && $vars['testmode'] === 'on',
        ));
        $service = new SslCommerzLookupService(new SslCommerzLedger(), $api);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token('WHMCS.admin.default');

            if (isset($_POST['export_csv'])) {
                $csv = SslCommerzLookupRenderer::csvRows($service->recent(5000));
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="sslcommerz-transactions-' . date('Y-m-d-His') . '.csv"');
                header('Cache-Control: no-store');
                echo "\xEF\xBB\xBF" . $csv;
                exit;
            }
            if (isset($_POST['search_txn'])) {
                $rows = $service->search(
                    isset($_POST['search_type']) ? $_POST['search_type'] : 'tran_id',
                    isset($_POST['search_value']) ? $_POST['search_value'] : ''
                );
                $notice = array('kind' => 'success', 'message' => count($rows) . ' transaction attempt(s) found.');
            } elseif (isset($_POST['view_detail'])) {
                $detail = $service->detail((int) $_POST['view_detail']);
                if (!$detail) { $notice = array('kind' => 'error', 'message' => 'Transaction detail was not found.'); }
            } elseif (isset($_POST['query_refund'])) {
                $refund = $service->refundStatus(isset($_POST['refund_ref_id']) ? $_POST['refund_ref_id'] : '');
            } else {
                $rows = $service->recent($recentLimit);
            }
        } else {
            $rows = $service->recent($recentLimit);
        }
    } catch (Throwable $error) {
        $notice = array('kind' => 'error', 'message' => $error->getMessage());
        if (!isset($service)) {
            $rows = array();
        } elseif (!$rows) {
            try {
                if ($screen === 'home') { $rows = $service->recent($recentLimit); }
            } catch (Throwable $ignored) {}
        }
        if (function_exists('logActivity')) {
            logActivity('SSLCommerz lookup: ' . $error->getMessage());
        }
    }

    $token = generate_token('plain');
    echo SslCommerzLookupRenderer::page($moduleLink, $token, $rows, $detail, $refund, $notice, array(
        'screen' => $screen,
        'recent_limit' => $recentLimit,
    ));
}
