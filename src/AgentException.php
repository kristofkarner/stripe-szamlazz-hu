<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Számla Agent hiba — azzal együtt, hogy mit tudunk a bizonylat sorsáról.
 *
 * Ez a lényeg: egy hiba után csak akkor szabad újra kiállítani, ha BIZTOS,
 * hogy az előző kérés nem hozott létre bizonylatot. Különben előbb meg kell
 * nézni a Számlázz.hu-ban, különben dupla számla lehet belőle.
 */
final class AgentException extends \RuntimeException
{
    /** Az Agent kifejezetten hibát jelzett: a bizonylat NEM készült el. */
    public const REJECTED = 'rejected';

    /** Nem tudni, elkészült-e: hálózati hiba, időtúllépés, sorszám nélküli válasz. */
    public const UNKNOWN = 'unknown';

    /**
     * Ezzel a rendelésszámmal már van bizonylat (71-es vagy 152-es hiba, ha a
     * fiókban be van kapcsolva a „Rendelésszám ismétlődés tiltása”). Vagyis egy
     * korábbi kérés elkészítette — le kell kérdezni a sorszámát.
     */
    public const DUPLICATE = 'duplicate';

    public function __construct(
        string $message,
        int $code = 0,
        public readonly string $outcome = self::UNKNOWN,
    ) {
        parent::__construct($message, $code);
    }
}
