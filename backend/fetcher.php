<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Cliente ESPN API (sin registro)
 * =========================================================
 *
 * Usa la API pública no-oficial de ESPN que no requiere
 * ningún registro ni API Key.
 *
 * Endpoints usados:
 *   GET site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/scoreboard
 *   GET site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/standings
 *
 * Formato de respuesta normalizado al mismo contrato interno
 * que Database::upsertMatch() y Database::upsertStanding()
 * para no cambiar la capa de persistencia.
 */

require_once __DIR__ . '/config.php';

class Fetcher {

    // ── Constantes ────────────────────────────────────────

    /** Base de la API pública de ESPN para soccer */
    private const ESPN_BASE = 'https://site.api.espn.com/apis/site/v2/sports/soccer';

    /**
     * Slug del torneo en ESPN.
     * Si cambia con el nuevo torneo, actualizar aquí solamente.
     */
    private const LEAGUE = 'fifa.world';

    /** Segundos de timeout por petición cURL */
    private const TIMEOUT = 20;

    // ── HTTP base ─────────────────────────────────────────

    /**
     * Realiza una petición GET a la API de ESPN.
     * No lleva cabecera de autenticación — la API es pública.
     *
     * El User-Agent imita un navegador para evitar bloqueos
     * de servidores que rechazan peticiones sin UA conocido.
     *
     * @throws RuntimeException En errores de red o HTTP
     */
    private static function get(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                'Referer: https://www.espn.com/',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Error de red (cURL): $curlErr");
        }
        if ($httpCode === 404) {
            throw new RuntimeException("Torneo no encontrado en ESPN (código $httpCode). Puede que el slug cambie con el Mundial 2026.");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("ESPN API devolvió HTTP $httpCode");
        }

        $data = json_decode($body, true);
        if ($data === null) {
            throw new RuntimeException("Respuesta JSON inválida de ESPN");
        }

