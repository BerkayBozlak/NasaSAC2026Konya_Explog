/**
 * globes.js
 * Sol (Dünya) ve Sağ (Ay / Mars) 3D Globe.gl kürelerinin yönetimi,
 * anında yüklenen prosedürel gezegen dokuları + opsiyonel CDN HD yükseltmesi,
 * kamera uçuşu (flyTo) ve pin etkileşimleri.
 */

window.SpaceGlobes = (() => {
    const TEXTURES = {
        Earth: {
            map: 'https://cdn.jsdelivr.net/npm/three-globe@2.31.1/example/img/earth-blue-marble.jpg',
            bump: 'https://cdn.jsdelivr.net/npm/three-globe@2.31.1/example/img/earth-topology.png',
            sky: 'https://cdn.jsdelivr.net/npm/three-globe@2.31.1/example/img/night-sky.png',
            atmosphereColor: '#38bdf8',
            atmosphereAltitude: 0.16
        },
        Moon: {
            map: 'https://raw.githubusercontent.com/mrdoob/three.js/dev/examples/textures/planets/moon_1024.jpg',
            bump: 'https://raw.githubusercontent.com/mrdoob/three.js/dev/examples/textures/planets/moon_1024.jpg',
            sky: 'https://cdn.jsdelivr.net/npm/three-globe@2.31.1/example/img/night-sky.png',
            atmosphereColor: '#94a3b8',
            atmosphereAltitude: 0.0
        },
        Mars: {
            map: 'assets/img/mars_2k.jpg',
            bump: 'assets/img/mars_2k.jpg',
            sky: 'https://cdn.jsdelivr.net/npm/three-globe@2.31.1/example/img/night-sky.png',
            atmosphereColor: '#fb923c',
            atmosphereAltitude: 0.10
        }
    };

    let earthGlobe = null;
    let spaceGlobe = null;
    let earthContainer = null;
    let spaceContainer = null;

    let selectedEarthId = null;
    let selectedSpaceId = null;
    let currentLang = 'tr';
    let lastEarthPoints = [];
    let lastSpacePoints = [];

    let continentFeatures = [];
    let continentsVisible = true;
    let hoveredContinent = null;
    let selectedContinent = null;

    const CONTINENT_META = {
        Europe:          { rgb: '59, 130, 246',  tr: 'Avrupa',        en: 'Europe',        lat: 50,  lng: 15,   alt: 1.45 },
        Asia:            { rgb: '239, 68, 68',   tr: 'Asya',          en: 'Asia',          lat: 38,  lng: 90,   alt: 1.85 },
        Africa:          { rgb: '245, 158, 11',  tr: 'Afrika',        en: 'Africa',        lat: 5,   lng: 20,   alt: 1.75 },
        'North America': { rgb: '234, 179, 8',   tr: 'Kuzey Amerika', en: 'North America', lat: 45,  lng: -100, alt: 1.85 },
        'South America': { rgb: '236, 72, 153',  tr: 'Güney Amerika', en: 'South America', lat: -18, lng: -60,  alt: 1.75 },
        Oceania:         { rgb: '16, 185, 129',  tr: 'Okyanusya',     en: 'Oceania',       lat: -24, lng: 135,  alt: 1.65 },
        Antarctica:      { rgb: '148, 163, 184', tr: 'Antarktika',    en: 'Antarctica',    lat: -80, lng: 0,    alt: 1.85 }
    };

    function getLoc(d, field) {
        if (!d) return '';
        if (currentLang === 'en' && d[`${field}_en`]) return d[`${field}_en`];
        return d[field] || '';
    }

    const proceduralCache = {};

    /**
     * CDN dokuları yavaşsa veya CORS/ağ nedeniyle yüklenemezse bile kürelerin
     * Google Earth kalitesinde anında görünmesini sağlayan 1024x512 prosedürel doku üreticisi.
     */
    function createProceduralTexture(bodyKey) {
        if (proceduralCache[bodyKey]) return proceduralCache[bodyKey];

        const w = 1024;
        const h = 512;
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');

        // Yardımcı: Enlem/Boylam -> Canvas X/Y
        const toX = lng => ((lng + 180) / 360) * w;
        const toY = lat => ((90 - lat) / 180) * h;

        if (bodyKey === 'Earth') {
            // Derin okyanus gradyanı
            const grad = ctx.createLinearGradient(0, 0, 0, h);
            grad.addColorStop(0, '#0c2748');
            grad.addColorStop(0.5, '#0b3564');
            grad.addColorStop(1, '#0a2342');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, w, h);

            // Basitleştirilmiş gerçekçi kıta poligonları (Enlem/Boylam)
            const continents = [
                // Kuzey Amerika
                [[-168,65],[-140,70],[-95,72],[-55,52],[-75,35],[-82,25],[-105,20],[-117,32],[-125,48],[-150,60]],
                // Güney Amerika
                [[-80,10],[-60,10],[-35,-6],[-40,-22],[-58,-38],[-68,-54],[-75,-45],[-81,-5]],
                // Avrupa
                [[-10,36],[0,43],[-5,50],[10,56],[28,70],[55,68],[60,50],[40,42],[25,36],[12,38]],
                // Afrika
                [[-17,15],[-5,35],[12,34],[32,31],[43,12],[51,11],[40,-10],[32,-26],[20,-35],[12,-18],[8,4],[-8,5]],
                // Asya
                [[30,42],[60,68],[105,75],[170,66],[160,55],[135,45],[122,30],[105,12],[98,18],[78,8],[65,24],[45,28]],
                // Avustralya
                [[114,-22],[130,-12],[145,-15],[153,-28],[145,-38],[130,-33],[115,-34]],
                // Grönland
                [[-52,60],[-20,70],[-25,82],[-60,80]],
                // Antarktika
                [[-180,-72],[-90,-70],[0,-71],[90,-69],[180,-72],[180,-90],[-180,-90]]
            ];

            continents.forEach((poly, idx) => {
                ctx.beginPath();
                poly.forEach(([lng, lat], i) => {
                    const x = toX(lng);
                    const y = toY(lat);
                    if (i === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                });
                ctx.closePath();
                ctx.fillStyle = idx >= 6 ? '#cbd5e1' : '#1e4d3b';
                ctx.fill();
                ctx.strokeStyle = 'rgba(56, 189, 248, 0.25)';
                ctx.lineWidth = 1.2;
                ctx.stroke();
            });
        } else if (bodyKey === 'Moon') {
            ctx.fillStyle = '#6b7280';
            ctx.fillRect(0, 0, w, h);

            // Ay denizleri (Maria)
            const maria = [
                [0.42, 0.38, 95, 65], [0.55, 0.42, 70, 50],
                [0.32, 0.46, 85, 75], [0.62, 0.34, 55, 40],
                [0.48, 0.56, 60, 45]
            ];
            maria.forEach(([cx, cy, rx, ry]) => {
                ctx.beginPath();
                ctx.ellipse(cx * w, cy * h, rx, ry, 0.2, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(55, 65, 81, 0.65)';
                ctx.fill();
            });

            // Kraterler
            for (let i = 0; i < 160; i++) {
                const x = (Math.sin(i * 78.233) * 0.5 + 0.5) * w;
                const y = (Math.cos(i * 45.164) * 0.5 + 0.5) * h;
                const r = 3 + (i % 14);
                ctx.beginPath();
                ctx.arc(x, y, r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(31, 41, 55, 0.35)';
                ctx.fill();
                ctx.strokeStyle = 'rgba(229, 231, 235, 0.28)';
                ctx.lineWidth = 1;
                ctx.stroke();
            }
        } else {
            // Mars yüzeyi
            const grad = ctx.createLinearGradient(0, 0, 0, h);
            grad.addColorStop(0, '#c2410c');
            grad.addColorStop(0.5, '#9a3412');
            grad.addColorStop(1, '#7c2d12');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, w, h);

            // Koyu bazaltik bölgeler (Syrtis Major vb.)
            for (let i = 0; i < 24; i++) {
                const x = (Math.sin(i * 19.31) * 0.5 + 0.5) * w;
                const y = (0.2 + (Math.cos(i * 11.7) * 0.5 + 0.5) * 0.6) * h;
                ctx.beginPath();
                ctx.ellipse(x, y, 40 + (i % 50), 22 + (i % 25), i * 0.4, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(67, 20, 7, 0.35)';
                ctx.fill();
            }

            // Kutup buzulları
            ctx.fillStyle = 'rgba(241, 245, 249, 0.78)';
            ctx.fillRect(0, 0, w, 16);
            ctx.fillRect(0, h - 14, w, 14);
        }

        // Google Earth tarzı ince enlem-boylam koordinat ağı (graticule)
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.lineWidth = 1;
        for (let lng = -150; lng <= 150; lng += 30) {
            const x = toX(lng);
            ctx.beginPath();
            ctx.moveTo(x, 0);
            ctx.lineTo(x, h);
            ctx.stroke();
        }
        for (let lat = -60; lat <= 60; lat += 30) {
            const y = toY(lat);
            ctx.beginPath();
            ctx.moveTo(0, y);
            ctx.lineTo(w, y);
            ctx.stroke();
        }

        const dataUrl = canvas.toDataURL('image/jpeg', 0.88);
        proceduralCache[bodyKey] = dataUrl;
        return dataUrl;
    }

    /**
     * Önce prosedürel dokuyu anında yükler (kesin görünürlük), ardından arka planda
     * gerçek HD uydu dokusunu CORS güvenli şekilde deneyerek yükseltir.
     */
    function applyTextureWithFallback(globeInstance, bodyKey) {
        const cfg = TEXTURES[bodyKey];
        if (!cfg || !globeInstance) return;

        // 1. Anında görünür prosedürel doku
        const fallbackUrl = createProceduralTexture(bodyKey);
        globeInstance
            .globeImageUrl(fallbackUrl)
            .bumpImageUrl(null)
            .showAtmosphere(cfg.atmosphereAltitude > 0)
            .atmosphereColor(cfg.atmosphereColor)
            .atmosphereAltitude(cfg.atmosphereAltitude);

        const mat = globeInstance.globeMaterial();
        if (mat && mat.color) {
            mat.color.set('#ffffff');
            if (mat.emissive) {
                mat.emissive.set('#111827');
                mat.emissiveIntensity = 0.28;
            }
        }

        // 2. Arka planda gerçek HD uydu dokusunu yüklemeyi dene
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
            globeInstance.globeImageUrl(cfg.map).bumpImageUrl(cfg.bump);
        };
        img.onerror = () => {
            console.info(`[SpaceGlobes] ${bodyKey} için prosedürel doku kullanılıyor.`);
        };
        img.src = cfg.map;

        if (cfg.sky) {
            const skyImg = new Image();
            skyImg.crossOrigin = 'anonymous';
            skyImg.onload = () => globeInstance.backgroundImageUrl(cfg.sky);
            skyImg.src = cfg.sky;
        }
    }

    function getScoreColor(score) {
        if (score === undefined || score === null) return '#38bdf8';
        if (score >= 80) return '#10b981';
        if (score >= 65) return '#f59e0b';
        return '#64748b';
    }

    function buildTooltipHtml(d, isEarth) {
        const simLabel = currentLang === 'en' ? 'Similarity' : 'Benzerlik';
        const scoreHtml = (isEarth && d.similarity_score !== undefined)
            ? `<div style="margin-top:4px;font-family:'JetBrains Mono',monospace;font-size:11px;color:${getScoreColor(d.similarity_score)};font-weight:700;">${simLabel}: %${d.similarity_score}</div>`
            : '';

        const nasaHtml = (isEarth && d.is_nasa_power && d.metrics)
            ? `<div style="font-size:10px;color:#94a3b8;margin-top:3px;">${currentLang === 'en' ? 'Temp' : 'Sıc'}: ${d.metrics.T2M}°C • ${currentLang === 'en' ? 'Hum' : 'Nem'}: %${d.metrics.RH2M} • Rad: ${d.metrics.ALLSKY_SFC_SW_DWN} kWh/m²</div>`
            : '';

        return `
            <div style="min-width:170px;">
                <div style="font-weight:600;font-size:12px;color:#f8fafc;">${getLoc(d, 'name')}</div>
                <div style="font-size:11px;color:#94a3b8;">${getLoc(d, 'country_or_region')}</div>
                <div style="font-size:10px;color:#cbd5e1;margin-top:3px;">${getLoc(d, 'mineral_type')}</div>
                ${nasaHtml}
                ${scoreHtml}
            </div>
        `;
    }

    function createGlobeHtmlLabel(d, isActive, onClick) {
        const el = document.createElement('div');
        el.className = `globe-html-label ${isActive ? 'active' : ''}`;
        if (d.rank !== undefined && d.similarity_score !== undefined) {
            el.textContent = `#${d.rank} • %${d.similarity_score}`;
        } else {
            el.textContent = getLoc(d, 'name').split('(')[0].trim();
        }
        if (typeof onClick === 'function') {
            el.addEventListener('click', ev => {
                ev.stopPropagation();
                onClick(d);
            });
        }
        return el;
    }

    function optimizeGlobeRenderer(globeInstance) {
        if (!globeInstance) return;
        const renderer = globeInstance.renderer();
        if (renderer && typeof renderer.setPixelRatio === 'function') {
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        }
        const mat = globeInstance.globeMaterial();
        if (mat) {
            mat.bumpScale = 0.04;
            if (mat.emissive) {
                mat.emissive.set('#0f172a');
                mat.emissiveIntensity = 0.14;
            }
        }
        // Kürenin karanlık tarafının simsiyah kalmaması için dengeli ortam ışığı
        const scene = globeInstance.scene();
        if (scene && typeof scene.traverse === 'function') {
            scene.traverse(obj => {
                if (obj.isAmbientLight) {
                    obj.intensity = 1.95;
                } else if (obj.isDirectionalLight) {
                    obj.intensity = 0.75;
                }
            });
        }
    }

    function init({ onEarthClick, onSpaceClick, onEarthGlobeClick, onSpaceGlobeClick, onContinentClick }) {
        earthContainer = document.getElementById('earthGlobe');
        spaceContainer = document.getElementById('spaceGlobe');

        if (typeof Globe !== 'function') {
            console.error('[SpaceGlobes] Globe.gl kütüphanesi yüklenemedi.');
            return;
        }

        const rendererOpts = { antialias: true, powerPreference: 'high-performance' };

        // 1. Sol Küre: Dünya (HD Uydu Karo Motoru + Yüksek Performans)
        earthGlobe = Globe({ rendererConfig: rendererOpts })(earthContainer)
            .width(earthContainer.clientWidth || window.innerWidth / 2)
            .height(earthContainer.clientHeight || window.innerHeight)
            .backgroundColor('#03060c')
            .pointLat('lat')
            .pointLng('lng')
            .pointAltitude(d => (d.id === selectedEarthId ? 0.028 : (d.is_dynamic ? 0.022 : 0.012)))
            .pointRadius(d => (d.id === selectedEarthId ? 0.48 : (d.is_dynamic ? 0.42 : 0.32)))
            .pointColor(d => {
                if (d.id === selectedEarthId) return '#ffffff';
                if (d.is_dynamic) return '#38bdf8';
                return getScoreColor(d.similarity_score);
            })
            .pointLabel(d => buildTooltipHtml(d, true))
            .onPointClick(d => {
                if (typeof onEarthClick === 'function') onEarthClick(d);
            })
            .onGlobeClick(coords => {
                if (typeof onEarthGlobeClick === 'function' && coords && typeof coords.lat === 'number') {
                    onEarthGlobeClick(coords);
                }
            })
            .htmlLat('lat')
            .htmlLng('lng')
            .htmlAltitude(d => (d.id === selectedEarthId ? 0.095 : 0.05))
            .htmlElement(d => createGlobeHtmlLabel(d, d.id === selectedEarthId, onEarthClick))
            .ringColor(() => t => `rgba(138, 180, 248, ${(1 - t) * 0.7})`)
            .ringMaxRadius(1.85)
            .ringPropagationSpeed(0.95)
            .ringRepeatPeriod(2600)
            .pathPoints('coords')
            .pathPointLat(p => p[0])
            .pathPointLng(p => p[1])
            .pathPointAlt(() => 0.012)
            .pathColor(() => '#8ab4f8')
            .pathStroke(1.8)
            .pathDashLength(0.02)
            .pathDashGap(0.01)
            .pathDashAnimateTime(0);

        applyTextureWithFallback(earthGlobe, 'Earth');
        optimizeGlobeRenderer(earthGlobe);
        earthGlobe.pointOfView({ lat: 28, lng: 18, altitude: 2.15 }, 0);
        initContinentPolygons(onContinentClick);

        const earthControls = earthGlobe.controls();
        earthControls.autoRotate = true;
        earthControls.autoRotateSpeed = 0.35;
        earthContainer.addEventListener('pointerdown', () => {
            earthControls.autoRotate = false;
        }, { once: true });

        // 2. Sağ Küre: Ay / Mars (Tıklanabilir Küresel Yüzey + IAU Hedefleri)
        spaceGlobe = Globe({ rendererConfig: rendererOpts })(spaceContainer)
            .width(spaceContainer.clientWidth || window.innerWidth / 2)
            .height(spaceContainer.clientHeight || window.innerHeight)
            .backgroundColor('#03060c')
            .pointLat('lat')
            .pointLng('lng')
            .pointAltitude(d => (d.id === selectedSpaceId ? 0.032 : (d.is_dynamic ? 0.024 : 0.014)))
            .pointRadius(d => (d.id === selectedSpaceId ? 0.52 : (d.is_dynamic ? 0.44 : 0.34)))
            .pointColor(d => {
                if (d.id === selectedSpaceId) return '#ffffff';
                if (d.is_dynamic) return '#facc15';
                return d.body === 'Mars' ? '#fb923c' : '#e2e8f0';
            })
            .pointLabel(d => buildTooltipHtml(d, false))
            .onPointClick(d => {
                if (typeof onSpaceClick === 'function') onSpaceClick(d);
            })
            .onGlobeClick(coords => {
                if (typeof onSpaceGlobeClick === 'function' && coords && typeof coords.lat === 'number') {
                    onSpaceGlobeClick(coords);
                }
            })
            .htmlLat('lat')
            .htmlLng('lng')
            .htmlAltitude(d => (d.id === selectedSpaceId ? 0.10 : 0.055))
            .htmlElement(d => createGlobeHtmlLabel(d, d.id === selectedSpaceId, onSpaceClick))
            .ringColor(() => t => `rgba(253, 214, 99, ${(1 - t) * 0.7})`)
            .ringMaxRadius(1.95)
            .ringPropagationSpeed(0.95)
            .ringRepeatPeriod(2600);

        applyTextureWithFallback(spaceGlobe, 'Moon');
        optimizeGlobeRenderer(spaceGlobe);
        spaceGlobe.pointOfView({ lat: -20, lng: 25, altitude: 2.15 }, 0);

        const spaceControls = spaceGlobe.controls();
        spaceControls.autoRotate = true;
        spaceControls.autoRotateSpeed = 0.35;
        spaceContainer.addEventListener('pointerdown', () => {
            spaceControls.autoRotate = false;
        }, { once: true });

        window.addEventListener('resize', handleResize);
        requestAnimationFrame(handleResize);
    }

    let prevEarthMinDim = 0;
    let prevSpaceMinDim = 0;

    function syncGlobeSizeAndZoom(globeInstance, container, prevMinDim) {
        if (!globeInstance || !container || container.clientWidth <= 0 || container.clientHeight <= 0) {
            return prevMinDim;
        }
        const w = container.clientWidth;
        const h = container.clientHeight;
        globeInstance.width(w).height(h);

        const newMinDim = Math.min(w, h);
        if (prevMinDim > 0 && Math.abs(newMinDim - prevMinDim) > 0.5) {
            const pov = globeInstance.pointOfView();
            if (pov && typeof pov.altitude === 'number') {
                const scale = prevMinDim / newMinDim;
                const nextAltitude = Math.max(0.35, Math.min(6.0, (1 + pov.altitude) * scale - 1));
                globeInstance.pointOfView({ lat: pov.lat, lng: pov.lng, altitude: nextAltitude }, 0);
            }
        }
        return newMinDim;
    }

    function handleResize() {
        prevEarthMinDim = syncGlobeSizeAndZoom(earthGlobe, earthContainer, prevEarthMinDim);
        prevSpaceMinDim = syncGlobeSizeAndZoom(spaceGlobe, spaceContainer, prevSpaceMinDim);
    }

    function setSpaceBody(bodyKey) {
        applyTextureWithFallback(spaceGlobe, bodyKey);
        if (spaceGlobe) {
            spaceGlobe.controls().autoRotate = true;
        }
    }

    function updateEarthPoints(points, activeEarthId = null) {
        selectedEarthId = activeEarthId;
        lastEarthPoints = points || [];
        if (!earthGlobe) return;

        // Tüm 3B pinler kürede görünür; HTML metin etiketleri ise kalabalığı önlemek için en iyi 12 saha + seçili saha ile sınırlandırılır
        let labelPoints = lastEarthPoints;
        if (lastEarthPoints.length > 14) {
            if (lastEarthPoints[0]?.rank !== undefined) {
                labelPoints = lastEarthPoints.filter(p => p.rank <= 6 || p.id === activeEarthId);
            } else {
                labelPoints = lastEarthPoints.filter((p, idx) => idx < 12 || p.id === activeEarthId || p.is_dynamic);
            }
        }

        earthGlobe.pointsData(lastEarthPoints).htmlElementsData(labelPoints);

        const activePoint = lastEarthPoints.find(p => p.id === activeEarthId);
        earthGlobe.ringsData(activePoint ? [activePoint] : []);
    }

    function updateSpacePoints(points, activeSpaceId = null) {
        selectedSpaceId = activeSpaceId;
        lastSpacePoints = points || [];
        if (!spaceGlobe) return;

        // Tüm Ay/Mars hedefleri 3B pin olarak görünür; HTML etiketleri ise seçili hedef + ana sahalar için gösterilir
        const labelPoints = lastSpacePoints.length > 14
            ? lastSpacePoints.filter((p, idx) => idx < 12 || p.id === activeSpaceId || p.is_dynamic)
            : lastSpacePoints;

        spaceGlobe.pointsData(lastSpacePoints).htmlElementsData(labelPoints);

        const activePoint = lastSpacePoints.find(p => p.id === activeSpaceId);
        spaceGlobe.ringsData(activePoint ? [activePoint] : []);
    }

    function getContinentColor(feat) {
        const cont = feat?.properties?.continent || '';
        const meta = CONTINENT_META[cont] || { rgb: '56, 189, 248' };
        const isSel = selectedContinent && cont === selectedContinent;
        const isHov = hoveredContinent && cont === hoveredContinent;
        const alpha = isSel ? 0.52 : (isHov ? 0.42 : 0.18);
        return `rgba(${meta.rgb}, ${alpha})`;
    }

    function refreshContinentStyles() {
        if (!earthGlobe) return;
        earthGlobe
            .polygonCapColor(getContinentColor)
            .polygonSideColor(feat => {
                const cont = feat?.properties?.continent || '';
                const isActive = (selectedContinent && cont === selectedContinent) || (hoveredContinent && cont === hoveredContinent);
                return isActive ? 'rgba(2, 6, 23, 0.85)' : 'rgba(2, 6, 23, 0.45)';
            })
            .polygonStrokeColor(feat => {
                const cont = feat?.properties?.continent || '';
                const isActive = (selectedContinent && cont === selectedContinent) || (hoveredContinent && cont === hoveredContinent);
                return isActive ? 'rgba(255, 255, 255, 0.72)' : 'rgba(255, 255, 255, 0.14)';
            })
            .polygonAltitude(feat => {
                const cont = feat?.properties?.continent || '';
                if (selectedContinent && cont === selectedContinent) return 0.032;
                if (hoveredContinent && cont === hoveredContinent) return 0.022;
                return 0.007;
            });
    }

    function initContinentPolygons(onContinentClick) {
        if (!earthGlobe) return;

        earthGlobe
            .polygonsTransitionDuration(220)
            .polygonLabel(feat => {
                if (earthContainer && earthContainer.classList.contains('crosshair-mode')) {
                    return '';
                }
                const cont = feat?.properties?.continent || '';
                const meta = CONTINENT_META[cont];
                const label = meta ? (currentLang === 'en' ? meta.en : meta.tr) : cont;
                const country = feat?.properties?.name || '';
                return `<div style="font-size:11px;font-weight:600;color:#f8fafc;">${label}</div><div style="font-size:10px;color:#94a3b8;">${country}</div>`;
            })
            .onPolygonHover(feat => {
                const cont = feat?.properties?.continent || null;
                if (cont !== hoveredContinent) {
                    hoveredContinent = cont;
                    refreshContinentStyles();
                }
            })
            .onPolygonClick((feat, _ev, coords) => {
                const cont = feat?.properties?.continent || null;
                if (!cont) return;
                selectedContinent = (selectedContinent === cont) ? null : cont;
                refreshContinentStyles();

                if (selectedContinent && CONTINENT_META[selectedContinent]) {
                    const m = CONTINENT_META[selectedContinent];
                    flyToEarth(m.lat, m.lng, m.alt);
                }

                if (typeof onContinentClick === 'function') {
                    const meta = CONTINENT_META[selectedContinent] || null;
                    onContinentClick(selectedContinent, meta, coords);
                }
            });

        refreshContinentStyles();

        fetch('data/continents.json')
            .then(r => (r.ok ? r.json() : null))
            .then(geo => {
                if (geo && Array.isArray(geo.features)) {
                    continentFeatures = geo.features;
                    if (continentsVisible && earthGlobe) {
                        earthGlobe.polygonsData(continentFeatures);
                    }
                }
            })
            .catch(err => console.warn('[SpaceGlobes] Kıta katmanı yüklenemedi:', err));
    }

    function setContinentsVisible(visible) {
        continentsVisible = Boolean(visible);
        if (!earthGlobe) return;
        if (!continentsVisible) {
            selectedContinent = null;
            hoveredContinent = null;
            earthGlobe.polygonsData([]);
        } else {
            earthGlobe.polygonsData(continentFeatures);
            refreshContinentStyles();
        }
    }

    function updateRulerPath(vertices) {
        if (!earthGlobe) return;
        if (!vertices || vertices.length < 2) {
            earthGlobe.pathsData([]);
            return;
        }
        const coords = vertices.map(v => [v.lat, v.lng]);
        if (vertices.length >= 3) {
            coords.push([vertices[0].lat, vertices[0].lng]);
        }
        earthGlobe.pathsData([{ coords }]);
    }

    function setLanguage(lang) {
        currentLang = lang === 'en' ? 'en' : 'tr';
        if (earthGlobe) {
            earthGlobe.htmlElementsData([...lastEarthPoints]).pointsData([...lastEarthPoints]);
        }
        if (spaceGlobe) {
            spaceGlobe.htmlElementsData([...lastSpacePoints]).pointsData([...lastSpacePoints]);
        }
    }

    function flyToEarth(lat, lng, altitude = 1.65) {
        if (!earthGlobe) return;
        earthGlobe.controls().autoRotate = false;
        earthGlobe.pointOfView({ lat, lng, altitude }, 850);
    }

    function flyToSpace(lat, lng, altitude = 1.75) {
        if (!spaceGlobe) return;
        spaceGlobe.controls().autoRotate = false;
        spaceGlobe.pointOfView({ lat, lng, altitude }, 850);
    }

    function setEarthCrosshair(enabled) {
        if (!earthContainer) return;
        earthContainer.classList.toggle('crosshair-mode', Boolean(enabled));
    }

    return {
        init,
        setSpaceBody,
        updateEarthPoints,
        updateSpacePoints,
        flyToEarth,
        flyToSpace,
        setEarthCrosshair,
        setContinentsVisible,
        updateRulerPath,
        setLanguage,
        resize: handleResize
    };
})();
