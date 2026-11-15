<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const NASA_POWER_ENDPOINT = 'https://power.larc.nasa.gov/api/temporal/climatology/point';
const NASA_PARAMETERS = 'T2M,T2M_RANGE,PRECTOTCORR,RH2M,ALLSKY_SFC_SW_DWN';
const CACHE_TTL_SECONDS = 86400 * 7; // 7 gün

const DEFAULT_TARGET_IDS = [
    'Moon' => 'moon_shackleton_degerlache_ridge',
    'Mars' => 'mars_jezero_crater',
];

/**
 * Seçilen Ay/Mars hedefinden (veya varsayılan Kalıcı Ay Üssü / Kalıcı Mars Üssü referansından)
 * gerçek gezegen referans profilini ve Dünya-zarfına (Earth Envelope) izdüşürülmüş
 * 5 NASA POWER hedef klimatoloji parametresini (T2M, T2M_RANGE, PRECTOTCORR, RH2M, ALLSKY_SFC_SW_DWN) üretir.
 *
 * Böylece referans noktası artık Dünya'daki Atacama veya McMurdo değil,
 * doğrudan Ay (Shackleton-de Gerlache Sırtı / seçilen Ay hedefi) veya Mars (Jezero / Arcadia / seçilen Mars hedefi)
 * üs lokasyonunun kendisidir.
 *
 * @return array<string, mixed>
 */
