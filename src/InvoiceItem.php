<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Egyetlen számlatétel.
 *
 * Az összegeket maga számolja ki. Az Agent ugyanis ellenőrzi, hogy
 * nettó egységár × mennyiség = nettó érték, és nettó + áfa = bruttó —
 * ha ezek egy fillérrel eltérnek, a kérés elszáll. Egy helyen kerekítünk.
 */
final class InvoiceItem
{
    public function __construct(
        public readonly string $name,
        public readonly float $quantity,
        public readonly string $unit,
        public readonly float $netUnitPrice,
        public readonly string $vatKey,   // pl. '27', 'AAM', 'TAM'
    ) {
    }

    /**
     * Bruttó egységárból. A Stripe azt az összeget adja, amit a vevő
     * fizetett, és magyar fogyasztói árnál ez bruttó. A nettót itt, egy
     * helyen számoljuk vissza; az áfa ebből jön, ahogy az Agent ellenőrzi.
     */
    public static function fromGross(
        string $name,
        float $quantity,
        string $unit,
        float $grossUnitPrice,
        string $vatKey,
    ): self {
        $rate = is_numeric($vatKey) ? (float) $vatKey / 100 : 0.0;
        return new self($name, $quantity, $unit, round($grossUnitPrice / (1 + $rate), 2), $vatKey);
    }

    public function netTotal(): float
    {
        return round($this->quantity * $this->netUnitPrice, 2);
    }

    public function vatTotal(): float
    {
        if (!is_numeric($this->vatKey)) {
            return 0.0;   // AAM, TAM és társai: nincs felszámított áfa
        }
        return round($this->netTotal() * ((float) $this->vatKey / 100), 2);
    }

    public function grossTotal(): float
    {
        return round($this->netTotal() + $this->vatTotal(), 2);
    }
}
