<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Servicio de Datos (Business Logic)
 * =========================================================
 * Compatible con PHP 5.4+ (sin type hints, sin arrow functions)
 *
 * Orquesta Fetcher + Database:
 *  - refreshData(): baja de ESPN y persiste en SQLite con TTL de cache
 *  - buildPayload(): prepara el JSON que consume el frontend SPA
 */

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/fetcher.php';

class DataService {

    // ── Actualizacion ─────────────────────────────────────

    /**
     * Descarga partidos y posiciones de ESPN y los persiste en SQLite.
     *
     * TTL de cache:
     *   - Modo REAL: 300s normal / 60s con partidos en vivo
     *   - Modo DEMO: siempre intenta ESPN (sin TTL) para auto-migrar
     *     a datos reales tan pronto como el torneo este en la API
     *
     * Devuelve true si se actualizo con datos reales, false si no.
     */
    public static function refreshData() {
        $lastUpdated = Database::getSetting('last_updated', '');
        $isDemo      = Database::getSetting('is_demo', '0');

        $ttl = CACHE_TTL;
        if (self::hasLiveMatches()) {
            $ttl = CACHE_TTL_LIVE;
        }

        // En modo demo SIEMPRE intentamos ESPN (sin respetar cache):
        // esto permite que en cuanto el torneo aparezca en ESPN
        // el sitio migre a datos reales sin intervencion del usuario.
        // En modo real, respetar el TTL normalmente.
        if ($isDemo !== '1' && $lastUpdated) {
            $age = time() - strtotime($lastUpdated);
            if ($age < $ttl) {
                return false;
            }
        }

        $matches   = Fetcher::getAllMatches();
        $standings = Fetcher::getStandings();

        // Sin datos de ESPN: marcar como demo y mantener datos sembrados
        if (empty($matches)) {
            // Aun sin ESPN, limpiar partidos que llevan demasiado tiempo "en vivo"
            Database::clearStaleLiveMatches();
            Database::setSetting('is_demo', '1');
            Database::setSetting('last_updated', date('c'));
            return false;
        }

        // ESPN devolvio datos reales.
        // upsertMatch() actualiza los partidos sembrados en-lugar
        // (no se borran; se les asigna external_id + marcador + estado).
        // Los standings si se reconstruyen desde ESPN si los devuelve.
        if (!empty($standings)) {
            $db = Database::connect();
            $db->exec("DELETE FROM standings");
        }

        Database::setSetting('is_demo', '0');

        $teamIdx = Database::getTeamNameIndex();
        foreach ($matches as $match) {
            Database::upsertMatch($match, $teamIdx);
        }

        // Refrescar indice: upsertMatch puede haber creado nuevos equipos
        $teamIdx = Database::getTeamNameIndex();
        foreach ($standings as $row) {
            $group = isset($row['group']) ? $row['group'] : '';
            Database::upsertStanding($row, $group, $teamIdx);
        }

        // Cerrar partidos que llevan >2h en vivo sin resultado (ESPN puede haberlos omitido)
        Database::clearStaleLiveMatches();
        // Recalcular standings desde resultados propios: el endpoint de standings
        // de ESPN frecuentemente no devuelve datos al inicio del torneo.
        Database::recalculateStandingsFromMatches();
        Database::setSetting('last_updated', date('c'));
        return true;
    }

    // ── Consultas para el Frontend ────────────────────────

    /**
     * Partidos agrupados por fecha UTC.
     * El frontend los re-agrupa por fecha LOCAL del usuario con Intl.DateTimeFormat.
     */
    public static function getMatchesGrouped($group) {
        $matches = Database::getMatches($group);
        $grouped = array();

        foreach ($matches as $match) {
            $raw     = isset($match['match_date']) ? $match['match_date'] : '';
            $dateKey = substr($raw, 0, 10);
            if (!$dateKey) continue;
            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = array();
            }
            $grouped[$dateKey][] = $match;
        }

        ksort($grouped);
        return $grouped;
    }

    /**
     * Partidos de hoy (UTC). Incluye siempre los partidos en vivo
     * aunque tecnicamente sean de ayer UTC (p.ej. partido a las 23:00 UTC).
     */
    public static function getTodayMatches() {
        $matches = Database::getMatches();
        $today   = gmdate('Y-m-d');

        $filtered = array_filter($matches, function($m) use ($today) {
            $raw    = isset($m['match_date']) ? $m['match_date'] : '';
            $status = isset($m['status']) ? $m['status'] : '';
            $isLive = $status === 'IN_PLAY' || $status === 'PAUSED';
            return substr($raw, 0, 10) === $today || $isLive;
        });

        return array_values($filtered);
    }

    /** Posiciones agrupadas por letra de grupo */
    public static function getGroupStandings() {
        $rows    = Database::getStandings();
        $grouped = array();

        foreach ($rows as $row) {
            $g = isset($row['group_name']) ? $row['group_name'] : '';
            if (!$g) continue;
            if (!isset($grouped[$g])) {
                $grouped[$g] = array();
            }
            $grouped[$g][] = $row;
        }

        ksort($grouped);
        return $grouped;
    }

    // ── Estado del Sistema ────────────────────────────────

    public static function getStatus() {
        return array(
            'last_updated' => Database::getSetting('last_updated', ''),
            'demo_mode'    => (bool)(int)Database::getSetting('is_demo', '0'),
            'version'      => APP_VERSION,
        );
    }

    public static function hasLiveMatches() {
        $live   = Database::getMatches(null, 'IN_PLAY');
        if (!empty($live)) return true;
        $paused = Database::getMatches(null, 'PAUSED');
        return !empty($paused);
    }

    // ── Payload para el Frontend ─────────────────────────

    /**
     * Construye el JSON completo que consume app.js.
     * Incluye: partidos-hoy, todos agrupados por fecha, posiciones,
     * indicador de vivos, metadata de estado.
     */
    public static function buildPayload($group) {
        // Limpiar partidos estancados cada vez que se sirve el payload,
        // incluso si el cache aun es valido y no se consulto ESPN.
        Database::clearStaleLiveMatches();

        $today     = self::getTodayMatches();
        $all       = self::getMatchesGrouped($group);
        $standings = self::getGroupStandings();
        $status    = self::getStatus();
        $hasLive   = self::hasLiveMatches();

        return array(
            'today'     => $today,
            'all'       => $all,       // Clave 'all', NO 'matches' — importante para app.js
            'standings' => $standings,
            'has_live'  => $hasLive,
            'status'    => $status,
        );
    }
}
