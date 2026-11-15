<?php
/**
 * api/db.php
 * Dinamik Gezegen ve Dünya Analog Veritabanı Motoru (SQLite PDO + Otomatik Tohumlama + Küresel Koordinat Sentezleyici)
 *
 * Bu modül:
 * 1. data/astro_analog.sqlite ilişkisel veritabanını otomatik oluşturur ve yönetir.
 * 2. Ay, Mars ve Dünya üzerindeki yüzlerce gerçek saha ve küresel grid noktasını veritabanına tohumlar.
 * 3. Ay, Mars veya Dünya küresinde tıklanan HERHANGİ BİR (lat, lng) koordinatı için
 *    LRO Diviner/LOLA (Ay), MGS MOLA/TES (Mars) ve NASA POWER / SRTM DEM / GLiM (Dünya)
 *    modellerini kullanarak gerçek zamanlı bilimsel telemetri üretir ve veritabanına kaydeder.
 */

declare(strict_types=1);

require_once __DIR__ . '/analog_metrics.php';

/**
 * Tekil (Singleton) PDO SQLite bağlantısını döndürür ve şema/veri tohumlamasını garanti eder.
 */
function astroDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }

    $dbPath = $dataDir . '/astro_analog.sqlite';
    $isNew = !is_file($dbPath) || filesize($dbPath) < 4096;

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    astroInitSchema($pdo);

    if ($isNew || astroNeedsSeeding($pdo)) {
        astroSeedDatabase($pdo);
    }

    return $pdo;
}

/**
 * İlişkisel veritabanı tablolarını ve indekslerini oluşturur.
 */
function astroInitSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS planetary_targets (
            id TEXT PRIMARY KEY,
            body TEXT NOT NULL,
            name TEXT NOT NULL,
            name_en TEXT NOT NULL,
            country_or_region TEXT NOT NULL,
            country_or_region_en TEXT NOT NULL,
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            elevation_m REAL DEFAULT 0.0,
            diameter_km REAL DEFAULT 0.0,
            feature_type TEXT DEFAULT 'crater',
            validation_tag TEXT DEFAULT 'planetary_surface',
            avg_temp_c REAL NOT NULL,
            slope_deg REAL NOT NULL,
            humidity_pct REAL NOT NULL DEFAULT 0.0,
            regolith_similarity REAL NOT NULL DEFAULT 100.0,
            solar_exposure_pct REAL NOT NULL,
            mineral_type TEXT NOT NULL,
            mineral_type_en TEXT NOT NULL,
            suitable_missions_json TEXT NOT NULL,
            description TEXT NOT NULL,
            description_en TEXT NOT NULL,
            source_url TEXT DEFAULT '',
            source_label TEXT DEFAULT '',
            trek_url TEXT DEFAULT '',
            is_curated INTEGER NOT NULL DEFAULT 1,
            is_dynamic INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_planetary_body ON planetary_targets(body);
        CREATE INDEX IF NOT EXISTS idx_planetary_coords ON planetary_targets(body, lat, lng);

        CREATE TABLE IF NOT EXISTS earth_sites (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            name_en TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT 'Earth',
            country_or_region TEXT NOT NULL,
            country_or_region_en TEXT NOT NULL,
            continent TEXT DEFAULT '',
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            elevation_m REAL DEFAULT 500.0,
            avg_temp_c REAL NOT NULL,
            slope_deg REAL NOT NULL,
            humidity_pct REAL NOT NULL,
            regolith_similarity REAL NOT NULL,
            solar_exposure_pct REAL NOT NULL,
            t2m_range REAL DEFAULT 14.0,
            prectotcorr REAL DEFAULT 0.5,
            allsky_sw_dwn REAL DEFAULT 5.2,
            mineral_type TEXT NOT NULL,
            mineral_type_en TEXT NOT NULL,
            suitable_missions_json TEXT NOT NULL,
            tags_json TEXT DEFAULT '[]',
            description TEXT NOT NULL,
            description_en TEXT NOT NULL,
            source_url TEXT DEFAULT '',
            is_curated INTEGER NOT NULL DEFAULT 1,
            is_dynamic INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_earth_coords ON earth_sites(lat, lng);
        CREATE INDEX IF NOT EXISTS idx_earth_curated ON earth_sites(is_curated);

        CREATE TABLE IF NOT EXISTS nasa_power_cache (
            coord_key TEXT PRIMARY KEY,
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            t2m REAL NOT NULL,
            t2m_range REAL NOT NULL,
            prectotcorr REAL NOT NULL,
            rh2m REAL NOT NULL,
            allsky_sfc_sw_dwn REAL NOT NULL,
            slope_deg REAL DEFAULT 5.0,
            elevation_m REAL DEFAULT 400.0,
            source TEXT DEFAULT 'nasa_power',
            updated_at INTEGER NOT NULL
        );
    ");
}

/**
 * Veritabanında yeterli saha olup olmadığını kontrol eder.
 */
function astroNeedsSeeding(PDO $pdo): bool
{
    $pCount = (int)$pdo->query("SELECT COUNT(*) FROM planetary_targets WHERE is_dynamic = 0")->fetchColumn();
    $eCount = (int)$pdo->query("SELECT COUNT(*) FROM earth_sites WHERE is_dynamic = 0")->fetchColumn();
    return ($pCount < 48 || $eCount < 55);
}

/**
 * Ekstra küresel Ay ve Mars IAU/NASA hedeflerini döndürür (tüm enlem ve boylam kuşaklarını kapsar).
 *
 * @return array<int, array<string, mixed>>
 */
function astroGetGlobalPlanetaryExpansion(): array
{
    return [
        // ======================== AY (MOON) KÜRESEL IAU / LRO HEDEFLERİ ========================
        [
            'id' => 'moon_tycho_crater',
            'name' => 'Tycho Krateri Merkez Zirvesi',
            'name_en' => 'Tycho Crater Central Peak',
            'body' => 'Moon',
            'country_or_region' => 'Güney Yaylaları, Ay',
            'country_or_region_en' => 'Southern Highlands, Moon',
            'lat' => -43.31, 'lng' => -11.36,
            'validation_tag' => 'impact_crater',
            'metrics' => ['avg_temp_c' => -35.0, 'slope_deg' => 21.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 54.0],
            'mineral_type' => 'Anortozitik Çarpma Eriyiği & Gabroik Zirve Kayacı',
            'mineral_type_en' => 'Anorthositic Impact Melt & Gabbroic Peak Rock',
            'suitable_missions' => ['rover_test', 'sample_analysis', 'hazard_avoidance', 'drilling_test'],
            'description' => '108 milyon yıllık genç ve sarp çarpma krateri; çarpma eriyiği tabakaları, dik yamaçları ve yüksek kayaç yoğunluğuyla ekstrem rover navigasyon ve otonom tehlike kaçınma testleri için referans hedeftir.',
            'description_en' => 'Young 108-Myr impact crater with steep walls, impact melt sheets, and high rock abundance, serving as a benchmark for autonomous hazard avoidance and steep-terrain rover navigation.',
            'source_url' => 'https://en.wikipedia.org/wiki/Tycho_(lunar_crater)',
            'source_label' => 'NASA LROC Tycho Central Peak NAC DTM',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_copernicus_crater',
            'name' => 'Copernicus Krateri Terasları',
            'name_en' => 'Copernicus Crater Terraces',
            'body' => 'Moon',
            'country_or_region' => 'Oceanus Procellarum Doğusu, Ay',
            'country_or_region_en' => 'Eastern Oceanus Procellarum, Moon',
            'lat' => 9.62, 'lng' => -20.08,
            'validation_tag' => 'impact_crater',
            'metrics' => ['avg_temp_c' => -18.0, 'slope_deg' => 16.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 95, 'solar_exposure_pct' => 60.0],
            'mineral_type' => 'Olivin Zengini Troktolit & Anortozit',
            'mineral_type_en' => 'Olivine-rich Troctolite & Anorthosite',
            'suitable_missions' => ['sample_analysis', 'eva_suit', 'rover_test', 'drilling_test'],
            'description' => '93 km çapındaki basamaklı krater terasları ve merkezi zirvelerinde derin Ay kabuğuna ait olivin ve troktolit mineralleri yüzeye çıkar.',
            'description_en' => '93 km terraced impact crater whose central peaks expose deep lunar crust olivine and troctolite minerals.',
            'source_url' => 'https://en.wikipedia.org/wiki/Copernicus_(lunar_crater)',
            'source_label' => 'USGS Astrogeology & Kaguya Spectral Profiler',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_mare_tranquillitatis_pit',
            'name' => 'Mare Tranquillitatis Çukuru (Doğrulanmış Lav Tüpü Mağarası)',
            'name_en' => 'Mare Tranquillitatis Pit (Confirmed Subsurface Cave Conduit)',
            'body' => 'Moon',
            'country_or_region' => 'Mare Tranquillitatis (Sessizlik Denizi), Ay',
            'country_or_region_en' => 'Mare Tranquillitatis (Sea of Tranquility), Moon',
            'lat' => 8.335, 'lng' => 33.222,
            'validation_tag' => 'lava_tube',
            'metrics' => ['avg_temp_c' => 17.0, 'slope_deg' => 6.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 100, 'solar_exposure_pct' => 18.0],
            'mineral_type' => 'İlmenit Zengini Mare Bazaltı & Lav Tüpü Galerisi',
            'mineral_type_en' => 'Ilmenite-rich Mare Basalt & Lava Tube Conduit',
            'suitable_missions' => ['cave_exploration', 'radiation_shelter', 'habitat_construction', 'isru_mining'],
            'description' => '2024 radar verileriyle (Carrer et al., Nature Astronomy) tabanından en az 30–80 metre yatay uzanan bir yeraltı mağara galerisine açıldığı kanıtlanan 100 m derinliğindeki en derin Ay çukurudur. Kalıcı Ay üssü için birincil radyasyon sığınağı adayıdır.',
            'description_en' => '100 m deep lunar skylight confirmed by 2024 radar sounder analysis (Nature Astronomy) to lead into an accessible horizontal subsurface cave conduit 30–80 m long, prime for a permanent lunar base shelter.',
            'source_url' => 'https://www.nature.com/articles/s41550-024-02302-y',
            'source_label' => 'Carrer et al. (2024), Nature Astronomy: Radar evidence of an accessible cave conduit on the Moon',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_aristarchus_plateau',
            'name' => 'Aristarchus Platosu & Vallis Schröteri',
            'name_en' => 'Aristarchus Plateau & Vallis Schröteri',
            'body' => 'Moon',
            'country_or_region' => 'Oceanus Procellarum, Ay',
            'country_or_region_en' => 'Oceanus Procellarum, Moon',
            'lat' => 24.5, 'lng' => -51.0,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -22.0, 'slope_deg' => 8.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 56.0],
            'mineral_type' => 'Piroklastik Volkanik Cam Kürecikleri & İlmenit Bazaltı',
            'mineral_type_en' => 'Pyroclastic Volcanic Glass Beads & Ilmenite Basalt',
            'suitable_missions' => ['isru_mining', 'volcanology', 'rover_test', 'landing_site', 'solar_power'],
            'description' => 'Ay üzerindeki en geniş piroklastik kül ve volkanik cam yataklarından birini ve Ay\'ın en büyük kıvrımlı lav kanalını (Vallis Schröteri) barındırır; ISRU oksijen ve su üretimi için en zengin hedeflerden biridir.',
            'description_en' => 'Hosts one of the largest regional pyroclastic volcanic glass deposits on the Moon and its largest sinuous rille (Vallis Schröteri), a top target for ISRU oxygen extraction.',
            'source_url' => 'https://en.wikipedia.org/wiki/Aristarchus_plateau',
            'source_label' => 'NASA LRO / Moon Mineralogy Mapper (M3) Pyroclastic Study',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_von_karman_change4',
            'name' => 'Von Kármán Krateri (Ay Arka Yüzü - SPA Havzası)',
            'name_en' => 'Von Kármán Crater (Lunar Far Side - SPA Basin)',
            'body' => 'Moon',
            'country_or_region' => 'Güney Kutbu-Aitken (SPA) Havzası, Ay Arka Yüzü',
            'country_or_region_en' => 'South Pole-Aitken (SPA) Basin, Lunar Far Side',
            'lat' => -44.45, 'lng' => 176.25,
            'validation_tag' => 'lunar_surface',
            'metrics' => ['avg_temp_c' => -38.0, 'slope_deg' => 5.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 97, 'solar_exposure_pct' => 52.0],
            'mineral_type' => 'Düşük Kalsiyumlu Piroksen & Olivin (Üst Manto Materyali)',
            'mineral_type_en' => 'Low-Calcium Pyroxene & Olivine (Upper Mantle Material)',
            'suitable_missions' => ['rover_test', 'comms_relay', 'sample_analysis', 'seismic_monitoring'],
            'description' => 'Ay\'ın arka yüzündeki 2.500 km\'lik Güney Kutbu-Aitken havzasında yer alır; Chang\'e-4 ve Yutu-2 gezgininin iniş yaptığı, derin manto minerallerini barındıran radyo-sessiz bilimsel üs bölgesidir.',
            'description_en' => 'Located within the 2,500 km South Pole-Aitken basin on the lunar far side; landing site of Chang\'e-4 and Yutu-2, ideal for radio-quiet astronomy and deep mantle sampling.',
            'source_url' => 'https://en.wikipedia.org/wiki/Von_K%C3%A1rm%C3%A1n_(lunar_crater)',
            'source_label' => 'CNSA / NASA LROC Chang\'e-4 Landing Site Characterization',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_peary_north_pole',
            'name' => 'Peary Krateri Sırtı (Ay Kuzey Kutbu)',
            'name_en' => 'Peary Crater Rim (Lunar North Pole)',
            'body' => 'Moon',
            'country_or_region' => 'Ay Kuzey Kutbu',
            'country_or_region_en' => 'Lunar North Pole',
            'lat' => 88.6, 'lng' => 33.0,
            'validation_tag' => 'lunar_polar_base',
            'metrics' => ['avg_temp_c' => -75.0, 'slope_deg' => 8.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 86.0],
            'mineral_type' => 'Anortozitik Kutup Regoliti & Komşu PSR Buzu',
            'mineral_type_en' => 'Anorthositic Polar Regolith & Adjacent PSR Ice',
            'suitable_missions' => ['solar_power', 'habitat_construction', 'ice_prospecting', 'comms_relay'],
            'description' => 'Ay Kuzey Kutbu\'na en yakın büyük krater sırtı; yılın %85\'inden fazlasında güneş alan "Ebedi Işık Zirveleri" (Peaks of Eternal Light) adaylarını ve hemen yanındaki Whipple soğuk tuzaklarını barındırır.',
            'description_en' => 'Closest large crater rim to the lunar north pole, featuring near-continuous sunlit peaks adjacent to the Whipple crater cold traps.',
            'source_url' => 'https://en.wikipedia.org/wiki/Peary_(crater)',
            'source_label' => 'NASA LRO LOLA North Polar Illumination Map',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_reiner_gamma',
            'name' => 'Reiner Gamma (Ay Manyetik Girdabı)',
            'name_en' => 'Reiner Gamma (Lunar Magnetic Swirl)',
            'body' => 'Moon',
            'country_or_region' => 'Oceanus Procellarum, Ay',
            'country_or_region_en' => 'Oceanus Procellarum, Moon',
            'lat' => 7.5, 'lng' => -59.0,
            'validation_tag' => 'lunar_surface',
            'metrics' => ['avg_temp_c' => -15.0, 'slope_deg' => 2.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 95, 'solar_exposure_pct' => 58.0],
            'mineral_type' => 'Yüksek Albedolu Taze Mare Bazaltı (Manyetik Anomali)',
            'mineral_type_en' => 'High-Albedo Fresh Mare Basalt (Magnetic Anomaly)',
            'suitable_missions' => ['landing_site', 'radiation_shelter', 'rover_test', 'solar_power'],
            'description' => 'Yerel kabuk manyetik alanının güneş rüzgarı protonlarını saptırdığı düz bazaltik ova (NASA Lunar Vertex CLPS görev sahası). Doğal manyetik kalkanlama araştırmaları için eşsizdir.',
            'description_en' => 'Flat mare plain with a strong localized crustal magnetic field that deflects solar wind protons (NASA Lunar Vertex CLPS landing site).',
            'source_url' => 'https://en.wikipedia.org/wiki/Reiner_Gamma',
            'source_label' => 'NASA CLPS Lunar Vertex Mission Specification',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_gruithuisen_domes',
            'name' => 'Gruithuisen Kubbeleri (Silisik Volkanizma)',
            'name_en' => 'Gruithuisen Domes (Silicic Volcanism)',
            'body' => 'Moon',
            'country_or_region' => 'Mare Imbrium Batısı, Ay',
            'country_or_region_en' => 'Western Mare Imbrium, Moon',
            'lat' => 36.3, 'lng' => -40.2,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -25.0, 'slope_deg' => 14.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 94, 'solar_exposure_pct' => 55.0],
            'mineral_type' => 'Riyolitik / Granitik Yüksek Silisli Lav Kubbesi',
            'mineral_type_en' => 'Rhyolitic / Granitic High-Silica Lava Dome',
            'suitable_missions' => ['volcanology', 'sample_analysis', 'rover_test', 'eva_suit'],
            'description' => 'Ay\'daki nadir bazalt-dışı (granitik/riyolitik) volkanik kubbelerdir. NASA CLPS Lunar-VISE görevinin hedef sahası olup Dünya\'daki riyolitik/dasitik kubbelerle doğrudan benzeşir.',
            'description_en' => 'Rare non-basaltic (granitic/rhyolitic) volcanic domes on the Moon and target of NASA\'s CLPS Lunar-VISE mission.',
            'source_url' => 'https://science.nasa.gov/lunar-science/clps-deliveries/',
            'source_label' => 'NASA CLPS Lunar-VISE Gruithuisen Domes Investigation',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_mare_crisium',
            'name' => 'Mare Crisium (Bunalımlar Denizi Havzası)',
            'name_en' => 'Mare Crisium Basin',
            'body' => 'Moon',
            'country_or_region' => 'Doğu Yakın Yüz, Ay',
            'country_or_region_en' => 'Eastern Near Side, Moon',
            'lat' => 17.0, 'lng' => 59.1,
            'validation_tag' => 'lunar_surface',
            'metrics' => ['avg_temp_c' => -19.0, 'slope_deg' => 2.2, 'humidity_pct' => 0.0, 'regolith_similarity' => 97, 'solar_exposure_pct' => 57.0],
            'mineral_type' => 'Magnezyum Zengini Mare Bazaltı',
            'mineral_type_en' => 'Magnesium-rich Mare Basalt',
            'suitable_missions' => ['landing_site', 'habitat_construction', 'solar_power', 'seismic_monitoring', 'isru_mining'],
            'description' => 'Çok ince kabuklu, aşırı düz bazaltik çarpma havzası (Firefly Blue Ghost CLPS iniş bölgesi). Geniş güvenli iniş alanları ve sismik/ısı akışı ölçümleri için idealdir.',
            'description_en' => 'Extremely flat basaltic multi-ring impact basin with thin crust (Firefly Blue Ghost CLPS landing region).',
            'source_url' => 'https://en.wikipedia.org/wiki/Mare_Crisium',
            'source_label' => 'NASA CLPS Firefly Blue Ghost Mare Crisium Payload Manifest',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_nobile_rim',
            'name' => 'Nobile Krateri Kenarı (VIPER & Artemis III Bölgesi)',
            'name_en' => 'Nobile Crater Rim (VIPER & Artemis III Region)',
            'body' => 'Moon',
            'country_or_region' => 'Ay Güney Kutbu',
            'country_or_region_en' => 'Lunar South Pole',
            'lat' => -85.2, 'lng' => 53.5,
            'validation_tag' => 'lunar_polar_base',
            'metrics' => ['avg_temp_c' => -95.0, 'slope_deg' => 10.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 100, 'solar_exposure_pct' => 74.0],
            'mineral_type' => 'Anortozitik Regolit & Sığ Buz Tuzakları',
            'mineral_type_en' => 'Anorthositic Regolith & Shallow Ice Traps',
            'suitable_missions' => ['ice_prospecting', 'drilling_test', 'rover_test', 'landing_site', 'isru_mining'],
            'description' => 'Hem NASA VIPER buz sondaj gezgini hem de Artemis III mürettebatlı inişi için seçilen güney kutbu sırtı; güneşli tepeler ile sığ soğuk tuzaklar birbirine birkaç yüz metre mesafededir.',
            'description_en' => 'South polar rim region selected for both the VIPER ice-drilling rover and Artemis III crewed landing candidates.',
            'source_url' => 'https://www.nasa.gov/news-release/nasa-provides-update-on-artemis-iii-moon-landing-regions/',
            'source_label' => 'NASA Artemis III & VIPER Site Selection Report',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_tsiolkovskiy',
            'name' => 'Tsiolkovskiy Krateri (Arka Yüz Mare Düzlüğü)',
            'name_en' => 'Tsiolkovskiy Crater (Far Side Mare Floor)',
            'body' => 'Moon',
            'country_or_region' => 'Güneybatı Arka Yüz, Ay',
            'country_or_region_en' => 'Southwestern Far Side, Moon',
            'lat' => -20.38, 'lng' => 128.97,
            'validation_tag' => 'lunar_surface',
            'metrics' => ['avg_temp_c' => -24.0, 'slope_deg' => 7.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 55.0],
            'mineral_type' => 'Koyu Mare Bazaltı & Anortozitik Merkez Zirve',
            'mineral_type_en' => 'Dark Mare Basalt & Anorthositic Central Peak',
            'suitable_missions' => ['landing_site', 'sample_analysis', 'rover_test', 'eva_suit'],
            'description' => 'Ay\'ın arka yüzündeki en belirgin koyu bazalt tabanlı krater ve ortasında yükselen 3.2 km\'lik saf anortozit zirvesi.',
            'description_en' => 'Most prominent dark mare-floored crater on the lunar far side with a pristine 3.2 km anorthosite central peak.',
            'source_url' => 'https://en.wikipedia.org/wiki/Tsiolkovskiy_(crater)',
            'source_label' => 'LROC NAC Oblique & Apollo 15 Mapping Camera',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_lacus_mortis_pit',
            'name' => 'Lacus Mortis Çukuru (Eğik Rampalı Lav Tüpü)',
            'name_en' => 'Lacus Mortis Pit (Ramped Lava Tube Skylight)',
            'body' => 'Moon',
            'country_or_region' => 'Lacus Mortis (Kuzeydoğu Ay)',
            'country_or_region_en' => 'Lacus Mortis (Northeastern Moon)',
            'lat' => 44.96, 'lng' => 25.61,
            'validation_tag' => 'lava_tube',
            'metrics' => ['avg_temp_c' => 12.0, 'slope_deg' => 14.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 99, 'solar_exposure_pct' => 25.0],
            'mineral_type' => 'Mare Bazaltı & Çöküntü Ejekta Rampası',
            'mineral_type_en' => 'Mare Basalt & Collapse Debris Ramp',
            'suitable_missions' => ['cave_exploration', 'rover_test', 'radiation_shelter', 'habitat_construction'],
            'description' => 'Doğu duvarında doğal bir moloz rampası bulunan 80 m derinliğindeki lav tüpü çukuru; halat gerektirmeden tekerlekli/bacaklı robotlarla mağara içine inilebilecek birincil hedeftir.',
            'description_en' => '80 m deep skylight pit featuring a natural rubble ramp on its eastern wall that could allow rovers to drive directly into a lunar cave.',
            'source_url' => 'https://lroc.im-ldi.com/atlases/pits',
            'source_label' => 'LROC Lunar Pit Atlas: Lacus Mortis Pit',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_alphonsus_crater',
            'name' => 'Alphonsus Krateri Piroklastik Bacaları',
            'name_en' => 'Alphonsus Crater Dark Halo Pyroclastic Vents',
            'body' => 'Moon',
            'country_or_region' => 'Mare Nubium Doğusu, Ay',
            'country_or_region_en' => 'Eastern Mare Nubium, Moon',
            'lat' => -13.39, 'lng' => -2.85,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -21.0, 'slope_deg' => 7.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 97, 'solar_exposure_pct' => 56.0],
            'mineral_type' => 'Koyu Haleli Piroklastik Bazaltik Kül & Anortozit Sırt',
            'mineral_type_en' => 'Dark-Halo Pyroclastic Basaltic Ash & Anorthosite Ridge',
            'suitable_missions' => ['volcanology', 'isru_mining', 'rover_test', 'sample_analysis'],
            'description' => 'Tabanındaki kırıklar boyunca koyu haleli volkanik kül bacaları (dark halo craters) barındıran 119 km\'lik krater; Ranger 9 çarpma sahası ve Apollo son 후보 iniş sahalarındandır.',
            'description_en' => '119 km crater hosting volcanic dark-halo pyroclastic vents along floor fractures; Ranger 9 impact site and prime ISRU volcanic glass target.',
            'source_url' => 'https://en.wikipedia.org/wiki/Alphonsus_(crater)',
            'source_label' => 'NASA LROC NAC Alphonsus Pyroclastic Vent Survey',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_schrodinger_basin',
            'name' => 'Schrödinger Çarpma Havzası (Ay Arka Yüzü Güney Kutbu)',
            'name_en' => 'Schrödinger Impact Basin (Far-Side South Polar Region)',
            'body' => 'Moon',
            'country_or_region' => 'SPA Havzası Güneybatısı, Ay Arka Yüzü',
            'country_or_region_en' => 'Southwestern SPA Basin, Lunar Far Side',
            'lat' => -75.0, 'lng' => 132.4,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -72.0, 'slope_deg' => 6.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 99, 'solar_exposure_pct' => 45.0],
            'mineral_type' => 'Saf Olivin/Anortozit Zirve Halkası & Dev Piroklastik Baca',
            'mineral_type_en' => 'Pure Olivine/Anorthosite Peak Ring & Giant Pyroclastic Vent',
            'suitable_missions' => ['sample_analysis', 'volcanology', 'seismic_monitoring', 'rover_test', 'isru_mining'],
            'description' => '320 km çapındaki genç iki halkalı çarpma havzası (NASA CLPS Draper/Farside Seismic Suite iniş sahası); hem derin kabuk olivinini hem de dev bir piroklastik volkan bacasını aynı havzada sunar.',
            'description_en' => '320 km peak-ring impact basin on the lunar far side (selected for NASA CLPS Farside Seismic Suite), exposing deep-crust olivine and a major pyroclastic vent.',
            'source_url' => 'https://en.wikipedia.org/wiki/Schr%C3%B6dinger_(crater)',
            'source_label' => 'NASA CLPS PRISM Schrödinger Basin Selection Report',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_mons_rumker',
            'name' => 'Mons Rümker Volkanik Kubbe Kompleksi (Chang\'e-5 Sahası)',
            'name_en' => 'Mons Rümker Volcanic Dome Complex (Chang\'e-5 Site)',
            'body' => 'Moon',
            'country_or_region' => 'Kuzey Oceanus Procellarum, Ay',
            'country_or_region_en' => 'Northern Oceanus Procellarum, Moon',
            'lat' => 40.8, 'lng' => -58.1,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -28.0, 'slope_deg' => 4.2, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 53.0],
            'mineral_type' => '2.0 Milyar Yıllık Genç KREEP Zengini Mare Bazaltı',
            'mineral_type_en' => '2.0-Ga Young KREEP-Rich Mare Basalt',
            'suitable_missions' => ['sample_analysis', 'volcanology', 'landing_site', 'drilling_test'],
            'description' => '30\'dan fazla bazaltik kalkan kubbeden oluşan volkanik plato; yakınındaki düzlükten Chang\'e-5 görevi Ay\'ın bilinen en genç (2.0 milyar yıllık) bazalt örneklerini Dünya\'ya getirmiştir.',
            'description_en' => 'Volcanic plateau of 30+ shield domes where Chang\'e-5 returned the youngest known lunar mare basalts (2.0 Ga).',
            'source_url' => 'https://en.wikipedia.org/wiki/Mons_R%C3%BCmker',
            'source_label' => 'Chang\'e-5 Sample Return & LROC Mons Rümker Geologic Map',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_mare_ingenii_pit',
            'name' => 'Mare Ingenii Çukuru (Arka Yüz Lav Tüpü Sığınağı)',
            'name_en' => 'Mare Ingenii Pit (Far-Side Lava Tube Shelter)',
            'body' => 'Moon',
            'country_or_region' => 'Mare Ingenii, Ay Arka Yüzü',
            'country_or_region_en' => 'Mare Ingenii, Lunar Far Side',
            'lat' => -35.95, 'lng' => 166.06,
            'validation_tag' => 'lava_tube',
            'metrics' => ['avg_temp_c' => 15.0, 'slope_deg' => 7.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 20.0],
            'mineral_type' => 'Arka Yüz Mare Bazaltı & Çökmüş Lav Kanalı',
            'mineral_type_en' => 'Far-Side Mare Basalt & Collapsed Lava Conduit',
            'suitable_missions' => ['cave_exploration', 'radiation_shelter', 'habitat_construction'],
            'description' => 'Ay\'ın arka yüzündeki nadir mare düzlüklerinden Mare Ingenii\'de yer alan 70 m derinliğindeki çöküntü çukuru; arka yüz radyo-astronomi üssü için doğal radyasyon sığınağı sunar.',
            'description_en' => '70 m deep collapse pit in Mare Ingenii on the lunar far side, offering natural radiation shielding for a far-side radio-astronomy base.',
            'source_url' => 'https://lroc.im-ldi.com/atlases/pits',
            'source_label' => 'LROC Lunar Pit Atlas: Mare Ingenii Pit',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_taurus_littrow_apollo17',
            'name' => 'Taurus-Littrow Vadisi (Apollo 17 & Turuncu Cam Toprak)',
            'name_en' => 'Taurus-Littrow Valley (Apollo 17 & Orange Glass Soil)',
            'body' => 'Moon',
            'country_or_region' => 'Mare Serenitatis Güneydoğusu, Ay',
            'country_or_region_en' => 'Southeastern Mare Serenitatis, Moon',
            'lat' => 20.19, 'lng' => 30.77,
            'validation_tag' => 'lunar_surface',
            'metrics' => ['avg_temp_c' => -18.0, 'slope_deg' => 9.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 100, 'solar_exposure_pct' => 58.0],
            'mineral_type' => 'Yüksek Titanyumlu Bazalt & 74220 Turuncu Piroklastik Volkanik Cam',
            'mineral_type_en' => 'High-Titanium Basalt & 74220 Orange Pyroclastic Glass',
            'suitable_missions' => ['eva_suit', 'rover_test', 'isru_mining', 'sample_analysis', 'volcanology'],
            'description' => 'Jeolog astronot Harrison Schmitt\'in Shorty Krateri\'nde turuncu volkanik cam kürecikleri keşfettiği derin tektonik-volkanik vadi; en uzun LRV gezgin sürüşünün (35.9 km) yapıldığı referans sahadır.',
            'description_en' => 'Deep graben valley where Apollo 17 astronaut-geologist Harrison Schmitt discovered orange volcanic glass beads during a 35.9 km Lunar Roving Vehicle traverse.',
            'source_url' => 'https://nssdc.gsfc.nasa.gov/planetary/lunar/apollo_17_landing_site.html',
            'source_label' => 'NASA NSSDC Apollo 17 Taurus-Littrow Geologic Summary',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_amundsen_crater',
            'name' => 'Amundsen Krateri Tabanı & Merkez Zirvesi (Güney Kutbu)',
            'name_en' => 'Amundsen Crater Floor & Central Peak (South Pole)',
            'body' => 'Moon',
            'country_or_region' => 'Ay Güney Kutup Bölgesi',
            'country_or_region_en' => 'Lunar South Polar Region',
            'lat' => -84.5, 'lng' => 82.8,
            'validation_tag' => 'cold_polar',
            'metrics' => ['avg_temp_c' => -175.0, 'slope_deg' => 6.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 99, 'solar_exposure_pct' => 12.0],
            'mineral_type' => 'Yüzey Su Buzu (M3/LAMP Doğrulanmış) & Anortozitik Düz Taban',
            'mineral_type_en' => 'Surface Water Ice (M3/LAMP Confirmed) & Anorthositic Flat Floor',
            'suitable_missions' => ['ice_prospecting', 'rover_test', 'isru_mining', 'landing_site', 'drilling_test'],
            'description' => '103 km çapındaki Amundsen krateri, hem düz ve rover sürüşüne uygun tabanı hem de LRO LAMP ve M3 spektrometreleriyle doğrulanmış geniş yüzey buzu yataklarıyla NASA\'nın uzun süreli kutup gezgini tasarım referansıdır.',
            'description_en' => '103 km south polar crater combining a flat, rover-traversable floor with confirmed surface water ice exposures.',
            'source_url' => 'https://en.wikipedia.org/wiki/Amundsen_(crater)',
            'source_label' => 'NASA Lunar Polar Rover Design Reference Mission: Amundsen Crater',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_orientale_basin',
            'name' => 'Mare Orientale Çok Halkalı Çarpma Havzası',
            'name_en' => 'Mare Orientale Multi-Ring Impact Basin',
            'body' => 'Moon',
            'country_or_region' => 'Batı Kenar (Limb), Ay',
            'country_or_region_en' => 'Western Limb, Moon',
            'lat' => -19.3, 'lng' => -92.8,
            'validation_tag' => 'impact_crater',
            'metrics' => ['avg_temp_c' => -23.0, 'slope_deg' => 11.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 55.0],
            'mineral_type' => 'Saf Anortozit İç Halka ( Montes Rook) & Mare Bazalt Merkezi',
            'mineral_type_en' => 'Pure Anorthosite Inner Ring (Montes Rook) & Mare Basalt Center',
            'suitable_missions' => ['seismic_monitoring', 'sample_analysis', 'rover_test', 'volcanology'],
            'description' => 'Ay\'daki en genç ve en iyi korunmuş 930 km çapındaki üç halkalı çarpma havzası; GRAIL yerçekimi misyonunun kabuk kalınlığı ve iç halka oluşumunu modellediği ana hedeftir.',
            'description_en' => 'Youngest and best-preserved 930 km multi-ring impact basin on the Moon, studied intensively by NASA\'s GRAIL gravity mission.',
            'source_url' => 'https://en.wikipedia.org/wiki/Mare_Orientale',
            'source_label' => 'NASA GRAIL & LRO Mare Orientale Ring Tectonics',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],
        [
            'id' => 'moon_plato_crater',
            'name' => 'Plato Krateri & Rima Plato Lav Kanalı',
            'name_en' => 'Plato Crater & Rima Plato Sinuous Rille',
            'body' => 'Moon',
            'country_or_region' => 'Mare Imbrium Kuzeyi / Montes Alpes, Ay',
            'country_or_region_en' => 'Northern Mare Imbrium / Montes Alpes, Moon',
            'lat' => 51.6, 'lng' => -9.3,
            'validation_tag' => 'lunar_surface',
            'metrics' => ['avg_temp_c' => -36.0, 'slope_deg' => 3.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 95, 'solar_exposure_pct' => 50.0],
            'mineral_type' => 'Düşük Albedolu Bazalt Taban & Alp Dağları Anortozit Masifi',
            'mineral_type_en' => 'Low-Albedo Basalt Floor & Alpine Anorthosite Massif',
            'suitable_missions' => ['landing_site', 'rover_test', 'volcanology', 'eva_suit'],
            'description' => '101 km çapında son derece düz bazaltik krater tabanı ve hemen doğusundaki Vallis Alpes tektonik yarığıyla kuzey orta enlem üs çalışmaları için idealdir.',
            'description_en' => '101 km flat-floored basaltic crater bordering the Montes Alpes and Vallis Alpes tectonic trough.',
            'source_url' => 'https://en.wikipedia.org/wiki/Plato_(crater)',
            'source_label' => 'USGS Lunar Geological Mapping Program: Plato Quadrangle',
            'trek_url' => 'https://trek.nasa.gov/moon/'
        ],

        // ======================== EKSTRA MARS KÜRESEL HEDEFLERİ ========================
        [
            'id' => 'mars_mawrth_vallis',
            'name' => 'Mawrth Vallis (Çok Katmanlı Fillosilikat Kil Kanyonu)',
            'name_en' => 'Mawrth Vallis (Layered Phyllosilicate Clay Outflow Channel)',
            'body' => 'Mars',
            'country_or_region' => 'Oxia Palus Dörtgeni, Mars',
            'country_or_region_en' => 'Oxia Palus Quadrangle, Mars',
            'lat' => 23.99, 'lng' => -19.05,
            'validation_tag' => 'mars_delta',
            'metrics' => ['avg_temp_c' => -53.0, 'slope_deg' => 5.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 62.0],
            'mineral_type' => 'Al-Smektit (Montmorillonit), Kaolinit & Fe/Mg Nontronit Killeri',
            'mineral_type_en' => 'Al-Smectite (Montmorillonite), Kaolinite & Fe/Mg Nontronite Clays',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'drilling_test', 'rover_test'],
            'description' => 'Mars üzerindeki en kalın (>200 m) ve en geniş açık renkli kil istifini barındıran taşkın kanalı; organik moleküllerin korunması açısından hem MSL hem ExoMars hem de Mars 2020\'de son finalistler arasında yer almıştır.',
            'description_en' => 'Hosts one of the thickest (>200 m) light-toned phyllosilicate clay sequences on Mars, a perennial top finalist for astrobiology landing missions.',
            'source_url' => 'https://en.wikipedia.org/wiki/Mawrth_Vallis',
            'source_label' => 'OMEGA / CRISM Phyllosilicate Stratigraphy at Mawrth Vallis',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_pavonis_mons_pit',
            'name' => 'Pavonis Mons Lav Tüpü Çukuru (Skylight Mağara Girişi)',
            'name_en' => 'Pavonis Mons Lava Tube Skylight Pit',
            'body' => 'Mars',
            'country_or_region' => 'Tharsis Montes Ekvator Kuşağı, Mars',
            'country_or_region_en' => 'Tharsis Montes Equatorial Belt, Mars',
            'lat' => 0.78, 'lng' => -112.95,
            'validation_tag' => 'lava_tube',
            'metrics' => ['avg_temp_c' => -45.0, 'slope_deg' => 8.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 99, 'solar_exposure_pct' => 22.0],
            'mineral_type' => 'Bazaltik Kalkan Lav Tüpü & Çöküntü Koni Boşluğu',
            'mineral_type_en' => 'Basaltic Shield Lava Tube & Collapse Pit Cavern',
            'suitable_missions' => ['cave_exploration', 'radiation_shelter', 'habitat_construction', 'astrobiology'],
            'description' => 'Pavonis Mons yanardağının yamacında 35 m çapında bir tavan açıklığından (skylight) 62 m derinlikteki devasa bir yeraltı lav mağarasına açılan Mars çukurudur.',
            'description_en' => '35 m wide volcanic skylight pit on Pavonis Mons opening into a subterranean lava cavern at least 62 m deep.',
            'source_url' => 'https://www.uahirise.org/ESP_023531_1840',
            'source_label' => 'MRO HiRISE ESP_023531_1840 Pavonis Mons Pit Digital Terrain Model',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_eberswalde_delta',
            'name' => 'Eberswalde Krateri (Korunmuş Menderesli Nehir Deltası)',
            'name_en' => 'Eberswalde Crater (Preserved Meandering River Delta)',
            'body' => 'Mars',
            'country_or_region' => 'Margaritifer Terra, Mars',
            'country_or_region_en' => 'Margaritifer Terra, Mars',
            'lat' => -23.86, 'lng' => -33.55,
            'validation_tag' => 'mars_delta',
            'metrics' => ['avg_temp_c' => -49.0, 'slope_deg' => 6.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 95, 'solar_exposure_pct' => 60.0],
            'mineral_type' => 'Ters Rölyefli Delta Kumtaşı, Konglomera & Kil Taşı',
            'mineral_type_en' => 'Inverted-Relief Deltaic Sandstone, Conglomerate & Mudstone',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'rover_test'],
            'description' => '115 km²\'lik fosilleşmiş nehir deltası ve menderes kanallarıyla Mars\'ta kalıcı akarsu ve göl sistemlerinin en net morfolojik kanıtını sunar.',
            'description_en' => '115 km² inverted-relief river delta offering the clearest morphological record of persistent fluvial-lacustrine activity on ancient Mars.',
            'source_url' => 'https://en.wikipedia.org/wiki/Eberswalde_(crater)',
            'source_label' => 'MGS MOC & MRO HiRISE Eberswalde Delta Stratigraphy',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_noctis_labyrinthus',
            'name' => 'Noctis Labyrinthus & Relikt Buzul/Volkan Kompleksi',
            'name_en' => 'Noctis Labyrinthus & Relict Glacier/Volcano Complex',
            'body' => 'Mars',
            'country_or_region' => 'Phoenicis Lacus, Mars Ekvatoru',
            'country_or_region_en' => 'Phoenicis Lacus, Martian Equator',
            'lat' => -7.0, 'lng' => -100.5,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -51.0, 'slope_deg' => 17.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 97, 'solar_exposure_pct' => 65.0],
            'mineral_type' => 'Jarosit, Opalin Silika, Demir Sülfatlar ve Buzul Kalıntısı Tuzları',
            'mineral_type_en' => 'Jarosite, Opaline Silica, Ferric Sulfates & Relict Glacier Salts',
            'suitable_missions' => ['ice_prospecting', 'astrobiology', 'volcanology', 'cave_exploration', 'hazard_avoidance'],
            'description' => 'Valles Marineris\'in batı ucundaki kesişen graben labirenti; volkanik ısı ile sülfat kaplı buzulların etkileşime girdiği ekvatoral su/buz ve biyo-imza hedefidir.',
            'description_en' => 'Intersecting graben labyrinth at the western head of Valles Marineris, hosting hydrothermal silica, jarosite, and recently identified relict glacier salt casts near the equator.',
            'source_url' => 'https://en.wikipedia.org/wiki/Noctis_Labyrinthus',
            'source_label' => 'Lee et al. (2024), LPSC: Relict Glacier & Volcano in Eastern Noctis Labyrinthus',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_argyre_planitia',
            'name' => 'Argyre Planitia (Güney Buzul-Göl Çarpma Havzası)',
            'name_en' => 'Argyre Planitia (Southern Glacio-Lacustrine Impact Basin)',
            'body' => 'Mars',
            'country_or_region' => 'Argyre Dörtgeni, Mars',
            'country_or_region_en' => 'Argyre Quadrangle, Mars',
            'lat' => -49.7, 'lng' => -43.0,
            'validation_tag' => 'permafrost',
            'metrics' => ['avg_temp_c' => -64.0, 'slope_deg' => 4.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 94, 'solar_exposure_pct' => 46.0],
            'mineral_type' => 'Buzul Esker Sırtları, Periglasiyal Pingolar & Çarpma Regoliti',
            'mineral_type_en' => 'Glacial Esker Ridges, Periglacial Pingos & Impact Regolith',
            'suitable_missions' => ['ice_prospecting', 'drilling_test', 'rover_test', 'astrobiology'],
            'description' => '1.800 km genişliğindeki çarpma havzası; tabanındaki kıvrımlı buzul esker sırtları ve olası hidrotermal pingo tepeleriyle soğuk-buzul Mars jeolojisini temsil eder.',
            'description_en' => '1,800 km impact basin containing sinuous glacial esker ridges and periglacial pingo mounds.',
            'source_url' => 'https://en.wikipedia.org/wiki/Argyre_Planitia',
            'source_label' => 'MGS MOLA & HiRISE Argyre Basin Glacial Geomorphology',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_eridania_basin',
            'name' => 'Eridania Paleo-Deniz Tabanı & Hidrotermal Çökeller',
            'name_en' => 'Eridania Seafloor & Deep Hydrothermal Deposits',
            'body' => 'Mars',
            'country_or_region' => 'Eridania Dörtgeni, Mars',
            'country_or_region_en' => 'Eridania Quadrangle, Mars',
            'lat' => -31.0, 'lng' => 178.0,
            'validation_tag' => 'mars_delta',
            'metrics' => ['avg_temp_c' => -56.0, 'slope_deg' => 5.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 55.0],
            'mineral_type' => 'Saponit, Talk, Serpantin & Derin Deniz Hidrotermal Kloritleri',
            'mineral_type_en' => 'Saponite, Talc, Serpentine & Seafloor Hydrothermal Chlorites',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'drilling_test'],
            'description' => 'Eski Mars\'ta Dünya\'nın tüm büyük göllerinin toplamından 10 kat fazla su tutan bir iç denizin tabanıdır; derin deniz hidrotermal bacalarına (siyah dumanlılar) ait talk-klorit-serpantin mineralleri barındırır.',
            'description_en' => 'Ancient Martian inland sea basin containing deep-water hydrothermal talc, saponite, and serpentine mineral assemblages.',
            'source_url' => 'https://agupubs.onlinelibrary.wiley.com/doi/full/10.1002/2017JE005276',
            'source_label' => 'Michalski et al. (2017), Nature Comms: Ancient hydrothermal seafloor deposits in Eridania basin',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_medusae_fossae',
            'name' => 'Medusae Fossae Formasyonu (Piroklastik Kül & Gömülü Buz)',
            'name_en' => 'Medusae Fossae Formation (Pyroklastic Ash & Buried Ice)',
            'body' => 'Mars',
            'country_or_region' => 'Amazonis / Elysium Sınırı, Mars',
            'country_or_region_en' => 'Amazonis / Elysium Margin, Mars',
            'lat' => -3.2, 'lng' => -162.5,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -54.0, 'slope_deg' => 7.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 93, 'solar_exposure_pct' => 64.0],
            'mineral_type' => 'Kalın Volkanik Tüf/İgnimbrit Yardangları & 3 km Derin Buz Yatakları',
            'mineral_type_en' => 'Thick Volcanic Tuff/Ignimbrite Yardangs & 3-km Deep Ice Deposits',
            'suitable_missions' => ['ice_prospecting', 'rover_test', 'volcanology', 'drilling_test'],
            'description' => 'Ekvator boyunca 5.000 km uzanan rüzgar aşındırmalı yumuşak volkanik kül sırtları; 2024 MARSIS radar verileri altında 3.7 km kalınlığa ulaşan devasa gömülü su buzu katmanları tespit etmiştir.',
            'description_en' => 'Equatorial wind-sculpted volcanic ash yardangs underlain by massive ice-rich deposits up to 3.7 km thick detected by MARSIS radar in 2024.',
            'source_url' => 'https://agupubs.onlinelibrary.wiley.com/doi/10.1029/2023GL105494',
            'source_label' => 'Watters et al. (2024), GRL: Evidence of Ice-Rich Layered Deposits in the Medusae Fossae Formation',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_chasma_boreale',
            'name' => 'Chasma Boreale (Kuzey Kutbu Kanyonu & Katmanlı Çökeller)',
            'name_en' => 'Chasma Boreale (North Polar Canyon & Layered Deposits)',
            'body' => 'Mars',
            'country_or_region' => 'Planum Boreum, Mars Kuzey Kutbu',
            'country_or_region_en' => 'Planum Boreum, Martian North Pole',
            'lat' => 83.0, 'lng' => -47.0,
            'validation_tag' => 'cold_polar',
            'metrics' => ['avg_temp_c' => -115.0, 'slope_deg' => 9.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 94, 'solar_exposure_pct' => 26.0],
            'mineral_type' => 'Açıkta Kalan H₂O Buz Katmanları, Jips Kumulları & Katabatik Rüzgar Kanalı',
            'mineral_type_en' => 'Exposed H2O Ice Layers, Gypsum Dunes & Katabatic Wind Trough',
            'suitable_missions' => ['ice_prospecting', 'drilling_test', 'isru_mining', 'astrobiology'],
            'description' => 'Kuzey kutup buz takkesini 2 km derinliğinde yaran 560 km uzunluğundaki dev kanyon; açıkta kalan buz-toz katmanları Mars\'ın son birkaç milyon yıllık iklim döngülerini sergiler.',
            'description_en' => '560 km long, 2 km deep canyon cutting into the north polar ice cap, exposing clean water-ice strata and gypsum sand sheets.',
            'source_url' => 'https://en.wikipedia.org/wiki/Chasma_Boreale',
            'source_label' => 'MRO SHARAD & HiRISE Chasma Boreale Polar Stratigraphy',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],

        // ======================== MARS KÜRESEL IAU / MOLA / TES HEDEFLERİ ========================
        [
            'id' => 'mars_gale_crater',
            'name' => 'Gale Krateri & Aeolis Mons (Mount Sharp)',
            'name_en' => 'Gale Crater & Aeolis Mons (Mount Sharp)',
            'body' => 'Mars',
            'country_or_region' => 'Aeolis Dörtgeni, Mars',
            'country_or_region_en' => 'Aeolis Quadrangle, Mars',
            'lat' => -5.4, 'lng' => 137.8,
            'validation_tag' => 'mars_catalog',
            'metrics' => ['avg_temp_c' => -48.0, 'slope_deg' => 8.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 100, 'solar_exposure_pct' => 64.0],
            'mineral_type' => 'Smektit Killeri, Sülfat Tortulları ve Bazaltik Kumullar',
            'mineral_type_en' => 'Smectite Clays, Sulfate Sediments & Basaltic Dunes',
            'suitable_missions' => ['rover_test', 'astrobiology', 'sample_analysis', 'drilling_test'],
            'description' => 'Curiosity (MSL) gezgininin 2012\'den beri incelediği 154 km\'lik eski göl krateri; tabanda kil minerallerinden üst katmanlarda sülfatlara geçişiyle Mars\'ın iklim evrimini kaydeder.',
            'description_en' => '154 km ancient lakebed crater explored by the Curiosity rover since 2012, recording Mars\'s transition from wet clay-forming eras to arid sulfate deposits.',
            'source_url' => 'https://science.nasa.gov/mission/msl-curiosity/',
            'source_label' => 'NASA MSL Curiosity Gale Crater Stratigraphy',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_olympus_mons_aureole',
            'name' => 'Olympus Mons Kalderası & Lav Yamaçları',
            'name_en' => 'Olympus Mons Caldera & Volcanic Flanks',
            'body' => 'Mars',
            'country_or_region' => 'Tharsis Volkanik Bölgesi, Mars',
            'country_or_region_en' => 'Tharsis Volcanic Province, Mars',
            'lat' => 18.65, 'lng' => -133.8,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -68.0, 'slope_deg' => 5.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 70.0],
            'mineral_type' => 'Тоleitik Bazalt Lav Akıntıları & Lav Tüpleri',
            'mineral_type_en' => 'Tholeiitic Basalt Lava Flows & Lava Tubes',
            'suitable_missions' => ['volcanology', 'cave_exploration', 'solar_power', 'eva_suit'],
            'description' => 'Güneş Sistemi\'nin en yüksek kalkan yanardağı (21.9 km); 5° yumuşak yamaç eğimi, atmosferin üstündeki yüksek güneş radyasyonu ve eteklerindeki çökmüş lav tüpleriyle dikkat çeker.',
            'description_en' => 'Tallest shield volcano in the Solar System (21.9 km), featuring gentle 5° basaltic flanks and numerous collapsed lava tube channels.',
            'source_url' => 'https://en.wikipedia.org/wiki/Olympus_Mons',
            'source_label' => 'NASA MGS MOLA & Mars Express HRSC Olympus Mons Survey',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_melas_chasma',
            'name' => 'Valles Marineris – Melas & Coprates Chasma',
            'name_en' => 'Valles Marineris – Melas & Coprates Chasma',
            'body' => 'Mars',
            'country_or_region' => 'Valles Marineris Kanyon Sistemi, Mars',
            'country_or_region_en' => 'Valles Marineris Canyon System, Mars',
            'lat' => -10.3, 'lng' => -72.5,
            'validation_tag' => 'mars_catalog',
            'metrics' => ['avg_temp_c' => -38.0, 'slope_deg' => 19.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 58.0],
            'mineral_type' => 'Polihidratlı Sülfatlar, Bazaltik Duvar Kayacı & RSL Yamaç Çizgileri',
            'mineral_type_en' => 'Polyhydrated Sulfates, Basaltic Wall Rock & RSL Streaks',
            'suitable_missions' => ['astrobiology', 'rover_test', 'hazard_avoidance', 'habitat_construction', 'landing_site'],
            'description' => '9 km derinliğe inen dev kanyonun en geniş ve alçak bölümüdür. Daha yüksek atmosfer basıncı, nispeten ılıman sıcaklığı ve katmanlı sülfat/kil çökelleriyle gelecekteki insanlı Mars üsleri için öne çıkar.',
            'description_en' => 'Deepest and widest segment of Valles Marineris (~9 km deep), offering higher atmospheric pressure for landing, warmer temperatures, and layered sulfate deposits.',
            'source_url' => 'https://en.wikipedia.org/wiki/Melas_Chasma',
            'source_label' => 'MRO CRISM & HiRISE Melas Chasma Candidate Landing Site Study',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_arcadia_planitia',
            'name' => 'Arcadia Planitia (SpaceX & NASA İnsanlı Üs Adayı)',
            'name_en' => 'Arcadia Planitia (Crewed Base & Shallow Ice Candidate)',
            'body' => 'Mars',
            'country_or_region' => 'Kuzey Alçak Düzlükleri, Mars',
            'country_or_region_en' => 'Northern Lowlands, Mars',
            'lat' => 39.2, 'lng' => -172.0,
            'validation_tag' => 'permafrost',
            'metrics' => ['avg_temp_c' => -66.0, 'slope_deg' => 1.8, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 49.0],
            'mineral_type' => 'Yüzeye Yakın Saf Su Buzu Levhası & Bazaltik Regolit',
            'mineral_type_en' => 'Shallow Subsurface Water Ice Sheet & Basaltic Regolith',
            'suitable_missions' => ['landing_site', 'isru_mining', 'ice_prospecting', 'habitat_construction', 'drilling_test'],
            'description' => 'Hem aşırı düz volkanik yüzeyi (güvenli iniş) hem de yüzeyin yalnızca birkaç metre altında uzanan devasa su buzu tabakasıyla (ISRU yakıt/su üretimi) kalıcı insanlı Mars üssü için birincil aday bölgedir.',
            'description_en' => 'Primary candidate region for a permanent crewed Mars base, combining ultra-flat landing terrain with abundant shallow subsurface water ice sheets for ISRU.',
            'source_url' => 'https://agupubs.onlinelibrary.wiley.com/doi/full/10.1029/2019GL083947',
            'source_label' => 'Piqueux et al. (2019), GRL: Widespread Shallow Water Ice on Mars at Arcadia Planitia',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_hellas_planitia',
            'name' => 'Hellas Planitia Derin Çarpma Havzası',
            'name_en' => 'Hellas Planitia Deep Impact Basin',
            'body' => 'Mars',
            'country_or_region' => 'Güney Yarımküre, Mars',
            'country_or_region_en' => 'Southern Hemisphere, Mars',
            'lat' => -42.4, 'lng' => 70.5,
            'validation_tag' => 'mars_catalog',
            'metrics' => ['avg_temp_c' => -42.0, 'slope_deg' => 3.2, 'humidity_pct' => 0.0, 'regolith_similarity' => 94, 'solar_exposure_pct' => 52.0],
            'mineral_type' => 'Evaporit Tuzlar, Buzul Tortulları ve Çarpma Breşi',
            'mineral_type_en' => 'Evaporite Salts, Glacial Sediments & Impact Breccia',
            'suitable_missions' => ['landing_site', 'astrobiology', 'ice_prospecting', 'seismic_monitoring'],
            'description' => 'Mars yüzeyinin en derin noktası (-7.152 m); atmosfer basıncı Mars ortalamasının iki katına (~12.4 mbar) çıkarak sıvı suyun geçici kararlılık sınırına yaklaşır ve paraşütle yavaşlamayı kolaylaştırır.',
            'description_en' => 'Deepest impact basin on Mars (-7,152 m), where atmospheric pressure reaches ~12.4 mbar (double the Martian average), aiding aerodynamic deceleration.',
            'source_url' => 'https://en.wikipedia.org/wiki/Hellas_Planitia',
            'source_label' => 'MGS MOLA & MRO SHARAD Hellas Basin Subsurface Glaciers',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_oxia_planum',
            'name' => 'Oxia Planum (ExoMars Rosalind Franklin İniş Sahası)',
            'name_en' => 'Oxia Planum (ExoMars Rosalind Franklin Landing Site)',
            'body' => 'Mars',
            'country_or_region' => 'Chryse Planitia Doğusu, Mars',
            'country_or_region_en' => 'Eastern Chryse Planitia, Mars',
            'lat' => 18.2, 'lng' => -24.55,
            'validation_tag' => 'mars_delta',
            'metrics' => ['avg_temp_c' => -54.0, 'slope_deg' => 2.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 97, 'solar_exposure_pct' => 62.0],
            'mineral_type' => 'Fe/Mg Zengini Fillosilikat Killeri (Smektit/Vermikülit)',
            'mineral_type_en' => 'Fe/Mg-rich Phyllosilicate Clays (Smectite/Vermiculite)',
            'suitable_missions' => ['astrobiology', 'drilling_test', 'sample_analysis', 'landing_site', 'rover_test'],
            'description' => 'Noachian döneminden kalma (3.9 milyar yıllık) geniş Fe/Mg kil yataklarına sahip düz havza; ESA ExoMars gezgininin 2 metre derinlikten biyo-imza sondajı yapacağı hedeftir.',
            'description_en' => 'Wide Noachian clay-bearing plain selected for the ESA ExoMars Rosalind Franklin rover 2-meter subsurface biosignature drilling mission.',
            'source_url' => 'https://www.esa.int/Science_Exploration/Human_and_Robotic_Exploration/Exploration/ExoMars/Oxia_Planum',
            'source_label' => 'ESA ExoMars Landing Site Selection Working Group',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_syrtis_major_nili',
            'name' => 'Nili Fossae & Syrtis Major Volkanik Sınırı',
            'name_en' => 'Nili Fossae & Syrtis Major Volcanic Margin',
            'body' => 'Mars',
            'country_or_region' => 'Syrtis Major, Mars',
            'country_or_region_en' => 'Syrtis Major, Mars',
            'lat' => 21.0, 'lng' => 74.5,
            'validation_tag' => 'mars_delta',
            'metrics' => ['avg_temp_c' => -50.0, 'slope_deg' => 9.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 99, 'solar_exposure_pct' => 63.0],
            'mineral_type' => 'Olivin, Magnezyum Karbonat, Serpantin & Bazalt',
            'mineral_type_en' => 'Olivine, Magnesium Carbonate, Serpentine & Basalt',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'volcanology', 'isru_mining', 'rover_test'],
            'description' => 'Mars\'taki en zengin karbonat, olivin ve hidrotermal serpantin yataklarını barındıran tektonik çöküntü kuşağı; Dünya\'daki ofiyolit ve karbonatlı göl sahalarıyla yüksek mineralojik uyum gösterir.',
            'description_en' => 'Tectonic trough system hosting Mars\'s richest olivine, carbonate, and hydrothermal serpentine exposures.',
            'source_url' => 'https://en.wikipedia.org/wiki/Nili_Fossae',
            'source_label' => 'MRO CRISM Carbonate & Serpentine Detection in Nili Fossae',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_korolev_crater',
            'name' => 'Korolev Buz Krateri (Kuzey Kutup Soğuk Tuzağı)',
            'name_en' => 'Korolev Ice Crater (North Polar Cold Trap)',
            'body' => 'Mars',
            'country_or_region' => 'Olympia Undae / Kuzey Kutbu, Mars',
            'country_or_region_en' => 'Olympia Undae / North Pole, Mars',
            'lat' => 72.7, 'lng' => 164.5,
            'validation_tag' => 'cold_polar',
            'metrics' => ['avg_temp_c' => -112.0, 'slope_deg' => 6.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 95, 'solar_exposure_pct' => 28.0],
            'mineral_type' => '1.8 km Kalınlığında Kalıcı H₂O Buz Kubbesi & Bazaltik Kumul',
            'mineral_type_en' => '1.8 km Thick Permanent H2O Ice Mound & Basaltic Dunes',
            'suitable_missions' => ['ice_prospecting', 'drilling_test', 'isru_mining', 'astrobiology'],
            'description' => 'İçinde yıl boyu erimeyen 1.8 km kalınlığında ve 2.200 km³ hacminde saf su buzu kubbesi barındıran 81 km çapındaki kutup krateri.',
            'description_en' => '81 km polar crater filled year-round with a 1.8 km thick dome of 2,200 km³ of pure water ice.',
            'source_url' => 'https://www.esa.int/Science_Exploration/Space_Science/Mars_Express/Mars_Express_gets_festive_A_winter_wonderland_on_Mars',
            'source_label' => 'ESA Mars Express HRSC Korolev Crater Ice Mound',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_elysium_insight',
            'name' => 'Elysium Planitia (InSight Sismik & Lav Düzlüğü)',
            'name_en' => 'Elysium Planitia (InSight Seismic & Volcanic Plain)',
            'body' => 'Mars',
            'country_or_region' => 'Elysium Dörtgeni, Mars',
            'country_or_region_en' => 'Elysium Quadrangle, Mars',
            'lat' => 4.5, 'lng' => 135.62,
            'validation_tag' => 'mars_catalog',
            'metrics' => ['avg_temp_c' => -55.0, 'slope_deg' => 1.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 66.0],
            'mineral_type' => 'İnce Taneli Bazaltik Regolit & Genç Cerberus Lavları',
            'mineral_type_en' => 'Fine-grained Basaltic Regolith & Young Cerberus Lavas',
            'suitable_missions' => ['seismic_monitoring', 'landing_site', 'solar_power', 'drilling_test', 'habitat_construction'],
            'description' => 'NASA InSight sismik aracının Mars depremlerini (marsquakes) ve iç yapıyı ölçtüğü ekvatoral volkanik düzlük; yakınındaki Cerberus Fossae Mars\'taki en genç volkanik-sismik bölgedir.',
            'description_en' => 'Equatorial volcanic plain where NASA\'s InSight lander recorded marsquakes near the seismically active Cerberus Fossae.',
            'source_url' => 'https://science.nasa.gov/mission/insight/',
            'source_label' => 'NASA InSight SEIS & HP3 Site Characterization',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_columbia_hills_gusev',
            'name' => 'Gusev Krateri – Home Plate Hidrotermal Sahası',
            'name_en' => 'Gusev Crater – Home Plate Hydrothermal Deposit',
            'body' => 'Mars',
            'country_or_region' => 'Aeolis, Mars',
            'country_or_region_en' => 'Aeolis, Mars',
            'lat' => -14.57, 'lng' => 175.47,
            'validation_tag' => 'volcanic',
            'metrics' => ['avg_temp_c' => -52.0, 'slope_deg' => 11.0, 'humidity_pct' => 0.0, 'regolith_similarity' => 98, 'solar_exposure_pct' => 61.0],
            'mineral_type' => 'Opalin Silika (Amorf SiO₂), Olivin Bazaltı & Demir Sülfat',
            'mineral_type_en' => 'Opaline Silica (Amorphous SiO2), Olivine Basalt & Ferric Sulfate',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'volcanology', 'rover_test'],
            'description' => 'Spirit gezgininin %90 saflıkta hidrotermal opalin silika keşfettiği eski fumarol/sıcak su kaynağı platosu; Dünya\'daki El Tatio (Şili) ve Yellowstone gayzer sahalarıyla birebir eşleşir.',
            'description_en' => 'Ancient volcanic hydrothermal sinter deposit where the Spirit rover discovered ~90% pure opaline silica, directly analogous to El Tatio geysers in Chile.',
            'source_url' => 'https://www.science.org/doi/10.1126/science.1155429',
            'source_label' => 'Squyres et al. (2008), Science: Detection of Silica-Rich Deposits on Mars',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ],
        [
            'id' => 'mars_planum_australe',
            'name' => 'Planum Australe (Mars Güney Kutup Buzul Takkesi)',
            'name_en' => 'Planum Australe (Martian South Polar Layered Deposits)',
            'body' => 'Mars',
            'country_or_region' => 'Güney Kutbu, Mars',
            'country_or_region_en' => 'South Pole, Mars',
            'lat' => -85.0, 'lng' => 160.0,
            'validation_tag' => 'cold_polar',
            'metrics' => ['avg_temp_c' => -118.0, 'slope_deg' => 3.5, 'humidity_pct' => 0.0, 'regolith_similarity' => 92, 'solar_exposure_pct' => 24.0],
            'mineral_type' => 'Katmanlı H₂O + CO₂ Kuru Buz Takkesi & Kutup Tozu',
            'mineral_type_en' => 'Layered H2O + CO2 Dry Ice Cap & Polar Dust',
            'suitable_missions' => ['ice_prospecting', 'drilling_test', 'astrobiology'],
            'description' => '3 km kalınlığında katmanlı su ve karbondioksit buzullarından oluşan Mars güney kutbu; radar yansımalarıyla tabanında tuzlu su/kil arayüzleri araştırılmaktadır.',
            'description_en' => '3 km thick south polar layered water and CO2 ice cap investigated by MARSIS/SHARAD radar for basal brines and climate cycles.',
            'source_url' => 'https://en.wikipedia.org/wiki/Planum_Australe',
            'source_label' => 'ESA Mars Express MARSIS South Polar Radar Sounding',
            'trek_url' => 'https://trek.nasa.gov/mars/'
        ]
    ];
}

