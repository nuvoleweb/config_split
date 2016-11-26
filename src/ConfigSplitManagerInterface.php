<?php

namespace Drupal\config_split;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\StorageInterface;

/**
 * Interface ConfigSplitManagerInterface.
 *
 * @package Drupal\config_split
 */
interface ConfigSplitManagerInterface {

  /**
   * Get all the config_split configurations.
   *
   * @return \Drupal\Core\Config\ImmutableConfig[]
   *   All available split configurations in the system.
   */
  public function getAllSplitConfig();

  /**
   * Get all the active config_split configurations.
   *
   * @return \Drupal\Core\Config\ImmutableConfig[]
   *   The active split configurations.
   */
  public function getActiveSplitConfig();

  /**
   * Get the storage wrapper with all filters applied.
   *
   * @param \Drupal\Core\Config\Config[] $configs
   *   The configuration instance(s) for the SplitFilter.
   * @param \Drupal\Core\Config\StorageInterface $primary
   *   The primary storage, typically what is defined by CONFIG_SYNC_DIRECTORY.
   * @param \Drupal\Core\Config\StorageInterface[] $storages
   *   The secondary storage to override the configurations file storage.
   *
   * @return \Drupal\config_split\Config\StorageWrapper
   *   The storage, wrapped to do the filtering.
   */
  public function getStorage($configs, StorageInterface $primary, $storages = []);

  /**
   * Returns the Config storage wrapper wrapping cores sync storage.
   *
   * All active splits will be applied in order.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The config storage that core can use.
   */
  public function getDefaultStorageWrapper();

  /**
   * Get a config storage for a given split configuration.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The split configuration.
   *
   * @return \Drupal\Core\Config\StorageInterface|null
   *   The config storage to use for it.
   */
  public function getSingleSplitStorage(Config $config);

}
