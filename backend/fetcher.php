<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Cliente ESPN API
 * =========================================================
 * Compatible con PHP 5.4+ (sin type hints, sin const privados)
 *
 * Usa PowerShell como proxy HTTP para soportar TLS 1.2:
 *   PHP 5.4 + OpenSSL 0.9.8z (EasyPHP 14) no puede negociar
 *   TLS 1.2 con servidores modernos. PowerShell usa .NET y
 *   soporta TLS 1.2 sin problema en Windows 7+.
 *
 * ESPN no-oficial (sin clave, sin registro):
 *   https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/
 */

require_once dirname(__FILE__) . '/config.php';

class Fetcher {

    const ESPN_BASE = 'https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/';
    const TIMEOUT   = 15;

    // ── HTTP via PowerShell ───────────────────────────────

    /**
     * Peticion GET usando PowerShell para sortear la limitacion
     * de TLS 1.2 en cURL/OpenSSL 0.9.8z de EasyPHP 14.
     * shell_exec esta disponible en EasyPHP por defecto.
     */
    public static function get($url) {
        if (!function_exists('shell_exec')) {
            error_log('[Fetcher] shell_exec no disponible');
            return array();
        }

        // Escapar comillas simples en la URL para PowerShell
        $safe = str_replace("'", "''", $url);

        // Forzar TLS 1.2 antes de la peticion; catch devuelve cadena vacia
        $cmd = 'powershell -NonInteractive -NoProfile -Command '
             . '"try { [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; '
             . '(Invoke-WebRequest -Uri \'' . $safe . '\' -UseBasicParsing -TimeoutSec ' . self::TIMEOUT . ').Content } '
             . 'catch { Write-Output \'\' }"';

        $body = @shell_exec($cmd . ' 2>&1');

        if (!$body || !trim($body)) {
            error_log('[Fetcher] Sin respuesta. URL: ' . $url);
            return array();
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            error_log('[Fetcher] JSON invalido. Inicio: ' . substr($body, 0, 200));
            return array();
        }
        return $data;
    }

    // ── Partidos ──────────────────────────────────────────

    /** Obtiene partidos de un rango de fechas (formato Ymd) */
    public static function getMatches($dateFrom, $dateTo) {
        $url  = self::ESPN_BASE . 'scoreboard?limit=100&dates=' . $dateFrom . '-' . $dateTo;
        $data = self::get($url);
        if (empty($data)) return array();

        $events = isset($data['events']) ? $data['events'] : array();
        if (empty($events) && isset($data['sports'][0]['leagues'][0]['events'])) {
            $events = $data['sports'][0]['leagues'][0]['events'];
        }

        $matches = array();
        foreach ($events as $event) {
            $norm = self::normalizeMatch($event);
            if ($norm !== null) {
                $matches[] = $norm;
            }
        }
        return $matches;
    }

    /**
     * Trae partidos del Mundial para la ventana actual (ayer +7 dias).
     * Usa queries de un solo dia porque el formato de rango de ESPN
     * no filtra correctamente (devuelve rondas de eliminacion en vez
     * de la fecha solicitada). Queries de dia unico si funcionan.
     * 7-8 requests x 0.2s = ~1.5s, aceptable.
     */
    public static function getAllMatches() {
        $today    = new DateTime('now', new DateTimeZone('UTC'));
        $wcStart  = new DateTime('2026-06-11', new DateTimeZone('UTC'));
        $wcEnd    = new DateTime('2026-07-19', new DateTimeZone('UTC'));

        // Ventana: ayer hasta +6 dias (captura partidos en vivo y proximos)
        $start = clone $today;
        $start->modify('-1 day');
        if ($start < $wcStart) $start = clone $wcStart;

        $end = clone $today;
        $end->modify('+6 days');
        if ($end > $wcEnd) $end = clone $wcEnd;

        $all     = array();
        $current = clone $start;

        while ($current <= $end) {
            $day     = $current->format('Ymd');
            $matches = self::getMatches($day, $day);
            $all     = array_merge($all, $matches);
            $current->modify('+1 day');

            if ($current <= $end) {
                usleep(200000);  // 0.2s entre requests
            }
        }
        return $all;
    }

