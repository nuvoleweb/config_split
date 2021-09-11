<?php

namespace Drupal\config_split;

use Drupal\Component\FileSecurity\FileSecurity;
use Drupal\config_split\Config\ConfigPatch;
use Drupal\config_split\Config\ConfigPatchMerge;
use Drupal\config_split\Config\EphemeralConfigFactory;
use Drupal\config_split\Config\SplitCollectionStorage;
use Drupal\config_split\Entity\ConfigSplitEntity;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageCopyTrait;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Database\Connection;

/**
 * The manager to split and merge.
 *
 * @internal This is not an API, it is code for config splits internal code, it
 *   may change without notice. You have been warned!
 */
final class ConfigSplitManager {

  use StorageCopyTrait;

  const SPLIT_PARTIAL_PREFIX = 'config_split.patch.';

  /**
   * The config factory to load config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $factory;

  /**
   * The database connection to set up database storages.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $connection;

  /**
   * The active config store to do single import.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  private $active;

  /**
   * The sync storage for checking conditional split.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  private $sync;

  /**
   * The export storage to do single export.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  private $export;

  /**
   * The config manager to calculate dependencies.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  private $manager;

  /**
   * The config array sorter.
   *
   * @var \Drupal\config_split\Config\ConfigPatchMerge
   */
  private $patchMerge;

  /**
   * ConfigSplitManager constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $factory
   *   The config factory.
   * @param \Drupal\Core\Config\ConfigManagerInterface $manager
   *   The config manager.
   * @param \Drupal\Core\Config\StorageInterface $active
   *   The active config store.
   * @param \Drupal\Core\Config\StorageInterface $sync
   *   The sync config store.
   * @param \Drupal\Core\Config\StorageInterface $export
   *   The export config store.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\config_split\Config\ConfigPatchMerge $patchMerge
   *   The patch-merge service.
   */
  public function __construct(
    ConfigFactoryInterface $factory,
    ConfigManagerInterface $manager,
    StorageInterface $active,
    StorageInterface $sync,
    StorageInterface $export,
    Connection $connection,
    ConfigPatchMerge $patchMerge
  ) {
    $this->factory = $factory;
    $this->sync = $sync;
    $this->active = $active;
    $this->export = $export;
    $this->connection = $connection;
    $this->manager = $manager;
    $this->patchMerge = $patchMerge;
  }

  /**
   * Get a split from a name.
   *
   * @param string $name
   *   The name of the split.
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The storage to get a split from if not the active one.
   *
   * @return \Drupal\Core\Config\ImmutableConfig|null
   *   The split config.
   */
  public function getSplitConfig(string $name, StorageInterface $storage = NULL): ?ImmutableConfig {
    if (strpos($name, 'config_split.config_split.') !== 0) {
      $name = 'config_split.config_split.' . $name;
    }
    // Get the split from the storage passed as an argument.
    if ($storage instanceof StorageInterface && $this->factory instanceof ConfigFactory) {
      $factory = EphemeralConfigFactory::fromService($this->factory, $storage);
      if (in_array($name, $factory->listAll('config_split.config_split.'), TRUE)) {
        return $factory->get($name);
      }
    }
    // Use the config factory service as a fallback.
    if (in_array($name, $this->factory->listAll('config_split.config_split.'), TRUE)) {
      return $this->factory->get($name);
    }

    return NULL;
  }

  /**
   * Get a split entity.
   *
   * @param string $name
   *   The split name.
   *
   * @return \Drupal\config_split\Entity\ConfigSplitEntity|null
   *   The config entity.
   */
  public function getSplitEntity(string $name): ?ConfigSplitEntity {
    $config = $this->getSplitConfig($name);
    if ($config === NULL) {
      return NULL;
    }
    $entity = $this->manager->loadConfigEntityByName($config->getName());
    if ($entity instanceof ConfigSplitEntity) {
      return $entity;
    }
    // Do we throw an exception? Do we return null?
    // @todo find out in what legitimate case this could possibly happen.
    throw new \RuntimeException('A split config does not load a split entity? something is very wrong.');
  }

