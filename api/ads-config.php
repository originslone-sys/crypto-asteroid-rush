<?php
// ============================================
// UNOBIX - Ads Config (Public Endpoint)
// api/ads-config.php
// Redireciona para admin/admin-ads.php
// ============================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Tentar chamar o endpoint correto
$adminAdsPath = __DIR__ . '/../admin/admin-ads.php';

if (file_exists($adminAdsPath)) {
    // Definir ação pública
    $_GET['action'] = 'get_public_config';
    include $adminAdsPath;
    exit;
}

// Fallback se admin-ads.php não existir
// Retornar configuração padrão (ads desabilitados)
echo json_encode([
    'success' => true,
    'config' => [
        'ads_enabled' => false,
        'pregame_enabled' => false,
        'endgame_enabled' => false,
        'interstitial_enabled' => false,
        'banner_enabled' => false
    ],
    'slots' => [
        'pregame' => [],
        'endgame' => [],
        'interstitial' => [],
        'banner' => []
    ]
]);
