<?php
header('Content-Type: application/json; charset=utf-8');

$T0 = microtime(true);
function ms($t0) { return (int)round((microtime(true) - $t0) * 1000); }
function out($ok, $label, $t0, $extra = []) {
  return array_merge([
    "ok" => $ok,
    "step" => $label,
    "ms" => ms($t0),
    "ts" => date('c'),
  ], $extra);
}

// 1) Confirma caminho e config carregado
$results = [];
$results[] = out(true, "start", $T0, [
  "file" => __FILE__,
]);

// 2) Carrega config oficial
$cfgPath = __DIR__ . "/config.php";
if (!file_exists($cfgPath)) {
  http_response_code(500);
  echo json_encode([
    "error" => "config.php não encontrado em /api",
    "expected" => $cfgPath
  ], JSON_PRETTY_PRINT);
  exit;
}

require_once $cfgPath;
$results[] = out(true, "after_require_config", $T0, [
  "config_path" => $cfgPath,
  "MYSQLHOST" => getenv("MYSQLHOST") ?: null,
  "MYSQLPORT" => getenv("MYSQLPORT") ?: null,
  "MYSQLDATABASE" => getenv("MYSQLDATABASE") ?: null,
  "MYSQLUSER" => getenv("MYSQLUSER") ?: null,
]);

// 3) Teste de conexão DB
$dbT = microtime(true);
$pdo = null;

if (function_exists("getDatabaseConnection")) {
  $pdo = getDatabaseConnection();
} else {
  $results[] = out(false, "getDatabaseConnection_missing", $T0);
}

if (!$pdo) {
  $results[] = out(false, "db_connect_failed", $T0, [
    "db_connect_ms" => ms($dbT),
    "hint" => "Se db_connect_ms for alto, é rede/host; se for baixo, é credencial/driver."
  ]);
  http_response_code(500);
  echo json_encode([
    "results" => $results
  ], JSON_PRETTY_PRINT);
  exit;
}

$results[] = out(true, "db_connected", $T0, [
  "db_connect_ms" => ms($dbT)
]);

// 4) Ping simples no banco
$q1T = microtime(true);
try {
  $row = $pdo->query("SELECT 1 AS ok")->fetch();
  $results[] = out(true, "db_select_1", $T0, [
    "query_ms" => ms($q1T),
    "row" => $row
  ]);
} catch (Exception $e) {
  $results[] = out(false, "db_select_1_failed", $T0, [
    "query_ms" => ms($q1T),
    "err" => $e->getMessage()
  ]);
}

// 5) Teste rápido: players por wallet (se a tabela existir)
$q2T = microtime(true);
try {
  // wallet de teste (pode trocar por uma real)
  $wallet = isset($_GET["wallet"]) ? strtolower(trim($_GET["wallet"])) : null;

  if ($wallet) {
    $st = $pdo->prepare("SELECT id, wallet_address, total_played FROM players WHERE wallet_address = ? LIMIT 1");
    $st->execute([$wallet]);
    $player = $st->fetch();
    $results[] = out(true, "db_players_lookup", $T0, [
      "query_ms" => ms($q2T),
      "wallet" => $wallet,
      "found" => $player ? true : false
    ]);
  } else {
    $results[] = out(true, "db_players_lookup_skipped", $T0, [
      "note" => "Passe ?wallet=0x... para medir lookup real"
    ]);
  }
} catch (Exception $e) {
  $results[] = out(false, "db_players_lookup_failed", $T0, [
    "query_ms" => ms($q2T),
    "err" => $e->getMessage()
  ]);
}

// 6) Teste de HTTP externo com timeout (pra provar se seu egress/rpc tá lento)
// Use ?url=https://example.com
$httpT = microtime(true);
$url = isset($_GET["url"]) ? trim($_GET["url"]) : null;

if ($url) {
  try {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 3,
      CURLOPT_TIMEOUT => 6,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HEADER => false,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) throw new Exception($err ?: "curl failed");

    $results[] = out(true, "http_get", $T0, [
      "http_ms" => ms($httpT),
      "url" => $url,
      "status" => $code,
      "bytes" => strlen($body),
    ]);
  } catch (Exception $e) {
    $results[] = out(false, "http_get_failed", $T0, [
      "http_ms" => ms($httpT),
      "url" => $url,
      "err" => $e->getMessage()
    ]);
  }
} else {
  $results[] = out(true, "http_get_skipped", $T0, [
    "note" => "Passe ?url=https://... para medir chamada externa"
  ]);
}

echo json_encode([
  "results" => $results,
  "total_ms" => ms($T0)
], JSON_PRETTY_PRINT);
