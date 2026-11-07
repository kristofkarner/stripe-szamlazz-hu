<?php
declare(strict_types=1);
require __DIR__ . '/src/AgentException.php';
require __DIR__ . '/src/InvoiceItem.php';
require __DIR__ . '/src/Invoice.php';
require __DIR__ . '/src/Money.php';
require __DIR__ . '/src/Refund.php';
require __DIR__ . '/src/InvoiceAgent.php';
require __DIR__ . '/src/SzamlazzAgent.php';
require __DIR__ . '/src/ProcessedEvents.php';
require __DIR__ . '/src/InvoicedCharges.php';
require __DIR__ . '/src/WebhookHandler.php';

use KK\StripeSzamlazz\{AgentException, Invoice, InvoiceAgent, InvoiceItem, InvoicedCharges, Money, ProcessedEvents, Refund, SzamlazzAgent, WebhookHandler};

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
try {
    SzamlazzAgent::invoiceNumberFrom(200, ['szlahu_error_code' => '57']);
} catch (AgentException $e) {
    check($e->outcome === AgentException::REJECTED, 'kifejezett hiba → nem készült bizonylat');
}
try {
    SzamlazzAgent::invoiceNumberFrom(200, ['szlahu_error_code' => '152', 'szlahu_error' => rawurlencode('Már létező rendelésszám: in_1')]);
} catch (AgentException $e) {
    check($e->outcome === AgentException::DUPLICATE, '152 → már létezik');
}
try {
    SzamlazzAgent::invoiceNumberFrom(200, []);
} catch (AgentException $e) {
    check($e->outcome === AgentException::UNKNOWN, 'sorszám nélküli válasz → ismeretlen kimenetel');
}
echo "válaszfejlécek OK: 200-as státusz mellett is látszik a hiba, és az is, mit tudunk a bizonylatról\n";

// A lekérdezés válasza: siker = szamla XML, 7-es hiba = nincs ilyen, más hiba = nem tudjuk.
$found = '<?xml version="1.0" encoding="UTF-8"?><szamla xmlns="http://www.szamlazz.hu/szamla"><szallito><id>1</id></szallito>'
    . '<alap><id>9</id><szamlaszam>E-TST-2026-7</szamlaszam><tipus>SZ</tipus></alap></szamla>';
check(SzamlazzAgent::invoiceNumberFromQuery(200, [], $found) === 'E-TST-2026-7', 'lekérdezés: sorszám');
$missing = '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz">'
    . '<sikeres>false</sikeres><hibakod>7</hibakod><hibauzenet>Hiányzó adat</hibauzenet></xmlszamlavalasz>';
check(SzamlazzAgent::invoiceNumberFromQuery(200, [], $missing) === null, 'lekérdezés: 7 → nincs ilyen');
check(SzamlazzAgent::invoiceNumberFromQuery(200, ['szlahu_error_code' => '7'], '') === null, 'lekérdezés: 7 a fejlécben');
try {
    SzamlazzAgent::invoiceNumberFromQuery(200, [], '<html>hiba</html');
    check(false, 'az olvashatatlan választ elfogadta');
} catch (AgentException $e) {
    check($e->outcome === AgentException::UNKNOWN, 'olvashatatlan válasz → nem tudjuk');
}
$q = new DOMDocument();
check($q->loadXML($agent->buildQueryXml('evt_1', 'in_1')), 'jólformált lekérdező XML');
$qx = new DOMXPath($q);
$qx->registerNamespace('q', 'http://www.szamlazz.hu/xmlszamlaxml');
check(
    $qx->evaluate('local-name(/q:xmlszamlaxml/q:szamlaKulsoAzon/preceding-sibling::*[1])') === 'rendelesSzam',
    'kötött sorrend: rendelésszám, aztán külső azonosító'
);
echo "lekérdezés OK: talált, nincs ilyen, olvashatatlan\n";

