<?php

/**
 * @file
 * Drush integration for the config_split module.
 */

use Drupal\config_split\Config\SplitFilter;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drush\Log\LogLevel;

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

  try {
    $config = config_split_get_split_config();
  }
  catch (InvalidArgumentException $e) {
    return drush_set_error('DRUSH_CONFIG_ERROR', $e->getMessage());
  }

  $primary = new FileStorage($destination_dir);
  $destinations = [
    'primary' => $destination_dir,
  ];

  $storages = config_split_get_storages($config, $destinations);

  $message = 'The following directories will be purged and used for exporting configuration:';
  $message .= "\n";
  $message .= implode("\n", $destinations);
  $message .= "\n";
  $message .= 'Export the configuration?';

  if (drush_confirm($message)) {
    \Drupal::service('config_split.cli')->export($config, $primary, $storages);
    drush_log(dt("Configuration successfully exported to: \n!target.", array('!target' => implode("\n", $destinations))), LogLevel::SUCCESS);
  }

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

  try {
    $config = config_split_get_split_config();
  }
  catch (InvalidArgumentException $e) {
    return drush_set_error('DRUSH_CONFIG_ERROR', $e->getMessage());
  }

  $primary = new FileStorage($source_dir);
  $destinations = [
    'primary' => $source_dir,
  ];

  $storages = config_split_get_storages($config, $destinations);

  $message = 'The following directories will be used to merge configuration to import:';
  $message .= "\n";
  $message .= implode("\n", $destinations);
  $message .= "\n";
  $message .= 'Import the configuration?';

  if (drush_confirm($message)) {
    \Drupal::service('config_split.cli')->import($config, $primary, $storages);
    drush_log(dt("Configuration successfully imported from: \n!target.", array('!target' => implode("\n", $destinations))), LogLevel::SUCCESS);
  }

}

/**
 * Get the configuration filtered by the drush option.
 *
 * @return \Drupal\Core\Config\ImmutableConfig[]
 *   The split configurations to use.
 */
function config_split_get_split_config() {

  // If the option is not set, get all the active ones.
  if (!drush_get_option('split')) {
    return \Drupal::service('config_split.manager')->getActiveSplitConfig();
  }

  // Load all the available splits.
  /** @var ImmutableConfig[] $config */
  $config = \Drupal::service('config_split.manager')->getAllSplitConfig();

  // Use only the splits specified by the argument.
  $split = explode(',', drush_get_option('split'));
  $split_names = array_map(function ($name) {
    return 'config_split.config_split.' . $name;
  }, $split);
  $split = array_combine($split_names, $split);

  if ($invalid = array_diff_key($split, $config)) {
    throw new InvalidArgumentException('The following splits are not available: ' . implode(', ', $invalid));
  }

  return array_intersect_key($config, $split);
}

/**
 * Get the storages, if any are set by the rush option.
 *
 * @param ImmutableConfig[] $config
 *   The configuration objects of the splits used.
 * @param string[] $destinations
 *   The destinations array to which configuration is writen to or read from.
 *
 * @return StorageInterface[]
 *   The storages to export to to override the ones in config.
 */
function config_split_get_storages(array $config, array &$destinations) {
  if ($split_destination = drush_get_option('split-destination')) {
    if (count($config) > 1) {
      return drush_set_error('DRUSH_CONFIG_ERROR', 'The split-destination can only be used with one split, consider overriding the config in settings.php if you wand different paths.');
    }
    $secondary = new FileStorage($split_destination);
    $destinations['split-destination'] = $split_destination;
    return array_fill_keys(array_keys($config), $secondary);
  }

  foreach ($config as $key => $value) {
    $destinations[$key] = $value->get('folder');
  }
  $destinations = array_filter($destinations);
  return [];
}
