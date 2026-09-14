<?php

class SslCommerzPaymentRules
{
    public static function sessionPayload(array $gateway, array $invoice, array $urls, $tranId)
    {
        $amount = (float)$invoice['amount'];
        $currency = strtoupper((string)$invoice['currency']);
        if ($currency === 'BDT' && ($amount < 10 || $amount > 500000)) {
            throw new InvalidArgumentException('SSLCommerz accepts direct BDT payments from 10.00 to 500000.00 BDT.');
        }
        $customerName = trim(
            (isset($invoice['firstname']) ? (string)$invoice['firstname'] : '') . ' '
            . (isset($invoice['lastname']) ? (string)$invoice['lastname'] : '')
        );
        if ($customerName === '') {
            throw new InvalidArgumentException('Customer name is required for SSLCommerz.');
        }
        if (!isset($invoice['email']) || trim((string)$invoice['email']) === '') {
            throw new InvalidArgumentException('Customer email is required for SSLCommerz.');
        }

        $payload = array(
            'store_id' => trim((string) $gateway['store_id']),
            'store_passwd' => trim((string) $gateway['store_password']),
            'total_amount' => $amount,
            'currency' => $currency,
            'tran_id' => (string) $tranId,
            'success_url' => (string) $urls['success_url'],
            'fail_url' => (string) $urls['fail_url'],
            'cancel_url' => (string) $urls['cancel_url'],
            'ipn_url' => (string) $urls['ipn_url'],
            'cus_name' => $customerName,
            'cus_email' => (string) $invoice['email'],
            'cus_add1' => isset($invoice['address1']) ? (string)$invoice['address1'] : '',
            'cus_city' => isset($invoice['city']) ? (string)$invoice['city'] : '',
            'cus_state' => isset($invoice['state']) ? (string)$invoice['state'] : '',
            'cus_postcode' => isset($invoice['postcode']) ? (string)$invoice['postcode'] : '',
            'cus_country' => isset($invoice['country']) ? (string)$invoice['country'] : '',
            'cus_phone' => isset($invoice['phone']) ? (string)$invoice['phone'] : '',
            'shipping_method' => 'NO',
            'num_of_item' => 1,
            'product_name' => (string) $invoice['description'],
            'product_category' => 'Domain-Hosting',
            'product_profile' => 'general',
            'value_a' => (string) $invoice['invoiceid'],
        );

        if (!empty($invoice['address2'])) {
            $payload['cus_add2'] = (string) $invoice['address2'];
        }
        if (!empty($urls['return_url'])) {
            $payload['value_b'] = (string) $urls['return_url'];
        }

        return $payload;
    }

    public static function validationDecision(array $attempt, array $validation)
    {
        $status = strtoupper(isset($validation['status']) ? (string) $validation['status'] : '');
        if (!in_array($status, array('VALID', 'VALIDATED'), true)) {
            return self::reject('status_invalid');
        }
        if (!isset($validation['tran_id'], $attempt['tran_id']) || (string) $validation['tran_id'] !== (string) $attempt['tran_id']) {
            return self::reject('transaction_mismatch');
        }
        if (!isset($validation['value_a'], $attempt['invoice_id']) || (int) $validation['value_a'] !== (int) $attempt['invoice_id']) {
            return self::reject('invoice_mismatch');
        }
        if ((int) (isset($validation['risk_level']) ? $validation['risk_level'] : -1) !== 0) {
            return self::reject('risk_flagged');
        }

        $currency = strtoupper(isset($attempt['currency']) ? (string) $attempt['currency'] : '');
        $validatedCurrency = strtoupper(isset($validation['currency_type']) ? (string) $validation['currency_type'] : (isset($validation['currency']) ? (string) $validation['currency'] : ''));
        if ($validatedCurrency !== $currency) {
            return self::reject('currency_mismatch');
        }

        $invoiceAmount = (float) (isset($attempt['invoice_amount']) ? $attempt['invoice_amount'] : 0);
        $validatedInvoiceAmount = $currency === 'BDT'
            ? (float) (isset($validation['currency_amount']) && $validation['currency_amount'] !== '' ? $validation['currency_amount'] : (isset($validation['amount']) ? $validation['amount'] : 0))
            : (float) (isset($validation['currency_amount']) ? $validation['currency_amount'] : 0);
        if ($invoiceAmount <= 0 || abs($invoiceAmount - $validatedInvoiceAmount) > 0.01) {
            return self::reject('amount_mismatch');
        }

        $bdtAmount = (float) (isset($validation['amount']) ? $validation['amount'] : 0);
        if ($bdtAmount <= 0) {
            return self::reject('amount_mismatch');
        }

        return array(
            'accepted' => true,
            'code' => 'accepted',
            'invoice_amount' => $invoiceAmount,
            'bdt_amount' => $bdtAmount,
            'currency_rate_bdt' => round($bdtAmount / $invoiceAmount, 8),
        );
    }

    public static function refundAmountBdt($requested, $currency, $capturedInvoice, $capturedBdt, $alreadyRefundedBdt)
    {
        $requested = (float) $requested;
        $currency = strtoupper((string) $currency);
        $capturedInvoice = (float) $capturedInvoice;
        $capturedBdt = (float) $capturedBdt;
        $remainingBdt = max(0.0, $capturedBdt - (float) $alreadyRefundedBdt);

        if ($requested <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }
        if ($currency === 'BDT') {
            if ($capturedBdt > 0 && $requested > $remainingBdt + 0.009) {
                throw new InvalidArgumentException('Refund amount exceeds the remaining captured balance.');
            }
            return round($requested, 2);
        }
        if ($capturedInvoice <= 0 || $capturedBdt <= 0) {
            throw new InvalidArgumentException('The capture-time BDT conversion rate is unavailable.');
        }

        $converted = round($requested * ($capturedBdt / $capturedInvoice), 2);
        if ($converted > $remainingBdt + 0.009) {
            throw new InvalidArgumentException('Refund amount exceeds the remaining captured balance.');
        }
        return $converted;
    }

    private static function reject($code)
    {
        return array(
            'accepted' => false,
            'code' => $code,
            'invoice_amount' => 0.0,
            'bdt_amount' => 0.0,
            'currency_rate_bdt' => 0.0,
        );
    }
}
