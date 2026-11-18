<?php

namespace App\Services\Sales;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;

class PosCheckoutIdempotency
{
    private const TABLE = 'pos_checkout_operations';

    public function lookup(Connection $db, int $businessId, int $userId, string $operationId, array $payload): ?array
    {
        $existing = $db->table(self::TABLE)->where('business_id', $businessId)
            ->where('operation_id', strtolower($operationId))->first();
        if (!$existing) {
            return null;
        }

        if ((int) $existing->user_id !== $userId ||
            !hash_equals($existing->payload_hash, $this->payloadHash($payload)) ||
            empty($existing->transaction_id)) {
            return ['conflict' => true];
        }

        return ['replay' => true, 'transaction_id' => (int) $existing->transaction_id];
    }

    public function claim(Connection $db, int $businessId, int $userId, string $operationId, array $payload): array
    {
        if ($db->transactionLevel() < 1) {
            throw new RuntimeException('POS checkout idempotency requires an open transaction.');
        }

        $operationId = strtolower($operationId);
        $payloadHash = $this->payloadHash($payload);
        $now = date('Y-m-d H:i:s');
        $inserted = $db->table(self::TABLE)->insertOrIgnore([
            'business_id' => $businessId,
            'user_id' => $userId,
            'operation_id' => $operationId,
            'payload_hash' => $payloadHash,
            'transaction_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return ['replay' => false];
        }

        $existing = $db->table(self::TABLE)->where('business_id', $businessId)
            ->where('operation_id', $operationId)->lockForUpdate()->first();
        if (!$existing) {
            throw new RuntimeException('POS checkout operation could not be claimed.');
        }

        if ((int) $existing->user_id !== $userId || !hash_equals($existing->payload_hash, $payloadHash)) {
            return ['replay' => false, 'conflict' => true];
        }

        if (empty($existing->transaction_id)) {
            return ['replay' => false, 'conflict' => true];
        }

        return ['replay' => true, 'transaction_id' => (int) $existing->transaction_id];
    }

    public function complete(Connection $db, int $businessId, string $operationId, int $transactionId): void
    {
        $updated = $db->table(self::TABLE)->where('business_id', $businessId)
            ->where('operation_id', strtolower($operationId))
            ->whereNull('transaction_id')
            ->update(['transaction_id' => $transactionId, 'updated_at' => date('Y-m-d H:i:s')]);

        if ($updated !== 1) {
            throw new RuntimeException('POS checkout operation could not be linked to its sale.');
        }
    }

    public function nextOperationId(): string
    {
        return (string) Str::uuid();
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonicalize($nested);
            }
        }

        if ($value && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }
}
