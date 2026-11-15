<?php
declare(strict_types=1);

/**
 * Bilimsel Analog Hesaplama Yardımcıları
 *
 * İçerik:
 * 1. Haversine mesafesi & En yakın analog mağara/lav tüpü (data/caves.json)
 * 2. Novelty (Yeni Keşif vs. Katalog Sahası) tespiti (data/known_analogs.json)
 * 3. Güneş geometrisi ile düşük Güneş açısı (<10°) gündüz payı (low_sun_share)
 * 4. TRL 5-6 Donanım Test Hazırlık Matrisi (4 Kategori)
 * 5. Dünya Zarfı Hedef Kırpma (Earth-Envelope Clamping) & Ağırlıklı Geometrik Ortalama
 */

const ASTRO_TRL_URL = 'https://www.nasa.gov/directorates/somd/space-communications-navigation-program/technology-readiness-levels/';
const ASTRO_NEAR_FRACTION = 0.10;
const ASTRO_LOW_SUN_DEG = 10.0;
const ASTRO_OBLIQUITY_DEG = 23.44;
const ASTRO_POLAR_CIRCLE_DEG = 66.57;

/**
 * İki koordinat arasındaki Haversine mesafesini (km) hesaplar.
 */
function astroHaversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2.0) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2.0) ** 2);
    return 2.0 * $r * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

/**
 * Bir değeri [min, max] aralığına kırpar.
 */
function astroClip(float $val, float $min, float $max): float
{
    return max($min, min($max, $val));
}

/**
 * Verilen enlem (lat) için yıl boyunca gündüz saatlerinin yüzde kaçında Güneş'in
 * ufuktan 0° ile 10° arasında (alçak Güneş açısı / uzun gölge rejimi) kaldığını hesaplar.
 */
function astroLowSunShare(float $lat): float
{
    static $cache = [];
    $key = sprintf('%.2f', round($lat, 2));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $phi = deg2rad((float)$key);
    $sinPhi = sin($phi);
    $cosPhi = cos($phi);

    $daylightCount = 0;
    $lowSunCount = 0;

    for ($day = 0; $day < 365; $day += 5) {
        $decl = deg2rad(-ASTRO_OBLIQUITY_DEG * cos(2.0 * M_PI * ($day + 10.0) / 365.0));
        $sinDecl = sin($decl);
        $cosDecl = cos($decl);

        for ($h = 0; $h < 180; $h++) {
            $hourAngle = deg2rad(($h * 8.0 / 60.0 - 12.0) * 15.0);
            $sinEl = $sinPhi * $sinDecl + $cosPhi * $cosDecl * cos($hourAngle);
            $sinEl = max(-1.0, min(1.0, $sinEl));
            $elDeg = rad2deg(asin($sinEl));

            if ($elDeg > 0.0) {
                $daylightCount++;
                if ($elDeg < ASTRO_LOW_SUN_DEG) {
                    $lowSunCount++;
                }
            }
        }
    }

    $share = $daylightCount > 0 ? ($lowSunCount / $daylightCount) : 0.0;
    $cache[$key] = round($share, 4);
    return $cache[$key];
}

/**
 * data/caves.json dosyasını belleğe yükler.
 *
 * @return array<int, array<string, mixed>>
 */
