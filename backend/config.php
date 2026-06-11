<?php
/**
 * =========================================================
 * FIFA World Cup 2026 Tracker — Configuración Central
 * =========================================================
 *
 * Para personalizar sin alterar este archivo, crea
 * backend/config.local.php (excluido por .gitignore).
 *
 * API Key gratuita en: https://www.football-data.org/client/register
 */

// ── API ──────────────────────────────────────────────────
// football-data.org capa gratuita: 10 requests/minuto
define('FOOTBALL_API_KEY',  getenv('FOOTBALL_API_KEY') ?: '');
define('FOOTBALL_API_BASE', 'https://api.football-data.org/v4');
define('WORLD_CUP_CODE',    'WC');      // Código FIFA World Cup

// ── Base de datos ────────────────────────────────────────
define('DB_PATH', __DIR__ . '/../data/worldcup2026.db');

// ── Caché ────────────────────────────────────────────────
// Segundos entre llamadas a la API (evita rate limit)
define('CACHE_TTL',      300);   // 5 minutos en modo normal
define('CACHE_TTL_LIVE', 60);    // 1 minuto cuando hay partidos en vivo

// ── Aplicación ───────────────────────────────────────────
$_version_file = __DIR__ . '/../VERSION';
define('APP_VERSION', file_exists($_version_file) ? trim(file_get_contents($_version_file)) : 'V1.0.0');
define('APP_NAME',    'FIFA World Cup 2026 Tracker');

// ── Zona horaria por defecto ─────────────────────────────
// El frontend puede sobreescribir esto vía localStorage
define('DEFAULT_TIMEZONE', 'UTC');

// ── Modo Demo ────────────────────────────────────────────
// Se activa automáticamente cuando no hay API Key configurada.
// Muestra datos de ejemplo para que la UI se vea funcional.
define('DEMO_MODE', empty(FOOTBALL_API_KEY));

// ── Overrides locales ─────────────────────────────────────
// Crea este archivo para poner tu API Key sin tocar el repo:
//   <?php define('FOOTBALL_API_KEY', 'tu_key_aqui');
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