// Rendelésszám a számlán: az árfolyam után, a helyesbítés előtt (kötött sorrend).
$withOrder = new Invoice(
    externalId: 'evt_x', buyerName: 'Teszt Kft.', buyerPostcode: '1021', buyerCity: 'Budapest',
    buyerAddress: 'Példa utca 1.', items: [$item], orderNumber: 'in_test_1',
);
$doc = new DOMDocument();
check($doc->loadXML($agent->buildXml($withOrder)), 'jólformált XML rendelésszámmal');
$xp = new DOMXPath($doc);
$xp->registerNamespace('sz', 'http://www.szamlazz.hu/xmlszamla');
check($xp->evaluate('string(/sz:xmlszamla/sz:fejlec/sz:rendelesSzam)') === 'in_test_1', 'rendelésszám a fejlécben');
check(
    $xp->evaluate('local-name(/sz:xmlszamla/sz:fejlec/sz:rendelesSzam/preceding-sibling::*[1])') === 'szamlaNyelve',
    'kötött sorrend: a rendelésszám a nyelv után (árfolyam nélkül)'
);
$doc = new DOMDocument();
check($doc->loadXML($agent->buildReversalXml('E-TST-2026-1', null, '2026-10-27', 'evt_r')), 'sztornó külső azonosítóval');
$xp = new DOMXPath($doc);
$xp->registerNamespace('st', 'http://www.szamlazz.hu/xmlszamlast');
check($xp->evaluate('string(/st:xmlszamlast/st:beallitasok/st:szamlaKulsoAzon)') === 'evt_r', 'a sztornó is visszakereshető');
echo "rendelésszám OK, a sztornó külső azonosítóval\n";

// üres tételsor elutasítása
try {
    new Invoice('e', 'n', '1', 'B', 'c', []);
    check(false, 'az üres tételsort elfogadta');
} catch (InvalidArgumentException) {
    echo "üres tételsor elutasítva OK\n";
}
// ---------------------------------------------------------------------------
// A webhook hibakezelése, hálózat nélkül: SQLite a memóriában, hamis Agent.
// A kérdés mindig ugyanaz: a végén pontosan egy bizonylat van-e, és ha
// valami elakadt, LÁTSZIK-e (riasztás), vagy csendben elveszett.
// ---------------------------------------------------------------------------

/** Hamis Számla Agent: feljegyzi, mit állított ki, és előre beállított hibákat dob. */
final class FakeAgent implements InvoiceAgent
{
    /** @var array<string, array{number: string, order: ?string}> külső azonosító → bizonylat */
    public array $issued = [];
    /** @var list<array{0: string, 1: bool}> a következő hívások hibái: [kimenetel, elkészül-e közben] */
    public array $failNext = [];
    public bool $queryFails = false;
    public int $calls = 0;
    public int $queries = 0;

    private function create(string $externalId, ?string $order): string
    {
        $this->calls++;
        if ($order !== null) {
            foreach ($this->issued as $doc) {
                if ($doc['order'] === $order) {
                    throw new AgentException("Már létező rendelésszám: {$order}", 152, AgentException::DUPLICATE);
                }
            }
        }
        $failure = array_shift($this->failNext);
        $number = 'E-TST-' . (count($this->issued) + 1);
        if ($failure !== null) {
            [$outcome, $created] = $failure;
            if ($created) {
                $this->issued[$externalId] = ['number' => $number, 'order' => $order];
            }
            throw new AgentException("hamis hiba ({$outcome})", 0, $outcome);
        }
        $this->issued[$externalId] = ['number' => $number, 'order' => $order];
        return $number;
    }

    public function issue(Invoice $invoice): string
    {
        return $this->create($invoice->externalId, $invoice->orderNumber);
    }

    public function reverse(string $invoiceNumber, ?string $buyerEmail = null, ?string $externalId = null): string
    {
        return $this->create($externalId ?? 'st-' . $invoiceNumber, null);
    }

    public function find(?string $externalId = null, ?string $orderNumber = null): ?string
    {
        $this->queries++;
        if ($this->queryFails) {
            throw new AgentException('a lekérdezés sem megy', 0, AgentException::UNKNOWN);
        }
        foreach ($this->issued as $ext => $doc) {
            if (($externalId !== null && $ext === $externalId) || ($orderNumber !== null && $doc['order'] === $orderNumber)) {
                return $doc['number'];
            }
        }
        return null;
    }
}

