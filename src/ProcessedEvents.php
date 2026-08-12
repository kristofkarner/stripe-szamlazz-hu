<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Feldolgozott események nyilvántartása — ez akadályozza meg a dupla
 * számlát.
 *
 * A Stripe újraküldi az eseményt, ha nem kap időben választ. Ez helyes
 * viselkedés a szolgáltató részéről, de azt jelenti, hogy minden
 * művelet lefuthat kétszer. Számlázásnál ez nem apróság: a számla
 * hivatalos irat, a duplikátumot sztornózni kell.
 *
 * A védelem sorrendje a lényeg: ELŐBB jegyezzük fel az eseményt, és
 * csak utána állítjuk ki a számlát. Fordított sorrendben a két lépés
 * közé beférhet egy második kérés.
 */
final class ProcessedEvents
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Megpróbálja lefoglalni az eseményt feldolgozásra.
     *
     * @return bool true, ha MI kaptuk meg — false, ha már fel van dolgozva
     */
    public function claim(string $eventId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO processed_events (event_id, claimed_at) VALUES (?, NOW())'
            );
            $stmt->execute([$eventId]);
            return true;
        } catch (\PDOException $e) {
            // 23000 = egyediség sérülés: valaki más már elvitte.
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /** A kiállított számla sorszámát visszaírjuk — így utólag párosítható. */
    public function markInvoiced(string $eventId, string $invoiceNumber): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE processed_events SET invoice_number = ?, invoiced_at = NOW() WHERE event_id = ?'
        );
        $stmt->execute([$invoiceNumber, $eventId]);
    }

    /**
     * Sikertelen feldolgozásnál elengedjük a foglalást, hogy a Stripe
     * újraküldése tudjon próbálkozni. Ha itt bennhagynánk a sort, a
     * számla soha nem készülne el — és senki nem venné észre.
     */
    public function release(string $eventId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM processed_events WHERE event_id = ? AND invoice_number IS NULL');
        $stmt->execute([$eventId]);
    }
}
