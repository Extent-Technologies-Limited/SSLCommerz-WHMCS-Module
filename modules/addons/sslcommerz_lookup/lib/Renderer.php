<?php

class SslCommerzLookupRenderer
{
    public static function page($moduleLink, $token, array $rows, $detail = null, $refund = null, $notice = null, array $options = array())
    {
        $screen = isset($options['screen']) ? (string)$options['screen'] : (is_array($detail) ? 'detail' : 'home');
        if (!in_array($screen, array('home', 'search', 'detail', 'refund'), true)) { $screen = 'home'; }
        $limit = isset($options['recent_limit']) ? (int)$options['recent_limit'] : 20;
        $action = self::e($moduleLink);
        $tokenInput = '<input type="hidden" name="token" value="' . self::e($token) . '">';

        $titles = array(
            'home' => 'SSLCommerz Transactions',
            'search' => 'Search Results',
            'detail' => 'Transaction Detail',
            'refund' => 'Refund Status',
        );

        $html = '<style>' . self::styles() . '</style><div class="sc-wrap">'
            . '<header class="sc-page-head"><h2>' . self::e($titles[$screen]) . '</h2>';
        if ($screen !== 'home') {
            $html .= '<a class="btn btn-default sc-back" href="' . $action . '">&larr; Back to overview</a>';
        }
        $html .= '</header>';

        if ($notice) {
            $kind = isset($notice['kind']) ? (string)$notice['kind'] : 'info';
            $html .= '<div class="sc-alert sc-' . self::e($kind) . '">' . self::e(isset($notice['message']) ? $notice['message'] : '') . '</div>';
        }

        if ($screen === 'home') {
            $html .= self::tools($action, $tokenInput);
            $controls = '<div class="sc-table-actions">'
                . '<form class="sc-limit-form" method="post" action="' . $action . '">' . $tokenInput
                . '<input type="hidden" name="set_recent_limit" value="1"><label for="sc-recent-limit">Show</label>'
                . '<select id="sc-recent-limit" name="recent_limit">' . self::limitOptions($limit) . '</select>'
                . '<button class="btn btn-default btn-sm" type="submit">Apply</button></form>'
                . '<form method="post" action="' . $action . '">' . $tokenInput
                . '<button class="btn btn-success btn-sm" type="submit" name="export_csv" value="1">Export CSV</button></form></div>';
            $html .= self::transactionTable('Latest Transactions', $rows, $action, $tokenInput, $controls);
        } elseif ($screen === 'search') {
            $html .= self::transactionTable('Search Results', $rows, $action, $tokenInput, '');
        } elseif ($screen === 'detail') {
            $html .= self::transactionDetail($detail);
        } else {
            $html .= self::refundDetail($refund);
        }

        return $html . '</div>';
    }

    private static function tools($action, $tokenInput)
    {
        return '<div class="sc-tools"><section class="sc-panel sc-tool-card"><div class="sc-card-icon">&#128269;</div>'
            . '<div class="sc-card-copy"><h3>Transaction Lookup</h3><p>Search local records and refresh supported identifiers from SSLCommerz.</p></div>'
            . '<form class="sc-form" method="post" action="' . $action . '">' . $tokenInput
            . '<div class="sc-field"><label for="sc-search-type">Search field</label><select id="sc-search-type" name="search_type">'
            . '<option value="invoice_id">Invoice ID</option><option value="tran_id">Merchant Transaction ID</option>'
            . '<option value="bank_tran_id">Bank Transaction ID</option><option value="val_id">Validation ID</option>'
            . '<option value="sessionkey">Session Key</option><option value="card_last4">Card Number (last 4)</option>'
            . '</select></div><div class="sc-field"><label for="sc-search-value">Search value</label>'
            . '<input id="sc-search-value" type="text" name="search_value" autocomplete="off" required></div>'
            . '<button class="btn btn-primary" type="submit" name="search_txn" value="1">Search transactions</button></form></section>'
            . '<section class="sc-panel sc-tool-card"><div class="sc-card-icon sc-refund-icon">&#8635;</div>'
            . '<div class="sc-card-copy"><h3>Refund Status Query</h3><p>Check the latest status using an SSLCommerz refund reference ID.</p></div>'
            . '<form class="sc-form" method="post" action="' . $action . '">' . $tokenInput
            . '<div class="sc-field"><label for="sc-refund-id">Refund reference ID</label>'
            . '<input id="sc-refund-id" type="text" name="refund_ref_id" autocomplete="off" required></div>'
            . '<button class="btn btn-default" type="submit" name="query_refund" value="1">Query refund status</button></form></section></div>';
    }

