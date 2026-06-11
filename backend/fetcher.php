<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Cliente HTTP (football-data.org)
 * =========================================================
 *
 * Realiza llamadas autenticadas a la API de football-data.org.
 * Capa gratuita: 10 requests/minuto.
 *
 * Endpoints usados:
 *   GET /competitions/WC/matches    — partidos y marcadores
 *   GET /competitions/WC/standings  — tabla de posiciones
 *   GET /competitions/WC/teams      — equipos participantes
 */

require_once __DIR__ . '/config.php';

class Fetcher {

    // ── Configuración interna ─────────────────────────────

    /** Cabeceras comunes para todas las peticiones */
    private static function getHeaders(): array {
        return [
            'X-Auth-Token: ' . FOOTBALL_API_KEY,
            'Accept: application/json',
        ];
    }

    /** Timeout en segundos para cURL */
    private const TIMEOUT = 15;

    // ── Método base HTTP ──────────────────────────────────

    /**
     * Ejecuta una petición GET con cURL.
     * Devuelve el cuerpo JSON decodificado o lanza una excepción
     * con el código HTTP y el mensaje de error.
     *
     * @param string $url URL completa
     * @return array Respuesta decodificada
     * @throws RuntimeException En caso de error HTTP o red
     */
    private static function get(string $url): array {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => self::getHeaders(),
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WorldCup2026Tracker/' . APP_VERSION,
        ]);

        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // Error de red (DNS, timeout, SSL)
        if ($body === false) {
            throw new RuntimeException("cURL error: $curlErr");
        }

        // Error HTTP de la API
        if ($httpCode >= 400) {
            $decoded = json_decode($body, true);
            $msg = $decoded['message'] ?? "HTTP $httpCode";

            if ($httpCode === 403) {
                throw new RuntimeException("API Key inválida o sin acceso al torneo ($msg)");
            }
            if ($httpCode === 429) {
                throw new RuntimeException("Rate limit alcanzado. Espera 1 minuto antes de volver a actualizar.");
            }
            if ($httpCode === 404) {
                throw new RuntimeException("Endpoint no encontrado. El torneo puede no estar disponible aún.");
            }
            throw new RuntimeException("Error API [$httpCode]: $msg");
        }

        $data = json_decode($body, true);
        if ($data === null) {
            throw new RuntimeException("Respuesta JSON inválida de la API");
        }

        return $data;
    }

    // ── Endpoints Públicos ────────────────────────────────

    /**
     * Obtiene todos los partidos del torneo.
     * Se puede filtrar por estado para reducir llamadas a la API.
     *
     * @param string|null $status SCHEDULED|LIVE|IN_PLAY|PAUSED|FINISHED
     * @return array Lista de partidos en formato football-data.org v4
     */
    public static function getMatches(?string $status = null): array {
        $url = FOOTBALL_API_BASE . '/competitions/' . WORLD_CUP_CODE . '/matches';
        if ($status) {
            $url .= '?status=' . urlencode($status);
        }
        $data = self::get($url);
        return $data['matches'] ?? [];
    }

    /**
     * Obtiene las tablas de posiciones por grupo.
     * El tipo TOTAL incluye victorias, empates y derrotas combinadas.
     *
     * @return array Lista de objetos standing (un item por grupo)
     */
    public static function getStandings(): array {
        $url  = FOOTBALL_API_BASE . '/competitions/' . WORLD_CUP_CODE . '/standings?standingType=TOTAL';
        $data = self::get($url);
        return $data['standings'] ?? [];
    }

    /**
     * Obtiene el catálogo de equipos participantes en el torneo.
     * Útil para sincronizar el grupo de cada selección.
     *
     * @return array Lista de equipos
     */
    public static function getTeams(): array {
        $url  = FOOTBALL_API_BASE . '/competitions/' . WORLD_CUP_CODE . '/teams';
        $data = self::get($url);
        return $data['teams'] ?? [];
    }

    /**
     * Verifica si la API Key está configurada y tiene acceso al torneo.
     * Hace una petición liviana al endpoint de información de la competencia.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function testConnection(): array {
        if (empty(FOOTBALL_API_KEY)) {
            return ['ok' => false, 'message' => 'No hay API Key configurada.'];
        }

        try {
            $url  = FOOTBALL_API_BASE . '/competitions/' . WORLD_CUP_CODE;
            $data = self::get($url);
            $name = $data['name'] ?? 'Competencia';
            return ['ok' => true, 'message' => "Conectado: $name"];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