  /**
   * Get all splits from the active storage plus the given storage.
   *
   * @param \Drupal\Core\Config\StorageInterface|null $storage
   *   The storage to consider when listing splits.
   *
   * @return string[]
   *   The split names from the active storage and the given stoage.
   */
  public function listAll(StorageInterface $storage = NULL): array {
    $names = [];
    if ($storage instanceof StorageInterface && $this->factory instanceof ConfigFactory) {
      $factory = EphemeralConfigFactory::fromService($this->factory, $storage);
      $names = $factory->listAll('config_split.config_split.');
    }

    return array_unique(array_merge($names, $this->factory->listAll('config_split.config_split.')));
  }

  /**
   * Load multiple splits and prefer loading it from the given storage.
   *
   * @param array $names
   *   The names to load.
   * @param \Drupal\Core\Config\StorageInterface|null $storage
   *   The storage to check.
   *
   * @return \Drupal\Core\Config\ImmutableConfig[]
   *   Loaded splits (with config overrides).
   */
  public function loadMultiple(array $names, StorageInterface $storage = NULL): array {
    $configs = [];
    if ($storage instanceof StorageInterface && $this->factory instanceof ConfigFactory) {
      $factory = EphemeralConfigFactory::fromService($this->factory, $storage);
      $configs = $factory->loadMultiple($names);
    }

    return $configs + $this->factory->loadMultiple($names);
  }

  /**
   * Process the export of a split.
   *
   * @param string $name
   *   The name of the split.
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The transformation event.
   */
  public function exportTransform(string $name, StorageTransformEvent $event): void {
    $split = $this->getSplitConfig($name);
    if ($split === NULL) {
      return;
    }
    if (!$split->get('status')) {
      return;
    }
    $storage = $event->getStorage();
    $preview = $this->getPreviewStorage($split, $storage);
    if ($preview !== NULL) {
      // Without a storage there is no splitting.
      $this->splitPreview($split, $storage, $preview);
    }
  }

  /**
   * Process the import of a split.
   *
   * @param string $name
   *   The name of the split.
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The transformation event.
   */
  public function importTransform(string $name, StorageTransformEvent $event): void {
    $split = $this->getSplitConfig($name, $event->getStorage());
    if ($split === NULL) {
      return;
    }
    if (!$split->get('status')) {
      return;
    }
    $storage = $event->getStorage();
    $secondary = $this->getSplitStorage($split, $storage);
    if ($secondary !== NULL) {
      $this->mergeSplit($split, $storage, $secondary);
    }
  }

  /**
   * Make the split permanent by copying the preview to the split storage.
   */
  public function commitAll(): void {
    $splits = $this->factory->loadMultiple($this->factory->listAll('config_split'));

    $splits = array_filter($splits, function (ImmutableConfig $config) {
      return $config->get('status');
    });

    // Copy the preview to the permanent place.
    foreach ($splits as $split) {
      $preview = $this->getPreviewStorage($split);
      $permanent = $this->getSplitStorage($split);
      if ($preview !== NULL && $permanent !== NULL) {
        self::replaceStorageContents($preview, $permanent);
      }
    }
  }

