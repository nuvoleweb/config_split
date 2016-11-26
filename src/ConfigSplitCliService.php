<?php

namespace Drupal\config_split;

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
   * The split manager.
   *
   * @var \Drupal\config_split\ConfigSplitManagerInterface
   */
  protected $configSplitManager;

  /**
   * Drupal\Core\Config\ConfigManager definition.
   *
   * @var \Drupal\Core\Config\ConfigManager
   */
  protected $configManager;

  /**
   * Drupal\Core\Config\CachedStorage definition.
   *
   * @var \Drupal\Core\Config\CachedStorage
   */
  protected $configStorage;

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
  public function __construct(ConfigSplitManagerInterface $config_split_manager, ConfigManagerInterface $config_manager, StorageInterface $config_storage, EventDispatcherInterface $event_dispatcher, LockBackendInterface $lock, TypedConfigManagerInterface $config_typed, ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, ThemeHandlerInterface $theme_handler, TranslationInterface $string_translation) {
    $this->configSplitManager = $config_split_manager;
    $this->configManager = $config_manager;
    $this->configStorage = $config_storage;
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
   * @param \Drupal\Core\Config\Config|\Drupal\Core\Config\Config[] $config
   *   The configuration instance(s) for the SplitFilter.
   * @param \Drupal\Core\Config\StorageInterface $primary
   *   The primary storage, typically what is defined by CONFIG_SYNC_DIRECTORY.
   * @param \Drupal\Core\Config\StorageInterface[]|null $secondary
   *   The storage to save the split to, overriding the config. (Usually NULL)
   */
  public function export($config, StorageInterface $primary, $secondary = []) {
    $configs = is_array($config) ? $config : [$config];
    $storage = $this->configSplitManager->getStorage($configs, $primary, $secondary);
    // Remove all the configuration which is not available.
    $this->deleteSuperfluous($storage, $this->configManager->getConfigFactory()->listAll());

    // Inspired by \Drupal\config\Controller\ConfigController::downloadExport().
    // Get raw configuration data without overrides.
    foreach ($this->configManager->getConfigFactory()->listAll() as $name) {
      $storage->write($name, $this->configManager->getConfigFactory()->get($name)->getRawData());
    }

    // Get all override data from the remaining collections.
    foreach ($this->configStorage->getAllCollectionNames() as $collection) {
      $source_collection = $this->configStorage->createCollection($collection);
      $destination_collection = $storage->createCollection($collection);
      // Delete everything in the collection sub-directory.
      $this->deleteSuperfluous($destination_collection, $source_collection->listAll());

      foreach ($source_collection->listAll() as $name) {
        $destination_collection->write($name, $source_collection->read($name));
      }

    }

  }

  /**
   * Delete configuration that will not be exported.
   *
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The storage to clean.
   * @param string[] $keep
   *   The array of configuration names to keep.
   */
  protected function deleteSuperfluous(StorageInterface $storage, $keep) {
    foreach ($storage->listAll() as $name) {
      if (!in_array($name, $keep)) {
        $storage->delete($name);
      }
    }
  }

  /**
   * Import the configuration.
   *
   * @param \Drupal\Core\Config\Config|\Drupal\Core\Config\Config[] $config
   *   The configuration instance(s) for the SplitFilter.
   * @param \Drupal\Core\Config\StorageInterface $primary
   *   The primary storage, typically what is defined by CONFIG_SYNC_DIRECTORY.
   * @param \Drupal\Core\Config\StorageInterface[]|null $secondary
   *   The storage to get the split from, overriding the config. (Usually NULL)
   *
   * @return string
   *   The state of importing.
   */
  public function import($config, StorageInterface $primary, $secondary = []) {
    $configs = is_array($config) ? $config : [$config];
    $storage = $this->configSplitManager->getStorage($configs, $primary, $secondary);
    $comparer = new StorageComparer($storage, $this->configStorage, $this->configManager);

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
