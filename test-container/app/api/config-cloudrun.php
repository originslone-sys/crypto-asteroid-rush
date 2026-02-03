<?php
// ============================================
// UNOBIX - Configuração Cloud Run
// Arquivo: api/config-cloudrun.php
// SIMPLESMENTE USA O CONFIG.PHP COMPLETO
// ============================================

// Usar o config.php completo que tem todas as funcionalidades
require_once __DIR__ . '/config.php';

// Log para indicar que estamos usando config.php via config-cloudrun.php
error_log('🚀 config-cloudrun.php carregado (usando config.php completo)');