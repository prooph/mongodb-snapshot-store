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

namespace Prooph\SnapshotStore\MongoDb;

use DateTimeImmutable;
use DateTimeZone;
use MongoDB\Client;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\WriteConcern;
use MongoDB\GridFS\Exception\FileNotFoundException;
use Prooph\SnapshotStore\Snapshot;
use Prooph\SnapshotStore\SnapshotStore;

final class MongoDbSnapshotStore implements SnapshotStore
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

    public function get(string $aggregateType, string $aggregateId): ?Snapshot
    {
        $bucket = $this->client->selectDatabase($this->dbName)->selectGridFSBucket([
            'bucketName' => $this->getGridFsName($aggregateType),
            'readConcern' => $this->readConcern,
        ]);

        try {
            $stream = $bucket->openDownloadStream($aggregateId);
        } catch (FileNotFoundException $e) {
            return null;
        }

        $metadata = $bucket->getFileDocumentForStream($stream);
        $createdAt = $metadata->metadata->created_at;
        $lastVersion = $metadata->metadata->last_version;

        $destination = $this->createStream();
        stream_copy_to_stream($stream, $destination);
        $aggregateRoot = unserialize(stream_get_contents($destination, -1, 0));

        return new Snapshot(
            $aggregateType,
            $aggregateId,
            $aggregateRoot,
            $lastVersion,
            DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $createdAt, new DateTimeZone('UTC'))
        );
    }

    public function save(Snapshot ...$snapshots): void
    {
        // unfortunately there is no easy way to mass-upload without a loop
        foreach ($snapshots as $snapshot) {
            $aggregateId = $snapshot->aggregateId();
            $aggregateType = $snapshot->aggregateType();

            $bucket = $this->client->selectDatabase($this->dbName)->selectGridFSBucket([
                'bucketName' => $this->getGridFsName($aggregateType),
                'writeConcern' => $this->writeConcern,
            ]);

            try {
                $bucket->delete($aggregateId);
            } catch (\Throwable $e) {
                // ignore
            }

            $bucket->uploadFromStream(
                $aggregateId,
                $this->createStream(serialize($snapshot->aggregateRoot())),
                [
                    '_id' => $aggregateId,
                    'metadata' => [
                        'aggregate_type' => $aggregateType,
                        'last_version' => $snapshot->lastVersion(),
                        'created_at' => $snapshot->createdAt()->format('Y-m-d\TH:i:s.u'),
                    ],
                ]
            );
        }
    }

    public function removeAll(string $aggregateType): void
    {
        $gridFsName = $this->getGridFsName($aggregateType);

        if ($gridFsName !== $this->defaultSnapshotGridFsName) {
            // it's faster to just drop the entire collection
            $this->client->selectCollection($this->dbName, sprintf('%s.files', $gridFsName))->drop([
                'writeConcern' => $this->writeConcern,
            ]);
            $this->client->selectCollection($this->dbName, sprintf('%s.chunks', $gridFsName))->drop([
                'writeConcern' => $this->writeConcern,
            ]);

            return;
        }

        $bucket = $this->client->selectDatabase($this->dbName)->selectGridFSBucket([
            'bucketName' => $this->getGridFsName($aggregateType),
            'readConcern' => $this->readConcern,
            'writeConcern' => $this->writeConcern,
        ]);

        $snapshots = $bucket->find([
            'metadata.aggregate_type' => $aggregateType,
        ]);

        foreach ($snapshots as $snapshot) {
            $bucket->delete($snapshot->_id);
        }
    }

    private function getGridFsName(string $aggregateType): string
    {
        if (isset($this->snapshotGridFsMap[$aggregateType])) {
            return $gridFsName = $this->snapshotGridFsMap[$aggregateType];
        }

        return $this->defaultSnapshotGridFsName;
    }

    /**
     * Creates an in-memory stream with the given data.
     *
     * @return resource
     */
    private function createStream(string $data = '')
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $data);
        rewind($stream);

        return $stream;
    }
}