/** @return array{0: WebhookHandler, 1: FakeAgent, 2: ProcessedEvents, 3: \PDO, 4: ArrayObject} */
function harness(?Closure $clock = null): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE processed_events (event_id TEXT PRIMARY KEY, state TEXT NOT NULL DEFAULT 'claimed',
        attempts INTEGER NOT NULL DEFAULT 0, claimed_at TEXT NOT NULL, invoice_number TEXT NULL,
        invoiced_at TEXT NULL, last_error TEXT NULL)");
    $pdo->exec('CREATE TABLE invoiced_charges (stripe_charge TEXT PRIMARY KEY, invoice_number TEXT NOT NULL,
        currency TEXT NOT NULL, amount INTEGER NOT NULL, refunded INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)');
    $agent = new FakeAgent();
    $events = new ProcessedEvents($pdo, $clock);
    $alerts = new ArrayObject();
    $handler = new WebhookHandler(
        $events,
        new InvoicedCharges($pdo),
        $agent,
        static fn (string $n, float $q, float $p): InvoiceItem => InvoiceItem::fromGross($n, $q, 'db', $p, '27'),
        static function (string $m) use ($alerts): void { $alerts[] = $m; },
    );
    return [$handler, $agent, $events, $pdo, $alerts];
}

function stripeEvent(array $data): object
{
    return json_decode(json_encode($data), false);
}

function paidInvoice(string $eventId, string $invoiceId = 'in_1', string $chargeId = 'ch_1'): object
{
    return stripeEvent(['id' => $eventId, 'type' => 'invoice.payment_succeeded', 'data' => ['object' => [
        'id' => $invoiceId, 'currency' => 'huf', 'charge' => $chargeId, 'amount_paid' => 1270000,
        'customer_name' => 'Teszt Elek', 'customer_email' => 'teszt@example.com',
        'customer_address' => ['postal_code' => '1021', 'city' => 'Budapest', 'line1' => 'Példa utca 1.'],
        'lines' => ['data' => [['amount' => 1270000, 'quantity' => 1, 'description' => 'Havi tagság', 'discount_amounts' => []]]],
    ]]]);
}

function refundEvent(string $eventId, int $refunded, bool $full, string $chargeId = 'ch_1'): object
{
    return stripeEvent(['id' => $eventId, 'type' => 'charge.refunded', 'data' => ['object' => [
        'id' => $chargeId, 'amount_refunded' => $refunded, 'refunded' => $full,
        'billing_details' => ['name' => 'Teszt Elek', 'email' => 'teszt@example.com',
            'address' => ['postal_code' => '1021', 'city' => 'Budapest', 'line1' => 'Példa utca 1.']],
    ]]]);
}

// Alapeset: egy esemény → egy számla; ugyanaz újra → nincs második.
[$h, $a, $ev] = harness();
check($h->handle(paidInvoice('evt_ok')) === 200 && count($a->issued) === 1, 'alapeset: 1 számla');
check($h->handle(paidInvoice('evt_ok')) === 200 && count($a->issued) === 1, 'újraküldés: nincs második számla');
check($ev->get('evt_ok')['state'] === 'done', 'alapeset: kész állapot');
echo "webhook alapeset OK\n";

// T1: az Agent kifejezetten elutasítja → biztosan nem készült; a következő
// kézbesítés lekérdezés nélkül kiállítja. Végül 1 számla.
[$h, $a, $ev, , $al] = harness();
$a->failNext[] = [AgentException::REJECTED, false];
check($h->handle(paidInvoice('evt_t1')) === 500, 'T1: elutasítás → 500, a Stripe újraküldi');
check($ev->get('evt_t1')['state'] === 'claimed' && count($al) === 1, 'T1: elengedve, riasztás ment');
check($h->handle(paidInvoice('evt_t1')) === 200 && count($a->issued) === 1 && $a->queries === 0, 'T1: második kézbesítés → 1 számla, lekérdezés nélkül');
echo "T1 OK: kifejezett Agent-hiba → újrapróba, 1 számla\n";

