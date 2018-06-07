<?php

    namespace Firegore\Mysql;

    use Firegore\Mysql\Sql\SqlBase;

    /**
     * @author  Pedro López Escudero Firegore2@gmail.com
     *
     * @version 1.0.0 This class was made to log the user activity on the website, taking the reference as date and
     *          time connection and the time navigation in the web
     *
     */

    /**
     * Class Sql
     *
     * @internal this class must will be used to manage all queries to the database
     */
    class Sql extends SqlBase
    {
        /**
         * [protected description]
         *
         * @var array
         */

        private static $_instance; //The single instance
        protected      $_dbcache = [];


        /**
         * [protected description]
         *
         * @var string
         */

        protected $database;

        public function __construct ($host = false, $user = false, $password = false, $db = false, $port = false)
        {
            parent::__construct($host, $user, $password, $db, $port);
            $this->_init();
        }

        protected function _init ()
        {
            if (empty(self::$cache)) {
                // $time = microtime(true);
                self::getCacheObject()
                    ->loadCache();
                $this->_dbcache = self::$cache[$this->_database];
                // d((microtime(true)-$time));
            }
        }

        public function make ()
        {
            return new self();
        }

        /**
         * [getInstance description]
         * @method getInstance
         *
         * @return self      [description]
         */
        public static function getInstance ()
        {
            if (!self::$_instance) { // If no instance then make one
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __wakeup ()
        {
            $this->establish_connection();
        }

        public function __destruct ()
        {
            $this->close();
            self::getCacheObject()
                ->saveCache();
        }

        public static function clone ()
        {
            return new self();
        }


        /**
         * [inteligentInsert description]
         * @method inteligentInsert
         *
         * @param  string $table  the table to insert the data
         * @param  array  $values an array with key as column and value as data to insert into the table
         * @param  bool   $update If the id exist, update with the new values
         *
         * @return bool           return true if the data was inserted successfully, otherwise return false
         */
        public function inteligentInsert ($table, $values, $update = false)
        {
            if ($this->issetTable($table)) {
                $values = $this->securizeArrayMysql($values);
                // d($values);
                $values = $this->getValidFormatArray($table, $values);
                foreach ($this->getColumns($table) as $column) {
                    if ($column == "id" && !isset($values[$column])) {
                        $data[$column] = "NULL";
                    } else {
                        if (isset($values[$column])) {
                            $data[$column] = $values[$column];
                        } else {
                            // d($values);
                            $data[$column] = $this->getValidFormat($table, $column, "");
                        }
                    }
                }
                $columns = implode(", ", array_keys($data));
                $values  = implode(", ", array_values($data));
                Debug::log(["INSERT INTO $table ($columns) VALUES ($values)"]);
                $sql = "INSERT INTO $table ($columns) VALUES ($values)";
                if ($update) {
                    $sql .= " ON DUPLICATE KEY UPDATE ";
                    foreach ($data as $column => $value) {
                        if ($column != "id") {
                            $sql .= " $column = $value, ";
                        }
                    }
                    $sql = substr($sql, 0, -2);
                }
                // d($sql);
                if ($this->queryMysql($sql)) {
                    if ($this->affectedRows()) {
                        return $this->insert_id;
                    } else {
                        return false;
                    }

                } else {
                    return false;
                }


            } else {
                return false;
            }

        }

        public function deleteObject ($table, $id)
        {
            if ($this->queryMysql("DELETE FROM $table WHERE id=$id")) {
                if ($this->resetAllAutoIncrement($table)) {
                    return true;
                } else {
                    return false;
                }
            }
            return false;
        }

    }
