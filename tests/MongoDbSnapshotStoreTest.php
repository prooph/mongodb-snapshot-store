<?php
/**
 * This file is part of the prooph/mongodb-snapshot-store.
 * (c) 2016-2017 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2017 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\SnapshotStore\MongoDb;

use DateTimeImmutable;
use DateTimeZone;
use MongoDB\Client;
use MongoDB\Driver\ReadPreference;
use MongoDB\Operation\Find;
use PHPUnit\Framework\TestCase;
use Prooph\SnapshotStore\MongoDb\MongoDbSnapshotStore;
use Prooph\SnapshotStore\Snapshot;

class MongoDbSnapshotStoreTest extends TestCase
{
    /**
     * @var MongoDbSnapshotStore
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
        $aggregateRoot = ['name' => 'Sascha'];
        $aggregateType = 'user';

        $time = (string) microtime(true);
        if (false === strpos($time, '.')) {
            $time .= '.0000';
        }

        $now = DateTimeImmutable::createFromFormat('U.u', $time, new DateTimeZone('UTC'));

        $snapshot = new Snapshot($aggregateType, 'id', $aggregateRoot, 1, $now);

        $this->snapshotStore->save($snapshot);

        $snapshot = new Snapshot($aggregateType, 'id', $aggregateRoot, 2, $now);

        $this->snapshotStore->save($snapshot);

        $this->assertNull($this->snapshotStore->get($aggregateType, 'invalid'));

        $readSnapshot = $this->snapshotStore->get($aggregateType, 'id');
        $this->assertEquals($snapshot, $readSnapshot);

        $server = $this->client->getManager()->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));
        $operation = new Find(TestUtil::getDatabaseName(), 'snapshots.files', []);
        $cursor = $operation->execute($server);

        $this->assertCount(1, $cursor->toArray());
    }

    /**
     * @test
     */
    public function it_saves_multiple_snapshots_and_removes_them()
    {
        $aggregateRoot = ['name' => 'Sascha'];
        $aggregateType = 'user';

        $time = (string) microtime(true);
        if (false === strpos($time, '.')) {
            $time .= '.0000';
        }

        $now = DateTimeImmutable::createFromFormat('U.u', $time, new DateTimeZone('UTC'));

        $snapshot1 = new Snapshot($aggregateType, 'id1', $aggregateRoot, 1, $now);

        $snapshot2 = new Snapshot($aggregateType, 'id2', $aggregateRoot, 2, $now);

        $snapshot3 = new Snapshot('bar', 'id3', $aggregateRoot, 1, $now);

        $snapshot4 = new Snapshot(\stdClass::class, 'id4', $aggregateRoot, 1, $now);

        $this->snapshotStore->save($snapshot1, $snapshot2, $snapshot3, $snapshot4);

        $readSnapshot = $this->snapshotStore->get($aggregateType, 'id1');
        $this->assertEquals($snapshot1, $readSnapshot);

        $readSnapshot = $this->snapshotStore->get($aggregateType, 'id2');
        $this->assertEquals($snapshot2, $readSnapshot);

        $readSnapshot = $this->snapshotStore->get('bar', 'id3');
        $this->assertEquals($snapshot3, $readSnapshot);

        $readSnapshot = $this->snapshotStore->get(\stdClass::class, 'id4');
        $this->assertEquals($snapshot4, $readSnapshot);

        $this->snapshotStore->removeAll($aggregateType);

        $this->assertNull($this->snapshotStore->get($aggregateType, 'id1'));
        $this->assertNull($this->snapshotStore->get($aggregateType, 'id2'));

        $readSnapshot = $this->snapshotStore->get('bar', 'id3');
        $this->assertEquals($snapshot3, $readSnapshot);

        $this->snapshotStore->removeAll(\stdClass::class);

        $this->assertNull($this->snapshotStore->get(\stdClass::class, 'id4'));
    }

    /**
     * @test
     */
    public function it_uses_custom_snapshot_table_map()
    {
        $aggregateType = \stdClass::class;
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

        $this->snapshotStore = new MongoDbSnapshotStore(
            $this->client,
            TestUtil::getDatabaseName(),
            [\stdClass::class => 'bar'],
            'snapshots'
        );
    }

    protected function tearDown(): void
    {
        $this->client->dropDatabase(TestUtil::getDatabaseName());
    }
}
