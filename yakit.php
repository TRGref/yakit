<?php
declare(strict_types=1);

// Usage:
//   php yakit.php
//   php yakit.php ANKARA
//   https://localhost/yakit.php
//   https://localhost/yakit.php?il=ANKARA

$provinceFilter = $_GET['il'] ?? $argv[1] ?? null;
if ($provinceFilter === null || trim((string)$provinceFilter) === '') {
    showUsage();
}

$wanted = normalizeKey((string)$provinceFilter);

$po = fetchPo($wanted);
$aytemiz = fetchAytemiz($wanted);
$opet = fetchOpet($wanted);
$shell = fetchShell($wanted);

$keys = array_unique(array_merge(array_keys($po), array_keys($aytemiz), array_keys($opet), array_keys($shell)));
sort($keys, SORT_STRING);

$rows = [];
foreach ($keys as $key) {
    if (!provinceMatches($key, $wanted)) {
        continue;
    }

    if ($key === 'ISTANBUL' && (in_array('ISTANBUL ANADOLU', $keys, true) || in_array('ISTANBUL AVRUPA', $keys, true))) {
        continue;
    }

    $rows[] = [
        'il' => displayProvince($key),
        'opet' => $opet[$key] ?? emptyStation(),
        'shell' => shellForKey($shell, $key),
        'po' => $po[$key] ?? emptyStation(),
        'aytemiz' => $aytemiz[$key] ?? emptyStation(),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

function fetchPo(?string $wanted): array
{
    $html = httpRequest('https://www.petrolofisi.com.tr/akaryakit-fiyatlari', [
        'Referer: https://www.petrolofisi.com.tr/',
    ]);

    preg_match_all('/<tr class="price-row[^"]*"\s+data-disctrict-id="([^"]*)"\s+data-disctrict-name="([^"]*)"[^>]*>(.*?)<\/tr>/su', $html, $matches, PREG_SET_ORDER);

    $rows = [];
    foreach ($matches as $match) {
        $key = normalizeKey($match[2]);
        if (!provinceMatches($key, $wanted)) {
            continue;
        }

        preg_match_all('/<span class="with-tax">([^<]*)<\/span>/u', $match[3], $prices);
        if (count($prices[1] ?? []) < 6) {
            continue;
        }

        $rows[$key] = [
            'benzin' => parsePrice($prices[1][0]),
            'dizel' => parsePrice($prices[1][1]),
            'lpg' => parsePrice($prices[1][5]),
        ];
    }

    return $rows;
}

function fetchAytemiz(?string $wanted): array
{
    $fuelHtml = httpRequest('https://www.aytemiz.com.tr/akaryakit-fiyatlari/motorin-fiyatlari', [
        'Referer: https://www.aytemiz.com.tr/akaryakit-fiyatlari/motorin-fiyatlari',
    ]);
    $lpgHtml = httpRequest('https://www.aytemiz.com.tr/akaryakit-fiyatlari/lpg-fiyatlari', [
        'Referer: https://www.aytemiz.com.tr/akaryakit-fiyatlari/lpg-fiyatlari',
    ]);

    $lpgRows = [];
    preg_match_all('~<tr>\s*<td[^>]*>\s*<a\s+href=([^>\s]+)[^>]*>(.*?)</a>\s*<td[^>]*>([0-9]+,[0-9]+)~su', $lpgHtml, $lpgMatches, PREG_SET_ORDER);
    foreach ($lpgMatches as $match) {
        if (strpos($match[1], 'lpg-fiyatlari') === false) {
            continue;
        }
        $lpgRows[normalizeKey($match[2])] = parsePrice($match[3]);
    }

    $rows = [];
    preg_match_all('~<tr>\s*<td[^>]*>\s*<a\s+href=([^>\s]+)[^>]*>(.*?)</a>\s*<td>([^<]*)<td>([^<]*)<td>([^<]*)<td>([^<]*)<td>([^<]*)~su', $fuelHtml, $fuelMatches, PREG_SET_ORDER);
    foreach ($fuelMatches as $match) {
        $key = normalizeKey($match[2]);
        if (!provinceMatches($key, $wanted)) {
            continue;
        }

        $rows[$key] = [
            'benzin' => parsePrice($match[3]),
            'dizel' => parsePrice($match[4]),
            'lpg' => $lpgRows[$key] ?? null,
        ];
    }

    return $rows;
}

function fetchOpet(?string $wanted): array
{
    $allPrices = getJson('https://api.opet.com.tr/api/fuelprices/allprices', [
        'Accept: application/json',
        'Accept-Language: tr-TR',
        'Channel: Web',
        'Origin: https://www.opet.com.tr',
        'Referer: https://www.opet.com.tr/akaryakit-fiyatlari',
    ]);

    if ($wanted !== null) {
        $allPrices = array_values(array_filter($allPrices, static function (array $row) use ($wanted): bool {
            return provinceMatches(normalizeKey((string)($row['provinceName'] ?? '')), $wanted);
        }));
    }

    $token = getAygazSessionToken();
    $expiry = getAygazJson('https://mt-ecommerce-productapi.aygaz.com.tr/api/Price/GetOtogazExpiryDates', $token);
    $validityDate = $expiry['Data'][0]['ExpiryDate'] ?? null;
    $lpgCache = [];

    $rows = [];
    foreach ($allPrices as $row) {
        $prices = [];
        foreach (($row['prices'] ?? []) as $price) {
            $prices[$price['productCode']] = $price['amount'] ?? null;
        }

        $key = normalizeKey((string)($row['provinceName'] ?? ''));
        $cityId = aygazCityId($row);
        $lpg = null;
        if ($cityId !== null && $validityDate !== null) {
            $cacheKey = $cityId . '|' . $validityDate;
            if (!array_key_exists($cacheKey, $lpgCache)) {
                $lpgCache[$cacheKey] = getAygazLpgPrice($token, $cityId, $validityDate);
                usleep(100000);
            }
            $lpg = $lpgCache[$cacheKey];
        }

        $rows[$key] = [
            'benzin' => toFloat($prices['A100'] ?? null),
            'dizel' => toFloat($prices['A121'] ?? ($prices['A128'] ?? null)),
            'lpg' => $lpg,
        ];
    }

    return $rows;
}

function fetchShell(?string $wanted): array
{
    $provinceCodes = shellProvinceCodes();
    if ($wanted !== null) {
        $provinceCodes = array_filter($provinceCodes, static function (string $code, string $name) use ($wanted): bool {
            if (strpos($wanted, 'ISTANBUL') === 0 && $name === 'ISTANBUL') {
                return true;
            }

            return provinceMatches($name, $wanted);
        }, ARRAY_FILTER_USE_BOTH);
    }

    $baseUrl = 'https://www.turkiyeshell.com/pompatest/';
    $cookieFile = tempnam(sys_get_temp_dir(), 'shell_master_');
    if (!is_string($cookieFile)) {
        return [];
    }

    try {
        $firstPage = shellRequest($baseUrl, null, $cookieFile, 'Shell first page');
        $hidden = [
            '__VIEWSTATE' => hiddenValue($firstPage, '__VIEWSTATE'),
            '__VIEWSTATEGENERATOR' => hiddenValue($firstPage, '__VIEWSTATEGENERATOR'),
            '__EVENTVALIDATION' => hiddenValue($firstPage, '__EVENTVALIDATION'),
        ];
        shellDebug('Shell hidden fields: ' . json_encode([
            '__VIEWSTATE' => $hidden['__VIEWSTATE'] !== '',
            '__VIEWSTATEGENERATOR' => $hidden['__VIEWSTATEGENERATOR'] !== '',
            '__EVENTVALIDATION' => $hidden['__EVENTVALIDATION'] !== '',
        ], JSON_UNESCAPED_SLASHES));

        $rows = [];
        foreach ($provinceCodes as $provinceName => $provinceCode) {
            $rawRows = fetchShellProvinceRows($baseUrl, $cookieFile, $hidden, $provinceCode);
            $rows[$provinceName] = summarizeShell($rawRows);
            if ($wanted === null) {
                usleep(100000);
            }
        }

        return $rows;
    } finally {
        if (file_exists($cookieFile)) {
            unlink($cookieFile);
        }
    }
}

function fetchShellProvinceRows(string $baseUrl, string $cookieFile, array $hidden, string $provinceCode): array
{
    $action = [
        'Action' => 'OnProvinceSelect',
        'Params' => [
            'county_code' => null,
            'province_code' => $provinceCode,
        ],
    ];

    $postFields = $hidden + [
        '__CALLBACKID' => 'cb_all',
        '__CALLBACKPARAM' => 'c0:' . json_encode($action, JSON_UNESCAPED_SLASHES),
    ];

    $callback = shellRequest($baseUrl, $postFields, $cookieFile, 'Shell callback ' . $provinceCode);
    $html = shellCallbackHtml($callback);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $rows = [];
    foreach ($xpath->query('//tr[contains(@class, "dxgvDataRow") or count(./td) >= 8]') as $row) {
        $values = [];
        foreach ($xpath->query('./td', $row) as $cell) {
            $values[] = cleanText($cell->textContent);
        }
        if (count($values) >= 8) {
            $rows[] = $values;
        }
    }

    shellDebug("Shell callback rows for {$provinceCode}: " . count($rows));
    if (!$rows) {
        shellDebug("Shell callback html preview for {$provinceCode}: " . shellBodyPreview($html !== '' ? $html : $callback));
    }

    return $rows;
}

function summarizeShell(array $rawRows): array
{
    $summary = emptyStation();

    foreach ($rawRows as $row) {
        $benzin = parsePrice($row[1] ?? null);
        $dizel = parsePrice($row[2] ?? null);
        $lpg = parsePrice($row[7] ?? null);

        if ($summary['lpg'] === null && $lpg !== null) {
            $summary['lpg'] = $lpg;
        }

        if (($summary['benzin'] === null || $summary['dizel'] === null) && ($benzin !== null || $dizel !== null)) {
            $summary['benzin'] = $benzin;
            $summary['dizel'] = $dizel;
        }
    }

    return $summary;
}

function shellForKey(array $shell, string $key): array
{
    if (isset($shell[$key])) {
        return $shell[$key];
    }

    if (strpos($key, 'ISTANBUL') !== false && isset($shell['ISTANBUL'])) {
        return $shell['ISTANBUL'];
    }

    return emptyStation();
}

function provinceMatches(string $key, string $wanted): bool
{
    if ($key === $wanted) {
        return true;
    }

    if ($wanted === 'ISTANBUL' && strpos($key, 'ISTANBUL') === 0) {
        return true;
    }

    return false;
}

function getAygazSessionToken(): string
{
    $payload = json_encode([
        'appVersion' => '',
        'language' => 'Mozilla/5.0',
        'userAgent' => 'Mozilla/5.0',
        'platform' => 'Windows',
        'ipAddress' => '',
    ], JSON_UNESCAPED_SLASHES);

    $json = getAygazJson('https://mt-ecommerce-infobridge.aygaz.com.tr/api/getsession', null, $payload);
    $token = $json['Data']['SessionToken'] ?? null;
    if (!is_string($token) || $token === '') {
        return '';
    }

    return $token;
}

function getAygazLpgPrice(string $token, string $cityId, string $validityDate): ?float
{
    if ($token === '') {
        return null;
    }

    $url = 'https://mt-ecommerce-productapi.aygaz.com.tr/api/Price/GetOtogazPrice?'
        . http_build_query([
            'cityId' => $cityId,
            'validityDate' => $validityDate,
        ], '', '&', PHP_QUERY_RFC3986);

    $json = getAygazJson($url, $token);
    return toFloat($json['Data']['Price'] ?? null);
}

function getAygazJson(string $url, ?string $token = null, ?string $postBody = null): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Origin: https://www.aygaz.com.tr',
        'Referer: https://www.aygaz.com.tr/fiyatlar/otogaz/istanbul-anadolu',
        'X-AppID: 8BC0A9EF-047A-4298-BFE2-942B5AF098CD',
        'X-DeviceCode: WEBCIHAZI-10',
        'X-Lang: tr',
    ];
    if ($token !== null) {
        $headers[] = 'X-SessionToken: ' . $token;
    }

    return getJson($url, $headers, $postBody);
}

