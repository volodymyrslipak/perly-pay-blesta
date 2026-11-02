<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

if (!defined('DS')) {
    exit;
}

/**
 * The Store in Blesta's own database, through its Record query builder
 * (minphp/record, as Blesta's gateways use it): the table perly_pay_invoices,
 * made when the gateway is installed (and again by upgrade(), if missing).
 * Blesta runs on MySQL or MariaDB; only plain builder calls are used, and
 * no NULLs, so every comparison means the same on both.
 */
final class RecordStore implements Store
{
    public const TABLE = 'perly_pay_invoices';

    private \Record $record;

    public function __construct(?\Record $record = null)
    {
        $this->record = $record ?? new \Record();
    }

    /** Makes the table unless it is there (install() and upgrade()). */
    public function install(): void
    {
        $int = ['type' => 'int', 'size' => 10, 'unsigned' => true];
        $this->record
            ->setField('id', $int + ['auto_increment' => true])
            ->setField('pay_invoice', ['type' => 'varchar', 'size' => 64])
            ->setField('company_id', $int)
            ->setField('client_id', $int)
            ->setField('invoices_key', ['type' => 'char', 'size' => 64])
            ->setField('invoices', ['type' => 'text'])
            ->setField('mode', ['type' => 'varchar', 'size' => 4])
            ->setField('amount', ['type' => 'bigint', 'size' => 20, 'unsigned' => true])
            ->setField('currency', ['type' => 'char', 'size' => 3])
            ->setField('url', ['type' => 'text'])
            ->setField('status', ['type' => 'varchar', 'size' => 10])
            ->setField('claim', ['type' => 'varchar', 'size' => 40, 'default' => ''])
            ->setField('claimed_at', $int + ['default' => 0])
            ->setField('events', ['type' => 'text'])
            ->setField('refunds', ['type' => 'text'])
            ->setField('created_at', $int)
            ->setField('updated_at', $int)
            ->setKey(['id'], 'primary')
            ->setKey(['pay_invoice'], 'unique')
            ->setKey(['company_id', 'client_id', 'invoices_key'], 'index', 'payment')
            ->create(self::TABLE, true);
    }

    /** Removes the table (uninstall() of the gateway's last instance). */
    public function uninstall(): void
    {
        $this->record->drop(self::TABLE, true);
    }

    public function latestFor(int $companyId, int $clientId, string $invoicesKey, string $mode): ?array
    {
        return self::row($this->record->select()->from(self::TABLE)
            ->where('company_id', '=', $companyId)->where('client_id', '=', $clientId)
            ->where('invoices_key', '=', $invoicesKey)->where('mode', '=', $mode)
            ->order(['id' => 'DESC'])->limit(1)->fetch());
    }

    public function latestForClient(int $companyId, int $clientId): ?array
    {
        return self::row($this->record->select()->from(self::TABLE)
            ->where('company_id', '=', $companyId)->where('client_id', '=', $clientId)
            ->order(['id' => 'DESC'])->limit(1)->fetch());
    }

    public function count(int $companyId, int $clientId, string $invoicesKey): int
    {
        return $this->record->select(['id'])->from(self::TABLE)
            ->where('company_id', '=', $companyId)->where('client_id', '=', $clientId)
            ->where('invoices_key', '=', $invoicesKey)->numResults();
    }