  /**
   * Split the config of a split to the preview storage.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The split config.
   * @param \Drupal\Core\Config\StorageInterface $transforming
   *   The transforming storage.
   * @param \Drupal\Core\Config\StorageInterface $splitStorage
   *   The splits preview storage.
   */
  public function splitPreview(ImmutableConfig $config, StorageInterface $transforming, StorageInterface $splitStorage): void {
    // Empty the split storage.
    foreach (array_merge([StorageInterface::DEFAULT_COLLECTION], $splitStorage->getAllCollectionNames()) as $collection) {
      $splitStorage->createCollection($collection)->deleteAll();
    }
    $transforming = $transforming->createCollection(StorageInterface::DEFAULT_COLLECTION);
    $splitStorage = $splitStorage->createCollection(StorageInterface::DEFAULT_COLLECTION);

    $modules = array_keys($config->get('module'));
    $changes = $this->manager->getConfigEntitiesToChangeOnDependencyRemoval('module', $modules, FALSE);

    $this->processEntitiesToChangeOnDependencyRemoval($changes, $transforming, $splitStorage);

    $completelySplit = array_map(function (ConfigEntityInterface $entity) {
      return $entity->getConfigDependencyName();
    }, $changes['delete']);

    // Process all simple config objects which implicitly depend on modules.
    foreach ($modules as $module) {
      $keys = $this->active->listAll($module . '.');
      $keys = array_diff($keys, $completelySplit);
      foreach ($keys as $name) {
        $splitStorage->write($name, $this->active->read($name));
        $transforming->delete($name);
        $completelySplit[] = $name;
      }
    }

    // Get explicitly split config.
    $completeSplitList = $config->get('complete_list');
    if (!empty($completeSplitList)) {
      // For the complete split we use the active storage config. This way two
      // splits can split the same config and both will have them. But also
      // because we use the config manager service to get entities to change
      // based on the modules which are configured to be split.
      $completeList = array_filter($this->active->listAll(), function ($name) use ($completeSplitList) {
        // Check for wildcards.
        return self::inFilterList($name, $completeSplitList);
      });
      // Check what is not processed already.
      $completeList = array_diff($completeList, $completelySplit);

      // Process also the config being removed.
      $changes = $this->manager->getConfigEntitiesToChangeOnDependencyRemoval('config', $completeList, FALSE);
      $this->processEntitiesToChangeOnDependencyRemoval($changes, $transforming, $splitStorage);

      // Split all the config which was specified but not processed yet.
      $processed = array_map(function (ConfigEntityInterface $entity) {
        return $entity->getConfigDependencyName();
      }, $changes['delete']);
      $unprocessed = array_diff($completeList, $processed);
      foreach ($unprocessed as $name) {
        $splitStorage->write($name, $this->active->read($name));
        $transforming->delete($name);
      }
    }

    // Split from collections what was split from the default collection.
    if (!empty($completelySplit) || !empty($completeSplitList)) {
      foreach ($this->active->getAllCollectionNames() as $collection) {
        $storageCollection = $transforming->createCollection($collection);
        $splitCollection = $splitStorage->createCollection($collection);
        $activeCollection = $this->active->createCollection($collection);

        $removeList = array_filter($activeCollection->listAll(), function ($name) use ($completeSplitList, $completelySplit) {
          // Check for wildcards.
          return in_array($name, $completelySplit) || self::inFilterList($name, $completeSplitList);
        });
        foreach ($removeList as $name) {
          // Split collections.
          $splitCollection->write($name, $activeCollection->read($name));
          $storageCollection->delete($name);
        }
      }
    }

    // Process partial config.
    $partialSplitList = $config->get('partial_list');
    if (!empty($partialSplitList)) {
      foreach (array_merge([StorageInterface::DEFAULT_COLLECTION], $transforming->getAllCollectionNames()) as $collection) {
        $syncCollection = $this->sync->createCollection($collection);
        $activeCollection = $this->active->createCollection($collection);
        $storageCollection = $transforming->createCollection($collection);
        $splitCollection = $splitStorage->createCollection($collection);

        $partialList = array_filter($activeCollection->listAll(), function ($name) use ($partialSplitList, $completelySplit) {
          // Check for wildcards. But skip config which is already split.
          return !in_array($name, $completelySplit) && self::inFilterList($name, $partialSplitList);
        });

        foreach ($partialList as $name) {
          if ($syncCollection->exists($name)) {
            $sync = $syncCollection->read($name);
            $active = $activeCollection->read($name);

            // If the split storage already contains a patch for the config
            // we need to apply it to the sync config so that the updated patch
            // contains both changes. We don't want to undo removing of things
            // that need to be removed due to a module which was split off.
            if ($splitCollection->exists(self::SPLIT_PARTIAL_PREFIX . $name)) {
              $patch = ConfigPatch::fromArray($splitCollection->read(self::SPLIT_PARTIAL_PREFIX . $name));
              $sync = $this->patchMerge->mergePatch($sync, $patch, $name);
            }

            $diff = $this->patchMerge->createPatch($active, $sync);
            // If the diff is empty then sync already contains the data.
            if (!$diff->isEmpty()) {
              $splitCollection->write(self::SPLIT_PARTIAL_PREFIX . $name, $diff->toArray());
              $storageCollection->write($name, $sync);
            }
          }
          else {
            // Split the config completely if it was not in the sync storage.
            $splitCollection->write($name, $activeCollection->read($name));
            $storageCollection->delete($name);
            if ($splitStorage->exists(self::SPLIT_PARTIAL_PREFIX . $name)) {
              // We completely split the config if it doesn't exist in the sync
              // storage, so we can also remove the patch if it exists.
              $splitStorage->delete(self::SPLIT_PARTIAL_PREFIX . $name);
            }
          }
        }
      }
    }

    // Now special case the extensions.
    $extensions = $transforming->read('core.extension');
    if ($extensions === FALSE) {
      return;
    }
    // Split off the extensions.
    $extensions['module'] = array_diff_key($extensions['module'], $config->get('module') ?? []);
    $extensions['theme'] = array_diff_key($extensions['theme'], $config->get('theme') ?? []);

    $transforming->write('core.extension', $extensions);
  }

