<?php
/**
 * This file is part of the prooph/mongodb-snapshot-store.
 * (c) 2016-2016 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2016 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\MongoDB\SnapshotStore\Container;

use Interop\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use Prooph\MongoDB\SnapshotStore\Container\MongoDBSnapshotStoreFactory;
use Prooph\MongoDB\SnapshotStore\MongoDBSnapshotStore;
use ProophTest\MongoDB\SnapshotStore\TestUtil;

class MongoDBSnapshotStoreFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_adapter_via_connection_service(): void
    {
        $config['prooph']['mongodb_snapshot_store']['default'] = [
            'mongo_client_service' => 'my_connection',
        ];

        $client = TestUtil::getClient();

        $container = $this->prophesize(ContainerInterface::class);

        $container->get('my_connection')->willReturn($client)->shouldBeCalled();
        $container->get('config')->willReturn($config)->shouldBeCalled();

        $factory = new MongoDBSnapshotStoreFactory();
        $snapshotStore = $factory($container->reveal());

        $this->assertInstanceOf(MongoDBSnapshotStore::class, $snapshotStore);
    }

    /**
     * @test
     */
    public function it_creates_adapter_via_connection_options(): void
    {
        $config['prooph']['mongodb_snapshot_store']['custom'] = [
            'connection_options' => TestUtil::getConnectionParams(),
        ];

        $container = $this->prophesize(ContainerInterface::class);

        $container->get('config')->willReturn($config)->shouldBeCalled();

        $snapshotStoreName = 'custom';
        $snapshotStore = MongoDBSnapshotStoreFactory::$snapshotStoreName($container->reveal());

        $this->assertInstanceOf(MongoDBSnapshotStore::class, $snapshotStore);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_invalid_container_given(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $eventStoreName = 'custom';
        MongoDBSnapshotStoreFactory::$eventStoreName('invalid container');
    }
}
