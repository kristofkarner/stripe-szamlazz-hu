# Stripe → Számlázz.hu

Minta arra, hogyan készüljön **automatikusan magyar számla minden sikeres
Stripe-terhelés után** — előfizetésnél is, a megújulásokra is —, és mi
történjen vele, ha a pénzt visszatérítik.

> *English: a small PHP reference showing how to issue a Hungarian fiscal
> invoice (Számlázz.hu Agent API) from a Stripe webhook, with idempotent
> event handling — and how to reverse or correct it when the payment is
> refunded. Comments and docs are in Hungarian, because that is who this
> problem belongs to.*

## Miért van erre szükség

Amit a Stripe kiállít, **az nem magyar számla**. A Stripe bizonylata a
fizetésről szól; ha magyar szabályok szerinti számla kell, azt külön
rendszernek kell elkészítenie.

Előfizetésnél ez havonta ismétlődő kézi munka lenne. Ez a minta azt mutatja
meg, hogyan lesz belőle automatizmus — és hol vannak benne a csapdák.

## Amit érdemes átvenni belőle

Nem a kód mennyisége a lényeg, hanem öt döntés:

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

**3 · Hiba esetén: minden hibát elkapni, és tudni, mi történt a bizonylattal.**
Ha a számlázás elszáll, a foglalást el kell engedni, különben a Stripe
újraküldésére „már kész”-t mondanánk, és a számla csendben soha nem készülne
el. Ehhez **minden** hibát el kell kapni, nem csak a Számlázz.hu-ét: egy
adatbázis- vagy adathiba ugyanúgy beragasztja a foglalást.

De elengedni sem szabad vakon. Ha az Agent hívása elindult, és a válasz
elveszett (időtúllépés, sorszám nélküli válasz), lehet, hogy a számla már
elkészült — az újraküldés ilyenkor dupla számlát csinálna. Ezért az állapotot
is tároljuk (`claimed` → `issuing` → `done`):

- ha **biztos**, hogy bizonylat nem készült (az Agentet nem hívtuk, vagy
  kifejezett hibakóddal utasította el), a következő kézbesítés egyszerűen
  újrakezdi;
- ha **nem tudni**, a következő kézbesítés előbb **lekérdezi** a Számlázz.hu-ban
  a külső azonosító (`szamlaKulsoAzon`) szerint, és csak akkor állít ki, ha
  nincs ilyen; ha a lekérdezés sem sikerül, inkább vár;
