<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Capa de Base de Datos (SQLite)
 * =========================================================
 *
 * Responsable de:
 *  - Crear y migrar el esquema SQLite
 *  - Poblar el catálogo inicial de equipos (seed)
 *  - Exponer métodos CRUD para partidos, tablas y ajustes
 */

require_once __DIR__ . '/config.php';

class Database {

    /** @var PDO Conexión singleton a SQLite */
    private static ?PDO $instance = null;

    // ── Conexión ──────────────────────────────────────────

    /**
     * Devuelve (o crea) la conexión PDO a SQLite.
     * Aplica WAL mode para lecturas concurrentes sin bloqueos.
     */
    public static function connect(): PDO {
        if (self::$instance === null) {
            // Asegurar que el directorio data/ exista
            $dir = dirname(DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . DB_PATH);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');

            // Crear tablas si no existen y sembrar datos iniciales
            self::createSchema();
            self::seedTeams();
            self::seedDemoMatches();
        }
        return self::$instance;
    }

    // ── Esquema ───────────────────────────────────────────

    /**
     * Define las cuatro tablas del tracker: teams, matches,
     * standings y settings.
     */
    private static function createSchema(): void {
        $db = self::$instance;

        // Tabla de equipos — catálogo de las 48 selecciones
        $db->exec("
            CREATE TABLE IF NOT EXISTS teams (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id   INTEGER,               -- ID en football-data.org
                name          TEXT NOT NULL UNIQUE,
                name_es       TEXT,                  -- Nombre en español
                name_en       TEXT,                  -- Nombre en inglés
                short_name    TEXT,
                tla           TEXT,                  -- Abreviatura 3 letras
                iso_code      TEXT,                  -- ISO 3166-1 alpha-2 para bandera
                confederation TEXT,                  -- UEFA CONMEBOL CONCACAF CAF AFC OFC
                group_name    TEXT,                  -- Grupo A–L (null hasta el sorteo)
                is_host       INTEGER DEFAULT 0      -- 1 = sede
            )
        ");

        // Partidos con marcadores y horario UTC
        $db->exec("
            CREATE TABLE IF NOT EXISTS matches (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id   INTEGER UNIQUE,
                home_team_id  INTEGER NOT NULL,
                away_team_id  INTEGER NOT NULL,
                home_score    INTEGER,               -- NULL = no jugado aún
                away_score    INTEGER,
                home_score_ht INTEGER,               -- Marcador al descanso
                away_score_ht INTEGER,
                match_date    TEXT NOT NULL,         -- ISO 8601 en UTC  2026-06-11T18:00:00Z
                status        TEXT DEFAULT 'SCHEDULED',
                stage         TEXT,                  -- GROUP_STAGE ROUND_OF_32 etc.
                group_name    TEXT,                  -- Grupo A–L (null en rondas knockout)
                matchday      INTEGER,
                venue         TEXT,
                city          TEXT,
                last_updated  TEXT,
                FOREIGN KEY (home_team_id) REFERENCES teams(id),
                FOREIGN KEY (away_team_id) REFERENCES teams(id)
            )
        ");

        // Tabla de posiciones por grupo
        $db->exec("
            CREATE TABLE IF NOT EXISTS standings (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                team_id        INTEGER NOT NULL,
                group_name     TEXT    NOT NULL,
                position       INTEGER DEFAULT 1,
                played         INTEGER DEFAULT 0,
                won            INTEGER DEFAULT 0,
                drawn          INTEGER DEFAULT 0,
                lost           INTEGER DEFAULT 0,
                goals_for      INTEGER DEFAULT 0,
                goals_against  INTEGER DEFAULT 0,
                goal_difference INTEGER DEFAULT 0,
                points         INTEGER DEFAULT 0,
                UNIQUE(team_id, group_name),
                FOREIGN KEY (team_id) REFERENCES teams(id)
            )
        ");

        // Pares clave-valor para metadatos (última actualización, versión API, etc.)
        $db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key        TEXT PRIMARY KEY,
                value      TEXT,
                updated_at TEXT
            )
        ");
    }

    // ── Seed de Equipos ───────────────────────────────────

    /**
     * Inserta las 48 selecciones clasificadas si la tabla está vacía.
     * Los códigos ISO se usan para mostrar la bandera vía flagcdn.com.
     * El grupo real lo sincroniza la API; aquí queda en NULL hasta entonces.
     */
    private static function seedTeams(): void {
        $db = self::$instance;
        $count = $db->query("SELECT COUNT(*) FROM teams")->fetchColumn();
        if ($count > 0) return;   // Ya sembrado

        $teams = [
            // ── CONCACAF (Sedes) ──────────────────────────
            ['United States',  'Estados Unidos', 'United States',  'USA',         'USA', 'us', 'CONCACAF', 1],
            ['Mexico',         'México',         'Mexico',         'Mexico',      'MEX', 'mx', 'CONCACAF', 1],
            ['Canada',         'Canadá',         'Canada',         'Canada',      'CAN', 'ca', 'CONCACAF', 1],
            // ── CONCACAF (Clasificados) ───────────────────
            ['Panama',         'Panamá',         'Panama',         'Panama',      'PAN', 'pa', 'CONCACAF', 0],
            ['Jamaica',        'Jamaica',        'Jamaica',        'Jamaica',     'JAM', 'jm', 'CONCACAF', 0],
            ['Honduras',       'Honduras',       'Honduras',       'Honduras',    'HON', 'hn', 'CONCACAF', 0],
            // ── UEFA ──────────────────────────────────────
            ['Germany',        'Alemania',       'Germany',        'Germany',     'GER', 'de', 'UEFA', 0],
            ['France',         'Francia',        'France',         'France',      'FRA', 'fr', 'UEFA', 0],
            ['Spain',          'España',         'Spain',          'Spain',       'ESP', 'es', 'UEFA', 0],
            ['England',        'Inglaterra',     'England',        'England',     'ENG', 'gb-eng', 'UEFA', 0],
            ['Portugal',       'Portugal',       'Portugal',       'Portugal',    'POR', 'pt', 'UEFA', 0],
            ['Netherlands',    'Países Bajos',   'Netherlands',    'Netherlands', 'NED', 'nl', 'UEFA', 0],
            ['Belgium',        'Bélgica',        'Belgium',        'Belgium',     'BEL', 'be', 'UEFA', 0],
            ['Italy',          'Italia',         'Italy',          'Italy',       'ITA', 'it', 'UEFA', 0],
            ['Croatia',        'Croacia',        'Croatia',        'Croatia',     'CRO', 'hr', 'UEFA', 0],
            ['Switzerland',    'Suiza',          'Switzerland',    'Switzerland', 'SUI', 'ch', 'UEFA', 0],
            ['Denmark',        'Dinamarca',      'Denmark',        'Denmark',     'DEN', 'dk', 'UEFA', 0],
            ['Austria',        'Austria',        'Austria',        'Austria',     'AUT', 'at', 'UEFA', 0],
            ['Turkey',         'Turquía',        'Turkey',         'Turkey',      'TUR', 'tr', 'UEFA', 0],
            ['Serbia',         'Serbia',         'Serbia',         'Serbia',      'SRB', 'rs', 'UEFA', 0],
            ['Scotland',       'Escocia',        'Scotland',       'Scotland',    'SCO', 'gb-sct', 'UEFA', 0],
            ['Hungary',        'Hungría',        'Hungary',        'Hungary',     'HUN', 'hu', 'UEFA', 0],
            ['Slovakia',       'Eslovaquia',     'Slovakia',       'Slovakia',    'SVK', 'sk', 'UEFA', 0],
            ['Slovenia',       'Eslovenia',      'Slovenia',       'Slovenia',    'SVN', 'si', 'UEFA', 0],
            ['Albania',        'Albania',        'Albania',        'Albania',     'ALB', 'al', 'UEFA', 0],
            ['Ukraine',        'Ucrania',        'Ukraine',        'Ukraine',     'UKR', 'ua', 'UEFA', 0],
            ['Romania',        'Rumania',        'Romania',        'Romania',     'ROU', 'ro', 'UEFA', 0],
            // ── CONMEBOL ──────────────────────────────────
            ['Argentina',      'Argentina',      'Argentina',      'Argentina',   'ARG', 'ar', 'CONMEBOL', 0],
            ['Brazil',         'Brasil',         'Brazil',         'Brazil',      'BRA', 'br', 'CONMEBOL', 0],
            ['Colombia',       'Colombia',       'Colombia',       'Colombia',    'COL', 'co', 'CONMEBOL', 0],
            ['Uruguay',        'Uruguay',        'Uruguay',        'Uruguay',     'URU', 'uy', 'CONMEBOL', 0],
            ['Ecuador',        'Ecuador',        'Ecuador',        'Ecuador',     'ECU', 'ec', 'CONMEBOL', 0],
            ['Venezuela',      'Venezuela',      'Venezuela',      'Venezuela',   'VEN', 've', 'CONMEBOL', 0],
            // ── CAF (África) ──────────────────────────────
            ['Morocco',        'Marruecos',      'Morocco',        'Morocco',     'MAR', 'ma', 'CAF', 0],
            ['Senegal',        'Senegal',        'Senegal',        'Senegal',     'SEN', 'sn', 'CAF', 0],
            ['Nigeria',        'Nigeria',        'Nigeria',        'Nigeria',     'NGA', 'ng', 'CAF', 0],
            ['Egypt',          'Egipto',         'Egypt',          'Egypt',       'EGY', 'eg', 'CAF', 0],
            ['Tunisia',        'Túnez',          'Tunisia',        'Tunisia',     'TUN', 'tn', 'CAF', 0],
            ['DR Congo',       'RD Congo',       'DR Congo',       'DR Congo',    'COD', 'cd', 'CAF', 0],
            ['South Africa',   'Sudáfrica',      'South Africa',   'S. Africa',   'RSA', 'za', 'CAF', 0],
            ['Ghana',          'Ghana',          'Ghana',          'Ghana',       'GHA', 'gh', 'CAF', 0],
            ['Cameroon',       'Camerún',        'Cameroon',       'Cameroon',    'CMR', 'cm', 'CAF', 0],
            // ── AFC (Asia / Oceanía) ──────────────────────
            ['Japan',          'Japón',          'Japan',          'Japan',       'JPN', 'jp', 'AFC', 0],
            ['South Korea',    'Corea del Sur',  'South Korea',    'S. Korea',    'KOR', 'kr', 'AFC', 0],
            ['Saudi Arabia',   'Arabia Saudita', 'Saudi Arabia',   'Saudi Arabia','KSA', 'sa', 'AFC', 0],
            ['Australia',      'Australia',      'Australia',      'Australia',   'AUS', 'au', 'AFC', 0],
            ['Iran',           'Irán',           'Iran',           'Iran',        'IRN', 'ir', 'AFC', 0],
            ['Iraq',           'Irak',           'Iraq',           'Iraq',        'IRQ', 'iq', 'AFC', 0],
            ['Jordan',         'Jordania',       'Jordan',         'Jordan',      'JOR', 'jo', 'AFC', 0],
            ['Uzbekistan',     'Uzbekistán',     'Uzbekistan',     'Uzbekistan',  'UZB', 'uz', 'AFC', 0],
            // ── OFC / Repechaje ───────────────────────────
            ['New Zealand',    'Nueva Zelanda',  'New Zealand',    'New Zealand', 'NZL', 'nz', 'OFC', 0],
        ];

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO teams
                (name, name_es, name_en, short_name, tla, iso_code, confederation, is_host)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($teams as $t) {
            $stmt->execute([$t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6], $t[7]]);
        }
    }

    // ── Seed de Partidos Demo ─────────────────────────────

    /**
     * Inserta partidos de ejemplo solo en Modo Demo (sin API Key).
     * Sirve para mostrar cómo se ve la interfaz con datos reales.
     */
    private static function seedDemoMatches(): void {
        if (!DEMO_MODE) return;

        $db = self::$instance;
        $count = $db->query("SELECT COUNT(*) FROM matches")->fetchColumn();
        if ($count > 0) return;

        // Obtener IDs de algunos equipos para armar partidos de muestra
        $teams = $db->query("SELECT id, name FROM teams ORDER BY id LIMIT 20")->fetchAll();
        if (count($teams) < 8) return;

        $byName = [];
        foreach ($teams as $t) { $byName[$t['name']] = $t['id']; }

        // Fecha actual como base (el torneo empieza hoy en el contexto del usuario)
        $today  = date('Y-m-d');
        $demos  = [
            [$byName['Mexico']       ?? 1, $byName['Ecuador']     ?? 5,  2, 0, 1, 0, $today . 'T19:00:00Z', 'FINISHED',   'GROUP_STAGE', 'A', 1, 'AT&T Stadium', 'Arlington'],
            [$byName['United States']?? 3, $byName['Argentina']   ?? 22, 1, 1, 0, 0, $today . 'T22:00:00Z', 'FINISHED',   'GROUP_STAGE', 'C', 1, 'SoFi Stadium', 'Los Angeles'],
            [$byName['Brazil']       ?? 23, $byName['Germany']    ?? 7,  null, null, null, null, $today . 'T02:00:00Z', 'SCHEDULED', 'GROUP_STAGE', 'E', 1, 'MetLife Stadium', 'New York'],
            [$byName['France']       ?? 8, $byName['Morocco']     ?? 28, null, null, null, null, $today . 'T23:00:00Z', 'SCHEDULED', 'GROUP_STAGE', 'D', 1, 'Levi\'s Stadium', 'San Francisco'],
            [$byName['Spain']        ?? 9, $byName['Japan']       ?? 37, 3, 1, 2, 0, $today . 'T19:00:00Z', 'FINISHED',   'GROUP_STAGE', 'F', 1, 'Rose Bowl', 'Pasadena'],
            [$byName['England']      ?? 10, $byName['Senegal']    ?? 29, null, null, null, null, $today . 'T21:00:00Z', 'IN_PLAY', 'GROUP_STAGE', 'G', 1, 'Arrowhead Stadium', 'Kansas City'],
            [$byName['Canada']       ?? 2, $byName['Colombia']    ?? 24, null, null, null, null, $today . 'T01:00:00Z', 'SCHEDULED', 'GROUP_STAGE', 'B', 1, 'BC Place', 'Vancouver'],
            [$byName['Netherlands']  ?? 12, $byName['Nigeria']    ?? 31, 2, 2, 1, 1, $today . 'T19:00:00Z', 'FINISHED',   'GROUP_STAGE', 'H', 1, 'Estadio Azteca', 'Mexico City'],
        ];

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO matches
                (home_team_id, away_team_id, home_score, away_score,
                 home_score_ht, away_score_ht, match_date, status,
                 stage, group_name, matchday, venue, city)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($demos as $d) {
            $stmt->execute($d);
        }

        // Standings de muestra para el Grupo A
        $db->exec("
            INSERT OR IGNORE INTO standings
                (team_id, group_name, position, played, won, drawn, lost,
                 goals_for, goals_against, goal_difference, points)
            SELECT id, 'A', 1, 1, 1, 0, 0, 2, 0, 2, 3 FROM teams WHERE name='Mexico'
        ");
        $db->exec("
            INSERT OR IGNORE INTO standings
                (team_id, group_name, position, played, won, drawn, lost,
                 goals_for, goals_against, goal_difference, points)
            SELECT id, 'A', 2, 1, 0, 0, 1, 0, 2, -2, 0 FROM teams WHERE name='Ecuador'
        ");

        self::setSetting('last_updated', date('c'));
        self::setSetting('is_demo',      '1');
    }

    // ── Settings ─────────────────────────────────────────

    /** Lee un ajuste por clave; devuelve $default si no existe */
    public static function getSetting(string $key, ?string $default = null): ?string {
        $db   = self::connect();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $row  = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    /** Guarda o actualiza un ajuste */
    public static function setSetting(string $key, string $value): void {
        $db = self::connect();
        $db->prepare("
            INSERT INTO settings (key, value, updated_at)
            VALUES (?, ?, datetime('now'))
            ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
        ")->execute([$key, $value]);
    }

    // ── Consultas de Equipos ─────────────────────────────

    /**
     * Devuelve el mapa nombre→ID para resolución rápida al importar
     * datos de la API (los nombres pueden variar ligeramente).
     */
    public static function getTeamNameIndex(): array {
        $db  = self::connect();
        $rows = $db->query("SELECT id, name, short_name, tla FROM teams")->fetchAll();
        $idx  = [];
        foreach ($rows as $r) {
            $idx[$r['name']]       = $r['id'];
            $idx[$r['short_name']] = $r['id'];
            $idx[$r['tla']]        = $r['id'];
        }
        return $idx;
    }

    /**
     * Mapa de nombre de equipo (variantes) → código ISO para banderas.
     * Se actualiza desde la API cuando los equipos tienen crests externos.
     */
    public static function getIsoCodeMap(): array {
        return [
            'United States'             => 'us',  'USA'          => 'us',
            'Mexico'                    => 'mx',
            'Canada'                    => 'ca',
            'Panama'                    => 'pa',
            'Jamaica'                   => 'jm',
            'Honduras'                  => 'hn',
            'Costa Rica'                => 'cr',
            'Trinidad and Tobago'       => 'tt',
            'El Salvador'               => 'sv',
            'Germany'                   => 'de',
            'France'                    => 'fr',
            'Spain'                     => 'es',
            'England'                   => 'gb-eng',
            'Portugal'                  => 'pt',
            'Netherlands'               => 'nl',
            'Belgium'                   => 'be',
            'Italy'                     => 'it',
            'Croatia'                   => 'hr',
            'Switzerland'               => 'ch',
            'Denmark'                   => 'dk',
            'Austria'                   => 'at',
            'Turkey'                    => 'tr',
            'Serbia'                    => 'rs',
            'Scotland'                  => 'gb-sct',
            'Hungary'                   => 'hu',
            'Czech Republic'            => 'cz',  'Czechia'        => 'cz',
            'Slovakia'                  => 'sk',
            'Slovenia'                  => 'si',
            'Albania'                   => 'al',
            'Ukraine'                   => 'ua',
            'Romania'                   => 'ro',
            'Poland'                    => 'pl',
            'Wales'                     => 'gb-wls',
            'Northern Ireland'          => 'gb-nir',
            'Norway'                    => 'no',
            'Sweden'                    => 'se',
            'Finland'                   => 'fi',
            'Greece'                    => 'gr',
            'Bulgaria'                  => 'bg',
            'Bosnia and Herzegovina'    => 'ba',
            'North Macedonia'           => 'mk',
            'Georgia'                   => 'ge',
            'Iceland'                   => 'is',
            'Kosovo'                    => 'xk',
            'Argentina'                 => 'ar',
            'Brazil'                    => 'br',
            'Colombia'                  => 'co',
            'Uruguay'                   => 'uy',
            'Ecuador'                   => 'ec',
            'Venezuela'                 => 've',
            'Paraguay'                  => 'py',
            'Chile'                     => 'cl',
            'Peru'                      => 'pe',  'Perú'           => 'pe',
            'Bolivia'                   => 'bo',
            'Morocco'                   => 'ma',
            'Senegal'                   => 'sn',
            'Nigeria'                   => 'ng',
            'Egypt'                     => 'eg',
            'Tunisia'                   => 'tn',
            'DR Congo'                  => 'cd',  'Congo DR'       => 'cd',
            'Democratic Republic of Congo' => 'cd',
            "Côte d'Ivoire"             => 'ci',  'Ivory Coast'    => 'ci',
            "Cote d'Ivoire"             => 'ci',
            'South Africa'              => 'za',
            'Ghana'                     => 'gh',
            'Cameroon'                  => 'cm',
            'Algeria'                   => 'dz',
            'Mali'                      => 'ml',
            'Cape Verde'                => 'cv',
            'Comoros'                   => 'km',
            'Guinea'                    => 'gn',
            'Gambia'                    => 'gm',
            'Mauritania'                => 'mr',
            'Japan'                     => 'jp',
            'South Korea'               => 'kr',  'Korea Republic' => 'kr',
            'Saudi Arabia'              => 'sa',
            'Australia'                 => 'au',
            'Iran'                      => 'ir',  'IR Iran'        => 'ir',
            'Iraq'                      => 'iq',
            'Jordan'                    => 'jo',
            'Uzbekistan'                => 'uz',
            'Qatar'                     => 'qa',
            'China'                     => 'cn',  'China PR'       => 'cn',
            'Indonesia'                 => 'id',
            'Oman'                      => 'om',
            'UAE'                       => 'ae',  'United Arab Emirates' => 'ae',
            'Bahrain'                   => 'bh',
            'India'                     => 'in',
            'Palestine'                 => 'ps',
            'Kyrgyzstan'                => 'kg',
            'Tajikistan'                => 'tj',
            'New Zealand'               => 'nz',
            'Papua New Guinea'          => 'pg',
            'Fiji'                      => 'fj',
            'Solomon Islands'           => 'sb',
        ];
    }

    // ── Upsert Equipos (desde API) ────────────────────────

    /**
     * Inserta o actualiza un equipo proveniente de la API.
     * Intenta resolver el código ISO por nombre y enriquece el registro.
     *
     * @param array $data Datos crudos del equipo (API football-data.org)
     * @return int ID interno del equipo
     */
    public static function upsertTeam(array $data): int {
        $db      = self::connect();
        $isoMap  = self::getIsoCodeMap();
        $name    = $data['name']      ?? '';
        $short   = $data['shortName'] ?? $name;
        $tla     = $data['tla']       ?? '';
        $extId   = $data['id']        ?? null;
        $group   = $data['group']     ?? null;

        // Resolver código ISO: buscar por variantes del nombre
        $iso = $isoMap[$name] ?? $isoMap[$short] ?? $isoMap[$tla] ?? null;

        // Resolver grupo desde nombre de grupo de la API (ej. "GROUP_A" → "A")
        if ($group) {
            $group = str_replace('GROUP_', '', $group);
        }

        $db->prepare("
            INSERT INTO teams (external_id, name, short_name, tla, iso_code, group_name)
            VALUES (:ext, :name, :short, :tla, :iso, :grp)
            ON CONFLICT(name) DO UPDATE SET
                external_id = COALESCE(:ext,   external_id),
                short_name  = COALESCE(:short, short_name),
                tla         = COALESCE(:tla,   tla),
                iso_code    = COALESCE(:iso,   iso_code),
                group_name  = COALESCE(:grp,   group_name)
        ")->execute([
            ':ext'   => $extId,
            ':name'  => $name,
            ':short' => $short,
            ':tla'   => $tla,
            ':iso'   => $iso,
            ':grp'   => $group,
        ]);

        $row = $db->prepare("SELECT id FROM teams WHERE name = ?")->execute([$name]);
        $row = $db->query("SELECT id FROM teams WHERE name = " . $db->quote($name))->fetchColumn();
        return (int) $row;
    }

    // ── Upsert Partidos (desde API) ───────────────────────

    /**
     * Inserta o actualiza un partido con los datos de la API.
     * La fecha/hora se guarda siempre en UTC para que el frontend
     * la convierta a la zona horaria seleccionada por el usuario.
     *
     * @param array $match  Objeto partido de la API
     * @param array $teamIdx Mapa nombre→ID para resolución rápida
     */
    public static function upsertMatch(array $match, array $teamIdx): void {
        $db = self::connect();

        $extId    = $match['id'];
        $homeName = $match['homeTeam']['name'] ?? '';
        $awayName = $match['awayTeam']['name'] ?? '';

        // Resolver IDs internos; si el equipo no existe, insertarlo
        $homeId = $teamIdx[$homeName] ?? self::upsertTeam($match['homeTeam']);
        $awayId = $teamIdx[$awayName] ?? self::upsertTeam($match['awayTeam']);

        // Extraer marcadores (pueden ser null si el partido no ha empezado)
        $homeFt  = $match['score']['fullTime']['home']   ?? null;
        $awayFt  = $match['score']['fullTime']['away']   ?? null;
        $homeHt  = $match['score']['halfTime']['home']   ?? null;
        $awayHt  = $match['score']['halfTime']['away']   ?? null;

        // Normalizar nombre de grupo: "GROUP_A" → "A"
        $grp = isset($match['group']) ? str_replace('GROUP_', '', $match['group']) : null;

        // Normalizar stage a valores amigables
        $stage = $match['stage'] ?? 'GROUP_STAGE';

        // La fecha del partido viene en ISO 8601 UTC desde la API
        $matchDate = $match['utcDate'] ?? ($match['lastUpdated'] ?? null);

        $db->prepare("
            INSERT INTO matches
                (external_id, home_team_id, away_team_id,
                 home_score, away_score, home_score_ht, away_score_ht,
                 match_date, status, stage, group_name, matchday,
                 venue, city, last_updated)
            VALUES
                (:ext, :hid, :aid,
                 :hft, :aft, :hht, :aht,
                 :date, :status, :stage, :grp, :md,
                 :venue, :city, datetime('now'))
            ON CONFLICT(external_id) DO UPDATE SET
                home_score    = excluded.home_score,
                away_score    = excluded.away_score,
                home_score_ht = excluded.home_score_ht,
                away_score_ht = excluded.away_score_ht,
                status        = excluded.status,
                group_name    = COALESCE(excluded.group_name, group_name),
                last_updated  = excluded.last_updated
        ")->execute([
            ':ext'    => $extId,
            ':hid'    => $homeId,
            ':aid'    => $awayId,
            ':hft'    => $homeFt,
            ':aft'    => $awayFt,
            ':hht'    => $homeHt,
            ':aht'    => $awayHt,
            ':date'   => $matchDate,
            ':status' => $match['status'] ?? 'SCHEDULED',
            ':stage'  => $stage,
            ':grp'    => $grp,
            ':md'     => $match['matchday'] ?? null,
            ':venue'  => $match['venue']    ?? null,
            ':city'   => null,
        ]);
    }

    // ── Upsert Posiciones ─────────────────────────────────

    /**
     * Reemplaza la tabla de posiciones de un grupo con los datos
     * que devuelve el endpoint /standings de la API.
     */
    public static function upsertStanding(array $row, string $group, array $teamIdx): void {
        $db = self::connect();

        $teamName = $row['team']['name'] ?? '';
        $teamId   = $teamIdx[$teamName]  ?? self::upsertTeam($row['team']);

        $db->prepare("
            INSERT INTO standings
                (team_id, group_name, position, played, won, drawn, lost,
                 goals_for, goals_against, goal_difference, points)
            VALUES
                (:tid, :grp, :pos, :pl, :w, :d, :l, :gf, :ga, :gd, :pts)
            ON CONFLICT(team_id, group_name) DO UPDATE SET
                position        = excluded.position,
                played          = excluded.played,
                won             = excluded.won,
                drawn           = excluded.drawn,
                lost            = excluded.lost,
                goals_for       = excluded.goals_for,
                goals_against   = excluded.goals_against,
                goal_difference = excluded.goal_difference,
                points          = excluded.points
        ")->execute([
            ':tid'  => $teamId,
            ':grp'  => $group,
            ':pos'  => $row['position']       ?? 1,
            ':pl'   => $row['playedGames']     ?? 0,
            ':w'    => $row['won']             ?? 0,
            ':d'    => $row['draw']            ?? 0,
            ':l'    => $row['lost']            ?? 0,
            ':gf'   => $row['goalsFor']        ?? 0,
            ':ga'   => $row['goalsAgainst']    ?? 0,
            ':gd'   => $row['goalDifference']  ?? 0,
            ':pts'  => $row['points']          ?? 0,
        ]);

        // Actualizar grupo en la tabla de equipos
        $db->prepare("UPDATE teams SET group_name = ? WHERE id = ?")->execute([$group, $teamId]);
    }

    // ── Consultas de Lectura ─────────────────────────────

    /**
     * Devuelve todos los partidos con datos de ambos equipos (nombre, iso, tla).
     * Se ordena por fecha ascendente para la vista de calendario.
     *
     * @param string|null $group Filtrar por grupo (A–L)
     * @param string|null $status Filtrar por estado (FINISHED, SCHEDULED, etc.)
     */
    public static function getMatches(?string $group = null, ?string $status = null): array {
        $db     = self::connect();
        $where  = [];
        $params = [];

        if ($group) {
            $where[]  = 'm.group_name = ?';
            $params[] = strtoupper($group);
        }
        if ($status) {
            $where[]  = 'm.status = ?';
            $params[] = strtoupper($status);
        }

        $sql = "
            SELECT
                m.id, m.external_id, m.match_date, m.status,
                m.stage, m.group_name, m.matchday, m.venue, m.city,
                m.home_score, m.away_score, m.home_score_ht, m.away_score_ht,
                ht.id    AS home_id,   ht.name  AS home_name,
                ht.name_es AS home_name_es, ht.name_en AS home_name_en,
                ht.tla   AS home_tla,  ht.iso_code AS home_iso,
                ht.is_host AS home_is_host,
                at.id    AS away_id,   at.name  AS away_name,
                at.name_es AS away_name_es, at.name_en AS away_name_en,
                at.tla   AS away_tla,  at.iso_code AS away_iso,
                at.is_host AS away_is_host
            FROM matches m
            JOIN teams ht ON m.home_team_id = ht.id
            JOIN teams at ON m.away_team_id = at.id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY m.match_date ASC, m.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Devuelve las posiciones de todos los grupos (o de uno específico).
     * Ordenado por grupo → posición.
     */
    public static function getStandings(?string $group = null): array {
        $db     = self::connect();
        $params = [];
        $where  = '';

        if ($group) {
            $where    = 'WHERE s.group_name = ?';
            $params[] = strtoupper($group);
        }

        $stmt = $db->prepare("
            SELECT
                s.group_name, s.position, s.played, s.won, s.drawn, s.lost,
                s.goals_for, s.goals_against, s.goal_difference, s.points,
                t.name, t.name_es, t.name_en, t.tla, t.iso_code, t.is_host
            FROM standings s
            JOIN teams t ON s.team_id = t.id
            $where
            ORDER BY s.group_name ASC, s.position ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Devuelve todos los equipos ordenados por confederación y nombre */
    public static function getAllTeams(): array {
        $db = self::connect();
        return $db->query("
            SELECT id, name, name_es, name_en, short_name, tla,
                   iso_code, confederation, group_name, is_host
            FROM teams
            ORDER BY confederation, name
        ")->fetchAll();
    }
}