  /**
   * Merge the config of a split to the transformation storage.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The split config.
   * @param \Drupal\Core\Config\StorageInterface $transforming
   *   The transforming storage.
   * @param \Drupal\Core\Config\StorageInterface $splitStorage
   *   The split storage.
   */
  public function mergeSplit(ImmutableConfig $config, StorageInterface $transforming, StorageInterface $splitStorage): void {
    $transforming = $transforming->createCollection(StorageInterface::DEFAULT_COLLECTION);
    $splitStorage = $splitStorage->createCollection(StorageInterface::DEFAULT_COLLECTION);

    // Merge all the configuration from all collections.
    foreach (array_merge([StorageInterface::DEFAULT_COLLECTION], $splitStorage->getAllCollectionNames()) as $collection) {
      $split = $splitStorage->createCollection($collection);
      $storage = $transforming->createCollection($collection);
      foreach ($split->listAll() as $name) {
        $data = $split->read($name);
        if ($data !== FALSE) {
          if (strpos($name, self::SPLIT_PARTIAL_PREFIX) === 0) {
            $name = substr($name, strlen(self::SPLIT_PARTIAL_PREFIX));
            $diff = ConfigPatch::fromArray($data);
            if ($storage->exists($name)) {
              // Skip patches for config that doesn't exist in the storage.
              $data = $storage->read($name);
              $data = $this->patchMerge->mergePatch($data, $diff->invert(), $name);
              $storage->write($name, $data);
            }
          }
          else {
            $storage->write($name, $data);
          }
        }
      }
    }

    // When merging a split with the collection storage we delete all in it.
    if ($config->get('storage') === 'collection') {
      // We can not assume $splitStorage is grafted onto $transforming.
      $collectionStorage = new SplitCollectionStorage($transforming, $config->get('id'));
      foreach (array_merge([StorageInterface::DEFAULT_COLLECTION], $collectionStorage->getAllCollectionNames()) as $collection) {
        $collectionStorage->createCollection($collection)->deleteAll();
      }
    }

    // Now special case the extensions.
    $extensions = $transforming->read('core.extension');
    if ($extensions === FALSE) {
      return;
    }

    $updated = $transforming->read($config->getName());
    if ($updated === FALSE) {
      return;
    }

    $extensions['module'] = array_merge($extensions['module'], $updated['module'] ?? []);
    $extensions['theme'] = array_merge($extensions['theme'], $updated['theme'] ?? []);
    // Sort the modules.
    $sorted = $extensions['module'];
    uksort($sorted, function ($a, $b) use ($sorted) {
      // Sort by module weight, this assumes the schema of core.extensions.
      if ($sorted[$a] != $sorted[$b]) {
        return $sorted[$a] > $sorted[$b] ? 1 : -1;
      }
      // Or sort by module name.
      return $a > $b ? 1 : -1;
    });

    $extensions['module'] = $sorted;

    $transforming->write('core.extension', $extensions);
  }