function astroLoadCaves(): array
{
    static $caves = null;
    if ($caves !== null) {
        return $caves;
    }
    $path = __DIR__ . '/../data/caves.json';
    if (!is_file($path)) {
        $caves = [];
        return $caves;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    $caves = is_array($decoded['sites'] ?? null) ? $decoded['sites'] : [];
    return $caves;
}

/**
 * Verilen koordinata en yakın analog lav tüpü veya mağarayı bulur.
 *
 * @return array<string, mixed>|null
 */
function astroFindNearestCave(float $lat, float $lon, string $lang = 'tr'): ?array
{
    $caves = astroLoadCaves();
    if ($caves === []) {
        return null;
    }

    $best = null;
    $bestDist = INF;

    foreach ($caves as $cave) {
        $cLat = (float)($cave['lat'] ?? 0.0);
        $cLon = (float)($cave['lon'] ?? 0.0);
        $d = astroHaversineKm($lat, $lon, $cLat, $cLon);
        if ($d < $bestDist) {
            $bestDist = $d;
            $best = $cave;
        }
    }

    if ($best === null) {
        return null;
    }

    $isEn = ($lang === 'en');
    return [
        'id'          => $best['id'] ?? 'cave',
        'name'        => $isEn ? ($best['name_en'] ?? $best['name']) : ($best['name'] ?? ''),
        'name_tr'     => $best['name'] ?? '',
        'name_en'     => $best['name_en'] ?? ($best['name'] ?? ''),
        'country'     => $isEn ? ($best['country_en'] ?? $best['country']) : ($best['country'] ?? ''),
        'country_tr'  => $best['country'] ?? '',
        'country_en'  => $best['country_en'] ?? ($best['country'] ?? ''),
        'lat'         => (float)$best['lat'],
        'lon'         => (float)$best['lon'],
        'kind'        => $best['kind'] ?? 'lava tube',
        'note'        => $isEn ? ($best['note_en'] ?? $best['note']) : ($best['note'] ?? ''),
        'url'         => $best['url'] ?? '',
        'source_url'  => $best['url'] ?? '',
        'distance_km' => round($bestDist, 1),
    ];
}

/**
 * data/known_analogs.json dosyasını belleğe yükler.
 *
 * @return array<string, mixed>
 */
function astroLoadKnownAnalogs(): array
{
    static $known = null;
    if ($known !== null) {
        return $known;
    }
    $path = __DIR__ . '/../data/known_analogs.json';
    if (!is_file($path)) {
        $known = ['sites' => [], 'negatives' => []];
        return $known;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    $known = is_array($decoded) ? $decoded : ['sites' => [], 'negatives' => []];
    return $known;
}

/**
 * Koordinatın bilinen katalog analog sahalarına olan uzaklığını ölçerek Novelty durumunu belirler.
 * - <= 85 km: 'known' (Katalog Sahası)
 * - <= 260 km: 'near_known' (Bilinen Saha Yakını)
 * - > 260 km: 'new' (Yeni Keşif Adayı / Novel Candidate)
 *
 * @return array<string, mixed>
 */
function astroComputeNovelty(float $lat, float $lon, string $lang = 'tr'): array
{
    $knownData = astroLoadKnownAnalogs();
    $sites = $knownData['sites'] ?? [];

    $bestSite = null;
    $bestDist = INF;

    foreach ($sites as $s) {
        $sLat = (float)($s['lat'] ?? 0.0);
        $sLon = (float)($s['lon'] ?? 0.0);
        $d = astroHaversineKm($lat, $lon, $sLat, $sLon);
        if ($d < $bestDist) {
            $bestDist = $d;
            $bestSite = $s;
        }
    }

    $distKm = is_finite($bestDist) ? round($bestDist, 1) : 9999.0;
    $nearestName = (string)($bestSite['name'] ?? 'Unknown');
    $nearestUrl = (string)($bestSite['source_url'] ?? '');

    if ($distKm <= 85.0) {
        $status = 'known';
        $badgeTr = '🏛️ Katalog Sahası';
        $badgeEn = '🏛️ Catalogued Site';
    } elseif ($distKm <= 260.0) {
        $status = 'near_known';
        $badgeTr = '📍 Bilinen Saha Yakını';
        $badgeEn = '📍 Near Catalog Site';
    } else {
        $status = 'new';
        $badgeTr = '🌟 Yeni Keşif Adayı';
        $badgeEn = '🌟 Novel Candidate';
    }

    return [
        'status'          => $status,
        'badge'           => $lang === 'en' ? $badgeEn : $badgeTr,
        'badge_tr'        => $badgeTr,
        'badge_en'        => $badgeEn,
        'distance_km'     => $distKm,
        'nearest_known'   => $nearestName,
        'nearest_name'    => $nearestName,
        'nearest_name_en' => $nearestName,
        'source_url'      => $nearestUrl,
    ];
}

/**
 * Bir gereksinim için pass / near / fail durumunu hesaplar.
 */
function astroRequirementStatus(?float $value, string $comparison, float $threshold, float $span): string
{
    if ($value === null || !is_finite($value)) {
        return 'no_data';
    }
    $gap = ($comparison === '>=') ? ($threshold - $value) : ($value - $threshold);
    if ($gap <= 0.0) {
        return 'pass';
    }
    return ($gap <= ASTRO_NEAR_FRACTION * $span) ? 'near' : 'fail';
}

/**
 * Bir kategorideki gereksinim durumlarından genel kararı (ready / partial / not_suited) üretir.
 *
 * @param array<int, string> $statuses
 */
function astroCategoryVerdict(array $statuses): string
{
    $allPass = true;
    $good = 0.0;
    $count = count($statuses);
    if ($count === 0) {
        return 'not_suited';
    }

    foreach ($statuses as $s) {
        if ($s !== 'pass') {
            $allPass = false;
        }
        if ($s === 'pass') {
            $good += 1.0;
        } elseif ($s === 'near') {
            $good += 0.5;
        }
    }

    if ($allPass) {
        return 'ready';
    }
    return ($good >= ($count / 2.0)) ? 'partial' : 'not_suited';
}

/**
 * Seçilen bir Dünya sahası (Curated veya NASA POWER Point/Grid) için
 * TRL 5-6 Donanım Test Hazırlık Matrisini (4 Kategori) hesaplar.
 *
 * @param array<string, mixed> $site
 * @return array<string, mixed>
 */
function astroAssessHardwareReadiness(array $site, string $lang = 'tr'): array
{
    $isEn = ($lang === 'en');
    $lat = (float)($site['lat'] ?? 0.0);
    $absLat = round(abs($lat), 2);
    $lowSunSharePct = round(astroLowSunShare($lat) * 100.0, 1);

    $m = $site['metrics'] ?? [];
    $isNasaPower = !empty($site['is_nasa_power']) || isset($m['T2M']);

    if ($isNasaPower) {
        $meanTempC = (float)($m['T2M'] ?? 15.0);
        $diurnalRangeK = (float)($m['T2M_RANGE'] ?? 12.0);
        $precipMmYr = round(((float)($m['PRECTOTCORR'] ?? 0.5)) * 365.0, 1);
        $humidityPct = (float)($m['RH2M'] ?? 40.0);
        $regolithScore = round(astroClip(100.0 - $humidityPct * 0.85, 10.0, 95.0), 1);
        $slopeDeg = 6.0;
    } else {
        $meanTempC = (float)($m['avg_temp_c'] ?? 12.0);
        $slopeDeg = (float)($m['slope_deg'] ?? 6.0);
        $humidityPct = (float)($m['humidity_pct'] ?? 35.0);
        $regolithScore = (float)($m['regolith_similarity'] ?? 65.0);
        $solarPct = (float)($m['solar_exposure_pct'] ?? 70.0);
        $precipMmYr = round(max(5.0, ($humidityPct / 100.0) * 650.0 * (1.0 - $solarPct / 150.0)), 1);
        $diurnalRangeK = round(max(6.0, 8.0 + ($solarPct / 100.0) * 14.0 - ($humidityPct / 100.0) * 6.0), 1);
    }

    $shareUnder15Pct = round(astroClip(100.0 - max(0.0, $slopeDeg - 3.0) * 3.2, 25.0, 99.0), 1);

    $verdictLabelsTr = [
        'ready'      => 'TRL 5–6 Testine Hazır',
        'partial'    => 'Kısmi Uygunluk',
        'not_suited' => 'Uygun Değil',
    ];
    $verdictLabelsEn = [
        'ready'      => 'Ready for TRL 5–6',
        'partial'    => 'Partial Fit',
        'not_suited' => 'Not Suited',
    ];
    $verdictLabels = $isEn ? $verdictLabelsEn : $verdictLabelsTr;

    $categoriesDef = [
        [
            'id'         => 'ice_drill',
            'icon'       => 'bi-snow2',
            'name'       => $isEn ? 'Ice-Mining Drill' : 'Buz Sondaj Matkabı',
            'title_tr'   => 'Buz Sondaj Matkabı',
            'title_en'   => 'Ice-Mining Drill',
            'system'     => $isEn ? 'VIPER-class 1 m regolith ice drill' : 'VIPER Sınıfı 1 m Regolit & Buz Sondajı',
            'system_url' => 'https://science.nasa.gov/mission/viper/',
            'reqs'       => [
                [
                    'metric'     => 'mean_annual_temperature',
                    'label'      => $isEn ? 'Mean Temp' : 'Ort. Sıcaklık',
                    'label_tr'   => 'Ort. Sıcaklık',
                    'label_en'   => 'Mean Temp',
                    'unit'       => '°C',
                    'value'      => $meanTempC,
                    'comparison' => '<=',
                    'threshold'  => -5.0,
                    'span'       => 85.0,
                    'why'        => $isEn
                        ? 'Continuous permafrost (< -5°C) keeps ground ice-cemented.'
                        : 'Sürekli permafrost (≤ -5°C) zeminin buz çimentolu kalmasını sağlar.',
                    'source_url' => 'https://en.wikipedia.org/wiki/Permafrost',
                ],
                [
                    'metric'     => 'regolith_barren',
                    'label'      => $isEn ? 'Barren Regolith' : 'Çorak Regolit Uyumu',
                    'label_tr'   => 'Çorak Regolit Uyumu',
                    'label_en'   => 'Barren Regolith',
                    'unit'       => '/100',
                    'value'      => $regolithScore,
                    'comparison' => '>=',
                    'threshold'  => 55.0,
                    'span'       => 100.0,
                    'why'        => $isEn
                        ? 'Bare rock, sand or permafrost soil with minimal vegetation.'
                        : 'Bitki örtüsüz çıplak kaya, kum veya permafrost zemin.',
                    'source_url' => 'https://en.wikipedia.org/wiki/Normalized_difference_vegetation_index',
                ],
                [
                    'metric'     => 'precipitation',
                    'label'      => $isEn ? 'Annual Precip.' : 'Yıllık Yağış',
                    'label_tr'   => 'Yıllık Yağış',
                    'label_en'   => 'Annual Precip.',
                    'unit'       => ' mm/yr',
                    'value'      => $precipMmYr,
                    'comparison' => '<=',
                    'threshold'  => 250.0,
                    'span'       => 1500.0,
                    'why'        => $isEn
                        ? 'Polar/arid desert dryness (< 250 mm/yr).'
                        : 'Çöl/kutup kuraklığı (≤ 250 mm/yıl).',
                    'source_url' => 'https://en.wikipedia.org/wiki/Desert',
                ],
            ],
        ],
        [
            'id'         => 'low_sun_navigation',
            'icon'       => 'bi-brightness-alt-low',
            'name'       => $isEn ? 'Low-Sun Navigation' : 'Alçak Güneş Navigasyonu',
            'title_tr'   => 'Alçak Güneş Navigasyonu',
            'title_en'   => 'Low-Sun Navigation',
            'system'     => $isEn ? 'Autonomous hazard detection at grazing sun angles' : 'Ufuk Çizgisi Güneş Açısında Otonom Gölge Navigasyonu',
            'system_url' => 'https://en.wikipedia.org/wiki/Solar_zenith_angle',
            'reqs'       => [
                [
                    'metric'     => 'abs_latitude',
                    'label'      => $isEn ? 'Polar Latitude' : 'Ekvator Uzaklığı',
                    'label_tr'   => 'Ekvator Uzaklığı',
                    'label_en'   => 'Polar Latitude',
                    'unit'       => '°',
                    'value'      => $absLat,
                    'comparison' => '>=',
                    'threshold'  => ASTRO_POLAR_CIRCLE_DEG,
                    'span'       => 90.0,
                    'why'        => $isEn
                        ? 'Inside the polar circle (≥ 66.57°) the Sun stays near the horizon for long periods.'
                        : 'Kutup dairesi içinde (≥ 66.57°) Güneş uzun süre ufka yakın seyreder.',
                    'source_url' => 'https://en.wikipedia.org/wiki/Arctic_Circle',
                ],
                [
                    'metric'     => 'low_sun_share',
                    'label'      => $isEn ? 'Sun < 10° Share' : 'Güneş < 10° Payı',
                    'label_tr'   => 'Güneş < 10° Payı',
                    'label_en'   => 'Sun < 10° Share',
                    'unit'       => '%',
                    'value'      => $lowSunSharePct,
                    'comparison' => '>=',
                    'threshold'  => 30.0,
                    'span'       => 100.0,
                    'why'        => $isEn
                        ? 'At least 30% of annual daylight has the Sun below 10°, casting long lunar-like shadows.'
                        : 'Yıllık gündüz süresinin en az %30\'unda Güneş 10° altındadır (uzun siyah gölgeler).',
                    'source_url' => 'https://en.wikipedia.org/wiki/Solar_zenith_angle',
                ],
                [
                    'metric'     => 'slope_deg',
                    'label'      => $isEn ? 'Terrain Relief' : 'Yüzey Eğimi',
                    'label_tr'   => 'Yüzey Eğimi',
                    'label_en'   => 'Terrain Relief',
                    'unit'       => '°',
                    'value'      => $slopeDeg,
                    'comparison' => '>=',
                    'threshold'  => 6.0,
                    'span'       => 25.0,
                    'why'        => $isEn
                        ? 'Sufficient topographic relief (≥ 6°) to cast grazing shadows for vision cameras.'
                        : 'Kamera ve LiDAR sistemlerini zorlayacak gölgeler için yeterli engebe (≥ 6°).',
                    'source_url' => 'https://registry.opendata.aws/terrain-tiles/',
                ],
            ],
        ],
        [
            'id'         => 'eva_suit',
            'icon'       => 'bi-person-walking',
            'name'       => $isEn ? 'Spacesuit EVA Mobility' : 'Uzay Giysisi (EVA) Mobilite',
            'title_tr'   => 'Uzay Giysisi (EVA) Mobilite',
            'title_en'   => 'Spacesuit EVA Mobility',
            'system'     => $isEn ? 'Artemis xEMU / AxEMU Surface Mobility Program' : 'Artemis xEMU / AxEMU Yüzey Yürüyüş & Jeoloji Testi',
            'system_url' => 'https://www.nasa.gov/suitup/',
            'reqs'       => [
                [
                    'metric'     => 'regolith_similarity',
                    'label'      => $isEn ? 'Basalt/Regolith' : 'Bazalt/Regolit Uyumu',
                    'label_tr'   => 'Bazalt/Regolit Uyumu',
                    'label_en'   => 'Basalt/Regolith',
                    'unit'       => '/100',
                    'value'      => $regolithScore,
                    'comparison' => '>=',
                    'threshold'  => 65.0,
                    'span'       => 100.0,
                    'why'        => $isEn
                        ? 'Volcanic basalt or impact breccia underfoot similar to lunar maria.'
                        : 'Ay denizlerine (maria) benzer bazaltik lav veya çarpma breşi zemin.',
                    'source_url' => 'https://doi.org/10.1594/PANGAEA.788537',
                ],
                [
                    'metric'     => 'slope_deg',
                    'label'      => $isEn ? 'Rugged Slope' : 'Yamaç Eğimi',
                    'label_tr'   => 'Yamaç Eğimi',
                    'label_en'   => 'Rugged Slope',
                    'unit'       => '°',
                    'value'      => $slopeDeg,
                    'comparison' => '>=',
                    'threshold'  => 5.0,
                    'span'       => 25.0,
                    'why'        => $isEn
                        ? 'Uneven ground (≥ 5°) for astronaut balance, boot traction and tool tests.'
                        : 'Astronot denge, bot tutuşu ve jeolojik örnekleme için engebeli zemin (≥ 5°).',
                    'source_url' => 'https://registry.opendata.aws/terrain-tiles/',
                ],
                [
                    'metric'     => 'lst_diurnal_range',
                    'label'      => $isEn ? 'Diurnal Swing' : 'Günlük Sıcaklık Farkı',
                    'label_tr'   => 'Günlük Sıcaklık Farkı',
                    'label_en'   => 'Diurnal Swing',
                    'unit'       => ' K',
                    'value'      => $diurnalRangeK,
                    'comparison' => '>=',
                    'threshold'  => 14.0,
                    'span'       => 40.0,
                    'why'        => $isEn
                        ? 'Day-night thermal swing (≥ 14 K) cycles suit outer fabrics and thermal control.'
                        : 'Gece-gündüz sıcaklık farkı (≥ 14 K) giysi dış katmanlarını ve ısıl kontrolü sınar.',
                    'source_url' => 'https://doi.org/10.5281/zenodo.6458406',
                ],
            ],
        ],
        [
            'id'         => 'rover',
            'icon'       => 'bi-truck-front',
            'name'       => $isEn ? 'Rover Mobility & Trafficability' : 'Rover Sürüş & Tehlike Kaçınma',
            'title_tr'   => 'Rover Sürüş & Tehlike Kaçınma',
            'title_en'   => 'Rover Mobility & Trafficability',
            'system'     => $isEn ? 'Lunar Terrain Vehicle (LTV) & Mars Pressurized Rover' : 'Lunar Terrain Vehicle (LTV) & Mars Gezgin Araçları',
            'system_url' => 'https://www.nasa.gov/suitup/',
            'reqs'       => [
                [
                    'metric'     => 'share_under_15',
                    'label'      => $isEn ? 'Ground < 15°' : 'Eğim < 15° Alan',
                    'label_tr'   => 'Eğim < 15° Alan',
                    'label_en'   => 'Ground < 15°',
                    'unit'       => '%',
                    'value'      => $shareUnder15Pct,
                    'comparison' => '>=',
                    'threshold'  => 75.0,
                    'span'       => 100.0,
                    'why'        => $isEn
                        ? 'At least 75% of terrain below the 15° rover rollover/slip limit.'
                        : 'Arazinin en az %75\'inin 15° rover devrilme/patinaj limitinin altında olması.',
                    'source_url' => 'https://registry.opendata.aws/terrain-tiles/',
                ],
                [
                    'metric'     => 'humidity_pct',
                    'label'      => $isEn ? 'Dry Traction' : 'Kuru Zemin Nemi',
                    'label_tr'   => 'Kuru Zemin Nemi',
                    'label_en'   => 'Dry Traction',
                    'unit'       => '%',
                    'value'      => $humidityPct,
                    'comparison' => '<=',
                    'threshold'  => 55.0,
                    'span'       => 100.0,
                    'why'        => $isEn
                        ? 'Dry, firm regolith without wet mud sticking to rover wheels.'
                        : 'Tekerleklere çamur yapışmasını önleyen kuru ve gevşek regolit zemin.',
                    'source_url' => 'https://en.wikipedia.org/wiki/Desert',
                ],
                [
                    'metric'     => 'regolith_similarity',
                    'label'      => $isEn ? 'Soil Simulant' : 'Toprak Simülant Uyumu',
                    'label_tr'   => 'Toprak Simülant Uyumu',
                    'label_en'   => 'Soil Simulant',
                    'unit'       => '/100',
                    'value'      => $regolithScore,
                    'comparison' => '>=',
                    'threshold'  => 50.0,
                    'span'       => 100.0,
                    'why'        => $isEn
                        ? 'Unconsolidated sand/volcanic tephra for wheel sinkage and slip testing.'
                        : 'Tekerlek batması ve patinaj testleri için kumul/volkanik tefra zemin.',
                    'source_url' => 'https://doi.org/10.1594/PANGAEA.788537',
                ],
            ],
        ],
    ];

    $categoriesOut = [];
    foreach ($categoriesDef as $cat) {
        $rows = [];
        $statuses = [];
        foreach ($cat['reqs'] as $r) {
            $st = astroRequirementStatus((float)$r['value'], $r['comparison'], (float)$r['threshold'], (float)$r['span']);
            $statuses[] = $st;
            $valRounded = round((float)$r['value'], 1);
            $thrRounded = round((float)$r['threshold'], 1);
            $rows[] = [
                'metric'     => $r['metric'],
                'label'      => $r['label'],
                'label_tr'   => $r['label_tr'],
                'label_en'   => $r['label_en'],
                'unit'       => $r['unit'],
                'value'      => $valRounded,
                'comparison' => $r['comparison'],
                'threshold'  => $thrRounded,
                'actual'     => $valRounded . $r['unit'] . ' (' . $r['comparison'] . ' ' . $thrRounded . $r['unit'] . ')',
                'status'     => $st,
                'why'        => $r['why'],
                'source_url' => $r['source_url'],
            ];
        }
        $verdict = astroCategoryVerdict($statuses);
        $categoriesOut[] = [
            'id'               => $cat['id'],
            'icon'             => $cat['icon'],
            'name'             => $cat['name'],
            'title_tr'         => $cat['title_tr'],
            'title_en'         => $cat['title_en'],
            'system'           => $cat['system'],
            'system_url'       => $cat['system_url'],
            'verdict'          => $verdict,
            'verdict_label'    => $verdictLabels[$verdict] ?? $verdict,
            'verdict_label_tr' => $verdictLabelsTr[$verdict] ?? $verdict,
            'verdict_label_en' => $verdictLabelsEn[$verdict] ?? $verdict,
            'requirements'     => $rows,
        ];
    }

    return [
        'trl_source_url' => ASTRO_TRL_URL,
        'low_sun_share'  => $lowSunSharePct,
        'categories'     => $categoriesOut,
    ];
}
