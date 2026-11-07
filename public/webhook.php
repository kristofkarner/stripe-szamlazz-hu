<?php

declare(strict_types=1);

/**
 * Stripe webhook — sikeres terhelésből magyar számla, visszatérítésből
 * sztornó vagy helyesbítő számla.
 *
 * Ez a fájl csak két dolgot csinál: ellenőrzi az aláírást, és továbbadja az
 * eseményt a WebhookHandlernek. A döntések ott vannak, hálózat nélkül
 * tesztelve (test.php).
 *
 * A mezőnevek a stripe-php 13-as ágához tartozó API-verziót (2023-10-16)
 * követik. Az újabb API-verziókban a számla és a terhelés kapcsolata máshol
 * van (`invoice.charge` helyett a számla kifizetései) — ott a
 * WebhookHandler::finish() elejét kell igazítani.
 */

require __DIR__ . '/../vendor/autoload.php';

use KK\StripeSzamlazz\{InvoiceItem, InvoicedCharges, ProcessedEvents, SzamlazzAgent, WebhookHandler};

$config = require __DIR__ . '/../config.php';

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Aláírás-ellenőrzés. Ez ELŐZI a naplózást is: enélkül bárki, aki ismeri a
// végpont címét, tetszőleges adatot írathatna a naplóba — a hamis „sikeres
// fizetés” eseményen túl ez magában is támadási felület.
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

// Előfizetésnél a megújulásokról CSAK a számlázási esemény szól — a vásárló
// ilyenkor nem jár az oldalon. Ezért ehhez kötünk, nem a fizetős oldal
// lezárásához: ez az első vásárlásnál is megérkezik. A visszatérítésről a
// terhelés eseménye szól.
if (!in_array($event->type, WebhookHandler::EVENTS, true)) {
    http_response_code(200);
    exit;
}

try {
    $pdo = new PDO(
        $config['dsn'],
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) {
    // Adatbázis nélkül foglalni sem tudunk: a Stripe próbálja újra.
    error_log('Számlázás: az adatbázis nem érhető el — esemény: ' . $event->id);
    http_response_code(500);
    exit;
}

// A Stripe azt az összeget adja, amit a vevő fizetett. Magyar fogyasztói
// árnál ez bruttó; ha a Stripe-ban nettó árak vannak, és az áfát a Stripe
// Tax teszi rá, a configban false. Alanyi adómentesnél (AAM) mindegy.
$item = static fn (string $name, float $quantity, float $unitPrice): InvoiceItem =>
    ($config['prices_include_vat'] ?? true)
        ? InvoiceItem::fromGross($name, $quantity, 'db', $unitPrice, $config['vat_key'])
        : new InvoiceItem($name, $quantity, 'db', $unitPrice, $config['vat_key']);

$handler = new WebhookHandler(
    new ProcessedEvents($pdo),
    new InvoicedCharges($pdo),
    new SzamlazzAgent($config['szamlazz_agent_key']),
    $item,
    // Riasztás: az elmaradt számlának LÁTSZANIA kell. Éles rendszerben ide
    // e-mail vagy üzenet kerül, nem csak a napló.
    static fn (string $message) => error_log($message),
);

http_response_code($handler->handle($event));