function resolvePlanetaryReferenceProfile(string $body, string $targetId = '', string $lang = 'tr'): array
{
    $normBody = ($body === 'Moon') ? 'Moon' : 'Mars';
    $lookupId = $targetId !== '' ? $targetId : DEFAULT_TARGET_IDS[$normBody];

    $target = astroDbGetPlanetaryTargetById($lookupId, $lang);
    if ($target === null || ($target['body'] ?? '') !== $normBody) {
        $target = astroDbGetPlanetaryTargetById(DEFAULT_TARGET_IDS[$normBody], $lang);
    }

    if ($target === null) {
        // Veritabanı erişilemezse bilimsel LRO / Perseverance yedeği
        $target = $normBody === 'Moon'
            ? [
                'id'                => 'moon_shackleton_degerlache_ridge',
                'name'              => $lang === 'en' ? 'Shackleton–de Gerlache Ridge (Artemis Permanent Lunar Base)' : 'Shackleton–de Gerlache Sırtı (Artemis Kalıcı Ay Üssü)',
                'country_or_region' => $lang === 'en' ? 'Lunar South Pole (89.35°S, 128.0°W)' : 'Ay Güney Kutbu (89.35°S, 128.0°W)',
                'mineral_type'      => $lang === 'en' ? 'Anorthositic Regolith & Nearby PSR Water Ice' : 'Anortozit Regolit & Yakın PSR Su Buzu',
                'lat'               => -89.35,
                'lng'               => -128.00,
                'body'              => 'Moon',
                'validation_tag'    => 'lunar_polar_base',
                'source_url'        => 'https://www.nasa.gov/artemis',
                'metrics'           => ['avg_temp_c' => -175.0, 'slope_deg' => 8.5, 'humidity_pct' => 5.0, 'regolith_similarity' => 100.0, 'solar_exposure_pct' => 86.0],
            ]
            : [
                'id'                => 'mars_jezero_crater',
                'name'              => $lang === 'en' ? 'Jezero Crater & Isidis Planitia (Mars Base Reference)' : 'Jezero Krateri & Isidis Planitia (Mars Üssü Referansı)',
                'country_or_region' => $lang === 'en' ? 'Syrtis Major Quadrangle, Mars (18.38°N, 77.58°E)' : 'Syrtis Major Dörtgeni, Mars (18.38°N, 77.58°E)',
                'mineral_type'      => $lang === 'en' ? 'Mg-Carbonate (Hydromagnesite), Olivine & Smectite Clay' : 'Mg-Karbonat (Hidromanyezit), Olivin & Smektit Kil',
                'lat'               => 18.38,
                'lng'               => 77.58,
                'body'              => 'Mars',
                'validation_tag'    => 'mars_delta',
                'source_url'        => 'https://mars.nasa.gov/mars2020/',
                'metrics'           => ['avg_temp_c' => -62.0, 'slope_deg' => 5.0, 'humidity_pct' => 1.0, 'regolith_similarity' => 100.0, 'solar_exposure_pct' => 59.0],
            ];
    }

    $vTag = (string)($target['validation_tag'] ?? ($normBody === 'Moon' ? 'lunar_polar_base' : 'mars_catalog'));
    $rawTemp = (float)($target['metrics']['avg_temp_c'] ?? ($normBody === 'Moon' ? -150.0 : -60.0));
    $rawSolarPct = (float)($target['metrics']['solar_exposure_pct'] ?? ($normBody === 'Moon' ? 80.0 : 58.0));

    // Gezegen hedefinin rejimine göre Dünya Zarfı (Earth-Envelope) NASA POWER hedef vektörü
    if ($vTag === 'cold_polar' || $vTag === 'permafrost') {
        $envT2M = round(astroClip($rawTemp, -32.0, -8.0), 2);
        $envRange = 14.5;
        $envPrec = 0.08;
        $envRh = 38.0;
        $envSw = round(max(2.2, min(5.2, ($rawSolarPct / 100.0) * 6.0 + 2.4)), 2);
    } elseif ($vTag === 'lunar_polar_base') {
        $envT2M = round(astroClip($rawTemp, -22.0, 2.0), 2);
        $envRange = 16.5;
        $envPrec = 0.12;
        $envRh = 32.0;
        $envSw = round(max(3.8, min(6.8, ($rawSolarPct / 100.0) * 7.2)), 2);
    } elseif ($vTag === 'lava_tube') {
        $envT2M = 9.5;
        $envRange = 13.5;
        $envPrec = 0.35;
        $envRh = 36.0;
        $envSw = 5.4;
    } elseif ($vTag === 'mars_delta') {
        $envT2M = 11.5;
        $envRange = 16.8;
        $envPrec = 0.25;
        $envRh = 28.0;
        $envSw = 5.8;
    } else {
        // Hiper-kurak Mars havzası veya Ay mare/krater yüzeyi
        $envT2M = round(astroClip($rawTemp, -10.0, 18.0), 2);
        $envRange = 19.2;
        $envPrec = 0.04;
        $envRh = 18.0;
        $envSw = round(max(4.5, min(7.4, ($rawSolarPct / 100.0) * 8.2)), 2);
    }

    $lat = (float)($target['lat'] ?? 0.0);
    $lng = (float)($target['lng'] ?? 0.0);
    $latStr = sprintf('%.2f°%s', abs($lat), $lat >= 0 ? 'N' : 'S');
    $lngStr = sprintf('%.2f°%s', abs($lng), $lng >= 0 ? 'E' : 'W');

    return [
        'id'                => (string)$target['id'],
        'name'              => (string)$target['name'],
        'name_en'           => (string)($target['name_en'] ?? $target['name']),
        'country_or_region' => (string)($target['country_or_region'] ?? "{$normBody} • {$latStr}, {$lngStr}"),
        'mineral_type'      => (string)($target['mineral_type'] ?? ($lang === 'en' ? 'Planetary Base Reference' : 'Gezegen Üssü Referansı')),
        'lat'               => $lat,
        'lng'               => $lng,
        'body'              => $normBody,
        'validation_tag'    => $vTag,
        'raw_planetary_temp_c' => $rawTemp,
        'source_url'        => (string)($target['source_url'] ?? 'https://trek.nasa.gov/'),
        'metrics'           => [
            'T2M'               => $envT2M,
            'T2M_RANGE'         => $envRange,
            'PRECTOTCORR'       => $envPrec,
            'RH2M'              => $envRh,
            'ALLSKY_SFC_SW_DWN' => $envSw,
        ],
    ];
}

const CONTROL_POINTS = [
    ['label' => 'Karadeniz Kontrol (Negatif)', 'lat' => 41.00, 'lng' => 39.70],
    ['label' => 'Sahra Kontrol (Pozitif)', 'lat' => 23.41, 'lng' => 25.66],
];

/**
 * İstek parametrelerini GET veya POST JSON üzerinden okur.
 *
 * @return array<string, mixed>
 */
