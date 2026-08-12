<?php

declare(strict_types=1);

/**
 * Stripe webhook — sikeres terhelésből magyar számla.
 *
 * A sorrend szándékos, és ez a fájl lényege:
 *   1. aláírás-ellenőrzés  — mielőtt BÁRMIT kezdenénk a tartalommal
 *   2. esemény lefoglalása — mielőtt a számla elkészülne
 *   3. számlakiállítás
 *   4. a sorszám visszaírása
 *
 * Ha a 3. lépés elszáll, elengedjük a foglalást, hogy a Stripe
 * újraküldése tudjon próbálkozni.
 */

require __DIR__ . '/../vendor/autoload.php';

use KK\StripeSzamlazz\{Invoice, InvoiceItem, ProcessedEvents, SzamlazzAgent, AgentException};

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
if ($event->type !== 'invoice.payment_succeeded') {
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

// 2. Lefoglalás. Ha már feldolgoztuk, 200-zal nyugtázunk: a Stripe-nak
//    nincs mit újraküldenie, nekünk nincs mit tennünk.
if (!$events->claim($event->id)) {
    http_response_code(200);
    exit;
}

try {
    $stripeInvoice = $event->data->object;
    $customer = $stripeInvoice->customer_address ?? null;

    $items = [];
    foreach ($stripeInvoice->lines->data as $line) {
        $items[] = new InvoiceItem(
            name: $line->description ?? 'Szolgáltatás',
            quantity: (float) ($line->quantity ?? 1),
            unit: 'db',
            // A Stripe a legkisebb egységben számol: forintnál nincs
            // váltópénz, más devizánál századokban jön az összeg.
            netUnitPrice: $stripeInvoice->currency === 'huf'
                ? (float) $line->amount / max(1, (int) ($line->quantity ?? 1))
                : (float) $line->amount / 100 / max(1, (int) ($line->quantity ?? 1)),
            vatKey: $config['vat_key'],
        );
    }

    $invoice = new Invoice(
        externalId: $event->id,
        buyerName: $stripeInvoice->customer_name ?? 'Vásárló',
        buyerPostcode: $customer->postal_code ?? '',
        buyerCity: $customer->city ?? '',
        buyerAddress: $customer->line1 ?? '',
        items: $items,
        currency: strtoupper($stripeInvoice->currency) === 'HUF' ? 'Ft' : strtoupper($stripeInvoice->currency),
        buyerEmail: $stripeInvoice->customer_email ?? null,
    );

    $agent = new SzamlazzAgent($config['szamlazz_agent_key']);
    $number = $agent->issue($invoice);          // 3. számlakiállítás

    $events->markInvoiced($event->id, $number); // 4. sorszám visszaírása
    http_response_code(200);
} catch (AgentException $e) {
    // A fizetés sikeres volt, csak a számla nem készült el. A foglalást
    // elengedjük, hogy az újraküldés próbálkozhasson — és értesítünk,
    // mert az elmaradt számlának LÁTSZANIA kell, nem csendben eltűnnie.
    $events->release($event->id);
    error_log('Számlakiállítás sikertelen: ' . $e->getMessage() . ' — esemény: ' . $event->id);
    http_response_code(500);
}