// T2: a számla elkészül, de a sorszám visszaírása előtt adatbázis-hiba jön.
// A régi kód ezt nem kapta el: a foglalás bent maradt, és a számla elveszett.
[$h, $a, $ev, $pdo, $al] = harness();
$pdo->exec('ALTER TABLE invoiced_charges RENAME TO invoiced_charges_el');   // a rögzítés elszáll
check($h->handle(paidInvoice('evt_t2')) === 500, 'T2: adatbázis-hiba a kiállítás után → 500');
check($ev->get('evt_t2')['state'] === 'issuing', 'T2: „nem tudni” állapot, nem csendes kész');
check(count($al) === 1 && str_contains($al[0], 'NEM TUDNI'), 'T2: riasztás ment');
$pdo->exec('ALTER TABLE invoiced_charges_el RENAME TO invoiced_charges');
check($h->handle(paidInvoice('evt_t2')) === 200, 'T2: második kézbesítés → 200');
check(count($a->issued) === 1 && $a->calls === 1 && $a->queries === 1, 'T2: lekérdezés talált, NEM állított ki újat');
check($ev->get('evt_t2')['invoice_number'] === 'E-TST-1', 'T2: a sorszám visszaírva');
echo "T2 OK: hiba a kiállítás után → lekérdezés, nincs dupla, nincs elveszett számla\n";

// T3: ismeretlen kimenetel (sorszám nélküli válasz), de a számla elkészült.
[$h, $a, $ev] = harness();
$a->failNext[] = [AgentException::UNKNOWN, true];
check($h->handle(paidInvoice('evt_t3')) === 500 && $ev->get('evt_t3')['state'] === 'issuing', 'T3: ismeretlen → issuing');
check($h->handle(paidInvoice('evt_t3')) === 200 && count($a->issued) === 1 && $a->calls === 1, 'T3: lekérdezés megtalálja, 1 számla');
echo "T3 OK: elveszett válasz, elkészült számla → nincs dupla\n";

// T4: ismeretlen kimenetel, és a számla NEM készült el.
[$h, $a, $ev] = harness();
$a->failNext[] = [AgentException::UNKNOWN, false];
check($h->handle(paidInvoice('evt_t4')) === 500, 'T4: ismeretlen → 500');
check($h->handle(paidInvoice('evt_t4')) === 200 && count($a->issued) === 1 && $a->calls === 2 && $a->queries === 1, 'T4: nincs találat → kiállítja, 1 számla');
echo "T4 OK: elveszett válasz, el nem készült számla → kiállítva\n";

// T5: a lekérdezés is elszáll → nem állítunk ki vakon.
[$h, $a, $ev] = harness();
$a->failNext[] = [AgentException::UNKNOWN, true];
$h->handle(paidInvoice('evt_t5'));
$a->queryFails = true;
check($h->handle(paidInvoice('evt_t5')) === 500 && $a->calls === 1, 'T5: lekérdezés nélkül nincs újabb kiállítás');
check($ev->get('evt_t5')['state'] === 'issuing', 'T5: továbbra is „nem tudni”');
echo "T5 OK: ha a lekérdezés sem megy, inkább késik, mint duplázódik\n";

// T6: adathiba még az Agent előtt (hiányzó tételsor) → riasztás, nem csendes.
[$h, $a, $ev, , $al] = harness();
$broken = paidInvoice('evt_t6');
unset($broken->data->object->lines);
check($h->handle($broken) === 500 && $a->calls === 0, 'T6: adathiba → 500, Agent-hívás nélkül');
check($ev->get('evt_t6')['state'] === 'claimed' && count($al) === 1, 'T6: elengedve és riasztva');
echo "T6 OK: adathiba az Agent előtt → látszik, nem vész el\n";

// T7: párhuzamos kézbesítés: amíg az első dolgozik, a második 409-et kap.
[$h, $a, $ev] = harness();
check($ev->claim('evt_t7') === ProcessedEvents::NEW, 'T7: az első foglal');
check($h->handle(paidInvoice('evt_t7')) === 409 && $a->calls === 0, 'T7: a második nem dolgozik rajta');
echo "T7 OK: párhuzamos kézbesítés → egy dolgozik\n";

