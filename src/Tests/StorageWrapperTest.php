<?php

namespace Drupal\config_filter\Tests;


use Drupal\config_filter\Config\StorageFilterBase;
use Drupal\config_filter\Config\StorageWrapper;
use Drupal\KernelTests\Core\Config\Storage\CachedStorageTest;

/**
 * Tests StorageWrapper operations using the CachedStorage.
 *
 * @group config_filter
 */
class StorageWrapperTest extends CachedStorageTest {

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // The storage is a wrapper with a transparent filter.
    // So all inherited tests should still pass.
    $this->storage = new StorageWrapper($this->storage, new StorageFilterBase());
  }
}