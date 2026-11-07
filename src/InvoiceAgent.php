<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Amit a webhook a számlázótól vár. A valódi megvalósítás a SzamlazzAgent;
 * a tesztben egy hálózat nélküli hamis változat áll a helyén.
 */
interface InvoiceAgent
{
    /** @throws AgentException */
    public function issue(Invoice $invoice): string;

    /** @throws AgentException */
    public function reverse(string $invoiceNumber, ?string $buyerEmail = null, ?string $externalId = null): string;

    /**
     * Létezik-e már bizonylat ezzel a külső azonosítóval vagy rendelésszámmal.
     *
     * @return string|null a sorszám, vagy null, ha biztosan nincs ilyen
     * @throws AgentException ha a kérdésre sem tudni a választ
     */
    public function find(?string $externalId = null, ?string $orderNumber = null): ?string;
}
