<?php

declare(strict_types=1);

require_once __DIR__ . '/api/db.php';

$extraterrestrialList = astroDbGetPlanetaryTargets(null, false);
$earthAnalogsList = astroDbGetEarthSites('tr', false);
$dbStats = astroDbStats();

$extraterrestrialJson = json_encode($extraterrestrialList, JSON_UNESCAPED_UNICODE);
$earthAnalogsJson = json_encode($earthAnalogsList, JSON_UNESCAPED_UNICODE);
$dbStatsJson = json_encode($dbStats, JSON_UNESCAPED_UNICODE);
$dataSourcesJson = @file_get_contents(__DIR__ . '/data/data_sources.json') ?: '[]';
$cavesJson = @file_get_contents(__DIR__ . '/data/caves.json') ?: '{"sites":[]}';
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EXPLOG - NASA Space Apps 2026</title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Fonts: Inter, Space Grotesk & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- Custom Space / Google Earth Style CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>

<body class="space-body">

    <!-- Floating Google Earth-Style Top Control Bar -->
    <header class="floating-header">
        <div class="glass-pill header-brand">
            <span class="brand-dot"></span>
            <div class="brand-text">
                <span class="brand-title">EXP<span class="text-info">LOG</span></span>
                <span class="brand-sub">NASA Space Apps 2026 • Open Science</span>
            </div>
        </div>

        <div class="glass-pill header-controls">
            <!-- Moon / Mars Toggle -->
            <div class="body-toggle" role="group" aria-label="Gök Cismi Seçimi">
                <button type="button" class="toggle-btn active" id="btnBodyMoon" data-body="Moon">
                    <i class="bi bi-moon-stars me-1"></i>Moon
                </button>
                <button type="button" class="toggle-btn" id="btnBodyMars" data-body="Mars">
                    <i class="bi bi-globe2 me-1"></i>Mars
                </button>
            </div>

            <div class="control-divider"></div>

            <!-- Mission Type Filter -->
            <div class="mission-filter-wrap" id="missionFilterWrap">
                <i class="bi bi-sliders2 filter-icon"></i>
                <select id="missionFilter" class="form-select form-select-sm mission-select" aria-label="Görev Türü Filtresi">
                    <option value="all">Tüm Görevler</option>
                    <option value="rover_test">Rover Sürüş Testi</option>
                    <option value="eva_suit">Uzay Giysisi (EVA)</option>
                    <option value="isru_mining">Kaynak Kullanımı (ISRU)</option>
                    <option value="habitat_construction">Habitat İnşası</option>
                    <option value="landing_site">İniş Sahası</option>
                    <option value="solar_power">Güneş Enerjisi</option>
                    <option value="ice_prospecting">Buz Arama</option>
                    <option value="comms_relay">İletişim Aktarımı</option>
                    <option value="cave_exploration">Mağara / Lav Tüpü Keşfi</option>
                    <option value="radiation_shelter">Radyasyon Sığınağı</option>
                    <option value="astrobiology">Astrobiyoloji</option>
                    <option value="sample_analysis">Numune Analizi</option>
                    <option value="seismic_monitoring">Sismik İzleme</option>
                    <option value="drilling_test">Sondaj Testi</option>
                    <option value="volcanology">Volkanoloji</option>
                    <option value="hazard_avoidance">Tehlike Kaçınma</option>
                </select>
            </div>

            <div class="control-divider"></div>

            <!-- Keşif Modu (Curated / Mod 1 / Mod 2) -->
            <div class="body-toggle mode-toggle" role="group" aria-label="Keşif Modu">
                <button type="button" class="toggle-btn active" id="btnModeCurated" data-mode="curated">
                    <i class="bi bi-pin-map me-1"></i><span data-i18n="mod0">Katalog Sahaları</span>
                </button>
                <button type="button" class="toggle-btn" id="btnModePoint" data-mode="point">
                    <i class="bi bi-crosshair me-1"></i><span data-i18n="mod1">Nokta Analizi</span>
                </button>
                <button type="button" class="toggle-btn" id="btnModeGrid" data-mode="grid">
                    <i class="bi bi-bounding-box-circles me-1"></i><span data-i18n="mod2">Bölge Tarama</span>
                </button>
            </div>
        </div>

        <div class="glass-pill header-status">
            <span class="status-indicator d-none d-lg-inline-block" id="apiStatusDot"></span>
            <span id="apiStatusText" class="mono-text d-none d-lg-inline">Hazır • Küreden bir hedef seçin</span>
            <div class="control-divider d-none d-lg-block"></div>
            <div class="lang-select-wrap">
                <i class="bi bi-translate text-info"></i>
                <select id="langSelect" class="form-select form-select-sm mission-select" aria-label="Dil Seçimi">
                    <option value="tr">Türkçe</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div class="control-divider"></div>
            <button type="button" id="btnMethodology" class="methodology-btn" data-bs-toggle="modal" data-bs-target="#methodologyModal" title="Metodoloji ve NASA Veri Kaynakları">
                <i class="bi bi-journal-code text-info"></i>
                <span class="d-none d-xl-inline" data-i18n="btn_methodology">Metodoloji</span>
            </button>
        </div>
    </header>

    <!-- Full-Screen 100vh Split-View 3D Globes -->
    <main class="split-universe">
        <!-- Left Pane: Earth 3D Globe -->
        <section class="globe-pane globe-pane-left" aria-label="Dünya Analog Küresi">
            <div id="earthGlobe" class="globe-canvas"></div>

            <!-- Mode 1 Hint Pill (Nokta Analizi) -->
            <div id="earthModeHint" class="earth-mode-hint d-none">
                <i class="bi bi-crosshair2 text-info me-2"></i>
                <span data-i18n="point_hint">Dünya küresinde herhangi bir noktaya tıklayın — canlı NASA POWER verisi, Novelty ve TRL 5-6 analizi çekilir.</span>
            </div>

            <!-- Mode 2 Slide-Out Drawer (Soldan Sağa Açılır Cetvel, EVA Traverse & Bölge Taraması) -->
            <div id="gridSearchPanel" class="grid-search-panel d-none">
                <button type="button" id="btnToggleGridPanel" class="grid-drawer-handle" title="Cetvel Panelini Aç / Kapat">
                    <i class="bi bi-chevron-left" id="gridDrawerChevron"></i>
                </button>
                <div class="grid-panel-header">
                    <span><i class="bi bi-rulers text-info me-2"></i><span data-i18n="grid_title">Cetvel, EVA Rota &amp; Tarama</span></span>
                    <span class="badge bg-info bg-opacity-25 text-info mono-text">NASA POWER</span>
                </div>

                <div class="ruler-toolbar mt-1">
                    <div class="ruler-hint" data-i18n="ruler_hint">
                        Küreye tıklayarak EVA yürüyüş rotası veya tarama köşeleri (P1, P2...) çizin:
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-2 mt-1">
                        <span id="rulerStats" class="mono-text text-info">0 Nokta • 0 km</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" id="btnRulerUndo" class="btn btn-outline-secondary btn-xs" title="Son Noktayı Geri Al">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                            <button type="button" id="btnRulerClear" class="btn btn-outline-danger btn-xs" title="Çizimi Temizle">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>
                    </div>
                    <!-- Tobler EVA Traverse Live Telemetry Box -->
                    <div id="rulerTraverseBox" class="ruler-traverse-box d-none mt-2 pt-2 border-top border-secondary border-opacity-25">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-semibold text-warning"><i class="bi bi-person-walking me-1"></i><span data-i18n="eva_route_lbl">Tobler EVA Yürüyüş Analizi</span></span>
                            <span id="rulerWalkTime" class="badge bg-warning bg-opacity-25 text-warning mono-text">0.0 sa</span>
                        </div>
                        <div class="d-flex justify-content-between small text-secondary mono-text mb-1">
                            <span id="rulerClimbStat">+0m / -0m</span>
                            <span id="rulerHazardStat">Eğim &lt;15°</span>
                        </div>
                        <div class="d-flex gap-1 mt-1">
                            <button type="button" id="btnOpenRulerGodsEye" class="btn btn-outline-info btn-xs flex-grow-1">
                                <i class="bi bi-box me-1"></i><span data-i18n="btn_3d_route">3B Profil &amp; God's Eye</span>
                            </button>
                            <button type="button" id="btnExportRulerGeoJson" class="btn btn-outline-light btn-xs" title="GeoJSON İndir">
                                <i class="bi bi-filetype-json"></i> GeoJSON
                            </button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mt-1">
                    <div class="col-6">
                        <label class="grid-label" for="gridMinLat" data-i18n="min_lat">Min Enlem</label>
                        <input type="number" step="0.5" min="-85" max="85" id="gridMinLat" class="form-control form-control-sm grid-input" value="36.5">
                    </div>
                    <div class="col-6">
                        <label class="grid-label" for="gridMaxLat" data-i18n="max_lat">Maks Enlem</label>
                        <input type="number" step="0.5" min="-85" max="85" id="gridMaxLat" class="form-control form-control-sm grid-input" value="41.5">
                    </div>
                    <div class="col-6">
                        <label class="grid-label" for="gridMinLon" data-i18n="min_lon">Min Boylam</label>
                        <input type="number" step="0.5" min="-180" max="180" id="gridMinLon" class="form-control form-control-sm grid-input" value="27.0">
                    </div>
                    <div class="col-6">
                        <label class="grid-label" for="gridMaxLon" data-i18n="max_lon">Maks Boylam</label>
                        <input type="number" step="0.5" min="-180" max="180" id="gridMaxLon" class="form-control form-control-sm grid-input" value="43.0">
                    </div>
                </div>
                <div class="mt-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="grid-label mb-0" for="gridSizeRange" data-i18n="grid_res">Tarama Çözünürlüğü</label>
                        <span id="gridSizeValue" class="mono-text text-info">4×4 (16 Nokta)</span>
                    </div>
                    <input type="range" id="gridSizeRange" class="form-range mt-1" min="3" max="6" step="1" value="4">
                </div>
                <button type="button" id="btnRunGridSearch" class="btn btn-info btn-sm w-100 mt-2 fw-semibold">
                    <i class="bi bi-rocket-takeoff me-1"></i><span data-i18n="btn_scan">Bölgeyi Tara ve En İyi Adayları Bul</span>
                </button>
            </div>

            <div class="pane-hud pane-hud-bottom-left">
                <div class="glass-chip">
                    <span class="chip-badge earth-badge">EARTH</span>
                    <span id="earthHudLabel" class="mono-text">24 Analog Sahası</span>
                </div>
                <button type="button" id="btnToggleContinents" class="glass-chip continent-toggle-chip active" title="3B Kıta Katmanını Aç/Kapat">
                    <i class="bi bi-layers me-1 text-info"></i><span data-i18n="continents_btn">Kıtalar</span>
                </button>
                <div id="selectedContinentChip" class="glass-chip d-none">
                    <i class="bi bi-geo-alt text-warning"></i>
                    <span id="selectedContinentLabel" class="mono-text"></span>
                </div>
                <div class="glass-chip legend-chip d-none d-md-flex">
                    <span class="legend-dot high"></span><span>%80+</span>
                    <span class="legend-dot mid"></span><span>%65-79</span>
                    <span class="legend-dot low"></span><span>&lt;%65</span>
                </div>
            </div>
        </section>

        <!-- Draggable Vertical Split Divider -->
        <div id="splitDivider" class="split-divider" role="separator" aria-orientation="vertical" title="Sürükleyerek boyutlandır (çift tıkla sıfırla)">
            <div class="split-divider-handle"></div>
        </div>

        <!-- Right Pane: Moon / Mars 3D Globe -->
        <section class="globe-pane globe-pane-right" aria-label="Ay ve Mars Hedef Küresi">
            <div id="spaceGlobe" class="globe-canvas"></div>

            <div class="pane-hud pane-hud-bottom-right">
                <div class="glass-chip" title="Ay veya Mars küresinde herhangi bir koordinata tıklayarak canlı LOLA/Diviner veya MOLA/TES analizi başlatabilirsiniz">
                    <i class="bi bi-crosshair text-warning"></i>
                    <span class="mono-text d-none d-xl-inline" data-i18n="space_click_hint">Kürenin Herhangi Bir Noktasına Tıklayın</span>
                </div>
                <div class="glass-chip">
                    <span class="chip-badge moon-badge" id="spaceBodyBadge">MOON</span>
                    <span id="spaceHudLabel" class="mono-text"><?= (int)$dbStats['moon_targets'] ?> Hedef Lokasyon</span>
                </div>
            </div>
        </section>
    </main>

    <!-- Earth Analog Site Detail Card (Opens on 1-click on any Earth analog) -->
    <aside id="earthSiteCard" class="earth-site-card collapsed" aria-hidden="true">
        <div class="site-card-header">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <div class="site-flag-wrap" id="siteCardFlagWrap">
                    <img id="siteCardFlag" class="site-flag-img" src="" alt="Ülke Bayrağı">
                    <span id="siteCardFlagFallback" class="site-flag-fallback d-none">🌍</span>
                </div>
                <div class="min-w-0">
                    <div class="site-card-title text-truncate" id="siteCardTitle">—</div>
                    <div class="site-card-sub mono-text text-truncate" id="siteCardSubtitle">—</div>
                </div>
            </div>
            <button type="button" class="btn-icon" id="btnCloseSiteCard" title="Kapat">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="site-card-body">
            <!-- Action Bar: God's Eye 3D & Mission Dossier -->
            <div class="d-flex gap-2 mt-2 mb-2">
                <button type="button" id="btnSiteGodsEye" class="btn btn-sm btn-info flex-grow-1 fw-semibold d-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-box-seam"></i>
                    <span data-i18n="btn_godseye">God's Eye 3B Arazi &amp; Gölge</span>
                </button>
                <button type="button" id="btnSiteDossier" class="btn btn-sm btn-outline-light d-flex align-items-center gap-1" title="Basılabilir Görev Dosyası (HTML Dossier)">
                    <i class="bi bi-file-earmark-medical text-info"></i>
                    <span data-i18n="btn_dossier_short">Dosya</span>
                </button>
            </div>

            <!-- Novelty & Nearest Analog Cave Context Strip -->
            <div id="siteCardContextStrip" class="site-context-strip mb-2"></div>

            <!-- Satellite Frames from Coordinates -->
            <div class="site-section-label">
                <i class="bi bi-camera me-1"></i><span id="siteFramesLabel">Bölgeden Uydu Kareleri</span>
            </div>
            <div class="site-frames-grid" id="siteCardFrames"></div>

            <!-- Specific Metrics -->
            <div class="site-section-label mt-3">
                <i class="bi bi-sliders me-1"></i><span id="siteSpecsLabel">Spesifik Özellikler</span>
            </div>
            <div class="site-specs-grid" id="siteCardSpecs"></div>

            <div class="site-mineral-box mt-2">
                <span class="text-secondary small" id="siteMineralLabel">Mineraloji:</span>
                <span class="text-info small fw-semibold ms-1" id="siteCardMineral">—</span>
            </div>

            <!-- TRL 5-6 Hardware Readiness Matrix in Site Card -->
            <div class="site-section-label mt-3">
                <i class="bi bi-cpu-fill me-1"></i><span data-i18n="trl_card_title">TRL 5–6 Donanım Test Uygunluğu</span>
            </div>
            <div id="siteCardReadiness" class="site-readiness-grid"></div>

            <p class="site-card-desc mt-2 mb-2" id="siteCardDesc">—</p>
            <div class="mission-tags" id="siteCardMissions"></div>
        </div>
    </aside>

    <!-- Satellite Frame Lightbox Overlay -->
    <div id="siteFrameLightbox" class="site-frame-lightbox d-none" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="site-lightbox-card">
            <img id="siteLightboxImg" src="" alt="Uydu Görüntüsü">
            <div class="site-lightbox-caption">
                <span id="siteLightboxTitle" class="fw-semibold"></span>
                <span id="siteLightboxBadge" class="badge bg-dark border border-secondary-subtle"></span>
            </div>
        </div>
    </div>

    <!-- Floating Comparison & Analysis Drawer (Bottom-Center / Clean & Scannable) -->
    <aside id="comparisonDrawer" class="comparison-drawer collapsed" aria-hidden="true">
        <div class="drawer-header">
            <div class="drawer-title-group">
                <div class="match-pair">
                    <span class="pair-target text-truncate" id="cmpTargetName">—</span>
                    <i class="bi bi-arrow-left-right pair-arrow"></i>
                    <span class="pair-earth text-truncate" id="cmpEarthName">—</span>
                    <span id="cmpNoveltyBadge" class="novelty-pill d-none"></span>
                </div>
                <div class="pair-meta mono-text text-truncate" id="cmpMetaSubtitle">
                    Konum &amp; Analog Rolü
                </div>
            </div>

            <div class="drawer-actions">
                <div class="similarity-badge" id="cmpSimilarityBadge">--%</div>
                <button type="button" class="btn-mission-action" id="btnDrawerGodsEye" title="3B Arazi, Alçak Güneş Gölgesi ve EVA Rota Planlayıcı">
                    <i class="bi bi-box-seam text-info"></i>
                    <span class="d-none d-md-inline" data-i18n="btn_godseye_short">3B Arazi</span>
                </button>
                <button type="button" class="btn-mission-action" id="btnDrawerDossier" title="Görev Raporu (HTML) İndir">
                    <i class="bi bi-file-earmark-richtext text-warning"></i>
                    <span class="d-none d-md-inline" data-i18n="btn_dossier">Rapor</span>
                </button>
                <button type="button" class="btn-icon" id="btnToggleDrawer" title="Paneli Küçült/Genişlet">
                    <i class="bi bi-chevron-down" id="drawerToggleIcon"></i>
                </button>
                <button type="button" class="btn-icon" id="btnCloseDrawer" title="Kapat">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <!-- Top Matches Quick Switcher Pills -->
        <div class="top-matches-bar" id="topMatchesBar"></div>

        <!-- Drawer Body: Clean 3-Column Grid (No Horizontal/Vertical Inner Scrollbars) -->
        <div class="drawer-body" id="drawerBody">
            <div class="drawer-grid">
                <!-- Left Column: Side-by-Side Technical Metrics -->
                <div class="panel-box d-flex flex-column justify-content-between">
                    <div>
                        <div class="panel-box-title d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-sliders2 me-1 text-info"></i><span data-i18n="tbl_title">Parametre Kıyaslaması</span></span>
                            <span id="cmpGeoScoreBadge" class="d-none"></span>
                        </div>
                        <table class="table table-sm table-borderless metrics-table mb-0">
                            <thead>
                                <tr>
                                    <th data-i18n="th_param">Parametre</th>
                                    <th class="text-end" id="tblTargetCol">Hedef</th>
                                    <th class="text-end text-info" data-i18n="th_earth">Dünya</th>
                                    <th class="text-end" data-i18n="th_match">Uyum</th>
                                </tr>
                            </thead>
                            <tbody id="metricsTableBody"></tbody>
                        </table>
                    </div>
                    <div class="mineral-compare mt-2 pt-2">
                        <div class="mineral-line" id="cmpTargetMineralRow">
                            <span class="mineral-lbl" data-i18n="lbl_target_min">Hedef:</span>
                            <span id="cmpTargetMineral" class="mineral-val text-light">—</span>
                        </div>
                        <div class="mineral-line" id="cmpEarthMineralRow">
                            <span class="mineral-lbl" data-i18n="lbl_earth_min">Dünya:</span>
                            <span id="cmpEarthMineral" class="mineral-val text-info">—</span>
                        </div>
                        <div id="cmpCaveRow" class="mineral-line d-none">
                            <span class="mineral-lbl" data-i18n="lbl_nearest_cave">Mağara:</span>
                            <span id="cmpNearestCave" class="mineral-val text-warning">—</span>
                        </div>
                    </div>
                </div>

                <!-- Center Column: Chart.js Radar Chart -->
                <div class="panel-box d-flex flex-column">
                    <div class="panel-box-title">
                        <i class="bi bi-radar me-1 text-info"></i><span data-i18n="radar_title">5-Parametre Radar Analizi</span>
                    </div>
                    <div class="radar-container flex-grow-1">
                        <canvas id="analogRadarChart"></canvas>
                    </div>
                </div>

                <!-- Right Column: Clear Summary, Hardware Readiness & Citations -->
                <div class="panel-box d-flex flex-column justify-content-between" id="rightStandardPanel">
                    <div>
                        <div class="panel-box-title d-flex justify-content-between align-items-center gap-2" id="rightPanelTitle">
                            <span class="text-truncate"><i class="bi bi-check2-circle me-1 text-info"></i><span data-i18n="why_title">Saha Özeti &amp; Donanım Uygunluğu</span></span>
                            <span id="cmpCitationLinks" class="citation-links-wrap flex-shrink-0"></span>
                        </div>
                        <div id="cmpRegimeBadge" class="regime-role-badge mb-2 d-none"></div>
                        <p class="scientific-desc mb-2 d-none" id="cmpEarthDesc">—</p>
                        <div class="calibration-callout mb-2" id="cmpCalibrationNote">—</div>
                        <div id="cmpReadinessBox" class="cmp-readiness-box"></div>
                    </div>
                    <div class="mission-tags mt-2 pt-1" id="cmpMissionTags"></div>
                </div>

                <div class="panel-box d-none flex-column" id="rightGridPanel">
                    <div class="panel-box-title d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-trophy me-1 text-warning"></i><span data-i18n="grid_rank_title">Keşfedilen Aday Sıralaması</span></span>
                        <span id="gridTotalBadge" class="mono-text text-info">0 Nokta</span>
                    </div>
                    <div class="table-responsive grid-results-scroll flex-grow-1">
                        <table class="table table-sm table-borderless metrics-table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Koordinat</th>
                                    <th class="text-center">Keşif</th>
                                    <th class="text-end">Sıc.</th>
                                    <th class="text-end">Skor</th>
                                </tr>
                            </thead>
                            <tbody id="gridResultsTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- God's Eye 3D Terrain, Lunar Low-Sun Shadow & EVA Traverse Simulator Modal -->
    <div class="modal fade" id="godsEyeModal" tabindex="-1" aria-labelledby="godsEyeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content methodology-modal-content godseye-modal-content">
                <div class="modal-header methodology-modal-header py-2 px-3">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-info bg-opacity-25 text-info mono-text">GOD'S EYE 3D DEM</span>
                        <h6 class="modal-title mb-0 fw-bold" id="godsEyeModalLabel">3B Arazi, Ay Gölge Simülatörü &amp; EVA Rota Planlayıcı</h6>
                        <span id="godsEyeCoordBadge" class="mono-text text-secondary small ms-2"></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" id="btnGodsEyeExportDossier" class="btn btn-xs btn-warning fw-semibold">
                            <i class="bi bi-file-earmark-richtext me-1"></i><span data-i18n="btn_export_dossier">Görev Dosyası İndir (HTML)</span>
                        </button>
                        <button type="button" id="btnGodsEyeExportGeoJson" class="btn btn-xs btn-outline-info">
                            <i class="bi bi-filetype-json me-1"></i>GeoJSON
                        </button>
                        <button type="button" class="btn-close btn-close-white ms-1" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                </div>
                <div class="modal-body p-0">
                    <div class="godseye-layout">
                        <!-- Left: Interactive Three.js 3D DEM Viewport -->
                        <div class="position-relative godseye-viewport-col">
                            <div id="godsEyeCanvasWrap" class="godseye-canvas-wrap"></div>
                            <div class="godseye-hud-top">
                                <span id="godsEyeStatusBadge" class="glass-chip mono-text">AWS Terrarium DEM + Sentinel-2</span>
                                <span class="glass-chip mono-text text-info"><i class="bi bi-cursor me-1"></i><span data-i18n="godseye_click_hint">3B araziye tıklayarak EVA rotası (P1..P8) ekleyin</span></span>
                            </div>
                            <div class="godseye-hud-bottom" id="godsEyeDrapeBar">
                                <div class="d-flex gap-1 flex-wrap">
                                    <button type="button" class="btn btn-xs btn-dark border-secondary-subtle active" data-drape="satellite">
                                        <i class="bi bi-globe-americas me-1 text-info"></i><span data-i18n="drape_sat">Uydu (Sentinel-2)</span>
                                    </button>
                                    <button type="button" class="btn btn-xs btn-dark border-secondary-subtle" data-drape="relief">
                                        <i class="bi bi-mountains me-1 text-light"></i><span data-i18n="drape_relief">3B Kabartma</span>
                                    </button>
                                    <button type="button" class="btn btn-xs btn-dark border-secondary-subtle" data-drape="thermal">
                                        <i class="bi bi-thermometer-half me-1 text-warning"></i><span data-i18n="drape_thermal">Termal Harita</span>
                                    </button>
                                    <button type="button" class="btn btn-xs btn-dark border-secondary-subtle" data-drape="slope">
                                        <i class="bi bi-exclamation-triangle me-1 text-danger"></i><span data-i18n="drape_slope">Eğim &amp; Rover Tehlike (&gt;15°)</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Controls, Lighting Presets, Telemetry & Tobler EVA Traverse Profile -->
                        <div class="godseye-console-col p-3">
                            <!-- 1. Solar Lighting & Lunar South Pole Low-Sun Simulator -->
                            <div class="godseye-card mb-2">
                                <div class="godseye-card-title">
                                    <span><i class="bi bi-sun text-warning me-1"></i><span data-i18n="ge_light_title">Güneş Açısı &amp; Gölge Simülasyonu</span></span>
                                    <span id="geSunElevVal" class="mono-text text-warning">1.5° (Ay Kutbu)</span>
                                </div>
                                <div class="d-flex gap-1 mb-2">
                                    <button type="button" class="btn btn-xs btn-outline-warning flex-grow-1 active" id="btnPresetLunarLight">
                                        🌑 <span data-i18n="preset_lunar">Ay Kutbu (1.5°)</span>
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-info flex-grow-1" id="btnPresetMarsLight">
                                        🟠 <span data-i18n="preset_mars">Mars (25°)</span>
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-light flex-grow-1" id="btnPresetNoonLight">
                                        ☀️ <span data-i18n="preset_noon">Öğle (65°)</span>
                                    </button>
                                </div>
                                <div class="row g-2 align-items-center">
                                    <div class="col-4"><label class="grid-label mb-0" for="geSunElevSlider" data-i18n="lbl_sun_elev">Güneş Açısı</label></div>
                                    <div class="col-8"><input type="range" id="geSunElevSlider" class="form-range" min="0.5" max="85" step="0.5" value="1.5"></div>
                                    <div class="col-4"><label class="grid-label mb-0" for="geSunAzimSlider" data-i18n="lbl_sun_azim">Güneş Yönü</label></div>
                                    <div class="col-8"><input type="range" id="geSunAzimSlider" class="form-range" min="0" max="360" step="5" value="135"></div>
                                    <div class="col-4"><label class="grid-label mb-0" for="geExagSlider" data-i18n="lbl_exag">Dikey Abartma</label></div>
                                    <div class="col-8">
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="range" id="geExagSlider" class="form-range" min="1" max="5" step="0.5" value="2.5">
                                            <span id="geExagVal" class="mono-text text-info">2.5×</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. Terrain Statistics (AWS Terrarium DEM) -->
                            <div class="godseye-card mb-2">
                                <div class="godseye-card-title">
                                    <span><i class="bi bi-activity text-info me-1"></i><span data-i18n="ge_stats_title">Arazi &amp; Rover Trafikabilite İstatistikleri</span></span>
                                </div>
                                <div class="godseye-stats-grid" id="godsEyeStatsGrid"></div>
                            </div>

                            <!-- 3. EVA Traverse & Tobler Hiking Function Profile -->
                            <div class="godseye-card">
                                <div class="godseye-card-title">
                                    <span><i class="bi bi-signpost-split text-success me-1"></i><span data-i18n="ge_traverse_title">EVA Yürüyüş Rotası (Tobler Modeli)</span></span>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" id="btnGeDemoRoute" class="btn btn-outline-info btn-xs" title="Otomatik Örnek Rota Çiz">
                                            <i class="bi bi-magic"></i> <span data-i18n="btn_demo_route">Örnek Rota</span>
                                        </button>
                                        <button type="button" id="btnGeClearRoute" class="btn btn-outline-danger btn-xs" title="Rotayı Temizle">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="godsEyeTraverseSummary" class="godseye-traverse-summary mb-2"></div>
                                <div id="godsEyeTraverseChart" class="godseye-traverse-chart"></div>
                                <div id="godsEyeWaypointsList" class="godseye-waypoints-list mt-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Methodology & NASA Data Sources Modal -->
    <div class="modal fade" id="methodologyModal" tabindex="-1" aria-labelledby="methodologyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content methodology-modal-content">
                <div class="modal-header methodology-modal-header">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-info bg-opacity-25 text-info mono-text">NASA SPACE APPS 2026</span>
                            <span class="badge bg-secondary bg-opacity-25 text-light mono-text">OPEN SCIENCE</span>
                        </div>
                        <h5 class="modal-title mt-2" id="methodologyModalLabel" data-i18n="meth_title">
                            Bilimsel Metodoloji &amp; NASA Veri Kaynakları
                        </h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body methodology-modal-body" id="methodologyModalBody">
                    <!-- Populated dynamically by app.js according to active language (TR/EN) -->
                </div>
                <div class="modal-footer methodology-modal-footer">
                    <span class="mono-text text-secondary small me-auto" data-i18n="meth_footer_note">
                        EXPLOG • Dünya–Ay/Mars Analog Eşleştirme Motoru
                    </span>
                    <button type="button" class="btn btn-sm btn-info fw-semibold" data-bs-dismiss="modal" data-i18n="meth_close">
                        Kapat
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Initial Datasets Injected from SQLite PDO Database -->
    <script>
        window.__INITIAL_DATA__ = {
            extraterrestrial: <?= $extraterrestrialJson ?>,
            earthAnalogs: <?= $earthAnalogsJson ?>,
            dbStats: <?= $dbStatsJson ?>,
            dataSources: <?= $dataSourcesJson ?>,
            caves: <?= $cavesJson ?>
        };
    </script>

    <!-- Bootstrap 5.3 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Three.js (for God's Eye 3D DEM & Low-Sun Shadow Simulator) -->
    <script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
    <!-- Globe.gl -->
    <script src="https://cdn.jsdelivr.net/npm/globe.gl@2.35.1/dist/globe.gl.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>

    <!-- App Modules -->
    <script src="assets/js/globes.js"></script>
    <script src="assets/js/terrain_3d.js"></script>
    <script src="assets/js/app.js"></script>
</body>

</html>