        return $data;
    }

    // ── Partidos ──────────────────────────────────────────

    /**
     * Obtiene los partidos en un rango de fechas de ±10 días desde hoy.
     * Para la primera carga completa del torneo usa getAllMatches().
     *
     * El rango acotado evita hacer decenas de peticiones en cada
     * actualización de rutina. Solo ampliamos el rango cuando
     * la DB está vacía (primera vez) o cuando el usuario fuerza
     * una actualización completa.
     *
     * @return array Lista de partidos normalizados al formato interno
     */
    public static function getMatches(): array {
        $today = new DateTime();

        // Rango centrado en hoy, dentro del torneo (11 jun — 19 jul 2026)
        $from = max(
            new DateTime('2026-06-11'),
            (clone $today)->modify('-3 days')
        );
        $to = min(
            new DateTime('2026-07-19'),
            (clone $today)->modify('+10 days')
        );

        return self::fetchRange($from->format('Ymd'), $to->format('Ymd'));
    }

    /**
     * Obtiene todos los partidos del torneo completo (9 semanas).
     * Se llama solo en la primera carga para poblar la DB desde cero.
     *
     * Divide el torneo en semanas para no superar el límite de la
     * API y evitar timeout por respuestas muy grandes.
     *
     * @return array Lista completa de partidos normalizados
     */
    public static function getAllMatches(): array {
        // Semanas del torneo
        $weeks = [
            ['20260611', '20260617'],
            ['20260618', '20260624'],
            ['20260625', '20260701'],
            ['20260702', '20260708'],
            ['20260709', '20260719'],
        ];

        $all = [];
        foreach ($weeks as [$from, $to]) {
            try {
                $matches = self::fetchRange($from, $to);
                $all     = array_merge($all, $matches);
                // Pausa mínima para no martillar la API
                if (count($weeks) > 1) usleep(250_000);
            } catch (RuntimeException $e) {
                // Si una semana falla, continuar con las demás
                error_log("Fetcher::getAllMatches error {$from}-{$to}: " . $e->getMessage());
            }
        }
        return $all;
    }

    /**
     * Llama al endpoint scoreboard de ESPN para el rango de fechas
     * indicado y normaliza la respuesta al formato interno.
     *
     * @param string $from  Formato YYYYMMDD
     * @param string $to    Formato YYYYMMDD
     */
    private static function fetchRange(string $from, string $to): array {
        // dates puede ser un rango YYYYMMDD-YYYYMMDD o una fecha sola
        $datesParam = ($from === $to) ? $from : "{$from}-{$to}";
        $url        = self::ESPN_BASE . '/' . self::LEAGUE
                    . '/scoreboard?dates=' . $datesParam . '&limit=100';

        $data   = self::get($url);
        $events = $data['events'] ?? [];

        $matches = [];
        foreach ($events as $event) {
            $match = self::normalizeMatch($event);
            if ($match) $matches[] = $match;
        }
        return $matches;
    }

    // ── Normalización de partidos ──────────────────────────

    /**
     * Convierte un evento ESPN al formato interno compatible con
     * Database::upsertMatch().
     *
     * Mapeo de estado ESPN → formato interno:
     *   "pre"  → SCHEDULED
     *   "in"   → IN_PLAY
     *   "post" → FINISHED
     *   STATUS_HALFTIME → PAUSED
     *   STATUS_POSTPONED → POSTPONED
     *
     * Los scores se dejan en null cuando el estado es SCHEDULED
     * para distinguir "0-0 en juego" de "aún no jugado".
     *
     * @return array|null null si el evento no tiene datos suficientes
     */
    private static function normalizeMatch(array $event): ?array {
        $competition = $event['competitions'][0] ?? null;
        if (!$competition) return null;

        // Separar equipo local y visitante
        $home = $away = null;
        foreach ($competition['competitors'] ?? [] as $c) {
            if ($c['homeAway'] === 'home') $home = $c;
            if ($c['homeAway'] === 'away') $away = $c;
        }
        if (!$home || !$away) return null;

        // Estado del partido
        $espnState = $event['status']['type']['state']     ?? 'pre';
        $espnName  = $event['status']['type']['name']      ?? '';

        $statusMap = ['pre' => 'SCHEDULED', 'in' => 'IN_PLAY', 'post' => 'FINISHED'];
        $status    = $statusMap[$espnState] ?? 'SCHEDULED';
        if ($espnName === 'STATUS_HALFTIME')  $status = 'PAUSED';
        if ($espnName === 'STATUS_POSTPONED') $status = 'POSTPONED';
        if ($espnName === 'STATUS_CANCELLED') $status = 'CANCELLED';

        // Marcadores: null cuando el partido no ha comenzado
        $homeScore = ($status !== 'SCHEDULED') ? ((int)($home['score'] ?? 0)) : null;
        $awayScore = ($status !== 'SCHEDULED') ? ((int)($away['score'] ?? 0)) : null;

        // Inferir grupo del partido desde las notas o el nombre del evento
        $group = null;
        foreach ($competition['notes'] ?? [] as $note) {
            if (preg_match('/Group\s+([A-L])/i', $note['headline'] ?? '', $m)) {
                $group = 'GROUP_' . strtoupper($m[1]);
                break;
            }
        }
        if (!$group) {
            $texts = [$event['name'] ?? '', $event['shortName'] ?? '', $competition['type']['text'] ?? ''];
            foreach ($texts as $t) {
                if (preg_match('/Group\s+([A-L])/i', $t, $m)) {
                    $group = 'GROUP_' . strtoupper($m[1]);
                    break;
                }
            }
        }

        // Stage a partir del tipo de competición reportado por ESPN
        $roundText = strtolower(
            $competition['type']['text'] ??
            $competition['type']['abbreviation'] ?? ''
        );
        $stage = 'GROUP_STAGE';
        if (str_contains($roundText, 'round of 32'))  $stage = 'ROUND_OF_32';
        if (str_contains($roundText, 'round of 16'))  $stage = 'LAST_16';
        if (str_contains($roundText, 'quarter'))       $stage = 'QUARTER_FINALS';
        if (str_contains($roundText, 'semi'))          $stage = 'SEMI_FINALS';
        if (str_contains($roundText, 'third'))         $stage = 'THIRD_PLACE';
        if (str_contains($roundText, 'final')
            && !str_contains($roundText, 'semi')
            && !str_contains($roundText, 'third'))     $stage = 'FINAL';

        // Jornada desde el nombre del grupo o notes
        $matchday = null;
        foreach ($competition['notes'] ?? [] as $note) {
            if (preg_match('/Matchday\s+(\d)/i', $note['headline'] ?? '', $m)) {
                $matchday = (int)$m[1];
                break;
            }
        }

        // Construir objeto compatible con Database::upsertMatch()
        return [
            'id'       => $event['id'],
            'utcDate'  => $event['date'],      // ISO 8601 UTC: "2026-06-11T19:00Z"
            'status'   => $status,
            'stage'    => $stage,
            'group'    => $group,
            'matchday' => $matchday,
            'venue'    => $competition['venue']['fullName'] ?? null,
            'homeTeam' => [
                'id'        => $home['id']                             ?? null,
                'name'      => $home['team']['displayName']            ?? $home['team']['name']  ?? 'TBD',
                'shortName' => $home['team']['shortDisplayName']       ?? $home['team']['name']  ?? 'TBD',
                'tla'       => strtoupper($home['team']['abbreviation'] ?? '???'),
            ],
            'awayTeam' => [
                'id'        => $away['id']                             ?? null,
                'name'      => $away['team']['displayName']            ?? $away['team']['name']  ?? 'TBD',
                'shortName' => $away['team']['shortDisplayName']       ?? $away['team']['name']  ?? 'TBD',
                'tla'       => strtoupper($away['team']['abbreviation'] ?? '???'),
            ],
            'score' => [
                'fullTime' => ['home' => $homeScore, 'away' => $awayScore],
                'halfTime' => ['home' => null,       'away' => null],
            ],
        ];
    }

    // ── Posiciones ────────────────────────────────────────

    /**
     * Obtiene las posiciones de los grupos desde ESPN.
     * Devuelve un array plano de entradas, cada una con su grupo
     * ya resuelto, listo para iterar en data_service.php.
     *
     * @return array Lista de entradas [ ['group'=>'A', 'team'=>..., ...], … ]
     */
    public static function getStandings(): array {
        $url  = self::ESPN_BASE . '/' . self::LEAGUE . '/standings';
        $data = self::get($url);
        return self::normalizeStandings($data);
    }

    /**
     * Normaliza la respuesta de standings de ESPN.
     *
     * ESPN puede devolver dos estructuras distintas según el torneo:
     *   1. standings.groups[].standings.entries[]  (más común en grupos)
     *   2. standings.entries[]                     (formato plano)
     *
     * Ambas quedan aplanadas en un array uniforme.
     */
    private static function normalizeStandings(array $data): array {
        $result      = [];
        $standingsObj = $data['standings'] ?? $data;

        // ── Formato grupado (más común para fase de grupos) ──
        if (!empty($standingsObj['groups'])) {
            foreach ($standingsObj['groups'] as $grpObj) {
                $grpName = $grpObj['name'] ?? $grpObj['abbreviation'] ?? '';
                preg_match('/Group\s+([A-L])/i', $grpName, $m);
                $letter  = isset($m[1]) ? strtoupper($m[1]) : null;
                if (!$letter) continue;

                $entries = $grpObj['standings']['entries']
                        ?? $grpObj['entries']
                        ?? [];
                foreach ($entries as $pos => $entry) {
                    $normalized = self::normalizeStandingEntry($entry, $letter);
                    // Si ESPN no devolvió posición explícita, usar el índice del array
                    if (!$normalized['position']) $normalized['position'] = $pos + 1;
                    $result[] = $normalized;
                }
            }
            return $result;
        }

        // ── Formato plano ──────────────────────────────────
        foreach ($standingsObj['entries'] ?? [] as $entry) {
            $grpName = $entry['group']['name'] ?? '';
            preg_match('/Group\s+([A-L])/i', $grpName, $m);
            $letter  = isset($m[1]) ? strtoupper($m[1]) : 'A';
            $result[] = self::normalizeStandingEntry($entry, $letter);
        }
        return $result;
    }

    /**
     * Convierte una entrada individual de posiciones ESPN
     * al formato que espera Database::upsertStanding().
     *
     * ESPN devuelve estadísticas como array de objetos {name, value},
     * primero los convertimos a un map asociativo para acceso directo.
     * Los nombres de campo de ESPN varían; el ?? encadena fallbacks.
     */
    private static function normalizeStandingEntry(array $entry, string $group): array {
        // Convertir [{name:'wins',value:1}, ...] → ['wins'=>1, ...]
        $stats = [];
        foreach ($entry['stats'] ?? [] as $s) {
            $stats[$s['name']] = $s['value'] ?? 0;
        }

        return [
            'group'          => $group,
            'team' => [
                'id'        => $entry['team']['id']               ?? null,
                'name'      => $entry['team']['displayName']      ?? $entry['team']['name']  ?? 'TBD',
                'shortName' => $entry['team']['shortDisplayName'] ?? $entry['team']['name']  ?? 'TBD',
                'tla'       => strtoupper($entry['team']['abbreviation'] ?? '???'),
            ],
            'position'       => (int)($stats['rank']               ?? $stats['position']      ?? 0),
            'playedGames'    => (int)($stats['gamesPlayed']        ?? $stats['played']        ?? 0),
            'won'            => (int)($stats['wins']               ?? $stats['won']           ?? 0),
            'draw'           => (int)($stats['ties']               ?? $stats['draws']         ?? 0),
            'lost'           => (int)($stats['losses']             ?? $stats['lost']          ?? 0),
            'goalsFor'       => (int)($stats['pointsFor']          ?? $stats['goalsFor']      ?? 0),
            'goalsAgainst'   => (int)($stats['pointsAgainst']      ?? $stats['goalsAgainst']  ?? 0),
            'goalDifference' => (int)($stats['pointDifferential']  ?? $stats['goalDifference']?? 0),
            'points'         => (int)($stats['points']             ?? 0),
        ];
    }

    // ── Utilidades ────────────────────────────────────────

    /**
     * Verifica que la API de ESPN esté accesible y devuelve datos
     * para el torneo. Usado por update-results.php para diagnóstico.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function testConnection(): array {
        try {
            $today = date('Ymd');
            $url   = self::ESPN_BASE . '/' . self::LEAGUE . '/scoreboard?dates=' . $today . '&limit=1';
            $data  = self::get($url);
            $count = count($data['events'] ?? []);
            return ['ok' => true, 'message' => "ESPN API conectada. Partidos hoy: $count"];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
