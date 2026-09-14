<?php

class SslCommerzLookupService
{
    private $ledger;
    private $api;

    public function __construct($ledger, $api)
    {
        $this->ledger = $ledger;
        $this->api = $api;
    }

    public function recent($limit = 20)
    {
        return $this->normalizeRows($this->ledger->recent($limit), 'local');
    }

    public function detail($id)
    {
        $row = $this->ledger->findById((int) $id);
        return $row ? $this->normalizeRow((array) $row, 'local') : null;
    }

    public function search($field, $value, $limit = 50)
    {
        $field = strtolower(trim((string) $field));
        $value = trim((string) $value);
        $allowed = array('invoice_id', 'tran_id', 'bank_tran_id', 'val_id', 'sessionkey', 'card_last4');
        if (!in_array($field, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported search field.');
        }
        if ($value === '') {
            throw new InvalidArgumentException('Enter a search value.');
        }
        if ($field === 'card_last4') {
            $value = substr(preg_replace('/\D/', '', $value), -4);
            if (strlen($value) !== 4) { throw new InvalidArgumentException('Enter the last four card digits.'); }
        }

        $local = $this->ledger->search($field, $value, $limit);
        $remoteCapable = in_array($field, array('tran_id','sessionkey','val_id'), true);
        if ($local && (!$remoteCapable || !$this->needsRefresh($local))) {
            return $this->normalizeRows($local, 'local');
        }
        $remote = array();
        if ($field === 'val_id') {
            $remote = array($this->api->validate($value));
        } elseif ($field === 'tran_id' || $field === 'sessionkey') {
            $response = $this->api->query(array($field => $value));
            $remote = $this->normalizeQueryResponse($response);
            $remote = array_map(function ($row) { unset($row['source']); return $row; }, $remote);
        }
        foreach ($remote as $transaction) {
            if (method_exists($this->ledger, 'upsertFromGateway')) {
                $this->ledger->upsertFromGateway($transaction, $field === 'sessionkey' ? $value : null);
            }
        }
        if (!$remote) { return $local ? $this->normalizeRows($local, 'local') : array(); }
        return $this->mergeRows($local, $remote);
    }

    public function refundStatus($refundRefId)
    {
        $refundRefId = trim((string) $refundRefId);
        if ($refundRefId === '') { throw new InvalidArgumentException('Enter a refund reference ID.'); }
        return $this->api->queryRefund($refundRefId);
    }

    private function normalizeQueryResponse(array $response)
    {
        if (isset($response['element']) && is_array($response['element'])) {
            return $this->normalizeRows($response['element'], 'remote');
        }
        if (isset($response[0]) && is_array($response[0])) {
            return $this->normalizeRows($response, 'remote');
        }
        if (isset($response['tran_id']) || isset($response['status'])) {
            return $this->normalizeRows(array($response), 'remote');
        }
        return array();
    }

    private function needsRefresh($rows)
    {
        foreach ((array)$rows as $row) {
            $row = (array)$row;
            if (empty($row['bank_tran_id']) || empty($row['val_id']) || empty($row['bdt_amount'])
                || in_array(strtolower(isset($row['status'])?$row['status']:''), array('initiated','session_created','validating'), true)) {
                return true;
            }
        }
        return false;
    }

    private function mergeRows($local, array $remote)
    {
        $merged = array();
        foreach ((array)$local as $row) {
            $row = $this->normalizeRow((array)$row, 'local');
            $merged[isset($row['tran_id'])?$row['tran_id']:'local-'.count($merged)] = $row;
        }
        foreach ($remote as $row) {
            $row = $this->normalizeRow((array)$row, 'remote');
            $key = isset($row['tran_id'])?$row['tran_id']:'remote-'.count($merged);
            $merged[$key] = isset($merged[$key]) ? array_merge($merged[$key], $row) : $row;
        }
        return array_values($merged);
    }

    private function normalizeRows($rows, $source)
    {
        $normalized = array();
        foreach ((array) $rows as $row) {
            $normalized[] = $this->normalizeRow((array) $row, $source);
        }
        return $normalized;
    }

    private function normalizeRow(array $row, $source)
    {
        $raw = array();
        if (!empty($row['raw_response']) && is_string($row['raw_response'])) {
            $decoded = json_decode($row['raw_response'], true);
            if (is_array($decoded)) { $raw = $decoded; }
        }
        unset($row['raw_response'], $row['validation_raw']);

        // SSLCommerz reports the original/customer currency as currency_type,
        // while currency may be the BDT settlement currency on legacy rows.
        $customerCurrency = !empty($row['currency_type'])
            ? (string)$row['currency_type']
            : (isset($row['currency']) ? (string)$row['currency'] : 'BDT');
        $row['currency'] = strtoupper($customerCurrency);

        if ((!isset($row['bdt_amount']) || (float)$row['bdt_amount'] <= 0) && isset($row['amount'])) {
            $row['bdt_amount'] = (float)$row['amount'];
        }
        if ((!isset($row['invoice_amount']) || (float)$row['invoice_amount'] <= 0) && isset($row['currency_amount']) && (float)$row['currency_amount'] > 0) {
            $row['invoice_amount'] = (float)$row['currency_amount'];
        }
        if ((!isset($row['invoice_amount']) || (float)$row['invoice_amount'] <= 0) && $row['currency'] === 'BDT' && !empty($row['bdt_amount'])) {
            $row['invoice_amount'] = (float)$row['bdt_amount'];
        }
        if (isset($row['invoice_amount'])) { $row['invoice_amount'] = (float)$row['invoice_amount']; }
        if (isset($row['bdt_amount'])) { $row['bdt_amount'] = (float)$row['bdt_amount']; }

        $row['invoice_id'] = isset($row['invoice_id']) ? (int)$row['invoice_id'] : 0;
        if ($row['invoice_id'] <= 0 && isset($row['value_a']) && ctype_digit((string)$row['value_a'])) {
            $row['invoice_id'] = (int)$row['value_a'];
        }
        if ($row['invoice_id'] <= 0 && !empty($row['tran_id']) && preg_match('/^INV([0-9]+)-/i', (string)$row['tran_id'], $match)) {
            $row['invoice_id'] = (int)$match[1];
        }

        $invoiceAmount = isset($row['invoice_amount']) ? (float)$row['invoice_amount'] : 0.0;
        $bdtAmount = isset($row['bdt_amount']) ? (float)$row['bdt_amount'] : 0.0;
        $rate = isset($row['currency_rate_bdt']) ? (float)$row['currency_rate_bdt'] : 0.0;
        if ($rate <= 0 && $invoiceAmount > 0 && $bdtAmount > 0) {
            $row['currency_rate_bdt'] = round($bdtAmount / $invoiceAmount, 8);
        }

        if (!empty($raw['failedreason']) && is_scalar($raw['failedreason'])) {
            $row['failure_reason'] = trim((string)$raw['failedreason']);
        }
        $row['source'] = $source;
        return $row;
    }
}
