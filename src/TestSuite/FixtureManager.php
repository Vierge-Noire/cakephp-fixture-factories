<?php

namespace TestFixtureFactories\TestSuite;

use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\Fixture\FixtureManager as BaseFixtureManager;
use Cake\Utility\Hash;
use function array_filter;
use function array_map;
use function count;
use function debug;
use function implode;
use function microtime;
use function strpos;

/**
 * Class FixtureManager
 * @package TestFixtureFactories\TestSuite
 */
class FixtureManager extends BaseFixtureManager
{
    /**
     * @param string $name
     * @return ConnectionInterface
     */
    public function getConnection($name = 'test')
    {
        return ConnectionManager::get($name);
    }

    public function initDb()
    {
        $this->_initDb();
    }

    public function truncateDirtyTablesForAllConnections()
    {
        $connections = ConnectionManager::configured();

        foreach ($connections as $connectionName) {
            if (strpos($connectionName, 'test') === 0) {
                $this->truncateDirtyTables($connectionName);
            }
        }
    }

    /**
     * @param string $connectionName
     * @deprecated
     */
    private function truncateDirtyTablesUsingCount(string $connectionName)
    {
        // not fully implemented as truncateDirtyTables seem sufficient
        $connection = $this->getConnection($connectionName);
        $databaseName = $connection->config()['database'];
        $tables = $connection->getSchemaCollection()->listTables();
        $tables = array_filter($tables, function ($table) {
            if (strpos($table, 'phinxlog') !== false) {
                return false;
            }
            return true;
        });
        $truncateStatement = null;
        foreach ($tables as $table) {
            $truncateStatement .= "SELECT '$table' AS table_name, COUNT(*) AS count FROM $databaseName.$table HAVING count > 0 UNION ";
        }
        $res = $connection->execute($truncateStatement);
        debug($res->fetchAll());
    }

    /**
     * Truncate tables that are reported dirty by the database behind the given connection name
     * This is much faster than truncating all the tables for large databases
     * Currently, only an implementation supporting Mysql and MariaDB is supported
     */
    private function truncateDirtyTables(string $connectionName)
    {
        $connection = $this->getConnection($connectionName);
        $driver = $connection->config()['driver'];
        switch ($driver) {
            case Mysql::class:
                $this->truncateDirtyTablesMySQL($connection);
                break;
            case Sqlite::class:
                $this->truncateDirtyTablesSqlite($connection);
                break;
            default:
                throw new \PHPUnit\Framework\Exception("The DB driver $driver is not being supported");
                break;
        }
    }

    /**
     * @param ConnectionInterface $connection
     */
    private function truncateDirtyTablesMySQL(ConnectionInterface $connection)
    {
        $databaseName = $connection->config()['database'];
        $res = $connection->execute("
            SELECT table_name, table_rows
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = '$databaseName' and AUTO_INCREMENT > 1;
        ");
        $dirtyTables = [];
        foreach($res->fetchAll() as $tableData) {
            if ($tableData[0] !== 'phinxlog') {
                $dirtyTables[] = $tableData[0];
            }
        }
        if (count($dirtyTables)) {
            $truncateStatement = "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `" . implode("`; TRUNCATE TABLE `", $dirtyTables) . "`; SET FOREIGN_KEY_CHECKS=1;";
            $connection->execute($truncateStatement);
        }
    }

    /**
     * @param ConnectionInterface $connection
     */
    private function truncateDirtyTablesSqlite($connection)
    {
        $tables = $connection->execute("
             SELECT name FROM sqlite_master WHERE type='table' AND name NOT IN ('sqlite_sequence', 'phinxlog');
        ")->fetchAll();
        $tables = Hash::extract($tables, '{n}.0');

        $connection->transactional(function(ConnectionInterface $connection) use ($tables) {
            $connection->execute('pragma foreign_keys = off;');
            foreach ($tables as $table) {
                $connection
                    ->newQuery()
                    ->delete($table)
                    ->execute();
                $connection
                    ->newQuery()
                    ->delete('sqlite_sequence')
                    ->where(['name' => $table])
                    ->execute();
            }
            $connection->execute('pragma foreign_keys = on;');
        });
    }

    /**
     * Truncate all tables for the given connection name
     * @deprecated
     */
    private function truncateAllTables(string $connectionName)
    {
        $tables = $this->getConnection($connectionName)->getSchemaCollection()->listTables();

        foreach ($tables as $i => $table) {
            if (strpos($table, 'phinxlog') !== false) {
                unset($tables[$i]);
            }
        }

        if (!empty($tables)) {
            $start = microtime(true);
            $this->getConnection($connectionName)->execute(
                "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `" . implode("`; TRUNCATE TABLE `",
                    $tables) . "`; SET FOREIGN_KEY_CHECKS=1;"
            );
            debug("SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `" . implode("`; TRUNCATE TABLE `",
                    $tables) . "`; SET FOREIGN_KEY_CHECKS=1;");
            $time_elapsed_secs = microtime(true) - $start;
            debug("elapsed time : $time_elapsed_secs");
        }
    }
}