function getJson(string $url, array $headers, ?string $postBody = null): array
{
    $body = httpRequest($url, $headers, $postBody);
    $json = json_decode($body, true);
    if (is_string($json)) {
        $json = json_decode($json, true);
    }

    return is_array($json) ? $json : [];
}

function httpRequest(string $url, array $headers = [], ?string $postBody = null): string
{
    $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($postBody !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ($body !== false && $status < 400) ? (string)$body : '';
}

function shellRequest(string $url, ?array $postFields, string $cookieFile, string $label = 'Shell request'): string
{
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Origin: https://www.turkiyeshell.com',
        'Pragma: no-cache',
        'Referer: https://www.turkiyeshell.com/pompatest/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ];

    if ($postFields !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields, '', '&', PHP_QUERY_RFC3986));
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    shellDebug(sprintf(
        '%s status=%s bytes=%s error=%s',
        $label,
        $status ?: 'n/a',
        $body === false ? 0 : strlen((string)$body),
        $error !== '' ? $error : '-'
    ));

    if (($body === false || $status >= 400) && $body !== false && (string)$body !== '') {
        shellDebug($label . ' body preview=' . shellBodyPreview((string)$body));
    }

    return ($body !== false && $status < 400) ? (string)$body : '';
}

function shellDebug(string $message): void
{
    if (defined('STDERR')) {
        fwrite(STDERR, "[debug] {$message}\n");
    }
}

