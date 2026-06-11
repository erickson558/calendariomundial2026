<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Servicio de Datos (Business Logic)
 * =========================================================
 *
 * Orquesta la sincronización entre la API pública de ESPN
 * (sin registro) y la base de datos local SQLite.
 *
 * Flujo de actualización:
 *   1. Verificar caché (CACHE_TTL / CACHE_TTL_LIVE)
 *   2. Si caché expiró → Fetcher::getMatches() + getStandings()
 *   3. Persistir via Database::upsertMatch() / upsertStanding()
 *   4. Registrar timestamp en settings
 *
 * Primera carga (DB vacía):
 *   Usa Fetcher::getAllMatches() para traer las 9 semanas del torneo.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/fetcher.php';

class DataService {

    // ── Sincronización ──────────────────────────────────────

    /**
     * Descarga y persiste los datos más recientes de ESPN.
     *
     * Gestión del caché:
     *   - Si hay partidos EN VIVO: caché de 60s (CACHE_TTL_LIVE)
     *   - En otro caso: 300s (CACHE_TTL)
     *   - Si el caché es válido: responde inmediatamente sin tocar la red
     *
     * Primera carga (DB vacía): descarga todo el torneo.
     * Cargas siguientes: solo ±10 días alrededor de hoy.
     *
     * @return array ['success'=>bool, 'message'=>string, 'updated'=>int]
     */
    public static function refreshData(): array {
        // Verificar caché antes de tocar la red
        $lastUpdated = Database::getSetting('last_updated');
        if ($lastUpdated) {
            $elapsed = time() - strtotime($lastUpdated);
            $ttl     = self::hasLiveMatches() ? CACHE_TTL_LIVE : CACHE_TTL;
            if ($elapsed < $ttl) {
                return [
                    'success' => true,
                    'message' => "Cache válido. Próxima actualización disponible en " . ($ttl - $elapsed) . "s.",
                    'updated' => 0,
                ];
            }
        }

        $updated  = 0;
        $teamIdx  = Database::getTeamNameIndex();
        $isFirst  = ((int) Database::getSetting('total_matches', '0')) === 0;

        try {
            // ── Paso 1: Partidos ──────────────────────────
            // Primera carga → traer todo el torneo; actualización → solo rango activo
            $matches = $isFirst
                ? Fetcher::getAllMatches()
                : Fetcher::getMatches();

            foreach ($matches as $match) {
                Database::upsertMatch($match, $teamIdx);
                $updated++;
                // Actualizar el índice de nombres en tiempo real para nuevos equipos
                if ($updated % 10 === 0) {
                    $teamIdx = Database::getTeamNameIndex();
                }
            }

            // Guardar el total de partidos conocidos para detectar la "primera carga"
            $totalNow = (int) Database::connect()->query("SELECT COUNT(*) FROM matches")->fetchColumn();
            Database::setSetting('total_matches', (string) $totalNow);

            // ── Paso 2: Posiciones ────────────────────────
            // ESPN devuelve array plano; cada entrada ya trae su grupo resuelto.
            try {
                $teamIdx   = Database::getTeamNameIndex();
                $standings = Fetcher::getStandings();
                foreach ($standings as $entry) {
                    $group = $entry['group'] ?? null;
                    if ($group) {
                        Database::upsertStanding($entry, $group, $teamIdx);
                    }
                }
            } catch (RuntimeException $e) {
                // Las posiciones pueden no estar disponibles al inicio del torneo
                error_log("Standings fetch warning: " . $e->getMessage());
            }

            // Registrar última sincronización exitosa
            Database::setSetting('last_updated', date('c'));
            Database::setSetting('is_demo',      '0');

            return [
                'success' => true,
                'message' => $isFirst
                    ? "Torneo completo cargado: $updated partidos."
                    : "Actualizados $updated partidos.",
                'updated' => $updated,
            ];

        } catch (RuntimeException $e) {
            // Si la API falla por primera vez, activamos el modo demo
            if ($isFirst) {
                Database::setSetting('is_demo', '1');
            }
            return [
                'success' => false,
                'message' => "ESPN API: " . $e->getMessage(),
                'updated' => $updated,
            ];
        }
    }

    // ── Consultas ──────────────────────────────────────────

    /**
     * Devuelve partidos agrupados por fecha UTC.
     * El frontend re-agrupa por fecha LOCAL del usuario en app.js.
     *
     * @param string|null $group Filtrar por grupo (A–L)
     */
    public static function getMatchesGrouped(?string $group = null): array {
        $matches = Database::getMatches($group);
        $grouped = [];

        foreach ($matches as $match) {
            $dateKey = substr($match['match_date'] ?? '', 0, 10);
            if (!$dateKey) continue;
            $grouped[$dateKey][] = $match;
        }

        ksort($grouped);
        return $grouped;
    }

    /** Partidos cuya fecha UTC es hoy */
    public static function getTodayMatches(): array {
        $today   = date('Y-m-d');
        $matches = Database::getMatches();
        return array_values(array_filter($matches, fn($m) =>
            substr($m['match_date'] ?? '', 0, 10) === $today
        ));
    }

    /** Posiciones agrupadas por letra de grupo */
    public static function getGroupStandings(): array {
        $rows    = Database::getStandings();
        $grouped = [];
        foreach ($rows as $row) {
            if ($row['group_name']) $grouped[$row['group_name']][] = $row;
        }
        ksort($grouped);
        return $grouped;
    }

    // ── Metadatos ──────────────────────────────────────────

    /**
     * Metadatos que el frontend usa para mostrar estado de la app.
     * has_live activa el auto-refresh cada 60s en app.js.
     */
    public static function getStatus(): array {
        return [
            'version'      => APP_VERSION,
            'demo_mode'    => (bool)(int)(Database::getSetting('is_demo', '0') ?? '0'),
            'api_key_set'  => true,          // ESPN no necesita API Key
            'last_updated' => Database::getSetting('last_updated', ''),
            'has_live'     => self::hasLiveMatches(),
            'data_source'  => 'ESPN (sin registro)',
        ];
    }

    /** True cuando hay partidos IN_PLAY o PAUSED en la DB */
    public static function hasLiveMatches(): bool {
        $count = Database::connect()
            ->query("SELECT COUNT(*) FROM matches WHERE status IN ('IN_PLAY','PAUSED')")
            ->fetchColumn();
        return $count > 0;
    }

    /** Payload completo para el frontend */
    public static function buildPayload(?string $groupFilter = null): array {
        return [
            'status'    => self::getStatus(),
            'matches'   => self::getMatchesGrouped($groupFilter),
            'today'     => self::getTodayMatches(),
            'standings' => self::getGroupStandings(),
            'teams'     => Database::getAllTeams(),
        ];
    }
}