    private static function transactionTable($title, array $rows, $action, $tokenInput, $controls)
    {
        $html = '<section class="sc-panel"><div class="sc-panel-head"><div><h3>' . self::e($title) . '</h3>'
            . '<p>Customer currency and gateway-settled BDT are shown separately.</p></div>' . $controls . '</div>';
        if (!$rows) {
            return $html . '<div class="sc-empty"><span>&#128203;</span><strong>No transactions found</strong>'
                . '<p>Try another identifier or return to the overview.</p></div></section>';
        }

        $html .= '<div class="sc-scroll"><table class="sc-table"><thead><tr>'
            . '<th>ID</th><th>Transaction</th><th>Invoice</th><th>Customer Amount</th><th>Settled Amount</th>'
            . '<th>Card</th><th>Status</th><th>Date</th><th class="sc-action-col">Action</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            $invoiceId = isset($row['invoice_id']) ? (int)$row['invoice_id'] : 0;
            $invoice = $invoiceId > 0
                ? '<a href="invoices.php?action=edit&amp;id=' . $invoiceId . '">#' . $invoiceId . '</a>' : '-';
            $view = $id > 0 ? '<form method="post" action="' . $action . '">' . $tokenInput
                . '<input type="hidden" name="view_detail" value="' . $id . '">'
                . '<button class="btn btn-xs btn-info" type="submit">View</button></form>' : '-';
            $status = isset($row['status']) ? strtoupper((string)$row['status']) : 'UNKNOWN';
            $html .= '<tr><td class="sc-id">' . ($id ?: '-') . '</td>'
                . '<td><code class="sc-code">' . self::e(isset($row['tran_id']) ? $row['tran_id'] : '-') . '</code></td>'
                . '<td>' . $invoice . '</td><td class="sc-money">' . self::e(self::customerAmount($row)) . '</td>'
                . '<td class="sc-money">' . self::e(self::settledAmount($row)) . '</td>'
                . '<td>' . self::e(self::maskCard(isset($row['card_no']) ? $row['card_no'] : '')) . '</td>'
                . '<td><span class="sc-status sc-status-' . self::statusTone($status) . '">' . self::e($status) . '</span></td>'
                . '<td>' . self::e(self::transactionDate($row)) . '</td><td class="sc-row-action">' . $view . '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    private static function transactionDetail($detail)
    {
        if (!is_array($detail)) {
            return '<section class="sc-panel"><div class="sc-empty"><span>&#9888;</span><strong>Transaction not found</strong>'
                . '<p>The requested ledger record is no longer available.</p></div></section>';
        }

        $status = isset($detail['status']) ? strtoupper((string)$detail['status']) : 'UNKNOWN';
        $invoiceId = isset($detail['invoice_id']) ? (int)$detail['invoice_id'] : 0;
        $invoice = $invoiceId > 0 ? '<a href="invoices.php?action=edit&amp;id=' . $invoiceId . '">Invoice #' . $invoiceId . '</a>' : '-';
        $currency = isset($detail['currency']) && $detail['currency'] !== '' ? strtoupper((string)$detail['currency']) : 'BDT';
        $rate = isset($detail['currency_rate_bdt']) ? (float)$detail['currency_rate_bdt'] : 0;

        $groups = array(
            'Payment summary' => array(
                array('Ledger ID', isset($detail['id']) ? (int)$detail['id'] : '-'),
                array('Merchant Transaction ID', self::code(isset($detail['tran_id']) ? $detail['tran_id'] : '-'), true),
                array('Invoice', $invoice, true),
                array('Status', '<span class="sc-status sc-status-' . self::statusTone($status) . '">' . self::e($status) . '</span>', true),
                array('Transaction Date', self::transactionDate($detail)),
                array('Source', isset($detail['source']) ? $detail['source'] : 'local'),
            ),
            'Amounts' => array(
                array('Customer Amount', self::customerAmount($detail)),
                array('Settled Amount', self::settledAmount($detail)),
                array('Exchange Rate', $rate > 0 ? number_format($rate, 4) . ' BDT / ' . $currency : '-'),
                array('Store Amount', self::numberWithCurrency(isset($detail['store_amount']) ? $detail['store_amount'] : null, 'BDT')),
                array('Refunded Amount', self::numberWithCurrency(isset($detail['refunded_bdt']) ? $detail['refunded_bdt'] : null, 'BDT')),
            ),
            'Gateway identifiers' => array(
                array('Bank Transaction ID', self::code(isset($detail['bank_tran_id']) ? $detail['bank_tran_id'] : '-'), true),
                array('Validation ID', self::code(isset($detail['val_id']) ? $detail['val_id'] : '-'), true),
                array('Session Key', self::code(isset($detail['sessionkey']) ? $detail['sessionkey'] : '-'), true),
                array('Refund Reference ID', self::code(isset($detail['refund_ref_id']) ? $detail['refund_ref_id'] : '-'), true),
                array('Refund Transaction ID', self::code(isset($detail['refund_trans_id']) ? $detail['refund_trans_id'] : '-'), true),
            ),
            'Card and risk' => array(
                array('Card', self::maskCard(isset($detail['card_no']) ? $detail['card_no'] : '')),
                array('Card Type', isset($detail['card_type']) ? $detail['card_type'] : '-'),
                array('Card Brand', isset($detail['card_brand']) ? $detail['card_brand'] : '-'),
                array('Card Issuer', isset($detail['card_issuer']) ? $detail['card_issuer'] : '-'),
                array('Issuer Country', isset($detail['card_issuer_country']) ? $detail['card_issuer_country'] : '-'),
                array('Risk', trim((isset($detail['risk_title']) ? $detail['risk_title'] : '-') . (isset($detail['risk_level']) ? ' (' . (int)$detail['risk_level'] . ')' : ''))),
            ),
        );

        $html = '<section class="sc-panel sc-detail-panel"><div class="sc-panel-head"><div><h3>Transaction Detail</h3>'
            . '<p>Record #' . self::e(isset($detail['id']) ? $detail['id'] : '-') . '</p></div></div>';
        if (!empty($detail['failure_reason'])) {
            $html .= '<div class="sc-alert sc-error"><strong>Session failure:</strong> ' . self::e($detail['failure_reason']) . '</div>';
        }
        $html .= '<div class="sc-scroll"><table class="sc-detail-table"><tbody>';
        foreach ($groups as $group => $items) {
            $html .= '<tr class="sc-detail-section"><th colspan="2">' . self::e($group) . '</th></tr>';
            foreach ($items as $item) {
                $value = isset($item[2]) && $item[2] ? $item[1] : self::e($item[1]);
                $html .= '<tr><th>' . self::e($item[0]) . '</th><td>' . $value . '</td></tr>';
            }
        }
        return $html . '</tbody></table></div></section>';
    }

    private static function refundDetail($refund)
    {
        $html = '<section class="sc-panel"><div class="sc-panel-head"><div><h3>Refund Status</h3>'
            . '<p>Latest gateway response for the requested refund.</p></div></div>';
        if (!is_array($refund)) {
            return $html . '<div class="sc-empty"><span>&#8635;</span><strong>No refund response</strong></div></section>';
        }
        $html .= '<div class="sc-scroll"><table class="sc-detail-table"><tbody>';
        foreach ($refund as $key => $value) {
            if (!is_scalar($value) || preg_match('/pass|secret|card/i', (string)$key)) { continue; }
            $label = ucwords(str_replace('_', ' ', (string)$key));
            $html .= '<tr><th>' . self::e($label) . '</th><td>' . self::e($value) . '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    private static function customerAmount(array $row)
    {
        $currency = !empty($row['currency']) ? strtoupper((string)$row['currency'])
            : (!empty($row['currency_type']) ? strtoupper((string)$row['currency_type']) : 'BDT');
        $amount = isset($row['invoice_amount']) ? (float)$row['invoice_amount'] : 0;
        if ($amount <= 0 && isset($row['currency_amount'])) { $amount = (float)$row['currency_amount']; }
        if ($amount <= 0 && $currency === 'BDT' && isset($row['bdt_amount'])) { $amount = (float)$row['bdt_amount']; }
        return $amount > 0 ? number_format($amount, 2) . ' ' . $currency : '-';
    }

    private static function settledAmount(array $row)
    {
        $amount = isset($row['bdt_amount']) ? (float)$row['bdt_amount'] : 0;
        if ($amount <= 0 && isset($row['amount'])) { $amount = (float)$row['amount']; }
        return $amount > 0 ? number_format($amount, 2) . ' BDT' : '-';
    }

    private static function numberWithCurrency($value, $currency)
    {
        return $value !== null && $value !== '' && (float)$value > 0 ? number_format((float)$value, 2) . ' ' . $currency : '-';
    }

    private static function transactionDate(array $row)
    {
        foreach (array('tran_date', 'created_at', 'updated_at') as $key) {
            if (!empty($row[$key])) { return (string)$row[$key]; }
        }
        return '-';
    }

    private static function statusTone($status)
    {
        $status = strtoupper((string)$status);
        if (in_array($status, array('VALID', 'VALIDATED', 'PAID', 'SUCCESS', 'REFUNDED'), true)) { return 'success'; }
        if (in_array($status, array('FAILED', 'SESSION_FAILED', 'CANCELLED', 'EXPIRED', 'REJECTED'), true)) { return 'danger'; }
        if (in_array($status, array('PROCESSING', 'REFUND_PROCESSING', 'PARTIALLY_REFUNDED'), true)) { return 'warning'; }
        return 'neutral';
    }

    private static function limitOptions($selected)
    {
        $html = '';
        foreach (array(10, 20, 50, 100) as $limit) {
            $html .= '<option value="' . $limit . '"' . ($limit === (int)$selected ? ' selected' : '') . '>' . $limit . '</option>';
        }
        return $html;
    }

    private static function code($value)
    {
        $value = trim((string)$value);
        return $value === '' || $value === '-' ? '-' : '<code class="sc-code">' . self::e($value) . '</code>';
    }

    public static function csvCell($value)
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    public static function csvRows(array $rows)
    {
        $columns = array('id', 'invoice_id', 'tran_id', 'bank_tran_id', 'val_id', 'sessionkey', 'status', 'currency', 'invoice_amount', 'bdt_amount', 'refunded_bdt', 'refund_ref_id', 'card_no', 'card_type', 'card_brand', 'risk_level', 'created_at', 'updated_at');
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            $line = array();
            foreach ($columns as $column) {
                $value = isset($row[$column]) ? $row[$column] : '';
                if ($column === 'card_no') { $value = self::maskCard($value); }
                $line[] = self::csvCell($value);
            }
            fputcsv($stream, $line);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    private static function maskCard($value)
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        return strlen($digits) >= 4 ? '****' . substr($digits, -4) : '-';
    }

    private static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function styles()
    {
        return '.sc-wrap{--sc-border:#d9e2ec;--sc-text:#263648;--sc-muted:#66788a;--sc-blue:#1f6fb2;max-width:1480px;color:var(--sc-text)}'
            . '.sc-wrap *{box-sizing:border-box}.sc-page-head{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:4px 0 18px}'
            . '.sc-page-head h2{font-size:25px;line-height:1.2;margin:0;font-weight:650}.sc-panel-head p,.sc-card-copy p{color:var(--sc-muted);margin:0}'
            . '.sc-back{white-space:nowrap}'
            . '.sc-tools{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:16px;margin-bottom:16px}.sc-panel{background:#fff;border:1px solid var(--sc-border);border-radius:10px;box-shadow:0 1px 2px rgba(30,55,80,.04);padding:20px;margin-bottom:16px}'
            . '.sc-tool-card{position:relative;padding-top:18px}.sc-card-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:#eaf4ff;color:#1768a8;font-size:18px;margin-bottom:12px}.sc-refund-icon{background:#edf9f1;color:#187944}'
            . '.sc-card-copy h3,.sc-panel-head h3{font-size:17px;margin:0 0 4px;font-weight:650}.sc-card-copy{margin-bottom:16px}.sc-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.sc-field{flex:1 1 220px}.sc-field label,.sc-limit-form label{display:block;font-size:12px;font-weight:650;margin:0 0 5px;color:#44576a}'
            . '.sc-field input,.sc-field select,.sc-limit-form select{width:100%;height:36px;border:1px solid #b8c6d4;border-radius:5px;background:#fff;padding:7px 10px;color:#263648}.sc-field input:focus,.sc-field select:focus,.sc-limit-form select:focus{border-color:#4e95cf;box-shadow:0 0 0 2px rgba(52,136,201,.12);outline:0}'
            . '.sc-panel-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:14px}.sc-table-actions,.sc-limit-form{display:flex;align-items:center;gap:8px}.sc-table-actions form{margin:0}.sc-limit-form label{margin:0}.sc-limit-form select{width:72px;height:31px;padding:4px 7px}'
            . '.sc-scroll{overflow-x:auto;border:1px solid #e5ebf1;border-radius:7px}.sc-table,.sc-detail-table{width:100%;border-collapse:separate;border-spacing:0;margin:0}.sc-table th{background:#f5f8fb;color:#53677a;font-size:11px;letter-spacing:.045em;text-transform:uppercase;padding:11px 12px;border-bottom:1px solid #dfe7ef;white-space:nowrap}'
            . '.sc-table td{padding:11px 12px;border-bottom:1px solid #edf1f5;vertical-align:middle;white-space:nowrap}.sc-table tbody tr:last-child td{border-bottom:0}.sc-table tbody tr:hover{background:#fafcff}.sc-table th,.sc-table td{text-align:left}.sc-money{font-variant-numeric:tabular-nums;font-weight:600}.sc-id{color:#6b7c8e}.sc-code{font-size:11px;color:#b4234d;background:#fff1f5;border-radius:4px;padding:3px 6px}.sc-row-action form{margin:0}.sc-action-col,.sc-row-action{text-align:right!important}'
            . '.sc-status{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:10px;line-height:1;font-weight:750;letter-spacing:.035em}.sc-status-success{background:#e8f7ee;color:#18733c}.sc-status-danger{background:#fff0f1;color:#a52735}.sc-status-warning{background:#fff5df;color:#8a5a00}.sc-status-neutral{background:#edf2f6;color:#53677a}'
            . '.sc-detail-panel{max-width:1040px}.sc-detail-table th,.sc-detail-table td{padding:11px 15px;border-bottom:1px solid #edf1f5;text-align:left;vertical-align:top}.sc-detail-table th{width:250px;color:#52677b;font-weight:600}.sc-detail-table td{word-break:break-word}.sc-detail-table tr:last-child th,.sc-detail-table tr:last-child td{border-bottom:0}.sc-detail-table .sc-detail-section th{width:auto;background:#f5f8fb;color:#28445f;text-transform:uppercase;letter-spacing:.055em;font-size:11px;padding:10px 15px}'
            . '.sc-alert{padding:12px 15px;border-radius:6px;margin-bottom:15px;border:1px solid transparent}.sc-error{background:#fff2f3;color:#941f2c;border-color:#ffd5d9}.sc-success{background:#edfbf2;color:#176538;border-color:#ccefd9}.sc-info{background:#eef7ff;color:#1b5d91;border-color:#d5eaff}'
            . '.sc-empty{text-align:center;padding:38px 20px;color:var(--sc-muted)}.sc-empty span{display:block;font-size:26px;margin-bottom:8px}.sc-empty strong{display:block;color:#405466;font-size:15px}.sc-empty p{margin:5px 0 0}'
            . '@media(max-width:980px){.sc-tools{grid-template-columns:1fr}.sc-page-head,.sc-panel-head{align-items:flex-start}.sc-table-actions{align-items:flex-end;flex-direction:column}.sc-detail-panel{max-width:none}}'
            . '@media(max-width:620px){.sc-page-head{align-items:flex-start;flex-direction:column}.sc-form{align-items:stretch;flex-direction:column}.sc-panel{padding:15px}.sc-panel-head{flex-direction:column}.sc-table-actions{width:100%;align-items:stretch}.sc-limit-form{flex-wrap:wrap}.sc-detail-table th{width:42%}}';
    }
}
