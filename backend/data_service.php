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
 *
 * La logica de negocio esta separada del HTTP (Fetcher) y del storage
 * (Database) para poder testear y reutilizar cada capa de forma independiente.
 */

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/fetcher.php';

class DataService {

    // ── Actualizacion ─────────────────────────────────────

    /**
     * Descarga partidos y posiciones de ESPN y los persiste en SQLite.
     * Respeta un TTL de cache: 300s en condiciones normales, 60s si hay
     * partidos en vivo (para mantener los marcadores actualizados).
     *
     * Devuelve true si se actualizo, false si el cache aun es valido.
     */
    public static function refreshData() {
        $lastUpdated = Database::getSetting('last_updated', '');
        $isDemo      = Database::getSetting('is_demo', '0');

        // Determinar TTL segun si hay partidos en vivo
        $ttl = CACHE_TTL;
        if (self::hasLiveMatches()) {
            $ttl = CACHE_TTL_LIVE;
        }

        // Cache aun valido: no hacer requests a ESPN
        if ($lastUpdated) {
            $age = time() - strtotime($lastUpdated);
            if ($age < $ttl) {
                return false;
            }
        }

        // Intentar obtener datos de ESPN
        $matches   = Fetcher::getAllMatches();
        $standings = Fetcher::getStandings();

        // Sin datos: activar modo demo (ESPN caido o torneo no iniciado)
        if (empty($matches)) {
            if ($isDemo !== '1') {
                Database::setSetting('is_demo', '1');
            }
            Database::setSetting('last_updated', date('c'));
            return false;
        }

        // Con datos: desactivar modo demo y persistir
        Database::setSetting('is_demo', '0');

        $teamIdx = Database::getTeamNameIndex();

        foreach ($matches as $match) {
            Database::upsertMatch($match, $teamIdx);
        }

        // Refrescar indice porque upsertMatch puede haber insertado nuevos equipos
        $teamIdx = Database::getTeamNameIndex();

        foreach ($standings as $row) {
            $group = isset($row['group']) ? $row['group'] : '';
            Database::upsertStanding($row, $group, $teamIdx);
        }

        Database::setSetting('last_updated', date('c'));
        return true;
    }

    // ── Consultas para el Frontend ────────────────────────

    /**
     * Partidos agrupados por fecha (UTC).
     * El frontend los re-agrupa por fecha LOCAL del usuario con Intl.DateTimeFormat.
     */
    public static function getMatchesGrouped($group) {
        $matches = Database::getMatches($group);
        $grouped = array();

        foreach ($matches as $match) {
            $raw     = isset($match['match_date']) ? $match['match_date'] : '';
            $dateKey = substr($raw, 0, 10);  // solo la parte YYYY-MM-DD
            if (!$dateKey) continue;
            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = array();
            }
            $grouped[$dateKey][] = $match;
        }

        ksort($grouped);  // ordenar por fecha ascendente
        return $grouped;
    }

    /**
     * Partidos programados para hoy (en UTC).
     * El frontend puede reinterpretar "hoy" segun la zona del usuario,
     * pero este filtro base usa UTC para consistencia del servidor.
     */
    public static function getTodayMatches() {
        $matches = Database::getMatches();
        $today   = gmdate('Y-m-d');  // fecha UTC del servidor

        // PHP 5.4 no tiene arrow functions (fn() =>), se usa funcion anonima
        $filtered = array_filter($matches, function($m) use ($today) {
            $raw = isset($m['match_date']) ? $m['match_date'] : '';
            return substr($raw, 0, 10) === $today;
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

    /** Devuelve metadata sobre el estado de sincronizacion */
    public static function getStatus() {
        return array(
            'last_updated' => Database::getSetting('last_updated', ''),
            'demo_mode'    => (bool)(int)Database::getSetting('is_demo', '0'),
            'version'      => APP_VERSION,
        );
    }

    /** true si hay algun partido EN_VIVO en la BD (controla TTL de cache) */
    public static function hasLiveMatches() {
        $live = Database::getMatches(null, 'IN_PLAY');
        if (!empty($live)) return true;
        $paused = Database::getMatches(null, 'PAUSED');
        return !empty($paused);
    }

    // ── Payload para el Frontend ─────────────────────────

    /**
     * Construye el JSON completo que consume app.js.
     * Incluye: partidos-hoy, partidos-todos agrupados, posiciones,
     * indicador de vivos, estado de sync y metadata.
     *
     * El parametro $group filtra los partidos por grupo (A-L) o null = todos.
     */
    public static function buildPayload($group) {
        $today     = self::getTodayMatches();
        $all       = self::getMatchesGrouped($group);
        $standings = self::getGroupStandings();
        $status    = self::getStatus();
        $hasLive   = self::hasLiveMatches();

        return array(
            'today'      => $today,
            'all'        => $all,
            'standings'  => $standings,
            'has_live'   => $hasLive,
            'status'     => $status,
        );
    }
}
