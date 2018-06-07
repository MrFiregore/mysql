<?php

    namespace Firegore\Mysql\Sql;

    /**
     *
     */
    class SqlCache extends SqlBase
    {
        private static $_instance; //The single instance
        protected      $cache_folder = __ROOT__ . "cache";
        protected      $cache_file;
        protected      $_should_save = false;

        function __construct ()
        {
            parent::__construct(false, false, false, "information_schema", false);
            $this->cache_file =
                __ROOT__ . "cache" . DIRECTORY_SEPARATOR . "db-" . $this->fetchArray($this->queryMysql("SHOW VARIABLES WHERE Variable_name = 'hostname'"))[0]['Value'] . ".json";
        }

        public function saveCache ()
        {
            if ($this->_should_save) {
                return file_put_contents($this->cache_file, json_encode(self::$cache));
            }
            return true;
        }

        public function loadCache ()
        {
            if (!file_exists($this->cache_folder)) {
                mkdir($this->cache_folder, 0755, true);
            }
            if (file_exists($this->cache_file) && !count(self::$cache)) {
                self::$cache = json_decode(file_get_contents($this->cache_file), true);
            }
            if (!count(self::$cache)) {
                // d($this->cache_file,self::$cache,(time() - filemtime($this->cache_file)));
                return $this->loadEmptyCache();
            } elseif ((time() - filemtime($this->cache_file)) > 600) {
                $this->shouldSave();
                $this->updateCache();
            }
            return $this;
        }

        public function updateCache ()
        {
            $databases    = array_keys(self::$cache);
            $db_databases = array_map(
                function ($db) {
                    return $db["SCHEMA_NAME"];
                }, $this->fetchArray($this->queryMysql("SELECT SCHEMA_NAME FROM SCHEMATA WHERE SCHEMA_NAME NOT LIKE 'information_schema'"))
            );

            foreach ($databases as $key => $dbname) {
                if (in_array($dbname, $db_databases)) {
                    unset($db_databases[array_search($dbname, $db_databases)]);
                    unset($databases[$key]);
                }
            }
            foreach ($databases as $dbname) {
                unset(self::$cache[$dbname]);
            }
            foreach ($db_databases as $dbname) {
                $this->updateDatabase($dbname);
            }


            /*
            UPDATE TABLES
            */
            foreach (self::$cache as $dbname => $db) {
                $tables    = array_keys($db["TABLES"]);
                $db_tables = array_map(
                    function ($db) {
                        return $db["TABLE_NAME"];
                    }, $this->fetchArray($this->queryMysql("SELECT TABLE_NAME FROM TABLES WHERE TABLE_SCHEMA LIKE '$dbname'"))
                );

                foreach ($tables as $key => $tablename) {
                    if (in_array($tablename, $db_tables)) {
                        unset($db_tables[array_search($tablename, $db_tables)]);
                        unset($tables[$key]);
                    }
                }

                foreach ($tables as $tablename) {
                    unset(self::$cache[$dbname]['TABLES'][$tablename]);
                }

                if (!empty($db_tables)) {
                    $this->updateDatabaseTables($dbname, $db_tables);
                }
            }
            /**
             * UPDATE COLUMNS
             */

            foreach (self::$cache as $dbname => $db) {
                foreach ($db['TABLES'] as $tablename => $table) {
                    $columns    = array_keys($table["COLUMNS"]);
                    $db_columns = array_diff(
                        array_map(
                            function ($col) {
                                return $col["COLUMN_NAME"];
                            }, $this->fetchArray($this->queryMysql("SELECT COLUMN_NAME FROM COLUMNS WHERE TABLE_SCHEMA LIKE '$dbname' AND TABLE_NAME LIKE '$tablename' AND COLUMN_NAME NOT IN ('" . implode("','", $columns) . "')"))
                        ), $columns
                    );

                    $inexistent = array_diff(
                        $columns, array_map(
                                    function ($col) use ($columns) {
                                        if (in_array($col["COLUMN_NAME"], $columns)) return $col["COLUMN_NAME"];
                                    }, $this->fetchArray($this->queryMysql("SELECT COLUMN_NAME FROM COLUMNS WHERE TABLE_SCHEMA LIKE '$dbname' AND TABLE_NAME LIKE '$tablename' AND COLUMN_NAME IN ('" . implode("','", $columns) . "')"))
                                )
                    );

                    foreach ($inexistent as $columnname) {
                        unset(self::$cache[$dbname]['TABLES'][$tablename]["COLUMNS"][$columnname]);
                    }
                    if (!empty($db_columns)) {
                        $this->updateTableColumns($dbname, $tablename, $db_columns);
                    }
                }
            }


        }

        public function getDatabase ($dbname = '')
        {
            if (isset(self::$cache[$dbname])) {
                return self::$cache[$dbname];
            }
            $this->updateDatabase($dbname);
            return self::$cache;
        }

        protected function shouldSave ()
        {
            if (!$this->_should_save) {
                $this->_should_save = true;
            }
        }

        public function updateDatabase ($dbname = '')
        {
            foreach ($this->fetchArray(
                $this->queryMysql(
                    "SELECT SCHEMA_NAME,DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME
          FROM SCHEMATA
          " . (($dbname !== "" && is_string($dbname)) ? "WHERE SCHEMA_NAME LIKE '$dbname'" :
                        " WHERE SCHEMA_NAME NOT LIKE 'information_schema'")
                )
            ) as $db) {

                $dbname = $db['SCHEMA_NAME'];
                if (!isset(self::$cache[$dbname])) {
                    $this->shouldSave();
                    self::$cache[$dbname] = [
                        "DEFAULT_CHARACTER_SET_NAME" => $db['DEFAULT_CHARACTER_SET_NAME'],
                        "DEFAULT_COLLATION_NAME"     => $db['DEFAULT_COLLATION_NAME'],
                        "TABLES"                     => [],
                    ];
                }
            }
        }

        public function getDatabaseTables ($dbname)
        {
            if (!isset(self::$cache[$dbname]['TABLES']) || empty(self::$cache[$dbname]['TABLES'])) {
                $this->updateDatabaseTables($dbname);
            }

            return self::$cache[$dbname]['TABLES'];
        }

        public function updateDatabaseTables ($dbname, $tables = [])
        {
            $where = "";
            if (!empty($tables)) {
                $where .= " AND TABLE_NAME IN ('" . implode("','", $tables) . "')";
            }
            $select =
                "SELECT TABLE_NAME,TABLE_ROWS,TABLE_COLLATION,TABLE_COMMENT,DATA_LENGTH,INDEX_LENGTH FROM TABLES WHERE TABLE_SCHEMA LIKE '$dbname'" . $where;

            foreach ($this->fetchArray($this->queryMysql($select)) as $table) {
                $tablename = $table["TABLE_NAME"];
                $this->shouldSave();

                self::$cache[$dbname]['TABLES'][$tablename] = [
                    "TABLE_ROWS"      => $table['TABLE_ROWS'],
                    "TABLE_COLLATION" => $table['TABLE_COLLATION'],
                    "TABLE_COMMENT"   => $table['TABLE_COMMENT'],
                    "DATA_LENGTH"     => $table['DATA_LENGTH'],
                    "INDEX_LENGTH"    => $table['INDEX_LENGTH'],
                    "BYTES"           => ($table['DATA_LENGTH'] ?: 0) + ($table['INDEX_LENGTH'] ?: 0),
                    "COLUMNS"         => [],
                ];
            }
        }


        public function getColumnsTable ($dbname, $tablename)
        {
            if (!isset(self::$cache[$dbname]['TABLES'][$tablename]["COLUMNS"]) || empty(self::$cache[$dbname]['TABLES'][$tablename]["COLUMNS"])) {
                $this->updateTableColumns($dbname, $tablename);
            }
            return self::$cache[$dbname]['TABLES'][$tablename]["COLUMNS"];
        }

        public function updateTableColumns ($dbname, $tablename, $columns = [])
        {
            $where = "";
            if (!empty($columns)) {
                $where .= " AND COLUMN_NAME IN ('" . implode("','", $columns) . "')";
            }
            $select =
                "SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,DATETIME_PRECISION,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_TYPE,COLUMN_KEY,EXTRA,PRIVILEGES,COLUMN_COMMENT FROM COLUMNS WHERE TABLE_SCHEMA LIKE '$dbname' AND TABLE_NAME LIKE '$tablename'" . $where;
            foreach ($this->fetchArray($this->queryMysql($select)) as $col) {
                $this->shouldSave();
                $colname                                                         = $col["COLUMN_NAME"];
                self::$cache[$dbname]['TABLES'][$tablename]["COLUMNS"][$colname] = $col;
            }
        }


        public function loadEmptyCache ()
        {
            foreach ($this->getDatabase() as $dbname => $db) {
                foreach ($this->getDatabaseTables($dbname) as $tablename => $table) {
                    $this->getColumnsTable($dbname, $tablename);
                }
            }
            $this->saveCache();
            return $this;
        }

    }