    public function add(array $row): void
    {
        $now = time();
        $this->record->insert(self::TABLE, [
            'pay_invoice' => (string) $row['pay_invoice'],
            'company_id' => (int) $row['company_id'],
            'client_id' => (int) $row['client_id'],
            'invoices_key' => (string) $row['invoices_key'],
            'invoices' => (string) json_encode(array_values((array) $row['invoices'])),
            'mode' => (string) $row['mode'],
            'amount' => (int) $row['amount'],
            'currency' => (string) $row['currency'],
            'url' => (string) $row['url'],
            'status' => 'open',
            'claim' => '',
            'claimed_at' => 0,
            'events' => '[]',
            'refunds' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function byPayInvoice(string $payInvoice): ?array
    {
        return self::row($this->record->select()->from(self::TABLE)->where('pay_invoice', '=', $payInvoice)->fetch());
    }

    public function setStatus(string $payInvoice, string $status): void
    {
        // A paid row stays paid; "paid" itself is set over anything.
        $this->record->where('pay_invoice', '=', $payInvoice);
        if ($status !== 'paid') {
            $this->record->where('status', '!=', 'paid');
        }
        $this->record->update(self::TABLE, ['status' => $status, 'updated_at' => time()]);
    }

    public function claim(string $payInvoice, string $token, int $now): bool
    {
        // One UPDATE takes it; reading the row back says whose it is. Two deliveries at the same
        // moment are serialised by the row lock, and the second finds the claim no longer empty.
        $this->record->where('pay_invoice', '=', $payInvoice)
            ->open()->where('claim', '=', '')->orWhere('claimed_at', '<', $now - self::CLAIM_SECONDS)->close()
            ->update(self::TABLE, ['claim' => $token, 'claimed_at' => $now, 'updated_at' => $now]);
        $row = $this->byPayInvoice($payInvoice);

        return $row !== null && $row['claim'] === $token;
    }

    public function release(string $payInvoice, string $token): void
    {
        $this->record->where('pay_invoice', '=', $payInvoice)->where('claim', '=', $token)
            ->update(self::TABLE, ['claim' => '', 'claimed_at' => 0, 'updated_at' => time()]);
    }

    public function hasEvent(string $payInvoice, string $eventId): bool
    {
        $row = $this->byPayInvoice($payInvoice);

        return $row !== null && in_array($eventId, $row['events'], true);
    }

    public function rememberEvent(string $payInvoice, string $eventId): void
    {
        $row = $this->byPayInvoice($payInvoice);
        if ($row === null || in_array($eventId, $row['events'], true)) {
            return;
        }
        $events = array_slice([...$row['events'], $eventId], -30);
        $this->record->where('pay_invoice', '=', $payInvoice)->update(self::TABLE, ['events' => (string) json_encode($events), 'updated_at' => time()]);
    }

    public function addRefund(string $payInvoice, string $refundId): void
    {
        $row = $this->byPayInvoice($payInvoice);
        if ($row === null) {
            return;
        }
        $refunds = array_slice([...$row['refunds'], $refundId], -50);
        $this->record->where('pay_invoice', '=', $payInvoice)->update(self::TABLE, ['refunds' => (string) json_encode($refunds), 'updated_at' => time()]);
    }

    /** @return array<string, mixed>|null */
    private static function row(mixed $found): ?array
    {
        if (!is_object($found) && !is_array($found)) {
            return null;
        }
        $row = (array) $found;
        $strings = static function (mixed $json): array {
            $decoded = is_string($json) ? json_decode($json, true) : null;

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        };
        $invoices = [];
        $decoded = is_string($row['invoices'] ?? null) ? json_decode($row['invoices'], true) : null;
        foreach (is_array($decoded) ? $decoded : [] as $line) {
            if (is_array($line) && isset($line['id'], $line['amount'])) {
                $invoices[] = ['id' => (int) $line['id'], 'amount' => (string) $line['amount']];
            }
        }

        return [
            'pay_invoice' => (string) $row['pay_invoice'],
            'company_id' => (int) $row['company_id'],
            'client_id' => (int) $row['client_id'],
            'invoices_key' => (string) $row['invoices_key'],
            'invoices' => $invoices,
            'mode' => (string) $row['mode'],
            'amount' => (int) $row['amount'],
            'currency' => (string) $row['currency'],
            'url' => (string) $row['url'],
            'status' => (string) $row['status'],
            'claim' => (string) ($row['claim'] ?? ''),
            'claimed_at' => (int) ($row['claimed_at'] ?? 0),
            'events' => $strings($row['events'] ?? null),
            'refunds' => $strings($row['refunds'] ?? null),
        ];
    }
}
