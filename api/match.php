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

/**
 * İstek parametrelerini GET veya POST (JSON / form) üzerinden okur.
 *
 * @return array{target_id: string, body: string, target_lat: ?float, target_lng: ?float, earth_lat: ?float, earth_lng: ?float, mission_type: string, lang: string}
 */
function getRequestParams(): array
{
    $input = $_REQUEST;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawBody = file_get_contents('php://input');
        if (is_string($rawBody) && $rawBody !== '') {
            $jsonBody = json_decode($rawBody, true);
            if (is_array($jsonBody)) {
                $input = array_merge($input, $jsonBody);
            }
        }
    }

    $targetId = trim((string)($input['target_id'] ?? ''));
    $body = trim((string)($input['body'] ?? 'Moon'));
    if ($body !== 'Mars' && $body !== 'Moon') {
        $body = 'Moon';
    }
    $targetLat = isset($input['target_lat']) && $input['target_lat'] !== '' ? (float)$input['target_lat'] : null;
    $targetLng = isset($input['target_lng']) && $input['target_lng'] !== '' ? (float)$input['target_lng'] : null;
    $earthLat = isset($input['earth_lat']) && $input['earth_lat'] !== '' ? (float)$input['earth_lat'] : null;
    $earthLng = isset($input['earth_lng']) && $input['earth_lng'] !== '' ? (float)$input['earth_lng'] : null;
    $missionType = trim((string)($input['mission_type'] ?? 'all'));
    $lang = trim((string)($input['lang'] ?? 'tr')) === 'en' ? 'en' : 'tr';
    if ($missionType === '') {
        $missionType = 'all';
    }

    return [
        'target_id'    => $targetId,
        'body'         => $body,
        'target_lat'   => $targetLat,
        'target_lng'   => $targetLng,
        'earth_lat'    => $earthLat,
        'earth_lng'    => $earthLng,
        'mission_type' => $missionType,
        'lang'         => $lang,
    ];
}

/**
 * Seçilen Ay/Mars üssü veya yüzey hedefinin jeolojik rejimi (validation_tag, mineral_type, suitable_missions)
 * ile Dünya analog sahasının jeolojik sınıfı (tags, mineral_type, suitable_missions) arasındaki
 * hedefe özgü regolit/yapısal uyumu (0-100) ve rejim çarpanını hesaplar.
 *
 * Neden gerekli?
 * Sabit bir `regolith_similarity` skoru kullanıldığında Atacama Çölü (91) ve Antarktika (95),
 * Marius Hills Lav Tüpü'nden Jezero Deltası'na ve Shackleton Sırtı'na kadar her hedefte 1. veya 2. çıkıyordu.
 * Oysa NASA Space Apps "Identify Earth Locations That Analog the Permanent Moon Base Locations and Mars"
 * kapsamında her kalıcı üs mimarisinin Dünya'daki gerçek jeolojik/operasyonel karşılığı farklıdır:
 * - Kalıcı Ay Kutup Sırtları (lunar_polar_base) -> Anortozitik çarpma kraterleri, kutup/yüksek irtifa bazalt & regolit test sahaları
 * - Kriyojenik PSR Soğuk Tuzaklar (cold_polar) -> Antarktika/Arktik kuru permafrost & buz sondaj sahaları
 * - Yeraltı Lav Tüpü Tavan Çökmeleri (lava_tube) -> Bazaltik lav tüpü mağaraları & radyasyon sığınakları
 * - Ay Mare Bazalt & Krater Sahaları (lunar_surface / impact_crater) -> Bazaltik volkanik alanlar & meteor kraterleri
 * - Mars Paleogöl Karbonat/Kil Deltaları (mars_delta) -> Hidromanyezit/karbonat gölleri (Salda), smektit kil & stromatolit havzaları
 * - Mars Buzul Permafrost Ovaları (permafrost) -> Soğuk-kurak poligonal permafrost havzaları
 * - Mars Hiper-Kurak Sülfat/Demir-Oksit Havzaları (hyper_arid / mars_catalog) -> Hiper-kurak sülfat/silika çölleri (Atacama, Qaidam vb.)
 *
 * @param array<string, mixed> $target
 * @param array<string, mixed> $earth
 * @return array{effective_regolith: float, regime_affinity: float, mission_overlap: float, regime_label_tr: string, regime_label_en: string}
 */
