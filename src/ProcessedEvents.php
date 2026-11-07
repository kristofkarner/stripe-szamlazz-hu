<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Feldolgozott események nyilvántartása — ez akadályozza meg a dupla
 * számlát, és azt is, hogy egy számla csendben elmaradjon.
 *
 * A Stripe újraküldi az eseményt, ha nem kap időben 2xx választ. Ezért
 * minden művelet lefuthat kétszer, és minden hiba után jön egy újabb
 * próbálkozás. Két hibát kell elkerülni:
 *
 * 1. Dupla számla: ELŐBB jegyezzük fel az eseményt, és csak utána állítjuk
 *    ki a számlát. Fordított sorrendben a két lépés közé beférhet egy
 *    második kérés.
 * 2. Csendes számlavesztés: ha a feldolgozás elszáll, a sor nem maradhat
 *    „foglalt” állapotban, mert akkor a következő kézbesítés azt hinné, hogy
 *    kész, és a számla soha nem készülne el.
 *
 * Ezért az állapotot is tároljuk, nem csak a foglalás tényét:
 *
 *   claimed  — lefoglalva, az Agentet még NEM hívtuk; biztonságosan újrakezdhető
 *   issuing  — az Agent hívása elindult; a kimenetel lehet ismeretlen, ezért
 *              újrakezdés előtt le kell kérdezni a Számlázz.hu-ban
 *   done     — kész (számlával, vagy mert nem volt teendő)
 *   failed   — feladtuk (az Agent 5 kérés után sem fogadta el); ember kell
 */
final class ProcessedEvents
{
    public const NEW = 'new';       // most foglaltuk le, kezdhető
    public const DONE = 'done';     // már kész vagy feladva: nincs teendő
    public const BUSY = 'busy';     // épp egy másik kérés dolgozik rajta
    public const RETRY = 'retry';   // korábban elakadt, az Agentet nem hívta: újrakezdhető
    public const CHECK = 'check';   // korábban elakadt az Agent hívása közben: előbb lekérdezés

    /** Ennyi ideig tekintjük élőnek egy másik kérés foglalását. */
    private const LOCK_SECONDS = 300;

    /** A „szabad” foglalás ideje: egy elengedett sort a következő kérés azonnal átvehet. */
    private const RELEASED = '1970-01-01 00:00:00';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ?\Closure $clock = null,   // a teszt állítja; élesben a valódi idő
    ) {
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function ts(int $time): string
    {
        return date('Y-m-d H:i:s', $time);
    }

    /**
     * Megpróbálja lefoglalni az eseményt feldolgozásra.
     *
     * @return string NEW | DONE | BUSY | RETRY | CHECK
     */
    public function claim(string $eventId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO processed_events (event_id, state, attempts, claimed_at) VALUES (?, 'claimed', 0, ?)"
            );
            $stmt->execute([$eventId, $this->ts($this->now())]);
            return self::NEW;
        } catch (\PDOException $e) {
            // 23000 = egyediség sérülés: már van sor erre az eseményre.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare('SELECT state, claimed_at FROM processed_events WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || in_array($row['state'], ['done', 'failed'], true)) {
            return self::DONE;
        }
        if ($row['claimed_at'] > $this->ts($this->now() - self::LOCK_SECONDS)) {
            return self::BUSY;
        }

        // Elakadt vagy elengedett sor: atomikusan átvesszük. Ha közben egy
        // másik kérés vette át, a feltétel nem teljesül, és mi visszalépünk.
        $take = $this->pdo->prepare(
            'UPDATE processed_events SET claimed_at = ? WHERE event_id = ? AND claimed_at = ? AND state = ?'
        );
        $take->execute([$this->ts($this->now()), $eventId, $row['claimed_at'], $row['state']]);
        if ($take->rowCount() !== 1) {
            return self::BUSY;
        }
        return $row['state'] === 'issuing' ? self::CHECK : self::RETRY;
    }

    /** Hány Agent-hívás történt eddig erre az eseményre. */
    public function attempts(string $eventId): int
    {
        $stmt = $this->pdo->prepare('SELECT attempts FROM processed_events WHERE event_id = ?');
        $stmt->execute([$eventId]);
        return (int) $stmt->fetchColumn();
    }

    /** Közvetlenül az Agent hívása ELŐTT: innentől a kimenetel lehet ismeretlen. */
    public function issuing(string $eventId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE processed_events SET state = 'issuing', attempts = attempts + 1 WHERE event_id = ?"
        );
        $stmt->execute([$eventId]);
    }

    /** A kiállított számla sorszámát visszaírjuk — így utólag párosítható. */
    public function markInvoiced(string $eventId, ?string $invoiceNumber): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE processed_events SET state = 'done', invoice_number = ?, invoiced_at = ?, last_error = NULL WHERE event_id = ?"
        );
        $stmt->execute([$invoiceNumber, $this->ts($this->now()), $eventId]);
    }

    /**
     * Hiba után elengedjük a foglalást, hogy a Stripe következő kézbesítése
     * azonnal átvehesse. Az állapot marad (vagy visszaáll): `claimed`, ha
     * BIZTOS, hogy bizonylat nem készült, `issuing`, ha nem tudni — akkor a
     * következő kérés előbb lekérdez.
     */
    public function release(string $eventId, string $state, string $error): void
    {
        if (!in_array($state, ['claimed', 'issuing'], true)) {
            throw new \InvalidArgumentException("Ismeretlen állapot: {$state}");
        }
        $stmt = $this->pdo->prepare(
            'UPDATE processed_events SET state = ?, claimed_at = ?, last_error = ? WHERE event_id = ?'
        );
        $stmt->execute([$state, self::RELEASED, mb_substr($error, 0, 500), $eventId]);
    }

    /** Feladjuk: ember kell hozzá. A sor megmarad, a hibával együtt. */
    public function fail(string $eventId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE processed_events SET state = 'failed', last_error = ? WHERE event_id = ?"
        );
        $stmt->execute([mb_substr($error, 0, 500), $eventId]);
    }

    /** @return array{state: string, attempts: int, invoice_number: ?string, last_error: ?string}|null */
    public function get(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT state, attempts, invoice_number, last_error FROM processed_events WHERE event_id = ?'
        );
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['attempts'] = (int) $row['attempts'];
        return $row;
    }
}
