<?php
/**
 * Másold config.php néven, és töltsd ki. A config.php a .gitignore-ban
 * van — kulcs soha ne kerüljön verziókövetésbe.
 */
return [
    // Stripe → Developers → Webhooks → az adott végpont "Signing secret"-je
    'stripe_webhook_secret' => 'whsec_...',

    // Számlázz.hu → Beállítások → Számla Agent → kulcs generálása
    'szamlazz_agent_key' => '...',

    // Az áfakulcs a te adózási formádtól függ: '27', 'AAM', 'TAM' stb.
    // Hogy melyik a helyes, azt a könyvelő mondja meg, nem ez a kód.
    'vat_key' => '27',

    'dsn'     => 'mysql:host=localhost;dbname=example;charset=utf8mb4',
    'db_user' => 'example',
    'db_pass' => '',
];
