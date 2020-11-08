<?php

namespace Drupal\config_split_filter_plugin\Tests;

use Drupal\config_split_filter_plugin\Config\GhostStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Tests\Core\Config\ReadOnlyStorageTest;

/**
 * Tests GhostStorage operations.
 *
 * @group config_split_filter_plugin
 */
class GhostStorageTest extends ReadOnlyStorageTest {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Wrap the memory storage in the read-only storage to test it.
    $this->storage = new GhostStorage($this->memory);
  }

  /**
   * @covers ::write
   * @covers ::delete
   * @covers ::rename
   * @covers ::deleteAll
   *
   * @dataProvider writeMethodsProvider
   */
  public function testWriteOperations($method, $arguments, $fixture) {
    $this->setRandomFixtureConfig($fixture);

    // Create an independent memory storage as a backup.
    $backup = new MemoryStorage();
    static::replaceStorageContents($this->memory, $backup);

    $actual = call_user_func_array([$this->storage, $method], $arguments);
    $this->assertTrue($actual);

    // Assert that the memory storage has not been altered.
    $this->assertTrue($backup == $this->memory);
  }

}
