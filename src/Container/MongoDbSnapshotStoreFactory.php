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

namespace Prooph\SnapshotStore\MongoDb\Container;

use Interop\Config\ConfigurationTrait;
use Interop\Config\ProvidesDefaultOptions;
use Interop\Config\RequiresConfigId;
use Interop\Config\RequiresMandatoryOptions;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\WriteConcern;
use Prooph\SnapshotStore\CallbackSerializer;
use Prooph\SnapshotStore\MongoDb\MongoDbSnapshotStore;
use Prooph\SnapshotStore\Serializer;
use Psr\Container\ContainerInterface;

class MongoDbSnapshotStoreFactory implements ProvidesDefaultOptions, RequiresConfigId, RequiresMandatoryOptions
{
    use ConfigurationTrait;

    /**
     * @var string
     */
    private $configId;

    /**
     * Creates a new instance from a specified config, specifically meant to be used as static factory.
     *
     * In case you want to use another config key than provided by the factories, you can add the following factory to
     * your config:
     *
     * <code>
     * <?php
     * return [
     *     MongoDbSnapshotStore::class => [MongoDbSnapshotStoreFactory::class, 'service_name'],
     * ];
     * </code>
     *
     * @throws \InvalidArgumentException
     */
    public static function __callStatic(string $name, array $arguments): MongoDbSnapshotStore
    {
        if (! isset($arguments[0]) || ! $arguments[0] instanceof ContainerInterface) {
            throw new \InvalidArgumentException(
                sprintf('The first argument must be of type %s', ContainerInterface::class)
            );
        }

        return (new static($name))->__invoke($arguments[0]);
    }

    public function __invoke(ContainerInterface $container): MongoDbSnapshotStore
    {
        $config = $container->get('config');
        $config = $this->options($config, $this->configId);

        $client = $container->get($config['mongo_client_service']);

        $readConcern = new ReadConcern($config['read_concern']);

        $writeConcern = new WriteConcern(
            $config['write_concern']['w'],
            $config['write_concern']['wtimeout'],
            $config['write_concern']['journal']
        );

        $serializer = $config['serializer'] instanceof Serializer ? $config['serializer'] : $container->get($config['serializer']);

        return new MongoDbSnapshotStore(
            $client,
            $config['db_name'],
            $config['snapshot_grid_fs_map'],
            $config['default_snapshot_grid_fs_name'],
            $readConcern,
            $writeConcern,
            $serializer
        );
    }

    public function __construct(string $configId = 'default')
    {
        $this->configId = $configId;
    }

    public function dimensions(): iterable
    {
        return ['prooph', 'mongodb_snapshot_store'];
    }

    public function defaultOptions(): iterable
    {
        return [
            'snapshot_grid_fs_map' => [],
            'default_snapshot_grid_fs_name' => 'snapshots',
            'read_concern' => 'local', // other value: majority
            'write_concern' => [
                'w' => 1,
                'wtimeout' => 0, // How long to wait (in milliseconds) for secondaries before failing.
                'journal' => false, // Wait until mongod has applied the write to the journal.
            ],
            'serializer' => new CallbackSerializer(null, null),
        ];
    }

    public function mandatoryOptions(): iterable
    {
        return [
            'mongo_client_service',
            'db_name',
        ];
    }
}
