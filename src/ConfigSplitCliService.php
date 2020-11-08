<?php

namespace Drupal\config_split;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The CLI service class for interoperability.
 *
 * @internal This service is not an api and may change at any time.
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
   * @var \Drupal\config_split\ConfigSplitManager
   */
  protected $manager;

  /**
   * Drupal\Core\Config\ConfigManager definition.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
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
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The lock.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * Drupal\Core\Config\TypedConfigManager definition.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $configTyped;

  /**
   * Drupal\Core\Extension\ModuleHandler definition.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Drupal\Core\ProxyClass\Extension\ModuleInstaller definition.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * Drupal\Core\Extension\ThemeHandler definition.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * Drupal\Core\StringTranslation\TranslationManager definition.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected $stringTranslation;

  /**
   * The ModuleExtensionList to be passed to the config importer.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

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
    ConfigSplitManager $manager,
    ConfigManagerInterface $config_manager,
    StorageInterface $active_storage,
    StorageInterface $sync_storage,
    EventDispatcherInterface $event_dispatcher,
    LockBackendInterface $lock,
    TypedConfigManagerInterface $config_typed,
    ModuleHandlerInterface $module_handler,
    ModuleInstallerInterface $module_installer,
    ThemeHandlerInterface $theme_handler,
    TranslationInterface $string_translation,
    ModuleExtensionList $moduleExtensionList
  ) {
    $this->manager = $manager;
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
    $this->moduleExtensionList = $moduleExtensionList;
    $this->errors = [];
  }

  /**
   * Handle the export interaction.
   *
   * @param string $split
   *   The split name to export, null for standard export.
   * @param \Symfony\Component\Console\Style\StyleInterface|object $io
   *   The io interface of the cli tool calling the method.
   * @param callable $t
   *   The translation function akin to t().
   * @param bool $confirmed
   *   Whether the export is already confirmed by the console input.
   */
  public function ioExport(string $split, $io, callable $t, bool $confirmed = FALSE): bool {
    if (!$split) {
      throw new \InvalidArgumentException('Split can not be empty');
    }

    $config = $this->manager->getSplitConfig($split);
    if ($config === NULL) {
      $io->error($t('There is no split with name @name', ['@name' => $split]));
      return FALSE;
    }

    $message = $t('Export the split config configuration?');
    if ($confirmed || $io->confirm($message)) {
      $this->manager->singleExport($config);
      $io->success($t("Configuration successfully exported."));
    }

    return TRUE;
  }

  /**
   * Handle the import interaction.
   *
   * @param string $split
   *   The split name to import, null for standard import.
   * @param \Symfony\Component\Console\Style\StyleInterface|object $io
   *   The $io interface of the cli tool calling.
   * @param callable $t
   *   The translation function akin to t().
   * @param bool $confirmed
   *   Whether the import is already confirmed by the console input.
   */
  public function ioImport(string $split, $io, callable $t, $confirmed = FALSE): bool {
    if (!$split) {
      throw new \InvalidArgumentException('Split can not be empty');
    }
    $config = $this->manager->getSplitConfig($split);
    if ($config === NULL) {
      $io->error($t('There is no split with name @name', ['@name' => $split]));
      return FALSE;
    }

    $message = $t('Import the split config configuration?');

    if ($confirmed || $io->confirm($message)) {
      try {
        $storage = $this->manager->singleImport($config, FALSE);
        $status = $this->import($storage);
        switch ($status) {
          case ConfigSplitCliService::COMPLETE:
            $io->success($t("Configuration successfully imported."));
            return TRUE;

          case ConfigSplitCliService::NO_CHANGES:
            $io->text($t("There are no changes to import."));
            return TRUE;

          case ConfigSplitCliService::ALREADY_IMPORTING:
            $io->error(
              $t("Another request may be synchronizing configuration already.")
            );
            return FALSE;

          default:
            $io->error($t("Something unexpected happened"));
            return FALSE;
        }
      }
      catch (ConfigImporterException $e) {
        $io->error(
          $t(
            'There have been errors importing: @errors',
            ['@errors' => strip_tags(implode("\n", $this->getErrors()))]
          )
        );
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The hook to invoke after having exported all config.
   */
  public function postExportAll() {
    // We need to make sure the split config is also written to the permanent
    // split storage.
    $this->manager->commitAll();
  }

  /**
   * Import the configuration.
   *
   * This is the quintessential config import.
   *
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The config storage to import from.
   *
   * @return string
   *   The state of importing.
   */
  protected function import(StorageInterface $storage) {

    $comparer = new StorageComparer($storage, $this->activeStorage);

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
      $this->stringTranslation,
      $this->moduleExtensionList
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

  /**
   * Returns the directory path to export or "database".
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return string
   *   The destination.
   */
  protected function getDestination($config_name) {
    $destination = $this->configManager->getConfigFactory()->get($config_name)->get('folder');
    if ($destination == '') {
      $destination = 'dedicated database table.';
    }
    return $destination;
  }

}
