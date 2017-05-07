<?php

/**
 * @file
 * Drush integration for the config_split module.
 */

use Drupal\config_split\ConfigSplitCliService;
use Drupal\config_split\Entity\ConfigSplitEntity;
use Drupal\config_split\Config\GhostStorage;
use Drupal\Core\Config\FileStorageFactory;
use Drush\Log\LogLevel;

/**
 * Implements hook_drush_command().
 */
function config_split_drush_command() {
  $items['config-split-export'] = [
    'description' => 'Export only split configuration to a directory.',
    'core' => ['8+'],
    'aliases' => ['csex'],
    'arguments' => [
      'split' => 'The split configuration to export, if none is given do a normal export.',
    ],
    'options' => [],
    'examples' => [
      'drush config-split-export development' => 'Export development configuration; assumes a "development" split, export only that.',
    ],
  ];

  $items['config-split-import'] = [
    'description' => 'Import only config from a split.',
    'core' => ['8+'],
    'aliases' => ['csim'],
    'arguments' => [
      'split' => 'The split configuration to export, if none is given do a normal import.',
    ],
    'options' => [],
    'examples' => [
      'drush config-split-import' => 'Import configuration as drush cim does.',
    ],
  ];

  return $items;
}

/**
 * Command callback: Export config to specified directory (usually sync).
 */
function drush_config_split_export($split = NULL) {

  $primary = NULL;
  $config_name = NULL;

  if (!$split) {
    // Here we could call the default command...
    drush_log(dt("Consider using the native drush commands for exporting."), LogLevel::NOTICE);
    $message = 'Do a normal (including filters) config export?';
  }
  else {
    try {
      $config_name = config_split_get_split_name($split);
    }
    catch (InvalidArgumentException $e) {
      return drush_set_error('DRUSH_CONFIG_ERROR', $e->getMessage());
    }

    $destination = \Drupal::config($config_name)->get('folder');

    // Set the primary to a GhostStorage so that we only export the split.
    $plugin_id = \Drupal::service('config_split.cli')->getPliginIdFromConfigName($config_name);
    $storage = \Drupal::service('config_filter.storage_factory')->getFilteredStorage(FileStorageFactory::getSync(), ['config.storage.sync'], [$plugin_id]);
    $primary = new GhostStorage($storage);

    $message = 'The following directories will be purged and used for exporting configuration:';
    $message .= "\n";
    $message .= $destination;
    $message .= "\n";
    $message .= 'Export the configuration?';
  }

  if (drush_confirm($message)) {
    \Drupal::service('config_split.cli')->export($config_name, $primary);
    drush_log(dt("Configuration successfully exported."), LogLevel::SUCCESS);
  }

}

/**
 * Command callback. Import from specified config directory (defaults to sync).
 */
function drush_config_split_import($split = NULL) {

  $primary = NULL;
  $config_name = NULL;

  if (!$split) {
    // Here we could call the default command...
    drush_log(dt("Consider using the native drush commands for importing."), LogLevel::NOTICE);
    $message = 'Do a normal (including filters) config import?';
  }
  else {
    try {
      $config_name = config_split_get_split_name($split);
    }
    catch (InvalidArgumentException $e) {
      return drush_set_error('DRUSH_CONFIG_ERROR', $e->getMessage());
    }

    $destination = \Drupal::config($config_name)->get('folder');

    // Set the primary to the active storage so we only import the split.
    $primary = \Drupal::getContainer()->get('config.storage');

    $message = 'The following directory will be used to merge config into the active storage:';
    $message .= "\n";
    $message .= $destination;
    $message .= "\n";
    $message .= 'Import the configuration?';
  }

  if (drush_confirm($message)) {
    $status = \Drupal::service('config_split.cli')->import($config_name, $primary);
    switch ($status) {
      case ConfigSplitCliService::COMPLETE:
        drush_log(dt("Configuration successfully imported."), LogLevel::SUCCESS);
        break;

      case ConfigSplitCliService::NO_CHANGES:
        drush_log(dt("There are no changes to import."), LogLevel::NOTICE);
        break;

      case ConfigSplitCliService::ALREADY_IMPORTING:
        drush_log(dt("Another request may be synchronizing configuration already."), LogLevel::WARNING);
        break;

      default:
        drush_log(dt("Something unexpected happened"), LogLevel::CRITICAL);
        break;
    }
  }
}

/**
 * Get the configuration name from the short name.
 *
 * @param string $name
 *   The name to get the config name for.
 *
 * @return string
 *   The split configuration name.
 */
function config_split_get_split_name($name) {

  if (strpos($name, 'config_split.config_split.') !== 0) {
    $name = 'config_split.config_split.' . $name;
  }

  $splits = array_map(function (ConfigSplitEntity $split) {
    return $split->getConfigDependencyName();
  },
    \Drupal::entityTypeManager()->getStorage('config_split')->loadMultiple());

  if (!in_array($name, $splits)) {
    throw new InvalidArgumentException('The following split is not available: ' . $name);
  }

  return $name;
}
