<?php

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/PaymentRules.php';

class SslCommerzCheckoutService
{
    private $api;
    private $ledger;
    private $invoiceLoader;
    private $gateway;
    private $systemUrl;
    private $currencyConverter;
    private $lastDiagnostic = array();

    public function __construct($api, $ledger, callable $invoiceLoader, array $gateway, $systemUrl, callable $currencyConverter = null)
    {
        $this->api = $api;
        $this->ledger = $ledger;
        $this->invoiceLoader = $invoiceLoader;
        $this->gateway = $gateway;
        $this->systemUrl = rtrim((string) $systemUrl, '/');
        $this->currencyConverter = $currencyConverter;
    }

    public function initiate($invoiceId)
    {
        $invoice = call_user_func($this->invoiceLoader, (int) $invoiceId);
        if (!$invoice || (int) $invoice['invoiceid'] <= 0) {
            throw new RuntimeException('Invoice was not found.');
        }
        if (strcasecmp((string) $invoice['status'], 'Unpaid') !== 0) {
            throw new RuntimeException('Only unpaid invoices can be paid.');
        }
        if ((float) $invoice['amount'] <= 0) {
            throw new RuntimeException('Invoice balance must be greater than zero.');
        }

        $tranId = SslCommerzSupport::transactionId($invoice['invoiceid']);
        $callback = $this->systemUrl . '/modules/gateways/callback/sslcommerz.php';
        $this->lastDiagnostic = array(
            'invoice_id' => (int)$invoice['invoiceid'],
            'tran_id' => $tranId,
            'amount' => (float)$invoice['amount'],
            'currency' => strtoupper((string)$invoice['currency']),
            'conversion_source' => isset($this->gateway['conversion_source']) && $this->gateway['conversion_source'] === 'whmcs'
                ? 'whmcs' : 'sslcommerz',
        );
        try {
            $amounts = SslCommerzPaymentRules::checkoutAmounts($this->gateway, $invoice, $this->currencyConverter);
        } catch (Throwable $error) {
            $message = $this->redactDiagnosticText($error->getMessage());
            $this->lastDiagnostic['preflight_error'] = $message;
            try {
                $this->ledger->begin(array(
                    'invoice_id' => (int) $invoice['invoiceid'],
                    'tran_id' => $tranId,
                    'status' => 'session_failed',
                    'currency' => strtoupper((string) $invoice['currency']),
                    'invoice_amount' => (float) $invoice['amount'],
                    'raw_response' => json_encode(array('status'=>'PREFLIGHT_FAILED','failedreason'=>$message)),
                ));
            } catch (Throwable $ignored) {
                // Preserve the actionable conversion error if diagnostic persistence also fails.
            }
            throw $error;
        }
        $invoice = array_merge($invoice, $amounts);

        $urls = array(
            'success_url' => $callback,
            'fail_url' => $callback,
            'cancel_url' => $callback,
            'ipn_url' => $this->systemUrl . '/modules/gateways/callback/sslcommerz_ipn.php',
            'return_url' => isset($invoice['returnurl']) ? $invoice['returnurl'] : $this->systemUrl . '/viewinvoice.php?id=' . (int) $invoice['invoiceid'],
        );
        $this->lastDiagnostic = array_merge($this->lastDiagnostic, array(
            'processing_amount' => (float)$invoice['processing_amount'],
            'processing_currency' => strtoupper((string)$invoice['processing_currency']),
            'checkout_mode' => SslCommerzSupport::checkoutMode(isset($this->gateway['ui_mode']) ? $this->gateway['ui_mode'] : ''),
            'test_mode' => isset($this->gateway['testmode']) && $this->gateway['testmode'] === 'on',
            'customer_fields_present' => array(
                'name' => trim((isset($invoice['firstname']) ? (string)$invoice['firstname'] : '') . ' ' . (isset($invoice['lastname']) ? (string)$invoice['lastname'] : '')) !== '',
                'email' => isset($invoice['email']) && trim((string)$invoice['email']) !== '',
                'address' => isset($invoice['address1']) && trim((string)$invoice['address1']) !== '',
                'city' => isset($invoice['city']) && trim((string)$invoice['city']) !== '',
                'postcode' => isset($invoice['postcode']) && trim((string)$invoice['postcode']) !== '',
                'country' => isset($invoice['country']) && trim((string)$invoice['country']) !== '',
                'phone' => isset($invoice['phone']) && trim((string)$invoice['phone']) !== '',
            ),
            'callback_host' => parse_url($callback, PHP_URL_HOST),
        ));

        $this->ledger->begin(array(
            'invoice_id' => (int) $invoice['invoiceid'],
            'tran_id' => $tranId,
            'status' => 'initiated',
            'currency' => strtoupper((string) $invoice['currency']),
            'invoice_amount' => (float) $invoice['amount'],
            'processing_currency' => strtoupper((string) $invoice['processing_currency']),
            'processing_amount' => (float) $invoice['processing_amount'],
        ));

        try {
            $payload = SslCommerzPaymentRules::sessionPayload($this->gateway, $invoice, $urls, $tranId);
        } catch (Throwable $error) {
            $message = $this->redactDiagnosticText($error->getMessage());
            $this->lastDiagnostic['preflight_error'] = $message;
            $this->ledger->update($tranId, array(
                'status' => 'session_failed',
                'raw_response' => json_encode(array('status'=>'PREFLIGHT_FAILED','failedreason'=>$message)),
            ));
            throw $error;
        }

        try {
            $response = $this->api->createSession($payload);
        } catch (Throwable $error) {
            $message = $this->redactDiagnosticText($error->getMessage());
            $this->lastDiagnostic['transport_error'] = $message;
            $this->ledger->update($tranId, array(
                'status' => 'session_failed',
                'raw_response' => json_encode(array('status'=>'TRANSPORT_FAILED','failedreason'=>$message)),
            ));
            throw $error;
        }
        $this->lastDiagnostic['gateway_status'] = strtoupper(isset($response['status']) ? (string)$response['status'] : '');
        if (strtoupper(isset($response['status']) ? (string) $response['status'] : '') !== 'SUCCESS') {
            $this->lastDiagnostic['gateway_failed_reason'] = isset($response['failedreason'])
                ? $this->redactDiagnosticText($response['failedreason']) : 'SSLCommerz session creation failed.';
            $this->ledger->update($tranId, array('status' => 'session_failed', 'raw_response' => json_encode(SslCommerzSupport::redact($response))));
            throw new RuntimeException(isset($response['failedreason']) ? (string) $response['failedreason'] : 'SSLCommerz session creation failed.');
        }
        $gatewayUrl = isset($response['GatewayPageURL']) ? (string) $response['GatewayPageURL'] : '';
        $this->lastDiagnostic['gateway_host'] = parse_url($gatewayUrl, PHP_URL_HOST);
        if (!SslCommerzSupport::safeGatewayUrl($gatewayUrl)) {
            $this->ledger->update($tranId, array(
                'status' => 'session_failed',
                'raw_response' => json_encode(SslCommerzSupport::redact($response)),
            ));
            throw new RuntimeException('SSLCommerz returned an unsafe gateway URL.');
        }

        $sessionKey = isset($response['sessionkey']) ? (string) $response['sessionkey'] : '';
        $this->ledger->update($tranId, array(
            'status' => 'session_created',
            'sessionkey' => $sessionKey,
            'raw_response' => json_encode(SslCommerzSupport::redact($response)),
        ));

        return array('tran_id' => $tranId, 'sessionkey' => $sessionKey, 'gateway_url' => $gatewayUrl, 'store_logo' => isset($response['storeLogo']) ? $response['storeLogo'] : '');
    }

    public function lastDiagnostic()
    {
        return $this->lastDiagnostic;
    }

    private function redactDiagnosticText($message)
    {
        $message = (string)$message;
        $password = isset($this->gateway['store_password']) ? (string)$this->gateway['store_password'] : '';
        return $password !== '' ? str_replace($password, '[REDACTED]', $message) : $message;
    }
}
