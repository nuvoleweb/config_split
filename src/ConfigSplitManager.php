<?php

namespace Drupal\config_split;

use Drupal\Component\FileSecurity\FileSecurity;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\DatabaseStorage;
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
   */
  public function __construct(
    ConfigFactoryInterface $factory,
    ConfigManagerInterface $manager,
    StorageInterface $active,
    StorageInterface $sync,
    StorageInterface $export,
    Connection $connection
  ) {
    $this->factory = $factory;
    $this->sync = $sync;
    $this->active = $active;
    $this->export = $export;
    $this->connection = $connection;
    $this->manager = $manager;
  }

  /**
   * Get a split from a name.
   *
   * @param string $name
   *   The name of the split.
   *
   * @return \Drupal\Core\Config\ImmutableConfig|null
   *   The split config.
   */
  public function getSplitConfig(string $name): ?ImmutableConfig {
    if (strpos($name, 'config_split.config_split.') !== 0) {
      $name = 'config_split.config_split.' . $name;
    }
    if (!in_array($name, $this->factory->listAll('config_split.config_split.'), TRUE)) {
      return NULL;
    }

    return $this->factory->get($name);
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
    $split = $this->getSplitConfig($name);
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
    /** @var \Drupal\Core\Config\ImmutableConfig[] $splits */
    $splits = $this->factory->loadMultiple($this->factory->listAll('config_split'));

    $splits = array_filter($splits, function (ImmutableConfig $config) {
      return $config->get('status');
    });

    // Copy the preview to the permanent place.
    foreach ($splits as $split) {
      $preview = $this->getPreviewStorage($split);
      $permanent = $this->getSplitStorage($split);
      if ($preview !== NULL && $permanent !== NULL) {
        static::replaceStorageContents($preview, $permanent);
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

    // @todo: Fix this.
    $complete_split_list = $this->calculateCompleteSplitList($config);
    $conditional_split_list = $this->calculateCondiionalSplitList($config);

    // Split the configuration that needs to be split.
    foreach (array_merge([StorageInterface::DEFAULT_COLLECTION], $transforming->getAllCollectionNames()) as $collection) {
      $storage = $transforming->createCollection($collection);
      $split = $splitStorage->createCollection($collection);
      $sync = $this->sync->createCollection($collection);
      foreach ($storage->listAll() as $name) {
        $data = $storage->read($name);
        if ($data === FALSE) {
          continue;
        }

        if (in_array($name, $complete_split_list)) {
          if ($data) {
            $split->write($name, $data);
          }

          // Remove it from the transforming storage.
          $storage->delete($name);
        }
        if (in_array($name, $conditional_split_list)) {
          $syncData = $sync->read($name);
          if (!$config->get('graylist_skip_equal') || $syncData !== $data) {
            // The configuration is in the graylist but skip-equal is not set or
            // the source does not have the same data, so write to secondary and
            // return source data or null if it doesn't exist in the source.
            $split->write($name, $data);

            // If it is in the sync config write that to transforming storage.
            if ($syncData !== FALSE) {
              $storage->write($name, $syncData);
            }
            else {
              $storage->delete($name);
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
        // Merging for now means using the config from the split.
        $data = $split->read($name);
        if ($data !== FALSE) {
          $storage->write($name, $data);
        }
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
    // Here we could determine to use relative paths etc.
    if ($directory = $config->get('folder')) {
      if (!is_dir($directory)) {
        // If the directory doesn't exist, attempt to create it.
        // This might have some negative consequences but we trust the user to
        // have properly configured their site.
        /* @noinspection MkdirRaceConditionInspection */
        @mkdir($directory, 0777, TRUE);
      }
      // The following is roughly: file_save_htaccess($directory, TRUE, TRUE);
      // But we can't use global drupal functions and we want to write the
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
  protected function getPreviewStorage(ImmutableConfig $config, StorageInterface $transforming = NULL): ?StorageInterface {

    $name = substr($config->getName(), strlen('config_split.config_split.'));
    $name = 'config_split_preview_' . strtr($name, ['.' => '_']);
    // Use the database for everything.
    return new DatabaseStorage($this->connection, $this->connection->escapeTable($name));
  }

  /**
   * Export the config of a single split.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split config.
   */
  public function singleExport(ImmutableConfig $split) {

    // Force the transformation.
    $this->export->listAll();
    $preview = $this->getPreviewStorage($split, $this->export);

    if (!$split->get('status') && $preview !== NULL) {
      // @todo: decide if splitting an inactive split is wise.
      $this->splitPreview($split, $this->export, $preview);
    }

    $permanent = $this->getSplitStorage($split, $this->sync);
    if ($preview !== NULL && $permanent !== NULL) {
      static::replaceStorageContents($preview, $permanent);
    }
    else {
      throw new \RuntimeException();
    }
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
    if ($storage === NULL) {
      throw new \RuntimeException();
    }

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
   * Deactivate a split.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $split
   *   The split config.
   * @param bool $exportSplit
   *   Whether to export the split config first.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage to pass to a ConfigImporter to do the config changes.
   */
  public function singleDeactivate(ImmutableConfig $split, bool $exportSplit): StorageInterface {
    if (!$split->get('status')) {
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
    if ($config !== FALSE) {
      $config['status'] = FALSE;
      $transformation->write($split->getName(), $config);
    }

    return $transformation;
  }

  /**
   * Get the list of completely split config.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The split config.
   *
   * @return string[]
   *   The list of config names.
   */
  public function calculateCompleteSplitList(ImmutableConfig $config) {
    $blacklist = $config->get('blacklist');
    $modules = array_keys($config->get('module'));
    if ($modules) {
      $blacklist = array_merge($blacklist, array_keys($this->manager->findConfigEntityDependents('module', $modules)));
    }

    $themes = array_keys($config->get('theme'));
    if ($themes) {
      $blacklist = array_merge($blacklist, array_keys($this->manager->findConfigEntityDependents('theme', $themes)));
    }

    $extensions = array_merge([], $modules, $themes);

    if (empty($blacklist) && empty($extensions)) {
      // Early return to short-circuit the expensive calculations.
      return [];
    }

    $blacklist = array_filter($this->manager->getConfigFactory()->listAll(), function ($name) use ($extensions, $blacklist) {
      // Filter the list of config objects since they are not included in
      // findConfigEntityDependents.
      foreach ($extensions as $extension) {
        if (strpos($name, $extension . '.') === 0) {
          return TRUE;
        }
      }

      // Add the config name to the blacklist if it is in the wildcard list.
      return self::inFilterList($name, $blacklist);
    });
    sort($blacklist);
    // Finally merge all dependencies of the blacklisted config.
    $blacklist = array_unique(array_merge($blacklist, array_keys($this->manager->findConfigEntityDependents('config', $blacklist))));
    // Exclude from the complete split what is conditionally split.
    return array_diff($blacklist, $this->calculateCondiionalSplitList($config));
  }

  /**
   * Get the list of conditionally split config.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The split config.
   *
   * @return string[]
   *   The list of config names.
   */
  public function calculateCondiionalSplitList(ImmutableConfig $config) {
    $graylist = $config->get('graylist');

    if (empty($graylist)) {
      // Early return to short-circuit the expensive calculations.
      return [];
    }

    $graylist = array_filter($this->manager->getConfigFactory()->listAll(), function ($name) use ($graylist) {
      // Add the config name to the graylist if it is in the wildcard list.
      return self::inFilterList($name, $graylist);
    });
    sort($graylist);

    if ($config->get('graylist_dependents')) {
      // Find dependent configuration and add it to the list.
      $graylist = array_unique(array_merge($graylist, array_keys($this->manager->findConfigEntityDependents('config', $graylist))));
    }

    return $graylist;
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
