# Stripe → Számlázz.hu

Minta arra, hogyan készüljön **automatikusan magyar számla minden sikeres
Stripe-terhelés után** — előfizetésnél is, a megújulásokra is.

> *English: a small PHP reference showing how to issue a Hungarian fiscal
> invoice (Számlázz.hu Agent API) from a Stripe webhook, with idempotent
> event handling. Comments and docs are in Hungarian, because that is who
> this problem belongs to.*

## Miért van erre szükség

Amit a Stripe kiállít, **az nem magyar számla**. A Stripe bizonylata a
fizetésről szól; ha magyar szabályok szerinti számla kell, azt külön
rendszernek kell elkészítenie.

Előfizetésnél ez havonta ismétlődő kézi munka lenne. Ez a minta azt mutatja
meg, hogyan lesz belőle automatizmus — és hol vannak benne a csapdák.

## Amit érdemes átvenni belőle

Nem a kód mennyisége a lényeg, hanem három döntés:

**1 · Az aláírás-ellenőrzés megelőz mindent, a naplózást is.**
Ha az ellenőrzés a naplózás után futna, bárki, aki ismeri a végpont címét,
tetszőleges adatot írathatna a naplóba. A hamis „sikeres fizetés" eseményen
túl ez magában is támadási felület.

**2 · Az esemény lefoglalása megelőzi a számlakiállítást.**
A Stripe újraküldi az eseményt, ha nem kap időben választ — ez helyes
viselkedés, de azt jelenti, hogy minden művelet lefuthat kétszer.
Számlázásnál ez nem apróság: a számla hivatalos irat, a duplikátumot
sztornózni kell. A védelem az `event_id` egyedisége az adatbázisban, és a
**sorrend**: előbb a foglalás, utána a számla. Fordítva a két lépés közé
beférhet egy második kérés.

**3 · Hiba esetén a foglalás elengedése.**
Ha a számlázás elszáll, a sort ki kell venni, különben a Stripe
újraküldése sem tud próbálkozni — és a számla soha nem készül el, csendben.

Ehhez jön két apróság, ami élesben derül ki:

- **A Számlázz.hu a hibát a HTTP-fejlécben adja vissza** (`szlahu_error_code`),
  nem a státuszkódban. Egy 200-as válasz még nem jelenti, hogy elkészült a
  számla.
- **A Stripe a legkisebb pénzegységben számol.** Forintnál nincs váltópénz,
  más devizánál századokban jön az összeg — ezt a kettőt külön kell kezelni.

## Használat

```bash
composer require stripe/stripe-php
cp config.example.php config.php    # töltsd ki
mysql < schema.sql
```

A `public/webhook.php` a Stripe webhook végpontja. A Stripe oldalán az
`invoice.payment_succeeded` eseményre iratkozz fel: előfizetésnél a
megújulásokról **csak ez szól**, mert a vásárló olyankor nem jár az oldalon.
A fizetős oldal lezárása (`checkout.session.completed`) csak az első
vásárlást fogja meg.

Hálózat nélkül is kipróbálható:

```bash
php test.php     # ellenőrzi a számításokat és a generált XML-t
```

## Amit ez a minta NEM tartalmaz

- **Sztornót.** A Stripe-ban a visszatérítés egy gomb, de a kiállított magyar
  számlát nem vonja vissza semmi. Ez a minta a kiállításról szól; a sztornó
  ugyanígy az Agenten keresztül megy, a `visszatérítés` eseményére kötve.
- **Adójogi döntéseket.** Hogy melyik áfakulcs, melyik teljesítési dátum és
  melyik árfolyam a helyes a te adózási formádban, azt a könyvelő mondja meg.
  A kód azt követi, amit beállítasz.
- **Éles kulcsokat.** Teszteld a Stripe tesztmódjában és a Számlázz.hu
  próbaszámlájával, mielőtt élesíted.

## Kapcsolódó írás

A döntések hosszabb kifejtése, buktatókkal:
[Hogyan készüljön automatikusan számla minden Stripe-terhelés után?](https://kristofkarner.com/irasok/stripe-szamlazz-hu-szamlazas/)

## Licenc

MIT — használd szabadon. Ha hasznos volt, egy csillag jólesik.

---

Készítette [Karner Kristóf](https://kristofkarner.com) · önálló webfejlesztő,
Budapest
