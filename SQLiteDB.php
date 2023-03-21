<?php

/**
 * @noinspection SqlNoDataSourceInspection
 * @noinspection SqlDialectInspection
 * @noinspection PhpComposerExtensionStubsInspection
 */

namespace dokuwiki\plugin\sqlite;

use dokuwiki\Extension\Event;

/**
 * Helpers to access a SQLite Database with automatic schema migration
 */
class SQLiteDB
{
    const FILE_EXTENSION = '.sqlite3';

    /** @var \PDO */
    protected $pdo;

    /** @var string */
    protected $schemadir;

    /** @var string */
    protected $dbname;

    /** @var \helper_plugin_sqlite */
    protected $helper;

    /**
     * Constructor
     *
     * @param string $dbname Database name
     * @param string $schemadir directory with schema migration files
     * @param \helper_plugin_sqlite $sqlitehelper for backwards compatibility
     * @throws \Exception
     */
    public function __construct($dbname, $schemadir, $sqlitehelper = null)
    {
        if (!class_exists('pdo') || !in_array('sqlite', \PDO::getAvailableDrivers())) {
            throw new \Exception('SQLite PDO driver not available');
        }

        // backwards compatibility, circular dependency
        $this->helper = $sqlitehelper;
        if (!$this->helper) {
            $this->helper = new \helper_plugin_sqlite();
        }
        $this->helper->setAdapter($this);

        $this->schemadir = $schemadir;
        $this->dbname = $dbname;
        $file = $this->getDbFile();

        $this->pdo = new \PDO(
            'sqlite:' . $file,
            null,
            null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]
        );