function computeTargetSpecificRegolithAndRegimeMatch(array $target, array $earth): array
{
    $body = (string)($target['body'] ?? 'Moon');
    $vTag = (string)($target['validation_tag'] ?? ($body === 'Moon' ? 'lunar_surface' : 'mars_catalog'));
    $earthId = (string)($earth['id'] ?? '');
    $earthTags = is_array($earth['tags'] ?? null) ? $earth['tags'] : [];
    $baseReg = (float)($earth['metrics']['regolith_similarity'] ?? 70.0);
    $earthTemp = (float)($earth['metrics']['avg_temp_c'] ?? 15.0);
    $earthElev = (float)($earth['elevation_m'] ?? 500.0);

    $earthMin = mb_strtolower((string)($earth['mineral_type'] ?? '') . ' ' . (string)($earth['mineral_type_en'] ?? ''), 'UTF-8');
    $targetMin = mb_strtolower((string)($target['mineral_type'] ?? '') . ' ' . (string)($target['mineral_type_en'] ?? ''), 'UTF-8');

    $hasTag = static fn(string $t): bool => in_array($t, $earthTags, true);

    // Görev örtüşmesi (Permanent Base operational alignment)
    $tMissions = is_array($target['suitable_missions'] ?? null) ? $target['suitable_missions'] : [];
    $eMissions = is_array($earth['suitable_missions'] ?? null) ? $earth['suitable_missions'] : [];
    if ($tMissions !== [] && $eMissions !== []) {
        $inter = count(array_intersect($tMissions, $eMissions));
        $missionOverlap = min(1.0, $inter / max(1, min(count($tMissions), 4)));
    } else {
        $missionOverlap = 0.65;
    }

    $effectiveReg = $baseReg;
    $regimeAffinity = 0.80;
    $labelTr = 'Gezegen Yüzey Analoğu';
    $labelEn = 'Planetary Surface Analog';

    if ($vTag === 'lava_tube') {
        $labelTr = 'Yeraltı Lav Tüpü & Radyasyon Sığınağı Analoğu';
        $labelEn = 'Subsurface Lava Tube & Radiation Shelter Analog';
        $isCaveSite = $hasTag('lava_tube')
            || in_array('cave_exploration', $eMissions, true)
            || in_array('radiation_shelter', $eMissions, true)
            || str_contains($earthMin, 'lava tube')
            || str_contains($earthMin, 'lav tüp')
            || str_contains($earthMin, 'skylight');

        if ($isCaveSite) {
            $effectiveReg = max(92.0, min(98.0, $baseReg + 18.0));
            $regimeAffinity = 1.00;
        } elseif ($hasTag('volcanic') || $hasTag('lunar_surface')) {
            $effectiveReg = min(68.0, $baseReg * 0.78);
            $regimeAffinity = 0.72;
        } else {
            $effectiveReg = min(38.0, $baseReg * 0.42);
            $regimeAffinity = 0.48;
        }
    } elseif ($vTag === 'lunar_polar_base') {
        $labelTr = 'Kalıcı Ay Kutup Üssü Analoğu';
        $labelEn = 'Permanent Lunar Polar Base Analog';

        $isAnorthositeOrImpact = str_contains($earthMin, 'anorthosit') || str_contains($earthMin, 'anortozit') || $hasTag('impact_crater') || $earthId === 'earth_devon_haughton';
        $isLunarTestbed = $hasTag('lunar_surface');
        $isColdOrHigh = $hasTag('cold_polar') || $hasTag('permafrost') || $earthTemp <= 10.0 || $earthElev >= 1400.0;

        if (in_array($earthId, ['earth_mistastin_crater', 'earth_devon_haughton', 'earth_sf_volcanic_field', 'earth_mauna_kea', 'earth_askja_iceland', 'earth_mcmurdo_dry_valleys', 'earth_university_valley'], true)) {
            $effectiveReg = 98.0;
            $regimeAffinity = ($earthId === 'earth_mistastin_crater' || $earthId === 'earth_devon_haughton') ? 1.03 : 1.00;
        } elseif ($isLunarTestbed && ($isAnorthositeOrImpact || $isColdOrHigh)) {
            $effectiveReg = max(91.0, min(97.0, $baseReg + 12.0));
            $regimeAffinity = 0.96;
        } elseif ($isAnorthositeOrImpact && $isColdOrHigh) {
            $effectiveReg = max(89.0, min(96.0, $baseReg + 20.0));
            $regimeAffinity = 0.95;
        } elseif ($isLunarTestbed) {
            $effectiveReg = max(84.0, min(92.0, $baseReg + 5.0));
            $regimeAffinity = 0.88;
        } elseif ($hasTag('cold_polar')) {
            $effectiveReg = max(78.0, min(88.0, $baseReg * 0.90));
            $regimeAffinity = 0.84;
        } else {
            $effectiveReg = min(48.0, $baseReg * 0.55);
            $regimeAffinity = 0.56;
        }
    } elseif ($vTag === 'cold_polar') {
        $labelTr = 'Kutup Buz Sondajı & Soğuk Tuzak Analoğu';
        $labelEn = 'Polar Ice Drilling & Cold-Trap Analog';

        $isCryo = $hasTag('cold_polar') || $hasTag('permafrost') || $earthTemp <= 0.0;
        $hasIceDrill = in_array('ice_prospecting', $eMissions, true) || in_array('drilling_test', $eMissions, true);

        if ($isCryo && $hasIceDrill) {
            $effectiveReg = max(92.0, min(98.0, $baseReg + 16.0));
            $regimeAffinity = 1.00;
        } elseif ($isCryo) {
            $effectiveReg = max(86.0, min(94.0, $baseReg + 10.0));
            $regimeAffinity = 0.92;
        } elseif ($earthTemp <= 8.0 && $earthElev >= 2500.0) {
            $effectiveReg = max(74.0, min(84.0, $baseReg));
            $regimeAffinity = 0.80;
        } else {
            $effectiveReg = min(36.0, $baseReg * 0.42);
            $regimeAffinity = 0.46;
        }
    } elseif ($vTag === 'mars_delta') {
        $labelTr = 'Mars Paleogöl & Biyo-İmza Analoğu';
        $labelEn = 'Martian Paleolake & Biosignature Analog';

        $isCarbonateClayDelta = $hasTag('mars_delta')
            || str_contains($earthMin, 'hydromagnesite')
            || str_contains($earthMin, 'hidromanyezit')
            || str_contains($earthMin, 'carbonate')
            || str_contains($earthMin, 'karbonat')
            || str_contains($earthMin, 'smectite')
            || str_contains($earthMin, 'smektit')
            || str_contains($earthMin, 'phyllosilicate')
            || str_contains($earthMin, 'fillosilikat')
            || str_contains($earthMin, 'stromatolit')
            || str_contains($earthMin, 'microbialit')
            || str_contains($earthMin, 'mikrobiyalit')
            || str_contains($earthMin, 'ophiolite')
            || str_contains($earthMin, 'ofiyolit')
            || str_contains($earthMin, 'serpentin')
            || in_array($earthId, ['earth_lake_salda', 'earth_pilbara_australia', 'earth_qaidam_basin_china', 'earth_mdrs_utah', 'earth_dhofar_oman', 'earth_makgadikgadi_botswana', 'earth_van_golu_microbialites', 'earth_mojave'], true);

        if ($earthId === 'earth_lake_salda' || $earthId === 'earth_pilbara_australia' || $earthId === 'earth_mdrs_utah') {
            $effectiveReg = 98.0;
            $regimeAffinity = ($earthId === 'earth_lake_salda') ? 1.03 : 1.00;
        } elseif ($hasTag('mars_delta') || in_array($earthId, ['earth_qaidam_basin_china', 'earth_dhofar_oman', 'earth_makgadikgadi_botswana', 'earth_van_golu_microbialites'], true)) {
            $effectiveReg = max(94.0, min(97.0, $baseReg + 22.0));
            $regimeAffinity = 0.98;
        } elseif ($isCarbonateClayDelta) {
            $effectiveReg = max(88.0, min(94.0, $baseReg + 18.0));
            $regimeAffinity = 0.92;
        } elseif ($hasTag('mars_catalog') || $hasTag('hyper_arid')) {
            $effectiveReg = min(74.0, $baseReg * 0.82);
            $regimeAffinity = 0.76;
        } else {
            $effectiveReg = min(50.0, $baseReg * 0.60);
            $regimeAffinity = 0.58;
        }
    } elseif ($vTag === 'permafrost') {
        $labelTr = 'Mars Yeraltı Buzu & ISRU Üs Analoğu';
        $labelEn = 'Martian Subsurface Ice & ISRU Base Analog';

        $isPermafrostSite = $hasTag('permafrost') || $hasTag('cold_polar')
            || str_contains($earthMin, 'permafrost')
            || in_array($earthId, ['earth_university_valley_antarctica', 'earth_mcmurdo_dry_valleys', 'earth_axel_heiberg', 'earth_devon_haughton', 'earth_ladakh_tso_kar', 'earth_qaidam_basin_china', 'earth_svalbard_bockfjord', 'earth_disko_island_greenland'], true);

        if ($isPermafrostSite) {
            $effectiveReg = max(91.0, min(98.0, $baseReg + 18.0));
            $regimeAffinity = 1.00;
        } elseif ($earthTemp <= 6.0 && $hasTag('hyper_arid')) {
            $effectiveReg = max(80.0, min(89.0, $baseReg));
            $regimeAffinity = 0.86;
        } else {
            $effectiveReg = min(46.0, $baseReg * 0.52);
            $regimeAffinity = 0.54;
        }
    } elseif ($body === 'Moon' && ($vTag === 'lunar_surface' || $vTag === 'impact_crater' || $vTag === 'volcanic')) {
        $labelTr = 'Ay Bazalt Yüzey & Krater Analoğu';
        $labelEn = 'Lunar Basalt Surface & Crater Analog';

        $isTargetCrater = ($vTag === 'impact_crater') || str_contains($targetMin, 'breccia') || str_contains($targetMin, 'breş') || str_contains($targetMin, 'anorthosit');
        $isEarthCrater = $hasTag('impact_crater') || str_contains($earthMin, 'impact') || str_contains($earthMin, 'çarpma') || str_contains($earthMin, 'breccia');
        $isEarthLunarVolcanic = $hasTag('lunar_surface') || ($hasTag('volcanic') && (str_contains($earthMin, 'basalt') || str_contains($earthMin, 'bazalt') || str_contains($earthMin, 'tephra') || str_contains($earthMin, 'scoria')));

        if ($isTargetCrater && $isEarthCrater) {
            $effectiveReg = max(92.0, min(98.0, $baseReg + 18.0));
            $regimeAffinity = 1.00;
        } elseif ($isEarthLunarVolcanic || $isEarthCrater) {
            $effectiveReg = max(89.0, min(96.0, $baseReg + 10.0));
            $regimeAffinity = 0.97;
        } elseif ($hasTag('volcanic')) {
            $effectiveReg = max(82.0, min(90.0, $baseReg + 5.0));
            $regimeAffinity = 0.88;
        } else {
            // Volkanik veya çarpma krateri olmayan düz tuz/sülfat çölleri Ay bazalt/krater hedefinde geri planda kalmalı
            $effectiveReg = min(52.0, $baseReg * 0.56);
            $regimeAffinity = 0.58;
        }
    } else {
        // Mars hyper_arid, mars_catalog, volcanic (Gale, Melas Chasma, Gusev, Olympus Mons, Tharsis vb.)
        $labelTr = 'Mars Kurak Havza & Volkanik Yüzey Analoğu';
        $labelEn = 'Martian Arid Basin & Volcanic Surface Analog';

        $isTargetVolcanic = ($vTag === 'volcanic') || str_contains($targetMin, 'basalt') || str_contains($targetMin, 'bazalt') || str_contains($targetMin, 'silica') || str_contains($targetMin, 'opaline');
        $isEarthMarsArid = $hasTag('hyper_arid') || $hasTag('mars_catalog')
            || str_contains($earthMin, 'sulfate') || str_contains($earthMin, 'sülfat')
            || str_contains($earthMin, 'jarosite') || str_contains($earthMin, 'jarosit')
            || str_contains($earthMin, 'hematite') || str_contains($earthMin, 'hematit')
            || str_contains($earthMin, 'perchlorate') || str_contains($earthMin, 'perklorat')
            || str_contains($earthMin, 'silica') || str_contains($earthMin, 'silika');

        if ($isTargetVolcanic && ($hasTag('volcanic') || str_contains($earthMin, 'silica') || str_contains($earthMin, 'geyser') || str_contains($earthMin, 'basalt'))) {
            $effectiveReg = max(91.0, min(97.0, $baseReg + 12.0));
            $regimeAffinity = 1.00;
        } elseif ($isEarthMarsArid) {
            $effectiveReg = max(88.0, min(96.0, $baseReg + 6.0));
            $regimeAffinity = 0.98;
        } elseif ($hasTag('volcanic') || $hasTag('mars_delta')) {
            $effectiveReg = max(78.0, min(88.0, $baseReg));
            $regimeAffinity = 0.86;
        } else {
            $effectiveReg = min(56.0, $baseReg * 0.65);
            $regimeAffinity = 0.64;
        }
    }

    return [
        'effective_regolith' => round($effectiveReg, 1),
        'regime_affinity'    => $regimeAffinity,
        'mission_overlap'    => $missionOverlap,
        'regime_label_tr'    => $labelTr,
        'regime_label_en'    => $labelEn,
    ];
}

