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

namespace ProophTest\MongoDB\SnapshotStore;

use MongoDB\Client;
use MongoDB\Driver\ReadPreference;
use MongoDB\Operation\Find;
use PHPUnit_Framework_TestCase as TestCase;
use Prooph\EventSourcing\Aggregate\AggregateType;
use Prooph\EventSourcing\Snapshot\Snapshot;
use Prooph\MongoDB\SnapshotStore\MongoDBSnapshotStore;

class MongoDBSnapshotStoreTest extends TestCase
{
    /**
     * @var MongoDBSnapshotStore
     */
    private $snapshotStore;

    /**
     * @var Client
     */
    private $client;

    /**
     * @test
     */
    public function it_saves_and_reads()
    {
        $aggregateType = AggregateType::fromString('baz');
        $aggregateRoot = new \stdClass();
        $aggregateRoot->foo = 'bar';

        $time = (string) microtime(true);
        if (false === strpos($time, '.')) {
            $time .= '.0000';
        }

        $now = \DateTimeImmutable::createFromFormat('U.u', $time);

        $snapshot = new Snapshot($aggregateType, 'id', $aggregateRoot, 1, $now);

        $this->snapshotStore->save($snapshot);

        $snapshot = new Snapshot($aggregateType, 'id', $aggregateRoot, 2, $now);

        $this->snapshotStore->save($snapshot);

        $this->assertNull($this->snapshotStore->get($aggregateType, 'invalid'));

        $readSnapshot = $this->snapshotStore->get($aggregateType, 'id');
        var_dump($readSnapshot);
        die;
        $this->assertEquals($snapshot, $readSnapshot);

        $server = $this->client->getManager()->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));
        $operation = new Find(TestUtil::getDatabaseName(), 'snapshots.files', []);
        $cursor = $operation->execute($server);

        $this->assertCount(1, $cursor);
    }

    /**
     * @test
     */
    public function it_uses_custom_snapshot_table_map()
    {
        $aggregateType = AggregateType::fromString('foo');
        $aggregateRoot = new \stdClass();
        $aggregateRoot->foo = 'bar';
        $time = (string) microtime(true);

        if (false === strpos($time, '.')) {
            $time .= '.0000';
        }

        $now = \DateTimeImmutable::createFromFormat('U.u', $time);

        $snapshot = new Snapshot($aggregateType, 'id', $aggregateRoot, 1, $now);

        $this->snapshotStore->save($snapshot);

        $server = $this->client->getManager()->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));
        $operation = new Find(TestUtil::getDatabaseName(), 'bar.files', []);
        $cursor = $operation->execute($server);

        $this->assertCount(1, $cursor->toArray());
    }

    protected function setUp(): void
    {
        $this->client = TestUtil::getClient();

        $this->snapshotStore = new MongoDBSnapshotStore(
            $this->client,
            TestUtil::getDatabaseName(),
            ['foo' => 'bar'],
            'snapshots'
        );
    }

    protected function tearDown(): void
    {
        $this->client->dropDatabase(TestUtil::getDatabaseName());
    }
}
