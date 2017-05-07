<?php

namespace Drupal\config_split\Tests;

use Drupal\config_filter\Config\ReadOnlyStorage;
use Drupal\config_filter\Exception\UnsupportedMethod;
use Drupal\config_filter\Tests\ReadonlyStorageTest;
use Drupal\config_split\Config\GhostStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\MethodProphecy;

/**
 * Tests GhostStorage operations
 *
 * @group config_split
 */
class GhostStorageTest extends ReadonlyStorageTest {

  protected function getStorage(StorageInterface $source) {
    return new GhostStorage($source);
  }

  /**
   * @dataProvider writeMethodsProvider
   */
  public function testWriteOperations($method, $arguments) {
    $source = $this->prophesize(StorageInterface::class);
    $source->$method(Argument::any())->shouldNotBeCalled();

    $storage = $this->getStorage($source->reveal());

    $actual = call_user_func_array([$storage, $method], $arguments);
    $this->assertTrue($actual);
  }


}
