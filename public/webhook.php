<?php

declare(strict_types=1);

/**
 * Stripe webhook — sikeres terhelésből magyar számla, visszatérítésből
 * sztornó vagy helyesbítő számla.
 *
 * A sorrend szándékos, és ez a fájl lényege:
 *   1. aláírás-ellenőrzés  — mielőtt BÁRMIT kezdenénk a tartalommal
 *   2. esemény lefoglalása — mielőtt a számla elkészülne
 *   3. számlakiállítás (vagy sztornó, helyesbítés)
 *   4. a sorszám visszaírása
 *
 * Ha a 3. lépés elszáll, elengedjük a foglalást, hogy a Stripe
 * újraküldése tudjon próbálkozni.
 *
 * A mezőnevek a stripe-php 13-as ágához tartozó API-verziót (2023-10-16)
 * követik. Az újabb API-verziókban a számla és a terhelés kapcsolata máshol
 * van (`invoice.charge` helyett a számla kifizetései) — ott ezt a helyet
 * kell igazítani.
 */

require __DIR__ . '/../vendor/autoload.php';

use KK\StripeSzamlazz\{AgentException, Invoice, InvoiceItem, InvoicedCharges, Money, ProcessedEvents, Refund, SzamlazzAgent};

$config = require __DIR__ . '/../config.php';

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// 1. Aláírás-ellenőrzés. Ez ELŐZI a naplózást is: enélkül bárki, aki
//    ismeri a végpont címét, tetszőleges adatot írathatna a naplóba —
//    a hamis "sikeres fizetés" eseményen túl ez magában is támadási felület.
try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $signature,
        $config['stripe_webhook_secret']
    );
} catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// Előfizetésnél a megújulásokról CSAK a számlázási esemény szól — a
// vásárló ilyenkor nem jár az oldalon. Ezért ehhez kötünk, nem a
// fizetős oldal lezárásához: ez az első vásárlásnál is megérkezik.
// A visszatérítésről a terhelés eseménye szól.
if (!in_array($event->type, ['invoice.payment_succeeded', 'charge.refunded'], true)) {
    http_response_code(200);
    exit;
}

$pdo = new PDO(
    $config['dsn'],
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$events = new ProcessedEvents($pdo);
$charges = new InvoicedCharges($pdo);
$agent = new SzamlazzAgent($config['szamlazz_agent_key']);

// A Stripe azt az összeget adja, amit a vevő fizetett. Magyar fogyasztói
// árnál ez bruttó; ha a Stripe-ban nettó árak vannak, és az áfát a Stripe
// Tax teszi rá, a configban false. Alanyi adómentesnél (AAM) mindegy.
$item = static fn (string $name, float $quantity, float $unitPrice): InvoiceItem =>
    ($config['prices_include_vat'] ?? true)
        ? InvoiceItem::fromGross($name, $quantity, 'db', $unitPrice, $config['vat_key'])
        : new InvoiceItem($name, $quantity, 'db', $unitPrice, $config['vat_key']);

// 2. Lefoglalás. Ha már feldolgoztuk, 200-zal nyugtázunk: a Stripe-nak
//    nincs mit újraküldenie, nekünk nincs mit tennünk.
if (!$events->claim($event->id)) {
    http_response_code(200);
    exit;
}

try {
    if ($event->type === 'invoice.payment_succeeded') {
        $stripeInvoice = $event->data->object;
        $customer = $stripeInvoice->customer_address ?? null;

        $items = [];
        foreach ($stripeInvoice->lines->data as $line) {
            $quantity = max(1, (int) ($line->quantity ?? 1));
            // A kedvezmény nem csökkenti a tétel összegét, külön mezőben
            // jön — enélkül a számlán a listaár szerepelne, nem az, amit a
            // vevő ténylegesen fizetett.
            $discount = 0;
            foreach ($line->discount_amounts ?? [] as $d) {
                $discount += (int) $d->amount;
            }
            $unitPrice = Money::fromStripe((int) $line->amount - $discount, $stripeInvoice->currency) / $quantity;
            $items[] = $item($line->description ?? 'Szolgáltatás', $quantity, $unitPrice);
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
        );

        $number = $agent->issue($invoice);          // 3. számlakiállítás
        $events->markInvoiced($event->id, $number); // 4. sorszám visszaírása

        // A visszatérítéshez tudni kell majd, melyik terhelésről melyik
        // számla készült — a visszatérítési esemény ezt nem hozza magával.
        if (!empty($stripeInvoice->charge)) {
            $charges->record($stripeInvoice->charge, $number, $stripeInvoice->currency, (int) $stripeInvoice->amount_paid);
        }
    } else {
        $charge = $event->data->object;
        $original = $charges->find($charge->id);

        // Nincs nyoma: ez a terhelés nem ezen a rendszeren kapott számlát.
        $plan = $original === null
            ? null
            : Refund::decide((int) $charge->amount_refunded, (bool) $charge->refunded, $original['refunded']);

        if ($plan !== null) {
            $billing = $charge->billing_details ?? null;
            $email = $billing->email ?? $charge->receipt_email ?? null;

            if ($plan->action === Refund::REVERSE) {
                $number = $agent->reverse($original['invoice_number'], $email);   // 3. sztornó
            } else {
                $amount = Money::fromStripe($plan->amount, $original['currency']);
                $number = $agent->issue(new Invoice(                            // 3. helyesbítő számla
                    externalId: $event->id,
                    buyerName: $billing->name ?? 'Vásárló',
                    buyerPostcode: $billing->address->postal_code ?? '',
                    buyerCity: $billing->address->city ?? '',
                    buyerAddress: $billing->address->line1 ?? '',
                    // Negatív mennyiség: a helyesbítő számla a visszatérített részt vonja le.
                    items: [$item('Visszatérítés', -1, $amount)],
                    currency: Money::invoiceCurrency($original['currency']),
                    buyerEmail: $email,
                    correctsInvoiceNumber: $original['invoice_number'],
                ));
            }

            $charges->setRefunded($charge->id, (int) $charge->amount_refunded);
            $events->markInvoiced($event->id, $number); // 4. sorszám visszaírása
        }
    }
    http_response_code(200);
} catch (AgentException $e) {
    // A pénzmozgás megtörtént, csak a bizonylat nem készült el. A foglalást
    // elengedjük, hogy az újraküldés próbálkozhasson — és értesítünk, mert
    // az elmaradt számlának LÁTSZANIA kell, nem csendben eltűnnie.
    $events->release($event->id);
    error_log('Számlázás sikertelen: ' . $e->getMessage() . ' — esemény: ' . $event->id);
    http_response_code(500);
}
