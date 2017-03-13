<?php

namespace Drupal\config_split;

use Drupal\Core\Config\FileStorageFactory;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Class ConfigSplitManager.
 *
 * @deprecated This is a legacy class to make sure people who were using it
 *   to override the service pre beta3 can update to later releases. This will
 *   be removed prior to a stable release.
 *
 * @package Drupal\config_split
 */
class ConfigSplitManager {

  /**
   * Returns the Config storage wrapper wrapping cores sync storage.
   *
   * @deprecated With config_filter this is not needed any more.
   *
   * @return \Drupal\config_filter\Config\FilteredStorageInterface
   *   The sync storage wrapped by config_filter
   */
  public function getDefaultStorageWrapper() {
    \Drupal::logger('config_split')->critical('Do not manually override the config.storage.sync service. The new config_filter module does that for you.');
    try {
      $storage = \Drupal::service('plugin.manager.config_filter')->getFilteredSyncStorage();
    }
    catch (ServiceNotFoundException $exception) {
      // Before running the update hook, the service is not available.
      \Drupal::logger('config_split')->critical('Make sure you install the config_filter module and run database updates.');
      $storage = FileStorageFactory::getSync();
    }

    return $storage;
  }

}
