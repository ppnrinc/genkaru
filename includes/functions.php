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
