<?php

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/PaymentRules.php';
require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Ledger.php';

class SslCommerzRefundService
{
    private $api;
    private $ledger;
    private $logger;

    public function __construct($api, $ledger, callable $logger = null)
    {
        $this->api = $api;
        $this->ledger = $ledger;
        $this->logger = $logger;
    }

    public static function fromWhmcs(array $params)
    {
        $api = new SslCommerzApiClient(array(
            'store_id' => isset($params['store_id']) ? $params['store_id'] : '',
            'store_password' => isset($params['store_password']) ? $params['store_password'] : '',
            'testmode' => isset($params['testmode']) && $params['testmode'] === 'on',
        ));
        $logger = function ($status, array $data) use ($params) {
            if (function_exists('logTransaction')) {
                logTransaction(isset($params['name']) ? $params['name'] : 'SSLCommerz', SslCommerzSupport::redact($data), $status);
            }
        };
        return new self($api, new SslCommerzLedger(), $logger);
    }

    public function refund(array $params)
    {
        try {
            $invoiceId = isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0;
            $transId = isset($params['transid']) ? trim((string) $params['transid']) : '';
            $currency = strtoupper(isset($params['currency']) ? (string) $params['currency'] : 'BDT');
            if ($invoiceId <= 0 || $transId === '') {
                throw new InvalidArgumentException('A valid invoice and payment transaction are required.');
            }

            $row = $this->ledger->find($transId);
            if (!$row) {
                $row = $this->importLegacyBdtPayment($invoiceId, $transId, $currency);
            }
            $capture = (array) $row;
            if (!empty($capture['currency']) && strtoupper((string)$capture['currency']) !== $currency) {
                throw new InvalidArgumentException('Refund currency does not match the captured payment currency.');
            }
            $bankTranId = isset($capture['bank_tran_id']) ? trim((string) $capture['bank_tran_id']) : '';
            if ($bankTranId === '') {
                throw new RuntimeException('The captured bank transaction ID is unavailable.');
            }

            $sourceAmount = isset($params['amount']) ? $params['amount'] : 0;
            $recoverable = $this->ledger->recoverRefund(array(
                'payment_identifier'=>$transId,'invoice_id'=>$invoiceId,
                'source_amount'=>$sourceAmount,'source_currency'=>$currency,
            ));
            if ($recoverable && !empty($recoverable->refund_ref_id)) {
                return array(
                    'status'=>'success','transid'=>(string)$recoverable->refund_ref_id,
                    'rawdata'=>array('status'=>'idempotent_recovery'),
                );
            }

            $refundBdt = SslCommerzPaymentRules::refundAmountBdt(
                $sourceAmount,
                isset($capture['currency']) ? $capture['currency'] : $currency,
                isset($capture['invoice_amount']) ? $capture['invoice_amount'] : 0,
                isset($capture['bdt_amount']) ? $capture['bdt_amount'] : 0,
                isset($capture['refunded_bdt']) ? $capture['refunded_bdt'] : 0
            );
            if ($refundBdt <= 0) {
                throw new InvalidArgumentException('No refundable balance remains on this payment.');
            }

            $refundTransId = SslCommerzSupport::refundTransactionId($invoiceId);
            $reservation = $this->ledger->reserveRefund(array(
                'payment_identifier'=>$transId,'invoice_id'=>$invoiceId,
                'source_amount'=>$sourceAmount,'source_currency'=>$currency,
                'desired_bdt'=>$refundBdt,'refund_trans_id'=>$refundTransId,
            ));
            $refundTransId = (string)$reservation->refund_trans_id;
            $refundBdt = (float)$reservation->amount_bdt;
            if (!empty($reservation->replay) && in_array($reservation->status, array('success','processing'), true)
                && !empty($reservation->refund_ref_id)) {
                return array('status'=>'success','transid'=>(string)$reservation->refund_ref_id,'rawdata'=>array('status'=>'idempotent_replay'));
            }
            $response = $this->api->refund(array(
                'bank_tran_id' => $bankTranId,
                'refund_trans_id' => $refundTransId,
                'refund_amount' => number_format($refundBdt, 2, '.', ''),
                'refund_remarks' => 'Refund for WHMCS invoice #' . $invoiceId,
                'refe_id' => 'INV' . $invoiceId,
            ));
            $status = strtolower(isset($response['status']) ? (string) $response['status'] : 'failed');
            $apiConnect = strtoupper(isset($response['APIConnect']) ? (string) $response['APIConnect'] : 'DONE');
            $refundRefId = isset($response['refund_ref_id']) ? trim((string) $response['refund_ref_id']) : '';

            if ($apiConnect !== 'DONE' || !in_array($status, array('success', 'processing'), true) || $refundRefId === '') {
                $this->ledger->finalizeRefund($refundTransId, array(
                    'status'=>'failed','refund_ref_id'=>$refundRefId,
                    'raw_response'=>json_encode(SslCommerzSupport::redact($response)),
                ));
                $this->log('Refund Declined', array('request' => $params, 'response' => $response));
                return array(
                    'status' => 'declined',
                    'rawdata' => SslCommerzSupport::redact($response),
                );
            }

            $this->ledger->finalizeRefund($refundTransId, array(
                'refund_ref_id' => $refundRefId,
                'status' => $status,
                'raw_response'=>json_encode(SslCommerzSupport::redact($response)),
            ));
            $this->log('Refund Accepted', array('request' => $params, 'response' => $response, 'refund_bdt' => $refundBdt));

            return array(
                'status' => 'success',
                'transid' => $refundRefId,
                'rawdata' => SslCommerzSupport::redact($response),
            );
        } catch (Throwable $error) {
            $this->log('Refund Error', array('error' => $error->getMessage(), 'request' => $params));
            return array('status' => 'error', 'rawdata' => array('message' => $error->getMessage()));
        }
    }

    public function queryStatus($refundRefId)
    {
        return $this->api->queryRefund($refundRefId);
    }

    private function importLegacyBdtPayment($invoiceId, $transId, $currency)
    {
        if ($currency !== 'BDT') {
            throw new RuntimeException('A historical non-BDT payment cannot be refunded without its capture-time conversion rate.');
        }
        $response = $this->api->query(array('tran_id' => $transId));
        $rows = isset($response['element']) && is_array($response['element']) ? $response['element'] : array($response);
        foreach ($rows as $candidate) {
            if (!is_array($candidate)) { continue; }
            $status = strtoupper(isset($candidate['status']) ? (string) $candidate['status'] : '');
            if (!in_array($status, array('VALID', 'VALIDATED'), true)) { continue; }
            if (!empty($candidate['tran_id']) && (string) $candidate['tran_id'] !== $transId) { continue; }
            $bankTranId = isset($candidate['bank_tran_id']) ? trim((string) $candidate['bank_tran_id']) : '';
            $bdtAmount = isset($candidate['amount']) ? (float) $candidate['amount'] : 0;
            if ($bankTranId === '' || $bdtAmount <= 0) { continue; }
            $this->ledger->begin(array(
                'invoice_id' => $invoiceId, 'tran_id' => $transId, 'currency' => 'BDT',
                'invoice_amount' => $bdtAmount, 'bdt_amount' => $bdtAmount,
            ));
            $this->ledger->update($transId, array('bank_tran_id' => $bankTranId, 'status' => 'paid'));
            return $this->ledger->find($transId);
        }
        throw new RuntimeException('The original SSLCommerz payment could not be resolved.');
    }

    private function log($status, array $data)
    {
        if ($this->logger) {
            call_user_func($this->logger, $status, SslCommerzSupport::redact($data));
        }
    }
}
