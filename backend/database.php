<?php
/**
 * =========================================================
 * FIFA World Cup 2026 — Capa de Base de Datos (SQLite)
 * =========================================================
 * Compatible con PHP 5.4+ y SQLite 3.7+ (bundled en EasyPHP 14)
 *
 * Responsable de:
 *  - Crear y migrar el esquema SQLite
 *  - Poblar los 48 equipos clasificados con sus grupos reales
 *  - Sembrar los 72 partidos de fase de grupos con horarios UTC reales
 *  - Sembrar standings iniciales (todos en 0) para cada grupo
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
            self::seedMatches();
            self::seedStandings();
        }
        return self::$instance;
    }

    // ── Esquema ───────────────────────────────────────────

    private static function createSchema() {
        $db = self::$instance;

        // Catalogo de las 48 selecciones clasificadas
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
     * Inserta las 48 selecciones clasificadas a FIFA World Cup 2026.
     * Incluye grupo correcto (A-L) verificado con fuentes oficiales.
     * INSERT OR IGNORE garantiza idempotencia en recargas.
     */
    private static function seedTeams() {
        $db    = self::$instance;
        $count = $db->query("SELECT COUNT(*) FROM teams")->fetchColumn();
        if ($count > 0) return;

        // [name, name_es, name_en, short_name, tla, iso_code, confederation, is_host, group_name]
        $teams = array(
            // GRUPO A
            array('Mexico',                  'Mexico',              'Mexico',               'Mexico',     'MEX','mx',     'CONCACAF', 1, 'A'),
            array('South Korea',             'Corea del Sur',       'South Korea',          'S. Korea',   'KOR','kr',     'AFC',      0, 'A'),
            array('Czechia',                 'Chequia',             'Czechia',              'Chequia',    'CZE','cz',     'UEFA',     0, 'A'),
            array('South Africa',            'Sudafrica',           'South Africa',         'S. Africa',  'RSA','za',     'CAF',      0, 'A'),
            // GRUPO B
            array('Canada',                  'Canada',              'Canada',               'Canada',     'CAN','ca',     'CONCACAF', 1, 'B'),
            array('Bosnia and Herzegovina',  'Bosnia y Herzegovina','Bosnia and Herzegovina','Bosnia',    'BIH','ba',     'UEFA',     0, 'B'),
            array('Qatar',                   'Qatar',               'Qatar',                'Qatar',      'QAT','qa',     'AFC',      0, 'B'),
            array('Switzerland',             'Suiza',               'Switzerland',          'Suiza',      'SUI','ch',     'UEFA',     0, 'B'),
            // GRUPO C
            array('Brazil',                  'Brasil',              'Brazil',               'Brasil',     'BRA','br',     'CONMEBOL', 0, 'C'),
            array('Morocco',                 'Marruecos',           'Morocco',              'Marruecos',  'MAR','ma',     'CAF',      0, 'C'),
            array('Haiti',                   'Haiti',               'Haiti',                'Haiti',      'HAI','ht',     'CONCACAF', 0, 'C'),
            array('Scotland',                'Escocia',             'Scotland',             'Escocia',    'SCO','gb-sct', 'UEFA',     0, 'C'),
            // GRUPO D
            array('United States',           'Estados Unidos',      'United States',        'USA',        'USA','us',     'CONCACAF', 1, 'D'),
            array('Paraguay',                'Paraguay',            'Paraguay',             'Paraguay',   'PAR','py',     'CONMEBOL', 0, 'D'),
            array('Australia',               'Australia',           'Australia',            'Australia',  'AUS','au',     'AFC',      0, 'D'),
            array('Turkey',                  'Turquia',             'Turkey',               'Turquia',    'TUR','tr',     'UEFA',     0, 'D'),
            // GRUPO E
            array('Germany',                 'Alemania',            'Germany',              'Alemania',   'GER','de',     'UEFA',     0, 'E'),
            array('Ivory Coast',             'Costa de Marfil',     'Ivory Coast',          'C. Marfil',  'CIV','ci',     'CAF',      0, 'E'),
            array('Ecuador',                 'Ecuador',             'Ecuador',              'Ecuador',    'ECU','ec',     'CONMEBOL', 0, 'E'),
            array('Curacao',                 'Curazao',             'Curacao',              'Curazao',    'CUW','cw',     'CONCACAF', 0, 'E'),
            // GRUPO F
            array('Netherlands',             'Paises Bajos',        'Netherlands',          'Netherlands','NED','nl',     'UEFA',     0, 'F'),
            array('Japan',                   'Japon',               'Japan',                'Japon',      'JPN','jp',     'AFC',      0, 'F'),
            array('Sweden',                  'Suecia',              'Sweden',               'Suecia',     'SWE','se',     'UEFA',     0, 'F'),
            array('Tunisia',                 'Tunez',               'Tunisia',              'Tunez',      'TUN','tn',     'CAF',      0, 'F'),
            // GRUPO G
            array('Belgium',                 'Belgica',             'Belgium',              'Belgica',    'BEL','be',     'UEFA',     0, 'G'),
            array('Egypt',                   'Egipto',              'Egypt',                'Egipto',     'EGY','eg',     'CAF',      0, 'G'),
            array('Iran',                    'Iran',                'Iran',                 'Iran',       'IRN','ir',     'AFC',      0, 'G'),
            array('New Zealand',             'Nueva Zelanda',       'New Zealand',          'N. Zelanda', 'NZL','nz',     'OFC',      0, 'G'),
            // GRUPO H
            array('Spain',                   'Espana',              'Spain',                'Espana',     'ESP','es',     'UEFA',     0, 'H'),
            array('Saudi Arabia',            'Arabia Saudita',      'Saudi Arabia',         'Arabia S.',  'KSA','sa',     'AFC',      0, 'H'),
            array('Uruguay',                 'Uruguay',             'Uruguay',              'Uruguay',    'URU','uy',     'CONMEBOL', 0, 'H'),
            array('Cape Verde',              'Cabo Verde',          'Cape Verde',           'Cabo Verde', 'CPV','cv',     'CAF',      0, 'H'),
            // GRUPO I
            array('France',                  'Francia',             'France',               'Francia',    'FRA','fr',     'UEFA',     0, 'I'),
            array('Senegal',                 'Senegal',             'Senegal',              'Senegal',    'SEN','sn',     'CAF',      0, 'I'),
            array('Iraq',                    'Irak',                'Iraq',                 'Irak',       'IRQ','iq',     'AFC',      0, 'I'),
            array('Norway',                  'Noruega',             'Norway',               'Noruega',    'NOR','no',     'UEFA',     0, 'I'),
            // GRUPO J
            array('Argentina',               'Argentina',           'Argentina',            'Argentina',  'ARG','ar',     'CONMEBOL', 0, 'J'),
            array('Algeria',                 'Argelia',             'Algeria',              'Argelia',    'ALG','dz',     'CAF',      0, 'J'),
            array('Austria',                 'Austria',             'Austria',              'Austria',    'AUT','at',     'UEFA',     0, 'J'),
            array('Jordan',                  'Jordania',            'Jordan',               'Jordania',   'JOR','jo',     'AFC',      0, 'J'),
            // GRUPO K
            array('Portugal',                'Portugal',            'Portugal',             'Portugal',   'POR','pt',     'UEFA',     0, 'K'),
            array('Colombia',                'Colombia',            'Colombia',             'Colombia',   'COL','co',     'CONMEBOL', 0, 'K'),
            array('DR Congo',                'RD Congo',            'DR Congo',             'RD Congo',   'COD','cd',     'CAF',      0, 'K'),
            array('Uzbekistan',              'Uzbekistan',          'Uzbekistan',           'Uzbekistan', 'UZB','uz',     'AFC',      0, 'K'),
            // GRUPO L
            array('England',                 'Inglaterra',          'England',              'Inglaterra', 'ENG','gb-eng', 'UEFA',     0, 'L'),
            array('Croatia',                 'Croacia',             'Croatia',              'Croacia',    'CRO','hr',     'UEFA',     0, 'L'),
            array('Ghana',                   'Ghana',               'Ghana',                'Ghana',      'GHA','gh',     'CAF',      0, 'L'),
            array('Panama',                  'Panama',              'Panama',               'Panama',     'PAN','pa',     'CONCACAF', 0, 'L'),
        );

        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO teams
                (name, name_es, name_en, short_name, tla, iso_code, confederation, is_host, group_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($teams as $t) {
            $stmt->execute($t);
        }

        // Aplicar tildes y caracteres especiales despues de insertar
        self::fixSpanishNames();
    }

    // ── Migracion de nombres ──────────────────────────────

    /**
     * Aplica tildes y caracteres UTF-8 correctos en name_es.
     * Se llama despues del seed para no depender de la codificacion
     * del archivo PHP (que puede variar entre editores).
     * El UPDATE es idempotente.
     */
    private static function fixSpanishNames() {
        $db = self::$instance;
        $fixes = array(
            // Equipos del Mundial 2026
            'Mexico'          => 'M&#233;xico',
            'Canada'          => 'Canad&#225;',
            'Panama'          => 'Panam&#225;',
            'Sudafrica'       => 'Sud&#225;frica',
            'Tunez'           => 'T&#250;nez',
            'Turquia'         => 'Turqu&#237;a',
            'Japon'           => 'Jap&#243;n',
            'Iran'            => 'Ir&#225;n',
            'Belgica'         => 'B&#233;lgica',
            'Paises Bajos'    => 'Pa&#237;ses Bajos',
            'Espana'          => 'Espa&#241;a',
            'Uzbekistan'      => 'Uzbekist&#225;n',
            'Haiti'           => 'Hait&#237;',
        );
        $stmt = $db->prepare("UPDATE teams SET name_es = ? WHERE name_es = ?");
        foreach ($fixes as $ascii => $correct) {
            // html_entity_decode para convertir las entidades a UTF-8
            $utf8 = html_entity_decode($correct, ENT_COMPAT, 'UTF-8');
            $stmt->execute(array($utf8, $ascii));
        }
    }

    // ── Seed de Partidos ──────────────────────────────────

    /**
     * Siembra los 72 partidos de la fase de grupos con horarios UTC reales.
     * Datos verificados contra fuentes oficiales (ESPN, FIFA, openfootball).
     * ESPN actualizara marcadores y estados encima de estos datos.
     */
    private static function seedMatches() {
        $db    = self::$instance;
        $count = $db->query("SELECT COUNT(*) FROM matches")->fetchColumn();
        if ($count > 0) return;

        // Construir mapa de nombre → id
        $byName = array();
        $rows   = $db->query("SELECT id, name FROM teams")->fetchAll();
        foreach ($rows as $r) { $byName[$r['name']] = $r['id']; }

        // [group, matchday, utcDate, home_name, away_name, venue, city]
        $fixtures = array(
            // ── JORNADA 1 (June 11-18) ───────────────────
            array('A',1,'2026-06-11T19:00:00Z','Mexico',                 'South Africa',           'Estadio Azteca',            'Mexico City'),
            array('A',1,'2026-06-12T02:00:00Z','South Korea',            'Czechia',                'Estadio Akron',             'Guadalajara'),
            array('B',1,'2026-06-12T19:00:00Z','Canada',                 'Bosnia and Herzegovina', 'BMO Field',                 'Toronto'),
            array('D',1,'2026-06-13T01:00:00Z','United States',          'Paraguay',               'SoFi Stadium',              'Inglewood'),
            array('B',1,'2026-06-13T19:00:00Z','Qatar',                  'Switzerland',            "Levi's Stadium",            'Santa Clara'),
            array('C',1,'2026-06-13T22:00:00Z','Brazil',                 'Morocco',                'MetLife Stadium',           'East Rutherford'),
            array('C',1,'2026-06-14T01:00:00Z','Haiti',                  'Scotland',               'Gillette Stadium',          'Foxborough'),
            array('D',1,'2026-06-14T04:00:00Z','Australia',              'Turkey',                 'BC Place',                  'Vancouver'),
            array('E',1,'2026-06-14T17:00:00Z','Germany',                'Curacao',                'NRG Stadium',               'Houston'),
            array('F',1,'2026-06-14T20:00:00Z','Netherlands',            'Japan',                  'AT&T Stadium',              'Arlington'),
            array('E',1,'2026-06-14T23:00:00Z','Ivory Coast',            'Ecuador',                'Lincoln Financial Field',   'Philadelphia'),
            array('F',1,'2026-06-15T02:00:00Z','Sweden',                 'Tunisia',                'Estadio BBVA',              'Monterrey'),
            array('H',1,'2026-06-15T16:00:00Z','Spain',                  'Cape Verde',             'Mercedes-Benz Stadium',     'Atlanta'),
            array('G',1,'2026-06-15T19:00:00Z','Belgium',                'Egypt',                  'Lumen Field',               'Seattle'),
            array('H',1,'2026-06-15T22:00:00Z','Saudi Arabia',           'Uruguay',                'Hard Rock Stadium',         'Miami Gardens'),
            array('G',1,'2026-06-16T01:00:00Z','Iran',                   'New Zealand',            'SoFi Stadium',              'Inglewood'),
            array('I',1,'2026-06-16T19:00:00Z','France',                 'Senegal',                'MetLife Stadium',           'East Rutherford'),
            array('I',1,'2026-06-16T22:00:00Z','Iraq',                   'Norway',                 'Gillette Stadium',          'Foxborough'),
            array('J',1,'2026-06-17T01:00:00Z','Argentina',              'Algeria',                'GEHA Field at Arrowhead',   'Kansas City'),
            array('J',1,'2026-06-17T04:00:00Z','Austria',                'Jordan',                 "Levi's Stadium",            'Santa Clara'),
            array('K',1,'2026-06-17T17:00:00Z','Portugal',               'DR Congo',               'NRG Stadium',               'Houston'),
            array('L',1,'2026-06-17T20:00:00Z','England',                'Croatia',                'AT&T Stadium',              'Arlington'),
            array('L',1,'2026-06-17T23:00:00Z','Ghana',                  'Panama',                 'BMO Field',                 'Toronto'),
            array('K',1,'2026-06-18T02:00:00Z','Uzbekistan',             'Colombia',               'Estadio Azteca',            'Mexico City'),
            // ── JORNADA 2 (June 18-24) ───────────────────
            array('A',2,'2026-06-18T16:00:00Z','Czechia',                'South Africa',           'Mercedes-Benz Stadium',     'Atlanta'),
            array('B',2,'2026-06-18T19:00:00Z','Switzerland',            'Bosnia and Herzegovina', 'SoFi Stadium',              'Inglewood'),
            array('B',2,'2026-06-18T22:00:00Z','Canada',                 'Qatar',                  'BC Place',                  'Vancouver'),
            array('A',2,'2026-06-19T00:00:00Z','Mexico',                 'South Korea',            'Estadio Akron',             'Guadalajara'),
            array('D',2,'2026-06-19T19:00:00Z','United States',          'Australia',              'Lumen Field',               'Seattle'),
            array('C',2,'2026-06-19T22:00:00Z','Scotland',               'Morocco',                'Gillette Stadium',          'Foxborough'),
            array('C',2,'2026-06-20T01:30:00Z','Brazil',                 'Haiti',                  'Lincoln Financial Field',   'Philadelphia'),
            array('D',2,'2026-06-20T03:00:00Z','Turkey',                 'Paraguay',               "Levi's Stadium",            'Santa Clara'),
            array('F',2,'2026-06-20T17:00:00Z','Netherlands',            'Sweden',                 'NRG Stadium',               'Houston'),
            array('E',2,'2026-06-20T20:00:00Z','Germany',                'Ivory Coast',            'BMO Field',                 'Toronto'),
            array('E',2,'2026-06-21T00:00:00Z','Ecuador',                'Curacao',                'GEHA Field at Arrowhead',   'Kansas City'),
            array('F',2,'2026-06-21T04:00:00Z','Tunisia',                'Japan',                  'Estadio BBVA',              'Monterrey'),
            array('H',2,'2026-06-21T16:00:00Z','Spain',                  'Saudi Arabia',           'Mercedes-Benz Stadium',     'Atlanta'),
            array('G',2,'2026-06-21T19:00:00Z','Belgium',                'Iran',                   'SoFi Stadium',              'Inglewood'),
            array('H',2,'2026-06-21T22:00:00Z','Uruguay',                'Cape Verde',             'Hard Rock Stadium',         'Miami Gardens'),
            array('G',2,'2026-06-22T01:00:00Z','New Zealand',            'Egypt',                  'BC Place',                  'Vancouver'),
            array('J',2,'2026-06-22T17:00:00Z','Argentina',              'Austria',                'AT&T Stadium',              'Arlington'),
            array('I',2,'2026-06-22T21:00:00Z','France',                 'Iraq',                   'Lincoln Financial Field',   'Philadelphia'),
            array('I',2,'2026-06-23T00:00:00Z','Norway',                 'Senegal',                'MetLife Stadium',           'East Rutherford'),
            array('J',2,'2026-06-23T03:00:00Z','Jordan',                 'Algeria',                "Levi's Stadium",            'Santa Clara'),
            array('K',2,'2026-06-23T17:00:00Z','Portugal',               'Uzbekistan',             'NRG Stadium',               'Houston'),
            array('L',2,'2026-06-23T20:00:00Z','England',                'Ghana',                  'Gillette Stadium',          'Foxborough'),
            array('L',2,'2026-06-23T23:00:00Z','Panama',                 'Croatia',                'BMO Field',                 'Toronto'),
            array('K',2,'2026-06-24T02:00:00Z','Colombia',               'DR Congo',               'Estadio Akron',             'Guadalajara'),
            // ── JORNADA 3 (June 24-28, simultaneos por grupo) ──
            array('B',3,'2026-06-24T19:00:00Z','Switzerland',            'Canada',                 'BC Place',                  'Vancouver'),
            array('B',3,'2026-06-24T19:00:00Z','Bosnia and Herzegovina', 'Qatar',                  'Lumen Field',               'Seattle'),
            array('C',3,'2026-06-24T22:00:00Z','Scotland',               'Brazil',                 'Hard Rock Stadium',         'Miami Gardens'),
            array('C',3,'2026-06-24T22:00:00Z','Morocco',                'Haiti',                  'Mercedes-Benz Stadium',     'Atlanta'),
            array('A',3,'2026-06-25T00:00:00Z','Czechia',                'Mexico',                 'Estadio Azteca',            'Mexico City'),
            array('A',3,'2026-06-25T00:00:00Z','South Africa',           'South Korea',            'Estadio BBVA',              'Monterrey'),
            array('E',3,'2026-06-25T20:00:00Z','Ecuador',                'Germany',                'MetLife Stadium',           'East Rutherford'),
            array('E',3,'2026-06-25T20:00:00Z','Curacao',                'Ivory Coast',            'Lincoln Financial Field',   'Philadelphia'),
            array('F',3,'2026-06-25T23:00:00Z','Japan',                  'Sweden',                 'AT&T Stadium',              'Arlington'),
            array('F',3,'2026-06-25T23:00:00Z','Tunisia',                'Netherlands',            'GEHA Field at Arrowhead',   'Kansas City'),
            array('D',3,'2026-06-26T02:00:00Z','Turkey',                 'United States',          'SoFi Stadium',              'Inglewood'),
            array('D',3,'2026-06-26T02:00:00Z','Paraguay',               'Australia',              "Levi's Stadium",            'Santa Clara'),
            array('I',3,'2026-06-26T19:00:00Z','Norway',                 'France',                 'Gillette Stadium',          'Foxborough'),
            array('I',3,'2026-06-26T19:00:00Z','Senegal',                'Iraq',                   'BMO Field',                 'Toronto'),
            array('H',3,'2026-06-27T00:00:00Z','Cape Verde',             'Saudi Arabia',           'NRG Stadium',               'Houston'),
            array('H',3,'2026-06-27T00:00:00Z','Uruguay',                'Spain',                  'Estadio Akron',             'Guadalajara'),
            array('G',3,'2026-06-27T03:00:00Z','Egypt',                  'Iran',                   'Lumen Field',               'Seattle'),
            array('G',3,'2026-06-27T03:00:00Z','New Zealand',            'Belgium',                'BC Place',                  'Vancouver'),
            array('L',3,'2026-06-27T21:00:00Z','Panama',                 'England',                'MetLife Stadium',           'East Rutherford'),
            array('L',3,'2026-06-27T21:00:00Z','Croatia',                'Ghana',                  'Lincoln Financial Field',   'Philadelphia'),
            array('K',3,'2026-06-27T23:30:00Z','Colombia',               'Portugal',               'Hard Rock Stadium',         'Miami Gardens'),
            array('K',3,'2026-06-27T23:30:00Z','DR Congo',               'Uzbekistan',             'Mercedes-Benz Stadium',     'Atlanta'),
            array('J',3,'2026-06-28T02:00:00Z','Algeria',                'Austria',                'GEHA Field at Arrowhead',   'Kansas City'),
            array('J',3,'2026-06-28T02:00:00Z','Jordan',                 'Argentina',              'AT&T Stadium',              'Arlington'),
        );

        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO matches
                (home_team_id, away_team_id, match_date, status,
                 stage, group_name, matchday, venue, city)
             VALUES (?, ?, ?, 'SCHEDULED', 'GROUP_STAGE', ?, ?, ?, ?)"
        );

        foreach ($fixtures as $f) {
            $homeId = isset($byName[$f[3]]) ? $byName[$f[3]] : null;
            $awayId = isset($byName[$f[4]]) ? $byName[$f[4]] : null;
            if (!$homeId || !$awayId) {
                error_log('[DB] Equipo no encontrado en seed: ' . $f[3] . ' o ' . $f[4]);
                continue;
            }
            $stmt->execute(array($homeId, $awayId, $f[2], $f[0], $f[1], $f[5], $f[6]));
        }

        // Iniciar en modo no-demo: los datos sembrados son reales
        self::setSetting('is_demo', '0');
    }

    // ── Seed de Posiciones Iniciales ──────────────────────

    /**
     * Inserta una fila de posicion en cero para cada equipo.
     * Permite mostrar los grupos incluso antes de que ESPN tenga standings.
     * ESPN sobreescribira estos valores cuando haya partidos jugados.
     */
    private static function seedStandings() {
        $db    = self::$instance;
        $count = $db->query("SELECT COUNT(*) FROM standings")->fetchColumn();
        if ($count > 0) return;

        $rows = $db->query(
            "SELECT id, group_name FROM teams WHERE group_name IS NOT NULL ORDER BY group_name, name"
        )->fetchAll();
        if (empty($rows)) return;

        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO standings
                (team_id, group_name, position, played, won, drawn, lost,
                 goals_for, goals_against, goal_difference, points)
             VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0)"
        );

        $groupPos = array();
        foreach ($rows as $row) {
            $g = $row['group_name'];
            if (!isset($groupPos[$g])) $groupPos[$g] = 1;
            $stmt->execute(array($row['id'], $g, $groupPos[$g]));
            $groupPos[$g]++;
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
     */
    public static function setSetting($key, $value) {
        $db = self::connect();
        $db->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        )->execute(array($key, $value));
    }

    /**
     * Marca como FINISHED partidos que llevan >2h en IN_PLAY/PAUSED.
     * Evita que un partido quede "en vivo" para siempre si ESPN deja
     * de devolverlo en el scoreboard (el partido ya termino pero la
     * ultima actualizacion fue antes de que ESPN lo cerrara).
     */
    public static function clearStaleLiveMatches() {
        $db = self::connect();
        $db->exec(
            "UPDATE matches SET status = 'FINISHED', last_updated = datetime('now')
             WHERE status IN ('IN_PLAY','PAUSED')
             AND datetime(match_date) < datetime('now', '-2 hours')"
        );
    }

    // ── Consultas de Equipos ─────────────────────────────

    /**
     * Mapa nombre/tla/short_name → id para resolver FKs al importar de ESPN.
     * Incluye aliases para las variantes de nombre que ESPN puede enviar.
     */
    public static function getTeamNameIndex() {
        $db   = self::connect();
        $rows = $db->query("SELECT id, name, short_name, tla FROM teams")->fetchAll();
        $idx  = array();
        foreach ($rows as $r) {
            $idx[$r['name']]       = $r['id'];
            if ($r['short_name']) $idx[$r['short_name']] = $r['id'];
            if ($r['tla'])        $idx[$r['tla']]        = $r['id'];
        }

        // ESPN puede enviar nombres distintos a los del seed
        $aliases = array(
            // Variantes de nombre confirmadas en ESPN 2026
            'Bosnia-Herzegovina'           => 'Bosnia and Herzegovina',
            'Bosnia & Herzegovina'         => 'Bosnia and Herzegovina',
            "Cura\xc3\xa7ao"              => 'Curacao',  // Curaçao con c cedilla UTF-8
            'Turkiye'                      => 'Turkey',
            "T\xc3\xbcrkiye"              => 'Turkey',   // Türkiye con u umlaut UTF-8
            'USA'                          => 'United States',
            'US'                           => 'United States',
            'IR Iran'                      => 'Iran',
            'Korea Republic'               => 'South Korea',
            "Cote d'Ivoire"                => 'Ivory Coast',
            "C\xc3\xb4te d'Ivoire"        => 'Ivory Coast',  // Côte con o circunflejo UTF-8
            'Congo DR'                     => 'DR Congo',
            'Democratic Republic of Congo' => 'DR Congo',
            'Cape Verde Islands'           => 'Cape Verde',
        );
        foreach ($aliases as $variant => $canonical) {
            if (isset($idx[$canonical]) && !isset($idx[$variant])) {
                $idx[$variant] = $idx[$canonical];
            }
        }

        return $idx;
    }

    /**
     * Mapa nombre de equipo → codigo ISO para banderas via flagcdn.com.
     */
    public static function getIsoCodeMap() {
        return array(
            'United States'  => 'us',  'USA' => 'us',  'US' => 'us',
            'Mexico'         => 'mx',  'Canada' => 'ca',
            'Panama'         => 'pa',  'Jamaica' => 'jm',  'Honduras' => 'hn',
            'Costa Rica'     => 'cr',  'Haiti' => 'ht',
            'El Salvador'    => 'sv',  'Guatemala' => 'gt',
            'Curacao'        => 'cw',
            'Germany'        => 'de',  'France' => 'fr',   'Spain' => 'es',
            'England'        => 'gb-eng', 'Portugal' => 'pt', 'Netherlands' => 'nl',
            'Belgium'        => 'be',  'Italy' => 'it',    'Croatia' => 'hr',
            'Switzerland'    => 'ch',  'Denmark' => 'dk',  'Austria' => 'at',
            'Turkey'         => 'tr',  'Turkiye' => 'tr',  'Türkiye' => 'tr',
            'Serbia'         => 'rs',  'Scotland' => 'gb-sct',
            'Norway'         => 'no',  'Sweden' => 'se',
            'Czech Republic' => 'cz',  'Czechia' => 'cz',
            'Slovakia'       => 'sk',  'Slovenia' => 'si',  'Albania' => 'al',
            'Ukraine'        => 'ua',  'Romania' => 'ro',
            'Bosnia and Herzegovina' => 'ba',
            'Argentina'      => 'ar',  'Brazil' => 'br',   'Colombia' => 'co',
            'Uruguay'        => 'uy',  'Ecuador' => 'ec',  'Venezuela' => 've',
            'Paraguay'       => 'py',  'Chile' => 'cl',    'Peru' => 'pe',
            'Morocco'        => 'ma',  'Senegal' => 'sn',  'Nigeria' => 'ng',
            'Egypt'          => 'eg',  'Tunisia' => 'tn',  'DR Congo' => 'cd',
            'Congo DR'       => 'cd',
            'Ivory Coast'    => 'ci',  "Cote d'Ivoire" => 'ci',
            'South Africa'   => 'za',  'Ghana' => 'gh',    'Cameroon' => 'cm',
            'Algeria'        => 'dz',  'Cape Verde' => 'cv',
            'Japan'          => 'jp',  'South Korea' => 'kr', 'Korea Republic' => 'kr',
            'Saudi Arabia'   => 'sa',  'Australia' => 'au', 'Iran' => 'ir',
            'IR Iran'        => 'ir',  'Iraq' => 'iq',     'Jordan' => 'jo',
            'Uzbekistan'     => 'uz',  'Qatar' => 'qa',    'New Zealand' => 'nz',
        );
    }

    // ── Upsert Equipos (desde ESPN) ───────────────────────

    /**
     * Inserta o actualiza un equipo desde ESPN.
     * Preserva name_es, name_en, confederation e is_host del seed local.
     */
    public static function upsertTeam($data) {
        $db     = self::connect();
        $isoMap = self::getIsoCodeMap();

        $name  = isset($data['name'])      ? $data['name']      : '';
        $short = isset($data['shortName']) ? $data['shortName'] : $name;
        $tla   = isset($data['tla'])       ? strtoupper($data['tla']) : '';
        $extId = isset($data['id'])        ? $data['id']        : null;
        $group = isset($data['group'])     ? str_replace('GROUP_', '', $data['group']) : null;

        $iso = null;
        if (isset($isoMap[$name]))   $iso = $isoMap[$name];
        elseif (isset($isoMap[$short])) $iso = $isoMap[$short];
        elseif (isset($isoMap[$tla]))   $iso = $isoMap[$tla];

        $stmt = $db->prepare("SELECT id FROM teams WHERE name = ?");
        $stmt->execute(array($name));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
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

        $db->prepare(
            "INSERT INTO teams (external_id, name, short_name, tla, iso_code, group_name)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute(array($extId, $name, $short, $tla, $iso, $group));
        return (int) $db->lastInsertId();
    }

    // ── Upsert Partidos ───────────────────────────────────

    /**
     * Inserta o actualiza un partido desde ESPN.
     * Estrategia de busqueda en 3 pasos para no duplicar partidos sembrados:
     *   1. Buscar por external_id (partido ya conocido de ESPN)
     *   2. Buscar por equipos + fecha (actualizar partido sembrado en-lugar)
     *   3. Si no existe, insertar como nuevo (p.ej. partido de eliminacion)
     *
     * Esto preserva los 72 partidos sembrados mientras ESPN los actualiza
     * con marcadores reales y external_ids.
     */
    public static function upsertMatch($match, $teamIdx) {
        $db = self::connect();

        $extId    = isset($match['id'])              ? $match['id']              : null;
        $homeName = isset($match['homeTeam']['name']) ? $match['homeTeam']['name'] : '';
        $awayName = isset($match['awayTeam']['name']) ? $match['awayTeam']['name'] : '';

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
        $city   = isset($match['city'])     ? $match['city']     : null;

        $matchDate = isset($match['utcDate'])
            ? $match['utcDate']
            : (isset($match['lastUpdated']) ? $match['lastUpdated'] : null);

        // Si ESPN no provee grupo, derivarlo del equipo local sembrado
        if (!$grp && $homeId) {
            $sGrp = $db->prepare("SELECT group_name FROM teams WHERE id = ?");
            $sGrp->execute(array($homeId));
            $tRow = $sGrp->fetch(PDO::FETCH_ASSOC);
            if ($tRow && $tRow['group_name']) $grp = $tRow['group_name'];
        }

        // Paso 1: buscar por external_id
        if ($extId) {
            $s1 = $db->prepare("SELECT id FROM matches WHERE external_id = ?");
            $s1->execute(array($extId));
            $found = $s1->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $db->prepare(
                    "UPDATE matches SET
                        home_score=?, away_score=?, home_score_ht=?, away_score_ht=?,
                        status=?, group_name=COALESCE(?,group_name),
                        venue=COALESCE(?,venue), city=COALESCE(?,city),
                        last_updated=datetime('now') WHERE external_id=?"
                )->execute(array($homeFt, $awayFt, $homeHt, $awayHt, $status, $grp, $venue, $city, $extId));
                return;
            }
        }

        // Paso 2: buscar partido sembrado por equipos + dia (para actualizarlo in-place)
        if ($homeId && $awayId && $matchDate) {
            $dateDay = substr($matchDate, 0, 10);
            $s2 = $db->prepare(
                "SELECT id FROM matches WHERE home_team_id=? AND away_team_id=? AND match_date LIKE ?"
            );
            $s2->execute(array($homeId, $awayId, $dateDay . '%'));
            $found = $s2->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                // Actualizar el partido sembrado: asignarle external_id + marcador + estado
                $db->prepare(
                    "UPDATE matches SET
                        external_id=COALESCE(?,external_id),
                        home_score=?, away_score=?, home_score_ht=?, away_score_ht=?,
                        status=?, group_name=COALESCE(?,group_name),
                        venue=COALESCE(?,venue), city=COALESCE(?,city),
                        last_updated=datetime('now') WHERE id=?"
                )->execute(array($extId, $homeFt, $awayFt, $homeHt, $awayHt, $status, $grp, $venue, $city, $found['id']));
                return;
            }
        }

        // Paso 3: insertar como nuevo (partido de eliminacion u otro no sembrado)
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
            $venue, $city
        ));
    }

    // ── Upsert Posiciones ─────────────────────────────────

    /**
     * Inserta o actualiza una posicion de grupo.
     * SELECT + UPDATE/INSERT por compatibilidad con SQLite 3.7.
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

        $db->prepare("UPDATE teams SET group_name = ? WHERE id = ?")->execute(array($group, $teamId));
    }

    // ── Consultas de Lectura ─────────────────────────────

    /**
     * Devuelve todos los partidos con datos de ambos equipos.
     * Ordenado por fecha ascendente para la vista de calendario.
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

    /** Todos los equipos ordenados por grupo y confederacion */
    public static function getAllTeams() {
        $db = self::connect();
        return $db->query(
            "SELECT id, name, name_es, name_en, short_name, tla,
                    iso_code, confederation, group_name, is_host
             FROM teams ORDER BY group_name, confederation, name"
        )->fetchAll();
    }
}