/**
 * Hedef gök cismi ile Dünya analog sahası arasında:
 * 1) Hedefe Özgü Jeolojik Rejim & Kalıcı Üs Görev Uyumu (Regolith & Regime Affinity)
 * 2) Dünya Zarfı Hedef Kırpması (t'_k = clip(t_k, earth_min, earth_max))
 * 3) Ağırlıklı Geometrik Ortalama (prod s_k ^ (w_k / W)) + Veto Kuralı
 * 4) Ağırlıklı Öklid Benzerlik Skoru
 * hesaplar ve her iki skoru birleştirir.
 *
 * @param array<string, mixed> $target
 * @param array<string, mixed> $earth
 * @return array<string, mixed>
 */
function calculateAnalogMatch(array $target, array $earth, string $lang = 'tr'): array
{
    $regimeInfo = computeTargetSpecificRegolithAndRegimeMatch($target, $earth);

    $tMetrics = $target['metrics'];
    $eMetrics = $earth['metrics'];
    // Dünya sahasının regolit uyumunu seçilen Ay/Mars hedefinin jeolojik rejimine göre dinamik güncelle
    $eMetrics['regolith_similarity'] = $regimeInfo['effective_regolith'];

    $validationTag = (string)($target['validation_tag'] ?? '');

    // Her parametre için bilimsel normalize ölçeği, ağırlık ve Dünya zarfı (Earth Envelope)
    $config = [
        'regolith_similarity' => ['scale' => 100.0, 'weight' => 0.34, 'earth_min' => 0.0,   'earth_max' => 100.0],
        'humidity_pct'        => ['scale' => 100.0, 'weight' => 0.18, 'earth_min' => 2.0,   'earth_max' => 98.0],
        'slope_deg'           => ['scale' => 25.0,  'weight' => 0.20, 'earth_min' => 0.5,   'earth_max' => 25.0],
        'solar_exposure_pct'  => ['scale' => 100.0, 'weight' => 0.14, 'earth_min' => 10.0,  'earth_max' => 95.0],
        'avg_temp_c'          => ['scale' => 90.0,  'weight' => 0.14, 'earth_min' => -53.0, 'earth_max' => 32.0],
    ];

    // Hedef üs tipine göre parametre ağırlıklarını ve Dünya zarfını kalibre et
    if ($validationTag === 'cold_polar' || $validationTag === 'permafrost') {
        $config['regolith_similarity']['weight'] = 0.32;
        $config['avg_temp_c']['weight'] = 0.28;
        $config['humidity_pct']['weight'] = 0.12;
        $config['slope_deg']['weight'] = 0.16;
        $config['solar_exposure_pct']['weight'] = 0.12;
    } elseif ($validationTag === 'lunar_polar_base') {
        // Kalıcı Ay Kutup Üssü Sırtları (Shackleton-de Gerlache, Malapert, Nobile, Peary):
        // Yüksek aydınlanma, krater sırtı eğimi, anortozit/bazalt regolit ve soğuk/yüksek irtifa zarfı
        $config['regolith_similarity']['weight'] = 0.42;
        $config['slope_deg']['weight'] = 0.22;
        $config['solar_exposure_pct']['weight'] = 0.12;
        $config['solar_exposure_pct']['earth_max'] = 65.0; // Yüksek enlem/irtifa Dünya test sahaları (Mistastin, Devon, Askja, Mauna Kea) zarfı
        $config['avg_temp_c']['weight'] = 0.14;
        $config['avg_temp_c']['earth_min'] = -15.0;
        $config['humidity_pct']['weight'] = 0.10;
        $config['humidity_pct']['earth_min'] = 45.0;
    } elseif ($validationTag === 'lava_tube') {
        // Yeraltı lav tüpleri (Marius Hills, Mare Tranquillitatis, Pavonis Mons):
        // Mağara içi izotermal sıcaklık (-5..+18°C) ve tavan çökmesi (skylight) yapısal uyumu belirleyicidir
        $config['regolith_similarity']['weight'] = 0.46;
        $config['slope_deg']['weight'] = 0.20;
        $config['avg_temp_c']['weight'] = 0.14;
        $config['avg_temp_c']['earth_min'] = 4.0;
        $config['humidity_pct']['weight'] = 0.12;
        $config['humidity_pct']['earth_min'] = 30.0;
        $config['solar_exposure_pct']['weight'] = 0.08;
        $config['solar_exposure_pct']['earth_min'] = 45.0;
    } elseif ($validationTag === 'mars_delta') {
        // Mars paleogöl karbonat/kil deltaları (Jezero, Oxia Planum, Mawrth Vallis, Nili Fossae):
        // Mineralojik/sedimanter uyum (Salda Gölü hidromanyezit, Pilbara stromatolit, Utah kil, Qaidam) en kritik faktördür
        $config['regolith_similarity']['weight'] = 0.48;
        $config['slope_deg']['weight'] = 0.20;
        $config['solar_exposure_pct']['weight'] = 0.12;
        $config['avg_temp_c']['weight'] = 0.10;
        $config['avg_temp_c']['earth_min'] = 10.0;
        $config['humidity_pct']['weight'] = 0.10;
        $config['humidity_pct']['earth_min'] = 38.0;
    } elseif (($target['body'] ?? '') === 'Moon') {
        // Ay Mare bazalt ve çarpma kraterleri (Hadley Rille, Taurus-Littrow, Aristarchus, Tycho, Copernicus)
        $config['regolith_similarity']['weight'] = 0.40;
        $config['slope_deg']['weight'] = 0.22;
        $config['solar_exposure_pct']['weight'] = 0.14;
        $config['humidity_pct']['weight'] = 0.12;
        $config['humidity_pct']['earth_min'] = 18.0;
        $config['avg_temp_c']['weight'] = 0.12;
        $config['avg_temp_c']['earth_min'] = -10.0;
    }

    $weightTotal = 0.0;
    foreach ($config as $cfg) {
        $weightTotal += $cfg['weight'];
    }

    $weightedSqSum = 0.0;
    $geometricProduct = 1.0;
    $vetoed = false;
    $metricDiffs = [];

    foreach ($config as $key => $cfg) {
        $rawTarget = (float)($tMetrics[$key] ?? 0.0);
        // Earth-Envelope Clamping: Dünya üzerinde imkansız olan ekstrem değerleri (ör. -233°C) Dünya ekstremine kırp
        $effectiveTarget = astroClip($rawTarget, $cfg['earth_min'], $cfg['earth_max']);
        $clamped = abs($effectiveTarget - $rawTarget) > 0.05;

        $eVal = (float)($eMetrics[$key] ?? 0.0);
        $diff = $eVal - $effectiveTarget;
        $absDiff = abs($diff);

        $normalizedDist = min(1.0, $absDiff / $cfg['scale']);
        $normWeight = $cfg['weight'] / $weightTotal;

        // 1. Öklid bileşeni
        $weightedSqSum += $normWeight * ($normalizedDist ** 2);

        // 2. Geometrik Ortalama bileşeni: s_k = clip(1 - |x_k - t'_k| / span, 0, 1)
        $s_k = max(0.0, min(1.0, 1.0 - $normalizedDist));
        if ($s_k <= 0.001) {
            $vetoed = true;
            $geometricProduct = 0.0;
        } else {
            $geometricProduct *= ($s_k ** $normWeight);
        }

        $metricDiffs[$key] = [
            'raw_target'       => round($rawTarget, 2),
            'effective_target' => round($effectiveTarget, 2),
            'target_clamped'   => round($effectiveTarget, 2),
            'clamped'          => $clamped,
            'target'           => round($rawTarget, 2),
            'earth'            => round($eVal, 2),
            'diff'             => round($eVal - $rawTarget, 2),
            'effective_diff'   => round($diff, 2),
            'abs_diff'         => round($absDiff, 2),
            'match_pct'        => round($s_k * 100.0, 1),
        ];
    }

    $euclideanDistance = sqrt($weightedSqSum);
    $euclideanScore = max(0.0, min(100.0, (1.0 - $euclideanDistance) * 100.0));
    $geometricScore = max(0.0, min(100.0, $geometricProduct * 100.0));

    // Fiziksel/iklimsel temel skor (%55 Geometrik + %45 Öklid)
    $rawBaseScore = $vetoed ? min(15.0, $euclideanScore * 0.25) : (0.55 * $geometricScore + 0.45 * $euclideanScore);

    // Jeolojik rejim uyumu ve kalıcı üs görev örtüşmesi ile nihai analog skorunu kalibre et
    $missionFactor = 0.90 + (0.10 * $regimeInfo['mission_overlap']);
    $finalScore = max(5.0, min(99.4, $rawBaseScore * $regimeInfo['regime_affinity'] * $missionFactor));

    $calibrationNote = buildCalibrationNote($target, $earth, $metricDiffs, $regimeInfo, $lang);
    $lat = (float)($earth['lat'] ?? 0.0);
    $lng = (float)($earth['lng'] ?? 0.0);

    return [
        'metrics'          => $eMetrics,
        'similarity_score' => round($finalScore, 1),
        'geometric_score'  => round($geometricScore * $regimeInfo['regime_affinity'], 1),
        'euclidean_score'  => round($euclideanScore * $regimeInfo['regime_affinity'], 1),
        'regime_label'     => $lang === 'en' ? $regimeInfo['regime_label_en'] : $regimeInfo['regime_label_tr'],
        'vetoed'           => $vetoed,
        'metric_diffs'     => $metricDiffs,
        'calibration_note' => $calibrationNote,
        'novelty'          => astroComputeNovelty($lat, $lng, $lang),
        'nearest_cave'     => astroFindNearestCave($lat, $lng, $lang),
        'qualification'    => astroAssessHardwareReadiness($earth, $lang),
    ];
}

