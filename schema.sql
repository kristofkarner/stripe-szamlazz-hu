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
