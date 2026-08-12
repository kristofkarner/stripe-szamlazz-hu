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

/**
 * Egy kiállítandó számla.
 *
 * A három dátum alapértelmezése a mai nap: kártyás fizetésnél a
 * teljesítés és a fizetési határidő is a terhelés napja. Ha ez a te
 * adózási formádban másképp van, add meg expliciten.
 */
final class Invoice
{
    public readonly string $issueDate;
    public readonly string $fulfilmentDate;
    public readonly string $dueDate;

    /** @param InvoiceItem[] $items */
    public function __construct(
        public readonly string $externalId,      // a Stripe-esemény azonosítója
        public readonly string $buyerName,
        public readonly string $buyerPostcode,
        public readonly string $buyerCity,
        public readonly string $buyerAddress,
        public readonly array $items,
        public readonly string $currency = 'Ft',
        public readonly string $paymentMethod = 'bankkártya',
        public readonly string $language = 'hu',
        public readonly ?string $buyerEmail = null,
        public readonly ?string $buyerTaxNumber = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?string $exchangeBank = null,
        public readonly ?string $sellerEmailSubject = null,
        public readonly ?string $sellerEmailBody = null,
        ?string $issueDate = null,
        ?string $fulfilmentDate = null,
        ?string $dueDate = null,
    ) {
        if ($items === []) {
            throw new \InvalidArgumentException('A számlán legalább egy tétel kell legyen.');
        }
        $today = date('Y-m-d');
        $this->issueDate = $issueDate ?? $today;
        $this->fulfilmentDate = $fulfilmentDate ?? $today;
        $this->dueDate = $dueDate ?? $today;
    }

    public function grossTotal(): float
    {
        return round(array_sum(array_map(
            static fn (InvoiceItem $i): float => $i->grossTotal(),
            $this->items
        )), 2);
    }
}
