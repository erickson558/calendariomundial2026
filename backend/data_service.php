<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Servicio de Datos (Business Logic)
 * =========================================================
 *
 * Orquesta la sincronización entre la API externa (Fetcher)
 * y la base de datos local (Database).
 *
 * Métodos clave:
 *   refreshData()        — descarga y persiste datos de la API
 *   getMatchesGrouped()  — partidos agrupados por fecha (para el frontend)
 *   getGroupStandings()  — posiciones por grupo
 *   getStatus()          — metadatos de la app (última actualización, modo demo)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/fetcher.php';

class DataService {

    // ── Sincronización ─────────────────────────────────────

    /**
     * Descarga la información más reciente de football-data.org
     * y la persiste en SQLite.
     *
     * Lógica de caché:
     *   - Si hay partidos EN VIVO usa CACHE_TTL_LIVE (60s)
     *   - En otro caso usa CACHE_TTL (300s)
     *   - Si el caché no ha expirado devuelve inmediatamente sin llamar a la API
     *
     * @return array ['success' => bool, 'message' => string, 'updated' => int]
     */
    public static function refreshData(): array {
        if (DEMO_MODE) {
            return ['success' => false, 'message' => 'Modo Demo activo. Configura una API Key en backend/config.local.php para datos reales.', 'updated' => 0];
        }

        // Respetar el caché para no sobrepasar el rate limit de la API
        $lastUpdated = Database::getSetting('last_updated');
        if ($lastUpdated) {
            $elapsed = time() - strtotime($lastUpdated);
            $ttl     = self::hasLiveMatches() ? CACHE_TTL_LIVE : CACHE_TTL;
            if ($elapsed < $ttl) {
                return ['success' => true, 'message' => "Cache válido. Próxima actualización en " . ($ttl - $elapsed) . "s.", 'updated' => 0];
            }
        }

        $updated = 0;

        try {
            // 1. Sincronizar equipos (obtiene grupos y grupos asignados)
            $teams = Fetcher::getTeams();
            if (!empty($teams)) {
                $teamIdx = Database::getTeamNameIndex();
                foreach ($teams as $team) {
                    Database::upsertTeam($team);
                }
                // Reconstruir índice con los nuevos equipos insertados
                $teamIdx = Database::getTeamNameIndex();
            } else {
                $teamIdx = Database::getTeamNameIndex();
            }

            // 2. Sincronizar partidos (todos los estados)
            $matches = Fetcher::getMatches();
            foreach ($matches as $match) {
                Database::upsertMatch($match, $teamIdx);
                $updated++;
            }

            // 3. Sincronizar posiciones de grupos
            $standings = Fetcher::getStandings();
            foreach ($standings as $standing) {
                $group = str_replace('GROUP_', '', $standing['group'] ?? '');
                foreach ($standing['table'] ?? [] as $row) {
                    Database::upsertStanding($row, $group, $teamIdx);
                }
            }

            // Registrar hora de actualización exitosa
            Database::setSetting('last_updated', date('c'));
            Database::setSetting('is_demo', '0');

            return [
                'success' => true,
                'message' => "Se actualizaron $updated partidos correctamente.",
                'updated' => $updated,
            ];

        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'updated' => $updated,
            ];
        }
    }

    // ── Consultas de Partidos ─────────────────────────────

    /**
     * Devuelve los partidos agrupados por fecha local (UTC).
     * El frontend convertirá las horas a la zona del usuario.
     *
     * @param string|null $group Filtrar por grupo (A–L)
     * @return array Estructura: ['YYYY-MM-DD' => [partidos...]]
     */
    public static function getMatchesGrouped(?string $group = null): array {
        $matches = Database::getMatches($group);
        $grouped = [];

        foreach ($matches as $match) {
            // Extraer la fecha UTC del campo match_date (ISO 8601)
            $dateKey = substr($match['match_date'] ?? '', 0, 10);  // "YYYY-MM-DD"
            if (!$dateKey) continue;

            $grouped[$dateKey][] = $match;
        }

        ksort($grouped);  // Ordenar las fechas cronológicamente
        return $grouped;
    }

    /**
     * Devuelve los partidos de hoy (fecha UTC).
     * Incluye los partidos en vivo para resaltar en la UI.
     */
    public static function getTodayMatches(): array {
        $today   = date('Y-m-d');
        $matches = Database::getMatches();
        return array_filter($matches, function($m) use ($today) {
            return substr($m['match_date'] ?? '', 0, 10) === $today;
        });
    }

    /**
     * Devuelve las posiciones agrupadas por letra de grupo.
     *
     * @return array Estructura: ['A' => [posiciones...], 'B' => [...], ...]
     */
    public static function getGroupStandings(): array {
        $rows    = Database::getStandings();
        $grouped = [];

        foreach ($rows as $row) {
            $g = $row['group_name'];
            if ($g) $grouped[$g][] = $row;
        }

        ksort($grouped);
        return $grouped;
    }

    // ── Metadatos ─────────────────────────────────────────

    /**
     * Devuelve metadatos de la aplicación para el frontend:
     * última actualización, modo demo, versión, y si hay
     * partidos en vivo para activar auto-refresh.
     */
    public static function getStatus(): array {
        return [
            'version'      => APP_VERSION,
            'demo_mode'    => DEMO_MODE,
            'api_key_set'  => !empty(FOOTBALL_API_KEY),
            'last_updated' => Database::getSetting('last_updated', ''),
            'has_live'     => self::hasLiveMatches(),
        ];
    }

    /**
     * Verifica si hay partidos en estado IN_PLAY o PAUSED.
     * Se usa para determinar el intervalo de caché adecuado.
     */
    public static function hasLiveMatches(): bool {
        $db    = Database::connect();
        $count = $db->query("SELECT COUNT(*) FROM matches WHERE status IN ('IN_PLAY','PAUSED')")->fetchColumn();
        return $count > 0;
    }

    // ── Payload completo para el frontend ─────────────────

    /**
     * Construye el objeto JSON completo que consume el frontend.
     * Agrupa partidos por fecha y añade posiciones y metadatos.
     *
     * @param string|null $groupFilter Grupo a filtrar (null = todos)
     * @return array Payload listo para json_encode()
     */
    public static function buildPayload(?string $groupFilter = null): array {
        return [
            'status'    => self::getStatus(),
            'matches'   => self::getMatchesGrouped($groupFilter),
            'today'     => array_values(self::getTodayMatches()),
            'standings' => self::getGroupStandings(),
            'teams'     => Database::getAllTeams(),
        ];
    }
}
