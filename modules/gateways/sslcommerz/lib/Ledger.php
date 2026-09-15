<?php

class SslCommerzLedger
{
    const TABLE = 'mod_sslcommerz_transactions';
    const REFUND_TABLE = 'mod_sslcommerz_refunds';

    private $adapter;
    private $schemaReady = false;

    public function __construct($adapter = null)
    {
        $this->adapter = $adapter;
    }

    public static function schemaDefinition()
    {
        return array(
            'columns' => array(
                'id' => 'increments', 'invoice_id' => 'integer', 'tran_id' => 'string:30',
                'val_id' => 'string:100?', 'bank_tran_id' => 'string:100?', 'sessionkey' => 'string:128?',
                'status' => 'string:32', 'currency' => 'string:8', 'invoice_amount' => 'decimal:16,2',
                'bdt_amount' => 'decimal:16,2', 'currency_rate_bdt' => 'decimal:18,8',
                'refunded_bdt' => 'decimal:16,2', 'refund_ref_id' => 'string:100?',
                'refund_trans_id' => 'string:30?', 'card_no' => 'string:80?', 'card_type' => 'string:100?',
                'card_brand' => 'string:50?', 'card_issuer' => 'string:150?', 'risk_level' => 'integer',
                'risk_title' => 'string:80?', 'raw_response' => 'mediumText?',
                'settlement_claimed_at' => 'timestamp?', 'paid_at' => 'timestamp?',
                'created_at' => 'timestamp?', 'updated_at' => 'timestamp?',
            ),
            'unique' => array('tran_id'),
            'indexes' => array('invoice_id', 'bank_tran_id', 'val_id', 'sessionkey', 'refund_ref_id'),
        );
    }

    public static function refundSchemaDefinition()
    {
        return array(
            'columns' => array(
                'id'=>'increments','payment_tran_id'=>'string:30','invoice_id'=>'integer',
                'refund_trans_id'=>'string:30','request_key'=>'string:64','source_currency'=>'string:8',
                'source_amount'=>'decimal:16,2','base_refunded_bdt'=>'decimal:16,2','amount_bdt'=>'decimal:16,2',
                'refund_ref_id'=>'string:50?','status'=>'string:20','raw_response'=>'mediumText?',
                'created_at'=>'timestamp?','updated_at'=>'timestamp?',
            ),
            'unique' => array('refund_trans_id', 'request_key'),
            'indexes' => array('payment_tran_id', 'invoice_id', 'refund_ref_id', 'status'),
        );
    }

