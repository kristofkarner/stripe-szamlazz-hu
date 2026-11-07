<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * A webhook döntései, hálózat nélkül tesztelhetően. A `public/webhook.php`
 * csak az aláírást ellenőrzi, és ide adja tovább a Stripe-eseményt.
 *
 * A sorrend szándékos:
 *   1. esemény lefoglalása — mielőtt a számla elkészülne
 *   2. ha egy korábbi próbálkozás az Agent hívása közben akadt el:
 *      lekérdezés, hogy elkészült-e már a bizonylat
 *   3. számlakiállítás (vagy sztornó, helyesbítés)
 *   4. a sorszám visszaírása
 *
 * Minden hibát elkapunk, nem csak az Agentét. Ha csak azt kapnánk el, egy
 * adatbázis- vagy adathiba után a foglalás bent maradna sorszám nélkül, a
 * Stripe újraküldésére „már kész”-t mondanánk, és a számla csendben soha nem
 * készülne el.
 */
final class WebhookHandler
{
    public const EVENTS = ['invoice.payment_succeeded', 'charge.refunded'];

    /**
     * A Számlázz.hu szabálya: ugyanazt a kérést legfeljebb ötször szabad
     * elküldeni, utána ember kell. https://docs.szamlazz.hu/agent/basics/error-handling
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * @param \Closure(string, float, float): InvoiceItem $item  tételgyártó (név, mennyiség, egységár)
     * @param \Closure(string): void|null                 $alert riasztás; alapból error_log
     */
    public function __construct(
        private readonly ProcessedEvents $events,
        private readonly InvoicedCharges $charges,
        private readonly InvoiceAgent $agent,
        private readonly \Closure $item,
        private readonly ?\Closure $alert = null,
    ) {
    }

    /** @return int a HTTP-státusz, amit a Stripe-nak válaszolni kell */
    public function handle(object $event): int
    {
        if (!in_array($event->type, self::EVENTS, true)) {
            return 200;
        }

        $claim = $this->events->claim($event->id);
        if ($claim === ProcessedEvents::DONE) {
            return 200;   // a Stripe-nak nincs mit újraküldenie, nekünk nincs mit tennünk
        }
        if ($claim === ProcessedEvents::BUSY) {
            return 409;   // egy másik kérés dolgozik rajta; a Stripe később újra próbálja
        }

        // Igaz, amíg nem tudjuk biztosan, hogy bizonylat nem készült. Egy
        // korábban az Agent hívása közben elakadt eseménynél kezdettől igaz.
        $uncertain = $claim === ProcessedEvents::CHECK;
        try {
            if ($claim === ProcessedEvents::CHECK) {
                // Egy korábbi próbálkozás az Agent hívása közben akadt el:
                // nem tudjuk, elkészült-e a bizonylat. Előbb megnézzük.
                $number = $this->agent->find(externalId: $event->id);
                if ($number !== null) {
                    $this->finish($event, $number);
                    return 200;
                }
                // Biztosan nem készült el: kiállítható.
                $uncertain = false;
            }

            if ($this->events->attempts($event->id) >= self::MAX_ATTEMPTS) {
                $this->events->fail($event->id, 'Az Agent ' . self::MAX_ATTEMPTS . ' kérés után sem állította ki.');
                $this->alert("FELADVA, kézi számlázás kell: {$event->id} ({$event->type})");
                return 200;   // a Stripe ne küldje tovább; a riasztás szól az embernek
            }

            $plan = $this->plan($event);
            if ($plan === null) {
                $this->events->markInvoiced($event->id, null);   // nincs teendő
                return 200;
            }

            $this->events->issuing($event->id);
            $uncertain = true;
            try {
                $number = ($plan['run'])();
            } catch (AgentException $e) {
                if ($e->outcome !== AgentException::DUPLICATE) {
                    throw $e;
                }
                // „Már létező rendelésszám”: egy korábbi kérés elkészítette.
                $number = $this->agent->find(externalId: $plan['orderNumber'] === null ? $event->id : null, orderNumber: $plan['orderNumber'])
                    ?? throw new AgentException('Már létező rendelésszám, de a lekérdezés nem találja.', 0, AgentException::UNKNOWN);
            }

            $this->finish($event, $number);
            return 200;
        } catch (\Throwable $e) {
            // Csak akkor kezdhető újra lekérdezés nélkül, ha BIZTOS, hogy
            // bizonylat nem készült: az Agentet nem hívtuk (és korábban sem
            // akadt el a hívása), vagy kifejezett hibával utasította el.
            $sure = !$uncertain
                || ($e instanceof AgentException && $e->outcome === AgentException::REJECTED);
            $this->events->release($event->id, $sure ? 'claimed' : 'issuing', $e->getMessage());
            $this->alert(sprintf(
                'Számlázás sikertelen (%s): %s — esemény: %s',
                $sure ? 'nem készült bizonylat' : 'NEM TUDNI, elkészült-e; a következő próba lekérdez',
                $e->getMessage(),
                $event->id
            ));
            return 500;   // a Stripe újraküldi
        }
    }

