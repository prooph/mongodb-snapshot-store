<?php

/**
 * This file is part of the prooph/mongodb-snapshot-store.
 * (c) 2016-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\SnapshotStore\MongoDb;

use MongoDB\Client;

abstract class TestUtil
{
    public static function getClient(): Client
    {
        $connectionParams = self::getConnectionParams();

        $authString = ! empty($connectionParams['user'])
            ? $connectionParams['user'] . ':' . $connectionParams['password'] . '@'
            : '';

        $uri = 'mongodb://' . $authString . $connectionParams['host'] .'/';

        return new Client($uri);
    }

    public static function getDatabaseName(): string
    {
        if (! self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return $GLOBALS['mongo_dbname'];
    }

    public static function getConnectionParams(): array
    {
        if (! self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return self::getSpecifiedConnectionParams();
    }

    private static function hasRequiredConnectionParams(): bool
    {
        return isset(
            $GLOBALS['mongo_username'],
            $GLOBALS['mongo_password'],
            $GLOBALS['mongo_host'],
            $GLOBALS['mongo_dbname'],
            $GLOBALS['mongo_port']
        );
    }

    private static function getSpecifiedConnectionParams(): array
    {
        return [
            'user' => $GLOBALS['mongo_username'],
            'password' => $GLOBALS['mongo_password'],
            'host' => $GLOBALS['mongo_host'],
            'dbname' => $GLOBALS['mongo_dbname'],
            'port' => $GLOBALS['mongo_port'],
        ];
    }
}