    public function ensureSchema()
    {
        if ($this->schemaReady) {
            return;
        }
        if ($this->adapter) {
            $this->adapter->ensureSchema(self::schemaDefinition());
            if (method_exists($this->adapter, 'ensureRefundSchema')) {
                $this->adapter->ensureRefundSchema(self::refundSchemaDefinition());
            }
            $this->schemaReady = true;
            return;
        }

        $capsule = '\\WHMCS\\Database\\Capsule';
        if (!class_exists($capsule)) {
            throw new RuntimeException('WHMCS Capsule is not available.');
        }
        if (!$capsule::schema()->hasTable(self::TABLE)) {
            try {
                $capsule::schema()->create(self::TABLE, function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->unsigned()->index();
                $table->string('tran_id', 30)->unique();
                $table->string('val_id', 100)->nullable()->index();
                $table->string('bank_tran_id', 100)->nullable()->index();
                $table->string('sessionkey', 128)->nullable()->index();
                $table->string('status', 32)->default('initiated')->index();
                $table->string('currency', 8);
                $table->decimal('invoice_amount', 16, 2)->default(0);
                $table->decimal('bdt_amount', 16, 2)->default(0);
                $table->decimal('currency_rate_bdt', 18, 8)->default(0);
                $table->decimal('refunded_bdt', 16, 2)->default(0);
                $table->string('refund_ref_id', 100)->nullable()->index();
                $table->string('refund_trans_id', 30)->nullable();
                $table->string('card_no', 80)->nullable();
                $table->string('card_type', 100)->nullable();
                $table->string('card_brand', 50)->nullable();
                $table->string('card_issuer', 150)->nullable();
                $table->smallInteger('risk_level')->default(0);
                $table->string('risk_title', 80)->nullable();
                $table->mediumText('raw_response')->nullable();
                $table->timestamp('settlement_claimed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                });
            } catch (Throwable $error) {
                if (!$capsule::schema()->hasTable(self::TABLE)) { throw $error; }
            }
        } else {
            $this->migrateExistingTable($capsule);
        }
        $this->ensureRefundTable($capsule);
        $this->schemaReady = true;
    }

    public function begin(array $attempt)
    {
        $this->ensureSchema();
        $now = date('Y-m-d H:i:s');
        $row = array_merge(array(
            'status' => 'initiated', 'bdt_amount' => 0, 'currency_rate_bdt' => 0,
            'refunded_bdt' => 0, 'created_at' => $now, 'updated_at' => $now,
        ), $this->filter($attempt));
        if ($this->adapter) {
            return $this->adapter->insert($row);
        }
        return \WHMCS\Database\Capsule::table(self::TABLE)->insertGetId($row);
    }

    public function update($tranId, array $fields)
    {
        $this->ensureSchema();
        $fields = $this->filter($fields);
        $fields['updated_at'] = date('Y-m-d H:i:s');
        if ($this->adapter) {
            return $this->adapter->update((string) $tranId, $fields);
        }
        return \WHMCS\Database\Capsule::table(self::TABLE)->where('tran_id', (string) $tranId)->update($fields);
    }

    public function find($id)
    {
        $this->ensureSchema();
        if ($this->adapter) {
            return $this->adapter->find((string) $id);
        }
        return \WHMCS\Database\Capsule::table(self::TABLE)
            ->where('tran_id', (string) $id)->orWhere('bank_tran_id', (string) $id)->first();
    }

    public function findById($id)
    {
        $this->ensureSchema();
        if ($this->adapter) {
            return $this->adapter->findById((int) $id);
        }
        return \WHMCS\Database\Capsule::table(self::TABLE)->where('id', (int) $id)->first();
    }

    public function recent($limit = 20)
    {
        $this->ensureSchema();
        $limit = max(1, min(5000, (int) $limit));
        if ($this->adapter) {
            return $this->adapter->recent($limit);
        }
        return \WHMCS\Database\Capsule::table(self::TABLE)->orderBy('id', 'desc')->limit($limit)->get()->all();
    }

    public function upsertFromGateway(array $transaction, $sessionKey = null)
    {
        if (empty($transaction['tran_id'])) { return false; }
        $tranId = (string)$transaction['tran_id'];
        $currency = strtoupper(trim(isset($transaction['currency_type']) ? (string)$transaction['currency_type'] : (isset($transaction['currency']) ? (string)$transaction['currency'] : '')));
        $invoiceAmount = isset($transaction['currency_amount']) && (float)$transaction['currency_amount'] > 0
            ? (float)$transaction['currency_amount'] : 0;
        $bdtAmount = isset($transaction['amount']) && (float)$transaction['amount'] > 0 ? (float)$transaction['amount'] : 0;
        if ($invoiceAmount <= 0 && $currency === 'BDT') { $invoiceAmount = $bdtAmount; }
        $safeTransaction = class_exists('SslCommerzSupport') ? SslCommerzSupport::redact($transaction) : $transaction;
        $fields = array('raw_response'=>json_encode($safeTransaction));
        if (isset($transaction['value_a']) && (int)$transaction['value_a'] > 0) { $fields['invoice_id'] = (int)$transaction['value_a']; }
        foreach (array('val_id','bank_tran_id') as $identifier) {
            if (isset($transaction[$identifier]) && trim((string)$transaction[$identifier]) !== '') {
                $fields[$identifier] = trim((string)$transaction[$identifier]);
            }
        }
        $resolvedSessionKey = trim((string)($sessionKey !== null ? $sessionKey : (isset($transaction['sessionkey']) ? $transaction['sessionkey'] : '')));
        if ($resolvedSessionKey !== '') { $fields['sessionkey'] = $resolvedSessionKey; }
        if (isset($transaction['status']) && trim((string)$transaction['status']) !== '') {
            $fields['status'] = strtolower(trim((string)$transaction['status']));
        }
        if ($currency !== '') { $fields['currency'] = $currency; }
        if ($invoiceAmount > 0) { $fields['invoice_amount'] = $invoiceAmount; }
        if ($bdtAmount > 0) { $fields['bdt_amount'] = $bdtAmount; }
        if ($invoiceAmount > 0 && $bdtAmount > 0) {
            $fields['currency_rate_bdt'] = $currency === 'BDT' ? 1 : round($bdtAmount/$invoiceAmount, 8);
        }
        foreach (array('card_no','card_type','card_brand','card_issuer','risk_title') as $detail) {
            if (isset($transaction[$detail]) && trim((string)$transaction[$detail]) !== '') {
                $fields[$detail] = $transaction[$detail];
            }
        }
        if (isset($transaction['risk_level']) && is_numeric($transaction['risk_level'])) {
            $fields['risk_level'] = (int)$transaction['risk_level'];
        }
        $existing = $this->find($tranId);
        if ($existing) { return $this->update($tranId, $fields); }
        if (!isset($fields['invoice_id'], $fields['invoice_amount'], $fields['currency'])
            || $fields['invoice_id'] <= 0 || $fields['invoice_amount'] <= 0 || $fields['currency'] === '') { return false; }
        $fields['tran_id'] = $tranId;
        return $this->begin($fields);
    }

    public function search($field, $value, $limit = 50)
    {
        $this->ensureSchema();
        $allowed = array('invoice_id', 'tran_id', 'bank_tran_id', 'val_id', 'sessionkey', 'refund_ref_id', 'card_last4');
        if (!in_array($field, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported ledger search field.');
        }
        $limit = max(1, min(5000, (int) $limit));
        if ($this->adapter) {
            return $this->adapter->search($field, (string) $value, $limit);
        }
        $query = \WHMCS\Database\Capsule::table(self::TABLE);
        if ($field === 'card_last4') {
            $query->where('card_no', 'like', '%' . preg_replace('/\D/', '', (string) $value));
        } else {
            $query->where($field, $value);
        }
        return $query->orderBy('id', 'desc')->limit($limit)->get()->all();
    }

    public function claim($tranId, $staleSeconds = 300)
    {
        try {
            $this->ensureSchema();
            $now = date('Y-m-d H:i:s');
            $staleBefore = date('Y-m-d H:i:s', time() - max(30, (int) $staleSeconds));
            if ($this->adapter) {
                return $this->adapter->claim((string) $tranId, $now, $staleBefore) ? 'acquired' : 'held';
            }
            $updated = \WHMCS\Database\Capsule::table(self::TABLE)
                ->where('tran_id', (string) $tranId)
                ->where(function ($query) use ($staleBefore) {
                    $query->whereNull('settlement_claimed_at')->orWhere('settlement_claimed_at', '<', $staleBefore);
                })
                ->update(array('settlement_claimed_at' => $now, 'updated_at' => $now));
            return $updated > 0 ? 'acquired' : 'held';
        } catch (Throwable $error) {
            return 'unavailable';
        }
    }

    public function release($tranId)
    {
        return $this->update($tranId, array('settlement_claimed_at' => null));
    }

    public function markPaid($tranId)
    {
        return $this->update($tranId, array('status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')));
    }

    public function recordRefund($tranId, array $refund)
    {
        $row = $this->find($tranId);
        if (!$row) {
            throw new RuntimeException('Payment ledger row was not found.');
        }
        $fields = array(
            'refunded_bdt' => (float) (isset($row->refunded_bdt) ? $row->refunded_bdt : 0) + (float) $refund['refund_bdt'],
            'refund_ref_id' => isset($refund['refund_ref_id']) ? $refund['refund_ref_id'] : null,
            'refund_trans_id' => isset($refund['refund_trans_id']) ? $refund['refund_trans_id'] : null,
            'status' => isset($refund['status']) ? $refund['status'] : 'refund_processing',
        );
        return $this->update($row->tran_id, $fields);
    }

    public function reserveRefund(array $request)
    {
        $this->ensureSchema();
        if ($this->adapter) {
            return $this->adapter->reserveRefund($request);
        }
        return \WHMCS\Database\Capsule::connection()->transaction(function () use ($request) {
            $payment = \WHMCS\Database\Capsule::table(self::TABLE)
                ->where(function ($query) use ($request) {
                    $query->where('tran_id', (string)$request['payment_identifier'])
                        ->orWhere('bank_tran_id', (string)$request['payment_identifier']);
                })->lockForUpdate()->first();
            if (!$payment) { throw new RuntimeException('Payment ledger row was not found.'); }

            $currency = strtoupper((string)$request['source_currency']);
            $sourceAmount = round((float)$request['source_amount'], 2);
            $baseRefunded = round((float)$payment->refunded_bdt, 2);
            $requestKey = hash('sha256', $payment->tran_id . '|' . $currency . '|' . number_format($sourceAmount,2,'.','') . '|' . number_format($baseRefunded,2,'.',''));
            $existing = \WHMCS\Database\Capsule::table(self::REFUND_TABLE)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                $existing->replay = true;
                return $existing;
            }
            $reserved = (float) \WHMCS\Database\Capsule::table(self::REFUND_TABLE)
                ->where('payment_tran_id', $payment->tran_id)->where('status', 'reserved')->sum('amount_bdt');
            $remaining = round(max(0, (float)$payment->bdt_amount - $baseRefunded - $reserved), 2);
            $desiredBdt = round((float)$request['desired_bdt'], 2);
            if ($desiredBdt > $remaining + 0.009) { throw new RuntimeException('Refund amount exceeds the remaining captured balance.'); }
            $amountBdt = $desiredBdt;
            if ($amountBdt <= 0) { throw new RuntimeException('No refundable balance remains on this payment.'); }
            $now = date('Y-m-d H:i:s');
            $id = \WHMCS\Database\Capsule::table(self::REFUND_TABLE)->insertGetId(array(
                'payment_tran_id'=>$payment->tran_id,'invoice_id'=>(int)$request['invoice_id'],
                'refund_trans_id'=>(string)$request['refund_trans_id'],'request_key'=>$requestKey,
                'source_currency'=>$currency,'source_amount'=>$sourceAmount,'base_refunded_bdt'=>$baseRefunded,
                'amount_bdt'=>$amountBdt,'status'=>'reserved','created_at'=>$now,'updated_at'=>$now,
            ));
            $row = \WHMCS\Database\Capsule::table(self::REFUND_TABLE)->where('id', $id)->first();
            $row->replay = false;
            return $row;
        });
    }

    public function recoverRefund(array $request)
    {
        $this->ensureSchema();
        if ($this->adapter) {
            return method_exists($this->adapter, 'recoverRefund') ? $this->adapter->recoverRefund($request) : null;
        }
        $payment = \WHMCS\Database\Capsule::table(self::TABLE)
            ->where(function ($query) use ($request) {
                $query->where('tran_id', (string)$request['payment_identifier'])
                    ->orWhere('bank_tran_id', (string)$request['payment_identifier']);
            })->first();
        if (!$payment) { return null; }
        $candidate = \WHMCS\Database\Capsule::table(self::REFUND_TABLE)
            ->where('payment_tran_id', $payment->tran_id)
            ->where('invoice_id', (int)$request['invoice_id'])
            ->where('source_currency', strtoupper((string)$request['source_currency']))
            ->where('source_amount', round((float)$request['source_amount'], 2))
            ->whereIn('status', array('success','processing'))
            ->whereNotNull('refund_ref_id')->orderBy('id', 'desc')->first();
        if (!$candidate || trim((string)$candidate->refund_ref_id) === '') { return null; }
        $recorded = \WHMCS\Database\Capsule::table('tblaccounts')
            ->where('transid', (string)$candidate->refund_ref_id)->exists();
        if ($recorded) { return null; }
        $candidate->replay = true;
        return $candidate;
    }

    public function finalizeRefund($refundTransId, array $result)
    {
        $this->ensureSchema();
        if ($this->adapter) { return $this->adapter->finalizeRefund($refundTransId, $result); }
        return \WHMCS\Database\Capsule::connection()->transaction(function () use ($refundTransId, $result) {
            $refund = \WHMCS\Database\Capsule::table(self::REFUND_TABLE)->where('refund_trans_id', (string)$refundTransId)->lockForUpdate()->first();
            if (!$refund) { throw new RuntimeException('Refund reservation was not found.'); }
            if (in_array($refund->status, array('success','processing'), true)) { return $refund; }
            $status = isset($result['status']) ? (string)$result['status'] : 'failed';
            $fields = array(
                'status'=>$status,'refund_ref_id'=>isset($result['refund_ref_id'])?$result['refund_ref_id']:null,
                'raw_response'=>isset($result['raw_response'])?$result['raw_response']:null,'updated_at'=>date('Y-m-d H:i:s'),
            );
            \WHMCS\Database\Capsule::table(self::REFUND_TABLE)->where('id', $refund->id)->update($fields);
            if (in_array($status, array('success','processing'), true)) {
                $payment = \WHMCS\Database\Capsule::table(self::TABLE)->where('tran_id', $refund->payment_tran_id)->lockForUpdate()->first();
                if (!$payment) { throw new RuntimeException('Payment ledger row was not found.'); }
                $newTotal = min((float)$payment->bdt_amount, (float)$payment->refunded_bdt + (float)$refund->amount_bdt);
                \WHMCS\Database\Capsule::table(self::TABLE)->where('id', $payment->id)->update(array(
                    'refunded_bdt'=>$newTotal,'refund_ref_id'=>$fields['refund_ref_id'],'refund_trans_id'=>$refundTransId,
                    'status'=>$newTotal + 0.009 >= (float)$payment->bdt_amount ? 'refunded' : 'partially_refunded',
                    'updated_at'=>date('Y-m-d H:i:s'),
                ));
            }
            return \WHMCS\Database\Capsule::table(self::REFUND_TABLE)->where('id', $refund->id)->first();
        });
    }

    private function filter(array $fields)
    {
        $allowed = array_keys(self::schemaDefinition()['columns']);
        return array_intersect_key($fields, array_flip($allowed));
    }

    private function migrateExistingTable($capsule)
    {
        $definitions = array(
            'currency_rate_bdt' => function ($t) { $t->decimal('currency_rate_bdt', 18, 8)->default(0); },
            'refunded_bdt' => function ($t) { $t->decimal('refunded_bdt', 16, 2)->default(0); },
            'refund_trans_id' => function ($t) { $t->string('refund_trans_id', 30)->nullable(); },
            'raw_response' => function ($t) { $t->mediumText('raw_response')->nullable(); },
            'settlement_claimed_at' => function ($t) { $t->timestamp('settlement_claimed_at')->nullable(); },
            'paid_at' => function ($t) { $t->timestamp('paid_at')->nullable(); },
        );
        foreach ($definitions as $column => $definition) {
            if (!$capsule::schema()->hasColumn(self::TABLE, $column)) {
                $capsule::schema()->table(self::TABLE, $definition);
            }
        }
        try {
            $columns = $capsule::select("SHOW COLUMNS FROM `" . self::TABLE . "`");
            $names = array();
            $byName = array();
            foreach ($columns as $column) { $names[] = $column->Field; $byName[$column->Field] = $column; }
            foreach (array('card_no'=>80, 'bank_tran_id'=>100, 'val_id'=>100) as $columnName => $length) {
                if (isset($byName[$columnName]) && $this->stringColumnNeedsWidening($byName[$columnName], $length)) {
                    $capsule::statement("ALTER TABLE `" . self::TABLE . "` MODIFY `" . $columnName . "` VARCHAR(" . $length . ") NULL");
                }
            }
            if (in_array('invoice_amount', $names, true) && in_array('bdt_amount', $names, true)) {
                $capsule::statement("UPDATE `" . self::TABLE . "` SET `currency_rate_bdt` = `bdt_amount` / `invoice_amount` WHERE `currency_rate_bdt` = 0 AND `invoice_amount` > 0 AND `bdt_amount` > 0");
            }
            if (in_array('validation_raw', $names, true)) {
                $capsule::statement("UPDATE `" . self::TABLE . "` SET `raw_response` = `validation_raw` WHERE `raw_response` IS NULL AND `validation_raw` IS NOT NULL");
            }
        } catch (Throwable $ignored) {
            // Best-effort widening/backfill is repeatable and should not block payments.
        }
        try {
            $indexes = $capsule::select("SHOW INDEX FROM `" . self::TABLE . "` WHERE `Key_name` = 'sslcommerz_tran_id_unique'");
            if (!$indexes) {
                $capsule::statement("ALTER TABLE `" . self::TABLE . "` ADD UNIQUE INDEX `sslcommerz_tran_id_unique` (`tran_id`)");
            }
        } catch (Throwable $ignored) {
            // Existing duplicate legacy rows should not make the whole gateway unavailable.
        }
    }

    private function stringColumnNeedsWidening($column, $minimumLength)
    {
        $type = isset($column->Type) ? strtolower((string)$column->Type) : '';
        $nullable = isset($column->Null) && strtoupper((string)$column->Null) === 'YES';
        if (!preg_match('/^varchar\((\d+)\)/', $type, $matches)) { return true; }
        return (int)$matches[1] < (int)$minimumLength || !$nullable;
    }

    private function ensureRefundTable($capsule)
    {
        if ($capsule::schema()->hasTable(self::REFUND_TABLE)) { return; }
        try {
            $capsule::schema()->create(self::REFUND_TABLE, function ($table) {
            $table->increments('id');
            $table->string('payment_tran_id',30)->index();
            $table->integer('invoice_id')->unsigned()->index();
            $table->string('refund_trans_id',30)->unique();
            $table->string('request_key',64)->unique();
            $table->string('source_currency',8);
            $table->decimal('source_amount',16,2);
            $table->decimal('base_refunded_bdt',16,2)->default(0);
            $table->decimal('amount_bdt',16,2);
            $table->string('refund_ref_id',50)->nullable()->index();
            $table->string('status',20)->default('reserved')->index();
            $table->mediumText('raw_response')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            });
        } catch (Throwable $error) {
            if (!$capsule::schema()->hasTable(self::REFUND_TABLE)) { throw $error; }
        }
    }
}
