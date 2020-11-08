<?php

namespace Drupal\Tests\config_split\Kernel;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;

/**
 * Test the splitting and merging.
 *
 * These are the integration tests to assert that the module has the behaviour
 * on import and export that we expect. This is supposed to not go into internal
 * details of how config split achieves this.
 *
 * @group config_split_1x
 */
class SplitMergeLegacyTest extends SplitMergeTest {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'system',
    'language',
    'user',
    'node',
    'field',
    'text',
    'config',
    'config_test',
    'config_exclude_test',
    'config_split',
    // We do the same tests with the legacy plugin.
    'config_filter',
    'config_split_filter_plugin',
  ];

  /**
   * Get the preview storage for a split, 1.x does not have a preview storage.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The split config.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage.
   */
  protected function getSplitPreviewStorage(Config $config): StorageInterface {
    // We cache it in its own memory storage so that it becomes decoupled.
    $memory = new MemoryStorage();
    // For now just get the source, there is no preview yet.
    $this->copyConfig($this->getSplitSourceStorage($config), $memory);
    return $memory;
  }

}
