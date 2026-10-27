<?php
declare(strict_types=1);
require __DIR__ . '/src/AgentException.php';
require __DIR__ . '/src/InvoiceItem.php';
require __DIR__ . '/src/Invoice.php';
require __DIR__ . '/src/Money.php';
require __DIR__ . '/src/Refund.php';
require __DIR__ . '/src/SzamlazzAgent.php';

use KK\StripeSzamlazz\{AgentException, Invoice, InvoiceItem, Money, Refund, SzamlazzAgent};

// Saját ellenőrzés az assert() helyett: az assert éles PHP-beállításnál
// (zend.assertions = -1) kikapcsol, és akkor a teszt hibás számításnál is
// „OK”-t írna. Ez a függvény minden beállítás mellett megállítja a futást.
function check(bool $ok, string $mit): void
{
    if (!$ok) {
        fwrite(STDERR, "HIBA: {$mit}\n");
        exit(1);
    }
}

$item = new InvoiceItem('Havidíjas karbantartás', 1, 'hó', 10000.0, '27');
check($item->netTotal() === 10000.0, 'nettó');
check($item->vatTotal() === 2700.0, 'áfa');
check($item->grossTotal() === 12700.0, 'bruttó');
echo "számítás OK: 10000 + 27% = {$item->grossTotal()}\n";

$aam = new InvoiceItem('Alanyi mentes tétel', 2, 'db', 5000.0, 'AAM');
check($aam->vatTotal() === 0.0, 'AAM-nál nincs áfa');
check($aam->grossTotal() === 10000.0, 'AAM bruttó');
echo "AAM OK: 2 × 5000 = {$aam->grossTotal()}, áfa {$aam->vatTotal()}\n";

// Bruttóból visszafelé: a Stripe azt adja, amit a vevő fizetett.
$gross = InvoiceItem::fromGross('Előfizetés', 1, 'db', 12700.0, '27');
check($gross->netTotal() === 10000.0 && $gross->grossTotal() === 12700.0, 'bruttóból nettó');
check(InvoiceItem::fromGross('x', 1, 'db', 5000.0, 'AAM')->netTotal() === 5000.0, 'AAM bruttó = nettó');
echo "bruttóból OK: 12700 → nettó {$gross->netTotal()} + áfa {$gross->vatTotal()}\n";

// A forint a Stripe-ban kéttizedes: 10 000 Ft = 1000000.
check(Money::fromStripe(1000000, 'huf') === 10000.0, 'forint: osztás százzal');
check(Money::fromStripe(1099, 'eur') === 10.99, 'euró');
check(Money::fromStripe(500, 'jpy') === 500.0, 'jen: nulla tizedes');
check(Money::invoiceCurrency('huf') === 'Ft' && Money::invoiceCurrency('eur') === 'EUR', 'pénznem-jelölés');
echo "pénznem OK: 1000000 HUF-egység = " . Money::fromStripe(1000000, 'huf') . " Ft\n";

// A visszatérítés döntése: teljes → sztornó, részleges → helyesbítő,
// a halmozott összegből csak a különbség.
$r = Refund::decide(10000, true, 0);
check($r !== null && $r->action === Refund::REVERSE && $r->amount === 10000, 'teljes → sztornó');
$r = Refund::decide(4000, false, 0);
check($r !== null && $r->action === Refund::CORRECT && $r->amount === 4000, 'részleges → helyesbítő');
$r = Refund::decide(10000, true, 4000);
check($r !== null && $r->action === Refund::CORRECT && $r->amount === 6000, 'a maradék is helyesbítő');
check(Refund::decide(4000, false, 4000) === null, 'ugyanaz a halmozott összeg → nincs teendő');
check(Refund::decide(3000, false, 4000) === null, 'késve érkező régebbi esemény → nincs teendő');
echo "visszatérítés OK: sztornó, helyesbítő, különbség, késői esemény\n";

$invoice = new Invoice(
    externalId: 'evt_test_123',
    buyerName: 'Teszt Kft.',
    buyerPostcode: '1021',
    buyerCity: 'Budapest',
    buyerAddress: 'Példa utca 1.',
    items: [$item],
    buyerEmail: 'teszt@example.com',
);
echo "dátumok: kelt={$invoice->issueDate} teljesítés={$invoice->fulfilmentDate}\n";

$agent = new SzamlazzAgent('DEMO-AGENT-KULCS');
$xml = $agent->buildXml($invoice);

