<?php

namespace Drupal\Tests\config_split\Kernel;

use Drupal\config_split\Config\SplitCollectionStorage;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;

/**
 * Trait to facilitate creating split configurations.
 */
trait SplitTestTrait {

  /**
   * Create a split configuration.
   *
   * @param string $name
   *   The name of the split.
   * @param array $data
   *   The split config data.
   *
   * @return \Drupal\Core\Config\Config
   *   The split config object.
   */
  protected function createSplitConfig(string $name, array $data): Config {
    if (substr($name, 0, strlen('config_split.config_split.')) !== 'config_split.config_split.') {
      // Allow using the id as the config name to keep it short.
      $name = 'config_split.config_split.' . $name;
    }
    // Add default values.
    $data += [
      'storage' => (isset($data['folder']) && $data['folder'] != '') ? 'folder' : 'database',
      'status' => TRUE,
      'weight' => 0,
      'folder' => (isset($data['storage']) && $data['storage'] == 'folder') ? Settings::get('file_public_path') . '/config/split' : '',
      'module' => [],
      'theme' => [],
      'complete_list' => [],
      'partial_list' => [],
    ];
    // Set the id from the name.
    $data['id'] = substr($name, strlen('config_split.config_split.'));
    // Create the config.
    $config = new Config($name, $this->container->get('config.storage'), $this->container->get('event_dispatcher'), $this->container->get('config.typed'));
    $config->initWithData($data)->save();

    return $config;
  }

  /**
   * Get the storage for a split.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The split config.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage.
   */
  protected function getSplitSourceStorage(Config $config): StorageInterface {
    switch ($config->get('storage')) {
      case 'folder':
        return new FileStorage($config->get('folder'));

      case 'collection':
        return new SplitCollectionStorage($this->getSyncFileStorage(), $config->get('id'));

      case 'database':
        // We don't escape the name, it is tests after all.
        return new DatabaseStorage($this->container->get('database'), strtr($config->getName(), ['.' => '_']));
    }
    throw new \LogicException();
  }

  /**
   * Get the preview storage for a split.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The split config.
   * @param \Drupal\Core\Config\StorageInterface $export
   *   The export storage to graft collection storages on.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage.
   */
  protected function getSplitPreviewStorage(Config $config, StorageInterface $export = NULL): StorageInterface {
    if ('collection' === $config->get('storage')) {
      if ($export === NULL) {
        throw new \InvalidArgumentException();
      }
      return new SplitCollectionStorage($export, $config->get('id'));
    }
    $name = substr($config->getName(), strlen('config_split.config_split.'));
    $storage = new DatabaseStorage($this->container->get('database'), 'config_split_preview_' . strtr($name, ['.' => '_']));
    // We cache it in its own memory storage so that it becomes decoupled.
    $memory = new MemoryStorage();
    $this->copyConfig($storage, $memory);
    return $memory;
  }

}
