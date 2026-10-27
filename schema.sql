-- A dupla számla elleni védelem tárolója.
--
-- Az event_id egyedisége maga a védelem: a második INSERT elszáll,
-- és abból tudjuk, hogy az eseményt már feldolgoztuk. Ne cseréld
-- SELECT-majd-INSERT párosra — az két kérés között versenyezhet.
CREATE TABLE processed_events (
    event_id       VARCHAR(64)  NOT NULL PRIMARY KEY,
    claimed_at     DATETIME     NOT NULL,
    invoice_number VARCHAR(64)  NULL,
    invoiced_at    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
