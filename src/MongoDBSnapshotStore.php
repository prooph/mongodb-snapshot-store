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

namespace Prooph\MongoDB\SnapshotStore;

use DateTimeImmutable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\WriteConcern;
use MongoDB\GridFS\Exception\FileNotFoundException;
use Prooph\EventSourcing\Aggregate\AggregateType;
use Prooph\EventSourcing\Snapshot\Snapshot;
use Prooph\EventSourcing\Snapshot\SnapshotStore;

final class MongoDBSnapshotStore implements SnapshotStore
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $dbName;

    /**
     * Custom sourceType to snapshot mapping
     *
     * @var array
     */
    private $snapshotGridFsMap;

    /**
     * @var string
     */
    private $defaultSnapshotGridFsName;

    /**
     * @var ReadConcern
     */
    private $readConcern;

    /**
     * @var WriteConcern
     */
    private $writeConcern;

    public function __construct(
        Client $client,
        string $dbName,
        array $snapshotGridFsMap = [],
        string $defaultSnapshotGridFsName = 'snapshots',
        ReadConcern $readConcern = null,
        WriteConcern $writeConcern = null
    ) {
        if (null === $readConcern) {
            $readConcern = new ReadConcern(ReadConcern::LOCAL);
        }

        if (null === $writeConcern) {
            $writeConcern = new WriteConcern(1);
        }

        $this->client = $client;
        $this->dbName = $dbName;
        $this->snapshotGridFsMap = $snapshotGridFsMap;
        $this->defaultSnapshotGridFsName = $defaultSnapshotGridFsName;
        $this->readConcern = $readConcern;
        $this->writeConcern = $writeConcern;
    }

    public function get(AggregateType $aggregateType, string $aggregateId): ?Snapshot
    {
        $bucket = $this->client->selectDatabase($this->dbName)->selectGridFSBucket([
            'bucketName' => $this->getGridFsName($aggregateType),
            'readConcern' => $this->readConcern,
        ]);

        try {
            $stream = $this->createStream();
            $bucket->downloadToStream($aggregateId, $stream);
        } catch (FileNotFoundException $e) {
            return null;
        }

        $metadata = $bucket->getFileDocumentForStream($stream);
        $createdAt = $metadata->created_at->toDateTime();

        try {
            $aggregateRoot = unserialize(stream_get_contents($stream));
        } catch (\Throwable $e) {
            // problem getting file from mongodb
            return;
        }

        $aggregateTypeString = $aggregateType->toString();

        if (! $aggregateRoot instanceof $aggregateTypeString) {
            // invalid instance returned
            return;
        }

        return new Snapshot(
            $aggregateType,
            $aggregateId,
            $aggregateRoot,
            $metadata->last_version,
            DateTimeImmutable::createFromMutable($createdAt)
        );
    }

    public function save(Snapshot $snapshot): void
    {
        $aggregateType = $snapshot->aggregateType();

        $bucket = $this->client->selectDatabase($this->dbName)->selectGridFSBucket([
            'bucketName' => $this->getGridFsName($aggregateType),
            'writeConcern' => $this->writeConcern,
        ]);

        $createdAt = new UTCDateTime(
            $snapshot->createdAt()->getTimestamp() * 1000 + (int) $snapshot->createdAt()->format('u')
        );

        $bucket->uploadFromStream(
            $snapshot->aggregateId(),
            $this->createStream(serialize($snapshot->aggregateRoot())),
            [
                'metadata' => [
                    'aggregate_type' => $aggregateType->toString(),
                    'last_version' => $snapshot->lastVersion(),
                    'created_at' => $createdAt,
                ],
            ]
        );
    }

    private function getGridFsName(AggregateType $aggregateType): string
    {
        if (isset($this->snapshotGridFsMap[$aggregateType->toString()])) {
            $gridFsName = $this->snapshotGridFsMap[$aggregateType->toString()];
        } else {
            $gridFsName = $this->defaultSnapshotGridFsName;
        }

        return $gridFsName;
    }

    /**
     * Creates an in-memory stream with the given data.
     */
    protected function createStream(string $data = ''): resource
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $data);
        rewind($stream);

        return $stream;
    }
}
