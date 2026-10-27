<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Melyik Stripe-terhelésről melyik számla készült, és mennyit kezeltünk le
 * belőle visszatérítésként.
 *
 * Erre azért van szükség, mert a visszatérítési eseményben (`charge.refunded`)
 * nincs benne a magyar számla száma — azt csak mi tudjuk. Enélkül nem lehetne
 * megmondani, melyik számlát kell sztornózni vagy helyesbíteni.
 */
final class InvoicedCharges
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function record(string $chargeId, string $invoiceNumber, string $currency, int $amount): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoiced_charges (stripe_charge, invoice_number, currency, amount, refunded, updated_at)
             VALUES (?, ?, ?, ?, 0, NOW())'
        );
        $stmt->execute([$chargeId, $invoiceNumber, strtolower($currency), $amount]);
    }

    /** @return array{invoice_number: string, currency: string, amount: int, refunded: int}|null */
    public function find(string $chargeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT invoice_number, currency, amount, refunded FROM invoiced_charges WHERE stripe_charge = ?'
        );
        $stmt->execute([$chargeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'invoice_number' => (string) $row['invoice_number'],
            'currency' => (string) $row['currency'],
            'amount' => (int) $row['amount'],
            'refunded' => (int) $row['refunded'],
        ];
    }

    /**
     * A lekezelt összeget abszolút értékre állítjuk, nem hozzáadunk: így egy
     * újra lefutó lépés sem számol kétszer. A feltétel kizárja, hogy egy késve
     * érkező, régebbi esemény visszaírja a kisebb összeget.
     */
    public function setRefunded(string $chargeId, int $refundedTotal): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE invoiced_charges SET refunded = ?, updated_at = NOW() WHERE stripe_charge = ? AND refunded < ?'
        );
        $stmt->execute([$refundedTotal, $chargeId, $refundedTotal]);
    }
}