function getInputParams(): array
{
    $input = $_REQUEST;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
    }
    return $input;
}

/**
 * Koordinat için önbellek dosya yolunu döndürür.
 */
function getCacheFilePath(float $lat, float $lon): string
{
    $dir = __DIR__ . '/../data/nasa_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $key = sprintf('%.4f_%.4f', round($lat, 4), round($lon, 4));
    return $dir . '/' . $key . '.json';
}

/**
 * Önbellekten (önce SQLite nasa_power_cache, sonra dosya) NASA POWER verisini okur.
 *
 * @return array<string, float>|null
 */
function readCachedPoint(float $lat, float $lon): ?array
{
    $key = sprintf('%.4f_%.4f', round($lat, 4), round($lon, 4));
    try {
        $pdo = astroDb();
        $stmt = $pdo->prepare("SELECT * FROM nasa_power_cache WHERE coord_key = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        if ($row && (time() - (int)$row['updated_at']) <= CACHE_TTL_SECONDS) {
            return [
                'T2M'               => (float)$row['t2m'],
                'T2M_RANGE'         => (float)$row['t2m_range'],
                'PRECTOTCORR'       => (float)$row['prectotcorr'],
                'RH2M'              => (float)$row['rh2m'],
                'ALLSKY_SFC_SW_DWN' => (float)$row['allsky_sfc_sw_dwn'],
            ];
        }
    } catch (Throwable $e) {
        // SQLite okuma hatasında dosya önbelleğine devam et
    }

    $file = getCacheFilePath($lat, $lon);
    if (!is_file($file)) {
        return null;
    }
    if ((time() - filemtime($file)) > CACHE_TTL_SECONDS) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * NASA POWER verisini hem SQLite veritabanına hem de dosya önbelleğine yazar.
 *
 * @param array<string, float> $metrics
 */
function writeCachedPoint(float $lat, float $lon, array $metrics, string $source = 'nasa_power'): void
{
    $key = sprintf('%.4f_%.4f', round($lat, 4), round($lon, 4));
    try {
        $pdo = astroDb();
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO nasa_power_cache (
                coord_key, lat, lng, t2m, t2m_range, prectotcorr, rh2m, allsky_sfc_sw_dwn, source, updated_at
            ) VALUES (
                :k, :lat, :lng, :t2m, :t2mr, :prec, :rh, :sw, :src, :ts
            )
        ");
        $stmt->execute([
            ':k'    => $key,
            ':lat'  => round($lat, 4),
            ':lng'  => round($lon, 4),
            ':t2m'  => (float)($metrics['T2M'] ?? 10.0),
            ':t2mr' => (float)($metrics['T2M_RANGE'] ?? 12.0),
            ':prec' => (float)($metrics['PRECTOTCORR'] ?? 0.5),
            ':rh'   => (float)($metrics['RH2M'] ?? 40.0),
            ':sw'   => (float)($metrics['ALLSKY_SFC_SW_DWN'] ?? 5.0),
            ':src'  => $source,
            ':ts'   => time(),
        ]);
    } catch (Throwable $e) {
        // SQLite yazma hatasını yoksay
    }

    $file = getCacheFilePath($lat, $lon);
    @file_put_contents($file, json_encode($metrics, JSON_UNESCAPED_UNICODE));
}

/**
 * Canlı NASA POWER API erişilemediğinde veya zaman aşımına uğradığında
 * küresel klimatoloji modelinden 5 NASA POWER parametresini kesintisiz üretir.
 *
 * @return array<string, float>
 */
function synthesizeNasaPowerFallback(float $lat, float $lng): array
{
    $synth = astroEstimateEarthMetricsFromCoords($lat, $lng, []);
    $m = $synth['metrics'];
    $t2m = (float)$m['avg_temp_c'];
    $rh = (float)$m['humidity_pct'];
    $solarPct = (float)$m['solar_exposure_pct'];

    return [
        'T2M'               => round($t2m, 2),
        'T2M_RANGE'         => round(max(4.2, 21.5 - ($rh * 0.14)), 2),
        'PRECTOTCORR'       => round(max(0.02, (($rh / 100.0) ** 2.1) * 3.6), 2),
        'RH2M'              => round($rh, 2),
        'ALLSKY_SFC_SW_DWN' => round(max(1.1, ($solarPct / 100.0) * 7.8), 2),
    ];
}

/**
 * NASA POWER JSON yanıtından 5 yıllık ortalama (ANN) metriği ayıklar.
 *
 * @return array<string, float>|null
 */
function parseNasaPowerResponse(string $jsonBody): ?array
{
    $decoded = json_decode($jsonBody, true);
    if (!is_array($decoded)) {
        return null;
    }

    $param = $decoded['properties']['parameter'] ?? null;
    if (!is_array($param)) {
        return null;
    }

    $keys = ['T2M', 'T2M_RANGE', 'PRECTOTCORR', 'RH2M', 'ALLSKY_SFC_SW_DWN'];
    $result = [];
    foreach ($keys as $k) {
        if (!isset($param[$k]['ANN']) || !is_numeric($param[$k]['ANN'])) {
            return null;
        }
        $val = (float)$param[$k]['ANN'];
        if ($val <= -900.0) {
            return null;
        }
        $result[$k] = round($val, 2);
    }

    return $result;
}

/**
 * Birden fazla koordinat için NASA POWER verilerini paralel (curl_multi) çeker.
 *
 * @param array<int, array{lat: float, lng: float}> $coords
 * @return array<int, array<string, float>|null>
 */
function fetchNasaPowerBatch(array $coords): array
{
    $results = array_fill(0, count($coords), null);
    $toFetch = [];

    foreach ($coords as $idx => $coord) {
        $lat = round((float)$coord['lat'], 4);
        $lng = round((float)$coord['lng'], 4);
        $cached = readCachedPoint($lat, $lng);
        if ($cached !== null) {
            $results[$idx] = $cached;
        } else {
            $toFetch[$idx] = ['lat' => $lat, 'lng' => $lng];
        }
    }

    if ($toFetch === []) {
        return $results;
    }

    $chunks = array_chunk($toFetch, 12, true);
    foreach ($chunks as $chunk) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($chunk as $idx => $coord) {
            $url = NASA_POWER_ENDPOINT . '?' . http_build_query([
                'parameters' => NASA_PARAMETERS,
                'community'  => 'RE',
                'longitude'  => $coord['lng'],
                'latitude'   => $coord['lat'],
                'format'     => 'JSON',
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'EXPLOG-SpaceApps2026/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $idx => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode === 200 && is_string($body) && $body !== '') {
                $parsed = parseNasaPowerResponse($body);
                if ($parsed !== null) {
                    $results[$idx] = $parsed;
                    writeCachedPoint($chunk[$idx]['lat'], $chunk[$idx]['lng'], $parsed, 'nasa_power_live');
                }
            }
            if ($results[$idx] === null) {
                $fallback = synthesizeNasaPowerFallback($chunk[$idx]['lat'], $chunk[$idx]['lng']);
                $results[$idx] = $fallback;
                writeCachedPoint($chunk[$idx]['lat'], $chunk[$idx]['lng'], $fallback, 'climatology_grid_model');
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    return $results;
}

/**
 * Ağırlıklı MinMaxScaler + Hibrit Benzerlik (%40 Kosinüs + %30 Öklid + %30 Ağırlıklı Geometrik Ortalama)
 * ile adayları skorlar ve Novelty, Nearest Cave, TRL 5-6 Qualification bilgilerini ekler.
 *
 * @param array<string, float> $refMetrics
 * @param array<int, array<string, mixed>> $candidates
 * @return array<int, array<string, mixed>>
 */
function calculateHybridScores(array $refMetrics, array $candidates, string $lang = 'tr'): array
{
    $features = ['T2M', 'T2M_RANGE', 'PRECTOTCORR', 'RH2M', 'ALLSKY_SFC_SW_DWN'];
    $weights = [
        'T2M'               => 0.12,
        'T2M_RANGE'         => 0.28,
        'PRECTOTCORR'       => 0.30,
        'RH2M'              => 0.20,
        'ALLSKY_SFC_SW_DWN' => 0.10,
    ];

    // Sabit Dünya Zarfı Span değerleri
    $fixedSpans = [
        'T2M'               => 85.0,  // °C
        'T2M_RANGE'         => 25.0,  // K
        'PRECTOTCORR'       => 6.0,   // mm/gün (~2190 mm/yıl)
        'RH2M'              => 95.0,  // %
        'ALLSKY_SFC_SW_DWN' => 7.5,   // kWh/m²/gün
    ];

    $allRows = array_merge([['metrics' => $refMetrics]], $candidates);
    $rowCount = count($allRows);

    $mins = [];
    $maxs = [];
    foreach ($features as $f) {
        $vals = array_map(static fn(array $row): float => (float)$row['metrics'][$f], $allRows);
        $mins[$f] = min($vals);
        $maxs[$f] = max($vals);
    }

    $normRows = [];
    for ($i = 0; $i < $rowCount; $i++) {
        $vec = [];
        foreach ($features as $f) {
            $range = $maxs[$f] - $mins[$f];
            $val = (float)$allRows[$i]['metrics'][$f];
            $scaled = $range > 1e-12 ? (($val - $mins[$f]) / $range) : 0.0;
            $vec[] = $scaled * $weights[$f];
        }
        $normRows[$i] = $vec;
    }

    $refVec = $normRows[0];
    $refNorm = sqrt(array_sum(array_map(static fn(float $v): float => $v * $v, $refVec)));

    $scored = [];
    for ($i = 1; $i < $rowCount; $i++) {
        $candVec = $normRows[$i];
        $candNorm = sqrt(array_sum(array_map(static fn(float $v): float => $v * $v, $candVec)));
        $candMetrics = $candidates[$i - 1]['metrics'];

        // 1. Ağırlıklı Kosinüs Benzerliği
        $dot = 0.0;
        $sqDiffSum = 0.0;
        for ($j = 0; $j < count($features); $j++) {
            $dot += $refVec[$j] * $candVec[$j];
            $diff = $candVec[$j] - $refVec[$j];
            $sqDiffSum += $diff * $diff;
        }

        $cosSim = ($refNorm > 1e-12 && $candNorm > 1e-12)
            ? ($dot / ($refNorm * $candNorm))
            : 0.0;

        // 2. Ağırlıklı Öklid Mesafesi Benzerliği
        $euclidDist = sqrt($sqDiffSum);
        $euclidSim = 1.0 / (1.0 + $euclidDist);

        // 3. Sabit Zarflı Geometrik Ortalama (prod s_k ^ w_k)
        $geoProd = 1.0;
        foreach ($features as $f) {
            $dist = abs((float)$candMetrics[$f] - (float)$refMetrics[$f]) / $fixedSpans[$f];
            $s_k = max(0.02, min(1.0, 1.0 - $dist));
            $geoProd *= ($s_k ** $weights[$f]);
        }

        $hybridScore = (0.40 * $cosSim + 0.30 * $euclidSim + 0.30 * $geoProd) * 100.0;
        $score = round(max(0.0, min(100.0, $hybridScore)), 2);

        $item = $candidates[$i - 1];
        $item['similarity_score'] = $score;
        $item['geometric_score'] = round($geoProd * 100.0, 1);

        $lat = (float)($item['lat'] ?? 0.0);
        $lng = (float)($item['lng'] ?? 0.0);
        $item['novelty'] = astroComputeNovelty($lat, $lng, $lang);
        $item['nearest_cave'] = astroFindNearestCave($lat, $lng, $lang);
        $item['qualification'] = astroAssessHardwareReadiness($item, $lang);

        $scored[] = $item;
    }

    return $scored;
}

/**
 * İki sayı arasında $count adet eşit aralıklı değer üretir (numpy.linspace).
 *
 * @return array<int, float>
 */
function linspace(float $start, float $stop, int $count): array
{
    if ($count <= 1) {
        return [round($start, 4)];
    }
    $step = ($stop - $start) / ($count - 1);
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $out[] = round($start + ($i * $step), 4);
    }
    return $out;
}

/**
 * Ray-casting algoritması ile (lat, lng) noktasının poligon içinde olup olmadığını kontrol eder.
 *
 * @param array<int, array{lat: float, lng: float}> $polygon
 */
function isPointInPolygon(float $lat, float $lng, array $polygon): bool
{
    $n = count($polygon);
    if ($n < 3) {
        return true;
    }

    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $yi = (float)$polygon[$i]['lat'];
        $xi = (float)$polygon[$i]['lng'];
        $yj = (float)$polygon[$j]['lat'];
        $xj = (float)$polygon[$j]['lng'];

        $intersect = (($yi > $lat) !== ($yj > $lat))
            && ($lng < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi));
        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}

try {
    $input = getInputParams();
    $mode = trim((string)($input['mode'] ?? 'point'));
    $body = trim((string)($input['body'] ?? 'Mars'));
    $targetId = trim((string)($input['target_id'] ?? ''));
    $lang = trim((string)($input['lang'] ?? 'tr')) === 'en' ? 'en' : 'tr';
    if ($body !== 'Moon' && $body !== 'Mars') {
        $body = 'Mars';
    }
    $refProfile = resolvePlanetaryReferenceProfile($body, $targetId, $lang);
    $refMetrics = $refProfile['metrics'];

    if ($mode === 'point') {
        $lat = isset($input['lat']) ? (float)$input['lat'] : null;
        $lng = isset($input['lng']) ? (float)$input['lng'] : null;

        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Geçerli lat (-90..90) ve lng (-180..180) parametreleri gereklidir.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $lat = round($lat, 4);
        $lng = round($lng, 4);

        $coords = [
            ['lat' => $lat, 'lng' => $lng],
            ['lat' => CONTROL_POINTS[0]['lat'], 'lng' => CONTROL_POINTS[0]['lng']],
            ['lat' => CONTROL_POINTS[1]['lat'], 'lng' => CONTROL_POINTS[1]['lng']],
        ];

        $fetched = fetchNasaPowerBatch($coords);
        if ($fetched[0] === null) {
            http_response_code(502);
            echo json_encode([
                'status'  => 'error',
                'message' => 'NASA POWER API verisi alınamadı. Lütfen farklı bir koordinat deneyin.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $candidates = [
            [
                'id'                => sprintf('nasa_pt_%.4f_%.4f', $lat, $lng),
                'name'              => $lang === 'en'
                    ? sprintf('Selected Point (%.2f°, %.2f°)', $lat, $lng)
                    : sprintf('Seçilen Nokta (%.2f°, %.2f°)', $lat, $lng),
                'country_or_region' => sprintf('NASA POWER Live • %.4f°, %.4f°', $lat, $lng),
                'mineral_type'      => $lang === 'en' ? 'Satellite Climate & Radiation Profile' : 'Uydu İklim & Radyasyon Profili',
                'lat'               => $lat,
                'lng'               => $lng,
                'metrics'           => $fetched[0],
                'is_target'         => true,
                'is_nasa_power'     => true,
            ],
        ];

        foreach (CONTROL_POINTS as $idx => $cp) {
            $m = $fetched[$idx + 1];
            if ($m !== null) {
                $candidates[] = [
                    'id'        => 'ctrl_' . $idx,
                    'name'      => $cp['label'],
                    'lat'       => $cp['lat'],
                    'lng'       => $cp['lng'],
                    'metrics'   => $m,
                    'is_target' => false,
                ];
            }
        }

        $scored = calculateHybridScores($refMetrics, $candidates, $lang);
        $selectedPoint = null;
        foreach ($scored as $item) {
            if (!empty($item['is_target'])) {
                $selectedPoint = $item;
                break;
            }
        }

        // Seçilen noktayı SQLite earth_sites tablosuna da dinamik saha olarak kaydet
        if ($selectedPoint !== null) {
            $resolvedEarth = astroResolveEarthPoint($lat, $lng, $lang, $fetched[0]);
            $selectedPoint['mineral_type'] = $resolvedEarth['mineral_type'];
            $selectedPoint['mineral_type_en'] = $resolvedEarth['mineral_type_en'];
            $selectedPoint['country_or_region'] = $resolvedEarth['country_or_region'];
            $selectedPoint['country_or_region_en'] = $resolvedEarth['country_or_region_en'];
            $selectedPoint['suitable_missions'] = $resolvedEarth['suitable_missions'];
            $selectedPoint['metrics'] = array_merge($resolvedEarth['metrics'], $fetched[0]);
        }

        echo json_encode([
            'status'    => 'ok',
            'mode'      => 'point',
            'body'      => $body,
            'db_stats'  => astroDbStats(),
            'reference' => $refProfile,
            'point'     => $selectedPoint,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($mode === 'grid') {
        $minLat = (float)($input['min_lat'] ?? 36.5);
        $maxLat = (float)($input['max_lat'] ?? 41.5);
        $minLon = (float)($input['min_lon'] ?? 27.0);
        $maxLon = (float)($input['max_lon'] ?? 43.0);
        $gridSize = max(2, min(6, (int)($input['grid_size'] ?? 4)));

        if ($minLat >= $maxLat || $minLon >= $maxLon) {
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Minimum enlem/boylam değerleri maksimum değerlerden küçük olmalıdır.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $lats = linspace($minLat, $maxLat, $gridSize);
        $lons = linspace($minLon, $maxLon, $gridSize);

        $polygon = [];
        if (!empty($input['polygon']) && is_string($input['polygon'])) {
            $decodedPoly = json_decode($input['polygon'], true);
            if (is_array($decodedPoly) && count($decodedPoly) >= 3) {
                $polygon = $decodedPoly;
            }
        }

        if ($polygon !== []) {
            $denseSize = min(7, $gridSize + 2);
            $lats = linspace($minLat, $maxLat, $denseSize);
            $lons = linspace($minLon, $maxLon, $denseSize);
        }

        $coords = [];
        $gridMeta = [];
        foreach ($lats as $la) {
            foreach ($lons as $lo) {
                if ($polygon !== [] && !isPointInPolygon($la, $lo, $polygon)) {
                    continue;
                }
                $coords[] = ['lat' => $la, 'lng' => $lo];
                $gridMeta[] = ['lat' => $la, 'lng' => $lo];
            }
        }

        $fetched = fetchNasaPowerBatch($coords);

        $candidates = [];
        foreach ($gridMeta as $idx => $meta) {
            $metrics = $fetched[$idx] ?? null;
            if ($metrics === null) {
                continue;
            }
            $synthMeta = astroEstimateEarthMetricsFromCoords($meta['lat'], $meta['lng'], []);
            $regionTr = astroGuessEarthRegionName($meta['lat'], $meta['lng'], 'tr');
            $regionEn = astroGuessEarthRegionName($meta['lat'], $meta['lng'], 'en');
            $candidates[] = [
                'id'                   => sprintf('grid_%.4f_%.4f', $meta['lat'], $meta['lng']),
                'name'                 => sprintf('Grid (%.2f°, %.2f°)', $meta['lat'], $meta['lng']),
                'country_or_region'    => $regionTr,
                'country_or_region_en' => $regionEn,
                'mineral_type'         => $synthMeta['mineral_tr'],
                'mineral_type_en'      => $synthMeta['mineral_en'],
                'suitable_missions'    => $synthMeta['missions'],
                'lat'                  => $meta['lat'],
                'lng'                  => $meta['lng'],
                'metrics'              => array_merge($synthMeta['metrics'], $metrics),
                'is_nasa_power'        => true,
            ];
        }

        if ($candidates === []) {
            http_response_code(502);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Seçilen bölgede geçerli NASA POWER verisi bulunamadı.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $scored = calculateHybridScores($refMetrics, $candidates, $lang);
        usort(
            $scored,
            static fn(array $a, array $b): int => $b['similarity_score'] <=> $a['similarity_score']
        );

        foreach ($scored as $rankIdx => &$item) {
            $item['rank'] = $rankIdx + 1;
            $item['name'] = $lang === 'en'
                ? sprintf('#%d Candidate (%.2f°, %.2f°)', $rankIdx + 1, $item['lat'], $item['lng'])
                : sprintf('#%d Aday (%.2f°, %.2f°)', $rankIdx + 1, $item['lat'], $item['lng']);
        }
        unset($item);

        echo json_encode([
            'status'    => 'ok',
            'mode'      => 'grid',
            'body'      => $body,
            'grid_size' => $gridSize,
            'reference' => $refProfile,
            'count'     => count($scored),
            'results'   => $scored,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Geçersiz mode parametresi. (point veya grid olmalıdır)',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
