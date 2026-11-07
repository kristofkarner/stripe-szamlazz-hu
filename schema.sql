-- A dupla számla és a csendes számlavesztés elleni védelem tárolója.
--
-- Az event_id egyedisége maga a védelem: a második INSERT elszáll,
-- és abból tudjuk, hogy az eseményt már láttuk. Ne cseréld
-- SELECT-majd-INSERT párosra — az két kérés között versenyezhet.
--
-- Az állapot azért kell, mert a „láttuk” még nem „kész”:
--   claimed — lefoglalva, az Agentet még nem hívtuk
--   issuing — az Agent hívása elindult; újrakezdés előtt lekérdezés kell
--   done    — kész
--   failed  — feladva (5 Agent-kérés után), ember kell
CREATE TABLE processed_events (
    event_id       VARCHAR(64)  NOT NULL PRIMARY KEY,
    state          VARCHAR(16)  NOT NULL DEFAULT 'claimed',
    attempts       INT          NOT NULL DEFAULT 0,
    claimed_at     DATETIME     NOT NULL,
    invoice_number VARCHAR(64)  NULL,
    invoiced_at    DATETIME     NULL,
    last_error     VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Meglévő telepítés frissítése (a régi tábla csak event_id, claimed_at,
-- invoice_number, invoiced_at oszlopokat tartalmazott):
--
--   ALTER TABLE processed_events
--     ADD COLUMN state VARCHAR(16) NOT NULL DEFAULT 'claimed' AFTER event_id,
--     ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER state,
--     ADD COLUMN last_error VARCHAR(500) NULL;
--   UPDATE processed_events SET state = 'done' WHERE invoice_number IS NOT NULL;
--
-- Ami ezután is 'claimed' sorszám nélkül, az a régi kód csendben elveszett
-- számlája lehet: nézd meg őket kézzel, mielőtt bármit törölnél.

-- Melyik terhelésről melyik számla készült, és mennyit kezeltünk le belőle
-- visszatérítésként. A visszatérítési esemény a magyar számla számát nem
-- hozza magával — enélkül nem tudnánk, mit kell sztornózni vagy helyesbíteni.
-- Az összegek a Stripe legkisebb egységében vannak (forintnál is: ×100).
CREATE TABLE invoiced_charges (
    stripe_charge  VARCHAR(64)  NOT NULL PRIMARY KEY,
    invoice_number VARCHAR(64)  NOT NULL,
    currency       CHAR(3)      NOT NULL,
    amount         BIGINT       NOT NULL,
    refunded       BIGINT       NOT NULL DEFAULT 0,
    updated_at     DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
