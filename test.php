<?php
declare(strict_types=1);
require __DIR__ . '/src/AgentException.php';
require __DIR__ . '/src/Invoice.php';
require __DIR__ . '/src/SzamlazzAgent.php';

use KK\StripeSzamlazz\{Invoice, InvoiceItem, SzamlazzAgent};

$item = new InvoiceItem('Havidíjas karbantartás', 1, 'hó', 10000.0, '27');
assert($item->netTotal() === 10000.0, 'nettó');
assert($item->vatTotal() === 2700.0, 'áfa');
assert($item->grossTotal() === 12700.0, 'bruttó');
echo "számítás OK: 10000 + 27% = {$item->grossTotal()}\n";

$aam = new InvoiceItem('Alanyi mentes tétel', 2, 'db', 5000.0, 'AAM');
assert($aam->vatTotal() === 0.0, 'AAM-nál nincs áfa');
assert($aam->grossTotal() === 10000.0, 'AAM bruttó');
echo "AAM OK: 2 × 5000 = {$aam->grossTotal()}, áfa {$aam->vatTotal()}\n";

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

$xml = (new SzamlazzAgent('DEMO-AGENT-KULCS'))->buildXml($invoice);

// XML jólformáltság + a kötelező elemek megléte
$doc = new DOMDocument();
if (!$doc->loadXML($xml)) { exit("HIBA: nem jólformált XML\n"); }
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
    $n = $xp->query($path);
    if ($n->length === 0) { exit("HIÁNYZIK: {$path}\n"); }
}
echo "XML jólformált, minden kötelező elem megvan\n";

// üres tételsor elutasítása
try {
    new Invoice('e', 'n', '1', 'B', 'c', []);
    exit("HIBA: az üres tételsort elfogadta\n");
} catch (InvalidArgumentException) {
    echo "üres tételsor elutasítva OK\n";
}
echo "\n--- a generált XML ---\n" . $xml;
