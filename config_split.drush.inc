<?php

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
