<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Mit kell kiállítani egy visszatérítésre — a döntés hálózat és adatbázis
 * nélkül, hogy tesztelhető legyen.
 *
 * A Stripe-ban a visszatérítés egy gomb, de a kiállított magyar számlát nem
 * vonja vissza semmi: arról nekünk kell külön bizonylatot kiállítani.
 *
 * Két buktató van benne:
 *
 * 1. A `charge.refunded` esemény a terhelés EDDIGI ÖSSZES visszatérítését
 *    hozza (`amount_refunded`), nem az aktuálisét. Ha két részleges
 *    visszatérítés jön, a másodiknál a halmozott összeg érkezik — ha ezt
 *    számláznánk, az első részt kétszer helyesbítenénk. Ezért azt tároljuk,
 *    mennyit kezeltünk már le számlával, és csak a különbséget számlázzuk.
 *
 * 2. Teljes visszatérítésnél (egyben, előzmény nélkül) az eredeti számlát
 *    sztornózzuk. Részlegesnél helyesbítő számla kell, negatív tétellel,
 *    az eredeti számla számára hivatkozva. Hogy a te esetedben a sztornó
 *    vagy a módosító számla a helyes, az KÖNYVELŐI kérdés — a kód azt
 *    a szokásos gyakorlatot követi, amit a magyar webshop-bővítmények is.
 */
final class Refund
{
    public const REVERSE = 'sztornó';
    public const CORRECT = 'helyesbítő';

    private function __construct(
        public readonly string $action,
        public readonly int $amount,     // a most számlázandó rész, legkisebb egységben
    ) {
    }

    /**
     * @param int  $refundedTotal   a Stripe szerint eddig visszatérített összeg (halmozott)
     * @param bool $fullyRefunded   a Stripe szerint a teljes terhelés vissza van-e térítve
     * @param int  $alreadyHandled  ebből mennyit kezeltünk már le számlával
     * @return self|null  null, ha nincs új teendő (pl. régebbi esemény késve érkezett)
     */
    public static function decide(int $refundedTotal, bool $fullyRefunded, int $alreadyHandled): ?self
    {
        $delta = $refundedTotal - $alreadyHandled;
        if ($delta <= 0) {
            return null;
        }
        if ($fullyRefunded && $alreadyHandled === 0) {
            return new self(self::REVERSE, $delta);
        }
        return new self(self::CORRECT, $delta);
    }
}
