<?php
require_once __DIR__ . '/db.php';

function getProductCost(PDO $db, int $productId): float {
    $stmt = $db->prepare('
        SELECT pm.quantity_used, m.purchase_price, m.total_quantity
        FROM product_materials pm
        JOIN materials m ON pm.material_id = m.id
        WHERE pm.product_id = ?
    ');
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll();

    $cost = 0.0;
    foreach ($rows as $row) {
        if ($row['total_quantity'] > 0) {
            $cost += ($row['purchase_price'] / $row['total_quantity']) * $row['quantity_used'];
        }
    }
    return $cost;
}

function getEventStats(PDO $db, int $eventId): array {
    $stmt = $db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    $stmt = $db->prepare('SELECT event_id, product_id, selling_price, quantity_sold FROM event_sales WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $sales = $stmt->fetchAll();

    $totalRevenue = 0.0;
    $totalCost    = 0.0;
    $totalQty     = 0;

    foreach ($sales as $sale) {
        $productCost    = getProductCost($db, (int)$sale['product_id']);
        $rate           = (float)$event['exchange_rate'];
        $totalRevenue  += $sale['selling_price'] * $sale['quantity_sold'] * $rate;
        $totalCost     += $productCost * $sale['quantity_sold'];
        $totalQty      += $sale['quantity_sold'];
    }

    $boothFeeJpy = (float)$event['booth_fee'] * (float)$event['exchange_rate'];
    $profit      = $totalRevenue - $totalCost - $boothFeeJpy;

    return [
        'revenue'   => $totalRevenue,
        'cost'      => $totalCost,
        'booth_fee' => $boothFeeJpy,
        'profit'    => $profit,
        'qty'       => $totalQty,
    ];
}

function formatJpy(float $amount): string {
    return '¥' . number_format(round($amount));
}

function formatCurrency(float $amount, string $currency = 'JPY'): string {
    $symbols = ['JPY' => '¥', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CNY' => '¥', 'KRW' => '₩'];
    $sym = $symbols[$currency] ?? $currency . ' ';
    return $sym . number_format($amount, 2);
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flashSet(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flashGet(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function httpPost(string $url, array $data, array $headers = []): string {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => http_build_query($data),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $result = file_get_contents($url, false, $ctx);
    return $result !== false ? $result : '';
}

function httpGet(string $url, array $headers = []): string {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $result = file_get_contents($url, false, $ctx);
    return $result !== false ? $result : '';
}

function csrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('不正なリクエストです。');
    }
}

// ── Markup / Pricing ─────────────────────────────────────────────────────────

function calcSellingPrice(float $cost, float $rate, string $type): float {
    if ($cost <= 0) return 0.0;
    return match ($type) {
        'multiplier' => $cost * $rate,
        'margin'     => ($rate >= 100) ? 0.0 : $cost / (1 - $rate / 100),
        default      => $cost * (1 + $rate / 100), // over_cost
    };
}

function markupLabel(string $type, float $rate): string {
    return match ($type) {
        'multiplier' => '×' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '倍',
        'margin'     => '利益率 ' . number_format($rate, 1) . '%',
        default      => '＋' . number_format($rate, 1) . '%上乗せ',
    };
}

function markupTypeLabel(string $type): string {
    return match ($type) {
        'multiplier' => '掛け率（×倍）',
        'margin'     => '利益率（%）',
        default      => '上乗せ率（%）',
    };
}

// ── User settings ─────────────────────────────────────────────────────────────

function getUserSettings(PDO $db, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: [
        'brand_name'          => '',
        'default_hourly_rate' => 0.0,
        'default_markup_rate' => 100.0,
        'default_markup_type' => 'over_cost',
        'tax_rate'            => 10.0,
        'include_tax'         => 0,
    ];
}

function saveUserSettings(PDO $db, int $userId, array $d): void {
    $db->prepare('
        INSERT INTO user_settings (user_id, brand_name, default_hourly_rate, default_markup_rate, default_markup_type, tax_rate, include_tax)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            brand_name=VALUES(brand_name),
            default_hourly_rate=VALUES(default_hourly_rate),
            default_markup_rate=VALUES(default_markup_rate),
            default_markup_type=VALUES(default_markup_type),
            tax_rate=VALUES(tax_rate),
            include_tax=VALUES(include_tax),
            updated_at=NOW()
    ')->execute([
        $userId,
        $d['brand_name'],
        $d['default_hourly_rate'],
        $d['default_markup_rate'],
        $d['default_markup_type'],
        $d['tax_rate'],
        $d['include_tax'],
    ]);
}

function effectiveMarkupRate(array $product, array $settings): float {
    return $product['markup_rate'] !== null ? (float)$product['markup_rate'] : (float)$settings['default_markup_rate'];
}

function effectiveMarkupType(array $product, array $settings): string {
    return $product['markup_type'] !== null ? $product['markup_type'] : $settings['default_markup_type'];
}

function effectiveHourlyRate(array $product, array $settings): float {
    $hr = (float)$product['hourly_rate'];
    return $hr > 0 ? $hr : (float)$settings['default_hourly_rate'];
}

// ── Quote helpers ─────────────────────────────────────────────────────────────

function generateQuoteNumber(PDO $db, int $userId): string {
    $stmt = $db->prepare("SELECT COUNT(*) FROM quotes WHERE user_id=? AND DATE(created_at)=CURDATE()");
    $stmt->execute([$userId]);
    $seq = (int)$stmt->fetchColumn() + 1;
    return sprintf('Q-%s-%03d', date('Ymd'), $seq);
}

function getQuoteTotal(array $items, float $taxRate, bool $includeTax): array {
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += $item['unit_price'] * $item['quantity'];
    }
    $tax   = $subtotal * ($taxRate / 100);
    $total = $includeTax ? $subtotal : $subtotal + $tax;
    return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total];
}

function quoteStatusLabel(string $status): string {
    return match ($status) {
        'sent'     => '送付済み',
        'accepted' => '受注',
        'declined' => '辞退',
        default    => '下書き',
    };
}

function quoteStatusClass(string $status): string {
    return match ($status) {
        'sent'     => 'tag-usd',
        'accepted' => 'tag-jpy',
        'declined' => 'tag-other',
        default    => '',
    };
}
