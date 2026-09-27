<?php

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/PaymentRules.php';
require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/CheckoutService.php';
require_once __DIR__ . '/CheckoutEndpoint.php';
require_once __DIR__ . '/PaymentProcessor.php';
require_once __DIR__ . '/CallbackResponse.php';
require_once __DIR__ . '/RefundService.php';

class SslCommerzBootstrap
{
    public static function loadWhmcs($suppressSessionCookie = false)
    {
        if ($suppressSessionCookie) { self::suppressSessionCookie(); }
        $root = dirname(__DIR__, 4);
        require_once $root . '/init.php';
        require_once $root . '/includes/gatewayfunctions.php';
        require_once $root . '/includes/invoicefunctions.php';
    }

    public static function suppressSessionCookie()
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.use_only_cookies', '0');
        }
    }

    public static function paymentProcessor(array $gateway)
    {
        $api = new SslCommerzApiClient(array(
            'store_id' => $gateway['store_id'],
            'store_password' => $gateway['store_password'],
            'testmode' => isset($gateway['testmode']) && $gateway['testmode'] === 'on',
        ));
        $ledger = new SslCommerzLedger();
        $callbacks = array(
            'check_invoice' => function ($invoiceId) use ($gateway) {
                checkCbInvoiceID($invoiceId, $gateway['name']);
                return (int) $invoiceId;
            },
            'find_transaction' => function ($transId) {
                $row = \WHMCS\Database\Capsule::table('tblaccounts')
                    ->where('gateway', 'sslcommerz')->where('transid', (string) $transId)->first();
                return $row ? (int) $row->invoiceid : null;
            },
            'add_payment' => function ($invoiceId, $transId, $amount) {
                addInvoicePayment($invoiceId, $transId, $amount, 0.00, 'sslcommerz');
                return true;
            },
            'log' => function ($status, $data) use ($gateway) {
                logTransaction($gateway['name'], $data, $status);
            },
        );
        return new SslCommerzPaymentProcessor($api, $ledger, $callbacks, $gateway);
    }

    public static function checkoutService(array $gateway)
    {
        $systemUrl = isset($gateway['systemurl']) ? $gateway['systemurl'] : \WHMCS\Config\Setting::getValue('SystemURL');
        $api = new SslCommerzApiClient(array(
            'store_id' => $gateway['store_id'],
            'store_password' => $gateway['store_password'],
            'testmode' => isset($gateway['testmode']) && $gateway['testmode'] === 'on',
        ));
        $ledger = new SslCommerzLedger();
        $loader = new SslCommerzWhmcsInvoiceLoader($systemUrl);
        return new SslCommerzCheckoutService(
            $api,
            $ledger,
            array($loader, 'load'),
            $gateway,
            $systemUrl,
            array($loader, 'convertCurrency')
        );
    }
}

class SslCommerzWhmcsInvoiceLoader
{
    private $systemUrl;

    public function __construct($systemUrl)
    {
        $this->systemUrl = rtrim((string) $systemUrl, '/');
    }

    public function load($invoiceId)
    {
        $invoiceModel = \WHMCS\Billing\Invoice::find((int) $invoiceId);
        if (!$invoiceModel) {
            return null;
        }
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
            ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
            ->where('tblinvoices.id', (int) $invoiceId)
            ->select(
                'tblinvoices.id as invoiceid', 'tblinvoices.status',
                'tblclients.currency as currency_id', 'tblclients.firstname', 'tblclients.lastname',
                'tblclients.email', 'tblclients.address1', 'tblclients.address2', 'tblclients.city',
                'tblclients.state', 'tblclients.postcode', 'tblclients.country', 'tblclients.phonenumber'
            )->first();
        if (!$invoice) {
            return null;
        }
        $currency = \WHMCS\Database\Capsule::table('tblcurrencies')->where('id', $invoice->currency_id)->value('code');
        return array(
            'invoiceid' => (int) $invoice->invoiceid,
            'status' => (string) $invoice->status,
            'amount' => (float) $invoiceModel->balance,
            'currency' => strtoupper((string) $currency),
            'description' => 'Invoice #' . (int) $invoice->invoiceid,
            'firstname' => (string) $invoice->firstname,
            'lastname' => (string) $invoice->lastname,
            'email' => (string) $invoice->email,
            'address1' => (string) $invoice->address1,
            'address2' => (string) $invoice->address2,
            'city' => (string) $invoice->city,
            'state' => (string) $invoice->state,
            'postcode' => (string) $invoice->postcode,
            'country' => (string) $invoice->country,
            'phone' => (string) $invoice->phonenumber,
            'returnurl' => $this->systemUrl . '/viewinvoice.php?id=' . (int) $invoice->invoiceid,
        );
    }

    public function convertCurrency($amount, $fromCode, $toCode)
    {
        $from = \WHMCS\Billing\Currency::where('code', strtoupper((string)$fromCode))->first();
        $to = \WHMCS\Billing\Currency::where('code', strtoupper((string)$toCode))->first();
        if (!$from || !$to) {
            throw new RuntimeException('WHMCS BDT conversion rate is unavailable.');
        }
        return \WHMCS\Billing\Currency::convertBetween($from, (float)$amount, $to);
    }
}
