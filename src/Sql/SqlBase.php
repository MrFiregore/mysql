<?php

    namespace Firegore\Mysql\Sql;

    use mysqli;
    use DateTime;
    use Exception;
    use mysqli_result;
    use Firegore\Mysql\Tools;
    use Firegore\Mysql\Sql\SqlCache;


    /**
     *
     */
    abstract class SqlBase extends mysqli
    {
        /**
         * [protected description]
         *
         * @var array
         */

        protected static $cache          = [];
        protected static $db_schema      = [];
        protected static $table_schema   = [];
        protected static $columns_schema = [];


        /**
         * [protected description]
         *
         * @var SqlCache
         */
        protected static $_cacheObject;
        protected static $numConnections = 0;
        protected        $dbcache        = [];
        protected        $_host;
        protected        $_username;
        protected        $_password;
        protected        $_database;
        protected        $_port;


        public function __construct ($host = false, $user = false, $password = false, $db = false, $port = false)
        {
            self::$numConnections++;
            $this->_host     = ($host) ? $host : $_ENV['host'];
            $this->_username = ($user) ? $user : $_ENV['user'];
            $this->_password = ($password) ? $password : $_ENV['password'];
            $this->_database = ($db) ? $db : $_ENV['database'];
            $this->_port     = ($port) ? $port : $_ENV['port'];
            $this->establish_connection();
        }

        /**
         * [getCacheObject description]
         * @method getCacheObject
         *
         * @return SqlCache         [description]
         */
        public static function getCacheObject ()
        {
            if (!self::$_cacheObject) { // If no instance then make one
                self::$_cacheObject = new SqlCache();
            }
            return self::$_cacheObject;
        }

        public function establish_connection ()
        {
            parent::__construct($this->_host, $this->_username, $this->_password, "", $this->_port);
            $this->select_db($this->_database);
            $this->set_charset("utf8mb4");
        }

        /**
         * @method queryMysql - protect and help the querys to the database
         *
         * @param string $query REQUIRED    -> the query to do
         *
         * @return mysqli_result -> if was made success return mysqli_result object, else return false
         *
         */
        public function queryMysql ($query)
        {
            if ($result = $this->query($query)) {
                return $result;
            } else {
                // d($query);
                throw new Exception("Error Processing Query : " . $this->error . "\n SQL: $query", 1);
                return false;
            }
        }

        /**
         * [securizeStringMysql description]
         * @method securizeStringMysql
         *
         * @param  [type]              $value [description]
         *
         * @return [type]              [description]
         */
        public function securizeStringMysql ($value)
        {
            if (is_array($value)) {
                // d($value);
            }
            return $this->real_escape_string($value);
        }

        /**
         * @method securizeArrayMysql - sanitizes an array to protect the querys to mysql
         *
         * @param array $array REQUIRED    -> the array to sanitize
         *
         * @return array -> returns the given array but with its characters escaped
         *
         */
        public function securizeArrayMysql ($array)
        {
            $a = [];
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $a[$key] = json_encode($value);
                } else {
                    if (is_string($value)) {
                        $a[$key] = self::securizeStringMysql($value);
                    } elseif (is_bool($value)) {
                        $a[$key] = ($value) ? 'true' : 'false';
                    } elseif (is_object($value)) {
                        if ($content = (string)$value) $a[$key] = $content; else $a[$key] =
                            self::securizeStringMysql(get_class($value));
                    } else {
                        $a[$key] = $value;
                    }
                }
            }
            return $a;
        }

        /**
         * returns the associative array of the object mysqli_result given
         * @method fetchArray
         *
         * @param  mysqli_result $result the object obtained in a select query to mysql
         *
         * @return array             the associative array
         */

        public function fetchArray ($result)
        {
            $rows  = [];
            $types = [];
            $data  = [];
            while ($column = $result->fetch_field()) {
                switch ($column->type) {
                    case 1:
                        if ($column->length == 1) {
                            $types[$column->name] = 'bool';
                        } else {
                            $types[$column->name] = 'int';
                        }

                        break;
                    case 3:
                        $types[$column->name] = 'int';
                        break;
                    case 4:
                    case 5:
                        $types[$column->name] = 'float';
                        break;
                    default:
                        $types[$column->name] = 'string';
                        break;
                }
            }
            while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
                foreach ($row as $key => $value) {
                    settype($value, $types[$key]);
                    $rowc[$key] = $value;
                }
                $rows[] = $rowc;
            }
            return $rows;
        }

        /**
         * @method affectedRows - returns the number of rows affected by the last mysql operation
         *
         * @return bool|int -> returns the rows affected (if return false is caused by a error)
         *
         */
        public function affectedRows ()
        {
            return ($affected_rows = $this->affected_rows > 0) ? $affected_rows : false;
        }

        /**
         * @method errorString - returns a string description of the last error
         *
         * @return null|string -> returns a string that describes the error or an empty string if no error occurred.
         *
         */
        public function errorString ()
        {
            return $this->error;
        }

        public function getAllDatabases ()
        {
            return array_keys(self::$cache);
        }

        /**
         * Selects the default database for database queries
         * @method select_db
         *
         * @param  string $dbname The database name
         *
         * @return bool              Returns true on success or false on failure
         */

        public function select_db ($dbname)
        {
            $this->_database = $dbname;
            parent::select_db($dbname);
        }

        public function getActualDBName ()
        {
            return $this->_database;
        }

        public function getActualUser ()
        {
            return sprintf("'%s'@'%s'", $this->_username, $this->_host);
        }

        public function getUserGrants ($user = null, $host = null)
        {
            return $this->fetchArray($this->queryMysql("SELECT PRIVILEGE_TYPE FROM information_schema.user_privileges WHERE GRANTEE LIKE \"" . $this->getActualUser() . "\""));
        }

        public function getAllTables ()
        {
            return array_keys(self::$cache[$this->getActualDBName()]["TABLES"]);
        }

        public function getColumns ($table)
        {
            return array_keys(self::$cache[$this->getActualDBName()]["TABLES"][$table]["COLUMNS"]);
        }

        /**
         * [issetId description]
         * @method issetId
         *
         * @param  string $table [description]
         * @param  int    $id    [description]
         * @param  string $key_id
         *
         * @return bool [description]
         */
        public function issetId ($table, $id, $key_id = "id")
        {
            if ($id === false || is_null($id)) {
                return false;
            }
            $id    = $this->getValidFormat($table, $key_id, $this->securizeStringMysql($id));
            $query = $this->queryMysql("SELECT * FROM $table WHERE $key_id=$id");
            $isset = ($query->num_rows > 0) ? true : false;
            return $isset;
        }

        /**
         * [issetParameter description]
         * @method issetParameter
         *
         * @param  string $table  The name of the table
         * @param  string $column The name of the column
         * @param  string $value  The value that must be set in any column
         */
        public function issetParameter ($table, $column, $value)
        {
            $table  = $this->securizeStringMysql($table);
            $column = $this->securizeStringMysql($column);
            $value  = $this->getValidFormat($table, $column, $this->securizeStringMysql($value));
            $query  = $this->queryMysql("SELECT $column FROM $table WHERE $column=$value");
            return ($this->affectedRows() > 0) ? true : false;
        }

        public function getEnumOptions ($table, $column)
        {
            preg_match("/^enum\(\'(.*)\'\)$/", self::$cache[$this->getActualDBName()]["TABLES"][$table]["COLUMNS"][$column]["COLUMN_TYPE"], $matches);
            return explode("','", $matches[1]);
        }

        public function addColumn ($table, $column_name, $type, $length)
        {
            if (!$this->issetTable($table) || $this->issetColumn($table, $column_name)) {
                return false;
            }
            if ($this->queryMysql("ALTER TABLE $table ADD $column_name $type($length) after " . $this->getLastColumn($table))) {
                self::getCacheObject()
                    ->updateTableColumns($this->_database, $table);
                return true;
            }
            return false;
        }

        public function getValidFormat ($table, $column, $value)
        {
            $format = $this->getColumnType($table, $column);
            Debug::log([$table, $column, $value, $format]);
            if (strpos($format, 'tinyint') !== false) {

                $value =
                    (is_bool($value) || is_numeric($value)) ? ($value ? 1 : 0) :
                        ((in_array($value, ["true", "false"])) ? (($value == "true") ? 1 : 0) :
                            $this->getColumnDefaultValue($table, $column));
                return $value;
            } elseif (strpos($format, 'enum') !== false) {
                $options = $this->getEnumOptions($table, $column);
                $value   =
                    (in_array($value, $options)) ? "'$value'" :
                        "'" . $this->getColumnDefaultValue($table, $column) . "'";
                return $value;
            } elseif (strpos($format, 'int') !== false || in_array($format, ['float', 'double', 'bigint'])) {
                // d($format,$value,is_numeric($value),$column,($value === false));
                $value =
                    (is_numeric($value)) ? $value :
                        $this->getColumnDefaultValue($table, $column);//(is_string($value) ? strtotime($value): $this->getColumnDefaultValue($table,$column));
                return $value;
            } elseif (in_array($format, ['varchar', 'char', "text"])) {
                if (is_array($value)) {
                    return "'" . json_encode($value) . "'";
                }
                return "'" . $value . "'";
            } elseif ($format == "datetime") {
                return "'" . $this->getValidDatetime($value) . "'";
            } else {
                return $this->getColumnDefaultValue($table, $column);
            }
        }

        public function getValidDatetime ($val)
        {
            if (is_string($val)) {
                $val = new DateTime($val);
            } elseif (is_numeric($val)) {
                $val = new DateTime(date("Y-m-d H:i:s", (int)$val));
            } else {
                $val = new DateTime();
            }
            if (!$val instanceof DateTime) {
                throw new Exception("The given value is not valid to datetime format convert", 1);
            }
            return $val->format('Y-m-d H:i:s');
        }

        public function getValidFormatArray ($table, $array)
        {
            $data = [];
            if ($this->issetTable($table)) {
                foreach ($array as $key => $value) {
                    $column = $this->securizeStringMysql($key);
                    $value  = $this->securizeStringMysql($value);
                    if ($this->issetColumn($table, $column)) {
                        $data[$column] = $this->getValidFormat($table, $column, $value);
                    }
                }
                return $data;
            } else {
                return false;
            }
        }

        public function getColumnType ($table, $column)
        {
            return self::$cache[$this->getActualDBName()]["TABLES"][$table]["COLUMNS"][$column]["DATA_TYPE"];
        }

        public function getColumnDefaultValue ($table, $column)
        {
            $query_array = self::$cache[$this->getActualDBName()]["TABLES"][$table]["COLUMNS"][$column];
            // d($query_array);
            return (!is_null($query_array['COLUMN_DEFAULT']) && $query_array['COLUMN_DEFAULT'] != "") ?
                $query_array['COLUMN_DEFAULT'] : (($query_array['IS_NULLABLE'] == "YES") ? "NULL" : "");
        }

        public function issetDB ($db)
        {
            return isset(self::$cache[$db]);

        }

        public function issetTable ($table)
        {
            return isset(self::$cache[$this->getActualDBName()]["TABLES"][$table]);
        }

        public function resetAllAutoIncrement ($table = "")
        {
            if ($table == "") {
                foreach ($this->getAllTables() as $table) {
                    if ($this->queryMysql("ALTER TABLE $table AUTO_INCREMENT = 1")) {
                    } else {
                        return false;
                    }
                }
                return true;
            } else {
                if ($this->issetTable($table)) {
                    if (!$this->queryMysql("ALTER TABLE $table AUTO_INCREMENT = 1")) {
                        return false;
                    }
                    return true;
                }

            }

        }

        /**
         * [issetColumn description]
         * @method issetColumn
         *
         * @param  string $table  [description]
         * @param  string $column [description]
         *
         * @return bool [description]
         */
        public function issetColumn ($table, $column)
        {
            return isset(self::$cache[$this->getActualDBName()]["TABLES"][$table]["COLUMNS"][$column]);
        }

    }