  /**
   * Get the split storage.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The split config.
   * @param \Drupal\Core\Config\StorageInterface|null $transforming
   *   The transforming storage.
   *
   * @return \Drupal\Core\Config\StorageInterface|null
   *   The split storage.
   */
  protected function getSplitStorage(ImmutableConfig $config, StorageInterface $transforming = NULL): ?StorageInterface {
    $storage = $config->get('storage');
    if ('collection' === $storage) {
      if ($transforming instanceof StorageInterface) {
        return new SplitCollectionStorage($transforming, $config->get('id'));
      }

      return NULL;
    }
    if ('folder' === $storage) {
      // Here we could determine to use relative paths etc.
      $directory = $config->get('folder');
      if (!is_dir($directory)) {
        // If the directory doesn't exist, attempt to create it.
        // This might have some negative consequences, but we trust the user to
        // have properly configured their site.
        /* @noinspection MkdirRaceConditionInspection */
        @mkdir($directory, 0777, TRUE);
      }
      // The following is roughly: file_save_htaccess($directory, TRUE, TRUE);
      // But we can't use global drupal functions, and we want to write the
      // .htaccess file to ensure the configuration is protected and the
      // directory not empty.
      if (file_exists($directory) && is_writable($directory)) {
        $htaccess_path = rtrim($directory, '/\\') . '/.htaccess';
        if (!file_exists($htaccess_path)) {
          file_put_contents($htaccess_path, FileSecurity::htaccessLines(TRUE));
          @chmod($htaccess_path, 0444);
        }
      }

      if (file_exists($directory) || strpos($directory, 'vfs://') === 0) {
        // Allow virtual file systems even if file_exists is false.
        return new FileStorage($directory);
      }

      return NULL;
    }

    // When the folder is not set use a database.
    return new DatabaseStorage($this->connection, $this->connection->escapeTable(strtr($config->getName(), ['.' => '_'])));
  }

  /**
   * Get the preview storage.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The split config.
   * @param \Drupal\Core\Config\StorageInterface|null $transforming
   *   The transforming storage.
   *
   * @return \Drupal\Core\Config\StorageInterface|null
   *   The preview storage.
   */
  public function getPreviewStorage(ImmutableConfig $config, StorageInterface $transforming = NULL): ?StorageInterface {
    if ('collection' === $config->get('storage')) {
      if ($transforming instanceof StorageInterface) {
        return new SplitCollectionStorage($transforming, $config->get('id'));
      }

      return NULL;
    }

    $name = substr($config->getName(), strlen('config_split.config_split.'));
    $name = 'config_split_preview_' . strtr($name, ['.' => '_']);
    // Use the database for everything.
    return new DatabaseStorage($this->connection, $this->connection->escapeTable($name));
  }

  /**
   * Get the single export preview.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split config.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The single export preview.
   */
  public function singleExportPreview(ImmutableConfig $split): StorageInterface {

    // Force the transformation.
    $this->export->listAll();
    $preview = $this->getPreviewStorage($split, $this->export);

    if (!$split->get('status') && $preview !== NULL) {
      // @todo decide if splitting an inactive split is wise.
      $transforming = new MemoryStorage();
      self::replaceStorageContents($this->export, $transforming);
      $this->splitPreview($split, $transforming, $preview);
    }

    if ($preview === NULL) {
      throw new \RuntimeException();
    }
    return $preview;
  }

  /**
   * Get the single export target.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split config.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The single export target.
   */
  public function singleExportTarget(ImmutableConfig $split): StorageInterface {
    $permanent = $this->getSplitStorage($split, $this->sync);
    if ($permanent === NULL) {
      throw new \RuntimeException();
    }
    return $permanent;
  }

  /**
   * Import the config of a single split.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split config.
   * @param bool $activate
   *   Whether to activate the split as well.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage to pass to a ConfigImporter to do the actual importing.
   */
  public function singleImport(ImmutableConfig $split, bool $activate): StorageInterface {
    $storage = $this->getSplitStorage($split, $this->sync);
    return $this->singleImportOrActivate($split, $storage, $activate);
  }