    /**
     * Mit kell kiállítani. Hálózat nélküli lépés: ha itt hiba van (hiányzó
     * mező, üres tétellista), az Agentet még nem hívtuk.
     *
     * @return array{run: \Closure(): string, orderNumber: ?string}|null
     */
    private function plan(object $event): ?array
    {
        if ($event->type === 'invoice.payment_succeeded') {
            $stripeInvoice = $event->data->object;
            $customer = $stripeInvoice->customer_address ?? null;

            $items = [];
            foreach ($stripeInvoice->lines->data as $line) {
                $quantity = max(1, (int) ($line->quantity ?? 1));
                // A kedvezmény nem csökkenti a tétel összegét, külön mezőben
                // jön — enélkül a számlán a listaár szerepelne.
                $discount = 0;
                foreach ($line->discount_amounts ?? [] as $d) {
                    $discount += (int) $d->amount;
                }
                $unitPrice = Money::fromStripe((int) $line->amount - $discount, $stripeInvoice->currency) / $quantity;
                $items[] = ($this->item)($line->description ?? 'Szolgáltatás', (float) $quantity, $unitPrice);
            }

            $invoice = new Invoice(
                externalId: $event->id,
                buyerName: $stripeInvoice->customer_name ?? 'Vásárló',
                buyerPostcode: $customer->postal_code ?? '',
                buyerCity: $customer->city ?? '',
                buyerAddress: $customer->line1 ?? '',
                items: $items,
                currency: Money::invoiceCurrency($stripeInvoice->currency),
                buyerEmail: $stripeInvoice->customer_email ?? null,
                // Egy Stripe-számla = egy magyar számla, akárhány esemény jön is.
                orderNumber: $stripeInvoice->id,
            );
            return ['run' => fn (): string => $this->agent->issue($invoice), 'orderNumber' => $stripeInvoice->id];
        }

        // charge.refunded
        $charge = $event->data->object;
        $original = $this->charges->find($charge->id);

        // Nincs nyoma: ez a terhelés nem ezen a rendszeren kapott számlát.
        $decision = $original === null
            ? null
            : Refund::decide((int) $charge->amount_refunded, (bool) $charge->refunded, $original['refunded']);
        if ($decision === null) {
            return null;
        }

        $billing = $charge->billing_details ?? null;
        $email = $billing->email ?? $charge->receipt_email ?? null;

        if ($decision->action === Refund::REVERSE) {
            return [
                'run' => fn (): string => $this->agent->reverse($original['invoice_number'], $email, $event->id),
                'orderNumber' => null,   // a sztornó mentes a rendelésszám-tiltás alól
            ];
        }

        $amount = Money::fromStripe($decision->amount, $original['currency']);
        $correction = new Invoice(
            externalId: $event->id,
            buyerName: $billing->name ?? 'Vásárló',
            buyerPostcode: $billing->address->postal_code ?? '',
            buyerCity: $billing->address->city ?? '',
            buyerAddress: $billing->address->line1 ?? '',
            // Negatív mennyiség: a helyesbítő számla a visszatérített részt vonja le.
            items: [($this->item)('Visszatérítés', -1.0, $amount)],
            currency: Money::invoiceCurrency($original['currency']),
            buyerEmail: $email,
            correctsInvoiceNumber: $original['invoice_number'],
        );
        // A helyesbítő is mentes a tiltás alól: itt a saját foglalás és a
        // halmozott összeg (Refund::decide) véd a duplától.
        return ['run' => fn (): string => $this->agent->issue($correction), 'orderNumber' => null];
    }

    /**
     * A sorszám visszaírása és ami hozzá tartozik. Ismételten is lefuthat
     * (a lekérdezéses ágon is), ezért minden lépése ismételhető.
     */
    private function finish(object $event, string $number): void
    {
        $object = $event->data->object;
        if ($event->type === 'invoice.payment_succeeded') {
            // A visszatérítéshez tudni kell majd, melyik terhelésről melyik
            // számla készült — a visszatérítési esemény ezt nem hozza magával.
            if (!empty($object->charge)) {
                $this->charges->record($object->charge, $number, $object->currency, (int) $object->amount_paid);
            }
        } else {
            $this->charges->setRefunded($object->id, (int) $object->amount_refunded);
        }
        $this->events->markInvoiced($event->id, $number);
    }

    private function alert(string $message): void
    {
        if ($this->alert !== null) {
            ($this->alert)($message);
            return;
        }
        error_log($message);
    }
}