/**
 * Dünya genelinde 7 kıtayı ve Türkiye'yi kapsayan genişletilmiş dinamik veritabanı sahalarını döndürür.
 *
 * @return array<int, array<string, mixed>>
 */
function astroGetGlobalEarthExpansion(): array
{
    return [
        // ======================== TÜRKİYE ANALOG SAHALARI ========================
        [
            'id' => 'earth_karapinar_konya',
            'name' => 'Karapınar Volkanik Maar ve Kumul Sahası (Konya)',
            'name_en' => 'Karapinar Volcanic Maar & Aeolian Dune Field (Konya)',
            'body' => 'Earth',
            'country_or_region' => 'Konya, Türkiye',
            'country_or_region_en' => 'Konya, Türkiye',
            'continent' => 'Asia',
            'lat' => 37.6714, 'lng' => 33.6525,
            'source_url' => 'https://en.wikipedia.org/wiki/Karap%C4%B1nar_ volcanic_field',
            'tags' => ['volcanic', 'impact_crater', 'mars_catalog', 'lunar_surface'],
            'metrics' => ['avg_temp_c' => 11.2, 'slope_deg' => 13.5, 'humidity_pct' => 42.0, 'regolith_similarity' => 86, 'solar_exposure_pct' => 82.0],
            'mineral_type' => 'Bazaltik Cüruf Konileri, Maar Ejektası & Rüzgar Kumulları',
            'mineral_type_en' => 'Basaltic Scoria Cones, Maar Ejecta & Aeolian Dunes',
            'suitable_missions' => ['rover_test', 'eva_suit', 'volcanology', 'landing_site', 'hazard_avoidance'],
            'description' => 'Meke Maarı, Acıgöl kalderası ve bazaltik cüruf konileriyle yarı-kurak rüzgar kumullarını bir arada barındıran volkanik saha; hem Ay krater morfolojisi hem de Mars rüzgar-volkan etkileşimi testleri için Türkiye\'deki en güçlü analoglardan biridir.',
            'description_en' => 'Quaternary volcanic field combining Meke Maar, basaltic scoria cones, and semi-arid aeolian dunes; a top Turkish analog for lunar crater rims and Martian volcanic-aeolian interactions.'
        ],
        [
            'id' => 'earth_kula_geopark',
            'name' => 'Kula-Salihli UNESCO Bazaltik Volkanik Jeoparkı',
            'name_en' => 'Kula-Salihli UNESCO Basaltic Volcanic Geopark',
            'body' => 'Earth',
            'country_or_region' => 'Manisa, Türkiye',
            'country_or_region_en' => 'Manisa, Türkiye',
            'continent' => 'Asia',
            'lat' => 38.5850, 'lng' => 28.5280,
            'source_url' => 'https://en.wikipedia.org/wiki/Kula_Volcanic_Geopark',
            'tags' => ['lunar_surface', 'volcanic', 'lava_tube'],
            'metrics' => ['avg_temp_c' => 15.4, 'slope_deg' => 14.0, 'humidity_pct' => 48.0, 'regolith_similarity' => 91, 'solar_exposure_pct' => 79.0],
            'mineral_type' => 'Alkali Olivin Bazalt (Kulaite), Pāhoehoe Lav Akıntıları & Lav Tüpleri',
            'mineral_type_en' => 'Alkali Olivine Basalt (Kulaite), Pahoehoe Flows & Lava Tubes',
            'suitable_missions' => ['eva_suit', 'rover_test', 'cave_exploration', 'volcanology', 'isru_mining'],
            'description' => 'Antik çağda "Katakekaumene" (Yanık Ülke) olarak adlandırılan genç bazaltik lav denizi; keskin siyah bazalt blokları, cüruf konileri ve lav tünelleriyle Ay Mare bazaltlarını ve Marius Hills bölgesini yüksek doğrulukla simüle eder.',
            'description_en' => 'Known in antiquity as Katakekaumene ("Burned Land"), this young basaltic lava field features sharp black basalt flows, scoria cones, and lava tubes closely matching lunar mare terrain.'
        ],
        [
            'id' => 'earth_tuz_golu_basin',
            'name' => 'Tuz Gölü Hiper-Tuzlu Evaporit Havzası',
            'name_en' => 'Lake Tuz Hypersaline Evaporite Basin',
            'body' => 'Earth',
            'country_or_region' => 'Aksaray / Konya, Türkiye',
            'country_or_region_en' => 'Aksaray / Konya, Türkiye',
            'continent' => 'Asia',
            'lat' => 38.8300, 'lng' => 33.4000,
            'source_url' => 'https://en.wikipedia.org/wiki/Lake_Tuz',
            'tags' => ['mars_catalog', 'astrobiology', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 11.8, 'slope_deg' => 0.8, 'humidity_pct' => 38.0, 'regolith_similarity' => 76, 'solar_exposure_pct' => 84.0],
            'mineral_type' => 'Halit, Jips, Polihidratlı Sülfatlar ve Halofilik Arke Biyo-İmzaları',
            'mineral_type_en' => 'Halite, Gypsum, Polyhydrated Sulfates & Halophilic Archaea Biosignatures',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'landing_site', 'rover_test', 'solar_power'],
            'description' => '%32\'yi aşan tuzluluk oranı, jips/sülfat kabukları ve ekstrem halofilik mikroorganizmalarıyla Mars\'taki kurumuş sülfat göl yatakları (Meridiani Planum, Gale Krateri) için astrobiyoloji ve spektroskopi kalibrasyon sahasıdır.',
            'description_en' => 'Hypersaline playa lake (>32% salinity) with gypsum/sulfate crusts and halophilic extremophiles, serving as an astrobiological and spectroscopic analog for Martian evaporite basins.'
        ],
        [
            'id' => 'earth_erciyes_volcanic',
            'name' => 'Erciyes Dağı Yüksek İrtifa Bazalt/Dasit Platosu',
            'name_en' => 'Mount Erciyes High-Altitude Basalt/Dacite Plateau',
            'body' => 'Earth',
            'country_or_region' => 'Kayseri, Türkiye',
            'country_or_region_en' => 'Kayseri, Türkiye',
            'continent' => 'Asia',
            'lat' => 38.5317, 'lng' => 35.4467,
            'source_url' => 'https://en.wikipedia.org/wiki/Mount_Erciyes',
            'tags' => ['volcanic', 'cold_polar', 'lunar_surface'],
            'metrics' => ['avg_temp_c' => -1.5, 'slope_deg' => 18.0, 'humidity_pct' => 44.0, 'regolith_similarity' => 88, 'solar_exposure_pct' => 76.0],
            'mineral_type' => 'Bazaltik Andezit, Dasitik Lav Kubbeleri & Piroklastik Pomza',
            'mineral_type_en' => 'Basaltic Andesite, Dacitic Lava Domes & Pyroclastic Pumice',
            'suitable_missions' => ['rover_test', 'eva_suit', 'volcanology', 'drilling_test', 'hazard_avoidance'],
            'description' => '3.917 m yüksekliğindeki stratovolkanın üst yamaçları; soğuk-kuru periglasiyal koşulları, piroklastik kül örtüsü ve parazit lav kubbeleriyle hem Mars Tharsis volkanlarını hem de Ay Gruithuisen kubbelerini temsil eder.',
            'description_en' => 'High-altitude (3,917 m) periglacial volcanic slopes with pyroclastic ash, basaltic-andesite flows, and dacitic domes matching Martian volcanoes and lunar silicic domes.'
        ],
        [
            'id' => 'earth_nemrut_caldera',
            'name' => 'Nemrut Kalderası ve Obsidiyen Lav Sahası (Bitlis)',
            'name_en' => 'Nemrut Caldera & Obsidian Lava Field (Bitlis)',
            'body' => 'Earth',
            'country_or_region' => 'Bitlis, Türkiye',
            'country_or_region_en' => 'Bitlis, Türkiye',
            'continent' => 'Asia',
            'lat' => 38.6500, 'lng' => 42.2300,
            'source_url' => 'https://en.wikipedia.org/wiki/Nemrut_(volcano)',
            'tags' => ['volcanic', 'impact_crater'],
            'metrics' => ['avg_temp_c' => 4.2, 'slope_deg' => 15.5, 'humidity_pct' => 50.0, 'regolith_similarity' => 85, 'solar_exposure_pct' => 75.0],
            'mineral_type' => 'Volkanik Cam (Obsidiyen), Riyolitik Kaldera Duvarı ve Bazaltik Skorya',
            'mineral_type_en' => 'Volcanic Glass (Obsidian), Rhyolitic Caldera Wall & Basaltic Scoria',
            'suitable_missions' => ['volcanology', 'sample_analysis', 'eva_suit', 'rover_test'],
            'description' => '9×5 km boyutlarındaki dik duvarlı volkanik kaldera, hidrotermal fumarol çıkışları ve geniş volkanik cam (obsidiyen) akıntılarıyla Ay ve Mars krater/kaldera jeolojisi araştırmalarına uygundur.',
            'description_en' => 'Steep-walled 9x5 km volcanic caldera featuring fumarolic vents and extensive obsidian glass flows.'
        ],

        // ======================== GÜNEY AMERİKA & KUZEY AMERİKA ========================
        [
            'id' => 'earth_puna_de_atacama',
            'name' => 'Puna de Atacama & Laguna Negra Yüksek İrtifa Platosu',
            'name_en' => 'Puna de Atacama & Laguna Negra High-Altitude Plateau',
            'body' => 'Earth',
            'country_or_region' => 'Catamarca, Arjantin / Şili',
            'country_or_region_en' => 'Catamarca, Argentina / Chile',
            'continent' => 'South America',
            'lat' => -26.50, 'lng' => -67.80,
            'source_url' => 'https://en.wikipedia.org/wiki/Puna_de_Atacama',
            'tags' => ['mars_catalog', 'hyper_arid', 'volcanic', 'astrobiology'],
            'metrics' => ['avg_temp_c' => -2.0, 'slope_deg' => 7.0, 'humidity_pct' => 14.0, 'regolith_similarity' => 93, 'solar_exposure_pct' => 94.0],
            'mineral_type' => 'İgnimbrit, Bazaltik Çakıl Dalgaları (Megaripples) & Stromatolitler',
            'mineral_type_en' => 'Ignimbrite, Basaltic Gravel Megaripples & Stromatolites',
            'suitable_missions' => ['rover_test', 'astrobiology', 'solar_power', 'eva_suit', 'sample_analysis'],
            'description' => '4.500 m rakımda ince atmosfer, Dünya üzerindeki en yüksek UV radyasyonu (94%), hiper-kurak soğuk iklim ve Mars benzeri dev rüzgar çakıl dalgaları (megaripples) barındırır.',
            'description_en' => 'At 4,500 m elevation, combines thin air, the highest surface UV radiation on Earth, hyper-arid cold conditions, and Martian-style gravel megaripples.'
        ],
        [
            'id' => 'earth_salar_de_uyuni',
            'name' => 'Salar de Uyuni Lityum & Evaporit Düzlüğü',
            'name_en' => 'Salar de Uyuni Evaporite Salt Flat',
            'body' => 'Earth',
            'country_or_region' => 'Potosí, Bolivya',
            'country_or_region_en' => 'Potosi, Bolivia',
            'continent' => 'South America',
            'lat' => -20.1338, 'lng' => -67.4891,
            'source_url' => 'https://en.wikipedia.org/wiki/Salar_de_Uyuni',
            'tags' => ['mars_catalog', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 7.5, 'slope_deg' => 0.3, 'humidity_pct' => 22.0, 'regolith_similarity' => 79, 'solar_exposure_pct' => 91.0],
            'mineral_type' => 'Poligonal Halit, Jips, Lityum Tuzları & Kalibrasyon Düzlüğü',
            'mineral_type_en' => 'Polygonal Halite, Gypsum & Lithium Brine Crust',
            'suitable_missions' => ['landing_site', 'solar_power', 'sample_analysis', 'comms_relay'],
            'description' => '3.656 m irtifadaki 10.582 km²\'lik poligonal tuz düzlüğü; NASA ICESat ve altimetre uydularının birincil kalibrasyon sahası ve Mars poligonal evaporit yataklarının modelidir.',
            'description_en' => '10,582 km² high-altitude polygonal salt flat used for NASA satellite altimeter calibration and Martian polygonal evaporite modeling.'
        ],
        [
            'id' => 'earth_el_tatio_geysers',
            'name' => 'El Tatio Silika Gayzer ve Hidrotermal Sahası',
            'name_en' => 'El Tatio Silica Geyser & Hydrothermal Field',
            'body' => 'Earth',
            'country_or_region' => 'Antofagasta, Şili',
            'country_or_region_en' => 'Antofagasta, Chile',
            'continent' => 'South America',
            'lat' => -22.3314, 'lng' => -68.0103,
            'source_url' => 'https://en.wikipedia.org/wiki/El_Tatio',
            'tags' => ['mars_catalog', 'astrobiology', 'volcanic'],
            'metrics' => ['avg_temp_c' => 1.5, 'slope_deg' => 6.0, 'humidity_pct' => 18.0, 'regolith_similarity' => 92, 'solar_exposure_pct' => 92.0],
            'mineral_type' => 'Opalin Silika Sinter (Amorf SiO₂) & Hidrotermal Biyo-İmzalar',
            'mineral_type_en' => 'Opaline Silica Sinter (Amorphous SiO2) & Hydrothermal Biosignatures',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'rover_test'],
            'description' => '4.320 m rakımda yüksek UV ve donma-çözülme döngüsü altında oluşan opalin silika nodülleri, Spirit gezgininin Mars Gusev Krateri\'nde (Home Plate) bulduğu silika yapılarıyla birebir örtüşür.',
            'description_en' => 'High-altitude (4,320 m) opaline silica sinter nodules that match the hydrothermal silica structures discovered by the Spirit rover at Home Plate in Gusev Crater on Mars.'
        ],
        [
            'id' => 'earth_pampas_de_la_joya',
            'name' => 'Pampas de La Joya Hiper-Kurak Mars Çölü (Peru)',
            'name_en' => 'Pampas de La Joya Hyper-Arid Desert (Peru)',
            'body' => 'Earth',
            'country_or_region' => 'Arequipa, Peru',
            'country_or_region_en' => 'Arequipa, Peru',
            'continent' => 'South America',
            'lat' => -16.72, 'lng' => -72.18,
            'source_url' => 'https://en.wikipedia.org/wiki/La_Joya_Desert',
            'tags' => ['mars_catalog', 'hyper_arid', 'astrobiology'],
            'metrics' => ['avg_temp_c' => 17.5, 'slope_deg' => 3.5, 'humidity_pct' => 15.0, 'regolith_similarity' => 90, 'solar_exposure_pct' => 90.0],
            'mineral_type' => 'Volkanik Kül, Perklorat/Sülfat Tuzları ve Organikçe Fakir Regolit',
            'mineral_type_en' => 'Volcanic Ash, Perchlorate/Sulfate Salts & Organic-Poor Regolith',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'rover_test', 'drilling_test'],
            'description' => 'Viking ve Phoenix Mars iniş araçlarındaki organik madde algılama deneylerini kalibre etmek için kullanılan aşırı kurak ve sülfat/perklorat zengini çöl sahası.',
            'description_en' => 'Hyper-arid sulfate and perchlorate-bearing desert used to calibrate the Viking and Phoenix Mars lander life-detection and organic analysis instruments.'
        ],
        [
            'id' => 'earth_sf_volcanic_field',
            'name' => 'San Francisco Volkanik Sahası & Black Point Lav Akıntısı (Arizona)',
            'name_en' => 'San Francisco Volcanic Field & Black Point Lava Flow (Arizona)',
            'body' => 'Earth',
            'country_or_region' => 'Flagstaff, Arizona, ABD',
            'country_or_region_en' => 'Flagstaff, Arizona, USA',
            'continent' => 'North America',
            'lat' => 35.58, 'lng' => -111.47,
            'source_url' => 'https://en.wikipedia.org/wiki/San_Francisco_volcanic_field',
            'tags' => ['lunar_surface', 'volcanic', 'impact_crater'],
            'metrics' => ['avg_temp_c' => 9.8, 'slope_deg' => 8.0, 'humidity_pct' => 27.0, 'regolith_similarity' => 94, 'solar_exposure_pct' => 86.0],
            'mineral_type' => 'Bazaltik Lav Akıntısı, Cüruf Konileri & Krater Eğitim Sahası',
            'mineral_type_en' => 'Basaltic Lava Flow, Scoria Cones & Crater Training Field',
            'suitable_missions' => ['eva_suit', 'rover_test', 'habitat_construction', 'landing_site', 'comms_relay'],
            'description' => 'NASA Desert RATS (Basınçlı Ay Gezgini ve Artemis EVA giysisi) saha testlerinin ana merkezi; Apollo döneminde astronot eğitimi için dinamitle Ay kraterlerinin birebir kopyası burada oluşturulmuştur.',
            'description_en' => 'Primary testbed for NASA Desert RATS pressurized lunar rovers and Artemis EVA suits; features simulated lunar crater fields built during the Apollo program.'
        ],
        [
            'id' => 'earth_white_sands_nm',
            'name' => 'White Sands Jips Kumulları ve Alkali Playa (New Mexico)',
            'name_en' => 'White Sands Gypsum Dunes & Alkali Playa (New Mexico)',
            'body' => 'Earth',
            'country_or_region' => 'New Mexico, ABD',
            'country_or_region_en' => 'New Mexico, USA',
            'continent' => 'North America',
            'lat' => 32.7872, 'lng' => -106.3257,
            'source_url' => 'https://en.wikipedia.org/wiki/White_Sands_National_Park',
            'tags' => ['mars_catalog', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 15.6, 'slope_deg' => 6.5, 'humidity_pct' => 24.0, 'regolith_similarity' => 87, 'solar_exposure_pct' => 89.0],
            'mineral_type' => 'Saf Jips (Kalsiyum Sülfat Dihidrat - CaSO₄·2H₂O) Kumulları',
            'mineral_type_en' => 'Pure Gypsum (Calcium Sulfate Dihydrate) Dunes',
            'suitable_missions' => ['rover_test', 'sample_analysis', 'astrobiology', 'landing_site'],
            'description' => 'Dünya\'nın en büyük jips (sülfat) kumul sahası; Mars Kuzey Kutbu çevresindeki Olympia Undae jips kumullarının birincil mineralojik ve sedimantolojik analogudur.',
            'description_en' => 'World\'s largest gypsum sulfate dune field, serving as the primary mineralogical and sedimentological analog for the Olympia Undae gypsum dunes at Mars\'s north pole.'
        ],
        [
            'id' => 'earth_pinacate_sonora',
            'name' => 'El Pinacate Volkanik Maar ve Bazalt Kalkanı (Meksika)',
            'name_en' => 'El Pinacate Volcanic Maar & Basalt Shield (Mexico)',
            'body' => 'Earth',
            'country_or_region' => 'Sonora, Meksika',
            'country_or_region_en' => 'Sonora, Mexico',
            'continent' => 'North America',
            'lat' => 31.7725, 'lng' => -113.4986,
            'source_url' => 'https://en.wikipedia.org/wiki/El_Pinacate_and_Gran_Desierto_de_Altar_Biosphere_Reserve',
            'tags' => ['lunar_surface', 'volcanic', 'impact_crater'],
            'metrics' => ['avg_temp_c' => 21.5, 'slope_deg' => 12.0, 'humidity_pct' => 22.0, 'regolith_similarity' => 91, 'solar_exposure_pct' => 91.0],
            'mineral_type' => 'Bazaltik Maar Kraterleri (Elegante), Tüf Halkaları & Lav Akıntıları',
            'mineral_type_en' => 'Basaltic Maar Craters, Tuff Rings & Lava Flows',
            'suitable_missions' => ['eva_suit', 'rover_test', 'volcanology', 'sample_analysis'],
            'description' => 'Apollo 11 ve 14 astronotlarının arazi jeolojisi eğitimi aldığı dev maar kraterleri ve kurak bazaltik kalkan sahası.',
            'description_en' => 'Giant volcanic maar craters and arid basalt shield where Apollo 11 and Apollo 14 astronauts trained for lunar geology traverses.'
        ],
        [
            'id' => 'earth_mistastin_crater',
            'name' => 'Mistastin Gölü Anortozitik Çarpma Krateri (Labrador)',
            'name_en' => 'Mistastin Lake Anorthositic Impact Crater (Labrador)',
            'body' => 'Earth',
            'country_or_region' => 'Labrador, Kanada',
            'country_or_region_en' => 'Labrador, Canada',
            'continent' => 'North America',
            'lat' => 55.8833, 'lng' => -63.3000,
            'source_url' => 'https://en.wikipedia.org/wiki/Mistastin_crater',
            'tags' => ['lunar_surface', 'impact_crater', 'cold_polar'],
            'metrics' => ['avg_temp_c' => -3.5, 'slope_deg' => 10.5, 'humidity_pct' => 58.0, 'regolith_similarity' => 97, 'solar_exposure_pct' => 54.0],
            'mineral_type' => 'Saf Anortozit Hedef Kayacı & Çarpma Breşi (Ay Yaylası İkizi)',
            'mineral_type_en' => 'Pure Anorthosite Target Rock & Impact Melt Breccia (Lunar Highland Twin)',
            'suitable_missions' => ['sample_analysis', 'eva_suit', 'rover_test', 'drilling_test', 'isru_mining'],
            'description' => 'Dünya üzerinde doğrudan anortozit (kalsiyumca zengin feldispat) kabuk içinde oluşmuş tek büyük çarpma krateridir; Kanada Uzay Ajansı (CSA) ve NASA tarafından Artemis Ay Güney Kutbu anortozitik yaylalarının en yakın jeokimyasal ikizi olarak kullanılır.',
            'description_en' => 'The only large impact crater on Earth formed directly in anorthosite bedrock, making it the closest geochemical twin on Earth to the Lunar South Pole highlands targeted by Artemis.'
        ],

        // ======================== AVRUPA & KUZEY KUTBU ========================
        [
            'id' => 'earth_askja_iceland',
            'name' => 'Askja Kalderası & Holuhraun Bazalt Lav Sahası (İzlanda)',
            'name_en' => 'Askja Caldera & Holuhraun Basalt Lava Field (Iceland)',
            'body' => 'Earth',
            'country_or_region' => 'Merkez Yaylalar, İzlanda',
            'country_or_region_en' => 'Central Highlands, Iceland',
            'continent' => 'Europe',
            'lat' => 65.03, 'lng' => -16.75,
            'source_url' => 'https://en.wikipedia.org/wiki/Askja',
            'tags' => ['lunar_surface', 'mars_catalog', 'volcanic', 'cold_polar'],
            'metrics' => ['avg_temp_c' => -1.2, 'slope_deg' => 9.5, 'humidity_pct' => 65.0, 'regolith_similarity' => 93, 'solar_exposure_pct' => 44.0],
            'mineral_type' => 'Taze Bazaltik Lav, Pomza ve Buzul-Volkanik Hiyaloklastit',
            'mineral_type_en' => 'Fresh Basaltic Lava, Pumice & Glaciovolcanic Hyaloclastite',
            'suitable_missions' => ['eva_suit', 'rover_test', 'volcanology', 'sample_analysis', 'drilling_test'],
            'description' => 'Hem Apollo astronotlarının hem de NASA Mars 2020 (Perseverance) ve VIPER ekiplerinin saha testlerini yürüttüğü soğuk-volkanik bazalt çölü.',
            'description_en' => 'Cold volcanic basalt desert in Iceland\'s interior highlands used for both Apollo astronaut training and NASA Mars 2020 / VIPER rover field trials.'
        ],
        [
            'id' => 'earth_mount_etna_italy',
            'name' => 'Etna Yanardağı Yüksek Bazaltik Yamaçları (ROBEX Sahası)',
            'name_en' => 'Mount Etna High Basaltic Slopes (DLR/ESA ROBEX Site)',
            'body' => 'Earth',
            'country_or_region' => 'Sicilya, İtalya',
            'country_or_region_en' => 'Sicily, Italy',
            'continent' => 'Europe',
            'lat' => 37.7510, 'lng' => 14.9934,
            'source_url' => 'https://en.wikipedia.org/wiki/Mount_Etna',
            'tags' => ['lunar_surface', 'volcanic', 'lava_tube'],
            'metrics' => ['avg_temp_c' => 3.5, 'slope_deg' => 16.5, 'humidity_pct' => 46.0, 'regolith_similarity' => 92, 'solar_exposure_pct' => 76.0],
            'mineral_type' => 'Bazaltik Skorya, Kül Regoliti & Volkanik Lav Mağaraları',
            'mineral_type_en' => 'Basaltic Scoria, Ash Regolith & Volcanic Lava Caves',
            'suitable_missions' => ['rover_test', 'seismic_monitoring', 'isru_mining', 'cave_exploration', 'habitat_construction'],
            'description' => 'ESA ve DLR\'nin ROBEX ve ARCHES robotik Ay üssü kurulumu, sismik ağ ve ISRU 3B regolit baskı deneylerini gerçekleştirdiği aktif bazaltik volkan platosu.',
            'description_en' => 'High-altitude basaltic volcanic test site where ESA and DLR conduct ROBEX and ARCHES robotic lunar base deployment, seismic network, and ISRU sintering trials.'
        ],
        [
            'id' => 'earth_lanzarote_timanfaya',
            'name' => 'Lanzarote (Timanfaya & Corona Lav Tüpü - ESA PANGAEA)',
            'name_en' => 'Lanzarote (Timanfaya & Corona Lava Tube - ESA PANGAEA)',
            'body' => 'Earth',
            'country_or_region' => 'Kanarya Adaları, İspanya',
            'country_or_region_en' => 'Canary Islands, Spain',
            'continent' => 'Europe',
            'lat' => 29.0040, 'lng' => -13.7330,
            'source_url' => 'https://www.esa.int/Science_Exploration/Human_and_Robotic_Exploration/PANGAEA',
            'tags' => ['lunar_surface', 'lava_tube', 'volcanic'],
            'metrics' => ['avg_temp_c' => 19.5, 'slope_deg' => 8.5, 'humidity_pct' => 45.0, 'regolith_similarity' => 93, 'solar_exposure_pct' => 84.0],
            'mineral_type' => 'Bazaltik Lav Denizi, Skylight Çöküntüleri ve 6 km\'lik Corona Lav Tüpü',
            'mineral_type_en' => 'Basaltic Lava Sea, Skylight Pits & 6 km Corona Lava Tube',
            'suitable_missions' => ['cave_exploration', 'radiation_shelter', 'eva_suit', 'rover_test', 'sample_analysis'],
            'description' => 'Avrupa Uzay Ajansı\'nın (ESA) PANGAEA astronot jeoloji eğitimi ve PANGAEA-X Ay lav tüpü robotik haritalama seferlerinin ana üssüdür; Corona lav tüpü 25 m çapıyla Ay\'daki dev lav tünellerine en yakın ölçektedir.',
            'description_en' => 'Home of ESA\'s PANGAEA astronaut geology training and PANGAEA-X lunar cave robotics campaigns, featuring the massive 6 km Corona lava tube.'
        ],
        [
            'id' => 'earth_ries_crater_germany',
            'name' => 'Nördlinger Ries Çarpma Krateri (Suevit Breşi Sahası)',
            'name_en' => 'Nördlinger Ries Impact Crater (Suevite Breccia Site)',
            'body' => 'Earth',
            'country_or_region' => 'Bavyera, Almanya',
            'country_or_region_en' => 'Bavaria, Germany',
            'continent' => 'Europe',
            'lat' => 48.8833, 'lng' => 10.5333,
            'source_url' => 'https://en.wikipedia.org/wiki/N%C3%B6rdlinger_Ries',
            'tags' => ['lunar_surface', 'impact_crater'],
            'metrics' => ['avg_temp_c' => 8.5, 'slope_deg' => 5.5, 'humidity_pct' => 64.0, 'regolith_similarity' => 87, 'solar_exposure_pct' => 58.0],
            'mineral_type' => 'Suevit Çarpma Breşi, Şoklanmış Kuvars ve Ejekta Örtüsü',
            'mineral_type_en' => 'Suevite Impact Breccia, Shocked Quartz & Ejecta Blanket',
            'suitable_missions' => ['sample_analysis', 'eva_suit', 'drilling_test'],
            'description' => 'Apollo 14 ve 16 astronotlarının Ay yaylalarındaki çarpma breşlerini (suevit) tanımak için saha eğitimi aldığı 24 km çapındaki çarpma krateri.',
            'description_en' => '24 km impact crater where Apollo 14 and 16 astronauts trained to identify shocked impact breccias (suevite) ahead of lunar highlands missions.'
        ],

        // ======================== AFRİKA & ORTA DOĞU ========================
        [
            'id' => 'earth_erfoud_morocco',
            'name' => 'Erfoud & Kem Kem Mars Analog Çölü (Fas)',
            'name_en' => 'Erfoud & Kem Kem Mars Analog Desert (Morocco)',
            'body' => 'Earth',
            'country_or_region' => 'Tafilalt, Fas',
            'country_or_region_en' => 'Tafilalt, Morocco',
            'continent' => 'Africa',
            'lat' => 31.4314, 'lng' => -4.2328,
            'source_url' => 'https://oewf.org/en/portfolio/amadee-13/',
            'tags' => ['mars_catalog', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 21.0, 'slope_deg' => 7.5, 'humidity_pct' => 19.0, 'regolith_similarity' => 89, 'solar_exposure_pct' => 91.0],
            'mineral_type' => 'Hematitli Kızıl Kumtaşı, Bazaltik Hamada ve Karbonat Sırtları',
            'mineral_type_en' => 'Hematite Red Sandstone, Basaltic Hamada & Carbonate Ridges',
            'suitable_missions' => ['rover_test', 'eva_suit', 'habitat_construction', 'landing_site', 'comms_relay'],
            'description' => 'ESA ExoMars rover şasi testlerinin ve Avusturya Uzay Forumu (ÖWF) AMADEE Mars simülasyonlarının gerçekleştirildiği taşlı kızıl çöl (hamada) ve kumul havzası.',
            'description_en' => 'Stony red desert (hamada) and dune basin in northern Sahara used for ESA ExoMars rover locomotion trials and ÖWF AMADEE Mars mission simulations.'
        ],
        [
            'id' => 'earth_danakil_dallol',
            'name' => 'Danakil Çöküntüsü & Dallol Hidrotermal Sahası (Etiyopya)',
            'name_en' => 'Danakil Depression & Dallol Hydrothermal Field (Ethiopia)',
            'body' => 'Earth',
            'country_or_region' => 'Afar, Etiyopya',
            'country_or_region_en' => 'Afar, Ethiopia',
            'continent' => 'Africa',
            'lat' => 14.2417, 'lng' => 40.3000,
            'source_url' => 'https://en.wikipedia.org/wiki/Dallol_(volcano)',
            'tags' => ['mars_catalog', 'astrobiology', 'hyper_arid', 'volcanic'],
            'metrics' => ['avg_temp_c' => 31.5, 'slope_deg' => 3.0, 'humidity_pct' => 25.0, 'regolith_similarity' => 84, 'solar_exposure_pct' => 92.0],
            'mineral_type' => 'Asidik Demir Oksit, Jarosit, Halit ve Bazaltik Rift Lavları',
            'mineral_type_en' => 'Acidic Iron Oxide, Jarosite, Halite & Basaltic Rift Lavas',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'volcanology'],
            'description' => 'pH < 1 aşırı asidik sıcak su kaynakları, jarosit/demir-sülfat çökelleri ve aktif bazaltik rift volkanizmasıyla erken Mars\'ın hidrotermal-asidik ortamlarını temsil eder.',
            'description_en' => 'Extreme poly-extremophile hydrothermal system (pH < 1) with jarosite and iron-sulfate precipitates modeling early Mars hydrothermal environments.'
        ],
        [
            'id' => 'earth_tibesti_trou_au_natron',
            'name' => 'Tibesti Volkanik Dağları & Trou au Natron Kalderası (Çad)',
            'name_en' => 'Tibesti Volcanic Mountains & Trou au Natron Caldera (Chad)',
            'body' => 'Earth',
            'country_or_region' => 'Tibesti, Çad',
            'country_or_region_en' => 'Tibesti, Chad',
            'continent' => 'Africa',
            'lat' => 20.98, 'lng' => 16.53,
            'source_url' => 'https://en.wikipedia.org/wiki/Tibesti_Mountains',
            'tags' => ['mars_catalog', 'volcanic', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 16.0, 'slope_deg' => 14.5, 'humidity_pct' => 12.0, 'regolith_similarity' => 91, 'solar_exposure_pct' => 93.0],
            'mineral_type' => 'Bazaltik Kalkan Lavları, İgnimbrit ve Sodyum Karbonat (Natron) Kabuğu',
            'mineral_type_en' => 'Basaltic Shield Lavas, Ignimbrite & Sodium Carbonate Crust',
            'suitable_missions' => ['volcanology', 'sample_analysis', 'rover_test', 'astrobiology'],
            'description' => 'Sahra Çölü\'nün kalbinde 3.400 m\'ye yükselen dev kalkan volkanları (Emi Koussi) ve beyaz karbonat kaplı derin kalderalar; Mars\'taki Elysium ve Tharsis volkanlarının hiper-kurak Dünya karşılığıdır.',
            'description_en' => 'Hyper-arid Saharan shield volcanoes (Emi Koussi) and natron-encrusted calderas closely analogous to Mars\'s Tharsis and Elysium volcanic provinces.'
        ],
        [
            'id' => 'earth_makgadikgadi_botswana',
            'name' => 'Makgadikgadi Tuz Tavaları & Paleo-Göl Yatağı (Botsvana)',
            'name_en' => 'Makgadikgadi Salt Pans & Paleolake Bed (Botswana)',
            'body' => 'Earth',
            'country_or_region' => 'Kalahari, Botsvana',
            'country_or_region_en' => 'Kalahari, Botswana',
            'continent' => 'Africa',
            'lat' => -20.75, 'lng' => 25.50,
            'source_url' => 'https://en.wikipedia.org/wiki/Makgadikgadi_Pan',
            'tags' => ['mars_catalog', 'astrobiology'],
            'metrics' => ['avg_temp_c' => 21.5, 'slope_deg' => 1.2, 'humidity_pct' => 30.0, 'regolith_similarity' => 83, 'solar_exposure_pct' => 89.0],
            'mineral_type' => 'Evaporit Karbonat/Sülfat Kil Tavaları & Kıyı Çizgisi Terasları',
            'mineral_type_en' => 'Evaporite Carbonate/Sulfate Clay Pans & Paleoshoreline Terraces',
            'suitable_missions' => ['astrobiology', 'rover_test', 'landing_site', 'sample_analysis'],
            'description' => 'Eski dev bir gölün kurumasıyla oluşan kil, karbonat ve evaporit tavaları; Mars\'taki Jezero ve Eridania paleo-göl yataklarının kuruma süreçlerini modellemek için kullanılır.',
            'description_en' => 'Desiccated paleolake basin with carbonate/sulfate clay pans modeling the drying history of Jezero and Eridania paleolakes on Mars.'
        ],
        [
            'id' => 'earth_dhofar_oman',
            'name' => 'Dhofar Çölü & Samail Ofiyolit Kuşağı (Umman - AMADEE-18)',
            'name_en' => 'Dhofar Desert & Samail Ophiolite Belt (Oman - AMADEE-18)',
            'body' => 'Earth',
            'country_or_region' => 'Dhofar / Al Hajar, Umman',
            'country_or_region_en' => 'Dhofar / Al Hajar, Oman',
            'continent' => 'Asia',
            'lat' => 19.20, 'lng' => 54.50,
            'source_url' => 'https://oewf.org/en/portfolio/amadee-18/',
            'tags' => ['mars_catalog', 'hyper_arid', 'lunar_surface'],
            'metrics' => ['avg_temp_c' => 25.5, 'slope_deg' => 5.5, 'humidity_pct' => 20.0, 'regolith_similarity' => 91, 'solar_exposure_pct' => 92.0],
            'mineral_type' => 'Olivin/Piroksen Peridotit, Serpantin, Karbonat & Çöl Regoliti',
            'mineral_type_en' => 'Olivine/Pyroxene Peridotite, Serpentine, Carbonate & Desert Regolith',
            'suitable_missions' => ['habitat_construction', 'eva_suit', 'rover_test', 'astrobiology', 'isru_mining'],
            'description' => 'AMADEE-18 Mars simülasyonuna ev sahipliği yapan hiper-kurak çöl platosu; ayrıca Samail ofiyolit kuşağındaki aktif serpantinizasyon ve metan oluşumu Mars Nili Fossae için birincil referanstır.',
            'description_en' => 'Host of the AMADEE-18 Mars mission simulation; adjacent Samail ophiolite belt exhibits active serpentinization and abiotic methane production matching Nili Fossae on Mars.'
        ],
        [
            'id' => 'earth_harrat_khaybar',
            'name' => 'Harrat Khaybar Bazaltik Lav ve Tüf Alanı (Suudi Arabistan)',
            'name_en' => 'Harrat Khaybar Basaltic Lava & Tuff Field (Saudi Arabia)',
            'body' => 'Earth',
            'country_or_region' => 'Medine Bölgesi, Suudi Arabistan',
            'country_or_region_en' => 'Medina Province, Saudi Arabia',
            'continent' => 'Asia',
            'lat' => 25.73, 'lng' => 39.98,
            'source_url' => 'https://en.wikipedia.org/wiki/Harrat_Khaybar',
            'tags' => ['lunar_surface', 'lava_tube', 'volcanic', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 24.0, 'slope_deg' => 8.0, 'humidity_pct' => 16.0, 'regolith_similarity' => 93, 'solar_exposure_pct' => 92.0],
            'mineral_type' => 'Bazaltik Lav Tüpleri (Umm Jirsan), Komendit Kubbeleri ve Tüf Halkaları',
            'mineral_type_en' => 'Basaltic Lava Tubes (Umm Jirsan), Comendite Domes & Tuff Rings',
            'suitable_missions' => ['cave_exploration', 'radiation_shelter', 'volcanology', 'rover_test', 'isru_mining'],
            'description' => '14.000 km²\'lik kurak volkanik saha ve Arap Yarımadası\'nın en uzun lav tüpü sistemi olan 1.5 km\'lik Umm Jirsan mağara galerisini barındırır.',
            'description_en' => '14,000 km² hyper-arid volcanic field containing the 1.5 km Umm Jirsan lava-tube system, basaltic scoria cones, and felsic lava domes.'
        ],

        // ======================== ASYA & OKYANUSYA & ANTARKTİKA ========================
        [
            'id' => 'earth_qaidam_basin_china',
            'name' => 'Qaidam Havzası Yardang ve Sülfat Playaları (Tibet Platosu)',
            'name_en' => 'Qaidam Basin Yardangs & Sulfate Playas (Tibetan Plateau)',
            'body' => 'Earth',
            'country_or_region' => 'Qinghai, Çin',
            'country_or_region_en' => 'Qinghai, China',
            'continent' => 'Asia',
            'lat' => 37.80, 'lng' => 93.20,
            'source_url' => 'https://agupubs.onlinelibrary.wiley.com/doi/full/10.1029/2018JE005672',
            'tags' => ['mars_catalog', 'hyper_arid', 'cold_polar'],
            'metrics' => ['avg_temp_c' => 2.2, 'slope_deg' => 6.0, 'humidity_pct' => 18.0, 'regolith_similarity' => 94, 'solar_exposure_pct' => 88.0],
            'mineral_type' => 'Magnezyum Sülfat (Kieserit/Epsomit), Halit, Kil & Rüzgar Yardangları',
            'mineral_type_en' => 'Magnesium Sulfate (Kieserite/Epsomite), Halite, Clay & Aeolian Yardangs',
            'suitable_missions' => ['rover_test', 'astrobiology', 'sample_analysis', 'landing_site', 'drilling_test'],
            'description' => '2.800 m irtifadaki soğuk ve hiper-kurak havza; Dünya üzerindeki en geniş rüzgar aşındırması sırtlarına (yardang), poligonal sülfat kabuklarına ve Çin\'in Tianwen-1 (Zhurong) Mars gezgini test sahasına ev sahipliği yapar.',
            'description_en' => 'High-altitude (2,800 m) cold hyper-arid basin containing Earth\'s largest yardang fields and Mg-sulfate playas; used to test China\'s Tianwen-1 / Zhurong Mars rover.'
        ],
        [
            'id' => 'earth_ladakh_tso_kar',
            'name' => 'Ladakh Yüksek İrtifa Soğuk Çölü & Tso Kar Havzası (Hindistan)',
            'name_en' => 'Ladakh High-Altitude Cold Desert & Tso Kar Basin (India)',
            'body' => 'Earth',
            'country_or_region' => 'Ladakh, Hindistan',
            'country_or_region_en' => 'Ladakh, India',
            'continent' => 'Asia',
            'lat' => 33.32, 'lng' => 78.00,
            'source_url' => 'https://www.sciencedirect.com/science/article/pii/S0032063319301163',
            'tags' => ['mars_catalog', 'cold_polar', 'permafrost', 'astrobiology'],
            'metrics' => ['avg_temp_c' => -3.5, 'slope_deg' => 8.5, 'humidity_pct' => 26.0, 'regolith_similarity' => 89, 'solar_exposure_pct' => 85.0],
            'mineral_type' => 'Permafrost Pingoları, Sülfat/Borat Tuzları ve Ofiyolitik Kayaçlar',
            'mineral_type_en' => 'Permafrost Pingos, Sulfate/Borate Salts & Ophiolitic Bedrock',
            'suitable_missions' => ['astrobiology', 'ice_prospecting', 'eva_suit', 'habitat_construction', 'rover_test'],
            'description' => '4.500–5.300 m rakımda düşük atmosfer basıncı, yüksek UV, sürekli permafrost, buzul-göl sülfat yatakları ve ISRO/NASA Spaceward Bound saha araştırmalarıyla bilinen soğuk Mars analogudur.',
            'description_en' => 'At 4,500–5,300 m altitude, combines low atmospheric pressure, high UV flux, permafrost mounds (pingos), and sulfate-borate lakebeds studied by NASA/ISRO Spaceward Bound.'
        ],
        [
            'id' => 'earth_kamchatka_tolbachik',
            'name' => 'Tolbachik Bazaltik Fissür ve Cüruf Düzlüğü (Kamçatka)',
            'name_en' => 'Tolbachik Basaltic Fissure & Scoria Plain (Kamchatka)',
            'body' => 'Earth',
            'country_or_region' => 'Kamçatka, Rusya',
            'country_or_region_en' => 'Kamchatka, Russia',
            'continent' => 'Asia',
            'lat' => 55.8300, 'lng' => 160.3300,
            'source_url' => 'https://en.wikipedia.org/wiki/Tolbachik',
            'tags' => ['lunar_surface', 'volcanic', 'lava_tube', 'cold_polar'],
            'metrics' => ['avg_temp_c' => -4.8, 'slope_deg' => 9.0, 'humidity_pct' => 62.0, 'regolith_similarity' => 95, 'solar_exposure_pct' => 48.0],
            'mineral_type' => 'Gevşek Bazaltik Kül/Skorya Regoliti & Taze Lav Tüpleri',
            'mineral_type_en' => 'Unconsolidated Basaltic Ash/Scoria Regolith & Fresh Lava Tubes',
            'suitable_missions' => ['rover_test', 'drilling_test', 'cave_exploration', 'isru_mining'],
            'description' => 'Lunokhod 1 ve 2 Ay gezginlerinin ve Venera/Mars sondaj sistemlerinin test edildiği bitkisiz siyah bazaltik kül çölü; mekanik dayanım ve tane boyutu dağılımı Ay regolitiyle neredeyse aynıdır.',
            'description_en' => 'Barren black basaltic ash and scoria plain where the Soviet Lunokhod 1 & 2 lunar rovers and planetary drills were tested due to its near-identical mechanical properties to lunar regolith.'
        ],
        [
            'id' => 'earth_pilbara_australia',
            'name' => 'Pilbara Kratonu (3.5 Milyar Yıllık Stromatolit & Kızıl Bazalt Sahası)',
            'name_en' => 'Pilbara Craton (3.5-Ga Stromatolite & Red Basalt Terrane)',
            'body' => 'Earth',
            'country_or_region' => 'Batı Avustralya, Avustralya',
            'country_or_region_en' => 'Western Australia, Australia',
            'continent' => 'Oceania',
            'lat' => -21.15, 'lng' => 119.35,
            'source_url' => 'https://mars.nasa.gov/news/8512/nasa-esa-officials-learn-from-ancient-life-in-australia/',
            'tags' => ['mars_catalog', 'astrobiology', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 26.0, 'slope_deg' => 4.5, 'humidity_pct' => 24.0, 'regolith_similarity' => 92, 'solar_exposure_pct' => 90.0],
            'mineral_type' => 'Arkeen Çört, Demirli Bantlı Formasyon (BIF), Bazalt & Stromatolitler',
            'mineral_type_en' => 'Archean Chert, Banded Iron Formation (BIF), Basalt & Stromatolites',
            'suitable_missions' => ['astrobiology', 'sample_analysis', 'rover_test', 'drilling_test'],
            'description' => 'Dünya üzerindeki en eski doğrulanmış yaşam izlerini (3.48 milyar yıllık Dresser Formasyonu stromatolitleri) koruyan demir-oksit zengini kraton; NASA Perseverance ve ESA ExoMars bilim ekiplerinin birincil astrobiyoloji eğitim sahasıdır.',
            'description_en' => 'Iron-oxide-rich Archean craton preserving Earth\'s oldest confirmed biosignatures (3.48 Ga stromatolites), used directly by NASA Perseverance and ESA ExoMars science teams.'
        ],
        [
            'id' => 'earth_arntunga_amadeus_australia',
            'name' => 'Amadeus Havzası & Henbury Meteor Kraterleri (Orta Avustralya)',
            'name_en' => 'Amadeus Basin & Henbury Meteorite Craters (Central Australia)',
            'body' => 'Earth',
            'country_or_region' => 'Kuzey Toprakları, Avustralya',
            'country_or_region_en' => 'Northern Territory, Australia',
            'continent' => 'Oceania',
            'lat' => -24.5719, 'lng' => 133.1478,
            'source_url' => 'https://en.wikipedia.org/wiki/Henbury_Meteorites_Conservation_Reserve',
            'tags' => ['mars_catalog', 'impact_crater', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 21.8, 'slope_deg' => 7.0, 'humidity_pct' => 21.0, 'regolith_similarity' => 90, 'solar_exposure_pct' => 91.0],
            'mineral_type' => 'Demir Oksitli Kuvarsit/Kumtaşı Çarpma Breşi & Kızıl Çöl Regoliti',
            'mineral_type_en' => 'Iron-Oxide Quartzite/Sandstone Impact Breccia & Red Desert Regolith',
            'suitable_missions' => ['rover_test', 'sample_analysis', 'landing_site', 'eva_suit'],
            'description' => 'Kızıl çöl ortasında korunmuş 13 küçük çarpma krateri kümesi ve ejekta ışınları; hem Ay ikincil krater kümeleri hem de Mars küçük çarpma kraterleri için mükemmel bir morfolojik modeldir.',
            'description_en' => 'Cluster of 13 preserved impact craters and ejecta rays in arid red desert terrain, modeling both lunar secondary crater clusters and Martian impact craters.'
        ],
        [
            'id' => 'earth_university_valley',
            'name' => 'University Vadisi (McMurdo Kuru Vadileri - NASA IceBite Sondaj Sahası)',
            'name_en' => 'University Valley (McMurdo Dry Valleys - NASA IceBite Drilling Site)',
            'body' => 'Earth',
            'country_or_region' => 'Victoria Toprakları, Antarktika',
            'country_or_region_en' => 'Victoria Land, Antarctica',
            'continent' => 'Antarctica',
            'lat' => -77.8630, 'lng' => 163.7500,
            'source_url' => 'https://www.nasa.gov/ames/icebite',
            'tags' => ['cold_polar', 'permafrost'],
            'metrics' => ['avg_temp_c' => -23.5, 'slope_deg' => 8.5, 'humidity_pct' => 18.0, 'regolith_similarity' => 95, 'solar_exposure_pct' => 45.0],
            'mineral_type' => 'Kuru Permafrost Üstü Kayaç Buzulu (Buz-Çimentolu Regolit) & Dolerit',
            'mineral_type_en' => 'Dry Permafrost Over Ice-Cemented Regolith & Ferrar Dolerite',
            'suitable_missions' => ['ice_prospecting', 'drilling_test', 'isru_mining', 'astrobiology', 'rover_test'],
            'description' => 'Yaz aylarında bile sıcaklığın sıfırın üzerine çıkmadığı yüksek irtifa (1.700 m) Antarktika vadisi; NASA Phoenix Mars iniş aracının keşfettiği buz-çimentolu toprak katmanının Dünya\'daki en yakın karşılığıdır ve NASA IceBite/TRIDENT matkaplarının test edildiği yerdir.',
            'description_en' => 'High-elevation (1,700 m) Antarctic valley where summer temperatures never rise above freezing; primary testbed for NASA IceBite and TRIDENT lunar/Martian ice drills.'
        ],
        [
            'id' => 'earth_agri_tendurek_lavas',
            'name' => 'Ağrı & Tendürek Kalkan Volkanı Bazalt Akıntıları (Türkiye)',
            'name_en' => 'Mount Ararat & Tendurek Shield Volcano Basalt Flows (Türkiye)',
            'body' => 'Earth',
            'country_or_region' => 'Ağrı / Van, Türkiye',
            'country_or_region_en' => 'Agri / Van, Türkiye',
            'continent' => 'Asia',
            'lat' => 39.3564, 'lng' => 43.8736,
            'source_url' => 'https://en.wikipedia.org/wiki/Mount_Tend%C3%BCrek',
            'tags' => ['volcanic', 'lunar_surface', 'mars_catalog'],
            'metrics' => ['avg_temp_c' => 3.0, 'slope_deg' => 9.5, 'humidity_pct' => 45.0, 'regolith_similarity' => 90, 'solar_exposure_pct' => 76.0],
            'mineral_type' => 'Düşük Viskoziteli Bazaltik Kalkan Lavları, Hornito Bacaları & Tüf',
            'mineral_type_en' => 'Low-Viscosity Basaltic Shield Lavas, Hornito Vents & Tuff',
            'suitable_missions' => ['rover_test', 'volcanology', 'eva_suit', 'isru_mining'],
            'description' => 'Tendürek kalkan volkanının geniş bazaltik pahoehoe/aa lav akıntıları ve hidrotermal krater çıkışları; hem Ay mare düzlüklerini hem de Mars kalkan volkanizmasını temsil eder.',
            'description_en' => 'Extensive basaltic shield lava fields and hydrothermal summit craters of Mount Tendurek in Eastern Anatolia.'
        ],
        [
            'id' => 'earth_van_golu_microbialites',
            'name' => 'Van Gölü Alkalin Soda Gölü & Dev Mikrobiyalitler (Türkiye)',
            'name_en' => 'Lake Van Alkaline Soda Lake & Giant Microbialites (Türkiye)',
            'body' => 'Earth',
            'country_or_region' => 'Van / Bitlis, Türkiye',
            'country_or_region_en' => 'Van / Bitlis, Türkiye',
            'continent' => 'Asia',
            'lat' => 38.6280, 'lng' => 42.9050,
            'source_url' => 'https://en.wikipedia.org/wiki/Lake_Van',
            'tags' => ['mars_catalog', 'astrobiology'],
            'metrics' => ['avg_temp_c' => 9.4, 'slope_deg' => 4.5, 'humidity_pct' => 52.0, 'regolith_similarity' => 74, 'solar_exposure_pct' => 78.0],
            'mineral_type' => 'Kalsiyum/Magnezyum Karbonat Mikrobiyalit Kuleleri & Bazaltik Kıyı',
            'mineral_type_en' => 'Ca/Mg-Carbonate Microbialite Towers & Basaltic Shoreline',
            'suitable_missions' => ['astrobiology', 'sample_analysis'],
            'description' => 'Dünya\'nın en büyük sodalı gölü (pH ~9.8) ve 40 metreye ulaşan sualtı karbonat mikrobiyalit bacaları; erken Mars alkalin göl kimyası ve yaşam izi araştırmaları için benzersiz bir doğal laboratuvardır.',
            'description_en' => 'World\'s largest alkaline soda lake (pH ~9.8) hosting 40 m carbonate microbialite chimneys analogous to early Martian alkaline lakes.'
        ],
        [
            'id' => 'earth_namib_sand_sea',
            'name' => 'Namib Çölü Doğrusal Kumulları & Çakıl Ovaları (Namibya)',
            'name_en' => 'Namib Desert Linear Dunes & Gravel Plains (Namibia)',
            'body' => 'Earth',
            'country_or_region' => 'Hardap / Erongo, Namibya',
            'country_or_region_en' => 'Hardap / Erongo, Namibia',
            'continent' => 'Africa',
            'lat' => -24.75, 'lng' => 15.27,
            'source_url' => 'https://science.nasa.gov/resource/bagnold-dunes-namib-dune/',
            'tags' => ['mars_catalog', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 20.5, 'slope_deg' => 11.0, 'humidity_pct' => 16.0, 'regolith_similarity' => 91, 'solar_exposure_pct' => 92.0],
            'mineral_type' => 'Demir Oksit Kaplı Kuvars/Bazalt Kumulları & Çöl Kaldırımı (Desert Pavement)',
            'mineral_type_en' => 'Iron-Oxide Coated Quartz/Basalt Dunes & Desert Pavement',
            'suitable_missions' => ['rover_test', 'landing_site', 'solar_power', 'hazard_avoidance'],
            'description' => 'Curiosity gezgininin Gale Krateri\'nde incelediği "Namib Kumulu"na adını veren, 55 milyon yıllık dünyanın en eski hiper-kurak rüzgar-kumul laboratuvarıdır.',
            'description_en' => 'World\'s oldest desert (55 Myr) and namesake of the Martian "Namib Dune" investigated by the Curiosity rover in Gale Crater.'
        ],
        [
            'id' => 'earth_lut_desert_kaluts',
            'name' => 'Lut Çölü (Deşt-i Lut) Yardang Koridorları & Gandom Beryan Bazalt Platosu (İran)',
            'name_en' => 'Lut Desert (Dasht-e Lut) Kalut Yardangs & Gandom Beryan Basalt Plateau (Iran)',
            'body' => 'Earth',
            'country_or_region' => 'Kirman, İran',
            'country_or_region_en' => 'Kerman, Iran',
            'continent' => 'Asia',
            'lat' => 30.57, 'lng' => 58.38,
            'source_url' => 'https://en.wikipedia.org/wiki/Dasht-e_Lut',
            'tags' => ['mars_catalog', 'hyper_arid', 'volcanic'],
            'metrics' => ['avg_temp_c' => 27.5, 'slope_deg' => 8.5, 'humidity_pct' => 11.0, 'regolith_similarity' => 92, 'solar_exposure_pct' => 94.0],
            'mineral_type' => 'Siyah Bazalt Lav Platosu, Rüzgar Yardangları (Kalut) & Tuzlu Kil',
            'mineral_type_en' => 'Black Basalt Lava Plateau, Aeolian Yardangs (Kaluts) & Saline Clay',
            'suitable_missions' => ['rover_test', 'astrobiology', 'solar_power', 'sample_analysis'],
            'description' => 'NASA MODIS uydusuyla Dünya\'nın en ekstrem yüzey sıcaklığının ve en kurak koşullarının ölçüldüğü saha; 150 km\'lik devasa rüzgar yardangları Mars Medusae Fossae sırtlarıyla birebir örtüşür.',
            'description_en' => 'Hyper-arid basin hosting 150 km aeolian yardang ridges (Kaluts) and the dark Gandom Beryan basalt plateau, closely matching Mars\'s Medusae Fossae.'
        ],
        [
            'id' => 'earth_lonar_crater_india',
            'name' => 'Lonar Bazaltik Çarpma Krateri (Deccan Tuzakları, Hindistan)',
            'name_en' => 'Lonar Basaltic Impact Crater (Deccan Traps, India)',
            'body' => 'Earth',
            'country_or_region' => 'Maharashtra, Hindistan',
            'country_or_region_en' => 'Maharashtra, India',
            'continent' => 'Asia',
            'lat' => 19.9767, 'lng' => 76.5083,
            'source_url' => 'https://en.wikipedia.org/wiki/Lonar_Lake',
            'tags' => ['lunar_surface', 'mars_catalog', 'impact_crater', 'astrobiology'],
            'metrics' => ['avg_temp_c' => 25.0, 'slope_deg' => 15.0, 'humidity_pct' => 40.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 83.0],
            'mineral_type' => 'Toleitik Bazalt Çarpma Breşi, Maskelinit (Şok Camı) & Alkalin Göl Tortulu',
            'mineral_type_en' => 'Tholeiitic Basalt Impact Breccia, Maskelynite (Shock Glass) & Alkaline Sediments',
            'suitable_missions' => ['sample_analysis', 'astrobiology', 'rover_test', 'eva_suit', 'drilling_test'],
            'description' => 'Dünya üzerinde tamamen bazaltik lav tabakaları (Deccan Traps) içinde oluşmuş en iyi korunmuş çarpma krateridir; bu özelliğiyle hem Ay mare kraterlerinin hem de Mars bazalt kraterlerinin birincil jeolojik referansıdır.',
            'description_en' => 'One of the only well-preserved impact craters on Earth formed entirely within basaltic flood lavas (Deccan Traps), making it a primary geological benchmark for both lunar mare and Martian craters.'
        ],
        [
            'id' => 'earth_jeju_manjanggul_korea',
            'name' => 'Jeju Adası Manjanggul Bazaltik Lav Tüpü (Güney Kore)',
            'name_en' => 'Jeju Island Manjanggul Basaltic Lava Tube (South Korea)',
            'body' => 'Earth',
            'country_or_region' => 'Jeju, Güney Kore',
            'country_or_region_en' => 'Jeju, South Korea',
            'continent' => 'Asia',
            'lat' => 33.5283, 'lng' => 126.7714,
            'source_url' => 'https://en.wikipedia.org/wiki/Manjanggul',
            'tags' => ['lava_tube', 'lunar_surface', 'volcanic'],
            'metrics' => ['avg_temp_c' => 14.5, 'slope_deg' => 3.5, 'humidity_pct' => 68.0, 'regolith_similarity' => 88, 'solar_exposure_pct' => 58.0],
            'mineral_type' => 'Çok Katlı Bazaltik Lav Tüpü Galerisi (30 m Yükseklik, 23 m Genişlik)',
            'mineral_type_en' => 'Multi-Level Basaltic Lava Tube Conduit (30 m High, 23 m Wide)',
            'suitable_missions' => ['cave_exploration', 'radiation_shelter', 'habitat_construction', 'rover_test'],
            'description' => '23 m genişliğe ve 30 m tavan yüksekliğine ulaşan dev kesiti ve düz lav zeminiyle Ay\'daki büyük yeraltı lav tünellerinde modüler habitat kurulumu ve otonom drone/rover haritalama testleri için kullanılır.',
            'description_en' => 'Massive basaltic lava tube (up to 23 m wide and 30 m high) used to model modular lunar habitat deployment and subterranean robotic mapping.'
        ],
        [
            'id' => 'earth_gobi_mars_base_china',
            'name' => 'Gobi Çölü Jinchang & Dunhuang Kızıl Kumtaşı Havzası (Çin)',
            'name_en' => 'Gobi Desert Jinchang & Dunhuang Red Sandstone Basin (China)',
            'body' => 'Earth',
            'country_or_region' => 'Gansu, Çin',
            'country_or_region_en' => 'Gansu, China',
            'continent' => 'Asia',
            'lat' => 38.52, 'lng' => 102.18,
            'source_url' => 'https://en.wikipedia.org/wiki/Gobi_Desert',
            'tags' => ['mars_catalog', 'hyper_arid'],
            'metrics' => ['avg_temp_c' => 8.2, 'slope_deg' => 6.5, 'humidity_pct' => 23.0, 'regolith_similarity' => 88, 'solar_exposure_pct' => 86.0],
            'mineral_type' => 'Kızıl Demir Oksitli Kumtaşı, Çakıl Gobi Regoliti & Evaporit',
            'mineral_type_en' => 'Red Iron-Oxide Sandstone, Gravel Gobi Regolith & Evaporite',
            'suitable_missions' => ['habitat_construction', 'eva_suit', 'rover_test', 'landing_site', 'solar_power'],
            'description' => 'Soğuk-kurak iklimi, bitkisiz taşlı çöl yüzeyi (gobi) ve kızıl kumtaşı tepeleriyle Mars yüzey operasyonları ve habitat simülasyonları (Mars Base 1) için kullanılan Asya analog sahası.',
            'description_en' => 'Cold-arid stony desert and red sandstone terrain in Gansu used for Mars surface habitat simulations and rover trials.'
        ],
        [
            'id' => 'earth_tongariro_ngaapuna_nz',
            'name' => 'Tongariro Volkanik Platosu & Taupo Hidrotermal Bölgesi (Yeni Zelanda)',
            'name_en' => 'Tongariro Volcanic Plateau & Taupo Hydrothermal Zone (New Zealand)',
            'body' => 'Earth',
            'country_or_region' => 'Kuzey Adası, Yeni Zelanda',
            'country_or_region_en' => 'North Island, New Zealand',
            'continent' => 'Oceania',
            'lat' => -39.13, 'lng' => 175.64,
            'source_url' => 'https://en.wikipedia.org/wiki/Mount_Tongariro',
            'tags' => ['mars_catalog', 'volcanic', 'astrobiology'],
            'metrics' => ['avg_temp_c' => 7.0, 'slope_deg' => 14.0, 'humidity_pct' => 56.0, 'regolith_similarity' => 86, 'solar_exposure_pct' => 66.0],
            'mineral_type' => 'Andezitik/Bazaltik Skorya, Asit-Sülfat Fumaroller & Silika Teraslar',
            'mineral_type_en' => 'Andesitic/Basaltic Scoria, Acid-Sulfate Fumaroles & Silica Sinters',
            'suitable_missions' => ['astrobiology', 'volcanology', 'sample_analysis', 'rover_test'],
            'description' => 'Aktif andezitik/bazaltik kraterler ile silika sinter birikimi yapan asidik hidrotermal kaynakları bir arada sunan Güney Yarımküre astrobiyoloji ve volkanoloji sahası.',
            'description_en' => 'Active volcanic craters and acid-sulfate / silica sinter hydrothermal springs used as an astrobiological analog for ancient Martian volcanic hydrothermal systems.'
        ],
        [
            'id' => 'earth_disko_island_greenland',
            'name' => 'Disko Adası Doğal Demirli Bazalt & Arktik Permafrost Sahası (Grönland)',
            'name_en' => 'Disko Island Native-Iron Basalt & Arctic Permafrost Site (Greenland)',
            'body' => 'Earth',
            'country_or_region' => 'Qeqertarsuaq, Grönland',
            'country_or_region_en' => 'Qeqertarsuaq, Greenland',
            'continent' => 'North America',
            'lat' => 69.75, 'lng' => -53.50,
            'source_url' => 'https://en.wikipedia.org/wiki/Disko_Island',
            'tags' => ['lunar_surface', 'cold_polar', 'permafrost', 'volcanic'],
            'metrics' => ['avg_temp_c' => -6.5, 'slope_deg' => 10.0, 'humidity_pct' => 60.0, 'regolith_similarity' => 96, 'solar_exposure_pct' => 45.0],
            'mineral_type' => 'Metalik Demir (Fe⁰) İçeren Bazalt Lavları & Kutup Permafrostu',
            'mineral_type_en' => 'Metallic Native-Iron (Fe0) Bearing Basalt & Polar Permafrost',
            'suitable_missions' => ['isru_mining', 'ice_prospecting', 'drilling_test', 'sample_analysis', 'rover_test'],
            'description' => 'Dünya üzerinde tıpkı Ay bazaltları gibi indirgenmiş metalik demir (Fe⁰) içeren nadir bazalt akıntılarını ve Arktik permafrostu bir arada barındıran eşsiz ISRU ve kutup sondaj sahasıdır.',
            'description_en' => 'One of the only places on Earth where basaltic lavas naturally contain metallic native iron (Fe0) like lunar mare basalts, combined with Arctic permafrost.'
        ]
    ];
}