  /**
   * Import the config of a single split.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split config.
   * @param bool $activate
   *   Whether to activate the split as well.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage to pass to a ConfigImporter to do the actual importing.
   */
  public function singleActivate(ImmutableConfig $split, bool $activate): StorageInterface {
    $storage = $this->getSplitStorage($split, $this->active);
    return $this->singleImportOrActivate($split, $storage, $activate);
  }

  /**
   * Deactivate a split.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split config.
   * @param bool $exportSplit
   *   Whether to export the split config first.
   * @param bool $override
   *   Allows the deactivation via override.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage to pass to a ConfigImporter to do the config changes.
   */
  public function singleDeactivate(ImmutableConfig $split, bool $exportSplit = FALSE, $override = FALSE): StorageInterface {
    if (!$split->get('status') && !$override) {
      throw new \InvalidArgumentException('Split is already not active.');
    }

    $transformation = new MemoryStorage();
    static::replaceStorageContents($this->active, $transformation);

    $preview = $this->getPreviewStorage($split, $transformation);
    if ($preview === NULL) {
      throw new \RuntimeException();
    }
    $this->splitPreview($split, $transformation, $preview);

    if ($exportSplit) {
      $permanent = $this->getSplitStorage($split, $this->sync);
      if ($permanent === NULL) {
        throw new \RuntimeException();
      }
      static::replaceStorageContents($preview, $permanent);
    }

    // Deactivate the split in the transformation so that the importer does it.
    $config = $transformation->read($split->getName());
    if ($config !== FALSE && !$override) {
      $config['status'] = FALSE;
      $transformation->write($split->getName(), $config);
    }

    return $transformation;
  }

  /**
   * Importing and activating are almost the same.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split.
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The storage.
   * @param bool $activate
   *   Whether to activate the split in the transformation.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage to pass to a ConfigImporter to do the config changes.
   */
  protected function singleImportOrActivate(ImmutableConfig $split, StorageInterface $storage, bool $activate): StorageInterface {
    $transformation = new MemoryStorage();
    static::replaceStorageContents($this->active, $transformation);

    $this->mergeSplit($split, $transformation, $storage);

    // Activate the split in the transformation so that the importer does it.
    $config = $transformation->read($split->getName());
    if ($activate && $config !== FALSE) {
      $config['status'] = TRUE;
      $transformation->write($split->getName(), $config);
    }

    return $transformation;
  }

  /**
   * Process changes the config manager calculated into the storages.
   *
   * @param array $changes
   *   The changes from getConfigEntitiesToChangeOnDependencyRemoval().
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The primary config transformation storage.
   * @param \Drupal\Core\Config\StorageInterface $split
   *   The split storage.
   */
  protected function processEntitiesToChangeOnDependencyRemoval(array $changes, StorageInterface $storage, StorageInterface $split) {
    // Process entities that need to be updated.
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    foreach ($changes['update'] as $entity) {
      $name = $entity->getConfigDependencyName();
      // We use the active store because we also load the entity from it.
      $original = $this->active->read($name);
      $updated = $entity->toArray();

      $diff = $this->patchMerge->createPatch($original, $updated);
      if (!$diff->isEmpty()) {
        $split->write(self::SPLIT_PARTIAL_PREFIX . $name, $diff->toArray());

        $data = $storage->read($name);
        $data = $this->patchMerge->mergePatch($data, $diff);

        $storage->write($name, $data);
      }
    }

    // Process entities that need to be deleted.
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    foreach ($changes['delete'] as $entity) {
      $name = $entity->getConfigDependencyName();
      $split->write($name, $this->active->read($name));
      $storage->delete($name);
    }
  }

  /**
   * Check whether the needle is in the haystack.
   *
   * @param string $name
   *   The needle which is checked.
   * @param string[] $list
   *   The haystack, a list of identifiers to determine whether $name is in it.
   *
   * @return bool
   *   True if the name is considered to be in the list.
   */
  protected static function inFilterList($name, array $list) {
    // Prepare the list for regex matching by quoting all regex symbols and
    // replacing back the original '*' with '.*' to allow it to catch all.
    $list = array_map(function ($line) {
      return str_replace('\*', '.*', preg_quote($line, '/'));
    }, $list);
    foreach ($list as $line) {
      if (preg_match('/^' . $line . '$/', $name)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
