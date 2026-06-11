<?php
/**
 * =========================================================
 * FIFA World Cup 2026 Tracker — Configuración Central
 * =========================================================
 *
 * No requiere ningún registro ni API Key.
 * Los datos se obtienen de la API pública de ESPN.
 *
 * Para overrides locales, crear backend/config.local.php
 * (excluido de git por .gitignore).
 */

// ── Base de datos ────────────────────────────────────────
define('DB_PATH', __DIR__ . '/../data/worldcup2026.db');

// ── Caché ────────────────────────────────────────────────
// Segundos mínimos entre llamadas a la API de ESPN.
// Tiempo mayor cuando no hay partidos en vivo.
define('CACHE_TTL',      300);   // 5 minutos en estado normal
define('CACHE_TTL_LIVE',  60);   // 1 minuto cuando hay partido en juego

// ── Aplicación ───────────────────────────────────────────
$_v = __DIR__ . '/../VERSION';
define('APP_VERSION', file_exists($_v) ? trim(file_get_contents($_v)) : 'V1.0.0');
define('APP_NAME',    'FIFA World Cup 2026 Tracker');

// ── Modo Demo ────────────────────────────────────────────
// Se activa automáticamente si ESPN no devuelve datos en la
// primera carga (sin internet, torneo aún no disponible, etc.).
// El usuario lo verá como un aviso en la UI.
define('DEMO_MODE', false);

// ── Zona horaria por defecto ─────────────────────────────
// El frontend puede sobreescribir esto vía localStorage.
// date_default_timezone_set evita el warning de PHP 5.x cuando php.ini
// no tiene date.timezone configurado (comun en instalaciones locales).
define('DEFAULT_TIMEZONE', 'UTC');
date_default_timezone_set('UTC');

// ── Overrides locales ─────────────────────────────────────
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