// XML jólformáltság + a kötelező elemek megléte
$doc = new DOMDocument();
check($doc->loadXML($xml), 'jólformált számla-XML');
$xp = new DOMXPath($doc);
$xp->registerNamespace('sz', 'http://www.szamlazz.hu/xmlszamla');
foreach ([
    '/sz:xmlszamla/sz:beallitasok/sz:szamlaagentkulcs',
    '/sz:xmlszamla/sz:fejlec/sz:teljesitesDatum',
    '/sz:xmlszamla/sz:fejlec/sz:fizetesiHataridoDatum',
    '/sz:xmlszamla/sz:fejlec/sz:penznem',
    '/sz:xmlszamla/sz:vevo/sz:nev',
    '/sz:xmlszamla/sz:tetelek/sz:tetel/sz:nettoErtek',
    '/sz:xmlszamla/sz:tetelek/sz:tetel/sz:bruttoErtek',
] as $path) {
    check($xp->query($path)->length > 0, "hiányzik: {$path}");
}
check($xp->query('/sz:xmlszamla/sz:fejlec/sz:helyesbitoszamla')->length === 0, 'sima számlán nincs helyesbítés');
echo "XML jólformált, minden kötelező elem megvan\n";

// Helyesbítő számla: a jelölés a fejlécben, a kötött sorrend szerint a
// számla nyelve után, és negatív tétel.
$correction = new Invoice(
    externalId: 'evt_refund_1',
    buyerName: 'Teszt Kft.',
    buyerPostcode: '1021',
    buyerCity: 'Budapest',
    buyerAddress: 'Példa utca 1.',
    items: [InvoiceItem::fromGross('Visszatérítés', -1, 'db', 1270.0, '27')],
    correctsInvoiceNumber: 'E-TST-2026-1',
);
$doc = new DOMDocument();
check($doc->loadXML($agent->buildXml($correction)), 'jólformált helyesbítő XML');
$xp = new DOMXPath($doc);
$xp->registerNamespace('sz', 'http://www.szamlazz.hu/xmlszamla');
check($xp->evaluate('string(/sz:xmlszamla/sz:fejlec/sz:helyesbitoszamla)') === 'true', 'helyesbítő jelölés');
check($xp->evaluate('string(/sz:xmlszamla/sz:fejlec/sz:helyesbitettSzamlaszam)') === 'E-TST-2026-1', 'hivatkozott számla');
check(
    $xp->evaluate('local-name(/sz:xmlszamla/sz:fejlec/sz:helyesbitoszamla/preceding-sibling::*[1])') === 'szamlaNyelve',
    'kötött sorrend: a helyesbítés a nyelv után jön'
);
check($xp->evaluate('string(/sz:xmlszamla/sz:tetelek/sz:tetel/sz:bruttoErtek)') === '-1270.00', 'negatív bruttó');
echo "helyesbítő számla OK: hivatkozás, sorrend, negatív tétel\n";

// Sztornó: külön XML-fajta, a számla számával.
$doc = new DOMDocument();
check($doc->loadXML($agent->buildReversalXml('E-TST-2026-1', 'teszt@example.com', '2026-10-27')), 'jólformált sztornó-XML');
$xp = new DOMXPath($doc);
$xp->registerNamespace('st', 'http://www.szamlazz.hu/xmlszamlast');
check($xp->evaluate('string(/st:xmlszamlast/st:fejlec/st:szamlaszam)') === 'E-TST-2026-1', 'sztornózott számla száma');
check($xp->evaluate('string(/st:xmlszamlast/st:fejlec/st:tipus)') === 'SS', 'sztornó típus');
check($xp->evaluate('string(/st:xmlszamlast/st:vevo/st:email)') === 'teszt@example.com', 'értesítési cím');
echo "sztornó OK\n";

// A válaszfejlécek: a sorszám és a hibaszöveg URL-kódolva jön, a hibaszöveg
// fejléce `szlahu_error`.
check(SzamlazzAgent::invoiceNumberFrom(200, ['szlahu_szamlaszam' => 'E-TST-2026-2']) === 'E-TST-2026-2', 'sorszám a fejlécből');
try {
    SzamlazzAgent::invoiceNumberFrom(200, ['szlahu_error_code' => '57', 'szlahu_error' => rawurlencode('Hibás kulcs')]);
    check(false, 'a hibafejlécet nem vette észre');
} catch (AgentException $e) {
    check(str_contains($e->getMessage(), 'Hibás kulcs') && $e->getCode() === 57, 'hibaszöveg dekódolva');
}
echo "válaszfejlécek OK: 200-as státusz mellett is látszik a hiba\n";

// üres tételsor elutasítása
try {
    new Invoice('e', 'n', '1', 'B', 'c', []);
    check(false, 'az üres tételsort elfogadta');
} catch (InvalidArgumentException) {
    echo "üres tételsor elutasítva OK\n";
}
echo "\n--- a generált XML ---\n" . $xml;
