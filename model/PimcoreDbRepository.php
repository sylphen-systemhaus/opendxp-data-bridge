<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\Tools\Installer;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception\NoIdentityValue;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Type;
use Exception;
use PDOException;
use OpenDxp;
use OpenDxp\Cache;
use OpenDxp\Db;
use Doctrine\DBAL\Connection;
use OpenDxp\Logger;
use Throwable;

class PimcoreDbRepository implements Repository
{
    /** @var Connection */
    protected $connection;

    /** @var Statement[] */
    protected static $preparedStatements = [];

    /** @var self */
    private static $instances = [];

    /** @var array for tracking executed SQL queries, uncomment all occurences of self::$debug, too */
    //private static $debug = [];

    private static $maxAllowedPacket;

    private static $dataTypeForString;
    private static $dataTypeForInt;
    private static $dataTypeForDateTime;
    private static $dataTypeForStringArray;
    private static $dataTypeForIntArray;

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? Db::get();
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::READ_UNCOMMITTED);

        /*$debugFunction = function () {
            arsort(self::$debug);
            error_log('SQL Queries: '.print_r(self::$debug, true));
        };
        register_shutdown_function($debugFunction);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            $cliAbortFunction = static function ($restart = false) use ($debugFunction) {
                $debugFunction();
                exit(1);
            };
            pcntl_signal(SIGINT, static function () use ($debugFunction) {
                $debugFunction();
            }); // SIGINT is sent by the TTY driver to the current foreground job when the interactive attention character (typically ^C, which has ASCII code 3) appears in the input stream
            pcntl_signal(SIGTERM, static function () use ($debugFunction) {
                // Symfony process first tries to stop process with SIGTERM in Process::stop()
                $debugFunction();
            });
            pcntl_signal(SIGHUP, $debugFunction); // SIGHUP is sent by the UART driver to the entire session when a hangup condition has been detected.
        }*/
    }

    /**
     * @param Connection|null $connection
     * @return static
     */
    public static function getInstance(?Connection $connection = null) {
        if(!isset(self::$instances[get_called_class()])) {
            self::$instances[get_called_class()] = new static($connection);
        }

        return self::$instances[get_called_class()];
    }

    public function getTableName() {
        throw new Exception('Please implement getTableName() in a model class');
    }

    private function executeSql($sql, $parameters)
    {
        $parameters = array_values($parameters);
        $dataTypes = $this->getDataTypes($parameters);

        /*if (is_string($sql)){
            self::$debug[$sql] = isset(self::$debug[$sql]) ? self::$debug[$sql] + 1 : 1;
        }*/

        if (in_array(self::getDataTypeForStringArray(), $dataTypes, true) || in_array(self::getDataTypeForIntArray(), $dataTypes, true)) {
            if(class_exists(\Doctrine\DBAL\SQLParserUtils::class)) {
                [$sql, $parameters, $dataTypes] = \Doctrine\DBAL\SQLParserUtils::expandListParameters($sql, $parameters, $dataTypes);
            }
            return $this->connection->executeQuery($sql, $parameters, $dataTypes);
        }

        if (is_string($sql) && !isset(self::$preparedStatements[$sql])) {
            self::$preparedStatements[$sql] = self::prepare($sql);
        }

        if($sql instanceof Statement) {
            $statement = $sql;
        } else {
            $statement = self::$preparedStatements[$sql];
        }

        foreach ($parameters as $index => $parameter) {
            $statement->bindValue($index + 1, $parameter, $dataTypes[$index]);
        }

        return $statement->executeQuery();
    }

    public function execute($sql, $parameters = [])
    {
        $result = $this->executeSql($sql, $parameters);
        if($result instanceof Result || $result instanceof \Doctrine\DBAL\Driver\Statement) {
            return $result->rowCount();
        }
        return self::$preparedStatements[$sql]->rowCount();
    }

    public function findOneInSql($sql, $parameters = [])
    {
        $result = $this->findRowInSql($sql, $parameters);
        if($result === null) {
            return false;
        }
        return reset($result);
    }

    public function findRowInSql($sql, $parameters = [])
    {
        $result = $this->findInSql($sql, $parameters);
        $result = reset($result);
        if($result === false) {
            return null;
        }
        return $result;
    }

    public function findColumnInSql($sql, $parameters = [])
    {
        $result = $this->findInSql($sql, $parameters);
        return array_map(static function($row) {
            return reset($row);
        }, $result);
    }

    public function findInSql($sql, $parameters = [])
    {
        $result = $this->executeSql($sql, $parameters);

        if ($result instanceof Result || $result instanceof \Doctrine\DBAL\Driver\Statement) {
            if(method_exists($result, 'fetchAllAssociative')) {
                return $result->fetchAllAssociative();
            }
            return $result->fetchAll(FetchMode::ASSOCIATIVE);
        }
        return self::$preparedStatements[$sql]->fetchAll(FetchMode::ASSOCIATIVE);
    }

    public function findInTable($table, array $where = [], ?string $order = null, ?int $count = null, int $offset = 0, ?string $groupBy = null, array $columns = ['*']): array
    {
        $parametersHash = md5(json_encode([$table, array_keys($where), $order, $count, $offset, $groupBy, $columns]));
        if (isset(self::$preparedStatements[$parametersHash])) {
            try {
                $result = $this->executeSql(self::$preparedStatements[$parametersHash], $where);
                if ($result instanceof Result || $result instanceof \Doctrine\DBAL\Driver\Statement) {
                    if (method_exists($result, 'fetchAllAssociative')) {
                        return $result->fetchAllAssociative();
                    }
                    return $result->fetchAll(FetchMode::ASSOCIATIVE);
                }
                return self::$preparedStatements[$parametersHash]->fetchAll(FetchMode::ASSOCIATIVE);
            } catch(\Exception $e) {
                unset(self::$preparedStatements[$parametersHash]);
            }
        }

        $cache = true;

        $queryBuilder = new QueryBuilder($this->connection);
        $queryBuilder = $queryBuilder->select(implode(',', $columns))->from($table);
        if (!empty($where)) {
            foreach ($where as $value) {
                if (is_array($value) || $value === null) {
                    $cache = false;
                    break;
                }
            }

            if ($cache) {
                $queryBuilder = $queryBuilder->where(implode(' AND ', array_keys($where)));
            } else {
                $conditions = [];
                foreach ($where as $condition => $value) {
                    if (is_array($value)) {
                        $value = array_map([$this->connection, 'quote'], $value);
                        $conditions[] = str_replace('?', implode(',', $value), $condition);
                    } else {
                        if($value === null) {
                            $value = '';
                        }
                        $conditions[] = str_replace('?', $this->connection->quote($value), $condition);
                    }
                }
                $queryBuilder = $queryBuilder->where(implode(' AND ', $conditions));
            }
        }
        if (!empty($order)) {
            if(method_exists($queryBuilder, 'add')) {
                $queryBuilder = $queryBuilder->add('orderBy', $order);
            } else {
                $queryBuilder = $queryBuilder->orderBy($order);
            }
        }
        if ($count > 0) {
            $queryBuilder = $queryBuilder->setMaxResults($count);
        }
        $queryBuilder = $queryBuilder->setFirstResult($offset);

        if($groupBy !== null) {
            if(strtolower($groupBy) === 'distinct') {
                $queryBuilder->distinct();
            } else {
                $queryBuilder->groupBy($groupBy);
            }
        }

        if ($cache) {
            self::$preparedStatements[$parametersHash] = self::prepare($queryBuilder->getSQL());
            return $this->findInTable($table, $where, $order, $count, $offset);
        }

        try {
            return $this->connection->fetchAllAssociative($queryBuilder->getSQL());
        } catch(\Throwable $e) {
            if(method_exists($this->connection, 'fetchAll')) {
                return $this->connection->fetchAll($queryBuilder->getSQL());
            }
            Cache::remove('data_bridge_installed');
            OpenDxp::getContainer()->get(Installer::class)->install();
        }

        die($queryBuilder->getSQL());
    }

    public function find(array $where = [], ?string $order = null, ?int $count = null, int $offset = 0, ?string $groupBy = null, array $columns = ['*']): array
    {
        return $this->findInTable($this->getTableName(), $where, $order, $count, $offset, $groupBy, $columns);
    }

    public function findOneInTable($table, array $where = [], ?string $order = null, int $offset = 0, ?string $groupBy = null): array
    {
        $result = $this->findInTable($table, $where, $order, 1, $offset, $groupBy);

        return $result[0] ?? [];
    }

    public function findOne(array $where = [], ?string $order = null, int $offset = 0, ?string $groupBy = null): array {
        return $this->findOneInTable($this->getTableName(), $where, $order, $offset, $groupBy) ?? [];
    }

    public function countRows(array $where = [], $groupBy = null) {
        $queryBuilder = new QueryBuilder($this->connection);
        $queryBuilder = $queryBuilder->select(($groupBy === null) ? 'COUNT(*)' : '1')->from($this->getTableName());
        if(!empty($where)) {
            $conditions = [];
            foreach($where as $condition => $value) {
                if(is_array($value)) {
                    $conditions[] = str_replace('?', implode(',', array_map([$this->connection, 'quote'], $value)), $condition);
                } else {
                    $conditions[] = str_replace('?', $this->connection->quote($value), $condition);
                }
            }
            $queryBuilder = $queryBuilder->where(implode(' AND ', $conditions));
        }

        if($groupBy !== null) {
            $queryBuilder->groupBy($groupBy);

            return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ('.$queryBuilder->getSQL().') t');
        }

        return (int)$this->connection->fetchOne($queryBuilder->getSQL());
    }

    public function create(array $data)
    {
        $parametersHash = md5($this->getTableName().'-'.implode('-', array_keys($data)));
        if (!isset(self::$preparedStatements[$parametersHash])) {
            $sql = 'INSERT INTO '.$this->getTableName().' ('.implode(', ', array_map([$this->connection, 'quoteIdentifier'], array_keys($data))).') VALUES ('.rtrim(str_repeat('?,', count($data)), ',').')';
            self::$preparedStatements[$parametersHash] = self::prepare($sql);
            self::$preparedStatements[$parametersHash.'-types'] = $this->getDataTypes($data);
        } else {
            $this->getDataTypes($data);
        }

        foreach (array_values($data) as $key => $dataItem) {
            self::$preparedStatements[$parametersHash]->bindValue($key + 1, $dataItem, self::$preparedStatements[$parametersHash.'-types'][$key]);
        }

        $result = self::$preparedStatements[$parametersHash]->executeQuery();
        if($result instanceof Result || $result instanceof \Doctrine\DBAL\Driver\Statement) {
            $insertedRows = $result->rowCount();
        } else {
            $insertedRows = self::$preparedStatements[$parametersHash]->rowCount();
        }

        if ($insertedRows > 0) {
            try {
                $data['id'] = $this->connection->lastInsertId();
            } catch(Throwable $e) {}

            return $data;
        }

        return false;
    }

    public function update($data, $where)
    {
        if(!is_array($where)) {
            $where = ['id' => $where];
        }

        $query = 'UPDATE '.$this->getTableName().' SET '.implode(',', array_map(function($field){
            return $this->connection->quoteIdentifier($field).'=?';
        }, array_keys($data))).'
            WHERE '.implode(' AND ', array_map(function ($field) {
            return $this->connection->quoteIdentifier($field).'=?';
        }, array_keys($where)));
        return $this->execute($query, array_merge(array_values($data), array_values($where)));
    }

    public function createOrUpdate(array $data, $table = null)
    {
        if ($data && !isset($data[0])) {
            $data = [$data];
        }

        $batches = [];
        // build batches with identical columns
        foreach ($data as $dataset) {
            $batches[implode('-', array_keys($dataset))][] = $dataset;
        }

        if ($table === null) {
            $table = $this->getTableName();
        }

        foreach($batches as $batch) {
            $columnList = array_keys($batch[0]);

            $countColumnList = count($columnList);
            if ($countColumnList === 0) {
                continue;
            }

            $query = 'INSERT INTO '.$table.' ('.implode(',', array_map([$this->connection, 'quoteIdentifier'], $columnList)).') VALUES ';

            $paramValues = [];
            $dataLength = 0;
            foreach ($batch as $dataset) {
                foreach ($dataset as $value) {
                    $paramValues[] = $value;
                    if(is_scalar($value)) {
                        $dataLength += strlen((string)$value);
                    }
                }
            }

            try {
                if ($dataLength > self::getMaxAllowedPacket() * 0.8) {
                    throw new \Exception('Got a packet bigger than \'max_allowed_packet\' bytes');
                }

                $updateList = array_map(function ($column) {
                    return $this->connection->quoteIdentifier($column).'=VALUES('.$this->connection->quoteIdentifier($column).')';
                }, $columnList);

                $query .= rtrim(str_repeat('('.rtrim(\str_repeat('?,', $countColumnList), ',').'),', count($batch)), ',').' ON DUPLICATE KEY UPDATE '.implode(',', $updateList);

                $this->execute($query, $paramValues);
            } catch(\Throwable $e) {
                if(count($batch) > 1 && strpos($e->getMessage(), 'Got a packet bigger than \'max_allowed_packet\' bytes') !== false) {
                    $batchSplit = array_chunk($batch, ceil(count($batch) / 2));
                    $this->createOrUpdate($batchSplit[0], $table);
                    if(isset($batchSplit[1])) {
                        $this->createOrUpdate($batchSplit[1], $table);
                    }
                } else {
                    throw $e;
                }
            }
        }
    }

    public function delete($id)
    {
        if (!empty($id)) {
            return $this->deleteWhere(['id' => $id]);
        }

        return 0;
    }

    public function deleteWhere(array $where = [])
    {
        return $this->connection->delete($this->getTableName(), $where);
    }

    public function get($id): array {
        return $this->findOne(['id = ?' => (int)$id]);
    }

    public function beginTransaction() {
        $this->connection->beginTransaction();
    }

    public function setTransactionIsolation($level) {
        $this->connection->setTransactionIsolation($level);
    }

    public function commit() {
        $this->connection->commit();
    }

    public function close()
    {
        $this->connection->close();
    }

    public function rollback() {
        $this->connection->rollBack();
    }

    public function isTransactionActive() {
        return $this->connection->isTransactionActive();
    }

    public function getTransactionNestingLevel() {
        return $this->connection->getTransactionNestingLevel();
    }

    public function isTransactionMarkedForRollbackOnly() {
        try {
            return $this->connection->isRollbackOnly();
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * @param array $data
     *
     * @return array
     */
    public function getDataTypes(array &$data): array
    {
        $types = [];
        foreach($data as &$item) {
            if ($item instanceof DateTimeInterface) {
                $item->setTimezone(new DateTimeZone('UTC'));
                $item = $item->format('Y-m-d H:i:s');
            }

            $types[] = $this->getDataType($item);
        }

        return $types;
    }

    private function getDataType($item) {
        if (is_string($item)) {
            return self::getDataTypeForString();
        }

        if (is_int($item)) {
            return self::getDataTypeForInt();
        }

        if (is_array($item)) {
            $isIntArray = true;
            foreach ($item as $arrayItem) {
                if (!is_int($arrayItem)) {
                    $isIntArray = false;
                    break;
                }
            }

            if ($isIntArray) {
                return self::getDataTypeForIntArray();
            }

            return self::getDataTypeForStringArray();
        }

        return self::getDataTypeForString();
    }

    private static function getDataTypeForStringArray() {
        if(self::$dataTypeForStringArray === null) {
            self::$dataTypeForStringArray = class_exists(ArrayParameterType::class) ? ArrayParameterType::STRING : Connection::PARAM_STR_ARRAY;
        }
        return self::$dataTypeForStringArray;
    }

    private static function getDataTypeForIntArray()
    {
        if (self::$dataTypeForIntArray === null) {
            self::$dataTypeForIntArray = class_exists(ArrayParameterType::class) ? ArrayParameterType::INTEGER : Connection::PARAM_INT_ARRAY;
        }
        return self::$dataTypeForIntArray;
    }

    private static function getDataTypeForString()
    {
        if (self::$dataTypeForString === null) {
            self::$dataTypeForString = class_exists(ParameterType::class) ? ParameterType::STRING : \PDO::PARAM_STR;
        }
        return self::$dataTypeForString;
    }

    private static function getDataTypeForInt()
    {
        if (self::$dataTypeForInt === null) {
            self::$dataTypeForInt = class_exists(ParameterType::class) ? ParameterType::INTEGER : \PDO::PARAM_INT;
        }
        return self::$dataTypeForInt;
    }

    public static function prepare($sql) {
        $parametersHash = md5($sql);

        if (!isset(self::$preparedStatements[$parametersHash])) {
            self::$preparedStatements[$parametersHash] = Db::get()->prepare($sql);
        }

        return self::$preparedStatements[$parametersHash];
    }

    public static function retry(callable $function, ?callable $rollbackFunction = null)
    {
        $maxRetries = 5;
        for ($retries = 0; $retries < $maxRetries; $retries++) {
            try {
                self::getInstance()->beginTransaction();
                $result = $function();
                try {
                    self::getInstance()->commit();
                } catch (\Throwable $e) {
                    // implicit commit happened in meantime
                }
                return $result;
            } catch (\Throwable $e) {
                try {
                    self::getInstance()->rollback();
                } catch (\Throwable $rollbackException) {
                }

                // we try to start the transaction $maxRetries times again (deadlocks, ...)
                if(($e instanceof RetryableException || (($e instanceof PDOException || $e instanceof DBALException) && in_array($e->getCode(), [1205, 1213]))) && $retries < $maxRetries - 1) {
                    if(is_callable($rollbackFunction)) {
                        $rollbackFunction();
                    }

                    $waitTime = random_int(1, 5) * 100000; // microseconds

                    usleep($waitTime); // wait specified time until we restart the transaction

                    Logger::debug('Restarting transaction');
                } else {
                    throw $e;
                }
            }
        }
    }

    public function insertOrUpdate($table, array $data)
    {
        $bind = [];
        $cols = [];
        foreach ($data as $col => $val) {
            $cols[] = $this->connection->quoteIdentifier($col);
            $bind[] = $val;
        }

        $set = [];
        foreach ($cols as $col) {
            $set[] = $col.' = ?';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->connection->quoteIdentifier($table),
            implode(', ', $cols),
            rtrim(str_repeat('?,', count($cols)), ','),
            implode(', ', $set)
        );

        $bind = array_merge($bind, $bind);

        return $this->executeSql($sql, $bind);
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    public static function clearPreparedStatements() {
        self::$preparedStatements = [];
    }

    public static function getMaxAllowedPacket() {
        if (self::$maxAllowedPacket === null) {
            self::$maxAllowedPacket = self::getInstance()->findOneInSql('SELECT @@max_allowed_packet') ?: 16777216;
        }
        return self::$maxAllowedPacket;
    }
}