/**
 * Seçilen iki saha için kalıcı üs mimarisi, yerçekimi, atmosfer, Earth-envelope kırpması ve metrik farklarına dayalı bilimsel kalibrasyon notu üretir.
 *
 * @param array<string, mixed> $target
 * @param array<string, mixed> $earth
 * @param array<string, array<string, mixed>> $diffs
 * @param array<string, mixed> $regimeInfo
 */
function buildCalibrationNote(array $target, array $earth, array $diffs, array $regimeInfo, string $lang = 'tr'): string
{
    $body = (string)($target['body'] ?? 'Moon');
    $vTag = (string)($target['validation_tag'] ?? '');
    $slopeDiff = round((float)($diffs['slope_deg']['abs_diff'] ?? 0.0), 1);
    $earthSlope = $earth['metrics']['slope_deg'] ?? 0;

    if ($lang === 'en') {
        $purpose = match ($vTag) {
            'lunar_polar_base' => 'Matches Artemis permanent polar ridge conditions (low-angle sunlight, anorthositic/basaltic regolith, and rover/EVA operations).',
            'cold_polar'       => 'Matches cryogenic cold-trap conditions for polar water-ice drilling (VIPER/TRIDENT) and volatile extraction.',
            'lava_tube'        => 'Provides a natural basaltic lava-tube cave and skylight entrance to test radiation-shielded underground habitats.',
            'mars_delta'       => 'Matches ancient Martian lake-delta carbonate and clay deposits for biosignature drilling and ISRU water extraction.',
            'permafrost'       => 'Models shallow Martian subsurface water-ice sheets for crewed base ISRU water mining.',
            default            => $body === 'Moon'
                ? 'Matches lunar basaltic volcanic plains and impact crater rims for EVA, rover traction, and ISRU tests.'
                : 'Matches Martian hyper-arid sulfate/basalt terrain for rover mobility, drilling, and instrument calibration.',
        };
        $cal = $body === 'Moon'
            ? "Calibration: 0.165g lunar gravity offloading & vacuum chamber required (terrain slope: {$earthSlope}°, Δ{$slopeDiff}°)."
            : "Calibration: 0.38g Martian gravity & 6 mbar CO₂ chamber correction required (terrain slope: {$earthSlope}°, Δ{$slopeDiff}°).";
        return $purpose . ' ' . $cal;
    }

    $purpose = match ($vTag) {
        'lunar_polar_base' => 'Artemis kalıcı Ay kutup üssü sırtlarını (1.5° yatay güneş açısı, anortozit/bazalt zemin ve EVA/rover operasyonları) test etmek için idealdir.',
        'cold_polar'       => 'Kalıcı gölgeli kutup kraterlerinde (PSR) kriyojenik buz sondajı ve su (H₂O) çıkarımı testlerini doğrudan simüle eder.',
        'lava_tube'        => 'Radyasyon ve mikrometeoritten korunan yeraltı lav tüpü (skylight) kalıcı üs ve mağara robotu testleri için birebir yapısal analogdur.',
        'mars_delta'       => 'Mars paleogöl deltalarındaki karbonat/kil yapısını temsil eder; biyo-imza sondajı ve minerallerden su üretimi (ISRU) için referans sahadır.',
        'permafrost'       => 'Kalıcı Mars üslerinin temel su kaynağı olan yüzeye yakın yeraltı buzul (permafrost) sondajı ve eritme testlerini modeller.',
        default            => $body === 'Moon'
            ? 'Ay bazalt düzlükleri ve krater yamaçlarındaki rover sürüş, EVA yürüyüş ve regolit (ISRU) donanım testleri için uygundur.'
            : 'Mars kurak sülfat/bazalt havzalarındaki gezgin (rover) sürüş, sondaj ve sensör kalibrasyon testleri için uygundur.',
    };

    $cal = $body === 'Moon'
        ? "Kalibrasyon: Ay yerçekimi (0.165g) askı sistemi ve vakum/toz odası desteği önerilir (Eğim: {$earthSlope}°, fark: {$slopeDiff}°)."
        : "Kalibrasyon: Mars yerçekimi (0.38g) ve ince CO₂ atmosferi (~6 mbar) düzeltmesi önerilir (Eğim: {$earthSlope}°, fark: {$slopeDiff}°).";

    return $purpose . ' ' . $cal;
}

