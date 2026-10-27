<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Számlázz.hu Számla Agent — számla és sztornó XML-kéréssel.
 *
 * A kérés multipart/form-data POST a https://www.szamlazz.hu/szamla/
 * címre. A mező NEVE dönti el, melyik műveletet hajtja végre az Agent,
 * nem az XML tartalma: számlánál `action-xmlagentxmlfile`, sztornónál
 * `action-szamla_agent_st`.
 *
 * A válasz a HTTP-fejlécekben érkezik: szlahu_error_code, szlahu_error
 * (URL-kódolva), szlahu_szamlaszam (URL-kódolva). A hibát tehát akkor is
 * látni kell, ha a HTTP-státusz 200.
 */
final class SzamlazzAgent
{
    private const ENDPOINT = 'https://www.szamlazz.hu/szamla/';
    private const XMLNS = 'http://www.szamlazz.hu/xmlszamla';
    private const XSD = 'http://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd';
    private const XMLNS_STORNO = 'http://www.szamlazz.hu/xmlszamlast';
    private const XSD_STORNO = 'https://www.szamlazz.hu/szamla/docs/xsds/agentst/xmlszamlast.xsd';

    public function __construct(
        private readonly string $agentKey,
        private readonly bool $eInvoice = true,
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * Számla vagy helyesbítő számla kiállítása.
     *
     * @return string a kiállított számla sorszáma
     * @throws AgentException ha az Agent hibát jelez
     */
    public function issue(Invoice $invoice): string
    {
        [$status, $headers] = $this->post('action-xmlagentxmlfile', $this->buildXml($invoice));
        return self::invoiceNumberFrom($status, $headers);
    }

    /**
     * Egy kiállított számla sztornózása — teljes visszatérítésnél.
     *
     * @return string a sztornó számla sorszáma
     * @throws AgentException ha az Agent hibát jelez
     */
    public function reverse(string $invoiceNumber, ?string $buyerEmail = null): string
    {
        [$status, $headers] = $this->post('action-szamla_agent_st', $this->buildReversalXml($invoiceNumber, $buyerEmail));
        return self::invoiceNumberFrom($status, $headers);
    }

    /**
     * A válaszfejlécekből a sorszám — vagy kivétel, ha hibát jeleznek.
     *
     * @param array<string,string> $headers kisbetűs fejlécnevekkel
     */
    public static function invoiceNumberFrom(int $status, array $headers): string
    {
        // A hibakód a fejlécben jön, a HTTP-státusztól függetlenül.
        $errorCode = $headers['szlahu_error_code'] ?? null;
        if ($errorCode !== null && $errorCode !== '') {
            $message = urldecode($headers['szlahu_error'] ?? 'ismeretlen hiba');
            throw new AgentException(
                sprintf('Számla Agent hiba (%s): %s', $errorCode, $message),
                (int) $errorCode
            );
        }

        $number = urldecode($headers['szlahu_szamlaszam'] ?? '');
        if ($number === '') {
            throw new AgentException(
                "A válasz nem tartalmaz számlaszámot (HTTP {$status}). "
                . 'Ilyenkor NEM biztos, hogy a számla nem készült el — '
                . 'ellenőrizd a Számlázz.hu felületén, mielőtt újraküldenéd.'
            );
        }

        return $number;
    }

    /** Az összeállított XML — küldés előtt megnézhető, és így tesztelhető
     *  hálózat nélkül is. */
    public function buildXml(Invoice $invoice): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::XMLNS, 'xmlszamla');
        $root->setAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:schemaLocation',
            self::XMLNS . ' ' . self::XSD
        );
        $doc->appendChild($root);

        $add = static function (\DOMElement $parent, string $name, string $value) use ($doc): void {
            $parent->appendChild($doc->createElement($name, htmlspecialchars($value, ENT_XML1)));
        };

        $settings = $doc->createElement('beallitasok');
        $root->appendChild($settings);
        $add($settings, 'szamlaagentkulcs', $this->agentKey);
        $add($settings, 'eszamla', $this->eInvoice ? 'true' : 'false');
        $add($settings, 'szamlaLetoltes', 'false');
        // A külső azonosító teszi a számlát visszakereshetővé a saját
        // rendszerünkből — ez az, amivel utólag párosítani lehet.
        $add($settings, 'szamlaKulsoAzon', $invoice->externalId);

        // Az Agent kötött sorrendet vár — az elemek nem cserélhetők fel.
        $header = $doc->createElement('fejlec');
        $root->appendChild($header);
        $add($header, 'keltDatum', $invoice->issueDate);
        $add($header, 'teljesitesDatum', $invoice->fulfilmentDate);
        $add($header, 'fizetesiHataridoDatum', $invoice->dueDate);
        $add($header, 'fizmod', $invoice->paymentMethod);
        $add($header, 'penznem', $invoice->currency);
        $add($header, 'szamlaNyelve', $invoice->language);
        if ($invoice->currency !== 'Ft' && $invoice->exchangeRate !== null) {
            // Devizás számlán az árfolyam kötelező. Hogy melyik nap
            // melyik árfolyama a helyes, az KÖNYVELŐI kérdés, nem
            // technikai — itt csak továbbadjuk, amiben megállapodtatok.
            $add($header, 'arfolyamBank', $invoice->exchangeBank ?? 'MNB');
            $add($header, 'arfolyam', (string) $invoice->exchangeRate);
        }
        if ($invoice->correctsInvoiceNumber !== null) {
            $add($header, 'helyesbitoszamla', 'true');
            $add($header, 'helyesbitettSzamlaszam', $invoice->correctsInvoiceNumber);
        }

        $seller = $doc->createElement('elado');
        $root->appendChild($seller);
        if ($invoice->sellerEmailSubject !== null) {
            $add($seller, 'emailTargy', $invoice->sellerEmailSubject);
        }
        if ($invoice->sellerEmailBody !== null) {
            $add($seller, 'emailSzoveg', $invoice->sellerEmailBody);
        }

        $buyer = $doc->createElement('vevo');
        $root->appendChild($buyer);
        $add($buyer, 'nev', $invoice->buyerName);
        $add($buyer, 'irsz', $invoice->buyerPostcode);
        $add($buyer, 'telepules', $invoice->buyerCity);
        $add($buyer, 'cim', $invoice->buyerAddress);
        if ($invoice->buyerEmail !== null) {
            $add($buyer, 'email', $invoice->buyerEmail);
        }
        if ($invoice->buyerTaxNumber !== null) {
            $add($buyer, 'adoszam', $invoice->buyerTaxNumber);
        }

        $items = $doc->createElement('tetelek');
        $root->appendChild($items);
        foreach ($invoice->items as $item) {
            $node = $doc->createElement('tetel');
            $items->appendChild($node);
            $add($node, 'megnevezes', $item->name);
            $add($node, 'mennyiseg', self::num($item->quantity));
            $add($node, 'mennyisegiEgyseg', $item->unit);
            $add($node, 'nettoEgysegar', self::num($item->netUnitPrice));
            $add($node, 'afakulcs', $item->vatKey);
            // Az Agent ellenőrzi: nettó egységár × mennyiség = nettó érték,
            // és nettó + áfa = bruttó. Ezért számoljuk ki mi, egy helyen.
            $add($node, 'nettoErtek', self::num($item->netTotal()));
            $add($node, 'afaErtek', self::num($item->vatTotal()));
            $add($node, 'bruttoErtek', self::num($item->grossTotal()));
        }

        return $doc->saveXML();
    }

    /**
     * A sztornókérés XML-je. A teljesítés dátumát szándékosan nem adjuk meg:
     * azt a Számlázz.hu az eredeti számlából veszi, mi csak elronthatnánk.
     */
    public function buildReversalXml(string $invoiceNumber, ?string $buyerEmail = null, ?string $date = null): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::XMLNS_STORNO, 'xmlszamlast');
        $root->setAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:schemaLocation',
            self::XMLNS_STORNO . ' ' . self::XSD_STORNO
        );
        $doc->appendChild($root);

        $add = static function (\DOMElement $parent, string $name, string $value) use ($doc): void {
            $parent->appendChild($doc->createElement($name, htmlspecialchars($value, ENT_XML1)));
        };

        $settings = $doc->createElement('beallitasok');
        $root->appendChild($settings);
        $add($settings, 'szamlaagentkulcs', $this->agentKey);
        $add($settings, 'eszamla', $this->eInvoice ? 'true' : 'false');
        $add($settings, 'szamlaLetoltes', 'false');

        $header = $doc->createElement('fejlec');
        $root->appendChild($header);
        $add($header, 'szamlaszam', $invoiceNumber);
        $add($header, 'keltDatum', $date ?? date('Y-m-d'));
        $add($header, 'tipus', 'SS');

        if ($buyerEmail !== null) {
            $buyer = $doc->createElement('vevo');
            $root->appendChild($buyer);
            $add($buyer, 'email', $buyerEmail);
        }

        return $doc->saveXML();
    }

    /** @return array{0:int,1:array<string,string>} */
    private function post(string $field, string $xml): array
    {
        $ch = curl_init(self::ENDPOINT);
        $headers = [];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_POSTFIELDS => [
                // A mezőnév dönti el a műveletet — ezért kell fájlként küldeni.
                $field => new \CURLStringFile(
                    $xml,
                    'szamla.xml',
                    'text/xml'
                ),
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        // A curl_close() PHP 8 óta nem csinál semmit (8.5-től elavult): a
        // kezelő a függvény végén magától felszabadul.
        $body = curl_exec($ch);
        if ($body === false) {
            throw new AgentException('A Számlázz.hu nem elérhető: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [$status, $headers];
    }

    private static function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
