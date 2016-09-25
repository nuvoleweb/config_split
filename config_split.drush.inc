<?php

/**
 * @file
 * Drush integration for the config_split module.
 */

use Drupal\config_split\Config\SplitFilter;
use Drupal\Core\Config\FileStorage;

/**
 * Implements hook_drush_storage_filters().
 */
function config_split_drush_storage_filters() {
  $result = array();
  $split_option = drush_get_option('split');
  if (!empty($split_option)) {
    $config = \Drupal::config('config_split.settings');
    $manager = \Drupal::service('config.manager');
    $file_storage = new FileStorage($config->get('folder'));
    $result[] = new SplitFilter($config, $manager, $file_storage);
  }
  return $result;
}

/**
 * Implements hook_drush_command().
 */
function config_split_drush_command() {
  $items['config-split-export'] = array(
    'description' => 'Export and split configuration to a directory.',
    'core' => array('8+'),
    'aliases' => array('csex'),
    'arguments' => array(
      'label' => "A config directory label (i.e. a key in \$config_directories array in settings.php). Defaults to 'sync'",
    ),
    'options' => array(
      'split' => 'The split configuration to use.',
      'destination' => 'An arbitrary directory that should receive the exported files. An alternative to label argument.',
      'split-destination' => 'An arbitrary directory that should receive the split files. An alternative to split argument..',
    ),
    'examples' => array(
      'drush config-split-export --destination' => 'Export configuration; Save files in a backup directory named config-export.',
    ),
  );

  $items['config-split-import'] = array(
    'description' => 'Import config from a config directory and a split.',
    'arguments' => array(
      'label' => "A config directory label (i.e. a key in \$config_directories array in settings.php). Defaults to 'sync'",
    ),
    'options' => array(
      'split' => 'The split configuration to use.',
      'destination' => 'An arbitrary directory that should receive the exported files. An alternative to label argument.',
      'split-destination' => 'An arbitrary directory that should receive the split files. An alternative to split argument..',
    ),
    'core' => array('8+'),
    'examples' => array(
      'drush config-split-import --destination' => 'Import configuration from a directory.',
    ),
    'aliases' => array('csim'),
  );

  return $items;
}

/**
 * Command callback: Export config to specified directory (usually sync).
 */
function drush_config_split_export($destination = NULL) {
  global $config_directories;

  // Determine which target directory to use.
  if ($target = drush_get_option('destination')) {
    if ($target === TRUE) {
      // User did not pass a specific value for --destination. Make one.
      /** @var drush_version_control_backup $backup */
      $backup = drush_include_engine('version_control', 'backup');
      $destination_dir = $backup->prepare_backup_dir('config-export');
    }
    else {
      $destination_dir = $target;
      // It is important to be able to specify a destination directory that
      // does not exist yet, for exporting on remote systems.
      drush_mkdir($destination_dir);
    }
  }
  else {
    $choices = drush_map_assoc(array_keys($config_directories));
    unset($choices[CONFIG_ACTIVE_DIRECTORY]);
    if (!isset($destination) && count($choices) >= 2) {
      $destination = drush_choice($choices, 'Choose a destination.');
      if (empty($destination)) {
        return drush_user_abort();
      }
    }
    elseif (!isset($destination)) {
      $destination = CONFIG_SYNC_DIRECTORY;
    }
    $destination_dir = config_get_config_directory($destination);
  }

  $config = config_split_get_split_config();;

  $primary = new FileStorage($destination_dir);
  $secondary = NULL;
  if ($split_destination = drush_get_option('split-destination')) {
    $secondary = new FileStorage($split_destination);
  }

  \Drupal::service('config_split.cli')->export($config, $primary, $secondary);

}

/**
 * Command callback. Import from specified config directory (defaults to sync).
 */
function drush_config_split_import($source = NULL) {
  global $config_directories;

  // Determine source directory.
  if ($target = drush_get_option('source')) {
    $source_dir = $target;
  }
  else {
    $choices = drush_map_assoc(array_keys($config_directories));
    unset($choices[CONFIG_ACTIVE_DIRECTORY]);
    if (!isset($source) && count($choices) >= 2) {
      $source = drush_choice($choices, 'Choose a source.');
      if (empty($source)) {
        return drush_user_abort();
      }
    }
    elseif (!isset($source)) {
      $source = CONFIG_SYNC_DIRECTORY;
    }
    $source_dir = config_get_config_directory($source);
  }

  $config = config_split_get_split_config();

  $primary = new FileStorage($source_dir);
  $secondary = NULL;
  if ($split_destination = drush_get_option('split-destination')) {
    $secondary = new FileStorage($split_destination);
  }

  $cliService = \Drupal::service('config_split.cli');
  $cliService->import($config, $primary, $secondary);

}

/**
 * Get the configuration filtered by the drush option.
 *
 * @return array
 */
function config_split_get_split_config() {
  // Load all the available splits.
  /** @var ImmutableConfig[] $config */
  $config = \Drupal::service('config_split.cli')->getAllConfig();

  if (!drush_get_option('split')) {
    return $config;
  }

  // Use only the splits specified by the argument.
  $split = explode(',', drush_get_option('split'));
  $split_names = array_map(function ($name) {
    return 'config_split.config_split.' . $name;
  }, $split);
  $split = array_combine($split_names, $split);


  if ($invalid = array_diff_key($split, $config)) {
    error_log('The following splits are not available: ' . implode(', ', $invalid));
    exit;
  }

  return array_intersect_key($config, $split);
}
