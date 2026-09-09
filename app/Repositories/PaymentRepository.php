<?php
declare(strict_types=1);

namespace App\Repositories;

final class PaymentRepository extends Repository
{
    protected function table(): string
    {
        return 'payments';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->findRow($id);
    }

    public function findByBatch(string $batchId): ?array
    {
        return $this->db->selectOne('SELECT * FROM payments WHERE batch_id = ?', [$batchId]);
    }

    public function findByOrderId(string $gateway, string $orderId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM payments WHERE gateway = ? AND gateway_order_id = ?',
            [$gateway, $orderId]
        );
    }

    public function findByGatewayPaymentId(string $paymentId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM payments WHERE gateway_payment_id = ?',
            [$paymentId]
        );
    }

    /**
     * Mark a payment verified.
     *
     * The WHERE clause requires the row to still be unverified, so a webhook
     * arriving after the redirect verification (or two webhooks racing) cannot
     * double-apply. The return value tells the caller whether THIS call was the
     * one that flipped it, which is what gates job release.
     */
    public function markVerified(
        int $paymentId,
        string $gatewayPaymentId,
        string $signature,
        string $method,
        string $verificationMethod,
        array $response
    ): bool {
        $updated = $this->db->execute(
            "UPDATE payments
             SET status = 'paid',
                 gateway_payment_id = ?,
                 gateway_signature = ?,
                 method = ?,
                 verified_server_side = 1,
                 verification_method = ?,
                 verified_at = UTC_TIMESTAMP(),
                 gateway_response = ?
             WHERE id = ? AND verified_server_side = 0",
            [
                $gatewayPaymentId,
                substr($signature, 0, 255),
                substr($method, 0, 40),
                $verificationMethod,
                json_encode($response, JSON_UNESCAPED_SLASHES),
                $paymentId,
            ]
        );
        return $updated > 0;
    }

    public function markFailed(int $paymentId, string $reason, array $response = []): int
    {
        return $this->db->execute(
            "UPDATE payments
             SET status = 'failed', failure_reason = ?, gateway_response = ?
             WHERE id = ? AND verified_server_side = 0",
            [substr($reason, 0, 255), json_encode($response, JSON_UNESCAPED_SLASHES), $paymentId]
        );
    }

    public function markRefunded(int $paymentId, string $refundId, int $amountPaise): int
    {
        return $this->db->execute(
            "UPDATE payments
             SET status = 'refunded', refund_id = ?, refunded_paise = refunded_paise + ?
             WHERE id = ?",
            [$refundId, $amountPaise, $paymentId]
        );
    }

    /**
     * Orders created but never paid, past their TTL. Their jobs are cancelled
     * and their files released.
     *
     * @return array<int,array<string,mixed>>
     */
    public function staleUnpaid(int $ttlMinutes, int $limit = 100): array
    {
        return $this->db->select(
            "SELECT * FROM payments
             WHERE status IN ('created','pending')
               AND verified_server_side = 0
               AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
             ORDER BY created_at ASC
             LIMIT " . max(1, min(500, $limit)),
            [$ttlMinutes]
        );
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(int $page, int $perPage, string $status = '', ?int $locationId = null): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $where = ['1 = 1'];
        $bindings = [];
        if ($status !== '') {
            $where[] = 'p.status = ?';
            $bindings[] = $status;
        }
        if ($locationId !== null) {
            $where[] = 'p.location_id = ?';
            $bindings[] = $locationId;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM payments p WHERE $whereSql", $bindings);

        $rows = $this->db->select(
            "SELECT p.*, l.name AS location_name,
                    (SELECT COUNT(*) FROM print_jobs j WHERE j.payment_id = p.id) AS job_count
             FROM payments p
             INNER JOIN locations l ON l.id = p.location_id
             WHERE $whereSql
             ORDER BY p.id DESC
             LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            $bindings
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }
}
