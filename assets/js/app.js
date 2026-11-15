/**
 * app.js
 * REST API (api/match.php) iletişimi, Moon/Mars geçişi, görev filtreleme,
 * karşılaştırma paneli ve Chart.js Radar Grafiği yönetimi.
 */

(() => {
    const DIL = {
        tr: {
            mod0: 'Katalog Sahaları',
            mod1: 'Nokta Analizi',
            mod2: 'Bölge Tarama',
            mod3: 'NASA 3B Gezegen Modelleri',
            mod3_short: '3B Modeller',
            model_sec: 'İncelenecek NASA 3B Modelini Seçin:',
            point_hint: 'Dünya küresinde herhangi bir noktaya tıklayın — canlı NASA POWER verisi çekilir.',
            grid_title: 'Cetvel & Bölge Taraması',
            ruler_hint: 'Küreye tıklayarak alan köşeleri (P1, P2...) çizin:',
            min_lat: 'Min Enlem',
            max_lat: 'Maks Enlem',
            min_lon: 'Min Boylam',
            max_lon: 'Maks Boylam',
            grid_res: 'Tarama Çözünürlüğü',
            btn_scan: 'Bölgeyi Tara ve En İyi Adayları Bul',
            tbl_title: 'Parametre Kıyaslaması',
            th_param: 'Parametre',
            th_earth: 'Dünya',
            th_match: 'Uyum',
            lbl_target_min: 'Hedef:',
            lbl_earth_min: 'Dünya:',
            radar_title: '5-Parametre Radar Analizi',
            why_title: 'Saha Özeti & Donanım Uygunluğu',
            grid_rank_title: 'Keşfedilen Aday Sıralaması',
            status_ready: 'Hazır • Küreden bir hedef seçin',
            status_point: 'Nokta Analizi • Dünya küresine tıklayın',
            status_grid: 'Bölge Tara • Koordinat aralığı seçip taramayı başlatın',
            status_models: 'NASA 3B Gezegen Modelleri inceleme modu aktif',
            missions: {
                all: 'Tüm Görevler',
                rover_test: 'Rover Sürüş Testi',
                habitat_sim: 'Habitat Kurulumu',
                habitat_construction: 'Habitat İnşası',
                eva_suit: 'Uzay Giysisi (EVA)',
                isru_mining: 'Kaynak Kullanımı (ISRU)',
                landing_site: 'İniş Sahası',
                solar_power: 'Güneş Enerjisi',
                ice_prospecting: 'Buz Arama',
                comms_relay: 'İletişim Aktarımı',
                cave_exploration: 'Mağara / Lav Tüpü Keşfi',
                radiation_shelter: 'Radyasyon Sığınağı',
                astrobiology: 'Astrobiyoloji',
                sample_analysis: 'Numune Analizi',
                seismic_monitoring: 'Sismik İzleme',
                drilling_test: 'Sondaj Testi',
                volcanology: 'Volkanoloji',
                hazard_avoidance: 'Tehlike Kaçınma'
            },
            points_unit: 'Nokta',
            earth_hud: 'Analog Sahası',
            space_hud: 'Hedef Lokasyon',
            space_click_hint: 'Kürenin Herhangi Bir Noktasına Tıklayın',
            continents_btn: 'Kıtalar',
            btn_methodology: 'Metodoloji',
            meth_title: 'Bilimsel Metodoloji & NASA Veri Kaynakları',
            meth_footer_note: 'EXPLOG • Dünya–Ay/Mars Analog Eşleştirme Motoru',
            meth_close: 'Kapat',
            btn_godseye: '3B Arazi & Gölge Simülasyonu',
            btn_godseye_short: '3B Arazi',
            btn_dossier: 'Rapor',
            btn_dossier_short: 'Rapor',
            trl_card_title: 'Donanım Test Uygunluğu',
            lbl_nearest_cave: 'Mağara:',
            eva_route_lbl: 'EVA Traverse Analizi (Tobler)',
            btn_3d_route: '3B Gölge & Rota',
            btn_export_dossier: 'Görev Dosyası İndir',
            godseye_click_hint: 'Sürükle: Döndür • Tekerlek: Yakınlaştır • Araziye Tıkla: EVA Rota Noktası (P1..P8) Ekle',
            drape_sat: 'Uydu',
            drape_relief: 'Rölyef',
            drape_thermal: 'Termal',
            drape_slope: 'Eğim (>15°)',
            ge_light_title: 'Güneş & Gölge Simülasyonu',
            preset_lunar: 'Ay Kutbu (1.5°)',
            preset_mars: 'Mars (25°)',
            preset_noon: 'Öğle (65°)',
            lbl_sun_elev: 'Güneş Yüksekliği (Grazing)',
            lbl_sun_azim: 'Güneş Azimutu',
            lbl_exag: 'Dikey Abartma (Z-Scale)',
            ge_stats_title: '3B Arazi Telemetrisi (AWS DEM)',
            ge_traverse_title: 'EVA Yürüyüş & Rover Traverse (Tobler)',
            btn_demo_route: 'Örnek Rota'
        },
        en: {
            mod0: 'Catalog Sites',
            mod1: 'Point Analysis',
            mod2: 'Regional Scan',
            mod3: 'NASA 3D Planetary Models',
            mod3_short: '3D Models',
            model_sec: 'Select NASA 3D Model to Inspect:',
            point_hint: 'Click anywhere on the Earth globe — live NASA POWER satellite data will be fetched.',
            grid_title: 'Ruler & Regional Scan',
            ruler_hint: 'Click the globe to draw area vertices (P1, P2...):',
            min_lat: 'Min Latitude',
            max_lat: 'Max Latitude',
            min_lon: 'Min Longitude',
            max_lon: 'Max Longitude',
            grid_res: 'Scan Resolution',
            btn_scan: 'Scan Region & Find Best Candidates',
            tbl_title: 'Parameter Comparison',
            th_param: 'Parameter',
            th_earth: 'Earth',
            th_match: 'Match',
            lbl_target_min: 'Target:',
            lbl_earth_min: 'Earth:',
            radar_title: '5-Parameter Radar Analysis',
            why_title: 'Site Summary & Hardware Readiness',
            grid_rank_title: 'Discovered Candidate Ranking',
            status_ready: 'Ready • Select a target from the globe',
            status_point: 'Point Analysis • Click on the Earth globe',
            status_grid: 'Grid Search • Set bounds and start scan',
            status_models: 'NASA 3D Planetary Models inspection active',
            missions: {
                all: 'All Missions',
                rover_test: 'Rover Mobility Test',
                habitat_sim: 'Habitat Setup',
                habitat_construction: 'Habitat Construction',
                eva_suit: 'EVA Spacesuit',
                isru_mining: 'ISRU Resource Mining',
                landing_site: 'Landing Site',
                solar_power: 'Solar Power',
                ice_prospecting: 'Ice Prospecting',
                comms_relay: 'Comms Relay',
                cave_exploration: 'Cave / Lava Tube Exploration',
                radiation_shelter: 'Radiation Shelter',
                astrobiology: 'Astrobiology',
                sample_analysis: 'Sample Analysis',
                seismic_monitoring: 'Seismic Monitoring',
                drilling_test: 'Drilling Test',
                volcanology: 'Volcanology',
                hazard_avoidance: 'Hazard Avoidance'
            },
            points_unit: 'Points',
            earth_hud: 'Analog Sites',
            space_hud: 'Target Locations',
            space_click_hint: 'Click Anywhere on the Globe',
            continents_btn: 'Continents',
            btn_methodology: 'Methodology',
            meth_title: 'Scientific Methodology & NASA Data Sources',
            meth_footer_note: 'EXPLOG • Earth–Moon/Mars Analog Matching Engine',
            meth_close: 'Close',
            btn_godseye: 'God\'s Eye 3D & Low-Sun Shadow',
            btn_godseye_short: '3D Terrain',
            btn_dossier: 'Mission Dossier (HTML/PDF)',
            btn_dossier_short: 'Dossier',
            trl_card_title: 'TRL 5–6 Hardware Test Readiness',
            lbl_nearest_cave: 'Nearest Analog Cave:',
            eva_route_lbl: 'EVA Traverse Analysis (Tobler)',
            btn_3d_route: '3D Shadow & Route',
            btn_export_dossier: 'Export Mission Dossier',
            godseye_click_hint: 'Drag: Orbit • Wheel: Zoom • Click Terrain: Add EVA Waypoint (P1..P8)',
            drape_sat: 'Satellite',
            drape_relief: 'Relief',
            drape_thermal: 'Thermal',
            drape_slope: 'Slope (>15°)',
            ge_light_title: 'Solar & Grazing Shadow Simulation',
            preset_lunar: 'Lunar Pole (1.5°)',
            preset_mars: 'Mars (25°)',
            preset_noon: 'Noon (65°)',
            lbl_sun_elev: 'Solar Elevation (Grazing)',
            lbl_sun_azim: 'Solar Azimuth',
            lbl_exag: 'Vertical Exaggeration (Z-Scale)',
            ge_stats_title: '3D Terrain Telemetry (AWS DEM)',
            ge_traverse_title: 'EVA Walk & Rover Traverse (Tobler)',
            btn_demo_route: 'Demo Route'
        }
    };

    const MISSION_LABELS = {
        rover_test: 'Rover Sürüş Testi',
        habitat_sim: 'Habitat Kurulumu',
        habitat_construction: 'Habitat İnşası',
        eva_suit: 'Uzay Giysisi (EVA)',
        isru_mining: 'Kaynak Kullanımı (ISRU)',
        landing_site: 'İniş Sahası',
        solar_power: 'Güneş Enerjisi',
        ice_prospecting: 'Buz Arama',
        comms_relay: 'İletişim Aktarımı',
        cave_exploration: 'Mağara / Lav Tüpü Keşfi',
        radiation_shelter: 'Radyasyon Sığınağı',
        astrobiology: 'Astrobiyoloji',
        sample_analysis: 'Numune Analizi',
        seismic_monitoring: 'Sismik İzleme',
        drilling_test: 'Sondaj Testi',
        volcanology: 'Volkanoloji',
        hazard_avoidance: 'Tehlike Kaçınma'
    };

    const METRIC_LABELS = {
        avg_temp_c: { label: 'Ort. Sıcaklık', unit: '°C' },
        slope_deg: { label: 'Yüzey Eğimi', unit: '°' },
        humidity_pct: { label: 'Nem Oranı', unit: '%' },
        regolith_similarity: { label: 'Regolit Uyumu', unit: '/100' },
        solar_exposure_pct: { label: 'Güneşlenme', unit: '%' }
    };

    const NASA_METRIC_LABELS = {
        T2M: { label: 'Ort. Sıcaklık', unit: '°C' },
        T2M_RANGE: { label: 'Günlük Sıcaklık Farkı', unit: '°C' },
        PRECTOTCORR: { label: 'Yağış Miktarı', unit: ' mm/g' },
        RH2M: { label: 'Bağıl Nem', unit: '%' },
        ALLSKY_SFC_SW_DWN: { label: 'Güneş Radyasyonu', unit: ' kWh/m²' }
    };

    const initialData = window.__INITIAL_DATA__ || { extraterrestrial: [], earthAnalogs: [], dataSources: [] };

    const state = {
        lang: 'tr',
        currentBody: 'Moon',
        missionType: 'all',
        discoveryMode: 'curated', // 'curated' | 'point' | 'grid'
        allSpaceTargets: initialData.extraterrestrial || [],
        allEarthAnalogs: initialData.earthAnalogs || [],
        dataSources: initialData.dataSources || [],
        selectedTarget: null,
        matchedResults: [],
        selectedEarth: null,
        nasaReference: null,
        nasaResults: [],
        nasaSelected: null,
        rulerPoints: [],
        radarChart: null
    };

    // DOM Referansları
    const els = {
        btnMoon: document.getElementById('btnBodyMoon'),
        btnMars: document.getElementById('btnBodyMars'),
        langSelect: document.getElementById('langSelect'),
        missionFilter: document.getElementById('missionFilter'),
        missionFilterWrap: document.getElementById('missionFilterWrap'),
        btnModeCurated: document.getElementById('btnModeCurated'),
        btnModePoint: document.getElementById('btnModePoint'),
        btnModeGrid: document.getElementById('btnModeGrid'),
        earthModeHint: document.getElementById('earthModeHint'),
        gridSearchPanel: document.getElementById('gridSearchPanel'),
        btnToggleGridPanel: document.getElementById('btnToggleGridPanel'),
        gridDrawerChevron: document.getElementById('gridDrawerChevron'),
        rulerStats: document.getElementById('rulerStats'),
        btnRulerUndo: document.getElementById('btnRulerUndo'),
        btnRulerClear: document.getElementById('btnRulerClear'),
        gridMinLat: document.getElementById('gridMinLat'),
        gridMaxLat: document.getElementById('gridMaxLat'),
        gridMinLon: document.getElementById('gridMinLon'),
        gridMaxLon: document.getElementById('gridMaxLon'),
        gridSizeRange: document.getElementById('gridSizeRange'),
        gridSizeValue: document.getElementById('gridSizeValue'),
        btnRunGridSearch: document.getElementById('btnRunGridSearch'),
        rightStandardPanel: document.getElementById('rightStandardPanel'),
        rightGridPanel: document.getElementById('rightGridPanel'),
        gridTotalBadge: document.getElementById('gridTotalBadge'),
        gridResultsTableBody: document.getElementById('gridResultsTableBody'),
        apiStatusDot: document.getElementById('apiStatusDot'),
        apiStatusText: document.getElementById('apiStatusText'),
        earthHudLabel: document.getElementById('earthHudLabel'),
        btnToggleContinents: document.getElementById('btnToggleContinents'),
        selectedContinentChip: document.getElementById('selectedContinentChip'),
        selectedContinentLabel: document.getElementById('selectedContinentLabel'),
        spaceBodyBadge: document.getElementById('spaceBodyBadge'),
        spaceHudLabel: document.getElementById('spaceHudLabel'),
        drawer: document.getElementById('comparisonDrawer'),
        btnToggleDrawer: document.getElementById('btnToggleDrawer'),
        drawerToggleIcon: document.getElementById('drawerToggleIcon'),
        btnCloseDrawer: document.getElementById('btnCloseDrawer'),
        cmpTargetName: document.getElementById('cmpTargetName'),
        cmpEarthName: document.getElementById('cmpEarthName'),
        cmpMetaSubtitle: document.getElementById('cmpMetaSubtitle'),
        cmpNoveltyBadge: document.getElementById('cmpNoveltyBadge'),
        cmpGeoScoreBadge: document.getElementById('cmpGeoScoreBadge'),
        cmpSimilarityBadge: document.getElementById('cmpSimilarityBadge'),
        btnDrawerGodsEye: document.getElementById('btnDrawerGodsEye'),
        btnDrawerDossier: document.getElementById('btnDrawerDossier'),
        topMatchesBar: document.getElementById('topMatchesBar'),
        tblTargetCol: document.getElementById('tblTargetCol'),
        metricsTableBody: document.getElementById('metricsTableBody'),
        cmpTargetMineral: document.getElementById('cmpTargetMineral'),
        cmpEarthMineral: document.getElementById('cmpEarthMineral'),
        cmpCaveRow: document.getElementById('cmpCaveRow'),
        cmpNearestCave: document.getElementById('cmpNearestCave'),
        cmpCitationLinks: document.getElementById('cmpCitationLinks'),
        cmpRegimeBadge: document.getElementById('cmpRegimeBadge'),
        cmpEarthDesc: document.getElementById('cmpEarthDesc'),
        cmpCalibrationNote: document.getElementById('cmpCalibrationNote'),
        cmpReadinessBox: document.getElementById('cmpReadinessBox'),
        cmpMissionTags: document.getElementById('cmpMissionTags'),
        radarCanvas: document.getElementById('analogRadarChart'),
        earthSiteCard: document.getElementById('earthSiteCard'),
        btnCloseSiteCard: document.getElementById('btnCloseSiteCard'),
        siteCardFlag: document.getElementById('siteCardFlag'),
        siteCardFlagFallback: document.getElementById('siteCardFlagFallback'),
        siteCardTitle: document.getElementById('siteCardTitle'),
        siteCardSubtitle: document.getElementById('siteCardSubtitle'),
        siteCardContextStrip: document.getElementById('siteCardContextStrip'),
        btnSiteGodsEye: document.getElementById('btnSiteGodsEye'),
        btnSiteDossier: document.getElementById('btnSiteDossier'),
        siteFramesLabel: document.getElementById('siteFramesLabel'),
        siteCardFrames: document.getElementById('siteCardFrames'),
        siteSpecsLabel: document.getElementById('siteSpecsLabel'),
        siteCardSpecs: document.getElementById('siteCardSpecs'),
        siteCardReadiness: document.getElementById('siteCardReadiness'),
        siteMineralLabel: document.getElementById('siteMineralLabel'),
        siteCardMineral: document.getElementById('siteCardMineral'),
        siteCardDesc: document.getElementById('siteCardDesc'),
        siteCardMissions: document.getElementById('siteCardMissions'),
        siteFrameLightbox: document.getElementById('siteFrameLightbox'),
        siteLightboxImg: document.getElementById('siteLightboxImg'),
        siteLightboxTitle: document.getElementById('siteLightboxTitle'),
        siteLightboxBadge: document.getElementById('siteLightboxBadge'),
        rulerTraverseBox: document.getElementById('rulerTraverseBox'),
        rulerWalkTime: document.getElementById('rulerWalkTime'),
        rulerClimbStat: document.getElementById('rulerClimbStat'),
        rulerHazardStat: document.getElementById('rulerHazardStat'),
        btnOpenRulerGodsEye: document.getElementById('btnOpenRulerGodsEye'),
        btnExportRulerGeoJson: document.getElementById('btnExportRulerGeoJson')
    };

    function getLoc(item, field) {
        if (!item) return '';
        if (state.lang === 'en' && item[`${field}_en`]) return item[`${field}_en`];
        return item[field] || '';
    }

    function setStatus(text, isLoading = false) {
        els.apiStatusText.textContent = text;
        els.apiStatusText.title = text;
        els.apiStatusDot.classList.toggle('loading', isLoading);
    }

    function getFilteredSpaceTargets() {
        return state.allSpaceTargets.filter(item => item.body === state.currentBody);
    }

    function refreshSpaceGlobe(activeTargetId = null) {
        const targets = getFilteredSpaceTargets();
        window.SpaceGlobes.updateSpacePoints(targets, activeTargetId);
        els.spaceBodyBadge.textContent = state.currentBody.toUpperCase();
        els.spaceBodyBadge.className = `chip-badge ${state.currentBody === 'Mars' ? 'mars-badge' : 'moon-badge'}`;
        const T = DIL[state.lang] || DIL.tr;
        els.spaceHudLabel.textContent = `${targets.length} ${T.space_hud}`;
    }

    function refreshEarthGlobe(points, activeEarthId = null) {
        window.SpaceGlobes.updateEarthPoints(points, activeEarthId);
        const T = DIL[state.lang] || DIL.tr;
        els.earthHudLabel.textContent = `${points.length} ${T.earth_hud}`;
    }

    async function fetchAnalogMatches(targetId, preferredEarthId = null) {
        setStatus(state.lang === 'en' ? 'Calculating analog matches...' : 'Analog eşleştirmesi hesaplanıyor...', true);

        try {
            const url = `api/match.php?target_id=${encodeURIComponent(targetId)}&mission_type=${encodeURIComponent(state.missionType)}&lang=${encodeURIComponent(state.lang)}`;
            const response = await fetch(url);
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'API yanıt hatası');
            }

            state.selectedTarget = payload.target;
            state.matchedResults = payload.results || [];

            if (state.matchedResults.length === 0) {
                refreshEarthGlobe([], null);
                setStatus('Seçili görev filtresi için uygun Dünya sahası bulunamadı.');
                return;
            }

            const chosenEarth = preferredEarthId
                ? (state.matchedResults.find(r => r.id === preferredEarthId) || state.matchedResults[0])
                : state.matchedResults[0];

            state.selectedEarth = chosenEarth;

            // Dünya küresini güncelle ve 1. eşleşmeye (veya seçilen Dünya sahasına) uç
            refreshEarthGlobe(state.matchedResults, chosenEarth.id);
            window.SpaceGlobes.flyToEarth(chosenEarth.lat, chosenEarth.lng, 1.65);

            renderComparisonDrawer();
            setStatus(`${state.selectedTarget.name} → ${state.matchedResults.length} analog listelendi`);
        } catch (err) {
            console.error('[App] Match API hatası:', err);
            setStatus(`Hata: ${err.message}`);
        }
    }

    /**
     * Ay veya Mars küresinde HERHANGİ BİR koordinata (lat, lng) tıklandığında
     * LRO Diviner/LOLA veya MGS MOLA/TES modeliyle canlı hedef sentezler ve tüm Dünya ile eşleştirir.
     */
    async function fetchDynamicPlanetaryMatch(lat, lng) {
        const latFixed = Number(lat.toFixed(2));
        const lngFixed = Number(lng.toFixed(2));
        setStatus(
            state.lang === 'en'
                ? `Synthesizing ${state.currentBody} telemetry (${latFixed}°, ${lngFixed}°)...`
                : `${state.currentBody} koordinatı analiz ediliyor (${latFixed}°, ${lngFixed}°)...`,
            true
        );

        try {
            const url = `api/match.php?body=${encodeURIComponent(state.currentBody)}&target_lat=${latFixed}&target_lng=${lngFixed}&mission_type=${encodeURIComponent(state.missionType)}&lang=${encodeURIComponent(state.lang)}`;
            const response = await fetch(url);
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'Dinamik gezegen analizi başarısız');
            }

            const dynTarget = payload.target;
            const existsIdx = state.allSpaceTargets.findIndex(t => t.id === dynTarget.id);
            if (existsIdx >= 0) {
                state.allSpaceTargets[existsIdx] = dynTarget;
            } else {
                // Önceki dinamik geçici hedefleri temizle ki küre kalabalıklaşmasın, yalnızca son tıklanan dinamik hedefi ve sabit hedefleri tut
                state.allSpaceTargets = state.allSpaceTargets.filter(t => !t.is_dynamic);
                state.allSpaceTargets.unshift(dynTarget);
            }

            state.selectedTarget = dynTarget;
            state.matchedResults = payload.results || [];

            window.SpaceGlobes.flyToSpace(dynTarget.lat, dynTarget.lng, 1.65);
            refreshSpaceGlobe(dynTarget.id);

            if (state.matchedResults.length > 0) {
                state.selectedEarth = state.matchedResults[0];
                refreshEarthGlobe(state.matchedResults, state.selectedEarth.id);
                window.SpaceGlobes.flyToEarth(state.selectedEarth.lat, state.selectedEarth.lng, 1.65);
                renderComparisonDrawer();
                setStatus(`${getLoc(dynTarget, 'name')} → #1 ${getLoc(state.selectedEarth, 'name')} (%${state.selectedEarth.similarity_score})`);
            } else {
                refreshEarthGlobe([], null);
                setStatus(state.lang === 'en' ? 'No matching Earth sites for current filter.' : 'Seçili görev filtresi için uygun Dünya sahası bulunamadı.');
            }
        } catch (err) {
            console.error('[App] Dinamik gezegen eşleştirme hatası:', err);
            setStatus(`Hata: ${err.message}`);
        }
    }

    /**
     * Sabit/Küresel moddayken Dünya küresinde herhangi bir (lat, lng) koordinatına tıklandığında
     * o koordinatın canlı verisini çekip aktif Ay/Mars hedefiyle karşılaştırır.
     */
    async function fetchDynamicEarthAgainstTarget(lat, lng) {
        const latFixed = Number(lat.toFixed(2));
        const lngFixed = Number(lng.toFixed(2));

        // Henüz Ay/Mars hedefi seçilmediyse aktif gök cisminin ilk hedefini seç
        let targetId = state.selectedTarget ? state.selectedTarget.id : null;
        if (!targetId) {
            const targets = getFilteredSpaceTargets();
            if (targets.length > 0) {
                targetId = targets[0].id;
                window.SpaceGlobes.flyToSpace(targets[0].lat, targets[0].lng, 1.75);
                refreshSpaceGlobe(targets[0].id);
            } else {
                return;
            }
        }

        setStatus(
            state.lang === 'en'
                ? `Analyzing Earth coordinate (${latFixed}°, ${lngFixed}°)...`
                : `Dünya koordinatı analiz ediliyor (${latFixed}°, ${lngFixed}°)...`,
            true
        );

        try {
            const url = `api/match.php?target_id=${encodeURIComponent(targetId)}&earth_lat=${latFixed}&earth_lng=${lngFixed}&mission_type=${encodeURIComponent(state.missionType)}&lang=${encodeURIComponent(state.lang)}`;
            const response = await fetch(url);
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'Dünya koordinat analizi başarısız');
            }

            state.selectedTarget = payload.target;
            state.matchedResults = payload.results || [];
            const prefId = payload.preferred_earth_id;
            const chosenEarth = prefId
                ? (state.matchedResults.find(r => r.id === prefId) || state.matchedResults[0])
                : state.matchedResults[0];

            if (chosenEarth) {
                state.selectedEarth = chosenEarth;
                refreshEarthGlobe(state.matchedResults, chosenEarth.id);
                window.SpaceGlobes.flyToEarth(chosenEarth.lat, chosenEarth.lng, 1.55);
                openEarthSiteCard(chosenEarth);
                renderComparisonDrawer();
                setStatus(`${getLoc(chosenEarth, 'name')} ↔ ${getLoc(state.selectedTarget, 'name')} (%${chosenEarth.similarity_score})`);
            }
        } catch (err) {
            console.error('[App] Dinamik Dünya koordinat hatası:', err);
            setStatus(`Hata: ${err.message}`);
        }
    }

    function selectEarthAnalog(earthItem) {
        if (state.discoveryMode !== 'curated' && earthItem && earthItem.is_nasa_power) {
            state.nasaSelected = earthItem;
            refreshEarthGlobe(state.nasaResults, earthItem.id);
            window.SpaceGlobes.flyToEarth(earthItem.lat, earthItem.lng, 1.65);
            renderNasaComparisonDrawer();
            return;
        }

        if (!state.selectedTarget) {
            // Henüz Ay/Mars hedefi seçilmediyse aktif gök cisminin ilk hedefini seç
            const targets = getFilteredSpaceTargets();
            if (targets.length > 0) {
                window.SpaceGlobes.flyToSpace(targets[0].lat, targets[0].lng, 1.75);
                window.SpaceGlobes.updateSpacePoints(targets, targets[0].id);
                fetchAnalogMatches(targets[0].id, earthItem.id);
            }
            return;
        }

        const matched = state.matchedResults.find(r => r.id === earthItem.id);
        if (!matched) {
            setStatus('Bu saha mevcut görev filtresinin dışında.');
            return;
        }

        state.selectedEarth = matched;
        refreshEarthGlobe(state.matchedResults, matched.id);
        window.SpaceGlobes.flyToEarth(matched.lat, matched.lng, 1.65);
        renderComparisonDrawer();
    }

    function renderComparisonDrawer() {
        const target = state.selectedTarget;
        const earth = state.selectedEarth;
        if (!target || !earth) return;

        els.drawer.classList.remove('collapsed', 'minimized');
        els.drawer.setAttribute('aria-hidden', 'false');
        els.drawerToggleIcon.className = 'bi bi-chevron-down';

        els.cmpTargetName.textContent = getLoc(target, 'name');
        els.cmpEarthName.textContent = getLoc(earth, 'name');
        els.cmpMetaSubtitle.textContent = `${getLoc(target, 'country_or_region')}  •  ${getLoc(earth, 'country_or_region')}`;

        const score = earth.similarity_score ?? 0;
        const simWord = state.lang === 'en' ? 'Match' : 'Benzerlik';
        els.cmpSimilarityBadge.textContent = `%${score} ${simWord}`;
        els.cmpSimilarityBadge.className = 'similarity-badge';
        if (score < 65) els.cmpSimilarityBadge.classList.add('low');
        else if (score < 80) els.cmpSimilarityBadge.classList.add('mid');

        // Novelty & Geometric Score Badges
        if (els.cmpNoveltyBadge) {
            if (earth.novelty) {
                els.cmpNoveltyBadge.textContent = state.lang === 'en'
                    ? (earth.novelty.badge_en || earth.novelty.badge)
                    : (earth.novelty.badge_tr || earth.novelty.badge);
                els.cmpNoveltyBadge.className = `novelty-pill novelty-${earth.novelty.status}`;
            } else {
                els.cmpNoveltyBadge.className = 'novelty-pill d-none';
            }
        }
        if (els.cmpGeoScoreBadge) {
            if (earth.geometric_score !== undefined) {
                els.cmpGeoScoreBadge.textContent = `Geo: %${earth.geometric_score}`;
                els.cmpGeoScoreBadge.classList.remove('d-none');
            } else {
                els.cmpGeoScoreBadge.classList.add('d-none');
            }
        }

        // En iyi eşleşmeler hızlı geçiş çipleri (ilk 5)
        els.topMatchesBar.innerHTML = '';
        state.matchedResults.slice(0, 5).forEach((item, idx) => {
            const rawName = getLoc(item, 'name').split('(')[0].trim();
            const shortName = rawName.length > 26 ? `${rawName.slice(0, 24)}…` : rawName;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `match-chip-btn ${item.id === earth.id ? 'active' : ''}`;
            btn.title = `${getLoc(item, 'name')} (%${item.similarity_score})`;
            btn.innerHTML = `<span>#${idx + 1} ${shortName}</span><span class="match-chip-score">%${item.similarity_score}</span>`;
            btn.addEventListener('click', () => selectEarthAnalog(item));
            els.topMatchesBar.appendChild(btn);
        });

        // Teknik Metrik Tablosu
        els.tblTargetCol.textContent = target.body === 'Mars' ? 'Mars' : (state.lang === 'en' ? 'Moon' : 'Ay');
        const T = DIL[state.lang] || DIL.tr;
        const metricNamesEn = {
            avg_temp_c: 'Avg. Temp',
            slope_deg: 'Surface Slope',
            humidity_pct: 'Humidity',
            regolith_similarity: 'Regolith Match',
            solar_exposure_pct: 'Solar Exposure'
        };
        els.metricsTableBody.innerHTML = '';
        Object.entries(METRIC_LABELS).forEach(([key, meta]) => {
            const diffObj = earth.metric_diffs?.[key];
            const tVal = diffObj ? diffObj.target : target.metrics[key];
            const eVal = diffObj ? diffObj.earth : earth.metrics[key];
            const mPct = diffObj ? diffObj.match_pct : '—';
            const labelText = state.lang === 'en' ? (metricNamesEn[key] || meta.label) : meta.label;

            const mNum = typeof mPct === 'number' ? mPct : parseFloat(mPct);
            const matchClass = !Number.isNaN(mNum)
                ? (mNum >= 80 ? 'high' : (mNum >= 60 ? 'mid' : 'low'))
                : 'mid';

            const clampTooltip = diffObj?.clamped
                ? `${state.lang === 'en' ? 'Clamped to Earth envelope' : 'Dünya zarfı referansı'}: ${diffObj.target_clamped}${meta.unit}`
                : '';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-secondary">${labelText}</td>
                <td class="text-end mono-text" ${clampTooltip ? `title="${clampTooltip}"` : ''}>${tVal}${meta.unit}</td>
                <td class="text-end mono-text text-info">${eVal}${meta.unit}</td>
                <td class="text-end">
                    <div class="metric-match-cell">
                        <span class="metric-match-pill ${matchClass} mono-text">%${Math.round(mNum)}</span>
                        <div class="metric-match-bar"><span class="${matchClass}" style="width:${Number.isNaN(mNum) ? 0 : mNum}%"></span></div>
                    </div>
                </td>
            `;
            els.metricsTableBody.appendChild(tr);
        });

        const tMin = getLoc(target, 'mineral_type');
        const eMin = getLoc(earth, 'mineral_type');
        els.cmpTargetMineral.textContent = tMin;
        els.cmpTargetMineral.title = tMin;
        els.cmpEarthMineral.textContent = eMin;
        els.cmpEarthMineral.title = eMin;

        // Nearest Cave & Citation Links
        if (els.cmpCaveRow && els.cmpNearestCave) {
            if (earth.nearest_cave) {
                const c = earth.nearest_cave;
                const cName = state.lang === 'en' ? (c.name_en || c.name) : c.name;
                els.cmpNearestCave.innerHTML = `${cName} <span class="mono-text text-info">(${c.distance_km} km)</span>`;
                els.cmpCaveRow.classList.remove('d-none');
            } else {
                els.cmpCaveRow.classList.add('d-none');
            }
        }
        if (els.cmpCitationLinks) {
            const links = [];
            const isEn = state.lang === 'en';
            if (target.source_url) {
                links.push(`<a href="${target.source_url}" target="_blank" rel="noopener noreferrer" class="citation-chip" title="${target.source_label || 'Target Reference'}"><i class="bi bi-globe2"></i><span>${isEn ? 'Target' : 'Hedef'}</span></a>`);
            }
            if (target.trek_url) {
                links.push(`<a href="${target.trek_url}" target="_blank" rel="noopener noreferrer" class="citation-chip" title="NASA Trek"><i class="bi bi-map"></i><span>Trek</span></a>`);
            }
            if (earth.source_url) {
                links.push(`<a href="${earth.source_url}" target="_blank" rel="noopener noreferrer" class="citation-chip" title="${earth.source_label || 'Earth Site Reference'}"><i class="bi bi-journal-text"></i><span>${isEn ? 'Site' : 'Saha'}</span></a>`);
            }
            els.cmpCitationLinks.innerHTML = links.join('');
        }

        if (els.cmpRegimeBadge) {
            if (earth.regime_label) {
                els.cmpRegimeBadge.innerHTML = `<i class="bi bi-bullseye me-1"></i>${earth.regime_label}`;
                els.cmpRegimeBadge.classList.remove('d-none');
            } else {
                els.cmpRegimeBadge.classList.add('d-none');
            }
        }

        els.cmpEarthDesc.textContent = getLoc(earth, 'description');
        els.cmpCalibrationNote.textContent = earth.calibration_note || '';

        if (els.cmpReadinessBox && window.AstroTerrain) {
            els.cmpReadinessBox.innerHTML = earth.qualification
                ? window.AstroTerrain.renderReadinessHtml(earth.qualification, state.lang, true)
                : '';
        }

        // Uygun Görev Etiketleri (En önemli 4 görev)
        els.cmpMissionTags.innerHTML = '';
        (earth.suitable_missions || []).slice(0, 4).forEach(m => {
            const span = document.createElement('span');
            span.className = 'mission-tag';
            span.textContent = (T.missions && T.missions[m]) ? T.missions[m] : (MISSION_LABELS[m] || m);
            els.cmpMissionTags.appendChild(span);
        });

        els.rightStandardPanel.classList.remove('d-none');
        els.rightStandardPanel.classList.add('d-flex');
        els.rightGridPanel.classList.add('d-none');
        els.rightGridPanel.classList.remove('d-flex');

        renderRadarChart(target, earth);
    }

    function normalizeNasaMetricsForRadar(m) {
        const tempNorm = Math.max(0, Math.min(100, ((m.T2M + 50) / 95) * 100));
        const rangeNorm = Math.max(0, Math.min(100, (m.T2M_RANGE / 25) * 100));
        const precNorm = Math.max(0, Math.min(100, (m.PRECTOTCORR / 10) * 100));
        const humNorm = Math.max(0, Math.min(100, m.RH2M));
        const radNorm = Math.max(0, Math.min(100, (m.ALLSKY_SFC_SW_DWN / 8) * 100));
        return [
            Number(tempNorm.toFixed(1)),
            Number(rangeNorm.toFixed(1)),
            Number(precNorm.toFixed(1)),
            Number(humNorm.toFixed(1)),
            Number(radNorm.toFixed(1))
        ];
    }

    function renderNasaRadarChart(ref, point) {
        if (!els.radarCanvas || typeof Chart === 'undefined') return;
        const refColor = state.currentBody === 'Mars' ? 'rgba(249, 115, 22, 1)' : 'rgba(226, 232, 240, 1)';
        const refBg = state.currentBody === 'Mars' ? 'rgba(249, 115, 22, 0.22)' : 'rgba(226, 232, 240, 0.18)';

        const data = {
            labels: ['Sıcaklık', 'Sıc. Farkı', 'Yağış', 'Bağıl Nem', 'Radyasyon'],
            datasets: [
                {
                    label: ref.name.split('(')[0].trim(),
                    data: normalizeNasaMetricsForRadar(ref.metrics),
                    borderColor: refColor,
                    backgroundColor: refBg,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: point.name.split('(')[0].trim(),
                    data: normalizeNasaMetricsForRadar(point.metrics),
                    borderColor: 'rgba(56, 189, 248, 1)',
                    backgroundColor: 'rgba(56, 189, 248, 0.22)',
                    borderWidth: 2,
                    pointRadius: 3
                }
            ]
        };

        if (state.radarChart) {
            state.radarChart.data = data;
            state.radarChart.update();
            return;
        }

        state.radarChart = new Chart(els.radarCanvas, {
            type: 'radar',
            data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        min: 0,
                        max: 100,
                        ticks: { display: false, stepSize: 25 },
                        grid: { color: 'rgba(255, 255, 255, 0.08)' },
                        angleLines: { color: 'rgba(255, 255, 255, 0.1)' },
                        pointLabels: { color: '#cbd5e1', font: { size: 11, family: 'Inter, sans-serif' } }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#e2e8f0', boxWidth: 10, font: { size: 11 } }
                    }
                }
            }
        });
    }

    function renderNasaComparisonDrawer() {
        const ref = state.nasaReference;
        const pt = state.nasaSelected;
        if (!ref || !pt) return;

        els.drawer.classList.remove('collapsed', 'minimized');
        els.drawer.setAttribute('aria-hidden', 'false');
        els.drawerToggleIcon.className = 'bi bi-chevron-down';

        els.cmpTargetName.textContent = ref.name;
        els.cmpEarthName.textContent = pt.name;
        els.cmpMetaSubtitle.textContent = `${ref.country_or_region}  •  ${pt.country_or_region}`;

        const score = pt.similarity_score ?? 0;
        els.cmpSimilarityBadge.textContent = `%${score} Analog`;
        els.cmpSimilarityBadge.className = 'similarity-badge';
        if (score < 65) els.cmpSimilarityBadge.classList.add('low');
        else if (score < 80) els.cmpSimilarityBadge.classList.add('mid');

        if (els.cmpNoveltyBadge) {
            if (pt.novelty) {
                els.cmpNoveltyBadge.textContent = state.lang === 'en'
                    ? (pt.novelty.badge_en || pt.novelty.badge)
                    : (pt.novelty.badge_tr || pt.novelty.badge);
                els.cmpNoveltyBadge.className = `novelty-pill novelty-${pt.novelty.status}`;
            } else {
                els.cmpNoveltyBadge.className = 'novelty-pill d-none';
            }
        }
        if (els.cmpGeoScoreBadge) {
            if (pt.geometric_score !== undefined) {
                els.cmpGeoScoreBadge.textContent = `Geo: %${pt.geometric_score}`;
                els.cmpGeoScoreBadge.classList.remove('d-none');
            } else {
                els.cmpGeoScoreBadge.classList.add('d-none');
            }
        }

        // Top 5 çipleri (Grid modunda)
        els.topMatchesBar.innerHTML = '';
        if (state.nasaResults.length > 1) {
            state.nasaResults.slice(0, 5).forEach((item, idx) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `match-chip-btn ${item.id === pt.id ? 'active' : ''}`;
                btn.innerHTML = `<span>#${idx + 1} (${item.lat.toFixed(2)}°, ${item.lng.toFixed(2)}°)</span><span class="match-chip-score">%${item.similarity_score}</span>`;
                btn.addEventListener('click', () => selectEarthAnalog(item));
                els.topMatchesBar.appendChild(btn);
            });
        }

        // NASA POWER 5-Parametre Tablosu
        els.tblTargetCol.textContent = 'Referans';
        els.metricsTableBody.innerHTML = '';
        Object.entries(NASA_METRIC_LABELS).forEach(([key, meta]) => {
            const rVal = ref.metrics[key];
            const pVal = pt.metrics[key];
            const diff = (pVal - rVal).toFixed(2);
            const diffSign = Number(diff) > 0 ? `+${diff}` : diff;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-secondary">${meta.label}</td>
                <td class="text-end mono-text">${rVal}${meta.unit}</td>
                <td class="text-end mono-text text-info">${pVal}${meta.unit}</td>
                <td class="text-end mono-text text-warning">${diffSign}</td>
            `;
            els.metricsTableBody.appendChild(tr);
        });

        els.cmpTargetMineral.textContent = ref.mineral_type || (state.lang === 'en' ? 'Planetary Base Reference Profile' : 'Gezegen Üssü Referans Profili');
        els.cmpEarthMineral.textContent = pt.mineral_type || (state.lang === 'en' ? 'Live Satellite Climate Data (ANN)' : 'Canlı Uydu İklim Verisi (ANN)');
        if (els.cmpCaveRow && els.cmpNearestCave && pt.nearest_cave) {
            const c = pt.nearest_cave;
            els.cmpNearestCave.innerHTML = `${state.lang === 'en' ? (c.name_en || c.name) : c.name} <span class="mono-text text-info">(${c.distance_km} km)</span>`;
            els.cmpCaveRow.classList.remove('d-none');
        }

        if (state.discoveryMode === 'grid') {
            els.rightStandardPanel.classList.add('d-none');
            els.rightStandardPanel.classList.remove('d-flex');
            els.rightGridPanel.classList.remove('d-none');
            els.rightGridPanel.classList.add('d-flex');

            els.gridTotalBadge.textContent = `${state.nasaResults.length} Nokta`;
            els.gridResultsTableBody.innerHTML = '';
            state.nasaResults.forEach((item, idx) => {
                const novStatus = item.novelty?.status || 'known';
                const novShort = novStatus === 'new'
                    ? (state.lang === 'en' ? '🌟 Novel' : '🌟 Yeni')
                    : (novStatus === 'near_known' ? '📍 Yakın' : '🏛️ Katalog');
                const tr = document.createElement('tr');
                tr.className = `grid-row-clickable ${item.id === pt.id ? 'active' : ''}`;
                tr.innerHTML = `
                    <td class="mono-text text-secondary">#${idx + 1}</td>
                    <td class="mono-text">${item.lat.toFixed(2)}°, ${item.lng.toFixed(2)}°</td>
                    <td class="text-center"><span class="novelty-pill novelty-${novStatus} py-0 px-1">${novShort}</span></td>
                    <td class="text-end mono-text">${item.metrics.T2M}°C</td>
                    <td class="text-end mono-text text-success fw-semibold">%${item.similarity_score}</td>
                `;
                tr.addEventListener('click', () => selectEarthAnalog(item));
                els.gridResultsTableBody.appendChild(tr);
            });
        } else {
            els.rightStandardPanel.classList.remove('d-none');
            els.rightStandardPanel.classList.add('d-flex');
            els.rightGridPanel.classList.add('d-none');
            els.rightGridPanel.classList.remove('d-flex');

            if (els.cmpRegimeBadge) {
                els.cmpRegimeBadge.innerHTML = `<i class="bi bi-satellite me-1"></i>${ref.name}`;
                els.cmpRegimeBadge.classList.remove('d-none');
            }
            els.cmpEarthDesc.textContent = '';
            els.cmpCalibrationNote.textContent = `Seçilen nokta (${pt.lat}°, ${pt.lng}°) NASA POWER uydu iklim verileriyle analiz edildi. Günlük sıcaklık farkı ${pt.metrics.T2M_RANGE}°C, nem %${pt.metrics.RH2M}, güneşlenme ${pt.metrics.ALLSKY_SFC_SW_DWN} kWh/m²/gün.`;
            if (els.cmpReadinessBox && window.AstroTerrain) {
                els.cmpReadinessBox.innerHTML = pt.qualification
                    ? window.AstroTerrain.renderReadinessHtml(pt.qualification, state.lang, true)
                    : '';
            }
            els.cmpMissionTags.innerHTML = '<span class="mission-tag">NASA POWER</span><span class="mission-tag">Canlı Analiz</span>';
        }

        renderNasaRadarChart(ref, pt);
    }

    async function analyzeEarthCoordinate(lat, lng) {
        setStatus(`NASA POWER verileri çekiliyor (${lat.toFixed(2)}°, ${lng.toFixed(2)}°)...`, true);
        const tempPin = {
            id: 'loading_pt',
            name: `${lat.toFixed(2)}°, ${lng.toFixed(2)}°`,
            country_or_region: state.lang === 'en' ? 'Fetching NASA POWER...' : 'NASA POWER çekiliyor...',
            lat,
            lng
        };
        refreshEarthGlobe([tempPin], tempPin.id);
        window.SpaceGlobes.flyToEarth(lat, lng, 1.65);

        try {
            const targetIdParam = state.selectedTarget?.id ? `&target_id=${encodeURIComponent(state.selectedTarget.id)}` : '';
            const url = `api/nasa_power.php?mode=point&body=${encodeURIComponent(state.currentBody)}${targetIdParam}&lang=${encodeURIComponent(state.lang)}&lat=${lat.toFixed(4)}&lng=${lng.toFixed(4)}`;
            const response = await fetch(url);
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'NASA POWER API hatası');
            }

            state.nasaReference = payload.reference;
            state.nasaSelected = payload.point;
            state.nasaResults = [payload.point];

            refreshEarthGlobe(state.nasaResults, payload.point.id);
            window.SpaceGlobes.flyToEarth(payload.point.lat, payload.point.lng, 1.65);
            renderNasaComparisonDrawer();
            setStatus(`Nokta Analizi • %${payload.point.similarity_score} Analog Uyumu`);
        } catch (err) {
            console.error('[App] NASA Point hatası:', err);
            setStatus(`Hata: ${err.message}`);
        }
    }

    async function runGridSearch() {
        const minLat = parseFloat(els.gridMinLat.value);
        const maxLat = parseFloat(els.gridMaxLat.value);
        const minLon = parseFloat(els.gridMinLon.value);
        const maxLon = parseFloat(els.gridMaxLon.value);
        const gridSize = parseInt(els.gridSizeRange.value, 10);

        if (Number.isNaN(minLat) || Number.isNaN(maxLat) || Number.isNaN(minLon) || Number.isNaN(maxLon)) {
            setStatus('Geçerli enlem ve boylam sınırları girin.');
            return;
        }

        els.btnRunGridSearch.disabled = true;
        els.btnRunGridSearch.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Taranıyor...';
        setStatus(`${gridSize}×${gridSize} bölge NASA POWER üzerinden taranıyor...`, true);

        try {
            const params = new URLSearchParams({
                mode: 'grid',
                body: state.currentBody,
                lang: state.lang,
                min_lat: minLat,
                max_lat: maxLat,
                min_lon: minLon,
                max_lon: maxLon,
                grid_size: gridSize
            });
            if (state.selectedTarget?.id) {
                params.set('target_id', state.selectedTarget.id);
            }
            if (state.rulerPoints.length >= 3) {
                params.set('polygon', JSON.stringify(state.rulerPoints.map(p => ({ lat: p.lat, lng: p.lng }))));
            }

            const response = await fetch(`api/nasa_power.php?${params.toString()}`);
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'Grid Search API hatası');
            }

            state.nasaReference = payload.reference;
            state.nasaResults = payload.results || [];
            state.nasaSelected = state.nasaResults[0] || null;

            if (state.nasaSelected) {
                refreshEarthGlobe(state.nasaResults, state.nasaSelected.id);
                window.SpaceGlobes.flyToEarth(state.nasaSelected.lat, state.nasaSelected.lng, 1.55);
                renderNasaComparisonDrawer();
                setStatus(`Tarama tamamlandı • En iyi aday: %${state.nasaSelected.similarity_score}`);
            } else {
                setStatus('Seçilen bölgede geçerli veri bulunamadı.');
            }
        } catch (err) {
            console.error('[App] Grid Search hatası:', err);
            setStatus(`Hata: ${err.message}`);
        } finally {
            els.btnRunGridSearch.disabled = false;
            els.btnRunGridSearch.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Bölgeyi Tara ve En İyi Adayları Bul';
        }
    }

    function haversineKm(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * (Math.PI / 180);
        const dLon = (lon2 - lon1) * (Math.PI / 180);
        const a = Math.sin(dLat / 2) ** 2 +
            Math.cos(lat1 * (Math.PI / 180)) * Math.cos(lat2 * (Math.PI / 180)) * Math.sin(dLon / 2) ** 2;
        return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function updateRulerUI() {
        const pts = state.rulerPoints;
        let totalKm = 0;
        for (let i = 1; i < pts.length; i++) {
            totalKm += haversineKm(pts[i - 1].lat, pts[i - 1].lng, pts[i].lat, pts[i].lng);
        }
        if (pts.length >= 3) {
            totalKm += haversineKm(pts[pts.length - 1].lat, pts[pts.length - 1].lng, pts[0].lat, pts[0].lng);
        }

        const T = DIL[state.lang] || DIL.tr;
        if (els.rulerStats) {
            els.rulerStats.textContent = `${pts.length} ${T.points_unit} • ${Math.round(totalKm).toLocaleString()} km`;
        }

        if (pts.length >= 2) {
            const lats = pts.map(p => p.lat);
            const lngs = pts.map(p => p.lng);
            els.gridMinLat.value = Math.min(...lats).toFixed(2);
            els.gridMaxLat.value = Math.max(...lats).toFixed(2);
            els.gridMinLon.value = Math.min(...lngs).toFixed(2);
            els.gridMaxLon.value = Math.max(...lngs).toFixed(2);

            if (els.rulerTraverseBox && window.AstroTerrain) {
                const route = window.AstroTerrain.computeTraverseFromPoints(pts, state.selectedEarth || state.nasaSelected);
                state.lastRulerRoute = route;
                if (route) {
                    els.rulerTraverseBox.classList.remove('d-none');
                    if (els.rulerWalkTime) {
                        els.rulerWalkTime.textContent = `⏱️ ${window.AstroTerrain.formatDuration(route.summary.total_time_min, state.lang)}`;
                    }
                    if (els.rulerClimbStat) {
                        els.rulerClimbStat.textContent = `⛰️ +${route.summary.total_climb_m}m / -${route.summary.total_descent_m}m`;
                    }
                    if (els.rulerHazardStat) {
                        els.rulerHazardStat.textContent = `⚠️ Maks ${route.summary.max_slope_deg}° (%${route.summary.hazard_pct} >15°)`;
                        els.rulerHazardStat.className = route.summary.has_hazard ? 'text-danger fw-bold' : 'text-success';
                    }
                }
            }
        } else if (els.rulerTraverseBox) {
            els.rulerTraverseBox.classList.add('d-none');
            state.lastRulerRoute = null;
        }

        refreshEarthGlobe(pts, pts.length ? pts[pts.length - 1].id : null);
        window.SpaceGlobes.updateRulerPath(pts);
    }

    function addRulerPoint(lat, lng) {
        const idx = state.rulerPoints.length + 1;
        state.rulerPoints.push({
            id: `ruler_${Date.now()}_${idx}`,
            name: `P${idx} (${lat.toFixed(1)}°, ${lng.toFixed(1)}°)`,
            country_or_region: state.lang === 'en' ? 'Ruler Vertex' : 'Cetvel Köşesi',
            mineral_type: `${lat.toFixed(3)}°, ${lng.toFixed(3)}°`,
            lat: Number(lat.toFixed(4)),
            lng: Number(lng.toFixed(4))
        });
        updateRulerUI();
    }

    function undoRulerPoint() {
        if (state.rulerPoints.length === 0) return;
        state.rulerPoints.pop();
        updateRulerUI();
    }

    function clearRulerPoints() {
        state.rulerPoints = [];
        updateRulerUI();
    }

    function setDiscoveryMode(mode) {
        const T = DIL[state.lang] || DIL.tr;
        state.discoveryMode = mode;
        [els.btnModeCurated, els.btnModePoint, els.btnModeGrid].forEach(btn => {
            if (btn) btn.classList.toggle('active', btn.dataset.mode === mode);
        });

        els.earthModeHint.classList.toggle('d-none', mode !== 'point');
        els.gridSearchPanel.classList.toggle('d-none', mode !== 'grid');
        if (mode === 'grid') {
            els.gridSearchPanel.classList.remove('drawer-closed');
            if (els.gridDrawerChevron) els.gridDrawerChevron.className = 'bi bi-chevron-left';
        }
        els.missionFilterWrap.classList.toggle('opacity-50', mode !== 'curated');
        els.missionFilter.disabled = mode !== 'curated';
        window.SpaceGlobes.setEarthCrosshair(mode === 'point' || mode === 'grid');

        els.drawer.classList.add('collapsed');
        els.drawer.setAttribute('aria-hidden', 'true');

        if (mode === 'curated') {
            window.SpaceGlobes.updateRulerPath([]);
            refreshEarthGlobe(state.allEarthAnalogs, null);
            setStatus(T.status_ready);
        } else if (mode === 'point') {
            window.SpaceGlobes.updateRulerPath([]);
            refreshEarthGlobe([], null);
            setStatus(T.status_point);
        } else if (mode === 'grid') {
            updateRulerUI();
            setStatus(T.status_grid);
        }
    }

    function renderMethodologyModal() {
        const container = document.getElementById('methodologyModalBody');
        if (!container) return;

        const isEn = state.lang === 'en';
        const earthCount = state.allEarthAnalogs.length;
        const spaceCount = state.allSpaceTargets.length;
        const accessLabels = isEn
            ? {
                free: 'Free',
                open: 'Open',
                open_data_portal: 'Open Data Portal',
                mostly_free_download: 'Mostly Free',
                free_account_for_download: 'Free Account',
                free_kaggle_account: 'Kaggle Account'
            }
            : {
                free: 'Ücretsiz',
                open: 'Açık Erişim',
                open_data_portal: 'Açık Veri Portalı',
                mostly_free_download: 'Çoğunlukla Ücretsiz',
                free_account_for_download: 'Ücretsiz Hesap',
                free_kaggle_account: 'Kaggle Hesabı'
            };
        const metricNames = isEn
            ? {
                avg_temp_c: 'Temp',
                slope_deg: 'Slope',
                humidity_pct: 'Humidity',
                regolith_similarity: 'Regolith',
                solar_exposure_pct: 'Solar'
            }
            : {
                avg_temp_c: 'Sıcaklık',
                slope_deg: 'Eğim',
                humidity_pct: 'Nem',
                regolith_similarity: 'Regolit',
                solar_exposure_pct: 'Güneş'
            };
        const sourcesHtml = (state.dataSources || []).map(src => {
            const title = isEn ? (src.name_en || src.name) : src.name;
            const desc = isEn ? (src.description_en || src.description) : src.description;
            const typeBadge = src.type === 'portal'
                ? (isEn ? 'Portal' : 'Portal')
                : (isEn ? 'Dataset' : 'Veri Seti');
            const accessBadge = accessLabels[src.access] || src.access;
            const metricPills = (src.feeds_metrics || [])
                .map(m => `<span class="meth-weight-pill">${metricNames[m] || m}</span>`)
                .join('');
            return `
                <div class="col-md-6">
                    <div class="meth-source-item">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                            <a href="${src.url}" target="_blank" rel="noopener noreferrer" class="meth-source-link">
                                ${title} <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                            <span class="badge bg-secondary-subtle text-light border border-secondary-subtle">${typeBadge}</span>
                        </div>
                        <div class="meth-source-meta mb-1">
                            <span><i class="bi bi-building me-1"></i>${src.provider || ''}</span>
                            <span>•</span>
                            <span><i class="bi bi-unlock me-1"></i>${accessBadge}</span>
                        </div>
                        <p class="text-secondary small mb-2">${desc}</p>
                        <div class="meth-weight-grid">${metricPills}</div>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = isEn ? `
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-satellite"></i> NASA &amp; Planetary Data Sources
                        </div>
                        <ul class="meth-source-list">
                            <li>
                                <strong class="text-light">NASA POWER Climatology API</strong><br>
                                <span class="text-secondary">Live multi-year annual averages (<code>ANN</code>, Renewable Energy community) for <code>T2M</code>, <code>T2M_RANGE</code>, <code>PRECTOTCORR</code>, <code>RH2M</code>, and <code>ALLSKY_SFC_SW_DWN</code> with 7-day local caching.</span>
                            </li>
                            <li>
                                <strong class="text-light">Planetary Base Reference Baselines</strong><br>
                                <span class="text-secondary">Moon default reference: Shackleton–de Gerlache Connecting Ridge (<code>89.35°S, 128.0°W</code>, Artemis Permanent Lunar Base) or any selected lunar target. Mars default reference: Jezero Crater &amp; Arcadia Planitia (<code>18.38°N, 77.58°E</code>) or any selected Martian target.</span>
                            </li>
                            <li>
                                <strong class="text-light">Curated Site Catalogs</strong><br>
                                <span class="text-secondary">${spaceCount} extraterrestrial targets paired with ${earthCount} validated Earth analog field sites.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-calculator"></i> Curated Mode Scoring (api/match.php)
                        </div>
                        <p class="text-secondary mb-2">
                            Computes a <strong>Weighted Euclidean Similarity</strong> (0–100%) across 5 normalized planetary-analog parameters:
                        </p>
                        <div class="meth-formula-box">
                            Score = (1 − √( Σ wᵢ · min(1, |Eᵢ − Tᵢ| / Sᵢ)² )) × 100
                        </div>
                        <div class="meth-weight-grid">
                            <span class="meth-weight-pill">Regolith 30%</span>
                            <span class="meth-weight-pill">Humidity 22%</span>
                            <span class="meth-weight-pill">Slope 20%</span>
                            <span class="meth-weight-pill">Solar 15%</span>
                            <span class="meth-weight-pill">Temp 13%</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-cpu"></i> Live NASA POWER Hybrid Scoring
                        </div>
                        <p class="text-secondary mb-2">
                            Point Analysis &amp; Polygon Grid Search apply <strong>Min-Max scaling</strong> followed by a <strong>50/50 Hybrid Similarity</strong> combining directional profile (Cosine) and physical proximity (Euclidean):
                        </p>
                        <div class="meth-formula-box">
                            Hybrid = (0.5 · CosSim(v_ref, v_cand) + 0.5 · (1 / (1 + Dist_Euc))) × 100
                        </div>
                        <div class="meth-weight-grid">
                            <span class="meth-weight-pill">T2M_RANGE 30%</span>
                            <span class="meth-weight-pill">PRECTOTCORR 30%</span>
                            <span class="meth-weight-pill">RH2M 20%</span>
                            <span class="meth-weight-pill">T2M 10%</span>
                            <span class="meth-weight-pill">SW_DWN 10%</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-rocket-takeoff"></i> Calibration &amp; Mission Relevance
                        </div>
                        <p class="text-secondary mb-2">
                            Because no Earth site replicates extraterrestrial gravity or thin/vacuum atmospheres, every match generates a dynamic <strong>Calibration Note</strong>:
                        </p>
                        <ul class="meth-source-list text-secondary">
                            <li><strong class="text-info">Moon (0.165g):</strong> Recommends gravity-offloading suspension or ballast calibration for rover traction &amp; EVA suit tests.</li>
                            <li><strong class="text-warning">Mars (0.38g, ~6 mbar CO₂):</strong> Flags dust transport and thermal dissipation differences.</li>
                            <li><strong class="text-light">Polygon Ray-Casting:</strong> Filters regional grid samples strictly inside user-drawn vertices on the 3D globe.</li>
                        </ul>
                    </div>
                </div>
                ${state.dataSources.length ? `
                <div class="col-12">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-database"></i> External Portals &amp; Datasets (${state.dataSources.length})
                        </div>
                        <div class="row g-2">${sourcesHtml}</div>
                    </div>
                </div>` : ''}
            </div>
        ` : `
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-satellite"></i> NASA &amp; Gezegen Veri Kaynakları
                        </div>
                        <ul class="meth-source-list">
                            <li>
                                <strong class="text-light">NASA POWER Climatology API</strong><br>
                                <span class="text-secondary">Çok yıllık iklim ortalamaları (<code>ANN</code>, Yenilenebilir Enerji topluluğu): <code>T2M</code>, <code>T2M_RANGE</code>, <code>PRECTOTCORR</code>, <code>RH2M</code> ve <code>ALLSKY_SFC_SW_DWN</code> parametreleri (7 gün yerel önbellekli).</span>
                            </li>
                            <li>
                                <strong class="text-light">Kalıcı Gezegen Üssü Referans Profilleri</strong><br>
                                <span class="text-secondary">Ay varsayılan üs referansı: Shackleton–de Gerlache Bağlantı Sırtı (<code>89.35°S, 128.0°W</code>, Artemis Kalıcı Ay Üssü) veya seçilen aktif Ay hedefi. Mars varsayılan üs referansı: Jezero Krateri &amp; Arcadia Planitia (<code>18.38°N, 77.58°E</code>) veya seçilen aktif Mars hedefi.</span>
                            </li>
                            <li>
                                <strong class="text-light">Küratörlü Saha Katalogları</strong><br>
                                <span class="text-secondary">${spaceCount} uzay hedefi ve ${earthCount} doğrulanmış Dünya analog sahası.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-calculator"></i> Sabit Sahalar Skorlaması (api/match.php)
                        </div>
                        <p class="text-secondary mb-2">
                            5 normalize gezegen-analog parametresi üzerinden <strong>Ağırlıklı Öklid Benzerlik Skoru</strong> (%0–%100) hesaplanır:
                        </p>
                        <div class="meth-formula-box">
                            Skor = (1 − √( Σ wᵢ · min(1, |Eᵢ − Tᵢ| / Sᵢ)² )) × 100
                        </div>
                        <div class="meth-weight-grid">
                            <span class="meth-weight-pill">Regolit %30</span>
                            <span class="meth-weight-pill">Nem %22</span>
                            <span class="meth-weight-pill">Eğim %20</span>
                            <span class="meth-weight-pill">Güneş %15</span>
                            <span class="meth-weight-pill">Sıcaklık %13</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-cpu"></i> Canlı NASA POWER Hibrit Skorlama
                        </div>
                        <p class="text-secondary mb-2">
                            Nokta Analizi ve Bölge Tarama modlarında <strong>Min-Max normalizasyonu</strong> sonrası yön (Kosinüs) ve fiziksel yakınlığı (Öklid) birleştiren <strong>%50/%50 Hibrit Skor</strong> uygulanır:
                        </p>
                        <div class="meth-formula-box">
                            Hibrit = (0.5 · CosSim(v_ref, v_aday) + 0.5 · (1 / (1 + Dist_Öklid))) × 100
                        </div>
                        <div class="meth-weight-grid">
                            <span class="meth-weight-pill">T2M_RANGE %30</span>
                            <span class="meth-weight-pill">PRECTOTCORR %30</span>
                            <span class="meth-weight-pill">RH2M %20</span>
                            <span class="meth-weight-pill">T2M %10</span>
                            <span class="meth-weight-pill">SW_DWN %10</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-rocket-takeoff"></i> Kalibrasyon &amp; Görev Uygunluğu
                        </div>
                        <p class="text-secondary mb-2">
                            Dünya'daki hiçbir saha Ay/Mars yerçekimi ve atmosferini birebir sunamadığı için her eşleşmede dinamik <strong>Kalibrasyon Notu</strong> üretilir:
                        </p>
                        <ul class="meth-source-list text-secondary">
                            <li><strong class="text-info">Ay (0.165g):</strong> Rover çekişi ve EVA yürüyüş testleri için yerçekimi azaltıcı askı/balast kalibrasyonu önerir.</li>
                            <li><strong class="text-warning">Mars (0.38g, ~6 mbar CO₂):</strong> Toz taşınımı ve ısıl dağılım farklarını raporlar.</li>
                            <li><strong class="text-light">Poligon Ray-Casting:</strong> 3B kürede çizilen köşe noktalarının yalnızca içindeki adayları tarar.</li>
                        </ul>
                    </div>
                </div>
                ${state.dataSources.length ? `
                <div class="col-12">
                    <div class="meth-section-card">
                        <div class="meth-section-title">
                            <i class="bi bi-database"></i> Harici Veri Portalları &amp; Veri Setleri (${state.dataSources.length})
                        </div>
                        <div class="row g-2">${sourcesHtml}</div>
                    </div>
                </div>` : ''}
            </div>
        `;
    }

    function applyLanguage(lang) {
        state.lang = DIL[lang] ? lang : 'tr';
        const T = DIL[state.lang];
        document.documentElement.lang = state.lang;
        window.SpaceGlobes.setLanguage(state.lang);

        document.querySelectorAll('[data-i18n]').forEach(el => {
            const key = el.getAttribute('data-i18n');
            if (T[key]) el.textContent = T[key];
        });

        renderMethodologyModal();

        // Görev filtresi seçenekleri
        Array.from(els.missionFilter.options).forEach(opt => {
            if (T.missions[opt.value]) {
                opt.textContent = T.missions[opt.value];
            }
        });

        const n = parseInt(els.gridSizeRange.value, 10);
        els.gridSizeValue.textContent = `${n}×${n} (${n * n} ${T.points_unit})`;

        // HUD etiketlerini güncelle
        const targets = getFilteredSpaceTargets();
        els.spaceHudLabel.textContent = `${targets.length} ${T.space_hud}`;
        if (state.discoveryMode === 'curated') {
            const earthCount = state.matchedResults.length || state.allEarthAnalogs.length;
            els.earthHudLabel.textContent = `${earthCount} ${T.earth_hud}`;
        }

        // Açık çekmece varsa yeniden çiz
        if (state.discoveryMode === 'curated' && state.selectedTarget && state.selectedEarth) {
            fetchAnalogMatches(state.selectedTarget.id, state.selectedEarth.id);
        } else if (state.discoveryMode !== 'curated' && state.nasaReference && state.nasaSelected) {
            renderNasaComparisonDrawer();
        }

        if (state.discoveryMode === 'point') {
            setStatus(T.status_point);
        } else if (state.discoveryMode === 'grid') {
            updateRulerUI();
            setStatus(T.status_grid);
        } else {
            setStatus(T.status_ready);
        }
    }

    /**
     * 5 metriği 0-100 ölçeğine normalize edip Chart.js Radar Grafiği çizer.
     */
    function normalizeMetricsForRadar(metrics) {
        // -210°C (PSR Ay kraterleri) ile +40°C (çöl sahaları) arasını 0-100 ölçeğine yayar
        const tempNorm = Math.max(0, Math.min(100, ((metrics.avg_temp_c + 210) / 250) * 100));
        const slopeNorm = Math.max(0, Math.min(100, (metrics.slope_deg / 25) * 100));
        const humNorm = Math.max(0, Math.min(100, metrics.humidity_pct));
        const regNorm = Math.max(0, Math.min(100, metrics.regolith_similarity));
        const solarNorm = Math.max(0, Math.min(100, metrics.solar_exposure_pct));

        return [
            Number(tempNorm.toFixed(1)),
            Number(slopeNorm.toFixed(1)),
            Number(humNorm.toFixed(1)),
            Number(regNorm.toFixed(1)),
            Number(solarNorm.toFixed(1))
        ];
    }

    function renderRadarChart(target, earth) {
        if (!els.radarCanvas || typeof Chart === 'undefined') return;

        const targetColor = target.body === 'Mars' ? 'rgba(249, 115, 22, 1)' : 'rgba(226, 232, 240, 1)';
        const targetBg = target.body === 'Mars' ? 'rgba(249, 115, 22, 0.22)' : 'rgba(226, 232, 240, 0.18)';

        const data = {
            labels: ['Sıcaklık', 'Eğim', 'Nem', 'Regolit', 'Güneşlenme'],
            datasets: [
                {
                    label: target.name.split('(')[0].trim(),
                    data: normalizeMetricsForRadar(target.metrics),
                    borderColor: targetColor,
                    backgroundColor: targetBg,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: earth.name.split('(')[0].trim(),
                    data: normalizeMetricsForRadar(earth.metrics),
                    borderColor: 'rgba(56, 189, 248, 1)',
                    backgroundColor: 'rgba(56, 189, 248, 0.22)',
                    borderWidth: 2,
                    pointRadius: 3
                }
            ]
        };

        if (state.radarChart) {
            state.radarChart.data = data;
            state.radarChart.update();
            return;
        }

        state.radarChart = new Chart(els.radarCanvas, {
            type: 'radar',
            data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        min: 0,
                        max: 100,
                        ticks: { display: false, stepSize: 25 },
                        grid: { color: 'rgba(255, 255, 255, 0.08)' },
                        angleLines: { color: 'rgba(255, 255, 255, 0.1)' },
                        pointLabels: {
                            color: '#cbd5e1',
                            font: { size: 11, family: 'Inter, sans-serif' }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#e2e8f0',
                            boxWidth: 10,
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    }

    function bindEvents() {
        // Moon / Mars Geçişi
        [els.btnMoon, els.btnMars].forEach(btn => {
            btn.addEventListener('click', () => {
                const body = btn.dataset.body;
                if (body === state.currentBody) return;

                state.currentBody = body;
                els.btnMoon.classList.toggle('active', body === 'Moon');
                els.btnMars.classList.toggle('active', body === 'Mars');

                state.selectedTarget = null;
                state.selectedEarth = null;
                state.matchedResults = [];

                els.drawer.classList.add('collapsed');
                window.SpaceGlobes.setSpaceBody(body);
                refreshSpaceGlobe(null);
                if (state.discoveryMode === 'curated') {
                    refreshEarthGlobe(state.allEarthAnalogs, null);
                    setStatus(`${body} görünümüne geçildi • Hedef seçin`);
                } else {
                    refreshEarthGlobe([], null);
                    setStatus(`${body} referansı aktif • ${state.discoveryMode === 'point' ? 'Dünya küresine tıklayın' : 'Bölge taramasını başlatın'}`);
                }
            });
        });

        // Keşif Modu Geçişi
        [els.btnModeCurated, els.btnModePoint, els.btnModeGrid].forEach(btn => {
            if (btn) btn.addEventListener('click', () => setDiscoveryMode(btn.dataset.mode));
        });

        // Cetvel (Ruler) Açılır Çekmece & Geri Al / Temizle
        if (els.btnToggleGridPanel) {
            els.btnToggleGridPanel.addEventListener('click', () => {
                const isClosed = els.gridSearchPanel.classList.toggle('drawer-closed');
                if (els.gridDrawerChevron) {
                    els.gridDrawerChevron.className = isClosed ? 'bi bi-chevron-right' : 'bi bi-chevron-left';
                }
            });
        }
        if (els.btnRulerUndo) els.btnRulerUndo.addEventListener('click', undoRulerPoint);
        if (els.btnRulerClear) els.btnRulerClear.addEventListener('click', clearRulerPoints);

        // Dil Combobox Geçişi
        if (els.langSelect) {
            els.langSelect.addEventListener('change', () => applyLanguage(els.langSelect.value));
        }

        // 3B Kıta Katmanı Aç/Kapat
        if (els.btnToggleContinents) {
            els.btnToggleContinents.addEventListener('click', () => {
                const isActive = els.btnToggleContinents.classList.toggle('active');
                window.SpaceGlobes.setContinentsVisible(isActive);
                if (!isActive && els.selectedContinentChip) {
                    els.selectedContinentChip.classList.add('d-none');
                }
            });
        }

        // Grid Search Slider & Buton
        els.gridSizeRange.addEventListener('input', () => {
            const n = parseInt(els.gridSizeRange.value, 10);
            const T = DIL[state.lang] || DIL.tr;
            els.gridSizeValue.textContent = `${n}×${n} (${n * n} ${T.points_unit})`;
        });
        els.btnRunGridSearch.addEventListener('click', runGridSearch);

        // Görev Türü Filtresi
        els.missionFilter.addEventListener('change', () => {
            state.missionType = els.missionFilter.value;
            if (state.selectedTarget) {
                fetchAnalogMatches(state.selectedTarget.id);
            } else {
                const filteredEarth = state.missionType === 'all'
                    ? state.allEarthAnalogs
                    : state.allEarthAnalogs.filter(e => (e.suitable_missions || []).includes(state.missionType));
                refreshEarthGlobe(filteredEarth, null);
            }
        });

        // Panel Küçült / Kapat
        els.btnToggleDrawer.addEventListener('click', () => {
            const isMin = els.drawer.classList.toggle('minimized');
            els.drawerToggleIcon.className = isMin ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        });

        els.btnCloseDrawer.addEventListener('click', () => {
            els.drawer.classList.add('collapsed');
            els.drawer.setAttribute('aria-hidden', 'true');
        });

        if (els.btnCloseSiteCard) {
            els.btnCloseSiteCard.addEventListener('click', closeEarthSiteCard);
        }

        // God's Eye 3B & Mission Dossier Butonları
        if (els.btnDrawerGodsEye) {
            els.btnDrawerGodsEye.addEventListener('click', () => {
                const site = state.discoveryMode === 'curated' ? state.selectedEarth : state.nasaSelected;
                const target = state.discoveryMode === 'curated' ? state.selectedTarget : state.nasaReference;
                if (site && window.AstroTerrain) {
                    window.AstroTerrain.openGodsEyeModal(site, target, state.rulerPoints.length >= 2 ? state.rulerPoints : null, state.lang);
                }
            });
        }
        if (els.btnDrawerDossier) {
            els.btnDrawerDossier.addEventListener('click', () => {
                const site = state.discoveryMode === 'curated' ? state.selectedEarth : state.nasaSelected;
                const target = state.discoveryMode === 'curated' ? state.selectedTarget : state.nasaReference;
                if (site && window.AstroTerrain) {
                    window.AstroTerrain.exportMissionDossier({
                        site,
                        target,
                        route: state.lastRulerRoute || null,
                        lang: state.lang
                    });
                }
            });
        }
        if (els.btnSiteGodsEye) {
            els.btnSiteGodsEye.addEventListener('click', () => {
                if (state.activeCardSite && window.AstroTerrain) {
                    window.AstroTerrain.openGodsEyeModal(state.activeCardSite, state.selectedTarget, null, state.lang);
                }
            });
        }
        if (els.btnSiteDossier) {
            els.btnSiteDossier.addEventListener('click', () => {
                if (state.activeCardSite && window.AstroTerrain) {
                    window.AstroTerrain.exportMissionDossier({
                        site: state.activeCardSite,
                        target: state.selectedTarget,
                        route: null,
                        lang: state.lang
                    });
                }
            });
        }
        if (els.btnOpenRulerGodsEye) {
            els.btnOpenRulerGodsEye.addEventListener('click', () => {
                if (!window.AstroTerrain) return;
                const pts = state.rulerPoints;
                const centerLat = pts.length ? pts.reduce((a, b) => a + b.lat, 0) / pts.length : 38.5;
                const centerLng = pts.length ? pts.reduce((a, b) => a + b.lng, 0) / pts.length : 34.5;
                const baseSite = state.nasaSelected || state.selectedEarth || {
                    id: 'ruler_custom_region',
                    name: `${centerLat.toFixed(2)}°, ${centerLng.toFixed(2)}° (EVA Traverse)`,
                    country_or_region: state.lang === 'en' ? 'Custom Polygon Region' : 'Özel Cetvel Bölgesi',
                    lat: centerLat,
                    lng: centerLng,
                    metrics: { avg_temp_c: -5, slope_deg: 11.5, humidity_pct: 22, regolith_similarity: 78, solar_exposure_pct: 65 }
                };
                window.AstroTerrain.openGodsEyeModal(baseSite, state.selectedTarget || state.nasaReference, pts, state.lang);
            });
        }
        if (els.btnExportRulerGeoJson) {
            els.btnExportRulerGeoJson.addEventListener('click', () => {
                if (state.lastRulerRoute && window.AstroTerrain) {
                    window.AstroTerrain.exportTraverseGeoJson(state.lastRulerRoute, state.nasaSelected || state.selectedEarth);
                }
            });
        }

        if (els.siteFrameLightbox) {
            els.siteFrameLightbox.addEventListener('click', e => {
                if (e.target === els.siteFrameLightbox) {
                    closeSiteFrameLightbox();
                }
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape' && !els.siteFrameLightbox.classList.contains('d-none')) {
                    closeSiteFrameLightbox();
                }
            });
        }

        initSplitDivider();
    }

    function getCountryFlagCode(regionStr) {
        const s = (regionStr || '').toLowerCase();
        if (s.includes('türkiye') || s.includes('turkey') || s.includes('anadolu') || s.includes('anatolia')) return { code: 'tr', emoji: '🇹🇷' };
        if (s.includes('meksika') || s.includes('mexico') || s.includes('sonora')) return { code: 'mx', emoji: '🇲🇽' };
        if (s.includes('abd') || s.includes('usa') || s.includes('california') || s.includes('arizona') || s.includes('utah') || s.includes('alaska') || s.includes('hawai') || s.includes('new mexico') || s.includes('idaho')) return { code: 'us', emoji: '🇺🇸' };
        if (s.includes('arjantin') || s.includes('argentina')) return { code: 'ar', emoji: '🇦🇷' };
        if (s.includes('şili') || s.includes('chile')) return { code: 'cl', emoji: '🇨🇱' };
        if (s.includes('bolivya') || s.includes('bolivia')) return { code: 'bo', emoji: '🇧🇴' };
        if (s.includes('peru')) return { code: 'pe', emoji: '🇵🇪' };
        if (s.includes('grönland') || s.includes('greenland')) return { code: 'gl', emoji: '🇬🇱' };
        if (s.includes('kanada') || s.includes('canada') || s.includes('nunavut') || s.includes('labrador')) return { code: 'ca', emoji: '🇨🇦' };
        if (s.includes('izlanda') || s.includes('iceland')) return { code: 'is', emoji: '🇮🇸' };
        if (s.includes('ispanya') || s.includes('spain') || s.includes('kanarya') || s.includes('canary')) return { code: 'es', emoji: '🇪🇸' };
        if (s.includes('italya') || s.includes('italy') || s.includes('sicilya') || s.includes('sicily')) return { code: 'it', emoji: '🇮🇹' };
        if (s.includes('almanya') || s.includes('germany') || s.includes('bavyera') || s.includes('bavaria')) return { code: 'de', emoji: '🇩🇪' };
        if (s.includes('norveç') || s.includes('norway') || s.includes('svalbard')) return { code: 'no', emoji: '🇳🇴' };
        if (s.includes('fas') || s.includes('morocco')) return { code: 'ma', emoji: '🇲🇦' };
        if (s.includes('etiyopya') || s.includes('ethiopia') || s.includes('afar')) return { code: 'et', emoji: '🇪🇹' };
        if (s.includes('çad') || s.includes('chad')) return { code: 'td', emoji: '🇹🇩' };
        if (s.includes('botsvana') || s.includes('botswana')) return { code: 'bw', emoji: '🇧🇼' };
        if (s.includes('namibya') || s.includes('namibia')) return { code: 'na', emoji: '🇳🇦' };
        if (s.includes('umman') || s.includes('oman')) return { code: 'om', emoji: '🇴🇲' };
        if (s.includes('suudi') || s.includes('saudi')) return { code: 'sa', emoji: '🇸🇦' };
        if (s.includes('iran')) return { code: 'ir', emoji: '🇮🇷' };
        if (s.includes('hindistan') || s.includes('india') || s.includes('ladakh')) return { code: 'in', emoji: '🇮🇳' };
        if (s.includes('çin') || s.includes('china') || s.includes('qinghai') || s.includes('gansu')) return { code: 'cn', emoji: '🇨🇳' };
        if (s.includes('kore') || s.includes('korea') || s.includes('jeju')) return { code: 'kr', emoji: '🇰🇷' };
        if (s.includes('rusya') || s.includes('russia') || s.includes('kamçatka') || s.includes('kamchatka')) return { code: 'ru', emoji: '🇷🇺' };
        if (s.includes('avustralya') || s.includes('australia') || s.includes('queensland')) return { code: 'au', emoji: '🇦🇺' };
        if (s.includes('yeni zelanda') || s.includes('new zealand')) return { code: 'nz', emoji: '🇳🇿' };
        if (s.includes('antarktika') || s.includes('antarctica')) return { code: 'aq', emoji: '🇦🇶' };
        return { code: '', emoji: '🌍' };
    }

    function latLngToEsriTileUrl(lat, lng, zoom) {
        const latRad = (lat * Math.PI) / 180;
        const n = 2 ** zoom;
        const x = Math.floor(((lng + 180) / 360) * n);
        const y = Math.floor(((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n);
        return `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${zoom}/${y}/${x}`;
    }

    function openSiteFrameLightbox(imgUrl, title, badgeText) {
        if (!els.siteFrameLightbox || !els.siteLightboxImg) return;
        els.siteLightboxImg.src = imgUrl;
        els.siteLightboxImg.alt = `${title} - ${badgeText}`;
        els.siteLightboxTitle.textContent = title;
        els.siteLightboxBadge.textContent = badgeText;
        els.siteFrameLightbox.classList.remove('d-none');
        els.siteFrameLightbox.setAttribute('aria-hidden', 'false');
    }

    function closeSiteFrameLightbox() {
        if (!els.siteFrameLightbox) return;
        els.siteFrameLightbox.classList.add('d-none');
        els.siteFrameLightbox.setAttribute('aria-hidden', 'true');
        if (els.siteLightboxImg) els.siteLightboxImg.src = '';
    }

    function closeEarthSiteCard() {
        if (!els.earthSiteCard) return;
        els.earthSiteCard.classList.add('collapsed');
        els.earthSiteCard.setAttribute('aria-hidden', 'true');
    }

    function openEarthSiteCard(earthItem) {
        if (!els.earthSiteCard || !earthItem) return;
        state.activeCardSite = earthItem;
        const isEn = state.lang === 'en';
        const T = DIL[state.lang] || DIL.tr;

        // Bayrak & Başlık
        const regionText = getLoc(earthItem, 'country_or_region');
        const flagMeta = getCountryFlagCode(earthItem.country_or_region_en || earthItem.country_or_region);
        if (flagMeta.code) {
            els.siteCardFlag.src = `https://flagcdn.com/w80/${flagMeta.code}.png`;
            els.siteCardFlag.alt = regionText;
            els.siteCardFlag.classList.remove('d-none');
            els.siteCardFlagFallback.classList.add('d-none');
            els.siteCardFlag.onerror = () => {
                els.siteCardFlag.classList.add('d-none');
                els.siteCardFlagFallback.textContent = flagMeta.emoji;
                els.siteCardFlagFallback.classList.remove('d-none');
            };
        } else {
            els.siteCardFlag.classList.add('d-none');
            els.siteCardFlagFallback.textContent = flagMeta.emoji;
            els.siteCardFlagFallback.classList.remove('d-none');
        }

        els.siteCardTitle.textContent = getLoc(earthItem, 'name');
        const latStr = `${Math.abs(earthItem.lat).toFixed(2)}°${earthItem.lat >= 0 ? 'N' : 'S'}`;
        const lngStr = `${Math.abs(earthItem.lng).toFixed(2)}°${earthItem.lng >= 0 ? 'E' : 'W'}`;
        els.siteCardSubtitle.textContent = `${regionText} • ${latStr}, ${lngStr}`;

        if (els.siteCardContextStrip && window.AstroTerrain) {
            els.siteCardContextStrip.innerHTML = window.AstroTerrain.renderContextStrip(earthItem, state.lang);
        }
        if (els.siteCardReadiness && window.AstroTerrain) {
            els.siteCardReadiness.innerHTML = earthItem.qualification
                ? window.AstroTerrain.renderReadinessHtml(earthItem.qualification, state.lang, true)
                : '';
        }

        // Etiketler
        els.siteFramesLabel.textContent = isEn ? 'Satellite Frames from Site' : 'Bölgeden Uydu Kareleri';
        els.siteSpecsLabel.textContent = isEn ? 'Specific Characteristics' : 'Spesifik Özellikler';
        els.siteMineralLabel.textContent = isEn ? 'Mineralogy:' : 'Mineraloji:';

        // Bölgeden 3 farklı ölçekte gerçek uydu karesi
        const zoomLevels = isEn
            ? [{ z: 9, lbl: 'Region' }, { z: 11, lbl: 'Basin' }, { z: 13, lbl: 'Close-Up' }]
            : [{ z: 9, lbl: 'Bölge' }, { z: 11, lbl: 'Havza' }, { z: 13, lbl: 'Yakın' }];
        els.siteCardFrames.innerHTML = zoomLevels.map(item => {
            const tileUrl = latLngToEsriTileUrl(earthItem.lat, earthItem.lng, item.z);
            return `
                <div class="site-frame-tile" data-url="${tileUrl}" data-label="${item.lbl}" title="Büyütmek için tıklayın">
                    <img src="${tileUrl}" alt="${getLoc(earthItem, 'name')} - ${item.lbl}" loading="lazy"
                         onerror="this.parentElement.classList.add('tile-error'); this.style.display='none';">
                    <span class="site-frame-badge">${item.lbl}</span>
                </div>
            `;
        }).join('');

        els.siteCardFrames.querySelectorAll('.site-frame-tile').forEach(tile => {
            tile.addEventListener('click', () => {
                if (tile.classList.contains('tile-error')) return;
                openSiteFrameLightbox(tile.dataset.url, getLoc(earthItem, 'name'), tile.dataset.label);
            });
        });

        // Spesifik Özellikler (5 Metrik)
        const m = earthItem.metrics || {};
        const specs = [
            { label: isEn ? 'Avg. Temp' : 'Ort. Sıcaklık', val: `${m.avg_temp_c ?? '—'}°C` },
            { label: isEn ? 'Slope' : 'Yüzey Eğimi', val: `${m.slope_deg ?? '—'}°` },
            { label: isEn ? 'Humidity' : 'Nem Oranı', val: `${m.humidity_pct ?? '—'}%` },
            { label: isEn ? 'Regolith' : 'Regolit Uyumu', val: `${m.regolith_similarity ?? '—'}/100` },
            { label: isEn ? 'Solar' : 'Güneşlenme', val: `${m.solar_exposure_pct ?? '—'}%` }
        ];
        els.siteCardSpecs.innerHTML = specs.map(s => `
            <div class="site-spec-item">
                <span class="site-spec-label">${s.label}</span>
                <span class="site-spec-value mono-text">${s.val}</span>
            </div>
        `).join('');

        els.siteCardMineral.textContent = getLoc(earthItem, 'mineral_type');
        els.siteCardDesc.textContent = getLoc(earthItem, 'description');

        els.siteCardMissions.innerHTML = '';
        (earthItem.suitable_missions || []).slice(0, 4).forEach(ms => {
            const span = document.createElement('span');
            span.className = 'mission-tag';
            span.textContent = (T.missions && T.missions[ms]) ? T.missions[ms] : (MISSION_LABELS[ms] || ms);
            els.siteCardMissions.appendChild(span);
        });

        els.earthSiteCard.classList.remove('collapsed');
        els.earthSiteCard.setAttribute('aria-hidden', 'false');
    }

    function initSplitDivider() {
        const splitUniverse = document.querySelector('.split-universe');
        const divider = document.getElementById('splitDivider');
        if (!splitUniverse || !divider) return;

        const MIN_RATIO = 0.30;
        const MAX_RATIO = 0.70;
        let isDragging = false;

        const applyRatio = ratio => {
            const clamped = Math.max(MIN_RATIO, Math.min(MAX_RATIO, ratio));
            splitUniverse.style.setProperty('--split-left', `${(clamped * 100).toFixed(2)}%`);
            if (window.SpaceGlobes && typeof window.SpaceGlobes.resize === 'function') {
                window.SpaceGlobes.resize();
            }
        };

        divider.addEventListener('pointerdown', e => {
            if (window.innerWidth <= 768) return;
            e.preventDefault();
            isDragging = true;
            divider.classList.add('dragging');
            document.body.classList.add('is-resizing-split');
        });

        window.addEventListener('pointermove', e => {
            if (!isDragging) return;
            const rect = splitUniverse.getBoundingClientRect();
            if (rect.width <= 0) return;
            const ratio = (e.clientX - rect.left) / rect.width;
            applyRatio(ratio);
        });

        const stopDragging = () => {
            if (!isDragging) return;
            isDragging = false;
            divider.classList.remove('dragging');
            document.body.classList.remove('is-resizing-split');
        };

        window.addEventListener('pointerup', stopDragging);
        window.addEventListener('pointercancel', stopDragging);

        divider.addEventListener('dblclick', () => {
            if (window.innerWidth <= 768) return;
            applyRatio(0.50);
        });
    }

    function bootstrap() {
        window.SpaceGlobes.init({
            onSpaceClick: target => {
                window.SpaceGlobes.flyToSpace(target.lat, target.lng, 1.75);
                refreshSpaceGlobe(target.id);
                fetchAnalogMatches(target.id);
            },
            onSpaceGlobeClick: coords => {
                fetchDynamicPlanetaryMatch(coords.lat, coords.lng);
            },
            onEarthClick: earthItem => {
                openEarthSiteCard(earthItem);
                window.SpaceGlobes.flyToEarth(earthItem.lat, earthItem.lng, 1.55);
                if (state.selectedTarget) {
                    selectEarthAnalog(earthItem);
                }
            },
            onEarthGlobeClick: coords => {
                if (state.discoveryMode === 'point') {
                    analyzeEarthCoordinate(coords.lat, coords.lng);
                } else if (state.discoveryMode === 'grid') {
                    addRulerPoint(coords.lat, coords.lng);
                } else {
                    fetchDynamicEarthAgainstTarget(coords.lat, coords.lng);
                }
            },
            onContinentClick: (continentKey, meta, coords) => {
                if (state.discoveryMode === 'point' && coords && typeof coords.lat === 'number') {
                    analyzeEarthCoordinate(coords.lat, coords.lng);
                    return;
                }
                if (state.discoveryMode === 'grid' && coords && typeof coords.lat === 'number') {
                    addRulerPoint(coords.lat, coords.lng);
                    return;
                }
                if (!els.selectedContinentChip || !els.selectedContinentLabel) return;
                if (continentKey && meta) {
                    els.selectedContinentLabel.textContent = state.lang === 'en' ? meta.en : meta.tr;
                    els.selectedContinentChip.classList.remove('d-none');
                } else {
                    els.selectedContinentChip.classList.add('d-none');
                }
            }
        });

        refreshSpaceGlobe(null);
        refreshEarthGlobe(state.allEarthAnalogs, null);
        renderMethodologyModal();
        bindEvents();
    }

    document.addEventListener('DOMContentLoaded', bootstrap);
})();
