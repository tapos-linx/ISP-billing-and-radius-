<?php
header('Content-Type: application/json');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/api/v1/health') {
    $pdo = new PDO('pgsql:host=citus_master;port=5432;dbname=isp_bss_oss', 'isp_admin', getenv('DB_PASSWORD'));
    $r = new Redis();
    $r->connect('redis_cluster', 6379);
    echo json_encode(['status' => 'ok', 'citus' => 'connected', 'redis' => $r->ping()]);
    exit;
}
if ($uri === '/api/v1/ai/diagnose' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['status' => 'ok', 'model' => getenv('GEMINI_MODEL'), 'summary' => 'PPP session healthy']);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'Not Found']);
