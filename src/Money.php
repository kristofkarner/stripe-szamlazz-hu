<?php

declare(strict_types=1);

namespace KK\StripeSzamlazz;

/**
 * Stripe-összeg → valódi összeg.
 *
 * A Stripe minden összeget a pénznem legkisebb egységében ad: 1099 = 10,99 EUR.
 * A FORINT IS ILYEN. A Stripe kéttizedes pénznemként kezeli, tehát a 10 000 Ft
 * terhelés `1000000`-ként érkezik. Nulla tizedesként csak a kifizetésnél
 * (payout) bánik vele, a terhelésnél nem — erről könnyű rosszul emlékezni, és
 * akkor a számla százszoros összeggel készül el.
 *
 * Nulla tizedes csak az alábbi listán szereplő pénznem.
 * Forrás: https://docs.stripe.com/currencies#zero-decimal
 */
final class Money
{
    private const ZERO_DECIMAL = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public static function fromStripe(int $amount, string $currency): float
    {
        return in_array(strtolower($currency), self::ZERO_DECIMAL, true)
            ? (float) $amount
            : round($amount / 100, 2);
    }

    /** A számlán használt jelölés: a forint „Ft”, a többi ISO-kód. */
    public static function invoiceCurrency(string $stripeCurrency): string
    {
        return strtolower($stripeCurrency) === 'huf' ? 'Ft' : strtoupper($stripeCurrency);
    }
}
