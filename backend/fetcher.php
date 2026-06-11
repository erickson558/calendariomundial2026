<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Cliente ESPN API
 * =========================================================
 * Compatible con PHP 5.4+ (sin type hints, sin const privados)
 *
 * Consulta la API no-oficial de ESPN (sin clave, sin registro):
 *   https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/
 *
 * ESPN no ofrece SLA pero es la unica opcion publica sin registro.
 * El modo demo se activa automaticamente si ESPN falla.
 */

require_once dirname(__FILE__) . '/config.php';

class Fetcher {

    // PHP 5.4 no permite modificadores de acceso en class constants (requiere PHP 7.1).
    // Se declaran como 'const' sin private/protected.
    const ESPN_BASE = 'https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/';
    const TIMEOUT   = 20;

    // ── HTTP generico ─────────────────────────────────────

    /**
     * Peticion GET con cURL. Devuelve array vacio en caso de error
     * para que el sistema degrade a modo demo sin mostrar fatales.
     */
    public static function get($url) {
        if (!function_exists('curl_init')) {
            error_log('[Fetcher] cURL no disponible');
            return array();
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WorldCup2026Tracker/1.0)',
            CURLOPT_HTTPHEADER     => array('Accept: application/json'),
        ));

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log('[Fetcher] cURL error: ' . $err);
            return array();
        }
        if ($code < 200 || $code >= 300) {
            error_log('[Fetcher] HTTP ' . $code . ' URL: ' . $url);
            return array();
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            error_log('[Fetcher] JSON invalido URL: ' . $url);
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
     * Trae todos los partidos del Mundial 2026 (11-jun al 19-jul).
     * Itera por semanas para minimizar requests a ESPN.
     */
    public static function getAllMatches() {
        $all     = array();
        $current = new DateTime('2026-06-11');
        $end     = new DateTime('2026-07-19');

        while ($current <= $end) {
            $weekEnd = clone $current;
            $weekEnd->modify('+6 days');
            if ($weekEnd > $end) {
                $weekEnd = clone $end;
            }

            $from    = $current->format('Ymd');
            $to      = $weekEnd->format('Ymd');
            $matches = self::getMatches($from, $to);
            $all     = array_merge($all, $matches);

            $current->modify('+7 days');

            // Pausa 0.25s entre requests para no saturar ESPN
            if ($current <= $end) {
                usleep(250000);
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

        // Grupo desde las notas del partido
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
        // strpos reemplaza str_contains() que requiere PHP 8.0+
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
