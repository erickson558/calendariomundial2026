<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Capa de Base de Datos (SQLite)
 * =========================================================
 * Compatible con PHP 5.4+ y SQLite 3.7+ (bundled en EasyPHP 14)
 *
 * Responsable de:
 *  - Crear y migrar el esquema SQLite
 *  - Poblar el catálogo inicial de equipos (seed)
 *  - Exponer metodos CRUD para partidos, tablas y ajustes
 */

require_once dirname(__FILE__) . '/config.php';

class Database {

    // Conexion singleton — sin tipo declarado (PHP 5.4 no soporta typed properties)
    private static $instance = null;

    // ── Conexion ──────────────────────────────────────────

    /**
     * Devuelve (o crea) la conexion PDO a SQLite.
     * WAL mode mejora lecturas concurrentes sin bloqueos.
     */
    public static function connect() {
        if (self::$instance === null) {
            $dir = dirname(DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$instance = new PDO('sqlite:' . DB_PATH);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
            self::createSchema();
            self::seedTeams();
            self::fixSpanishNames();   // Corrige tildes en DBs existentes
            self::seedDemoMatches();
        }
        return self::$instance;
    }

    // ── Esquema ───────────────────────────────────────────

    private static function createSchema() {
        $db = self::$instance;

        // Catalogo de las 48 selecciones
        $db->exec("
            CREATE TABLE IF NOT EXISTS teams (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id   INTEGER,
                name          TEXT NOT NULL UNIQUE,
                name_es       TEXT,
                name_en       TEXT,
                short_name    TEXT,
                tla           TEXT,
                iso_code      TEXT,
                confederation TEXT,
                group_name    TEXT,
                is_host       INTEGER DEFAULT 0
            )
        ");

        // Partidos con marcadores y horario UTC
        $db->exec("
            CREATE TABLE IF NOT EXISTS matches (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id   INTEGER UNIQUE,
                home_team_id  INTEGER NOT NULL,
                away_team_id  INTEGER NOT NULL,
                home_score    INTEGER,
                away_score    INTEGER,
                home_score_ht INTEGER,
                away_score_ht INTEGER,
                match_date    TEXT NOT NULL,
                status        TEXT DEFAULT 'SCHEDULED',
                stage         TEXT,
                group_name    TEXT,
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

        // Pares clave-valor para metadatos de la app
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
     * Inserta las 48 selecciones si la tabla esta vacia.
     * INSERT OR IGNORE garantiza que no se dupliquen en recargas.
     */
    private static function seedTeams() {
        $db    = self::$instance;
        $count = $db->query("SELECT COUNT(*) FROM teams")->fetchColumn();
        if ($count > 0) return;

        // [name, name_es, name_en, short_name, tla, iso_code, confederation, is_host]
        $teams = array(
            array('United States',  'Estados Unidos', 'United States', 'USA',       'USA','us',     'CONCACAF',1),
            array('Mexico',         'México',         'Mexico',        'México',    'MEX','mx',     'CONCACAF',1),
            array('Canada',         'Canadá',         'Canada',        'Canada',    'CAN','ca',     'CONCACAF',1),
            array('Panama',         'Panamá',         'Panama',        'Panama',    'PAN','pa',     'CONCACAF',0),
            array('Jamaica',        'Jamaica',        'Jamaica',       'Jamaica',   'JAM','jm',     'CONCACAF',0),
            array('Honduras',       'Honduras',       'Honduras',      'Honduras',  'HON','hn',     'CONCACAF',0),
            array('Germany',        'Alemania',       'Germany',       'Germany',   'GER','de',     'UEFA',0),
            array('France',         'Francia',        'France',        'France',    'FRA','fr',     'UEFA',0),
            array('Spain',          'España',         'Spain',         'Spain',     'ESP','es',     'UEFA',0),
            array('England',        'Inglaterra',     'England',       'England',   'ENG','gb-eng', 'UEFA',0),
            array('Portugal',       'Portugal',       'Portugal',      'Portugal',  'POR','pt',     'UEFA',0),
            array('Netherlands',    'Países Bajos',   'Netherlands',   'Netherlands','NED','nl',    'UEFA',0),
            array('Belgium',        'Bélgica',        'Belgium',       'Belgium',   'BEL','be',     'UEFA',0),
            array('Italy',          'Italia',         'Italy',         'Italy',     'ITA','it',     'UEFA',0),
            array('Croatia',        'Croacia',        'Croatia',       'Croatia',   'CRO','hr',     'UEFA',0),
            array('Switzerland',    'Suiza',          'Switzerland',   'Switzerland','SUI','ch',    'UEFA',0),
            array('Denmark',        'Dinamarca',      'Denmark',       'Denmark',   'DEN','dk',     'UEFA',0),
            array('Austria',        'Austria',        'Austria',       'Austria',   'AUT','at',     'UEFA',0),
            array('Turkey',         'Turquía',        'Turkey',        'Turkey',    'TUR','tr',     'UEFA',0),
            array('Serbia',         'Serbia',         'Serbia',        'Serbia',    'SRB','rs',     'UEFA',0),
            array('Scotland',       'Escocia',        'Scotland',      'Scotland',  'SCO','gb-sct', 'UEFA',0),
            array('Hungary',        'Hungría',        'Hungary',       'Hungary',   'HUN','hu',     'UEFA',0),
            array('Slovakia',       'Eslovaquia',     'Slovakia',      'Slovakia',  'SVK','sk',     'UEFA',0),
            array('Slovenia',       'Eslovenia',      'Slovenia',      'Slovenia',  'SVN','si',     'UEFA',0),
            array('Albania',        'Albania',        'Albania',       'Albania',   'ALB','al',     'UEFA',0),
            array('Ukraine',        'Ucrania',        'Ukraine',       'Ukraine',   'UKR','ua',     'UEFA',0),
            array('Romania',        'Rumania',        'Romania',       'Romania',   'ROU','ro',     'UEFA',0),
            array('Argentina',      'Argentina',      'Argentina',     'Argentina', 'ARG','ar',     'CONMEBOL',0),
            array('Brazil',         'Brasil',         'Brazil',        'Brazil',    'BRA','br',     'CONMEBOL',0),
            array('Colombia',       'Colombia',       'Colombia',      'Colombia',  'COL','co',     'CONMEBOL',0),
            array('Uruguay',        'Uruguay',        'Uruguay',       'Uruguay',   'URU','uy',     'CONMEBOL',0),
            array('Ecuador',        'Ecuador',        'Ecuador',       'Ecuador',   'ECU','ec',     'CONMEBOL',0),
            array('Venezuela',      'Venezuela',      'Venezuela',     'Venezuela', 'VEN','ve',     'CONMEBOL',0),
            array('Morocco',        'Marruecos',      'Morocco',       'Morocco',   'MAR','ma',     'CAF',0),
            array('Senegal',        'Senegal',        'Senegal',       'Senegal',   'SEN','sn',     'CAF',0),
            array('Nigeria',        'Nigeria',        'Nigeria',       'Nigeria',   'NGA','ng',     'CAF',0),
            array('Egypt',          'Egipto',         'Egypt',         'Egypt',     'EGY','eg',     'CAF',0),
            array('Tunisia',        'Túnez',          'Tunisia',       'Tunisia',   'TUN','tn',     'CAF',0),
            array('DR Congo',       'RD Congo',       'DR Congo',      'DR Congo',  'COD','cd',     'CAF',0),
            array('South Africa',   'Sudáfrica',      'South Africa',  'S. Africa', 'RSA','za',     'CAF',0),
            array('Ghana',          'Ghana',          'Ghana',         'Ghana',     'GHA','gh',     'CAF',0),
            array('Cameroon',       'Camerún',        'Cameroon',      'Cameroon',  'CMR','cm',     'CAF',0),
            array('Japan',          'Japón',          'Japan',         'Japan',     'JPN','jp',     'AFC',0),
            array('South Korea',    'Corea del Sur',  'South Korea',   'S. Korea',  'KOR','kr',     'AFC',0),
            array('Saudi Arabia',   'Arabia Saudita', 'Saudi Arabia',  'Saudi Arabia','KSA','sa',  'AFC',0),
            array('Australia',      'Australia',      'Australia',     'Australia', 'AUS','au',     'AFC',0),
            array('Iran',           'Irán',           'Iran',          'Iran',      'IRN','ir',     'AFC',0),
            array('Iraq',           'Irak',           'Iraq',          'Iraq',      'IRQ','iq',     'AFC',0),
            array('Jordan',         'Jordania',       'Jordan',        'Jordan',    'JOR','jo',     'AFC',0),
            array('Uzbekistan',     'Uzbekistán',     'Uzbekistan',    'Uzbekistan','UZB','uz',     'AFC',0),
            array('New Zealand',    'Nueva Zelanda',  'New Zealand',   'New Zealand','NZL','nz',    'OFC',0),
        );

        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO teams (name, name_es, name_en, short_name, tla, iso_code, confederation, is_host)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($teams as $t) {
            $stmt->execute($t);
        }
    }

    // ── Seed de Partidos Demo ─────────────────────────────

    /**
     * Inserta partidos de muestra como fallback cuando ESPN
     * no esta disponible (sin internet o torneo no en API aun).
     * Solo se ejecuta si la tabla de partidos esta vacia.
     */
    private static function seedDemoMatches() {
        $db    = self::$instance;
        $count = $db->query("SELECT COUNT(*) FROM matches")->fetchColumn();
        if ($count > 0) return;

        $teams = $db->query("SELECT id, name FROM teams ORDER BY id LIMIT 20")->fetchAll();
        if (count($teams) < 8) return;

        $byName = array();
        foreach ($teams as $t) { $byName[$t['name']] = $t['id']; }

        $today = date('Y-m-d');
        $demos = array(
            array(isset($byName['Mexico'])?$byName['Mexico']:1,        isset($byName['Ecuador'])?$byName['Ecuador']:5,
                  2, 0, 1, 0, $today.'T19:00:00Z','FINISHED',   'GROUP_STAGE','A',1,'AT&T Stadium','Arlington'),
            array(isset($byName['United States'])?$byName['United States']:3, isset($byName['Argentina'])?$byName['Argentina']:22,
                  1, 1, 0, 0, $today.'T22:00:00Z','FINISHED',   'GROUP_STAGE','C',1,'SoFi Stadium','Los Angeles'),
            array(isset($byName['Brazil'])?$byName['Brazil']:23,       isset($byName['Germany'])?$byName['Germany']:7,
                  null,null,null,null, $today.'T02:00:00Z','SCHEDULED','GROUP_STAGE','E',1,'MetLife Stadium','New York'),
            array(isset($byName['France'])?$byName['France']:8,        isset($byName['Morocco'])?$byName['Morocco']:28,
                  null,null,null,null, $today.'T23:00:00Z','SCHEDULED','GROUP_STAGE','D',1,"Levi's Stadium",'San Francisco'),
            array(isset($byName['Spain'])?$byName['Spain']:9,          isset($byName['Japan'])?$byName['Japan']:37,
                  3, 1, 2, 0, $today.'T19:00:00Z','FINISHED',   'GROUP_STAGE','F',1,'Rose Bowl','Pasadena'),
            array(isset($byName['England'])?$byName['England']:10,     isset($byName['Senegal'])?$byName['Senegal']:29,
                  null,null,null,null, $today.'T21:00:00Z','IN_PLAY','GROUP_STAGE','G',1,'Arrowhead Stadium','Kansas City'),
            array(isset($byName['Canada'])?$byName['Canada']:2,        isset($byName['Colombia'])?$byName['Colombia']:24,
                  null,null,null,null, $today.'T01:00:00Z','SCHEDULED','GROUP_STAGE','B',1,'BC Place','Vancouver'),
            array(isset($byName['Netherlands'])?$byName['Netherlands']:12, isset($byName['Nigeria'])?$byName['Nigeria']:31,
                  2, 2, 1, 1, $today.'T19:00:00Z','FINISHED',   'GROUP_STAGE','H',1,'Estadio Azteca','Mexico City'),
        );

        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO matches
                (home_team_id, away_team_id, home_score, away_score,
                 home_score_ht, away_score_ht, match_date, status,
                 stage, group_name, matchday, venue, city)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($demos as $d) { $stmt->execute($d); }

        // Standings de muestra para Grupo A
        $mexId = isset($byName['Mexico']) ? $byName['Mexico'] : 1;
        $ecuId = isset($byName['Ecuador']) ? $byName['Ecuador'] : 5;
        $stmtSt = $db->prepare(
            "INSERT OR IGNORE INTO standings
                (team_id,group_name,position,played,won,drawn,lost,goals_for,goals_against,goal_difference,points)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmtSt->execute(array($mexId,'A',1,1,1,0,0,2,0, 2,3));
        $stmtSt->execute(array($ecuId,'A',2,1,0,0,1,0,2,-2,0));

        // NO setear last_updated aqui: dejarlo vacio permite que get-data.php
        // detecte "primera carga" y llame ESPN automaticamente en la primer visita.
        self::setSetting('is_demo', '1');
    }

    // ── Migracion de nombres ──────────────────────────────

    /**
     * Corrige tildes y caracteres especiales en los nombres en espanol.
     * Se llama en cada boot para reparar tambien DBs existentes sin tener
     * que borrar y recrear la base de datos.
     * El UPDATE es idempotente (no hace nada si ya esta corregido).
     */
    private static function fixSpanishNames() {
        $db = self::$instance;
        $fixes = array(
            'Espana'       => 'España',
            'Mexico'       => 'México',
            'Canada'       => 'Canadá',
            'Panama'       => 'Panamá',
            'Belgica'      => 'Bélgica',
            'Paises Bajos' => 'Países Bajos',
            'Turquia'      => 'Turquía',
            'Hungria'      => 'Hungría',
            'Tunez'        => 'Túnez',
            'Sudafrica'    => 'Sudáfrica',
            'Camerun'      => 'Camerún',
            'Japon'        => 'Japón',
            'Iran'         => 'Irán',
            'Uzbekistan'   => 'Uzbekistán',
        );
        $stmt = $db->prepare("UPDATE teams SET name_es = ? WHERE name_es = ?");
        foreach ($fixes as $wrong => $correct) {
            $stmt->execute(array($correct, $wrong));
        }
    }

    // ── Settings ─────────────────────────────────────────

    /** Lee un ajuste por clave; devuelve $default si no existe */
    public static function getSetting($key, $default = null) {
        $db   = self::connect();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute(array($key));
        $row  = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    /**
     * Guarda o actualiza un ajuste.
     * INSERT OR REPLACE es compatible con SQLite 3.7+ (bundled en PHP 5.4).
     * Es seguro para key-value porque queremos sobreescribir siempre.
     */
    public static function setSetting($key, $value) {
        $db = self::connect();
        $db->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        )->execute(array($key, $value));
    }

    // ── Consultas de Equipos ─────────────────────────────

    /** Mapa nombre/tla/short_name → id para resolver FKs al importar de ESPN */
    public static function getTeamNameIndex() {
        $db   = self::connect();
        $rows = $db->query("SELECT id, name, short_name, tla FROM teams")->fetchAll();
        $idx  = array();
        foreach ($rows as $r) {
            $idx[$r['name']]       = $r['id'];
            if ($r['short_name']) $idx[$r['short_name']] = $r['id'];
            if ($r['tla'])        $idx[$r['tla']]        = $r['id'];
        }
        return $idx;
    }

    /**
     * Mapa de nombre de equipo → codigo ISO para banderas via flagcdn.com.
     * Incluye variantes de nombre que ESPN puede usar.
     */
    public static function getIsoCodeMap() {
        return array(
            'United States' => 'us', 'USA' => 'us', 'US' => 'us',
            'Mexico' => 'mx',    'Canada' => 'ca',
            'Panama' => 'pa',    'Jamaica' => 'jm',   'Honduras' => 'hn',
            'Costa Rica' => 'cr','Trinidad and Tobago' => 'tt',
            'El Salvador' => 'sv','Guatemala' => 'gt',
            'Germany' => 'de',   'France' => 'fr',    'Spain' => 'es',
            'England' => 'gb-eng','Portugal' => 'pt', 'Netherlands' => 'nl',
            'Belgium' => 'be',   'Italy' => 'it',     'Croatia' => 'hr',
            'Switzerland' => 'ch','Denmark' => 'dk',  'Austria' => 'at',
            'Turkey' => 'tr',    'Serbia' => 'rs',    'Scotland' => 'gb-sct',
            'Hungary' => 'hu',   'Czech Republic' => 'cz', 'Czechia' => 'cz',
            'Slovakia' => 'sk',  'Slovenia' => 'si',  'Albania' => 'al',
            'Ukraine' => 'ua',   'Romania' => 'ro',   'Poland' => 'pl',
            'Wales' => 'gb-wls', 'Northern Ireland' => 'gb-nir',
            'Norway' => 'no',    'Sweden' => 'se',    'Finland' => 'fi',
            'Greece' => 'gr',    'Bulgaria' => 'bg',
            'Bosnia and Herzegovina' => 'ba','North Macedonia' => 'mk',
            'Georgia' => 'ge',   'Iceland' => 'is',   'Kosovo' => 'xk',
            'Argentina' => 'ar', 'Brazil' => 'br',    'Colombia' => 'co',
            'Uruguay' => 'uy',   'Ecuador' => 'ec',   'Venezuela' => 've',
            'Paraguay' => 'py',  'Chile' => 'cl',     'Peru' => 'pe',
            'Bolivia' => 'bo',
            'Morocco' => 'ma',   'Senegal' => 'sn',   'Nigeria' => 'ng',
            'Egypt' => 'eg',     'Tunisia' => 'tn',   'DR Congo' => 'cd',
            'Congo DR' => 'cd',  'Democratic Republic of Congo' => 'cd',
            'Cote d\'Ivoire' => 'ci', 'Ivory Coast' => 'ci',
            'South Africa' => 'za','Ghana' => 'gh',   'Cameroon' => 'cm',
            'Algeria' => 'dz',   'Mali' => 'ml',      'Cape Verde' => 'cv',
            'Japan' => 'jp',     'South Korea' => 'kr','Korea Republic' => 'kr',
            'Saudi Arabia' => 'sa','Australia' => 'au','Iran' => 'ir',
            'IR Iran' => 'ir',   'Iraq' => 'iq',      'Jordan' => 'jo',
            'Uzbekistan' => 'uz','Qatar' => 'qa',     'China PR' => 'cn',
            'China' => 'cn',     'Indonesia' => 'id', 'UAE' => 'ae',
            'United Arab Emirates' => 'ae','Oman' => 'om','Bahrain' => 'bh',
            'New Zealand' => 'nz','Papua New Guinea' => 'pg','Fiji' => 'fj',
        );
    }

    // ── Upsert Equipos (desde ESPN) ───────────────────────

    /**
     * Inserta o actualiza un equipo desde ESPN.
     * Usa SELECT + UPDATE/INSERT porque old SQLite no tiene UPSERT.
     * Solo actualiza campos que ESPN provee; preserva name_es, name_en,
     * confederation e is_host que vienen del seed local.
     */
    public static function upsertTeam($data) {
        $db     = self::connect();
        $isoMap = self::getIsoCodeMap();

        $name  = isset($data['name'])      ? $data['name']      : '';
        $short = isset($data['shortName']) ? $data['shortName'] : $name;
        $tla   = isset($data['tla'])       ? strtoupper($data['tla']) : '';
        $extId = isset($data['id'])        ? $data['id']        : null;
        $group = isset($data['group'])     ? str_replace('GROUP_', '', $data['group']) : null;

        // Resolver ISO: buscar por variantes del nombre
        $iso = null;
        if (isset($isoMap[$name]))  $iso = $isoMap[$name];
        elseif (isset($isoMap[$short])) $iso = $isoMap[$short];
        elseif (isset($isoMap[$tla]))   $iso = $isoMap[$tla];

        // Comprobar si el equipo ya existe por nombre
        $stmt = $db->prepare("SELECT id FROM teams WHERE name = ?");
        $stmt->execute(array($name));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Actualizar solo los campos que trae ESPN; preservar el resto
            $db->prepare(
                "UPDATE teams SET
                    external_id = COALESCE(?, external_id),
                    short_name  = COALESCE(?, short_name),
                    tla         = COALESCE(?, tla),
                    iso_code    = COALESCE(?, iso_code),
                    group_name  = COALESCE(?, group_name)
                 WHERE id = ?"
            )->execute(array($extId, $short, $tla, $iso, $group, $existing['id']));
            return (int) $existing['id'];
        }

        // Insertar nuevo equipo (vendrá de la API con nombre distinto al seed)
        $db->prepare(
            "INSERT INTO teams (external_id, name, short_name, tla, iso_code, group_name)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute(array($extId, $name, $short, $tla, $iso, $group));
        return (int) $db->lastInsertId();
    }

    // ── Upsert Partidos ───────────────────────────────────

    /**
     * Inserta o actualiza un partido.
     * La fecha/hora siempre se guarda en UTC para que el frontend
     * la convierta a la zona del usuario con Intl.DateTimeFormat.
     *
     * Usa SELECT + UPDATE/INSERT porque SQLite bundled con PHP 5.4
     * no soporta la sintaxis UPSERT (anadida en SQLite 3.24 / 2018).
     */
    public static function upsertMatch($match, $teamIdx) {
        $db = self::connect();

        $extId    = isset($match['id'])             ? $match['id']             : null;
        $homeName = isset($match['homeTeam']['name'])? $match['homeTeam']['name'] : '';
        $awayName = isset($match['awayTeam']['name'])? $match['awayTeam']['name'] : '';

        $homeId = isset($teamIdx[$homeName]) ? $teamIdx[$homeName] : self::upsertTeam($match['homeTeam']);
        $awayId = isset($teamIdx[$awayName]) ? $teamIdx[$awayName] : self::upsertTeam($match['awayTeam']);

        $homeFt = isset($match['score']['fullTime']['home']) ? $match['score']['fullTime']['home'] : null;
        $awayFt = isset($match['score']['fullTime']['away']) ? $match['score']['fullTime']['away'] : null;
        $homeHt = isset($match['score']['halfTime']['home']) ? $match['score']['halfTime']['home'] : null;
        $awayHt = isset($match['score']['halfTime']['away']) ? $match['score']['halfTime']['away'] : null;
        $grp    = isset($match['group'])    ? str_replace('GROUP_', '', $match['group']) : null;
        $stage  = isset($match['stage'])    ? $match['stage']    : 'GROUP_STAGE';
        $status = isset($match['status'])   ? $match['status']   : 'SCHEDULED';
        $md     = isset($match['matchday']) ? $match['matchday'] : null;
        $venue  = isset($match['venue'])    ? $match['venue']    : null;
        $matchDate = isset($match['utcDate'])
            ? $match['utcDate']
            : (isset($match['lastUpdated']) ? $match['lastUpdated'] : null);

        if ($extId) {
            // Comprobar si el partido ya existe por external_id
            $stmt = $db->prepare("SELECT id FROM matches WHERE external_id = ?");
            $stmt->execute(array($extId));
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Solo actualizar campos volatiles (marcador, estado)
                $db->prepare(
                    "UPDATE matches SET
                        home_score    = ?,
                        away_score    = ?,
                        home_score_ht = ?,
                        away_score_ht = ?,
                        status        = ?,
                        group_name    = COALESCE(?, group_name),
                        last_updated  = datetime('now')
                     WHERE external_id = ?"
                )->execute(array($homeFt, $awayFt, $homeHt, $awayHt, $status, $grp, $extId));
                return;
            }
        }

        // Insercion inicial del partido
        $db->prepare(
            "INSERT OR IGNORE INTO matches
                (external_id, home_team_id, away_team_id,
                 home_score, away_score, home_score_ht, away_score_ht,
                 match_date, status, stage, group_name, matchday,
                 venue, city, last_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))"
        )->execute(array(
            $extId, $homeId, $awayId,
            $homeFt, $awayFt, $homeHt, $awayHt,
            $matchDate, $status, $stage, $grp, $md,
            $venue, null
        ));
    }

    // ── Upsert Posiciones ─────────────────────────────────

    /**
     * Inserta o actualiza una posicion de grupo.
     * Misma logica SELECT + UPDATE/INSERT por compatibilidad con SQLite 3.7.
     */
    public static function upsertStanding($row, $group, $teamIdx) {
        $db = self::connect();

        $teamName = isset($row['team']['name']) ? $row['team']['name'] : '';
        $teamId   = isset($teamIdx[$teamName])  ? $teamIdx[$teamName]  : self::upsertTeam($row['team']);

        $pos = isset($row['position'])       ? (int)$row['position']       : 1;
        $pl  = isset($row['playedGames'])    ? (int)$row['playedGames']    : 0;
        $w   = isset($row['won'])            ? (int)$row['won']            : 0;
        $d   = isset($row['draw'])           ? (int)$row['draw']           : 0;
        $l   = isset($row['lost'])           ? (int)$row['lost']           : 0;
        $gf  = isset($row['goalsFor'])       ? (int)$row['goalsFor']       : 0;
        $ga  = isset($row['goalsAgainst'])   ? (int)$row['goalsAgainst']   : 0;
        $gd  = isset($row['goalDifference']) ? (int)$row['goalDifference'] : 0;
        $pts = isset($row['points'])         ? (int)$row['points']         : 0;

        $stmt = $db->prepare("SELECT id FROM standings WHERE team_id = ? AND group_name = ?");
        $stmt->execute(array($teamId, $group));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $db->prepare(
                "UPDATE standings SET
                    position=?, played=?, won=?, drawn=?, lost=?,
                    goals_for=?, goals_against=?, goal_difference=?, points=?
                 WHERE team_id=? AND group_name=?"
            )->execute(array($pos, $pl, $w, $d, $l, $gf, $ga, $gd, $pts, $teamId, $group));
        } else {
            $db->prepare(
                "INSERT INTO standings
                    (team_id, group_name, position, played, won, drawn, lost,
                     goals_for, goals_against, goal_difference, points)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute(array($teamId, $group, $pos, $pl, $w, $d, $l, $gf, $ga, $gd, $pts));
        }

        // Sincronizar grupo del equipo en la tabla teams
        $db->prepare("UPDATE teams SET group_name = ? WHERE id = ?")->execute(array($group, $teamId));
    }

    // ── Consultas de Lectura ─────────────────────────────

    /**
     * Devuelve todos los partidos con datos de ambos equipos.
     * Siempre ordenado por fecha ascendente para la vista de calendario.
     */
    public static function getMatches($group = null, $status = null) {
        $db     = self::connect();
        $where  = array();
        $params = array();

        if ($group) {
            $where[]  = 'm.group_name = ?';
            $params[] = strtoupper($group);
        }
        if ($status) {
            $where[]  = 'm.status = ?';
            $params[] = strtoupper($status);
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $db->prepare("
            SELECT
                m.id, m.external_id, m.match_date, m.status,
                m.stage, m.group_name, m.matchday, m.venue, m.city,
                m.home_score, m.away_score, m.home_score_ht, m.away_score_ht,
                ht.id AS home_id, ht.name AS home_name,
                ht.name_es AS home_name_es, ht.name_en AS home_name_en,
                ht.tla AS home_tla, ht.iso_code AS home_iso, ht.is_host AS home_is_host,
                at.id AS away_id, at.name AS away_name,
                at.name_es AS away_name_es, at.name_en AS away_name_en,
                at.tla AS away_tla, at.iso_code AS away_iso, at.is_host AS away_is_host
            FROM matches m
            JOIN teams ht ON m.home_team_id = ht.id
            JOIN teams at ON m.away_team_id = at.id
            $whereClause
            ORDER BY m.match_date ASC, m.id ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Posiciones de todos los grupos o de uno especifico */
    public static function getStandings($group = null) {
        $db     = self::connect();
        $params = array();
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

    /** Todos los equipos ordenados por confederacion */
    public static function getAllTeams() {
        $db = self::connect();
        return $db->query(
            "SELECT id, name, name_es, name_en, short_name, tla,
                    iso_code, confederation, group_name, is_host
             FROM teams ORDER BY confederation, name"
        )->fetchAll();
    }
}
