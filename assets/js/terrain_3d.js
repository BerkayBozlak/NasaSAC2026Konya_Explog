/**
 * terrain_3d.js
 * 3B Arazi, Donanım Hazırlık & EVA Rota Modülü:
 * 1. TRL 5–6 Donanım Test Hazırlık Matrisi (Hardware Qualification)
 * 2. Keşif Özgünlüğü (Novelty Detection) & En Yakın Lav Tüpü / Mağara Bağlamı
 * 3. Tobler Yürüyüş Fonksiyonu ile EVA Traverse & Eğim Tehlike (>15°) Analizi
 * 4. Tek Tıkla Görev Dosyası (Mission Dossier HTML) & GeoJSON Rota Çıktısı
 * 5. God's Eye 3B Arazi & Ay Güney Kutbu (1.5°) Düşük Güneş Gölge Simülatörü
 */

(() => {
    const TERRARIUM_URL = 'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png';
    const ESRI_SAT_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';

    const state = {
        lang: 'tr',
        activeSite: null,
        activeTarget: null,
        currentRoute: null,
        demBlock: null,
        satCanvas: null,
        lastTerrainStats: null,
        drapeMode: 'satellite',
        sunElevDeg: 1.5,
        sunAzimDeg: 135,
        exaggeration: 2.5,
        waypointsUV: [], // [{ u, v, lat, lng, elev }]
        three: {
            initialized: false,
            renderer: null,
            scene: null,
            camera: null,
            terrainMesh: null,
            routeGroup: null,
            sunLight: null,
            raycaster: null,
            pointerDownPos: null,
            isDragging: false,
            spherical: { radius: 2.15, theta: 0.58, phi: 0.96 },
            targetVec: null
        },
        fallback2d: {
            canvas: null,
            yaw: 0.62,
            pitch: 0.55,
            zoom: 1.0,
            isDragging: false,
            downPos: null,
            projGrid: null
        }
    };

    function esc(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function haversineKm(lat1, lon1, lat2, lon2) {
        const R = 6371.0;
        const dLat = ((lat2 - lat1) * Math.PI) / 180;
        const dLon = ((lon2 - lon1) * Math.PI) / 180;
        const a =
            Math.sin(dLat / 2) ** 2 +
            Math.cos((lat1 * Math.PI) / 180) *
                Math.cos((lat2 * Math.PI) / 180) *
                Math.sin(dLon / 2) ** 2;
        return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function getReqLabel(r, isEn) {
        if (!r) return '';
        if (isEn) return r.label_en || r.label || r.label_tr || r.metric || '';
        return r.label_tr || r.label || r.label_en || r.metric || '';
    }

    function getReqActual(r) {
        if (!r) return '';
        if (r.actual !== undefined && r.actual !== null && r.actual !== '') return String(r.actual);
        const val = r.value !== undefined ? r.value : '';
        const unit = r.unit || '';
        const cmp = r.comparison || '';
        const thr = r.threshold !== undefined ? r.threshold : '';
        if (cmp && thr !== '') {
            return `${val}${unit} (${cmp} ${thr}${unit})`;
        }
        return `${val}${unit}`;
    }

    function getCatTitle(cat, isEn) {
        if (!cat) return '';
        if (isEn) return cat.title_en || cat.name_en || cat.name || cat.title_tr || '';
        return cat.title_tr || cat.name || cat.title_en || '';
    }

    function getCatVerdictLabel(cat, isEn) {
        if (!cat) return '';
        if (isEn) return cat.verdict_label_en || cat.verdict_label || (cat.verdict === 'ready' ? 'Ready' : cat.verdict === 'partial' ? 'Partial' : 'Not Suited');
        return cat.verdict_label_tr || cat.verdict_label || (cat.verdict === 'ready' ? 'Teste Hazır' : cat.verdict === 'partial' ? 'Kısmi Uygun' : 'Uygun Değil');
    }

    // =========================================================================
    // 1. KEŞİF ÖZGÜNLÜĞÜ (NOVELTY) & EN YAKIN MAĞARA BAĞLAMI
    // =========================================================================
    function renderNoveltyBadge(novelty, lang = 'tr') {
        if (!novelty) return '';
        const isEn = lang === 'en';
        const status = novelty.status || 'known';
        const label = isEn
            ? (novelty.badge_en || novelty.badge || 'Catalog Site')
            : (novelty.badge_tr || novelty.badge || 'Katalog Sahası');
        const nearName = isEn
            ? (novelty.nearest_name_en || novelty.nearest_name || novelty.nearest_known || '')
            : (novelty.nearest_name || novelty.nearest_known || '');
        const distInfo = novelty.distance_km > 5 && nearName ? ` (${novelty.distance_km} km → ${nearName})` : '';
        return `<span class="novelty-pill novelty-${esc(status)}" title="${esc(label + distInfo)}">${esc(label)}</span>`;
    }

    function renderContextStrip(site, lang = 'tr') {
        if (!site) return '';
        const isEn = lang === 'en';
        const noveltyHtml = site.novelty ? renderNoveltyBadge(site.novelty, lang) : '';
        let caveHtml = '';
        if (site.nearest_cave) {
            const c = site.nearest_cave;
            const cName = isEn ? (c.name_en || c.name) : (c.name_tr || c.name || c.name_en);
            const caveUrl = c.source_url || c.url || '';
            const cLink = caveUrl
                ? `<a href="${esc(caveUrl)}" target="_blank" rel="noopener noreferrer" class="context-ext-link">${esc(cName)}</a>`
                : `<strong>${esc(cName)}</strong>`;
            caveHtml = `
                <span class="context-cave-inline">
                    <i class="bi bi-compass text-warning me-1"></i>${cLink} <span class="mono-text text-info">(${esc(c.distance_km)} km)</span>
                </span>
            `;
        }
        let wikiHtml = '';
        if (site.source_url) {
            wikiHtml = `
                <a href="${esc(site.source_url)}" target="_blank" rel="noopener noreferrer" class="context-wiki-pill">
                    <i class="bi bi-box-arrow-up-right me-1"></i>${isEn ? 'Source' : 'Kaynak'}
                </a>
            `;
        }
        return `
            <div class="site-context-wrap d-flex flex-wrap align-items-center justify-content-between gap-1">
                <div class="d-flex flex-wrap align-items-center gap-1">
                    ${noveltyHtml}
                    ${wikiHtml}
                </div>
                ${caveHtml}
            </div>
        `;
    }

    // =========================================================================
    // 2. TRL 5–6 DONANIM TEST HAZIRLIK MATRİSİ (QUALIFICATION)
    // =========================================================================
    function renderReadinessHtml(qualification, lang = 'tr', compact = false) {
        if (!qualification || !Array.isArray(qualification.categories)) return '';
        const isEn = lang === 'en';

        if (compact) {
            const shortTitlesTr = {
                ice_drill: 'Buz Sondajı',
                habitat_isru: 'İniş & Habitat',
                eva_suit: 'EVA Giysi',
                rover: 'Rover Sürüş'
            };
            const shortTitlesEn = {
                ice_drill: 'Ice Drill',
                habitat_isru: 'Habitat & ISRU',
                eva_suit: 'EVA Suit',
                rover: 'Rover Drive'
            };
            const shortVerdictTr = {
                ready: '✓ Uygun',
                partial: '≈ Kısmi',
                not_suited: '✗ Düşük'
            };
            const shortVerdictEn = {
                ready: '✓ Ready',
                partial: '≈ Partial',
                not_suited: '✗ Low'
            };

            const pills = qualification.categories.map(cat => {
                const title = isEn
                    ? (shortTitlesEn[cat.id] || getCatTitle(cat, true))
                    : (shortTitlesTr[cat.id] || getCatTitle(cat, false));
                const vLabel = isEn
                    ? (shortVerdictEn[cat.verdict] || 'Partial')
                    : (shortVerdictTr[cat.verdict] || 'Kısmi');
                const tooltipLines = (cat.requirements || [])
                    .map(r => `${getReqLabel(r, isEn)}: ${getReqActual(r)}`)
                    .join(' • ');

                return `
                    <div class="trl-compact-pill trl-verdict-${esc(cat.verdict)}" title="${esc(tooltipLines)}">
                        <span class="trl-compact-name"><i class="bi ${esc(cat.icon || 'bi-cpu')} me-1"></i>${esc(title)}</span>
                        <span class="rd-pill rd-${esc(cat.verdict)}">${esc(vLabel)}</span>
                    </div>
                `;
            }).join('');

            return `<div class="trl-compact-grid">${pills}</div>`;
        }

        const cards = qualification.categories.map(cat => {
            const title = getCatTitle(cat, isEn);
            const verdict = getCatVerdictLabel(cat, isEn);
            const reqRows = (cat.requirements || []).map(r => {
                const rLabel = getReqLabel(r, isEn);
                const rActual = getReqActual(r);
                const icon = r.status === 'pass' ? '✓' : (r.status === 'near' ? '≈' : '✗');
                return `
                    <div class="trl-req-row trl-req-${esc(r.status)}" title="${esc(r.why || '')}">
                        <span class="trl-req-icon mono-text">${icon}</span>
                        <span class="trl-req-name">${esc(rLabel)}</span>
                        <span class="trl-req-val mono-text">${esc(rActual)}</span>
                    </div>
                `;
            }).join('');

            return `
                <div class="trl-card trl-verdict-${esc(cat.verdict)}">
                    <div class="trl-card-head">
                        <span class="trl-card-name" title="${esc(cat.system || title)}"><i class="bi ${esc(cat.icon || 'bi-cpu')} me-1"></i>${esc(title)}</span>
                        <span class="rd-pill rd-${esc(cat.verdict)}">${esc(verdict)}</span>
                    </div>
                    <div class="trl-req-list">${reqRows}</div>
                </div>
            `;
        }).join('');

        return `<div class="trl-matrix-grid">${cards}</div>`;
    }

    // =========================================================================
    // 3. EVA TRAVERSE ANALİZİ (TOBLER YÜRÜYÜŞ FONKSİYONU) & GEOJSON
    // =========================================================================
    function proceduralElevationAt(lat, lng, baseElevM = 1200, baseSlopeDeg = 8) {
        const r1 = Math.sin(lat * 127.1 + lng * 311.7) * 43758.5453;
        const r2 = Math.cos(lat * 269.5 + lng * 183.3) * 28411.1234;
        const n1 = r1 - Math.floor(r1);
        const n2 = r2 - Math.floor(r2);
        const wave =
            Math.sin(lat * 85) * Math.cos(lng * 85) * (baseSlopeDeg * 14) +
            Math.sin(lat * 240 + 1.3) * Math.sin(lng * 240 + 0.7) * (baseSlopeDeg * 6) +
            (n1 - 0.5) * 18 +
            (n2 - 0.5) * 9;
        return baseElevM + wave;
    }

    function computeTraverseFromPoints(points, siteContext = null) {
        if (!Array.isArray(points) || points.length < 2) return null;

        const baseSlope = siteContext?.metrics?.slope_deg ?? 9.5;
        const baseElev = siteContext?.lat ? Math.round(450 + Math.abs(siteContext.lat) * 18) : 1150;

        const samples = [];
        const legs = [];
        let cumDistKm = 0;
        let totalTimeHr = 0;
        let totalClimbM = 0;
        let totalDescentM = 0;
        let maxSlopeDeg = 0;
        let hazardDistKm = 0;

        const SUB_STEPS = 12;

        for (let i = 0; i < points.length - 1; i++) {
            const pA = points[i];
            const pB = points[i + 1];
            const legDistKm = Math.max(0.02, haversineKm(pA.lat, pA.lng, pB.lat, pB.lng));
            let legTimeHr = 0;
            let legClimbM = 0;
            let legDescentM = 0;
            let legMaxSlope = 0;

            for (let s = 0; s <= SUB_STEPS; s++) {
                if (i > 0 && s === 0) continue;
                const t = s / SUB_STEPS;
                const lat = pA.lat + (pB.lat - pA.lat) * t;
                const lng = pA.lng + (pB.lng - pA.lng) * t;

                let elev = pA.elev !== undefined && pB.elev !== undefined
                    ? pA.elev + (pB.elev - pA.elev) * t + Math.sin(t * Math.PI * 2 + i) * (baseSlope * 1.35)
                    : proceduralElevationAt(lat, lng, baseElev, baseSlope);

                elev = Number(elev.toFixed(1));

                if (samples.length === 0) {
                    samples.push({
                        dist_km: 0,
                        elev_m: elev,
                        slope_deg: 0,
                        speed_kmh: 4.5,
                        hazard: false,
                        lat,
                        lng
                    });
                } else {
                    const prev = samples[samples.length - 1];
                    const stepKm = legDistKm / SUB_STEPS;
                    const stepM = Math.max(1.0, stepKm * 1000.0);
                    const dh = elev - prev.elev_m;
                    const dhdx = dh / stepM;
                    const slopeDeg = Math.abs((Math.atan(dhdx) * 180) / Math.PI);

                    const toblerKmh = 6.0 * Math.exp(-3.5 * Math.abs(dhdx + 0.05)) * 0.72;
                    const speedKmh = Math.max(0.45, Math.min(5.5, toblerKmh));
                    const dtHr = stepKm / speedKmh;

                    cumDistKm += stepKm;
                    totalTimeHr += dtHr;
                    legTimeHr += dtHr;

                    if (dh > 0) {
                        totalClimbM += dh;
                        legClimbM += dh;
                    } else {
                        totalDescentM += Math.abs(dh);
                        legDescentM += Math.abs(dh);
                    }

                    if (slopeDeg > maxSlopeDeg) maxSlopeDeg = slopeDeg;
                    if (slopeDeg > legMaxSlope) legMaxSlope = slopeDeg;
                    const isHazard = slopeDeg >= 15.0;
                    if (isHazard) hazardDistKm += stepKm;

                    samples.push({
                        dist_km: Number(cumDistKm.toFixed(3)),
                        elev_m: elev,
                        slope_deg: Number(slopeDeg.toFixed(1)),
                        speed_kmh: Number(speedKmh.toFixed(2)),
                        hazard: isHazard,
                        lat,
                        lng
                    });
                }
            }

            legs.push({
                index: i + 1,
                from_label: `P${i + 1}`,
                to_label: `P${i + 2}`,
                dist_km: Number(legDistKm.toFixed(2)),
                time_min: Math.max(1, Math.round(legTimeHr * 60)),
                climb_m: Math.round(legClimbM),
                descent_m: Math.round(legDescentM),
                max_slope_deg: Number(legMaxSlope.toFixed(1)),
                hazard: legMaxSlope >= 15.0
            });
        }

        const hazardPct = cumDistKm > 0 ? Math.min(100, Math.round((hazardDistKm / cumDistKm) * 100)) : 0;

        return {
            waypoints: points.map((p, idx) => ({
                label: `P${idx + 1}`,
                lat: Number(p.lat.toFixed(4)),
                lng: Number(p.lng.toFixed(4)),
                elev_m: p.elev !== undefined ? Math.round(p.elev) : Math.round(proceduralElevationAt(p.lat, p.lng, baseElev, baseSlope))
            })),
            samples,
            legs,
            summary: {
                total_dist_km: Number(cumDistKm.toFixed(2)),
                total_time_hr: Number(totalTimeHr.toFixed(2)),
                total_time_min: Math.max(1, Math.round(totalTimeHr * 60)),
                total_climb_m: Math.round(totalClimbM),
                total_descent_m: Math.round(totalDescentM),
                max_slope_deg: Number(maxSlopeDeg.toFixed(1)),
                hazard_pct: hazardPct,
                has_hazard: maxSlopeDeg >= 15.0
            }
        };
    }

    function formatDuration(totalMin, lang = 'tr') {
        const hrs = Math.floor(totalMin / 60);
        const mins = totalMin % 60;
        if (hrs <= 0) return `${mins} ${lang === 'en' ? 'min' : 'dk'}`;
        return `${hrs}${lang === 'en' ? 'h' : 'sa'} ${mins}${lang === 'en' ? 'm' : 'dk'}`;
    }

    function buildTraverseSvg(route, lang = 'tr') {
        if (!route || !Array.isArray(route.samples) || route.samples.length < 2) {
            return `<div class="text-secondary small py-2">${
                lang === 'en'
                    ? 'Click 2 or more points on the 3D terrain to generate an EVA elevation & slope hazard profile.'
                    : 'EVA yükseklik ve >15° eğim tehlike profili oluşturmak için 3B arazi üzerinde en az 2 noktaya tıklayın.'
            }</div>`;
        }

        const W = 340;
        const H = 118;
        const PAD_L = 36;
        const PAD_R = 10;
        const PAD_T = 14;
        const PAD_B = 22;
        const plotW = W - PAD_L - PAD_R;
        const plotH = H - PAD_T - PAD_B;

        const samples = route.samples;
        const maxDist = Math.max(0.01, samples[samples.length - 1].dist_km);
        const elevs = samples.map(s => s.elev_m);
        const minE = Math.min(...elevs);
        const maxE = Math.max(...elevs, minE + 10);

        const xOf = d => PAD_L + (d / maxDist) * plotW;
        const yOf = e => PAD_T + plotH - ((e - minE) / (maxE - minE)) * plotH;

        const ptsStr = samples.map(s => `${xOf(s.dist_km).toFixed(1)},${yOf(s.elev_m).toFixed(1)}`).join(' ');
        const areaStr = `${PAD_L},${PAD_T + plotH} ${ptsStr} ${xOf(maxDist).toFixed(1)},${PAD_T + plotH}`;

        let hazardSegments = '';
        for (let i = 1; i < samples.length; i++) {
            if (samples[i].hazard) {
                const x1 = xOf(samples[i - 1].dist_km).toFixed(1);
                const y1 = yOf(samples[i - 1].elev_m).toFixed(1);
                const x2 = xOf(samples[i].dist_km).toFixed(1);
                const y2 = yOf(samples[i].elev_m).toFixed(1);
                hazardSegments += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#ef4444" stroke-width="3" stroke-linecap="round" />`;
            }
        }

        return `
            <svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}" class="traverse-svg">
                <defs>
                    <linearGradient id="evaElevGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.42"/>
                        <stop offset="100%" stop-color="#38bdf8" stop-opacity="0.03"/>
                    </linearGradient>
                </defs>
                <line x1="${PAD_L}" y1="${PAD_T}" x2="${W - PAD_R}" y2="${PAD_T}" stroke="rgba(255,255,255,0.08)" stroke-dasharray="2,2"/>
                <line x1="${PAD_L}" y1="${PAD_T + plotH}" x2="${W - PAD_R}" y2="${PAD_T + plotH}" stroke="rgba(255,255,255,0.18)"/>
                <polygon points="${areaStr}" fill="url(#evaElevGrad)" />
                <polyline points="${ptsStr}" fill="none" stroke="#38bdf8" stroke-width="2" />
                ${hazardSegments}
                <text x="${PAD_L - 4}" y="${PAD_T + 4}" text-anchor="end" fill="#94a3b8" font-size="9" font-family="JetBrains Mono">${Math.round(maxE)}m</text>
                <text x="${PAD_L - 4}" y="${PAD_T + plotH}" text-anchor="end" fill="#94a3b8" font-size="9" font-family="JetBrains Mono">${Math.round(minE)}m</text>
                <text x="${PAD_L}" y="${H - 5}" fill="#94a3b8" font-size="9" font-family="JetBrains Mono">0 km</text>
                <text x="${W - PAD_R}" y="${H - 5}" text-anchor="end" fill="#38bdf8" font-size="9" font-family="JetBrains Mono">${maxDist.toFixed(2)} km</text>
            </svg>
        `;
    }

    function exportTraverseGeoJson(route, site = null) {
        if (!route || !Array.isArray(route.samples) || route.samples.length < 2) return;
        const features = [
            {
                type: 'Feature',
                properties: {
                    name: site ? `${site.name} EVA Traverse` : 'EXPLOG EVA Traverse',
                    total_dist_km: route.summary.total_dist_km,
                    walk_time_min: route.summary.total_time_min,
                    total_climb_m: route.summary.total_climb_m,
                    max_slope_deg: route.summary.max_slope_deg,
                    rover_rollover_hazard_pct: route.summary.hazard_pct
                },
                geometry: {
                    type: 'LineString',
                    coordinates: route.samples.map(s => [s.lng, s.lat, s.elev_m])
                }
            },
            ...route.waypoints.map(wp => ({
                type: 'Feature',
                properties: {
                    label: wp.label,
                    elev_m: wp.elev_m
                },
                geometry: {
                    type: 'Point',
                    coordinates: [wp.lng, wp.lat, wp.elev_m]
                }
            }))
        ];

        const geojson = {
            type: 'FeatureCollection',
            metadata: {
                generator: 'EXPLOG',
                exported_at: new Date().toISOString()
            },
            features
        };

        const blob = new Blob([JSON.stringify(geojson, null, 2)], { type: 'application/geo+json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const slug = (site?.id || 'eva_traverse').replace(/[^a-z0-9_-]/gi, '_');
        a.href = url;
        a.download = `${slug}_traverse.geojson`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2500);
    }

    // =========================================================================
    // 4. GÖREV DOSYASI (PRINTABLE HTML MISSION DOSSIER) ÜRETİCİSİ
    // =========================================================================
    function exportMissionDossier({ site, target, route, lang = 'tr' }) {
        if (!site) return;
        const isEn = lang === 'en';
        const effectiveRoute =
            route ||
            state.currentRoute ||
            computeTraverseFromPoints(
                [
                    { lat: site.lat - 0.018, lng: site.lng - 0.022 },
                    { lat: site.lat - 0.004, lng: site.lng + 0.006 },
                    { lat: site.lat + 0.015, lng: site.lng + 0.024 }
                ],
                site
            );

        let snapshotDataUrl = '';
        try {
            if (state.three.renderer && state.three.renderer.domElement) {
                state.three.renderer.render(state.three.scene, state.three.camera);
                snapshotDataUrl = state.three.renderer.domElement.toDataURL('image/png');
            } else if (state.fallback2d.canvas) {
                snapshotDataUrl = state.fallback2d.canvas.toDataURL('image/png');
            }
        } catch (_) {
            snapshotDataUrl = '';
        }

        const siteName = isEn ? (site.name_en || site.name) : site.name;
        const siteRegion = isEn ? (site.country_or_region_en || site.country_or_region) : site.country_or_region;
        const targetName = target ? (isEn ? (target.name_en || target.name) : target.name) : (isEn ? 'Planetary Reference' : 'Gezegen Referansı');
        const simScore = site.similarity_score ?? '—';
        const geoScore = site.geometric_score ?? simScore;
        const noveltyBadge = site.novelty
            ? (isEn ? (site.novelty.badge_en || site.novelty.badge) : (site.novelty.badge_tr || site.novelty.badge))
            : '';
        const cave = site.nearest_cave;

        const qualCats = site.qualification?.categories || [];
        const qualRowsHtml = qualCats.map(c => {
            const title = getCatTitle(c, isEn);
            const verdict = getCatVerdictLabel(c, isEn);
            const reqText = (c.requirements || []).map(r => {
                const rLbl = getReqLabel(r, isEn);
                const rAct = getReqActual(r);
                const stColor = r.status === 'pass' ? '#34d399' : (r.status === 'near' ? '#fbbf24' : '#f87171');
                const stIcon = r.status === 'pass' ? '✓' : (r.status === 'near' ? '≈' : '✗');
                return `<span style="margin-right:10px;"><b style="color:${stColor}">${stIcon}</b> ${esc(rLbl)}: <strong>${esc(rAct)}</strong></span>`;
            }).join(' • ');
            return `
                <tr>
                    <td>
                        <strong>${esc(title)}</strong>
                        ${c.system ? `<div style="font-size:11px;color:#94a3b8;">${esc(c.system)}</div>` : ''}
                    </td>
                    <td><span class="badge badge-${esc(c.verdict)}">${esc(verdict)}</span></td>
                    <td>${reqText}</td>
                </tr>
            `;
        }).join('');

        const legsRowsHtml = (effectiveRoute?.legs || []).map(l => `
            <tr>
                <td>${esc(l.from_label)} → ${esc(l.to_label)}</td>
                <td>${l.dist_km} km</td>
                <td>${l.time_min} ${isEn ? 'min' : 'dk'}</td>
                <td>+${l.climb_m}m / -${l.descent_m}m</td>
                <td>${l.max_slope_deg}° ${l.hazard ? '<strong style="color:#dc2626">(>15° RİSK)</strong>' : ''}</td>
            </tr>
        `).join('');

        const svgChart = buildTraverseSvg(effectiveRoute, lang);

        const html = `<!DOCTYPE html>
<html lang="${esc(lang)}">
<head>
<meta charset="UTF-8">
<title>EXPLOG Mission Dossier — ${esc(siteName)}</title>
<style>
    body { font-family: 'Segoe UI', Inter, Arial, sans-serif; background: #0b1120; color: #e2e8f0; margin: 0; padding: 28px; line-height: 1.5; }
    .dossier-wrap { max-width: 980px; margin: 0 auto; background: #111827; border: 1px solid #1e293b; border-radius: 14px; padding: 28px 32px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1e293b; padding-bottom: 16px; margin-bottom: 20px; gap: 16px; }
    .kicker { color: #38bdf8; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
    h1 { margin: 4px 0 6px; font-size: 24px; color: #f8fafc; }
    h2 { font-size: 15px; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #1e293b; padding-bottom: 6px; margin-top: 24px; }
    .meta { color: #94a3b8; font-size: 13px; }
    .score-box { text-align: right; background: #0f172a; border: 1px solid #38bdf8; border-radius: 10px; padding: 10px 16px; min-width: 170px; }
    .score-val { font-size: 26px; font-weight: 800; color: #38bdf8; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .card { background: #0f172a; border: 1px solid #1e293b; border-radius: 10px; padding: 14px 16px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
    th, td { border-bottom: 1px solid #1e293b; padding: 8px 10px; text-align: left; vertical-align: middle; }
    th { color: #94a3b8; font-weight: 600; }
    .badge { display: inline-block; padding: 3px 9px; border-radius: 99px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .badge-ready { background: rgba(16,185,129,0.2); color: #34d399; border: 1px solid rgba(16,185,129,0.4); }
    .badge-partial { background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid rgba(245,158,11,0.4); }
    .badge-not_suited { background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.4); }
    .print-bar { display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 16px; }
    .btn { background: #0284c7; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; }
    @media print { body { background: #fff; color: #111; padding: 0; } .dossier-wrap { box-shadow: none; border: none; background: #fff; color: #111; } .card, .score-box { background: #f8fafc; border-color: #cbd5e1; } .print-bar { display: none; } }
</style>
</head>
<body>
<div class="dossier-wrap">
    <div class="print-bar">
        <button class="btn" onclick="window.print()">🖨️ ${isEn ? 'Print / Save PDF' : 'Yazdır / PDF Kaydet'}</button>
    </div>
    <div class="header">
        <div>
            <div class="kicker">NASA SPACE APPS • EXPLOG MISSION DOSSIER</div>
            <h1>${esc(siteName)}</h1>
            <div class="meta">
                ${esc(siteRegion)} • (${Number(site.lat).toFixed(3)}°, ${Number(site.lng).toFixed(3)}°)
                ${noveltyBadge ? ` • <strong>${esc(noveltyBadge)}</strong>` : ''}
            </div>
            <div class="meta" style="margin-top:4px;">
                ${isEn ? 'Target Reference:' : 'Hedef Referans:'} <strong>${esc(targetName)}</strong>
                ${cave ? ` • ${isEn ? 'Nearest Analog Cave:' : 'En Yakın Analog Mağara:'} <strong>${esc(isEn ? (cave.name_en || cave.name) : (cave.name_tr || cave.name))} (${cave.distance_km} km)</strong>` : ''}
            </div>
        </div>
        <div class="score-box">
            <div class="meta">${isEn ? 'Similarity / Geometric' : 'Öklid / Geometrik Skor'}</div>
            <div class="score-val">%${esc(simScore)} <span style="font-size:15px;color:#94a3b8;">/ %${esc(geoScore)}</span></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2 style="margin-top:0">${isEn ? 'Site Characterization & Calibration' : 'Saha Karakterizasyonu & Kalibrasyon'}</h2>
            <p style="font-size:13px;color:#cbd5e1;">${esc(isEn ? (site.description_en || site.description) : site.description)}</p>
            ${site.calibration_note ? `<p style="font-size:12px;color:#7dd3fc;border-left:3px solid #38bdf8;padding-left:10px;">${esc(site.calibration_note)}</p>` : ''}
        </div>
        <div class="card">
            <h2 style="margin-top:0">${isEn ? "God's Eye 3D Low-Sun Simulation" : "God's Eye 3B Düşük Güneş Simülasyonu"}</h2>
            ${
                snapshotDataUrl
                    ? `<img src="${snapshotDataUrl}" alt="3D DEM Snapshot" style="width:100%;max-height:190px;object-fit:cover;border-radius:8px;border:1px solid #334155;" />`
                    : `<div style="font-size:13px;color:#94a3b8;">
                        <p><strong>${isEn ? 'Solar Elevation:' : 'Güneş Yüksekliği:'}</strong> ${state.sunElevDeg}° (${isEn ? 'Azimuth' : 'Azimut'} ${state.sunAzimDeg}°)</p>
                        <p><strong>${isEn ? 'Mineralogy:' : 'Mineraloji:'}</strong> ${esc(isEn ? (site.mineral_type_en || site.mineral_type) : site.mineral_type)}</p>
                       </div>`
            }
        </div>
    </div>

    <h2>${isEn ? 'TRL 5–6 Hardware Test Readiness Matrix' : 'TRL 5–6 Donanım Test Hazırlık Matrisi'}</h2>
    <table>
        <thead>
            <tr>
                <th>${isEn ? 'Hardware Subsystem' : 'Donanım Alt Sistemi'}</th>
                <th>${isEn ? 'Verdict' : 'Karar'}</th>
                <th>${isEn ? 'Envelope Criteria & Site Telemetry' : 'Zarf Kriterleri & Saha Telemetrisi'}</th>
            </tr>
        </thead>
        <tbody>${qualRowsHtml}</tbody>
    </table>

    <h2>${isEn ? "EVA Traverse Profile (Tobler's Hiking Function)" : 'EVA Yürüyüş & Rover Traverse Profili (Tobler Fonksiyonu)'}</h2>
    <div class="grid-2">
        <div class="card">
            ${svgChart}
            <div class="meta" style="margin-top:6px;">
                <strong>${isEn ? 'Total Distance:' : 'Toplam Mesafe:'}</strong> ${effectiveRoute.summary.total_dist_km} km •
                <strong>${isEn ? 'EVA Duration:' : 'EVA Süresi:'}</strong> ${formatDuration(effectiveRoute.summary.total_time_min, lang)} •
                <strong>${isEn ? 'Climb:' : 'Tırmanış:'}</strong> +${effectiveRoute.summary.total_climb_m}m •
                <strong>${isEn ? 'Max Slope:' : 'Maks Eğim:'}</strong> ${effectiveRoute.summary.max_slope_deg}°
            </div>
        </div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Segment</th>
                        <th>${isEn ? 'Dist' : 'Mesafe'}</th>
                        <th>${isEn ? 'Time' : 'Süre'}</th>
                        <th>Δh</th>
                        <th>${isEn ? 'Max Slope' : 'Maks Eğim'}</th>
                    </tr>
                </thead>
                <tbody>${legsRowsHtml}</tbody>
            </table>
        </div>
    </div>

    <h2>${isEn ? 'Scientific Citations & Portals' : 'Bilimsel Veri Kaynakları & Referanslar'}</h2>
    <div class="meta">
        • NASA POWER MERRA-2 Climatology API (https://power.larc.nasa.gov/)<br>
        • AWS Terrain Tiles / Mapzen Terrarium DEM (https://registry.opendata.aws/terrain-tiles/)<br>
        ${site.source_url ? `• ${isEn ? 'Site Literature:' : 'Saha Referansı:'} <a href="${esc(site.source_url)}" style="color:#38bdf8">${esc(site.source_url)}</a><br>` : ''}
        ${target?.source_url ? `• ${isEn ? 'Target Reference:' : 'Hedef Referansı:'} <a href="${esc(target.source_url)}" style="color:#38bdf8">${esc(target.source_url)}</a>` : ''}
    </div>
</div>
</body>
</html>`;

        const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const win = window.open(url, '_blank');
        if (!win) {
            const a = document.createElement('a');
            a.href = url;
            a.download = `${(site.id || 'mission').replace(/[^a-z0-9_-]/gi, '_')}_dossier.html`;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }
    }

    // =========================================================================
    // 5. GOD'S EYE 3B ARAZİ & DÜŞÜK GÜNEŞ (1.5°) GÖLGE SİMÜLATÖRÜ
    // =========================================================================
    function latLonToTile(lat, lon, z) {
        const latRad = (lat * Math.PI) / 180;
        const n = 2 ** z;
        const x = Math.floor(((lon + 180) / 360) * n);
        const y = Math.floor(((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n);
        return { x, y, z };
    }

    function loadImageCrossOrigin(url, timeoutMs = 4200) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            const timer = setTimeout(() => {
                reject(new Error('Image timeout'));
            }, timeoutMs);
            img.onload = () => {
                clearTimeout(timer);
                resolve(img);
            };
            img.onerror = err => {
                clearTimeout(timer);
                reject(err);
            };
            img.src = url;
        });
    }

    function buildProceduralDemBlock(site, gridSize = 96) {
        const lat0 = site?.lat ?? 38.5;
        const lng0 = site?.lng ?? 34.5;
        const baseSlope = site?.metrics?.slope_deg ?? 10.5;
        const baseElev = Math.round(600 + Math.abs(lat0) * 16);
        const data = new Float32Array(gridSize * gridSize);

        const seed = Math.abs(Math.sin(lat0 * 12.9898 + lng0 * 78.233) * 43758.5453);
        const c1x = 0.35 + (seed % 0.3);
        const c1y = 0.38 + ((seed * 1.7) % 0.28);
        const c2x = 0.68 - ((seed * 2.3) % 0.25);
        const c2y = 0.62 - ((seed * 1.3) % 0.25);

        let minE = Infinity;
        let maxE = -Infinity;

        for (let y = 0; y < gridSize; y++) {
            for (let x = 0; x < gridSize; x++) {
                const u = x / (gridSize - 1);
                const v = y / (gridSize - 1);

                const ridge =
                    Math.sin(u * Math.PI * 2.5 + seed) * Math.cos(v * Math.PI * 2.2) * (baseSlope * 18) +
                    Math.sin(u * 9.0 + v * 7.0) * (baseSlope * 5.5) +
                    Math.cos(u * 19.0 - v * 15.0) * (baseSlope * 2.2);

                const d1 = Math.hypot(u - c1x, v - c1y) / 0.22;
                let crater1 = 0;
                if (d1 < 1.0) {
                    crater1 = (d1 ** 2 * 1.35 - 0.85) * (baseSlope * 22);
                } else if (d1 < 1.35) {
                    crater1 = Math.cos(((d1 - 1.0) / 0.35) * (Math.PI / 2)) * (baseSlope * 11);
                }

                const d2 = Math.hypot(u - c2x, v - c2y) / 0.12;
                let crater2 = 0;
                if (d2 < 1.0) {
                    crater2 = (d2 ** 2 * 1.2 - 0.7) * (baseSlope * 12);
                }

                const elev = baseElev + ridge + crater1 + crater2;
                data[y * gridSize + x] = elev;
                if (elev < minE) minE = elev;
                if (elev > maxE) maxE = elev;
            }
        }

        return {
            width: gridSize,
            height: gridSize,
            data,
            minElev: minE,
            maxElev: maxE,
            spanKm: 18.0,
            source: 'Procedural Planetary DEM'
        };
    }

    async function fetchSiteDemAndImagery(site) {
        const GRID = 96;
        const z = 10;
        const tile = latLonToTile(site.lat, site.lng, z);
        const demUrl = TERRARIUM_URL.replace('{z}', z).replace('{x}', tile.x).replace('{y}', tile.y);
        const satUrl = ESRI_SAT_URL.replace('{z}', z).replace('{y}', tile.y).replace('{x}', tile.x);

        let satCanvas = null;
        try {
            const satImg = await loadImageCrossOrigin(satUrl, 3800);
            satCanvas = document.createElement('canvas');
            satCanvas.width = 256;
            satCanvas.height = 256;
            satCanvas.getContext('2d').drawImage(satImg, 0, 0, 256, 256);
        } catch (_) {
            satCanvas = null;
        }

        try {
            const demImg = await loadImageCrossOrigin(demUrl, 3800);
            const c = document.createElement('canvas');
            c.width = GRID;
            c.height = GRID;
            const ctx = c.getContext('2d');
            ctx.drawImage(demImg, 0, 0, GRID, GRID);
            const pixels = ctx.getImageData(0, 0, GRID, GRID).data;
            const data = new Float32Array(GRID * GRID);
            let minE = Infinity;
            let maxE = -Infinity;

            for (let i = 0; i < GRID * GRID; i++) {
                const r = pixels[i * 4];
                const g = pixels[i * 4 + 1];
                const b = pixels[i * 4 + 2];
                const elev = r * 256 + g + b / 256 - 32768;
                data[i] = elev;
                if (elev < minE) minE = elev;
                if (elev > maxE) maxE = elev;
            }

            if (maxE - minE < 5) {
                return { demBlock: buildProceduralDemBlock(site, GRID), satCanvas };
            }

            const spanKm = Math.max(8.0, 40.0 * Math.cos((site.lat * Math.PI) / 180));
            return {
                demBlock: {
                    width: GRID,
                    height: GRID,
                    data,
                    minElev: minE,
                    maxElev: maxE,
                    spanKm,
                    source: 'AWS Terrarium DEM z10'
                },
                satCanvas
            };
        } catch (_) {
            return { demBlock: buildProceduralDemBlock(site, GRID), satCanvas };
        }
    }

    function computeSlopeAndShadowStats(demBlock, sunElevDeg, sunAzimDeg) {
        const { width: W, height: H, data, spanKm } = demBlock;
        const cellM = (spanKm * 1000) / W;
        const slopes = new Float32Array(W * H);
        const shadows = new Uint8Array(W * H);

        let sumSlope = 0;
        let maxSlope = 0;
        let hazardCount = 0;

        for (let y = 0; y < H; y++) {
            for (let x = 0; x < W; x++) {
                const xm = Math.max(0, x - 1);
                const xp = Math.min(W - 1, x + 1);
                const ym = Math.max(0, y - 1);
                const yp = Math.min(H - 1, y + 1);

                const dzdx = (data[y * W + xp] - data[y * W + xm]) / ((xp - xm || 1) * cellM);
                const dzdy = (data[yp * W + x] - data[ym * W + x]) / ((yp - ym || 1) * cellM);
                const slopeDeg = (Math.atan(Math.hypot(dzdx, dzdy)) * 180) / Math.PI;
                slopes[y * W + x] = slopeDeg;
                sumSlope += slopeDeg;
                if (slopeDeg > maxSlope) maxSlope = slopeDeg;
                if (slopeDeg >= 15.0) hazardCount++;
            }
        }

        const azRad = ((sunAzimDeg - 90) * Math.PI) / 180;
        const stepX = Math.cos(azRad);
        const stepY = Math.sin(azRad);
        const tanSun = Math.tan((Math.max(0.3, sunElevDeg) * Math.PI) / 180);
        let shadowCount = 0;

        for (let y = 0; y < H; y++) {
            for (let x = 0; x < W; x++) {
                const z0 = data[y * W + x];
                let inShadow = 0;
                for (let s = 1; s < 36; s++) {
                    const rx = Math.round(x + stepX * s);
                    const ry = Math.round(y + stepY * s);
                    if (rx < 0 || rx >= W || ry < 0 || ry >= H) break;
                    const distM = s * cellM;
                    const rayH = z0 + distM * tanSun;
                    if (data[ry * W + rx] > rayH) {
                        inShadow = 1;
                        break;
                    }
                }
                shadows[y * W + x] = inShadow;
                if (inShadow) shadowCount++;
            }
        }

        const totalCells = W * H;
        return {
            slopes,
            shadows,
            meanSlope: Number((sumSlope / totalCells).toFixed(1)),
            maxSlope: Number(maxSlope.toFixed(1)),
            hazardPct: Math.round((hazardCount / totalCells) * 100),
            shadowPct: Math.round((shadowCount / totalCells) * 100)
        };
    }

    function createWaypointSpriteThree(THREE, labelText, isStart, isEnd) {
        const c = document.createElement('canvas');
        c.width = 96;
        c.height = 96;
        const ctx = c.getContext('2d');
        ctx.beginPath();
        ctx.arc(48, 48, 38, 0, Math.PI * 2);
        ctx.fillStyle = isStart ? '#10b981' : isEnd ? '#f97316' : '#38bdf8';
        ctx.fill();
        ctx.lineWidth = 6;
        ctx.strokeStyle = '#040812';
        ctx.stroke();
        ctx.fillStyle = '#040812';
        ctx.font = 'bold 34px Inter, Segoe UI, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(labelText, 48, 50);

        const tex = new THREE.CanvasTexture(c);
        const mat = new THREE.SpriteMaterial({ map: tex, depthTest: false, sizeAttenuation: false });
        const sp = new THREE.Sprite(mat);
        sp.scale.set(0.055, 0.055, 1);
        sp.renderOrder = 20;
        return sp;
    }

    function ensureThreeScene() {
        const wrap = document.getElementById('godsEyeCanvasWrap');
        if (!wrap) return false;

        if (!window.THREE) {
            ensureFallback2DCanvas(wrap);
            return false;
        }

        const THREE = window.THREE;

        if (!state.three.initialized) {
            const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
            renderer.setClearColor(0x050a15, 1);
            wrap.innerHTML = '';
            wrap.appendChild(renderer.domElement);

            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(40, 1, 0.05, 100);
            const targetVec = new THREE.Vector3(0, 0, 0);

            const ambient = new THREE.HemisphereLight(0xdbeafe, 0x1e293b, 0.72);
            scene.add(ambient);

            const sunLight = new THREE.DirectionalLight(0xfffbeb, 1.45);
            scene.add(sunLight);

            const gridHelper = new THREE.GridHelper(2.4, 16, 0x1e3a5f, 0x0f1f38);
            gridHelper.position.y = -0.26;
            scene.add(gridHelper);

            const routeGroup = new THREE.Group();
            scene.add(routeGroup);

            state.three = {
                initialized: true,
                renderer,
                scene,
                camera,
                terrainMesh: null,
                routeGroup,
                sunLight,
                raycaster: new THREE.Raycaster(),
                pointerDownPos: null,
                isDragging: false,
                spherical: { radius: 2.15, theta: 0.58, phi: 0.96 },
                targetVec
            };

            const dom = renderer.domElement;
            dom.addEventListener('pointerdown', e => {
                state.three.isDragging = true;
                state.three.pointerDownPos = { x: e.clientX, y: e.clientY, startX: e.clientX, startY: e.clientY };
            });

            window.addEventListener('pointermove', e => {
                if (!state.three.isDragging || !state.three.pointerDownPos) return;
                const dx = e.clientX - state.three.pointerDownPos.x;
                const dy = e.clientY - state.three.pointerDownPos.y;
                state.three.pointerDownPos.x = e.clientX;
                state.three.pointerDownPos.y = e.clientY;
                state.three.spherical.theta -= dx * 0.0075;
                state.three.spherical.phi = Math.max(0.25, Math.min(1.42, state.three.spherical.phi - dy * 0.0065));
                updateCameraFromSpherical();
            });

            window.addEventListener('pointerup', () => {
                state.three.isDragging = false;
            });

            dom.addEventListener('click', e => {
                if (state.three.pointerDownPos) {
                    const moved = Math.hypot(
                        e.clientX - (state.three.pointerDownPos.startX ?? e.clientX),
                        e.clientY - (state.three.pointerDownPos.startY ?? e.clientY)
                    );
                    if (moved > 6) return;
                }
                handleTerrainClick(e);
            });

            dom.addEventListener(
                'wheel',
                e => {
                    e.preventDefault();
                    state.three.spherical.radius = Math.max(0.95, Math.min(3.8, state.three.spherical.radius + e.deltaY * 0.0014));
                    updateCameraFromSpherical();
                },
                { passive: false }
            );

            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => resizeThreeCanvas()).observe(wrap);
            }
        }

        resizeThreeCanvas();
        return true;
    }

    function ensureFallback2DCanvas(wrap) {
        if (!state.fallback2d.canvas) {
            const c = document.createElement('canvas');
            wrap.innerHTML = '';
            wrap.appendChild(c);
            state.fallback2d.canvas = c;

            c.addEventListener('pointerdown', e => {
                state.fallback2d.isDragging = true;
                state.fallback2d.downPos = { x: e.clientX, y: e.clientY, sx: e.clientX, sy: e.clientY };
            });

            window.addEventListener('pointermove', e => {
                if (!state.fallback2d.isDragging || !state.fallback2d.downPos) return;
                const dx = e.clientX - state.fallback2d.downPos.x;
                const dy = e.clientY - state.fallback2d.downPos.y;
                state.fallback2d.downPos.x = e.clientX;
                state.fallback2d.downPos.y = e.clientY;
                state.fallback2d.yaw += dx * 0.008;
                state.fallback2d.pitch = Math.max(0.25, Math.min(1.15, state.fallback2d.pitch + dy * 0.006));
                renderFallback2DScene();
            });

            window.addEventListener('pointerup', () => {
                state.fallback2d.isDragging = false;
            });

            c.addEventListener('click', e => {
                if (state.fallback2d.downPos) {
                    const moved = Math.hypot(e.clientX - state.fallback2d.downPos.sx, e.clientY - state.fallback2d.downPos.sy);
                    if (moved > 6) return;
                }
                if (!state.fallback2d.projGrid) return;
                const rect = c.getBoundingClientRect();
                const mx = e.clientX - rect.left;
                const my = e.clientY - rect.top;
                let best = null;
                let bestD = 9999;
                for (const pt of state.fallback2d.projGrid) {
                    const d = Math.hypot(pt.sx - mx, pt.sy - my);
                    if (d < bestD) {
                        bestD = d;
                        best = pt;
                    }
                }
                if (best && bestD < 40) {
                    addWaypointAtUV(best.u, best.v);
                }
            });
        }
    }

    function renderFallback2DScene() {
        const c = state.fallback2d.canvas;
        if (!c || !state.demBlock || !state.lastTerrainStats) return;
        const wrap = c.parentElement;
        const W = Math.max(480, wrap?.clientWidth || 720);
        const H = Math.max(420, wrap?.clientHeight || 520);
        c.width = W;
        c.height = H;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#050a15';
        ctx.fillRect(0, 0, W, H);

        const dem = state.demBlock;
        const stats = state.lastTerrainStats;
        const N = dem.width;
        const step = 2;
        const yaw = state.fallback2d.yaw;
        const pitch = state.fallback2d.pitch;
        const scale = Math.min(W, H) * 0.56 * state.fallback2d.zoom;
        const elevRange = Math.max(30, dem.maxElev - dem.minElev);
        const zScale = 0.22 * state.exaggeration;

        const cosY = Math.cos(yaw);
        const sinY = Math.sin(yaw);
        const cosP = Math.cos(pitch);
        const sinP = Math.sin(pitch);

        const project = (u, v, normE) => {
            const x = u - 0.5;
            const z = v - 0.5;
            const y = (normE - 0.4) * zScale;
            const rx = x * cosY - z * sinY;
            const rz = x * sinY + z * cosY;
            const sx = W * 0.5 + rx * scale;
            const sy = H * 0.54 + (rz * sinP - y * cosP) * scale;
            return { sx, sy };
        };

        const projGrid = [];
        for (let gy = 0; gy < N - step; gy += step) {
            for (let gx = 0; gx < N - step; gx += step) {
                const i00 = gy * N + gx;
                const i10 = gy * N + (gx + step);
                const i11 = (gy + step) * N + (gx + step);
                const i01 = (gy + step) * N + gx;

                const e00 = (dem.data[i00] - dem.minElev) / elevRange;
                const e10 = (dem.data[i10] - dem.minElev) / elevRange;
                const e11 = (dem.data[i11] - dem.minElev) / elevRange;
                const e01 = (dem.data[i01] - dem.minElev) / elevRange;

                const p00 = project(gx / (N - 1), gy / (N - 1), e00);
                const p10 = project((gx + step) / (N - 1), gy / (N - 1), e10);
                const p11 = project((gx + step) / (N - 1), (gy + step) / (N - 1), e11);
                const p01 = project(gx / (N - 1), (gy + step) / (N - 1), e01);

                projGrid.push({ u: gx / (N - 1), v: gy / (N - 1), sx: p00.sx, sy: p00.sy });

                const sh = stats.shadows[i00] ? 0.32 : 1.0;
                const sl = stats.slopes[i00];
                let r = Math.round((75 + e00 * 145) * sh);
                let g = Math.round((95 + e00 * 135) * sh);
                let b = Math.round((125 + e00 * 110) * sh);
                if (state.drapeMode === 'slope' && sl >= 15) {
                    r = Math.round(239 * sh);
                    g = Math.round(68 * sh);
                    b = Math.round(68 * sh);
                } else if (state.drapeMode === 'thermal') {
                    r = Math.round((45 + e00 * 210) * sh);
                    g = Math.round((110 + (1 - Math.abs(e00 - 0.5)) * 110) * sh);
                    b = Math.round((235 - e00 * 190) * sh);
                }

                ctx.beginPath();
                ctx.moveTo(p00.sx, p00.sy);
                ctx.lineTo(p10.sx, p10.sy);
                ctx.lineTo(p11.sx, p11.sy);
                ctx.lineTo(p01.sx, p01.sy);
                ctx.closePath();
                ctx.fillStyle = `rgb(${r},${g},${b})`;
                ctx.fill();
            }
        }
        state.fallback2d.projGrid = projGrid;

        if (state.waypointsUV.length > 0) {
            const screenPts = state.waypointsUV.map(wp => {
                const hInfo = sampleDemHeightNormalized(wp.u, wp.v);
                const normE = (hInfo.elev_m - dem.minElev) / elevRange;
                return project(wp.u, wp.v, normE + 0.02);
            });

            if (screenPts.length >= 2) {
                ctx.beginPath();
                screenPts.forEach((p, idx) => (idx === 0 ? ctx.moveTo(p.sx, p.sy) : ctx.lineTo(p.sx, p.sy)));
                ctx.strokeStyle = '#38bdf8';
                ctx.lineWidth = 3;
                ctx.stroke();
            }

            screenPts.forEach((p, idx) => {
                ctx.beginPath();
                ctx.arc(p.sx, p.sy, 10, 0, Math.PI * 2);
                ctx.fillStyle = idx === 0 ? '#10b981' : idx === screenPts.length - 1 ? '#f97316' : '#38bdf8';
                ctx.fill();
                ctx.fillStyle = '#040812';
                ctx.font = 'bold 10px Inter, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(`P${idx + 1}`, p.sx, p.sy);
            });
        }
    }

    function updateCameraFromSpherical() {
        const { camera, spherical, targetVec, renderer, scene } = state.three;
        if (!camera || !renderer) return;
        const sinPhi = Math.sin(spherical.phi);
        camera.position.set(
            spherical.radius * sinPhi * Math.sin(spherical.theta),
            spherical.radius * Math.cos(spherical.phi),
            spherical.radius * sinPhi * Math.cos(spherical.theta)
        );
        camera.lookAt(targetVec);
        renderer.render(scene, camera);
    }

    function resizeThreeCanvas() {
        const wrap = document.getElementById('godsEyeCanvasWrap');
        if (!wrap || !state.three.renderer || !state.three.camera) return;
        const w = Math.max(480, wrap.clientWidth || 760);
        const h = Math.max(440, wrap.clientHeight || 560);
        state.three.renderer.setSize(w, h, false);
        state.three.camera.aspect = w / h;
        state.three.camera.updateProjectionMatrix();
        updateCameraFromSpherical();
    }

    function rebuildTerrainMesh() {
        if (!state.demBlock) return;
        const dem = state.demBlock;
        const W = dem.width;
        const H = dem.height;

        const stats = computeSlopeAndShadowStats(dem, state.sunElevDeg, state.sunAzimDeg);
        state.lastTerrainStats = stats;

        const hasThree = ensureThreeScene();
        if (!hasThree) {
            renderFallback2DScene();
            updateGodsEyeStatsUI();
            return;
        }

        const THREE = window.THREE;
        const { scene, sunLight } = state.three;

        const elevRad = (state.sunElevDeg * Math.PI) / 180;
        const azRad = ((state.sunAzimDeg - 90) * Math.PI) / 180;
        sunLight.position.set(
            Math.cos(elevRad) * Math.cos(azRad) * 4.0,
            Math.max(0.35, Math.sin(elevRad) * 4.0),
            Math.cos(elevRad) * Math.sin(azRad) * 4.0
        );

        if (state.three.terrainMesh) {
            scene.remove(state.three.terrainMesh);
            state.three.terrainMesh.geometry.dispose();
            state.three.terrainMesh.material.dispose();
        }

        const geom = new THREE.PlaneGeometry(2.0, 2.0, W - 1, H - 1);
        geom.rotateX(-Math.PI / 2);

        const posAttr = geom.attributes.position;
        const colors = new Float32Array(W * H * 3);
        const elevRange = Math.max(30, dem.maxElev - dem.minElev);
        const heightScale = 0.22 * state.exaggeration;

        let satPixels = null;
        if (state.satCanvas && state.drapeMode === 'satellite') {
            const tmp = document.createElement('canvas');
            tmp.width = W;
            tmp.height = H;
            tmp.getContext('2d').drawImage(state.satCanvas, 0, 0, W, H);
            satPixels = tmp.getContext('2d').getImageData(0, 0, W, H).data;
        }

        for (let i = 0; i < W * H; i++) {
            const normE = (dem.data[i] - dem.minElev) / elevRange;
            posAttr.setY(i, (normE - 0.45) * heightScale);

            const slope = stats.slopes[i];
            const inShadow = stats.shadows[i] === 1;
            const shadowShade = inShadow ? 0.28 : 1.0;

            let r = 0.55;
            let g = 0.58;
            let b = 0.64;

            if (state.drapeMode === 'satellite' && satPixels) {
                r = satPixels[i * 4] / 255;
                g = satPixels[i * 4 + 1] / 255;
                b = satPixels[i * 4 + 2] / 255;
            } else if (state.drapeMode === 'slope') {
                if (slope >= 15.0) {
                    r = 0.95;
                    g = 0.22;
                    b = 0.22;
                } else if (slope >= 10.0) {
                    r = 0.96;
                    g = 0.68;
                    b = 0.18;
                } else {
                    r = 0.18 + normE * 0.25;
                    g = 0.72 + normE * 0.2;
                    b = 0.5;
                }
            } else if (state.drapeMode === 'thermal') {
                r = Math.min(1, Math.max(0.12, normE * 1.15));
                g = Math.min(1, Math.max(0.18, 0.25 + (1 - Math.abs(normE - 0.5) * 1.6) * 0.65));
                b = Math.min(1, Math.max(0.18, 1.0 - normE * 0.85));
            } else {
                r = 0.38 + normE * 0.48;
                g = 0.42 + normE * 0.46;
                b = 0.48 + normE * 0.44;
            }

            colors[i * 3] = r * shadowShade;
            colors[i * 3 + 1] = g * shadowShade;
            colors[i * 3 + 2] = b * shadowShade;
        }

        geom.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        geom.computeVertexNormals();

        const mat = new THREE.MeshLambertMaterial({
            vertexColors: true,
            side: THREE.DoubleSide
        });

        const mesh = new THREE.Mesh(geom, mat);
        scene.add(mesh);
        state.three.terrainMesh = mesh;

        render3DWaypointsAndRoute();
        updateGodsEyeStatsUI();
        updateCameraFromSpherical();
    }

    function sampleDemHeightNormalized(u, v) {
        if (!state.demBlock) return { elev_m: 1000, yWorld: 0 };
        const dem = state.demBlock;
        const x = Math.max(0, Math.min(dem.width - 1, Math.round(u * (dem.width - 1))));
        const y = Math.max(0, Math.min(dem.height - 1, Math.round(v * (dem.height - 1))));
        const elev = dem.data[y * dem.width + x];
        const elevRange = Math.max(30, dem.maxElev - dem.minElev);
        const normE = (elev - dem.minElev) / elevRange;
        return {
            elev_m: elev,
            yWorld: (normE - 0.45) * (0.22 * state.exaggeration)
        };
    }

    function render3DWaypointsAndRoute() {
        if (!window.THREE) {
            renderFallback2DScene();
            return;
        }
        if (!state.three.routeGroup) return;
        const THREE = window.THREE;
        const group = state.three.routeGroup;

        while (group.children.length > 0) {
            const ch = group.children.pop();
            if (ch.geometry) ch.geometry.dispose();
            if (ch.material) ch.material.dispose();
        }

        if (state.waypointsUV.length === 0) return;

        const pts3D = [];
        state.waypointsUV.forEach((wp, idx) => {
            const hInfo = sampleDemHeightNormalized(wp.u, wp.v);
            wp.elev = hInfo.elev_m;
            const wx = (wp.u - 0.5) * 2.0;
            const wz = (wp.v - 0.5) * 2.0;
            const wy = hInfo.yWorld + 0.03;
            pts3D.push(new THREE.Vector3(wx, wy, wz));

            const sp = createWaypointSpriteThree(
                THREE,
                `P${idx + 1}`,
                idx === 0,
                idx === state.waypointsUV.length - 1
            );
            sp.position.set(wx, wy + 0.035, wz);
            group.add(sp);
        });

        if (pts3D.length >= 2) {
            const lineGeom = new THREE.BufferGeometry().setFromPoints(pts3D);
            const lineMat = new THREE.LineBasicMaterial({ color: 0x38bdf8, linewidth: 3 });
            const line = new THREE.Line(lineGeom, lineMat);
            group.add(line);
        }
    }

    function addWaypointAtUV(u, v) {
        if (state.waypointsUV.length >= 8) {
            state.waypointsUV.shift();
        }
        const spanDeg = (state.demBlock?.spanKm || 18.0) / 111.32;
        const lat = (state.activeSite?.lat || 0) + (0.5 - v) * spanDeg;
        const lng = (state.activeSite?.lng || 0) + (u - 0.5) * spanDeg;
        const hInfo = sampleDemHeightNormalized(u, v);

        state.waypointsUV.push({ u, v, lat, lng, elev: hInfo.elev_m });
        render3DWaypointsAndRoute();
        updateGodsEyeTraverseUI();
        updateCameraFromSpherical();
    }

    function handleTerrainClick(event) {
        if (!state.three.terrainMesh || !state.three.renderer) return;
        const rect = state.three.renderer.domElement.getBoundingClientRect();
        const mouse = {
            x: ((event.clientX - rect.left) / rect.width) * 2 - 1,
            y: -((event.clientY - rect.top) / rect.height) * 2 + 1
        };
        state.three.raycaster.setFromCamera(mouse, state.three.camera);
        const hits = state.three.raycaster.intersectObject(state.three.terrainMesh);
        if (!hits.length) return;

        const pt = hits[0].point;
        const u = Math.max(0, Math.min(1, pt.x / 2.0 + 0.5));
        const v = Math.max(0, Math.min(1, pt.z / 2.0 + 0.5));
        addWaypointAtUV(u, v);
    }

    function updateGodsEyeStatsUI() {
        const gridEl = document.getElementById('godsEyeStatsGrid');
        const statusBadge = document.getElementById('godsEyeStatusBadge');
        if (!gridEl || !state.demBlock || !state.lastTerrainStats) return;
        const isEn = state.lang === 'en';
        const dem = state.demBlock;
        const st = state.lastTerrainStats;

        if (statusBadge) {
            statusBadge.textContent = `${dem.source} • ${state.satCanvas ? 'Esri/Sentinel-2' : 'Procedural Texture'}`;
        }

        const items = [
            { lbl: isEn ? 'Min / Max Elev' : 'Min / Maks Rakım', val: `${Math.round(dem.minElev)}m / ${Math.round(dem.maxElev)}m` },
            { lbl: isEn ? 'Relief Span' : 'Rölyef Farkı', val: `${Math.round(dem.maxElev - dem.minElev)} m` },
            { lbl: isEn ? 'Mean / Max Slope' : 'Ort / Maks Eğim', val: `${st.meanSlope}° / ${st.maxSlope}°` },
            { lbl: isEn ? 'Rollover Hazard (>15°)' : 'Devrilme Riski (>15°)', val: `%${st.hazardPct}` },
            { lbl: isEn ? 'Shadow Coverage' : 'Gölge Kaplaması', val: `%${st.shadowPct}` },
            { lbl: isEn ? 'Sun Angle / Azimuth' : 'Güneş Açısı / Yönü', val: `${state.sunElevDeg}° / ${Math.round(state.sunAzimDeg)}°` }
        ];

        gridEl.innerHTML = items.map(it => `
            <div class="ge-stat-box">
                <span class="ge-stat-lbl">${esc(it.lbl)}</span>
                <span class="ge-stat-val mono-text">${esc(it.val)}</span>
            </div>
        `).join('');
    }

    function updateGodsEyeTraverseUI() {
        const chartEl = document.getElementById('godsEyeTraverseChart');
        const sumEl = document.getElementById('godsEyeTraverseSummary');
        const wpListEl = document.getElementById('godsEyeWaypointsList');
        if (!chartEl || !sumEl) return;

        if (state.waypointsUV.length < 2) {
            state.currentRoute = null;
            chartEl.innerHTML = buildTraverseSvg(null, state.lang);
            sumEl.innerHTML = '';
            if (wpListEl) wpListEl.innerHTML = '';
            return;
        }

        const route = computeTraverseFromPoints(state.waypointsUV, state.activeSite);
        state.currentRoute = route;
        chartEl.innerHTML = buildTraverseSvg(route, state.lang);

        const s = route.summary;
        sumEl.innerHTML = `
            <div class="d-flex flex-wrap justify-content-between gap-1 small text-light">
                <span><i class="bi bi-signpost-split text-info me-1"></i><strong>${s.total_dist_km} km</strong></span>
                <span><i class="bi bi-clock-history text-warning me-1"></i><strong>${formatDuration(s.total_time_min, state.lang)}</strong></span>
                <span><i class="bi bi-graph-up-arrow text-success me-1"></i>+${s.total_climb_m}m / -${s.total_descent_m}m</span>
                <span class="${s.has_hazard ? 'text-danger fw-bold' : 'text-secondary'}">
                    <i class="bi bi-exclamation-triangle me-1"></i>Maks ${s.max_slope_deg}° (%${s.hazard_pct} &gt;15°)
                </span>
            </div>
        `;

        if (wpListEl) {
            wpListEl.innerHTML = route.waypoints
                .map(wp => `<span class="ge-wp-chip"><b>${esc(wp.label)}</b> ${wp.elev_m}m</span>`)
                .join('');
        }
    }

    function setDemoWaypoints() {
        if (!state.activeSite) return;
        const spanDeg = (state.demBlock?.spanKm || 18.0) / 111.32;
        const demoUV = [
            { u: 0.22, v: 0.74 },
            { u: 0.44, v: 0.52 },
            { u: 0.63, v: 0.38 },
            { u: 0.81, v: 0.24 }
        ];
        state.waypointsUV = demoUV.map(pt => {
            const hInfo = sampleDemHeightNormalized(pt.u, pt.v);
            return {
                u: pt.u,
                v: pt.v,
                lat: state.activeSite.lat + (0.5 - pt.v) * spanDeg,
                lng: state.activeSite.lng + (pt.u - 0.5) * spanDeg,
                elev: hInfo.elev_m
            };
        });
        render3DWaypointsAndRoute();
        updateGodsEyeTraverseUI();
        updateCameraFromSpherical();
    }

    async function openGodsEyeModal(site, target = null, initialWaypoints = null, lang = 'tr') {
        if (!site) return;
        state.lang = lang;
        state.activeSite = site;
        state.activeTarget = target;

        const modalEl = document.getElementById('godsEyeModal');
        if (!modalEl) return;

        const isEn = lang === 'en';
        const sName = isEn ? (site.name_en || site.name) : site.name;
        const sRegion = isEn ? (site.country_or_region_en || site.country_or_region) : site.country_or_region;
        const titleEl = document.getElementById('godsEyeModalLabel') || document.getElementById('godsEyeTitle');
        const coordEl = document.getElementById('godsEyeCoordBadge') || document.getElementById('godsEyeSubtitle');
        if (titleEl) {
            titleEl.textContent = `${sName} — ${isEn ? '3D Terrain, Lunar Shadow & EVA Planner' : '3B Arazi, Ay Gölge Simülatörü & EVA Rota Planlayıcı'}`;
        }
        if (coordEl) {
            coordEl.textContent = `${sRegion} • (${Number(site.lat).toFixed(3)}°, ${Number(site.lng).toFixed(3)}°)`;
        }

        state.demBlock = buildProceduralDemBlock(site, 96);
        setDemoWaypoints();

        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

        setTimeout(() => {
            resizeThreeCanvas();
            rebuildTerrainMesh();
            updateGodsEyeTraverseUI();
        }, 80);

        const loaded = await fetchSiteDemAndImagery(site);
        state.demBlock = loaded.demBlock;
        state.satCanvas = loaded.satCanvas;

        if (Array.isArray(initialWaypoints) && initialWaypoints.length >= 2) {
            const lats = initialWaypoints.map(p => p.lat);
            const lngs = initialWaypoints.map(p => p.lng);
            const minLat = Math.min(...lats);
            const maxLat = Math.max(...lats);
            const minLng = Math.min(...lngs);
            const maxLng = Math.max(...lngs);
            const dLat = Math.max(0.01, maxLat - minLat);
            const dLng = Math.max(0.01, maxLng - minLng);

            state.waypointsUV = initialWaypoints.slice(0, 8).map(p => {
                const u = 0.15 + ((p.lng - minLng) / dLng) * 0.7;
                const v = 0.85 - ((p.lat - minLat) / dLat) * 0.7;
                return { u, v, lat: p.lat, lng: p.lng };
            });
        } else {
            setDemoWaypoints();
        }

        resizeThreeCanvas();
        rebuildTerrainMesh();
        updateGodsEyeTraverseUI();
    }

    function applySunPreset(elev, azim, activeBtnId) {
        state.sunElevDeg = elev;
        state.sunAzimDeg = azim;
        const elevRange = document.getElementById('geSunElevSlider') || document.getElementById('godsEyeSunElev');
        const azimRange = document.getElementById('geSunAzimSlider') || document.getElementById('godsEyeSunAzim');
        const elevVal = document.getElementById('geSunElevVal') || document.getElementById('godsEyeSunElevVal');
        if (elevRange) elevRange.value = String(elev);
        if (azimRange) azimRange.value = String(azim);
        if (elevVal) elevVal.textContent = `${elev.toFixed(1)}°`;
        ['btnPresetLunarLight', 'btnPresetMarsLight', 'btnPresetNoonLight'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.classList.toggle('active', id === activeBtnId);
        });
        rebuildTerrainMesh();
    }

    function bindGodsEyeControls() {
        const elevRange = document.getElementById('geSunElevSlider') || document.getElementById('godsEyeSunElev');
        const azimRange = document.getElementById('geSunAzimSlider') || document.getElementById('godsEyeSunAzim');
        const exagRange = document.getElementById('geExagSlider') || document.getElementById('godsEyeExag');
        const elevVal = document.getElementById('geSunElevVal') || document.getElementById('godsEyeSunElevVal');
        const exagVal = document.getElementById('geExagVal') || document.getElementById('godsEyeExagVal');

        if (elevRange) {
            elevRange.addEventListener('input', () => {
                state.sunElevDeg = parseFloat(elevRange.value);
                if (elevVal) elevVal.textContent = `${state.sunElevDeg.toFixed(1)}°`;
                rebuildTerrainMesh();
            });
        }

        if (azimRange) {
            azimRange.addEventListener('input', () => {
                state.sunAzimDeg = parseFloat(azimRange.value);
                rebuildTerrainMesh();
            });
        }

        if (exagRange) {
            exagRange.addEventListener('input', () => {
                state.exaggeration = parseFloat(exagRange.value);
                if (exagVal) exagVal.textContent = `${state.exaggeration.toFixed(1)}×`;
                rebuildTerrainMesh();
            });
        }

        const modalEl = document.getElementById('godsEyeModal');
        if (modalEl) {
            modalEl.querySelectorAll('[data-drape]').forEach(btn => {
                btn.addEventListener('click', () => {
                    modalEl.querySelectorAll('[data-drape]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    state.drapeMode = btn.getAttribute('data-drape');
                    rebuildTerrainMesh();
                });
            });
        }

        const btnLunar = document.getElementById('btnPresetLunarLight');
        const btnMars = document.getElementById('btnPresetMarsLight');
        const btnNoon = document.getElementById('btnPresetNoonLight');
        if (btnLunar) btnLunar.addEventListener('click', () => applySunPreset(1.5, 135, 'btnPresetLunarLight'));
        if (btnMars) btnMars.addEventListener('click', () => applySunPreset(25.0, 210, 'btnPresetMarsLight'));
        if (btnNoon) btnNoon.addEventListener('click', () => applySunPreset(65.0, 180, 'btnPresetNoonLight'));

        const btnDemo = document.getElementById('btnGeDemoRoute') || document.getElementById('btnGodsEyeDemoRoute');
        if (btnDemo) {
            btnDemo.addEventListener('click', () => setDemoWaypoints());
        }

        const btnClear = document.getElementById('btnGeClearRoute') || document.getElementById('btnGodsEyeClearRoute');
        if (btnClear) {
            btnClear.addEventListener('click', () => {
                state.waypointsUV = [];
                render3DWaypointsAndRoute();
                updateGodsEyeTraverseUI();
                updateCameraFromSpherical();
            });
        }

        const btnGeoJson = document.getElementById('btnGodsEyeExportGeoJson');
        if (btnGeoJson) {
            btnGeoJson.addEventListener('click', () => {
                if (state.currentRoute) {
                    exportTraverseGeoJson(state.currentRoute, state.activeSite);
                }
            });
        }

        const btnDossier = document.getElementById('btnGodsEyeExportDossier');
        if (btnDossier) {
            btnDossier.addEventListener('click', () => {
                exportMissionDossier({
                    site: state.activeSite,
                    target: state.activeTarget,
                    route: state.currentRoute,
                    lang: state.lang
                });
            });
        }

        if (modalEl) {
            modalEl.addEventListener('shown.bs.modal', () => {
                resizeThreeCanvas();
                rebuildTerrainMesh();
            });
        }

        window.addEventListener('resize', () => {
            if (modalEl && modalEl.classList.contains('show')) {
                resizeThreeCanvas();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', bindGodsEyeControls);

    window.AstroTerrain = {
        renderNoveltyBadge,
        renderContextStrip,
        renderReadinessHtml,
        computeTraverseFromPoints,
        formatDuration,
        buildTraverseSvg,
        exportTraverseGeoJson,
        exportMissionDossier,
        openGodsEyeModal
    };
})();