- ugyanazt a kérést a Számlázz.hu szabálya szerint
  [legfeljebb ötször](https://docs.szamlazz.hu/agent/basics/error-handling)
  szabad elküldeni: utána a rendszer felad, és riaszt, mert ember kell.

Minden hiba riasztással jár (`webhook.php`: alapból `error_log`, élesben
e-mail vagy üzenet), mert az elmaradt számlának látszania kell.

**4 · A visszatérítés nem vonja vissza a számlát.**
A Stripe-ban a visszatérítés egy gomb; a kiállított magyar számlához nem
nyúl. Arról külön bizonylat kell:

- **teljes visszatérítésnél** (egyben, előzmény nélkül) **sztornó** az eredeti
  számlára;
- **részlegesnél** **helyesbítő számla**, negatív tétellel, az eredeti számla
  számára hivatkozva.

Két buktató van benne. A `charge.refunded` esemény a terhelés **eddigi összes**
visszatérítését hozza, nem az aktuálisét — ha a második részleges
visszatérítésnél ezt számláznánk, az elsőt kétszer helyesbítenénk. Ezért azt
tároljuk, mennyit kezeltünk már le, és csak a különbséget számlázzuk. A másik:
a visszatérítési esemény **nem tudja a magyar számla számát**. Ezt a számla
kiállításakor kell eltenni (`invoiced_charges`), különben nem lehet megmondani,
mit kell sztornózni.

**5 · Rendelésszám, és a „Rendelésszám ismétlődés tiltása”.**
A minta minden számlára rendelésszámot (`rendelesSzam`) ír: a Stripe-számla
azonosítóját. Ha a Számlázz.hu fiókban bekapcsolod a **Beállítások →
Fiókbeállítások → Számlázási beállítások → „Rendelésszám ismétlődés tiltása”**
kapcsolót, ugyanarra a Stripe-számlára második magyar számla nem készülhet: ha
egy elveszett válasz után ugyanaz a kérés újra megy, az Agent a korábbi számlát
adja vissza, eltérő adatnál pedig 71-es vagy 152-es hibát ad („Már létező
rendelésszám”). A minta ezt nem hibának veszi, hanem lekérdezi a meglévő
számla sorszámát. Ez egy második védvonal a saját foglalás mögött, és akkor is
véd, ha két különböző esemény (vagy egy másik bővítmény) ugyanarra a fizetésre
számlázna. **A sztornó és a helyesbítő számla mentes a tiltás alól** — ott a
saját foglalás, a halmozott visszatérítési összeg és a külső azonosító szerinti
lekérdezés véd a duplától.
[Forrás](https://docs.szamlazz.hu/agent/generating_invoice/settings_and_rules/order-number)

Ehhez jön négy apróság, ami élesben derül ki:

- **A Számlázz.hu a hibát a HTTP-fejlécben adja vissza** (`szlahu_error_code`,
  a szövegét a `szlahu_error`, URL-kódolva), nem a státuszkódban. Egy 200-as
  válasz még nem jelenti, hogy elkészült a számla.
- **A forint a Stripe-ban kéttizedes.** A 10 000 Ft-os terhelés `1000000`-ként
  érkezik; nulla tizedesként a Stripe csak a kifizetésnél kezeli. Ha a forint
  külön ágat kap osztás nélkül, a számla százszoros összeggel készül.
- **A kedvezmény külön mezőben jön** (`discount_amounts`), a tétel összegét nem
  csökkenti. Ha nem vonod le, a számlán a listaár szerepel, nem az, amit a vevő
  fizetett.
- **Bruttó vagy nettó?** A Stripe azt adja, amit a vevő fizetett; magyar
  fogyasztói árnál ez bruttó. A nettót egy helyen számoljuk vissza belőle
  (`prices_include_vat`).

## Használat

```bash
composer require stripe/stripe-php
cp config.example.php config.php    # töltsd ki
mysql < schema.sql
```

A `public/webhook.php` a Stripe webhook végpontja. A Stripe oldalán két
eseményre iratkozz fel:

- `invoice.payment_succeeded` — előfizetésnél a megújulásokról **csak ez
  szól**, mert a vásárló olyankor nem jár az oldalon. A fizetős oldal lezárása
  (`checkout.session.completed`) csak az első vásárlást fogja meg.
- `charge.refunded` — a visszatérítésekhez.

A mezőnevek a stripe-php 13-as ágához tartozó API-verziót (2023-10-16)
követik. Újabb API-verzióban a számla és a terhelés kapcsolata máshol van —
a `webhook.php` eleje megmondja, hol kell igazítani.

Hálózat nélkül is kipróbálható:

```bash
php test.php     # számítások, pénznem, visszatérítés, az XML-ek, és a webhook
                 # hibakezelése: elveszett válasz, adatbázis-hiba, 71/152,
                 # párhuzamos kézbesítés, 5-ös korlát — SQLite, hálózat nélkül
```

## Amit ez a minta NEM tartalmaz

- **Nyugtát.** Magánszemélynek a nyugta is elég lehet; a Számla Agent azt is
  tudja, ez a minta számlát állít ki.
- **Hozzáférés-kezelést.** Ha az előfizetéshez hozzáférés is tartozik (például
  fizetős Telegram-csatorna), az külön minta:
  [telegram-subscription-access](https://github.com/kristofkarner/telegram-subscription-access).
- **Adójogi döntéseket.** Hogy melyik áfakulcs, melyik teljesítési dátum,
  melyik árfolyam, és visszatérítésnél sztornó vagy módosító számla a helyes a
  te adózási formádban, azt a könyvelő mondja meg. A kód azt követi, amit
  beállítasz.
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
