<?php

namespace Drupal\config_split;

use Drupal\config_filter\Config\FilteredStorage;
use Drupal\config_filter\ConfigFilterManagerInterface;
use Drupal\config_split\Plugin\ConfigFilter\SplitFilter;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class ConfigSplitCliService.
 *
 * @package Drupal\config_split
 */
class ConfigSplitCliService {

  /**
   * The return value indicating no changes were imported.
   */
  const NO_CHANGES = 'no_changes';

  /**
   * The return value indicating that the import is already in progress.
   */
  const ALREADY_IMPORTING = 'already_importing';

  /**
   * The return value indicating that the process is complete.
   */
  const COMPLETE = 'complete';

  /**
   * The filter manager.
   *
   * @var \Drupal\config_filter\ConfigFilterManagerInterface
   */
  protected $configFilterManager;

  /**
   * Drupal\Core\Config\ConfigManager definition.
   *
   * @var \Drupal\Core\Config\ConfigManager
   */
  protected $configManager;

  /**
   * Active Config Storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * Sync Config Storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $syncStorage;

  /**
   * Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher definition.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * Drupal\Core\ProxyClass\Lock\DatabaseLockBackend definition.
   *
   * @var \Drupal\Core\ProxyClass\Lock\DatabaseLockBackend
   */
  protected $lock;

  /**
   * Drupal\Core\Config\TypedConfigManager definition.
   *
   * @var \Drupal\Core\Config\TypedConfigManager
   */
  protected $configTyped;

  /**
   * Drupal\Core\Extension\ModuleHandler definition.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Drupal\Core\ProxyClass\Extension\ModuleInstaller definition.
   *
   * @var \Drupal\Core\ProxyClass\Extension\ModuleInstaller
   */
  protected $moduleInstaller;

  /**
   * Drupal\Core\Extension\ThemeHandler definition.
   *
   * @var \Drupal\Core\Extension\ThemeHandler
   */
  protected $themeHandler;

  /**
   * Drupal\Core\StringTranslation\TranslationManager definition.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $stringTranslation;

  /**
   * List of messages.
   *
   * @var array
   */
  protected $errors;

  /**
   * Constructor.
   */
  public function __construct(
    ConfigFilterManagerInterface $config_filter_manager,
    ConfigManagerInterface $config_manager,
    StorageInterface $active_storage,
    StorageInterface $sync_storage,
    EventDispatcherInterface $event_dispatcher,
    LockBackendInterface $lock,
    TypedConfigManagerInterface $config_typed,
    ModuleHandlerInterface $module_handler,
    ModuleInstallerInterface $module_installer,
    ThemeHandlerInterface $theme_handler,
    TranslationInterface $string_translation
  ) {
    $this->configFilterManager = $config_filter_manager;
    $this->configManager = $config_manager;
    $this->activeStorage = $active_storage;
    $this->syncStorage = $sync_storage;
    $this->eventDispatcher = $event_dispatcher;
    $this->lock = $lock;
    $this->configTyped = $config_typed;
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->themeHandler = $theme_handler;
    $this->stringTranslation = $string_translation;
    $this->errors = [];
  }

  /**
   * Export the configuration.
   *
   * @param string $config_name
   *   The configuration name for the SplitFilter.
   * @param \Drupal\Core\Config\StorageInterface $primary
   *   The primary storage, typically what is defined by CONFIG_SYNC_DIRECTORY.
   */
  public function export($config_name = NULL, StorageInterface $primary = NULL) {
    $storage = $this->getStorage($config_name, $primary);

    // Delete all, the filters are responsible for keeping some configuration.
    $storage->deleteAll();

    // Get the default active storage to copy it to the sync storage.
    if ($this->activeStorage->getCollectionName() != StorageInterface::DEFAULT_COLLECTION) {
      // This is probably not necessary, but we do it as a precaution.
      $this->activeStorage = $this->activeStorage->createCollection(StorageInterface::DEFAULT_COLLECTION);
    }

    // Copy everything.
    foreach ($this->activeStorage->listAll() as $name) {
      $storage->write($name, $this->activeStorage->read($name));
    }

    // Get all override data from the remaining collections.
    foreach ($this->activeStorage->getAllCollectionNames() as $collection) {
      $source_collection = $this->activeStorage->createCollection($collection);
      $destination_collection = $storage->createCollection($collection);
      // Delete everything in the collection sub-directory.
      $destination_collection->deleteAll();

      foreach ($source_collection->listAll() as $name) {
        $destination_collection->write($name, $source_collection->read($name));
      }

    }

  }

  /**
   * Get the storage to work with.
   *
   * If either the name or the primary storage are empty, the default is used.
   *
   * @param string $config_name
   *   The name of the configuration for the split to use.
   * @param \Drupal\Core\Config\StorageInterface $primary
   *   The config storage to wrap.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage to use.
   */
  public function getStorage($config_name = NULL, StorageInterface $primary = NULL) {
    $filters = [];
    if ($config_name) {
      $filters[] = $this->configFilterManager->getFilterInstance($this->getPliginIdFromConfigName($config_name));
    }
    if ($primary) {
      return new FilteredStorage($primary, $filters);
    }

    return $this->syncStorage;
  }

  /**
   * Get the plugin id of a split filter from a config name.
   *
   * @param string $name
   *   The config name.
   *
   * @return string
   *   The plugin id.
   */
  public function getPliginIdFromConfigName($name) {
    return 'config_split:' . str_replace('config_split.config_split.', '', $name);
  }

  /**
   * Import the configuration.
   *
   * @param string $config_name
   *   The configuration name for the SplitFilter.
   * @param \Drupal\Core\Config\StorageInterface $primary
   *   The primary storage, typically what is defined by CONFIG_SYNC_DIRECTORY.
   *
   * @return string
   *   The state of importing.
   */
  public function import($config_name = NULL, StorageInterface $primary = NULL) {
    $storage = $this->getStorage($config_name, $primary);
    $comparer = new StorageComparer($storage, $this->activeStorage, $this->configManager);

    if (!$comparer->createChangelist()->hasChanges()) {
      return static::NO_CHANGES;
    }

    $importer = new ConfigImporter(
      $comparer,
      $this->eventDispatcher,
      $this->configManager,
      $this->lock,
      $this->configTyped,
      $this->moduleHandler,
      $this->moduleInstaller,
      $this->themeHandler,
      $this->stringTranslation
    );

    if ($importer->alreadyImporting()) {
      return static::ALREADY_IMPORTING;
    }

    try {
      // Do the import with the ConfigImporter.
      $importer->import();
    }
    catch (ConfigImporterException $e) {
      // Catch and re-trow the ConfigImporterException.
      $this->errors = $importer->getErrors();
      throw $e;
    }

    return static::COMPLETE;
  }

  /**
   * Returns error messages created while running the import.
   *
   * @return array
   *   List of messages.
   */
  public function getErrors() {
    return $this->errors;
  }

}
