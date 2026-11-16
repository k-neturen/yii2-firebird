<?php

/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace neturen\db\firebird;

use PDO;
use yii\base\NotSupportedException;
use yii\db\Transaction;

/**
 * Description of PdoAdapter
 *
 * @author Edgard Lorraine Messias <edgard.cpd@romera.com.br>
 */
class PdoAdapter extends PDO
{

    private $_inTransaction = false;

    /**
     * @var int|null isolation level used when a transaction is started without one (PHP >= 8.4 only)
     */
    private $_defaultIsolationLevel;

    /**
     * Do some basic setup for Firebird.
     * o Force use of exceptions on error.
     * o Force all metadata to lower case.
     *   Yii will behave in unpredicatable ways if
     *   metadata is not lowercase.
     * o Ensure that table names are not prefixed to
     *    fieldnames when returning metadata.
     * Finally call parent constructor.
     *
     */
    public function __construct($dsn, $username, $password, $driver_options = [])
    {
        // Windows OS paths with backslashes should be changed
        $dsn = str_replace('\\', '/', $dsn);
        // apply error mode
        $driver_options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        // lower case column names in results are necessary for Yii ActiveRecord proper functioning
        $driver_options[PDO::ATTR_CASE] = PDO::CASE_LOWER;
        // ensure we only receive fieldname not tablename.fieldname.
        $driver_options[PDO::ATTR_FETCH_TABLE_NAMES] = false;
        parent::__construct($dsn, $username, $password, $driver_options);

        if (static::hasNativeTransactions()) {
            $this->_defaultIsolationLevel = $this->getAttribute(\Pdo\Firebird::TRANSACTION_ISOLATION_LEVEL);
        }
    }

    /**
     * Since PHP 8.4 pdo_firebird always keeps its own transaction handle open, so transactions
     * can no longer be controlled with `SET TRANSACTION`/`COMMIT`/`ROLLBACK` statements and
     * the native PDO transaction API must be used instead.
     * @return bool whether native PDO transactions (with isolation level support) are used.
     */
    public static function hasNativeTransactions()
    {
        return PHP_VERSION_ID >= 80400;
    }

    /**
     * Initiates a transaction
     * @param string|false|null $isolationLevel the isolation level. On PHP < 8.4 this is a Firebird
     * isolation level clause, `false` to let [[Schema::setTransactionIsolationLevel()]] start the transaction
     * or `null` for the default. On PHP >= 8.4 this is one of the [[Transaction]] isolation level constants
     * or the equivalent Firebird clause.
     * @return bool <b>TRUE</b> on success or <b>FALSE</b> on failure.
     */
    public function beginTransaction($isolationLevel = null) : bool
    {
        if (static::hasNativeTransactions()) {
            $level = $isolationLevel === null || $isolationLevel === false
                ? $this->_defaultIsolationLevel
                : $this->resolveNativeIsolationLevel($isolationLevel);
            $this->setAttribute(\Pdo\Firebird::TRANSACTION_ISOLATION_LEVEL, $level);

            return parent::beginTransaction();
        }

        $this->setAttribute(PDO::ATTR_AUTOCOMMIT, false);

        if ($isolationLevel === false) {
            $this->_inTransaction = true;
            return true;
        }

        if ($isolationLevel === null) {
            $r = $this->exec('SET TRANSACTION NO WAIT');
            $success = ($r !== false);
            if ($success) {
                $this->_inTransaction = true;
            }
            return ($success);
        }

        $r = $this->exec("SET TRANSACTION ISOLATION LEVEL $isolationLevel NO WAIT");
        $success = ($r !== false);
        if ($success) {
            $this->_inTransaction = true;
        }
        return ($success);
    }

    /**
     * Commits a transaction
     * @return bool <b>TRUE</b> on success or <b>FALSE</b> on failure.
     */
    public function commit(): bool
    {
        if (static::hasNativeTransactions()) {
            return parent::commit();
        }

        $r = $this->exec('COMMIT');
        $this->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $success = ($r !== false);
        if ($success) {
            $this->_inTransaction = false;
        }
        return ($success);
    }

    /**
     * Rolls back a transaction
     * @return bool <b>TRUE</b> on success or <b>FALSE</b> on failure.
     */
    public function rollBack(): bool
    {
        if (static::hasNativeTransactions()) {
            return parent::rollBack();
        }

        $r = $this->exec('ROLLBACK');
        $this->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $success = ($r !== false);
        if ($success) {
            $this->_inTransaction = false;
        }
        return ($success);
    }

    /**
     * Checks if inside a transaction
     * @return bool <b>TRUE</b> if a transaction is currently active, and <b>FALSE</b> if not.
     */
    public function inTransaction(): bool
    {
        if (static::hasNativeTransactions()) {
            return parent::inTransaction();
        }

        return $this->_inTransaction;
    }

    /**
     * Maps a Yii or Firebird isolation level to a `Pdo\Firebird` isolation level constant.
     * @param string $isolationLevel
     * @return int
     * @throws NotSupportedException if the isolation level is not supported by pdo_firebird
     */
    protected function resolveNativeIsolationLevel($isolationLevel)
    {
        switch (strtoupper(trim($isolationLevel))) {
            case Transaction::READ_UNCOMMITTED:
            case Transaction::READ_COMMITTED:
            case 'READ COMMITTED RECORD_VERSION':
                return \Pdo\Firebird::READ_COMMITTED;
            case Transaction::REPEATABLE_READ:
            case 'SNAPSHOT':
                return \Pdo\Firebird::REPEATABLE_READ;
            case Transaction::SERIALIZABLE:
            case 'SNAPSHOT TABLE STABILITY':
                return \Pdo\Firebird::SERIALIZABLE;
        }

        throw new NotSupportedException("Transaction isolation level '$isolationLevel' is not supported by pdo_firebird.");
    }
}