        if ($schemadir !== '') {
            // schema dir is empty, when accessing the DB from Admin interface instead of plugin context
            $this->applyMigrations();
        }
        Functions::register($this->pdo);
    }

    /**
     * Do not serialize the DB connection
     *
     * @return array
     */
    public function __sleep()
    {
        $this->pdo = null;
        return array_keys(get_object_vars($this));
    }

    /**
     * On deserialization, reinit database connection
     */
    public function __wakeup()
    {
        $this->__construct($this->dbname, $this->schemadir, $this->helper);
    }

    // region public API

    /**
     * Direct access to the PDO object
     * @return \PDO
     *
     */
    public function pdo()
    {
        return $this->pdo;
    }

    /**
     * Alias for backwards compatibility.
     *
     * @return \PDO
     *
     * @deprecated 2023-03-15
     */
    public function getDb() {
        return $this->pdo();
    }

    /**
     * Execute a statement and return it
     *
     * @param string $sql
     * @param array $parameters
     * @return \PDOStatement Be sure to close the cursor yourself
     * @throws \PDOException
     */
    public function query($sql, $parameters = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $eventData = [
            'sqlitedb' => $this,
            'sql'  => &$sql,
            'parameters' => &$parameters,
            'stmt' => $stmt
        ];
        $event = new Event('PLUGIN_SQLITE_QUERY_EXECUTE', $eventData);
        if ($event->advise_before()) {
            $stmt->execute($parameters);
        }
        $event->advise_after();
        return $stmt;
    }

    /**
     * Execute a statement and return metadata
     *
     * Returns the last insert ID on INSERTs or the number of affected rows
     *
     * @param string $sql
     * @param array $parameters
     * @return int
     * @throws \PDOException
     */
    public function exec($sql, $parameters = [])
    {
        $stmt = $this->query($sql, $parameters);

        $count = $stmt->rowCount();
        $stmt->closeCursor();
        if ($count && preg_match('/^INSERT /i', $sql)) {
            return $this->queryValue('SELECT last_insert_rowid()');
        }

        return $count;
    }

    /**
     * Simple query abstraction
     *
     * Returns all data
     *
     * @param string $sql
     * @param array $params
     * @return array
     * @throws \PDOException
     */
    public function queryAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $data;
    }

    /**
     * Query one single row
     *
     * @param string $sql
     * @param array $params
     * @return array|null
     * @throws \PDOException
     */
    public function queryRecord($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (is_array($row) && count($row)) {
            return $row;
        }
        return null;
    }

    /**
     * Insert or replace the given data into the table
     *
     * @param string $table
     * @param array $data
     * @param bool $replace Conflict resolution, replace or ignore
     * @return array|null Either the inserted row or null if nothing was inserted
     * @throws \PDOException
     */
    public function saveRecord($table, $data, $replace = true)
    {
        $columns = array_map(function ($column) {
            return '"' . $column . '"';
        }, array_keys($data));
        $values = array_values($data);
        $placeholders = array_pad([], count($columns), '?');

        if ($replace) {
            $command = 'REPLACE';
        } else {
            $command = 'INSERT OR IGNORE';
        }

        /** @noinspection SqlResolve */
        $sql = $command . ' INTO "' . $table . '" (' . join(',', $columns) . ') VALUES (' . join(',',
                $placeholders) . ')';
        $stm = $this->query($sql, $values);
        $success = $stm->rowCount();
        $stm->closeCursor();

        if ($success) {
            $sql = 'SELECT * FROM "' . $table . '" WHERE rowid = last_insert_rowid()';
            return $this->queryRecord($sql);
        }
        return null;
    }

    /**
     * Execute a query that returns a single value
     *
     * @param string $sql
     * @param array $params
     * @return mixed|null
     * @throws \PDOException
     */
    public function queryValue($sql, $params = [])
    {
        $result = $this->queryAll($sql, $params);
        if (is_array($result) && count($result)) {
            return array_values($result[0])[0];
        }
        return null;
    }

    // endregion

    // region meta handling

    /**
     * Get a config value from the opt table
     *
     * @param string $opt Config name
     * @param mixed $default What to return if the value isn't set
     * @return mixed
     * @throws \PDOException
     */
    public function getOpt($opt, $default = null)
    {
        $value = $this->queryValue("SELECT val FROM opts WHERE opt = ?", [$opt]);
        if ($value === null) {
            return $default;
        }
        return $value;
    }

    /**
     * Set a config value in the opt table
     *
     * @param $opt
     * @param $value
     * @throws \PDOException
     */
    public function setOpt($opt, $value)
    {
        $this->exec('REPLACE INTO opts (opt,val) VALUES (?,?)', [$opt, $value]);
    }

    /**
     * @return string
     */
    public function getDbName()
    {
        return $this->dbname;
    }

    /**
     * @return string
     */
    public function getDbFile()
    {
        global $conf;
        return $conf['metadir'] . '/' . $this->dbname . self::FILE_EXTENSION;
    }

    /**
     * Create a dump of the database and its contents
     *
     * @return string
     * @throws \Exception
     */
    public function dumpToFile($filename)
    {
        $fp = fopen($filename, 'w');
        if (!$fp) {
            throw new \Exception('Could not open file ' . $filename . ' for writing');
        }

        $tables = $this->queryAll('SELECT name,sql FROM sqlite_master WHERE type="table"');
        fwrite($fp, 'BEGIN TRANSACTION;' . "\n");

        foreach ($tables as $table) {
            fwrite($fp, $table['sql'] . ";\n"); // table definition

            // data as INSERT statements
            $sql = "SELECT * FROM " . $table['name'];
            $res = $this->query($sql);
            while ($row = $res->fetch(\PDO::FETCH_ASSOC)) {
                $line = 'INSERT INTO ' . $table['name'] . ' VALUES(';
                foreach ($row as $no_entry => $entry) {
                    if ($no_entry !== 0) {
                        $line .= ',';
                    }

                    if (is_null($entry)) {
                        $line .= 'NULL';
                    } elseif (!is_numeric($entry)) {
                        $line .= $this->pdo->quote($entry);
                    } else {
                        // TODO depending on locale extra leading zeros
                        // are truncated e.g 1.300 (thousand three hunderd)-> 1.3
                        $line .= $entry;
                    }
                }
                $line .= ');' . "\n";
                fwrite($fp, $line);
            }
            $res->closeCursor();
        }

        // indexes
        $indexes = $this->queryAll("SELECT name,sql FROM sqlite_master WHERE type='index'");
        foreach ($indexes as $index) {
            fwrite($fp, $index['sql'] . ";\n");
        }
        fwrite($fp, 'COMMIT;' . "\n");
        fclose($fp);
        return $filename;
    }

    // endregion

    // region migration handling

    /**
     * Apply all pending migrations
     *
     * Each migration is executed in a transaction which is rolled back on failure
     * Migrations can be files in the schema directory or event handlers
     *
     * @throws \Exception
     */
    protected function applyMigrations()
    {
        $currentVersion = $this->currentDbVersion();
        $latestVersion = $this->latestDbVersion();

        for ($newVersion = $currentVersion + 1; $newVersion <= $latestVersion; $newVersion++) {
            $data = [
                'dbname' => $this->dbname,
                'from' => $currentVersion,
                'to' => $newVersion,
                'file' => $this->getMigrationFile($newVersion),
                'sqlite' => $this->helper,
                'adapter' => $this,
            ];
            $event = new \Doku_Event('PLUGIN_SQLITE_DATABASE_UPGRADE', $data);

            $this->pdo->beginTransaction();
            try {
                if ($event->advise_before()) {
                    // standard migration file
                    $sql = file_get_contents($data['file']);
                    $this->pdo->exec($sql);
                } else {
                    if (!$event->result) {
                        // advise before returned false, but the result was false
                        throw new \PDOException('Plugin event did not signal success');
                    }
                }
                $this->setOpt('dbversion', $newVersion);
                $this->pdo->commit();
                $event->advise_after();
            } catch(\Exception $e) {
                // something went wrong, rollback
                $this->pdo->rollBack();
                throw $e;
            }
        }

        // vacuum the database to free up unused space
        $this->pdo->exec('VACUUM');
    }

    /**
     * Read the current version from the opt table
     *
     * The opt table is created here if not found
     *
     * @return int
     * @throws \PDOException
     */
    protected function currentDbVersion()
    {
        try {
            $version = $this->getOpt('dbversion', 0);
            return (int) $version;
        } catch(\PDOException $ignored) {
            // add the opt table - if this fails too, let the exception bubble up
            $sql = "CREATE TABLE IF NOT EXISTS opts (opt TEXT NOT NULL PRIMARY KEY, val NOT NULL DEFAULT '')";
            $this->exec($sql);
            $this->setOpt('dbversion', 0);
            return 0;
        }
    }

    /**
     * Get the version this db should have
     *
     * @return int
     * @throws \PDOException
     */
    protected function latestDbVersion()
    {
        if (!file_exists($this->schemadir . '/latest.version')) {
            throw new \PDOException('No latest.version in schema dir');
        }
        return (int) trim(file_get_contents($this->schemadir . '/latest.version'));
    }

    /**
     * Get the migrartion file for the given version
     *
     * @param int $version
     * @return string
     */
    protected function getMigrationFile($version)
    {
        return sprintf($this->schemadir . '/update%04d.sql', $version);
    }
    // endregion
}
