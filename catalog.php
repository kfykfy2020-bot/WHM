<?php

declare(strict_types=1);

/**
 * Slasel — live catalog endpoint.
 *
 * Returns live prices (and a few mutable product flags) from WHMCS so the
 * Next.js storefront can overlay them on top of the git-tracked static
 * snapshot at frontend/lib/catalog.generated.json.
 *
 * The static snapshot still carries slug, short_description, specs, and a
 * fallback price; this endpoint only tells the frontend "what did the admin
 * just set in tblproducts/tblpricing". An edit in WHMCS Products/Services →
 * this endpoint returns the new number → the Next.js fetch cache
 * revalidates within its ISR window (default 5 min) → storefront updates
 * without a redeploy.
 *
 * Auth: shared bearer token. The token is read from the SLASEL_CATALOG_TOKEN
 * env var, and if unset, from a local gitignored file .catalog-token next to
 * this script (the XAMPP/local-dev convenience path). Production should set
 * the env var. This endpoint is server-to-server only — no other auth path.
 *
 * Response shape:
 *   {
 *     "generated_at": "<ISO8601>",
 *     "source": "whmcs-live",
 *     "byId": {
 *       "<tblproducts.id>": {
 *         "name": "...",
 *         "hidden": 0,
 *         "retired": 0,
 *         "pricing": {
 *           "monthly":      { "sar": 70.31, "usd": 18.75 },
 *           "quarterly":    { "sar": null,  "usd": null  },
 *           "semiannually": { "sar": null,  "usd": null  },
 *           "annual":       { "sar": null,  "usd": null  }
 *         }
 *       }
 *     }
 *   }
 *
 * WHMCS stores "annually" on tblpricing; we remap to "annual" to match the
 * shape of frontend/lib/catalog.generated.json. Prices of -1 in tblpricing
 * mean "not offered at this cycle" — surfaced as null so the frontend
 * merge leaves the static fallback in place.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$expected = getenv('SLASEL_CATALOG_TOKEN');
if (!is_string($expected) || $expected === '') {
    $tokenFile = __DIR__ . '/.catalog-token';
    if (is_file($tokenFile) && is_readable($tokenFile)) {
        $fileToken = trim((string) file_get_contents($tokenFile));
        if ($fileToken !== '') {
            $expected = $fileToken;
        }
    }
}
if (!is_string($expected) || $expected === '') {
    $respond(503, ['error' => 'endpoint_not_configured']);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
$presented = '';
if (is_string($auth) && stripos($auth, 'Bearer ') === 0) {
    $presented = substr($auth, 7);
}
if (!hash_equals($expected, $presented)) {
    $respond(401, ['error' => 'unauthorized']);
}

$configPath = __DIR__ . '/configuration.php';
if (!is_file($configPath)) {
    $respond(500, ['error' => 'configuration_missing']);
}
require $configPath;

if (!isset($db_host, $db_username, $db_password, $db_name)) {
    $respond(500, ['error' => 'db_config_missing']);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $db_host,
    $db_port ?? '3306',
    $db_name
);

try {
    $pdo = new PDO($dsn, $db_username, $db_password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $respond(500, ['error' => 'db_connect_failed']);
}

$products = $pdo->query('SELECT id, name, hidden, retired FROM tblproducts')->fetchAll();

$stmt = $pdo->query(
    "SELECT p.relid AS product_id,
            c.code  AS currency_code,
            p.monthly, p.quarterly, p.semiannually, p.annually
       FROM tblpricing p
       JOIN tblcurrencies c ON c.id = p.currency
      WHERE p.type = 'product'
        AND c.code IN ('SAR', 'USD')"
);
$priceRows = $stmt->fetchAll();

$normalize = static function ($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    $num = (float) $value;
    if ($num < 0) {
        return null;
    }
    return $num;
};

$emptyCycle = ['sar' => null, 'usd' => null];
$emptyPricing = [
    'monthly'      => $emptyCycle,
    'quarterly'    => $emptyCycle,
    'semiannually' => $emptyCycle,
    'annual'       => $emptyCycle,
];

$byId = [];
foreach ($products as $row) {
    $byId[(int) $row['id']] = [
        'name'    => (string) $row['name'],
        'hidden'  => (int) $row['hidden'],
        'retired' => (int) $row['retired'],
        'pricing' => $emptyPricing,
    ];
}

foreach ($priceRows as $row) {
    $id = (int) $row['product_id'];
    if (!isset($byId[$id])) {
        continue;
    }
    $cur = strtolower($row['currency_code']) === 'sar' ? 'sar' : 'usd';
    $byId[$id]['pricing']['monthly'][$cur]      = $normalize($row['monthly']);
    $byId[$id]['pricing']['quarterly'][$cur]    = $normalize($row['quarterly']);
    $byId[$id]['pricing']['semiannually'][$cur] = $normalize($row['semiannually']);
    $byId[$id]['pricing']['annual'][$cur]       = $normalize($row['annually']);
}

$respond(200, [
    'generated_at' => gmdate('c'),
    'source'       => 'whmcs-live',
    'byId'         => $byId,
]);