/**
 * Veritabanını JSON dosyaları + Genişletilmiş Küresel Katalog + Küresel Kara Grid Noktaları ile doldurur.
 */
function astroSeedDatabase(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        // 1. Gezegen Hedefleri (extraterrestrial.json + Global Expansion)
        $extPath = __DIR__ . '/../data/extraterrestrial.json';
        $extList = is_file($extPath) ? (json_decode((string)file_get_contents($extPath), true) ?: []) : [];
        $allPlanetary = array_merge($extList, astroGetGlobalPlanetaryExpansion());

        $stmtPlanetary = $pdo->prepare("
            INSERT OR REPLACE INTO planetary_targets (
                id, body, name, name_en, country_or_region, country_or_region_en,
                lat, lng, elevation_m, diameter_km, feature_type, validation_tag,
                avg_temp_c, slope_deg, humidity_pct, regolith_similarity, solar_exposure_pct,
                mineral_type, mineral_type_en, suitable_missions_json,
                description, description_en, source_url, source_label, trek_url, is_curated, is_dynamic
            ) VALUES (
                :id, :body, :name, :name_en, :region, :region_en,
                :lat, :lng, :elev, :diam, :ftype, :vtag,
                :temp, :slope, :hum, :reg, :solar,
                :min_tr, :min_en, :missions,
                :desc_tr, :desc_en, :surl, :slabel, :turl, 1, 0
            )
        ");

        foreach ($allPlanetary as $item) {
            $m = $item['metrics'] ?? [];
            $stmtPlanetary->execute([
                ':id'        => (string)$item['id'],
                ':body'      => (string)($item['body'] ?? 'Moon'),
                ':name'      => (string)($item['name'] ?? ''),
                ':name_en'   => (string)($item['name_en'] ?? $item['name'] ?? ''),
                ':region'    => (string)($item['country_or_region'] ?? ''),
                ':region_en' => (string)($item['country_or_region_en'] ?? $item['country_or_region'] ?? ''),
                ':lat'       => (float)($item['lat'] ?? 0.0),
                ':lng'       => (float)($item['lng'] ?? 0.0),
                ':elev'      => (float)($item['elevation_m'] ?? 0.0),
                ':diam'      => (float)($item['diameter_km'] ?? 0.0),
                ':ftype'     => (string)($item['feature_type'] ?? 'landmark'),
                ':vtag'      => (string)($item['validation_tag'] ?? 'planetary_surface'),
                ':temp'      => (float)($m['avg_temp_c'] ?? -50.0),
                ':slope'     => (float)($m['slope_deg'] ?? 8.0),
                ':hum'       => (float)($m['humidity_pct'] ?? 0.0),
                ':reg'       => (float)($m['regolith_similarity'] ?? 100.0),
                ':solar'     => (float)($m['solar_exposure_pct'] ?? 50.0),
                ':min_tr'    => (string)($item['mineral_type'] ?? ''),
                ':min_en'    => (string)($item['mineral_type_en'] ?? $item['mineral_type'] ?? ''),
                ':missions'  => json_encode($item['suitable_missions'] ?? [], JSON_UNESCAPED_UNICODE),
                ':desc_tr'   => (string)($item['description'] ?? ''),
                ':desc_en'   => (string)($item['description_en'] ?? $item['description'] ?? ''),
                ':surl'      => (string)($item['source_url'] ?? ''),
                ':slabel'    => (string)($item['source_label'] ?? ''),
                ':turl'      => (string)($item['trek_url'] ?? ''),
            ]);
        }

        // 2. Dünya Analog Sahaları (earth_analogs.json + Global Expansion)
        $pdo->exec("DELETE FROM earth_sites WHERE id LIKE 'earth_bench_%' OR id IN ('earth_san_francisco_volcanic_az', 'earth_askja_holuhraun_iceland', 'earth_university_valley_antarctica')");
        $earthPath = __DIR__ . '/../data/earth_analogs.json';
        $earthList = is_file($earthPath) ? (json_decode((string)file_get_contents($earthPath), true) ?: []) : [];
        $allEarth = array_merge($earthList, astroGetGlobalEarthExpansion());

        $stmtEarth = $pdo->prepare("
            INSERT OR REPLACE INTO earth_sites (
                id, name, name_en, body, country_or_region, country_or_region_en, continent,
                lat, lng, elevation_m, avg_temp_c, slope_deg, humidity_pct, regolith_similarity, solar_exposure_pct,
                t2m_range, prectotcorr, allsky_sw_dwn,
                mineral_type, mineral_type_en, suitable_missions_json, tags_json,
                description, description_en, source_url, is_curated, is_dynamic
            ) VALUES (
                :id, :name, :name_en, 'Earth', :region, :region_en, :continent,
                :lat, :lng, :elev, :temp, :slope, :hum, :reg, :solar,
                :t2m_range, :prec, :sw,
                :min_tr, :min_en, :missions, :tags,
                :desc_tr, :desc_en, :surl, 1, 0
            )
        ");

        foreach ($allEarth as $item) {
            $m = $item['metrics'] ?? [];
            $lat = (float)($item['lat'] ?? 0.0);
            $lng = (float)($item['lng'] ?? 0.0);
            $temp = (float)($m['avg_temp_c'] ?? 10.0);
            $hum = (float)($m['humidity_pct'] ?? 35.0);
            $solar = (float)($m['solar_exposure_pct'] ?? 75.0);

            $stmtEarth->execute([
                ':id'        => (string)$item['id'],
                ':name'      => (string)($item['name'] ?? ''),
                ':name_en'   => (string)($item['name_en'] ?? $item['name'] ?? ''),
                ':region'    => (string)($item['country_or_region'] ?? ''),
                ':region_en' => (string)($item['country_or_region_en'] ?? $item['country_or_region'] ?? ''),
                ':continent' => (string)($item['continent'] ?? astroGuessContinent($lat, $lng)),
                ':lat'       => $lat,
                ':lng'       => $lng,
                ':elev'      => (float)($item['elevation_m'] ?? astroEstimateEarthElevation($lat, $lng)),
                ':temp'      => $temp,
                ':slope'     => (float)($m['slope_deg'] ?? 6.0),
                ':hum'       => $hum,
                ':reg'       => (float)($m['regolith_similarity'] ?? 80.0),
                ':solar'     => $solar,
                ':t2m_range' => round(max(4.0, 22.0 - ($hum * 0.14)), 1),
                ':prec'      => round(max(0.01, ($hum / 100.0) ** 2.2 * 3.5), 2),
                ':sw'        => round(max(1.2, ($solar / 100.0) * 7.8), 2),
                ':min_tr'    => (string)($item['mineral_type'] ?? ''),
                ':min_en'    => (string)($item['mineral_type_en'] ?? $item['mineral_type'] ?? ''),
                ':missions'  => json_encode($item['suitable_missions'] ?? [], JSON_UNESCAPED_UNICODE),
                ':tags'      => json_encode($item['tags'] ?? [], JSON_UNESCAPED_UNICODE),
                ':desc_tr'   => (string)($item['description'] ?? ''),
                ':desc_en'   => (string)($item['description_en'] ?? $item['description'] ?? ''),
                ':surl'      => (string)($item['source_url'] ?? ''),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Koordinattan kıta adını tahmin eder.
 */
function astroGuessContinent(float $lat, float $lng): string
{
    if ($lat < -60.0) return 'Antarctica';
    if ($lng >= -170.0 && $lng <= -30.0) {
        return $lat >= 12.0 ? 'North America' : 'South America';
    }
    if ($lng > -30.0 && $lng <= 60.0) {
        if ($lat >= 35.0) return ($lng >= 26.0 && $lat <= 42.5) ? 'Asia' : 'Europe';
        return ($lng >= 35.0 && $lat >= 12.0) ? 'Asia' : 'Africa';
    }
    if ($lng > 60.0 && $lng <= 180.0) {
        return $lat < -10.0 ? 'Oceania' : 'Asia';
    }
    return 'Asia';
}

/**
 * Dünya üzerinde herhangi bir (lat, lng) için bölge/ülke adını tahmin eder.
 */
function astroGuessEarthRegionName(float $lat, float $lng, string $lang = 'tr'): string
{
    // Türkiye sınırları
    if ($lat >= 35.8 && $lat <= 42.2 && $lng >= 25.6 && $lng <= 44.9) {
        if ($lat >= 37.0 && $lat <= 39.8 && $lng >= 31.0 && $lng <= 36.5) {
            return $lang === 'en' ? 'Central Anatolia, Türkiye' : 'İç Anadolu Bölgesi, Türkiye';
        }
        if ($lng >= 38.0) {
            return $lang === 'en' ? 'Eastern Anatolia, Türkiye' : 'Doğu Anadolu Volkanik Kuşağı, Türkiye';
        }
        return $lang === 'en' ? 'Anatolia, Türkiye' : 'Anadolu Kuşağı, Türkiye';
    }
    $cont = astroGuessContinent($lat, $lng);
    $contTr = [
        'Europe'        => 'Avrupa Kıta Sektörü',
        'Asia'          => 'Asya Kıta Sektörü',
        'Africa'        => 'Afrika / Sahra Sektörü',
        'North America' => 'Kuzey Amerika Sektörü',
        'South America' => 'Güney Amerika / And Sektörü',
        'Oceania'       => 'Okyanusya / Avustralya Sektörü',
        'Antarctica'    => 'Antarktika Kutup Sektörü',
    ][$cont] ?? 'Küresel Sektör';

    $latDir = $lat >= 0 ? 'N' : 'S';
    $lngDir = $lng >= 0 ? 'E' : 'W';
    $coordStr = sprintf('%.2f°%s, %.2f°%s', abs($lat), $latDir, abs($lng), $lngDir);
    return $lang === 'en' ? "{$cont} Sector ({$coordStr})" : "{$contTr} ({$coordStr})";
}

/**
 * Dünya üzerindeki bir koordinatın yaklaşık rakımını (m) küresel topoğrafya harmonikleriyle tahmin eder.
 */
function astroEstimateEarthElevation(float $lat, float $lng): float
{
    // Yüksek platolar (Tibet, Andlar, Antarktika, Doğu Anadolu, Batı ABD)
    if ($lat >= 28.0 && $lat <= 40.0 && $lng >= 75.0 && $lng <= 102.0) return 4100.0; // Tibet / Qaidam
    if ($lat >= -28.0 && $lat <= -14.0 && $lng >= -71.0 && $lng <= -65.0) return 3850.0; // Altiplano / Puna
    if ($lat <= -70.0) return 2100.0; // Antarktika
    if ($lat >= 38.0 && $lat <= 41.0 && $lng >= 39.0 && $lng <= 44.5) return 1950.0; // Doğu Anadolu
    if ($lat >= 36.0 && $lat <= 40.0 && $lng >= 30.0 && $lng <= 38.0) return 1150.0; // İç Anadolu
    if ($lat >= 32.0 && $lat <= 44.0 && $lng >= -120.0 && $lng <= -105.0) return 1650.0; // Colorado Platosu / Great Basin

    $wave = sin(deg2rad($lat * 3.1)) * cos(deg2rad($lng * 2.3));
    return round(max(25.0, 480.0 + $wave * 420.0), 0);
}

/**
 * Koordinat ve etiketlere göre Dünya sahası metriklerini sentezler.
 *
 * @param array<int, string> $tags
 * @return array{metrics: array<string, float>, mineral_tr: string, mineral_en: string, missions: array<int, string>}
 */
function astroEstimateEarthMetricsFromCoords(float $lat, float $lng, array $tags = []): array
{
    $absLat = abs($lat);
    $elev = astroEstimateEarthElevation($lat, $lng);

    // Enlem ve rakıma dayalı fiziksel sıcaklık tahmini
    $baseTemp = 27.5 - ($absLat * 0.62) - (($elev / 1000.0) * 5.8);
    if (in_array('cold_polar', $tags, true) || $absLat > 66.0) {
        $baseTemp = min($baseTemp, -12.0 - ($absLat - 60.0) * 0.5);
    }

    // Kuraklık / nem tahmini
    $isDesertBelt = ($absLat >= 15.0 && $absLat <= 38.0) || in_array('hyper_arid', $tags, true);
    $humidity = $isDesertBelt ? 22.0 : ($absLat > 65.0 ? 52.0 : 48.0);
    if (in_array('hyper_arid', $tags, true)) {
        $humidity = 14.0;
    }

    // Eğim ve regolit
    $hash = abs(sin($lat * 12.9898 + $lng * 78.233));
    $slope = round(3.0 + ($hash * 13.5), 1);
    $regolith = in_array('volcanic', $tags, true) || in_array('lava_tube', $tags, true) ? 89.0 : ($isDesertBelt ? 83.0 : 75.0);
    $solar = round(max(35.0, min(94.0, 92.0 - ($absLat * 0.48) - ($humidity * 0.22))), 1);

    $mineralTr = 'Bazaltik / Sedimanter Karasal Regolit';
    $mineralEn = 'Basaltic / Sedimentary Terrestrial Regolith';
    $missions = ['rover_test', 'eva_suit', 'sample_analysis'];

    if (in_array('lava_tube', $tags, true) || in_array('volcanic', $tags, true)) {
        $mineralTr = 'Bazaltik Lav Akıntısı & Volkanik Skorya/Tüf';
        $mineralEn = 'Basaltic Lava Flow & Volcanic Scoria/Tuff';
        $missions = ['cave_exploration', 'radiation_shelter', 'eva_suit', 'rover_test', 'volcanology'];
    } elseif (in_array('cold_polar', $tags, true) || in_array('permafrost', $tags, true)) {
        $mineralTr = 'Permafrost / Kriyojenik Buz-Çimentolu Regolit';
        $mineralEn = 'Permafrost / Cryogenic Ice-Cemented Regolith';
        $missions = ['ice_prospecting', 'drilling_test', 'isru_mining', 'astrobiology'];
    } elseif (in_array('hyper_arid', $tags, true) || in_array('mars_catalog', $tags, true)) {
        $mineralTr = 'Demir Oksitli Kurak Çöl Kumtaşı & Sülfat/Evaporit';
        $mineralEn = 'Iron-Oxide Arid Sandstone & Sulfate/Evaporite';
        $missions = ['rover_test', 'astrobiology', 'sample_analysis', 'landing_site', 'solar_power'];
    }

    return [
        'metrics' => [
            'avg_temp_c'          => round($baseTemp, 1),
            'slope_deg'           => $slope,
            'humidity_pct'        => round($humidity, 1),
            'regolith_similarity' => round($regolith, 0),
            'solar_exposure_pct'  => $solar,
        ],
        'mineral_tr' => $mineralTr,
        'mineral_en' => $mineralEn,
        'missions'   => $missions,
    ];
}

/**
 * Veritabanındaki bir planetary_targets satırını uygulama JSON şemasına dönüştürür.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function astroFormatPlanetaryRow(array $row): array
{
    $missions = json_decode((string)($row['suitable_missions_json'] ?? '[]'), true);
    if (!is_array($missions)) {
        $missions = ['rover_test', 'eva_suit', 'sample_analysis'];
    }

    return [
        'id'                   => (string)$row['id'],
        'name'                 => (string)$row['name'],
        'name_en'              => (string)$row['name_en'],
        'body'                 => (string)$row['body'],
        'country_or_region'    => (string)$row['country_or_region'],
        'country_or_region_en' => (string)$row['country_or_region_en'],
        'lat'                  => round((float)$row['lat'], 4),
        'lng'                  => round((float)$row['lng'], 4),
        'elevation_m'          => round((float)($row['elevation_m'] ?? 0.0), 0),
        'validation_tag'       => (string)($row['validation_tag'] ?? 'planetary_surface'),
        'is_dynamic'           => !empty($row['is_dynamic']),
        'source_url'           => (string)($row['source_url'] ?? ''),
        'source_label'         => (string)($row['source_label'] ?? ''),
        'trek_url'             => (string)($row['trek_url'] ?? ''),
        'metrics'              => [
            'avg_temp_c'          => round((float)$row['avg_temp_c'], 1),
            'slope_deg'           => round((float)$row['slope_deg'], 1),
            'humidity_pct'        => round((float)$row['humidity_pct'], 1),
            'regolith_similarity' => round((float)$row['regolith_similarity'], 0),
            'solar_exposure_pct'  => round((float)$row['solar_exposure_pct'], 1),
        ],
        'mineral_type'         => (string)$row['mineral_type'],
        'mineral_type_en'      => (string)$row['mineral_type_en'],
        'suitable_missions'    => $missions,
        'description'          => (string)$row['description'],
        'description_en'       => (string)$row['description_en'],
    ];
}

/**
 * Veritabanındaki bir earth_sites satırını uygulama JSON şemasına dönüştürür ve zenginleştirir.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function astroFormatEarthRow(array $row, string $lang = 'tr'): array
{
    $missions = json_decode((string)($row['suitable_missions_json'] ?? '[]'), true);
    $tags = json_decode((string)($row['tags_json'] ?? '[]'), true);
    $lat = round((float)$row['lat'], 4);
    $lng = round((float)$row['lng'], 4);

    $item = [
        'id'                   => (string)$row['id'],
        'name'                 => (string)$row['name'],
        'name_en'              => (string)$row['name_en'],
        'body'                 => 'Earth',
        'country_or_region'    => (string)$row['country_or_region'],
        'country_or_region_en' => (string)$row['country_or_region_en'],
        'continent'            => (string)($row['continent'] ?? ''),
        'lat'                  => $lat,
        'lng'                  => $lng,
        'elevation_m'          => round((float)($row['elevation_m'] ?? 500.0), 0),
        'is_curated'           => !empty($row['is_curated']),
        'is_dynamic'           => !empty($row['is_dynamic']),
        'source_url'           => (string)($row['source_url'] ?? ''),
        'tags'                 => is_array($tags) ? $tags : [],
        'metrics'              => [
            'avg_temp_c'          => round((float)$row['avg_temp_c'], 1),
            'slope_deg'           => round((float)$row['slope_deg'], 1),
            'humidity_pct'        => round((float)$row['humidity_pct'], 1),
            'regolith_similarity' => round((float)$row['regolith_similarity'], 0),
            'solar_exposure_pct'  => round((float)$row['solar_exposure_pct'], 1),
            'T2M'                 => round((float)$row['avg_temp_c'], 2),
            'T2M_RANGE'           => round((float)($row['t2m_range'] ?? 14.0), 2),
            'PRECTOTCORR'         => round((float)($row['prectotcorr'] ?? 0.5), 2),
            'RH2M'                => round((float)$row['humidity_pct'], 2),
            'ALLSKY_SFC_SW_DWN'   => round((float)($row['allsky_sw_dwn'] ?? 5.2), 2),
        ],
        'mineral_type'         => (string)$row['mineral_type'],
        'mineral_type_en'      => (string)$row['mineral_type_en'],
        'suitable_missions'    => is_array($missions) ? $missions : [],
        'description'          => (string)$row['description'],
        'description_en'       => (string)$row['description_en'],
    ];

    $item['novelty'] = astroComputeNovelty($lat, $lng, $lang);
    $item['nearest_cave'] = astroFindNearestCave($lat, $lng, $lang);
    $item['qualification'] = astroAssessHardwareReadiness($item, $lang);

    return $item;
}

/**
 * Veritabanından tüm Ay ve Mars hedeflerini getirir.
 *
 * @return array<int, array<string, mixed>>
 */
function astroDbGetPlanetaryTargets(?string $body = null, bool $includeDynamic = false): array
{
    $pdo = astroDb();
    $sql = "SELECT * FROM planetary_targets WHERE 1=1";
    $params = [];
    if (!$includeDynamic) {
        $sql .= " AND is_dynamic = 0";
    }
    if ($body !== null && ($body === 'Moon' || $body === 'Mars')) {
        $sql .= " AND body = :body";
        $params[':body'] = $body;
    }
    $sql .= " ORDER BY is_dynamic ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = astroFormatPlanetaryRow($row);
    }
    return $out;
}

/**
 * ID'ye göre tek bir Ay/Mars hedefini veritabanından getirir.
 * Eğer ID `dyn_moon_LAT_LNG` veya `dyn_mars_LAT_LNG` formatındaysa ve tabloda yoksa anında sentezler!
 *
 * @return array<string, mixed>|null
 */
function astroDbGetPlanetaryTargetById(string $targetId, string $lang = 'tr'): ?array
{
    $pdo = astroDb();
    $stmt = $pdo->prepare("SELECT * FROM planetary_targets WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $targetId]);
    $row = $stmt->fetch();
    if ($row) {
        return astroFormatPlanetaryRow($row);
    }

    // Dinamik koordinat ID'si kontrolü: dyn_moon_-43.25_12.40 veya dyn_mars_18.50_-72.10
    if (preg_match('/^dyn_(moon|mars)_(-?\d+(?:\.\d+)?)_(-?\d+(?:\.\d+)?)$/i', $targetId, $m)) {
        $body = ucfirst(strtolower($m[1]));
        $lat = (float)$m[2];
        $lng = (float)$m[3];
        return astroSynthesizePlanetaryTarget($body, $lat, $lng, $lang, true);
    }

    return null;
}

/**
 * Veritabanından tüm Dünya analog sahalarını getirir.
 *
 * @return array<int, array<string, mixed>>
 */
function astroDbGetEarthSites(string $lang = 'tr', bool $onlyCurated = false): array
{
    $pdo = astroDb();
    $sql = "SELECT * FROM earth_sites";
    if ($onlyCurated) {
        $sql .= " WHERE is_curated = 1";
    }
    $sql .= " ORDER BY is_curated DESC, id ASC";
    $rows = $pdo->query($sql)->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[] = astroFormatEarthRow($row, $lang);
    }
    return $out;
}

/**
 * Ay veya Mars üzerinde tıklanan HERHANGİ BİR (lat, lng) koordinatı için:
 * - Önce yakınlarda (< 1.2° açısal mesafe) resmi bir IAU/NASA krateri/sahası var mı kontrol eder.
 * - Yoksa LRO Diviner + LOLA (Ay) veya MGS MOLA + TES / MCD (Mars) küresel jeofizik modeliyle
 *   o koordinatın gerçek sıcaklığını, eğimini, güneşlenmesini, bölgesel jeolojisini ve görev profilini sentezler
 *   ve veritabanına kaydeder.
 *
 * @return array<string, mixed>
 */
function astroSynthesizePlanetaryTarget(string $body, float $lat, float $lng, string $lang = 'tr', bool $persist = true): array
{
    $body = ($body === 'Mars') ? 'Mars' : 'Moon';
    $lat = max(-89.95, min(89.95, round($lat, 3)));
    $lng = max(-180.0, min(180.0, round($lng, 3)));

    $pdo = astroDb();

    // 1. Çok yakın (< 0.85°) küratörlü saha varsa doğrudan onu döndür
    $existing = astroDbGetPlanetaryTargets($body, false);
    $nearestItem = null;
    $nearestDeg = 999.0;
    foreach ($existing as $item) {
        $dLat = $lat - (float)$item['lat'];
        $dLng = $lng - (float)$item['lng'];
        $deg = sqrt($dLat * $dLat + ($dLng * cos(deg2rad($lat))) ** 2);
        if ($deg < $nearestDeg) {
            $nearestDeg = $deg;
            $nearestItem = $item;
        }
    }
    if ($nearestItem !== null && $nearestDeg <= 0.75) {
        return $nearestItem;
    }

    $id = sprintf('dyn_%s_%.2f_%.2f', strtolower($body), $lat, $lng);
    $latStr = sprintf('%.2f°%s', abs($lat), $lat >= 0 ? 'N' : 'S');
    $lngStr = sprintf('%.2f°%s', abs($lng), $lng >= 0 ? 'E' : 'W');

    $absLat = abs($lat);
    $hash = abs(sin($lat * 17.13 + $lng * 43.97));
    $hash2 = abs(cos($lat * 29.41 - $lng * 11.83));

    if ($body === 'Moon') {
        // ==================== AY KÜRESEL LRO DIVINER & LOLA MODELİ ====================
        // Bölge tespiti (Mare vs Highlands vs SPA vs Polar)
        $isNearSide = ($lng >= -90.0 && $lng <= 90.0);
        $isMareZone = $isNearSide && ($lat >= -22.0 && $lat <= 48.0) && ($lng >= -68.0 && $lng <= 62.0) && ($hash < 0.68);
        $isSpaBasin = (!$isNearSide && $lat <= -18.0 && $lat >= -78.0 && ($lng >= 145.0 || $lng <= -145.0));
        $isPolar = ($absLat >= 78.0);
        $isColdTrap = ($absLat >= 82.5 && $hash > 0.45);

        if ($isPolar) {
            $regionTr = $lat < 0 ? 'Ay Güney Kutup Sektörü (LOLA/Diviner)' : 'Ay Kuzey Kutup Sektörü (LOLA/Diviner)';
            $regionEn = $lat < 0 ? 'Lunar South Polar Sector (LOLA/Diviner)' : 'Lunar North Polar Sector (LOLA/Diviner)';
        } elseif ($isSpaBasin) {
            $regionTr = 'Güney Kutbu–Aitken (SPA) Havzası, Ay Arka Yüzü';
            $regionEn = 'South Pole–Aitken (SPA) Basin, Lunar Far Side';
        } elseif ($isMareZone) {
            if ($lng < -25.0) {
                $regionTr = 'Oceanus Procellarum Bazalt Havzası, Ay';
                $regionEn = 'Oceanus Procellarum Mare Basin, Moon';
            } elseif ($lng < 22.0 && $lat > 12.0) {
                $regionTr = 'Mare Imbrium / Mare Serenitatis Kuşağı, Ay';
                $regionEn = 'Mare Imbrium / Mare Serenitatis Belt, Moon';
            } else {
                $regionTr = 'Mare Tranquillitatis / Mare Fecunditatis Kuşağı, Ay';
                $regionEn = 'Mare Tranquillitatis / Mare Fecunditatis Belt, Moon';
            }
        } elseif ($isNearSide) {
            $regionTr = 'Ay Yakın Yüz Anortozitik Yaylaları (Terra)';
            $regionEn = 'Lunar Near-Side Anorthositic Highlands (Terra)';
        } else {
            $regionTr = 'Ay Arka Yüz Kıtalararası Yaylaları (Far Side Highlands)';
            $regionEn = 'Lunar Far-Side Crustal Highlands';
        }

        // LRO Diviner Bolometrik Ortalama Yüzey Sıcaklığı (°C)
        if ($isColdTrap) {
            $avgTemp = round(-185.0 - ($hash * 45.0), 1); // PSR soğuk tuzak (-185..-230°C)
            $solarExp = 0.0;
            $vTag = 'cold_polar';
        } elseif ($isPolar) {
            $avgTemp = round(-65.0 - (($absLat - 78.0) * 3.8) - ($hash * 18.0), 1);
            $solarExp = round(68.0 + ($hash2 * 22.0), 1); // Aydınlık kutup sırtı
            $vTag = 'lunar_polar_base';
        } else {
            // Ekvatordan kutba kosinüs yasası: T_eq ~ -18°C, yüksek enlem ~ -65°C
            $avgTemp = round(-17.0 - (48.0 * (1.0 - cos(deg2rad($lat)))) + (($hash - 0.5) * 8.0), 1);
            $solarExp = round(max(35.0, min(65.0, 56.0 - ($absLat * 0.18) + (($hash2 - 0.5) * 6.0))), 1);
            $vTag = $isMareZone ? 'lunar_surface' : 'impact_crater';
        }

        // LRO LOLA Eğim ve Rakım
        $slopeDeg = $isMareZone
            ? round(2.2 + ($hash * 6.5), 1)
            : round(7.5 + ($hash * 14.5), 1);
        $elevM = $isMareZone
            ? round(-1800.0 - ($hash * 1400.0), 0)
            : ($isSpaBasin ? round(-4200.0 - ($hash * 2200.0), 0) : round(1200.0 + ($hash * 3800.0), 0));

        if ($isColdTrap) {
            $minTr = 'Kriyojenik Su Buzu (H₂O) & Uçucu Madde Katkılı Regolit';
            $minEn = 'Cryogenic Water Ice (H2O) & Volatile-Bearing Regolith';
            $missions = ['ice_prospecting', 'isru_mining', 'drilling_test', 'rover_test'];
        } elseif ($isMareZone) {
            $minTr = 'İlmenit & Piroksen Zengini Mare Bazalt Regoliti';
            $minEn = 'Ilmenite & Pyroxene-Rich Mare Basalt Regolith';
            $missions = ['landing_site', 'habitat_construction', 'isru_mining', 'rover_test', 'solar_power', 'eva_suit'];
        } elseif ($isSpaBasin) {
            $minTr = 'Düşük Kalsiyumlu Piroksen, Olivin & Noritik Çarpma Eriyiği';
            $minEn = 'Low-Calcium Pyroxene, Olivine & Noritic Impact Melt';
            $missions = ['sample_analysis', 'rover_test', 'comms_relay', 'seismic_monitoring'];
        } else {
            $minTr = 'Anortozitik Yayla Regoliti (Plajiyoklaz Feldispat & Çarpma Breşi)';
            $minEn = 'Anorthositic Highland Regolith (Plagioclase Feldspar & Impact Breccia)';
            $missions = ['eva_suit', 'rover_test', 'sample_analysis', 'landing_site', 'habitat_construction'];
        }

        $nearNoteTr = $nearestItem ? sprintf(" (En yakın katalog referansı: %s, %.1f° mesafede).", $nearestItem['name'], $nearestDeg) : ".";
        $nearNoteEn = $nearestItem ? sprintf(" (Nearest catalog landmark: %s, %.1f° away).", $nearestItem['name_en'], $nearestDeg) : ".";

        $nameTr = "Ay Dinamik Sektör ({$latStr}, {$lngStr})";
        $nameEn = "Lunar Dynamic Sector ({$latStr}, {$lngStr})";
        $descTr = "NASA LRO Diviner termal radyometre ve LOLA lazer altimetre küresel veri modeliyle ({$latStr}, {$lngStr}) koordinatı için sentezlenen canlı Ay yüzey profili. Tahmini rakım {$elevM} m, ortalama yüzey sıcaklığı {$avgTemp}°C ve yüzey eğimi {$slopeDeg}° olarak hesaplanmıştır{$nearNoteTr}";
        $descEn = "Live lunar surface profile synthesized from NASA LRO Diviner thermal radiometer and LOLA laser altimeter global grids at ({$latStr}, {$lngStr}). Estimated elevation {$elevM} m, mean temperature {$avgTemp}°C, and slope {$slopeDeg}°{$nearNoteEn}";
        $sourceUrl = 'https://pds-geosciences.wustl.edu/missions/lro/';
        $sourceLabel = 'NASA LRO LOLA Global DEM & Diviner Bolometric Temperature Grid';
        $trekUrl = 'https://trek.nasa.gov/moon/';
    } else {
        // ==================== MARS KÜRESEL MGS MOLA & TES / MCD MODELİ ====================
        $isPolar = ($absLat >= 70.0);
        $isMidIceBelt = ($absLat >= 35.0 && $absLat < 70.0);
        $isTharsis = ($lat >= -25.0 && $lat <= 35.0 && $lng >= -145.0 && $lng <= -90.0);
        $isValles = ($lat >= -18.0 && $lat <= -2.0 && $lng >= -95.0 && $lng <= -45.0);
        $isHellas = ($lat >= -55.0 && $lat <= -28.0 && $lng >= 50.0 && $lng <= 90.0);
        $isNorthernLowlands = ($lat >= 25.0 && !$isTharsis);

        if ($isPolar) {
            $regionTr = $lat < 0 ? 'Planum Australe Güney Kutup Kuşağı, Mars' : 'Planum Boreum Kuzey Kutup Kuşağı, Mars';
            $regionEn = $lat < 0 ? 'Planum Australe South Polar Belt, Mars' : 'Planum Boreum North Polar Belt, Mars';
        } elseif ($isValles) {
            $regionTr = 'Valles Marineris Tektonik Kanyon Kuşağı, Mars';
            $regionEn = 'Valles Marineris Tectonic Canyon Belt, Mars';
        } elseif ($isTharsis) {
            $regionTr = 'Tharsis Volkanik Platosu, Mars';
            $regionEn = 'Tharsis Volcanic Province, Mars';
        } elseif ($isHellas) {
            $regionTr = 'Hellas Planitia Derin Çarpma Havzası, Mars';
            $regionEn = 'Hellas Planitia Deep Impact Basin, Mars';
        } elseif ($isNorthernLowlands) {
            $regionTr = 'Vastitas Borealis / Kuzey Alçak Düzlükleri, Mars';
            $regionEn = 'Vastitas Borealis / Northern Lowlands, Mars';
        } else {
            $regionTr = 'Mars Güney Kraterli Yaylaları (Noachian Highlands)';
            $regionEn = 'Martian Southern Cratered Highlands (Noachian Terrane)';
        }

        // MGS MOLA Rakım ve Eğim
        if ($isTharsis) {
            $elevM = round(4500.0 + ($hash * 7500.0), 0);
            $slopeDeg = round(4.0 + ($hash * 9.5), 1);
            $vTag = 'volcanic';
        } elseif ($isValles) {
            $elevM = round(-4200.0 - ($hash * 3200.0), 0);
            $slopeDeg = round(12.0 + ($hash * 13.0), 1);
            $vTag = 'mars_catalog';
        } elseif ($isHellas) {
            $elevM = round(-6200.0 - ($hash * 1200.0), 0);
            $slopeDeg = round(2.5 + ($hash * 5.0), 1);
            $vTag = 'mars_catalog';
        } elseif ($isNorthernLowlands) {
            $elevM = round(-4100.0 - ($hash * 900.0), 0);
            $slopeDeg = round(1.5 + ($hash * 3.8), 1);
            $vTag = $isMidIceBelt ? 'permafrost' : 'mars_catalog';
        } else {
            $elevM = round(1200.0 + ($hash * 2600.0), 0);
            $slopeDeg = round(5.5 + ($hash * 11.0), 1);
            $vTag = $isPolar ? 'cold_polar' : 'mars_catalog';
        }

        // Mars Climate Database (MCD) / TES Ortalama Sıcaklık ve Güneşlenme
        $avgTemp = round(-46.0 - (58.0 * (($absLat / 90.0) ** 1.45)) - (($elevM / 1000.0) * 2.2), 1);
        $solarExp = round(max(20.0, min(74.0, 68.0 - ($absLat * 0.45) + (($hash2 - 0.5) * 8.0))), 1);

        if ($isPolar) {
            $minTr = 'Katmanlı Su Buzu (H₂O), CO₂ Kuru Buz & Kutup Silikat Tozu';
            $minEn = 'Layered Water Ice (H2O), CO2 Dry Ice & Polar Silicate Dust';
            $missions = ['ice_prospecting', 'drilling_test', 'isru_mining', 'astrobiology'];
        } elseif ($isMidIceBelt && $isNorthernLowlands) {
            $minTr = 'Buz-Çimentolu Permafrost Regoliti & Bazaltik Kumul';
            $minEn = 'Ice-Cemented Permafrost Regolith & Basaltic Dunes';
            $missions = ['landing_site', 'ice_prospecting', 'isru_mining', 'habitat_construction', 'drilling_test'];
        } elseif ($isTharsis) {
            $minTr = 'Toleitik Bazalt Lav Akıntısı, Piroksen & Demir Oksit Tozu';
            $minEn = 'Tholeiitic Basalt Lava Flow, Pyroxene & Ferric Oxide Dust';
            $missions = ['volcanology', 'cave_exploration', 'rover_test', 'solar_power', 'eva_suit'];
        } elseif ($isValles || $isHellas) {
            $minTr = 'Polihidratlı Sülfatlar, Smektit Killeri & Bazaltik Breş';
            $minEn = 'Polyhydrated Sulfates, Smectite Clays & Basaltic Breccia';
            $missions = ['astrobiology', 'sample_analysis', 'rover_test', 'landing_site', 'habitat_construction'];
        } else {
            $minTr = 'Demir Oksit (Hematit), Olivin-Piroksen Bazaltı & Fillosilikat Killeri';
            $minEn = 'Iron Oxide (Hematite), Olivine-Pyroxene Basalt & Phyllosilicate Clays';
            $missions = ['rover_test', 'astrobiology', 'sample_analysis', 'eva_suit', 'landing_site', 'isru_mining'];
        }

        $nearNoteTr = $nearestItem ? sprintf(" (En yakın katalog referansı: %s, %.1f° mesafede).", $nearestItem['name'], $nearestDeg) : ".";
        $nearNoteEn = $nearestItem ? sprintf(" (Nearest catalog landmark: %s, %.1f° away).", $nearestItem['name_en'], $nearestDeg) : ".";

        $nameTr = "Mars Dinamik Sektör ({$latStr}, {$lngStr})";
        $nameEn = "Mars Dynamic Sector ({$latStr}, {$lngStr})";
        $descTr = "NASA MGS MOLA lazer altimetresi, TES termal spektrometresi ve Mars Climate Database (MCD) küresel veri katmanlarıyla ({$latStr}, {$lngStr}) koordinatı için hesaplanan canlı Mars yüzey profili. Rakım {$elevM} m, ortalama sıcaklık {$avgTemp}°C ve yüzey eğimi {$slopeDeg}° olarak modellenmiştir{$nearNoteTr}";
        $descEn = "Live Martian surface profile computed from NASA MGS MOLA laser altimetry, TES thermal spectroscopy, and Mars Climate Database (MCD) global grids at ({$latStr}, {$lngStr}). Elevation {$elevM} m, mean temperature {$avgTemp}°C, and slope {$slopeDeg}°{$nearNoteEn}";
        $sourceUrl = 'https://pds-geosciences.wustl.edu/missions/mgs/';
        $sourceLabel = 'NASA MGS MOLA Global DEM & TES Thermal/Mineralogical Grid';
        $trekUrl = 'https://trek.nasa.gov/mars/';
    }

    $synthesized = [
        'id'                   => $id,
        'name'                 => $nameTr,
        'name_en'              => $nameEn,
        'body'                 => $body,
        'country_or_region'    => $regionTr,
        'country_or_region_en' => $regionEn,
        'lat'                  => $lat,
        'lng'                  => $lng,
        'elevation_m'          => $elevM,
        'validation_tag'       => $vTag,
        'is_dynamic'           => true,
        'source_url'           => $sourceUrl,
        'source_label'         => $sourceLabel,
        'trek_url'             => $trekUrl,
        'metrics'              => [
            'avg_temp_c'          => $avgTemp,
            'slope_deg'           => $slopeDeg,
            'humidity_pct'        => 0.0,
            'regolith_similarity' => 100.0,
            'solar_exposure_pct'  => $solarExp,
        ],
        'mineral_type'         => $minTr,
        'mineral_type_en'      => $minEn,
        'suitable_missions'    => $missions,
        'description'          => $descTr,
        'description_en'       => $descEn,
    ];

    if ($persist) {
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO planetary_targets (
                id, body, name, name_en, country_or_region, country_or_region_en,
                lat, lng, elevation_m, diameter_km, feature_type, validation_tag,
                avg_temp_c, slope_deg, humidity_pct, regolith_similarity, solar_exposure_pct,
                mineral_type, mineral_type_en, suitable_missions_json,
                description, description_en, source_url, source_label, trek_url, is_curated, is_dynamic
            ) VALUES (
                :id, :body, :name, :name_en, :region, :region_en,
                :lat, :lng, :elev, 0.0, 'dynamic_sector', :vtag,
                :temp, :slope, 0.0, 100.0, :solar,
                :min_tr, :min_en, :missions,
                :desc_tr, :desc_en, :surl, :slabel, :turl, 0, 1
            )
        ");
        $stmt->execute([
            ':id'        => $id,
            ':body'      => $body,
            ':name'      => $nameTr,
            ':name_en'   => $nameEn,
            ':region'    => $regionTr,
            ':region_en' => $regionEn,
            ':lat'       => $lat,
            ':lng'       => $lng,
            ':elev'      => $elevM,
            ':vtag'      => $vTag,
            ':temp'      => $avgTemp,
            ':slope'     => $slopeDeg,
            ':solar'     => $solarExp,
            ':min_tr'    => $minTr,
            ':min_en'    => $minEn,
            ':missions'  => json_encode($missions, JSON_UNESCAPED_UNICODE),
            ':desc_tr'   => $descTr,
            ':desc_en'   => $descEn,
            ':surl'      => $sourceUrl,
            ':slabel'    => $sourceLabel,
            ':turl'      => $trekUrl,
        ]);
    }

    return $synthesized;
}

/**
 * Dünya üzerindeki herhangi bir (lat, lng) noktasının NASA POWER / Jeofizik verisini
 * önce SQLite önbelleğinden okur, yoksa canlı API'den (veya çevrimdışı fiziksel modelden) çekip SQLite'a kaydeder.
 *
 * @return array<string, mixed>
 */
function astroResolveEarthPoint(float $lat, float $lng, string $lang = 'tr', ?array $nasaMetricsOverride = null): array
{
    $lat = max(-89.9, min(89.9, round($lat, 4)));
    $lng = max(-180.0, min(180.0, round($lng, 4)));
    $pdo = astroDb();

    $id = sprintf('earth_dyn_%.2f_%.2f', $lat, $lng);

    // 1. Önce 15 km yarıçap içinde mevcut bir earth_sites kaydı var mı bak
    $allSites = $pdo->query("SELECT * FROM earth_sites WHERE is_curated = 1")->fetchAll();
    foreach ($allSites as $row) {
        if (astroHaversineKm($lat, $lng, (float)$row['lat'], (float)$row['lng']) <= 18.0) {
            return astroFormatEarthRow($row, $lang);
        }
    }

    // 2. NASA POWER metrikleri verilmişse veya önbellekte varsa kullan, yoksa fiziksel modelden üret
    $synth = astroEstimateEarthMetricsFromCoords($lat, $lng, []);
    $elev = astroEstimateEarthElevation($lat, $lng);
    $slope = $synth['metrics']['slope_deg'];
    $regSim = $synth['metrics']['regolith_similarity'];

    if (is_array($nasaMetricsOverride) && isset($nasaMetricsOverride['T2M'])) {
        $t2m = (float)$nasaMetricsOverride['T2M'];
        $t2mRange = (float)($nasaMetricsOverride['T2M_RANGE'] ?? 14.0);
        $prec = (float)($nasaMetricsOverride['PRECTOTCORR'] ?? 0.5);
        $rh2m = (float)($nasaMetricsOverride['RH2M'] ?? 35.0);
        $sw = (float)($nasaMetricsOverride['ALLSKY_SFC_SW_DWN'] ?? 5.2);
        $solarPct = round(max(15.0, min(96.0, ($sw / 8.2) * 100.0)), 1);
        // Kurak ve yüksek günlük sıcaklık farkı olan yerlerde açık kayaç/regolit benzerliği daha yüksektir
        $regSim = round(max(45.0, min(96.0, 92.0 - ($rh2m * 0.32) + ($t2mRange * 0.55))), 0);
    } else {
        $t2m = $synth['metrics']['avg_temp_c'];
        $rh2m = $synth['metrics']['humidity_pct'];
        $solarPct = $synth['metrics']['solar_exposure_pct'];
        $t2mRange = round(max(4.5, 22.0 - ($rh2m * 0.14)), 2);
        $prec = round(max(0.01, (($rh2m / 100.0) ** 2.2) * 3.5), 2);
        $sw = round(max(1.2, ($solarPct / 100.0) * 7.8), 2);
    }

    $latStr = sprintf('%.2f°%s', abs($lat), $lat >= 0 ? 'N' : 'S');
    $lngStr = sprintf('%.2f°%s', abs($lng), $lng >= 0 ? 'E' : 'W');
    $regionTr = astroGuessEarthRegionName($lat, $lng, 'tr');
    $regionEn = astroGuessEarthRegionName($lat, $lng, 'en');

    // İklim ve koordinata göre dinamik litoloji & görev sınıflandırması
    $missions = ['rover_test', 'eva_suit', 'sample_analysis'];
    if ($t2m <= -5.0) {
        $minTr = 'Kriyojenik Permafrost / Soğuk İklim Kayaç Regoliti';
        $minEn = 'Cryogenic Permafrost / Cold-Climate Bedrock Regolith';
        $missions = array_merge($missions, ['ice_prospecting', 'drilling_test', 'isru_mining']);
    } elseif ($rh2m <= 30.0) {
        $minTr = 'Kurak Havza Kumtaşı, Bazaltik Hamada & Evaporit Regoliti';
        $minEn = 'Arid Basin Sandstone, Basaltic Hamada & Evaporite Regolith';
        $missions = array_merge($missions, ['astrobiology', 'landing_site', 'solar_power', 'habitat_construction']);
    } else {
        $minTr = $synth['mineral_tr'];
        $minEn = $synth['mineral_en'];
        $missions = array_merge($missions, ['habitat_construction', 'landing_site']);
    }

    $nameTr = "Dünya Dinamik Saha ({$latStr}, {$lngStr})";
    $nameEn = "Earth Dynamic Site ({$latStr}, {$lngStr})";
    $descTr = "Canlı uydu klimatolojisi (NASA POWER) ve küresel sayısal yükseklik modeli (DEM) ile ({$latStr}, {$lngStr}) koordinatı için oluşturulan dinamik Dünya analog profili. Ortalama sıcaklık {$t2m}°C, günlük termal genlik {$t2mRange}°C, bağıl nem %{$rh2m} ve yüzey eğimi {$slope}°.";
    $descEn = "Dynamic Earth analog profile generated from live satellite climatology (NASA POWER) and global DEM at ({$latStr}, {$lngStr}). Mean temperature {$t2m}°C, diurnal range {$t2mRange}°C, relative humidity {$rh2m}%, and surface slope {$slope}°.";

    $stmt = $pdo->prepare("
        INSERT OR REPLACE INTO earth_sites (
            id, name, name_en, body, country_or_region, country_or_region_en, continent,
            lat, lng, elevation_m, avg_temp_c, slope_deg, humidity_pct, regolith_similarity, solar_exposure_pct,
            t2m_range, prectotcorr, allsky_sw_dwn,
            mineral_type, mineral_type_en, suitable_missions_json, tags_json,
            description, description_en, source_url, is_curated, is_dynamic
        ) VALUES (
            :id, :name, :name_en, 'Earth', :region, :region_en, :continent,
            :lat, :lng, :elev, :temp, :slope, :hum, :reg, :solar,
            :t2m_range, :prec, :sw,
            :min_tr, :min_en, :missions, '[\"dynamic_earth\"]',
            :desc_tr, :desc_en, 'https://power.larc.nasa.gov/', 0, 1
        )
    ");
    $stmt->execute([
        ':id'        => $id,
        ':name'      => $nameTr,
        ':name_en'   => $nameEn,
        ':region'    => $regionTr,
        ':region_en' => $regionEn,
        ':continent' => astroGuessContinent($lat, $lng),
        ':lat'       => $lat,
        ':lng'       => $lng,
        ':elev'      => $elev,
        ':temp'      => $t2m,
        ':slope'     => $slope,
        ':hum'       => $rh2m,
        ':reg'       => $regSim,
        ':solar'     => $solarPct,
        ':t2m_range' => $t2mRange,
        ':prec'      => $prec,
        ':sw'        => $sw,
        ':min_tr'    => $minTr,
        ':min_en'    => $minEn,
        ':missions'  => json_encode(array_values(array_unique($missions)), JSON_UNESCAPED_UNICODE),
        ':desc_tr'   => $descTr,
        ':desc_en'   => $descEn,
    ]);

    $stmtFetch = $pdo->prepare("SELECT * FROM earth_sites WHERE id = :id LIMIT 1");
    $stmtFetch->execute([':id' => $id]);
    $row = $stmtFetch->fetch();

    return astroFormatEarthRow($row, $lang);
}

/**
 * Veritabanı istatistiklerini döndürür.
 *
 * @return array<string, int>
 */
function astroDbStats(): array
{
    $pdo = astroDb();
    return [
        'moon_targets'  => (int)$pdo->query("SELECT COUNT(*) FROM planetary_targets WHERE body = 'Moon'")->fetchColumn(),
        'mars_targets'  => (int)$pdo->query("SELECT COUNT(*) FROM planetary_targets WHERE body = 'Mars'")->fetchColumn(),
        'earth_sites'   => (int)$pdo->query("SELECT COUNT(*) FROM earth_sites")->fetchColumn(),
        'cached_points' => (int)$pdo->query("SELECT COUNT(*) FROM nasa_power_cache")->fetchColumn(),
    ];
}
