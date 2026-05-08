<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$keyword = trim($_GET['keyword'] ?? '');
if ($keyword === '') {
    echo json_encode(['items' => []]);
    exit;
}

$params = [
    'applicationId' => RAKUTEN_APP_ID,
    'keyword'       => $keyword,
    'hits'          => 20,
    'imageFlag'     => 1,
    'sort'          => 'standard',
    'formatVersion' => 2,
];

$url  = 'https://app.rakuten.co.jp/services/api/IchibaItem/Search/20220601?' . http_build_query($params);
$resp = httpGet($url);
$data = json_decode($resp, true);

if (!$data || !isset($data['Items'])) {
    echo json_encode(['items' => [], 'error' => 'API error']);
    exit;
}

$affiliateId = RAKUTEN_AFFILIATE_ID;
$items = [];

foreach ($data['Items'] as $raw) {
    $item     = $raw; // formatVersion=2 returns items directly
    $itemUrl  = $item['itemUrl'] ?? '';
    $affUrl   = $affiliateId
        ? 'https://hb.afl.rakuten.co.jp/ichiba/' . $affiliateId . '/?pc=' . urlencode($itemUrl)
        : $itemUrl;

    $images = $item['mediumImageUrls'] ?? $item['smallImageUrls'] ?? [];
    $imageUrl = $images[0] ?? '';

    $items[] = [
        'name'     => $item['itemName'] ?? '',
        'price'    => (int)($item['itemPrice'] ?? 0),
        'shop'     => $item['shopName'] ?? '',
        'itemCode' => $item['itemCode'] ?? '',
        'url'      => $itemUrl,
        'affUrl'   => $affUrl,
        'image'    => $imageUrl,
    ];
}

echo json_encode(['items' => $items]);