// Elakadt (nem elengedett) foglalás: 5 perc után átvehető.
$now = 1_800_000_000;
[$h, $a, $ev] = harness(static function () use (&$now): int { return $now; });
$ev->claim('evt_stale');   // egy kérés lefoglalta, aztán a PHP-folyamat meghalt
check($h->handle(paidInvoice('evt_stale')) === 409, 'elakadt foglalás: 5 percen belül még foglalt');
$now += 301;
check($h->handle(paidInvoice('evt_stale')) === 200 && count($a->issued) === 1, 'elakadt foglalás: 5 perc után átvéve, 1 számla');
echo "elakadt foglalás OK: egy kilőtt folyamat sem hagyja el a számlát\n";

// T8: két külön esemény ugyanarra a Stripe-számlára, bekapcsolt
// ismétlődés-tiltással → a második 152-est kap, lekérdez, nincs dupla.
// (Ez a T17 is: a 71/152 nem hiba, hanem „már kész”.)
[$h, $a, $ev, , $al] = harness();
check($h->handle(paidInvoice('evt_a', 'in_same')) === 200, 'T8: első esemény');
check($h->handle(paidInvoice('evt_b', 'in_same')) === 200, 'T17: 152-es hiba → 200');
check(count($a->issued) === 1 && $ev->get('evt_b')['invoice_number'] === 'E-TST-1', 'T17: nincs második számla, a meglévő sorszáma');
check(count($al) === 0, 'T17: nincs riasztás, mert nem hiba');
echo "T8/T17 OK: 71/152 → lekérdezés, a meglévő számla\n";

// Az 5-ös korlát: a Számlázz.hu szerint ugyanazt legfeljebb ötször szabad
// elküldeni. Utána feladjuk, és szólunk.
[$h, $a, $ev, , $al] = harness();
for ($i = 0; $i < 5; $i++) {
    $a->failNext[] = [AgentException::REJECTED, false];
    $h->handle(paidInvoice('evt_max'));
}
check($a->calls === 5, 'korlát: 5 Agent-hívás');
check($h->handle(paidInvoice('evt_max')) === 200 && $a->calls === 5, 'korlát: a 6. kézbesítés nem hív Agentet');
check($ev->get('evt_max')['state'] === 'failed' && str_contains($al[count($al) - 1], 'FELADVA'), 'korlát: feladva, riasztással');
echo "5-ös korlát OK\n";

// T16: visszatérítés. Ugyanaz az esemény kétszer → 1 helyesbítő; új
// eseményazonosító ugyanazzal a halmozott összeggel → szintén nincs második.
[$h, $a, $ev] = harness();
$h->handle(paidInvoice('evt_pay'));
check($h->handle(refundEvent('evt_r1', 400000, false)) === 200, 'T16: részleges visszatérítés');
check($h->handle(refundEvent('evt_r1', 400000, false)) === 200, 'T16: ugyanaz az esemény újra');
check($h->handle(refundEvent('evt_r1b', 400000, false)) === 200, 'T16: másik esemény, ugyanaz az összeg');
check(count($a->issued) === 2, 'T16: 1 számla + 1 helyesbítő, nincs dupla');
echo "T16 OK: dupla visszatérítési esemény → 1 helyesbítő\n";

// T16b: a helyesbítő válasza elveszik (mentes a tiltás alól, ezért a saját
// foglalás és a külső azonosító szerinti lekérdezés véd).
[$h, $a, $ev] = harness();
$h->handle(paidInvoice('evt_pay2'));
$a->failNext[] = [AgentException::UNKNOWN, true];
check($h->handle(refundEvent('evt_r2', 1270000, true)) === 500, 'T16b: sztornó, elveszett válasz');
check($h->handle(refundEvent('evt_r2', 1270000, true)) === 200 && count($a->issued) === 2, 'T16b: lekérdezés, nincs második sztornó');
echo "T16b OK: elveszett sztornó-válasz → nincs dupla\n";

echo "\nMinden webhook-teszt zöld.\n";

echo "\n--- a generált XML ---\n" . $xml;