    /**
     * Normaliza un evento ESPN al formato interno.
     * ESPN tiene estructura muy anidada; aqui la aplanamos.
     * Devuelve null si le falta la estructura minima esperada.
     */
    public static function normalizeMatch($event) {
        if (empty($event) || !is_array($event)) return null;

        $comp = isset($event['competitions'][0]) ? $event['competitions'][0] : null;
        if (!$comp) return null;

        $competitors = isset($comp['competitors']) ? $comp['competitors'] : array();
        if (count($competitors) < 2) return null;

        // ESPN marca home/away con competitor[x]['homeAway']
        $home = null;
        $away = null;
        foreach ($competitors as $c) {
            if (isset($c['homeAway']) && $c['homeAway'] === 'home') $home = $c;
            if (isset($c['homeAway']) && $c['homeAway'] === 'away') $away = $c;
        }
        if (!$home || !$away) {
            $home = $competitors[0];
            $away = $competitors[1];
        }

        // Mapear estado ESPN → estado interno
        $espnStatus = '';
        if (isset($event['status']['type']['name'])) {
            $espnStatus = $event['status']['type']['name'];
        } elseif (isset($comp['status']['type']['name'])) {
            $espnStatus = $comp['status']['type']['name'];
        }
        $status = self::mapStatus($espnStatus);

        // Marcadores solo si el partido no esta programado
        $homeScore = null;
        $awayScore = null;
        if ($status !== 'SCHEDULED') {
            $homeScore = isset($home['score']) ? (int)$home['score'] : null;
            $awayScore = isset($away['score']) ? (int)$away['score'] : null;
        }

        // Grupo desde las notas del partido (ESPN puede incluirlo o no)
        $group = null;
        if (isset($comp['notes']) && is_array($comp['notes'])) {
            foreach ($comp['notes'] as $note) {
                $h = isset($note['headline']) ? $note['headline'] : '';
                if (preg_match('/group\s+([A-L])/i', $h, $m)) {
                    $group = 'GROUP_' . strtoupper($m[1]);
                    break;
                }
            }
        }

        // Fase del torneo
        $stage     = 'GROUP_STAGE';
        $stageSlug = '';
        if (isset($event['season']['slug'])) {
            $stageSlug = strtolower($event['season']['slug']);
        }
        // strpos reemplaza str_contains() (PHP 8.0+)
        if (strpos($stageSlug, 'round-of-32') !== false) $stage = 'LAST_32';
        if (strpos($stageSlug, 'round-of-16') !== false) $stage = 'LAST_16';
        if (strpos($stageSlug, 'quarter')     !== false) $stage = 'QUARTER_FINALS';
        if (strpos($stageSlug, 'semi')        !== false) $stage = 'SEMI_FINALS';
        if (strpos($stageSlug, 'final')       !== false && strpos($stageSlug, 'semi') === false) $stage = 'FINAL';

        $venue = null;
        $city  = null;
        if (isset($comp['venue']['fullName']))          $venue = $comp['venue']['fullName'];
        if (isset($comp['venue']['address']['city']))   $city  = $comp['venue']['address']['city'];

        return array(
            'id'       => isset($event['id'])   ? $event['id']   : null,
            'utcDate'  => isset($event['date']) ? $event['date'] : null,
            'status'   => $status,
            'stage'    => $stage,
            'group'    => $group,
            'matchday' => isset($event['week']) ? $event['week'] : null,
            'venue'    => $venue,
            'city'     => $city,
            'homeTeam' => array(
                'id'        => isset($home['team']['id'])               ? $home['team']['id']               : null,
                'name'      => isset($home['team']['displayName'])      ? $home['team']['displayName']      : '',
                'shortName' => isset($home['team']['shortDisplayName']) ? $home['team']['shortDisplayName'] : '',
                'tla'       => isset($home['team']['abbreviation'])     ? $home['team']['abbreviation']     : '',
            ),
            'awayTeam' => array(
                'id'        => isset($away['team']['id'])               ? $away['team']['id']               : null,
                'name'      => isset($away['team']['displayName'])      ? $away['team']['displayName']      : '',
                'shortName' => isset($away['team']['shortDisplayName']) ? $away['team']['shortDisplayName'] : '',
                'tla'       => isset($away['team']['abbreviation'])     ? $away['team']['abbreviation']     : '',
            ),
            'score' => array(
                'fullTime' => array('home' => $homeScore, 'away' => $awayScore),
                'halfTime' => array('home' => null,       'away' => null),
            ),
        );
    }