try {
    $params = getRequestParams();

    // 1. Katalog zenginleştirme veya veritabanı durumu isteği (enrich_catalog=1)
    if (!empty($_REQUEST['enrich_catalog'])) {
        $earthAnalogs = astroDbGetEarthSites($params['lang'], false);
        $planetaryTargets = astroDbGetPlanetaryTargets(null, false);
        echo json_encode([
            'status'            => 'ok',
            'db_stats'          => astroDbStats(),
            'planetary_targets' => $planetaryTargets,
            'count'             => count($earthAnalogs),
            'results'           => $earthAnalogs,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // 2. Ay veya Mars küresinde herhangi bir (target_lat, target_lng) koordinatına tıklandıysa dinamik hedef sentezle
    $target = null;
    if ($params['target_lat'] !== null && $params['target_lng'] !== null) {
        $target = astroSynthesizePlanetaryTarget(
            $params['body'],
            $params['target_lat'],
            $params['target_lng'],
            $params['lang'],
            true
        );
    } elseif ($params['target_id'] !== '') {
        $target = astroDbGetPlanetaryTargetById($params['target_id'], $params['lang']);
    }

    if ($target === null) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Hedef lokasyon bulunamadı veya target_id / (target_lat, target_lng) eksik.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // 3. Eğer Dünya küresinde özel bir koordinata (earth_lat, earth_lng) tıklandıysa onu da veritabanına çöz/ekle
    $preferredEarthId = null;
    if ($params['earth_lat'] !== null && $params['earth_lng'] !== null) {
        $dynamicEarth = astroResolveEarthPoint($params['earth_lat'], $params['earth_lng'], $params['lang']);
        $preferredEarthId = $dynamicEarth['id'];
    }

    $earthAnalogs = astroDbGetEarthSites($params['lang'], false);
    $missionType = $params['mission_type'];
    $results = [];

    foreach ($earthAnalogs as $earth) {
        // Özel tıklanan Dünya noktasını görev filtresi ne olursa olsun sonuçlarda tut
        $isClickedEarth = ($preferredEarthId !== null && ($earth['id'] ?? '') === $preferredEarthId);
        if (!$isClickedEarth && $missionType !== 'all') {
            $missions = $earth['suitable_missions'] ?? [];
            if (!in_array($missionType, $missions, true)) {
                continue;
            }
        }

        $match = calculateAnalogMatch($target, $earth, $params['lang']);
        $results[] = array_merge($earth, $match);
    }

    usort(
        $results,
        static fn(array $a, array $b): int => $b['similarity_score'] <=> $a['similarity_score']
    );

    echo json_encode([
        'status'             => 'ok',
        'target'             => $target,
        'preferred_earth_id' => $preferredEarthId,
        'mission_type'       => $missionType,
        'db_stats'           => astroDbStats(),
        'count'              => count($results),
        'results'            => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
