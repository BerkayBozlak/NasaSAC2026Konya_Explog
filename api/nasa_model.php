<?php
declare(strict_types=1);

@set_time_limit(180);
header('Access-Control-Allow-Origin: *');

const ALLOWED_MODELS = [
    '2372' => 'Mars',
    '2393' => 'Earth',
    '2366' => 'Moon',
];

$id = trim((string)($_GET['id'] ?? ''));
if (!isset(ALLOWED_MODELS[$id])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz NASA model ID.']);
    exit;
}

$cacheDir = __DIR__ . '/../data/nasa_cache/models';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/' . $id . '.glb';

if (!is_file($cacheFile) || filesize($cacheFile) < 10240) {
    $embedUrl = "https://solarsystem.nasa.gov/gltf_embed/{$id}/";
    $ch = curl_init($embedUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'EXPLOG-SpaceApps2026/1.0',
    ]);
    $html = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($html) || $html === '') {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'NASA model sayfası alınamadı.']);
        exit;
    }

    if (!preg_match('/https:\/\/solarsystem\.nasa\.gov\/rails\/active_storage\/blobs\/[^\'"\s]+\.glb[^\'"\s]*/i', $html, $matches)) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'GLB model bağlantısı bulunamadı.']);
        exit;
    }

    $glbUrl = html_entity_decode($matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $chGlb = curl_init($glbUrl);
    curl_setopt_array($chGlb, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => 'EXPLOG-SpaceApps2026/1.0',
    ]);
    $glbData = curl_exec($chGlb);
    $glbCode = (int)curl_getinfo($chGlb, CURLINFO_HTTP_CODE);
    curl_close($chGlb);

    if ($glbCode !== 200 || !is_string($glbData) || strlen($glbData) < 10240) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'NASA GLB dosyası indirilemedi.']);
        exit;
    }

    @file_put_contents($cacheFile, $glbData);
}

header('Content-Type: model/gltf-binary');
header('Content-Length: ' . (string)filesize($cacheFile));
header('Cache-Control: public, max-age=604800');
readfile($cacheFile);