function shellBodyPreview(string $body): string
{
    $body = preg_replace('/\s+/', ' ', strip_tags($body)) ?? $body;
    $body = trim($body);

    if (function_exists('mb_substr')) {
        return mb_substr($body, 0, 300, 'UTF-8');
    }

    return substr($body, 0, 300);
}

function hiddenValue(string $html, string $name): string
{
    if (!preg_match('/id="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $match)) {
        return '';
    }

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function shellCallbackHtml(string $body): string
{
    $start = strpos($body, "'result':'");
    if ($start === false) {
        shellDebug('Shell callback result field missing. Body preview=' . shellBodyPreview($body));
        return '';
    }

    $value = readSingleQuotedJsValue($body, $start + strlen("'result':'"));
    if ($value === '') {
        shellDebug('Shell callback result field empty. Body preview=' . shellBodyPreview($body));
        return '';
    }

    $html = str_replace('\\/', '/', stripcslashes($value));
    shellDebug('Shell callback html bytes=' . strlen($html));

    return $html;
}

function readSingleQuotedJsValue(string $text, int $offset): string
{
    $value = '';
    $length = strlen($text);
    $escaped = false;

    for ($i = $offset; $i < $length; $i++) {
        $char = $text[$i];

        if ($escaped) {
            $value .= '\\' . $char;
            $escaped = false;
            continue;
        }

        if ($char === '\\') {
            $escaped = true;
            continue;
        }

        if ($char === "'") {
            break;
        }

        $value .= $char;
    }

    if ($escaped) {
        $value .= '\\';
    }

    return $value;
}

function aygazCityId(array $row): ?string
{
    $name = normalizeKey((string)($row['provinceName'] ?? ''));
    if ($name === 'ISTANBUL AVRUPA') {
        return '341';
    }
    if ($name === 'ISTANBUL ANADOLU') {
        return '34';
    }

    $code = $row['provinceCode'] ?? null;
    return is_numeric($code) ? (string)((int)$code) : null;
}

function parsePrice($value): ?float
{
    if ($value === null) {
        return null;
    }

    $value = cleanText((string)$value);
    if ($value === '' || $value === '-') {
        return null;
    }

    if (strpos($value, ',') !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float)$value : null;
}

function toFloat($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float)$value : parsePrice((string)$value);
}

function cleanText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function normalizeKey(string $value): string
{
    $value = cleanText($value);
    $value = str_replace(['-', '/', '(', ')'], ' ', $value);
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    $value = strtr($value, [
        'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'I' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U', 'Â' => 'A',
        'ç' => 'C', 'ğ' => 'G', 'ı' => 'I', 'i' => 'I', 'ö' => 'O', 'ş' => 'S', 'ü' => 'U', 'â' => 'A',
        'AGRI' => 'AGRI', 'K MARAS' => 'KAHRAMANMARAS', 'K.MARAS' => 'KAHRAMANMARAS',
    ]);
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

    if ($value === 'AFYON') {
        return 'AFYONKARAHISAR';
    }

    if ($value === 'K MARAS' || $value === 'K.MARAS') {
        return 'KAHRAMANMARAS';
    }

    if ($value === 'ISTANBUL') {
        return 'ISTANBUL';
    }

    return $value;
}

function displayProvince(string $key): string
{
    return slugProvince($key);
}

function slugProvince(string $key): string
{
    return strtolower(str_replace(' ', '-', normalizeKey($key)));
}

function emptyStation(): array
{
    return [
        'benzin' => null,
        'dizel' => null,
        'lpg' => null,
    ];
}

function showUsage(): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'message' => 'Kullanim: yakit.php?il=ANKARA veya CLI: php yakit.php ANKARA',
        'examples' => [
            'yakit.php?il=ankara',
            'yakit.php?il=istanbul',
            'yakit.php?il=istanbul-anadolu',
            'yakit.php?il=istanbul-avrupa',
        ],
        'cities' => array_merge(array_keys(shellProvinceCodes()), [
            'ISTANBUL ANADOLU',
            'ISTANBUL AVRUPA',
        ]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function shellProvinceCodes(): array
{
    return [
        'ADANA' => '001', 'ADIYAMAN' => '002', 'AFYONKARAHISAR' => '003', 'AGRI' => '004',
        'AKSARAY' => '068', 'AMASYA' => '005', 'ANKARA' => '006', 'ANTALYA' => '007',
        'ARDAHAN' => '075', 'ARTVIN' => '008', 'AYDIN' => '009', 'BALIKESIR' => '010',
        'BARTIN' => '074', 'BATMAN' => '072', 'BAYBURT' => '069', 'BILECIK' => '011',
        'BINGOL' => '012', 'BITLIS' => '013', 'BOLU' => '014', 'BURDUR' => '015',
        'BURSA' => '016', 'CANAKKALE' => '017', 'CANKIRI' => '018', 'CORUM' => '019',
        'DENIZLI' => '020', 'DIYARBAKIR' => '021', 'DUZCE' => '081', 'EDIRNE' => '022',
        'ELAZIG' => '023', 'ERZINCAN' => '024', 'ERZURUM' => '025', 'ESKISEHIR' => '026',
        'GAZIANTEP' => '027', 'GIRESUN' => '028', 'GUMUSHANE' => '029', 'HAKKARI' => '030',
        'HATAY' => '031', 'IGDIR' => '076', 'ISPARTA' => '032', 'ISTANBUL' => '034',
        'IZMIR' => '035', 'KAHRAMANMARAS' => '046', 'KARABUK' => '078', 'KARAMAN' => '070',
        'KARS' => '036', 'KASTAMONU' => '037', 'KAYSERI' => '038', 'KILIS' => '079',
        'KIRIKKALE' => '071', 'KIRKLARELI' => '039', 'KIRSEHIR' => '040', 'KOCAELI' => '041',
        'KONYA' => '042', 'KUTAHYA' => '043', 'MALATYA' => '044', 'MANISA' => '045',
        'MARDIN' => '047', 'MERSIN' => '033', 'MUGLA' => '048', 'MUS' => '049',
        'NEVSEHIR' => '050', 'NIGDE' => '051', 'ORDU' => '052', 'OSMANIYE' => '080',
        'RIZE' => '053', 'SAKARYA' => '054', 'SAMSUN' => '055', 'SANLIURFA' => '063',
        'SIIRT' => '056', 'SINOP' => '057', 'SIRNAK' => '073', 'SIVAS' => '058',
        'TEKIRDAG' => '059', 'TOKAT' => '060', 'TRABZON' => '061', 'TUNCELI' => '062',
        'USAK' => '064', 'VAN' => '065', 'YALOVA' => '077', 'YOZGAT' => '066',
        'ZONGULDAK' => '067',
    ];
}
