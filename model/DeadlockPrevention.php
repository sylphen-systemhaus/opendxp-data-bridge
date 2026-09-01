<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\TransactionIsolationLevel;
use PDOException;
use OpenDxp\Db;
use OpenDxp\Logger;

class DeadlockPrevention
{
    const MAX_RETRIES = 5;

    /** @var callable */
    private $callbackFunction;

    /** @var callable */
    private $rollbackFunction;

    private $retriesLeft = self::MAX_RETRIES;

    /**
     * @param callable $callbackFunction
     * @param callable|null $rollbackFunction
     */
    public function __construct(callable $callbackFunction, callable $rollbackFunction = null)
    {
        $this->callbackFunction = $callbackFunction;
        $this->rollbackFunction = $rollbackFunction;
    }

    public function run()
    {
        $this->retriesLeft = self::MAX_RETRIES;
        while($this->retriesLeft > 0) {
            try {
                PimcoreDbRepository::getInstance()->beginTransaction();
                $result = ($this->callbackFunction)();

                try {
                    PimcoreDbRepository::getInstance()->commit();
                } catch (\Throwable $e) {
                    // transaction got committed by implicit commit (e.g. via database change)
                    PimcoreDbRepository::getInstance()->close();
                }

                return $result;
            } catch (\Throwable $e) {
                // we try to start the transaction $maxRetries times again (deadlocks, ...)
                if (($e instanceof RetryableException || ($e instanceof PDOException && $e->getCode() == 1205)) && $this->retriesLeft-- > 0) {
                    while (PimcoreDbRepository::getInstance()->isTransactionActive()) {
                        try {
                            PimcoreDbRepository::getInstance()->rollback();
                        } catch (\Throwable $rollbackException) {
                            break;
                        }
                    }

                    if (is_callable($this->rollbackFunction)) {
                        ($this->rollbackFunction)();
                    }

                    $waitTime = random_int(1, 5) * 100000; // microseconds

                    usleep($waitTime); // wait specified time until we restart the transaction

                    Logger::info('Restarting transaction');
                } else {
                    throw $e;
                }
            }
        }
    }

    public function deadlockHappened() {
        return $this->retriesLeft !== self::MAX_RETRIES;
    }
}