<?php

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/PaymentRules.php';

class SslCommerzPaymentProcessor
{
    private $api;
    private $ledger;
    private $callbacks;
    private $gateway;

    public function __construct($api, $ledger, array $callbacks, array $gateway)
    {
        $this->api = $api;
        $this->ledger = $ledger;
        $this->callbacks = $callbacks;
        $this->gateway = $gateway;
    }

    public function process(array $notification, $source)
    {
        $source = $source === 'ipn' ? 'ipn' : 'browser';
        $status = strtoupper(isset($notification['status']) ? (string) $notification['status'] : '');
        $tranId = isset($notification['tran_id']) ? (string) $notification['tran_id'] : '';
        $valId = isset($notification['val_id']) ? (string) $notification['val_id'] : '';

        if ($source === 'ipn' && SslCommerzSupport::signatureMatches($notification, isset($this->gateway['store_password']) ? $this->gateway['store_password'] : '') === false) {
            return $this->finish('rejected', 0, 'IPN Signature Rejected', $notification);
        }

        $attempt = null;
        if ($tranId !== '') {
            try {
                $attempt = $this->ledger->find($tranId);
            } catch (Throwable $error) {
                if (in_array($status, array('VALID', 'VALIDATED'), true)) {
                    return $this->finish('retry', 0, 'Ledger Lookup Failed', array('error' => $error->getMessage(), 'notification' => $notification));
                }
            }
        }
        $knownInvoiceId = $attempt && isset($attempt->invoice_id) ? (int) $attempt->invoice_id : 0;
        if (in_array($status, array('FAILED', 'CANCELLED', 'EXPIRED', 'UNATTEMPTED'), true)) {
            if ($tranId !== '') { $this->safeUpdate($tranId, array('status' => strtolower($status))); }
            return $this->finish('ignored', $knownInvoiceId, $source . ' ' . $status, $notification);
        }
        if (!in_array($status, array('VALID', 'VALIDATED'), true) || $tranId === '' || $valId === '') {
            return $this->finish('rejected', 0, 'Notification Missing Valid Identity', $notification);
        }

        if (!$attempt) {
            return $this->finish('rejected', 0, 'Unknown Merchant Transaction', $notification);
        }
        $attemptArray = (array) $attempt;
        $invoiceId = (int) $attemptArray['invoice_id'];

        try {
            $validation = $this->api->validate($valId);
        } catch (Throwable $error) {
            return $this->finish('retry', $invoiceId, 'Validation API Failed', array('error' => $error->getMessage(), 'notification' => $notification));
        }
        $decision = SslCommerzPaymentRules::validationDecision($attemptArray, $validation);
        if (!$decision['accepted']) {
            $this->safeUpdate($tranId, array('status' => $decision['code'], 'val_id' => $valId, 'raw_response' => json_encode(SslCommerzSupport::redact($validation))));
            return $this->finish('rejected', $invoiceId, 'Validation ' . $decision['code'], array('notification' => $notification, 'validation' => $validation));
        }

        $bankTranId = isset($validation['bank_tran_id']) ? (string) $validation['bank_tran_id'] : '';
        if ($bankTranId === '') {
            return $this->finish('rejected', $invoiceId, 'Validation Missing Bank Transaction ID', $validation);
        }
        $this->safeUpdate($tranId, array(
            'val_id' => $valId,
            'bank_tran_id' => $bankTranId,
            'status' => 'validated',
            'bdt_amount' => $decision['bdt_amount'],
            'currency_rate_bdt' => $decision['currency_rate_bdt'],
            'card_no' => isset($validation['card_no']) ? $validation['card_no'] : null,
            'card_type' => isset($validation['card_type']) ? $validation['card_type'] : null,
            'card_brand' => isset($validation['card_brand']) ? $validation['card_brand'] : null,
            'card_issuer' => isset($validation['card_issuer']) ? $validation['card_issuer'] : null,
            'risk_level' => isset($validation['risk_level']) ? (int) $validation['risk_level'] : 0,
            'risk_title' => isset($validation['risk_title']) ? $validation['risk_title'] : null,
            'raw_response' => json_encode(SslCommerzSupport::redact($validation)),
        ));

        foreach (array($tranId, $bankTranId) as $candidate) {
            $existingInvoice = call_user_func($this->callbacks['find_transaction'], $candidate);
            if ($existingInvoice !== null) {
                if ((int) $existingInvoice === $invoiceId) {
                    $this->safeMarkPaid($tranId);
                    return $this->finish('duplicate', $invoiceId, 'Already Processed', array('transaction_id' => $candidate));
                }
                return $this->finish('rejected', $invoiceId, 'Transaction Used By Another Invoice', array('transaction_id' => $candidate, 'existing_invoice' => $existingInvoice));
            }
        }

        $claim = $this->ledger->claim($tranId);
        if ($claim === 'held') {
            return $this->finish('retry', $invoiceId, 'Settlement Is Being Processed', array('tran_id' => $tranId));
        }
        if ($claim === 'unavailable') {
            $this->log('Ledger Unavailable - WHMCS Guards Active', array('tran_id' => $tranId, 'invoice_id' => $invoiceId));
        }

        try {
            $checkedInvoiceId = call_user_func($this->callbacks['check_invoice'], $invoiceId);
            if ($checkedInvoiceId !== null) {
                $invoiceId = (int) $checkedInvoiceId;
            }
            $recordedId = isset($this->gateway['transid_source']) && $this->gateway['transid_source'] === 'bank_tran_id' ? $bankTranId : $tranId;
            call_user_func($this->callbacks['add_payment'], $invoiceId, $recordedId, (float) $decision['invoice_amount']);
        } catch (Throwable $error) {
            if ($claim === 'acquired') {
                try { $this->ledger->release($tranId); } catch (Throwable $ignored) {}
            }
            return $this->finish('retry', $invoiceId, 'WHMCS Settlement Failed', array('error' => $error->getMessage(), 'tran_id' => $tranId));
        }

        $this->safeMarkPaid($tranId);
        return $this->finish('paid', $invoiceId, ucfirst($source) . ' Payment Successful', array(
            'tran_id' => $tranId, 'bank_tran_id' => $bankTranId,
            'invoice_amount' => $decision['invoice_amount'], 'bdt_amount' => $decision['bdt_amount'],
        ));
    }

    private function safeUpdate($tranId, array $fields)
    {
        try { $this->ledger->update($tranId, $fields); } catch (Throwable $error) {
            $this->log('Ledger Update Failed', array('tran_id' => $tranId, 'error' => $error->getMessage()));
        }
    }

    private function safeMarkPaid($tranId)
    {
        try { $this->ledger->markPaid($tranId); } catch (Throwable $error) {
            $this->log('Ledger Paid Mark Failed', array('tran_id' => $tranId, 'error' => $error->getMessage()));
        }
    }

    private function finish($code, $invoiceId, $status, array $data)
    {
        $this->log($status, $data);
        return array('code' => $code, 'invoice_id' => (int) $invoiceId, 'message' => $status);
    }

    private function log($status, array $data)
    {
        call_user_func($this->callbacks['log'], $status, SslCommerzSupport::redact($data));
    }
}
