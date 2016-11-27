<?php

namespace Drupal\config_split;

use Drupal\config_split\Config\SplitFilter;
use Drupal\config_split\Config\StorageWrapper;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\FileStorageFactory;
use Drupal\Core\Config\StorageInterface;

/**
 * Class ConfigSplitManager.
 *
 * @package Drupal\config_split
 */
class ConfigSplitManager implements ConfigSplitManagerInterface {

  /**
   * The config manager used to load config and pass into split filters.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * ConfigSplitManager constructor.
   *
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The injected config manager.
   */
  public function __construct(ConfigManagerInterface $config_manager) {
    $this->configManager = $config_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllSplitConfig() {
    $names = $this->configManager->getConfigFactory()->listAll('config_split.config_split.');
    $config = $this->configManager->getConfigFactory()->loadMultiple($names);

    // Sort the configuration by weight.
    uasort($config, function ($a, $b) {
      return strcmp($a->get('weight'), $b->get('weight'));
    });

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveSplitConfig() {

    // Include only active config.
    return array_filter($this->getAllSplitConfig(), function ($item) {
      return $item->get('status');
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getStorage($configs, StorageInterface $primary, $storages = []) {
    $filter = [];
    foreach ($configs as $key => $config) {
      // The secondary storage if given, from the configuration otherwise.
      $storage = isset($storages[$key]) ? $storages[$key] : $this->getSingleSplitStorage($config);
      $filter[] = new SplitFilter($config, $this->configManager, $storage);
    }

    return new StorageWrapper($primary, $filter);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultStorageWrapper() {
    $configs = $this->getActiveSplitConfig();
    $storage = FileStorageFactory::getSync();
    return $this->getStorage($configs, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public function getSingleSplitStorage(Config $config) {
    // Here we could determine to use relative paths etc.
    if ($config->get('folder')) {
      return new FileStorage($config->get('folder'));
    }
    return NULL;
  }

}