    /** Convierte estados ESPN → estado interno de la app */
    private static function mapStatus($espnStatus) {
        $map = array(
            'STATUS_SCHEDULED'   => 'SCHEDULED',
            'STATUS_IN_PROGRESS' => 'IN_PLAY',
            'STATUS_HALFTIME'    => 'PAUSED',
            'STATUS_FINAL'       => 'FINISHED',
            'STATUS_FULL_TIME'   => 'FINISHED',
            'STATUS_POSTPONED'   => 'POSTPONED',
            'STATUS_CANCELED'    => 'CANCELLED',
            'STATUS_SUSPENDED'   => 'PAUSED',
        );
        return isset($map[$espnStatus]) ? $map[$espnStatus] : 'SCHEDULED';
    }

    // ── Posiciones ────────────────────────────────────────

    /** Obtiene tabla de posiciones de ESPN */
    public static function getStandings() {
        $url  = self::ESPN_BASE . 'standings?season=2026';
        $data = self::get($url);
        if (empty($data)) return array();
        return self::normalizeStandings($data);
    }

    /** Aplana la respuesta anidada de standings de ESPN */
    public static function normalizeStandings($data) {
        $result = array();
        $groups = array();

        if (isset($data['standings']['entries'])) {
            $groups = array($data['standings']);
        } elseif (isset($data['children'])) {
            $groups = $data['children'];
        }

        foreach ($groups as $groupData) {
            $groupName = isset($groupData['name']) ? $groupData['name'] : '';
            if (!preg_match('/group\s+([A-L])/i', $groupName, $m)) continue;
            $group   = strtoupper($m[1]);
            $entries = isset($groupData['standings']['entries']) ? $groupData['standings']['entries'] : array();

            foreach ($entries as $entry) {
                $norm = self::normalizeStandingEntry($entry, $group);
                if ($norm) $result[] = $norm;
            }
        }
        return $result;
    }

    /** Normaliza una fila de posicion de ESPN */
    private static function normalizeStandingEntry($entry, $group) {
        if (empty($entry['team'])) return null;

        $stats = array();
        if (isset($entry['stats'])) {
            foreach ($entry['stats'] as $s) {
                if (isset($s['name'], $s['value'])) {
                    $stats[$s['name']] = $s['value'];
                }
            }
        }

        return array(
            'group' => $group,
            'team'  => array(
                'id'        => isset($entry['team']['id'])               ? $entry['team']['id']               : null,
                'name'      => isset($entry['team']['displayName'])      ? $entry['team']['displayName']      : '',
                'shortName' => isset($entry['team']['shortDisplayName']) ? $entry['team']['shortDisplayName'] : '',
                'tla'       => isset($entry['team']['abbreviation'])     ? $entry['team']['abbreviation']     : '',
            ),
            'position'       => isset($stats['rank'])              ? (int)$stats['rank']              : 1,
            'playedGames'    => isset($stats['gamesPlayed'])       ? (int)$stats['gamesPlayed']       : 0,
            'won'            => isset($stats['wins'])              ? (int)$stats['wins']              : 0,
            'draw'           => isset($stats['ties'])              ? (int)$stats['ties']              : 0,
            'lost'           => isset($stats['losses'])            ? (int)$stats['losses']            : 0,
            'goalsFor'       => isset($stats['pointsFor'])         ? (int)$stats['pointsFor']         : 0,
            'goalsAgainst'   => isset($stats['pointsAgainst'])     ? (int)$stats['pointsAgainst']     : 0,
            'goalDifference' => isset($stats['pointDifferential']) ? (int)$stats['pointDifferential'] : 0,
            'points'         => isset($stats['points'])            ? (int)$stats['points']            : 0,
        );
    }
}
