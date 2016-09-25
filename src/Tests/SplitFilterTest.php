<?php

namespace Drupal\config_split\Tests;

use Drupal\config_split\Config\SplitFilter;
use Drupal\Core\Config\NullStorage;
use Drupal\Core\Config\StorageInterface;
use \Drupal\Tests\UnitTestCase;
use Prophecy\Argument;

/**
 * Class SplitFilterTest.
 *
 * @group config_split
 */
class SplitFilterTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * Test that the blacklist is correctly calculated.
   */
  public function testBlacklist() {
    $config = $this->prophesize('Drupal\Core\Config\ImmutableConfig');
    $config->get('blacklist')->willReturn(['a', 'b']);
    $config->get('module')->willReturn(['module1' => 0, 'module2' => 0]);
    $config->get('theme')->willReturn(['theme1' => 0]);

    // The config manager returns dependent entities for modules and themes.
    $manager = $this->prophesize('Drupal\Core\Config\ConfigManagerInterface');
    $manager->findConfigEntityDependents(Argument::exact('module'), Argument::exact(['module1', 'module2']))->willReturn(['c' => 0, 'd' => 0, 'a' => 0]);
    $manager->findConfigEntityDependents(Argument::exact('theme'), Argument::exact(['theme1']))->willReturn(['e' => 0, 'f' => 0, 'c' => 0]);
    // Add a config storage returning some settings for the filtered modules.
    $manager->getConfigFactory()->willReturn($this->getConfigStorageStub(['module1.settings' => [], 'module3.settings' => []]));
    // Add more config dependencies, independently of what is asked for.
    $manager->findConfigEntityDependents(Argument::exact('config'), Argument::cetera())->willReturn(['f' => 0, 'g' => 0, 'b' => 0]);

    $filter = new SplitFilter($config->reveal(), $manager->reveal());

    // Get the protected blacklist property.
    $blacklist = new \ReflectionProperty('Drupal\config_split\Config\SplitFilter', 'blacklist');
    $blacklist->setAccessible(TRUE);
    $actual = $blacklist->getValue($filter);
    // The order of values and keys are not important.
    sort($actual);
    $this->assertArrayEquals(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'module1.settings'], $actual);
  }

  /**
   * Test that the filter reads correctly.
   */
  public function testFilterRead() {
    // Transparent filter.
    $name = $this->randomMachineName();
    $data = (array) $this->getRandomGenerator()->object();
    $filter = $this->getFilter();
    $this->assertEquals($data, $filter->filterRead($name, $data));

    // Filter with a storage that has an alternative.
    $name2 = $this->randomMachineName();
    $data2 = (array) $this->getRandomGenerator()->object();
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->read($name)->willReturn(NULL);
    $storage->read($name2)->willReturn($data2);
    $filter = $this->getFilter($storage->reveal());
    $this->assertEquals($data, $filter->filterRead($name, $data));
    $this->assertEquals($data2, $filter->filterRead($name2, $data));

    // Test that extensions are correctly added.
    $extensions = [
      'module' => [
        'config' => 0,
        'user' => 0,
        'views_ui' => 0,
        'menu_link_content' => 1,
        'views' => 10,
      ],
      'theme' => ['stable' => 0, 'classy' => 0],
    ];
    $modules = [
      'module1' => 0,
      'module2' => 1,
    ];
    $themes = [
      'custom_theme' => 0,
    ];
    $extensions_extra = [
      'module' => [
        'config' => 0,
        'module1' => 0,
        'user' => 0,
        'views_ui' => 0,
        'menu_link_content' => 1,
        'module2' => 1,
        'views' => 10,
      ],
      'theme' => ['stable' => 0, 'classy' => 0, 'custom_theme' => 0],
    ];
    $filter = $this->getFilter(NULL, [], $modules, $themes);
    $this->assertEquals($extensions_extra, $filter->filterRead('core.extension', $extensions));
    $this->assertEquals($extensions_extra, $filter->filterRead('core.extension', $extensions_extra));
  }

  /**
   * Test that the filter writes correctly.
   */
  public function testFilterWrite() {
    // Transparent filter.
    $name = $this->randomMachineName();
    $data = (array) $this->getRandomGenerator()->object();
    $filter = $this->getFilter();
    $this->assertEquals($data, $filter->filterWrite($name, $data));

    // Filter with a blacklist.
    $name2 = $this->randomMachineName();
    $filter = $this->getFilter(NULL, [$name2], [], []);
    $this->assertEquals($data, $filter->filterWrite($name, $data));
    $this->assertNull($filter->filterWrite($name2, $data));
    // Filter with a blacklist and a storage.
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->write(Argument::cetera())->willReturn(TRUE);
    $storage->exists($name)->willReturn(FALSE);
    $filter = $this->getFilter($storage->reveal(), [$name2], [], []);
    $this->assertEquals($data, $filter->filterWrite($name, $data));
    $this->assertNull($filter->filterWrite($name2, $data));

    // Filter with a gray list and a storage.
    $name3 = $this->randomMachineName();
    $data3 = (array) $this->getRandomGenerator()->object();
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->write(Argument::cetera())->willReturn(TRUE);
    $storage->read($name3)->willReturn($data3);
    $storage->exists($name)->willReturn(TRUE);
    $storage->delete($name)->willReturn(TRUE)->shouldBeCalled();
    $storage = $storage->reveal();
    $filter = $this->getFilter($storage, [$name2], [], [], [$name3]);
    $this->assertEquals($data, $filter->filterWrite($name, $data, $storage));
    $this->assertNull($filter->filterWrite($name2, $data, $storage));
    $this->assertEquals($data3, $filter->filterWrite($name3, $data, $storage));

    // Test that extensions are correctly removed.
    $extensions = [
      'module' => [
        'config' => 0,
        'user' => 0,
        'views_ui' => 0,
        'menu_link_content' => 1,
        'views' => 10,
      ],
      'theme' => ['stable' => 0, 'classy' => 0],
    ];
    $modules = [
      'module1' => 0,
      'module2' => 1,
    ];
    $themes = [
      'custom_theme' => 0,
    ];
    $extensions_extra = [
      'module' => [
        'config' => 0,
        'module1' => 0,
        'user' => 0,
        'views_ui' => 0,
        'menu_link_content' => 1,
        'module2' => 1,
        'views' => 10,
      ],
      'theme' => ['stable' => 0, 'classy' => 0, 'custom_theme' => 0],
    ];
    $filter = $this->getFilter(NULL, [], $modules, $themes);
    $this->assertEquals($extensions, $filter->filterWrite('core.extension', $extensions));
    $this->assertEquals($extensions, $filter->filterWrite('core.extension', $extensions_extra));
  }

  /**
   * Test that the filter checks existence correctly.
   */
  public function testFilterExists() {
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->exists('Yes')->willReturn(TRUE);
    $storage->exists('No')->willReturn(FALSE);

    $transparent = $this->getFilter(NULL);
    $filter = $this->getFilter($storage->reveal());

    $this->assertTrue($transparent->filterExists('Yes', TRUE));
    $this->assertTrue($transparent->filterExists('No', TRUE));
    $this->assertFalse($transparent->filterExists('Yes', FALSE));
    $this->assertFalse($transparent->filterExists('No', FALSE));

    $this->assertTrue($filter->filterExists('Yes', TRUE));
    $this->assertTrue($filter->filterExists('No', TRUE));
    $this->assertTrue($filter->filterExists('Yes', FALSE));
    $this->assertFalse($filter->filterExists('No', FALSE));
  }

  /**
   * Test that the filter deletes correctly.
   */
  public function testFilterDelete() {
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->delete('Yes')->willReturn(TRUE);

    $transparent = $this->getFilter(NULL);
    $filter = $this->getFilter($storage->reveal());

    $this->assertTrue($transparent->filterDelete('Yes', TRUE));
    $this->assertFalse($transparent->filterDelete('No', FALSE));

    $this->assertTrue($filter->filterDelete('Yes', TRUE));
    $this->assertFalse($filter->filterDelete('No', FALSE));
  }

  /**
   * Test that the filter reads multiple objects correctly.
   */
  public function testFilterReadMultiple() {
    // Set up random config storage.
    $primary = (array) $this->getRandomGenerator()->object(rand(3, 10));
    $secondary = (array) $this->getRandomGenerator()->object(rand(3, 10));
    $merged = array_merge($primary, $secondary);
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->readMultiple(Argument::cetera())->willReturn($secondary);

    $transparent = $this->getFilter(NULL);
    $filter = $this->getFilter($storage->reveal());

    // Test listing config.
    $this->assertArrayEquals($primary, $transparent->filterReadMultiple(array_keys($merged), $primary));
    $this->assertArrayEquals($merged, $filter->filterReadMultiple(array_keys($merged), $primary));
  }

  /**
   * Test that the filter lists all correctly.
   */
  public function testFilterListAll() {
    // Set up random config storage.
    $primary = (array) $this->getRandomGenerator()->object(rand(3, 10));
    $secondary = (array) $this->getRandomGenerator()->object(rand(3, 10));
    $merged = array_merge($primary, $secondary);
    $storage = $this->getConfigStorageStub($secondary);

    $transparent = $this->getFilter(NULL);
    $filter = $this->getFilter($storage);

    // Test listing config.
    $this->assertArrayEquals(array_keys($primary), $transparent->filterListAll('', array_keys($primary)));
    $this->assertArrayEquals(array_keys($merged), $filter->filterListAll('', array_keys($primary)));
  }

  /**
   * Test that the filter deletes all correctly.
   */
  public function testFilterDeleteAll() {
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->deleteAll('Yes')->willReturn(TRUE);

    $transparent = $this->getFilter(NULL);
    $filter = $this->getFilter($storage->reveal());

    $this->assertTrue($transparent->filterDeleteAll('Yes', TRUE));
    $this->assertFalse($transparent->filterDeleteAll('No', FALSE));

    $this->assertTrue($filter->filterDeleteAll('Yes', TRUE));
    $this->assertFalse($filter->filterDeleteAll('No', FALSE));
  }

  /**
   * Test that the filter creates collections correctly.
   */
  public function testFilterCreateCollection() {
    $collection = $this->randomMachineName();
    $collection_storage = new NullStorage();
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->createCollection($collection)->willReturn($collection_storage);

    $transparent = $this->getFilter(NULL);
    $this->assertEquals($transparent, $transparent->filterCreateCollection($collection));

    $filter = $this->getFilter($storage->reveal());
    $new_filter = $filter->filterCreateCollection($collection);

    // Get the protected storage property.
    $internal = new \ReflectionProperty('Drupal\config_split\Config\SplitFilter', 'secondaryStorage');
    $internal->setAccessible(TRUE);
    $actual = $internal->getValue($new_filter);
    $this->assertEquals($collection_storage, $actual);
  }

  /**
   * Test that the filter gets collections names correctly.
   */
  public function testFilterGetAllCollectionNames() {
    $collections = array_keys((array) $this->getRandomGenerator()->object(rand(3, 10)));
    $extra = array_keys((array) $this->getRandomGenerator()->object(rand(3, 10)));
    $storage = $this->prophesize('Drupal\Core\Config\StorageInterface');
    $storage->getAllCollectionNames()->willReturn($extra);

    $transparent = $this->getFilter(NULL);
    $filter = $this->getFilter($storage->reveal());

    $this->assertArrayEquals($collections, $transparent->filterGetAllCollectionNames($collections));
    $this->assertArrayEquals(array_merge($collections, $extra), $filter->filterGetAllCollectionNames($collections));
  }

  /**
   * Returns a SplitFilter that can be used to test its behaviour.
   *
   * @param \Drupal\Core\Config\StorageInterface|null $storage
   *   The Storage interface the filter can use as its alternative storage.
   * @param array $blacklist
   *   The blacklisted configuration that is filtered out.
   * @param array $modules
   *   The blacklisted modules that are removed from the core.extensions.
   * @param array $themes
   *   The blacklisted themes that are removed from the core.extensions.
   *
   * @return \Drupal\config_split\Config\SplitFilter
   *   The filter to test.
   */
  protected function getFilter(StorageInterface $storage = NULL, array $blacklist = [], array $modules = [], array $themes = [], array $graylist = []) {
    // Set up a Config object that returns the blacklist and modules.
    $config = $this->prophesize('Drupal\Core\Config\ImmutableConfig');
    $config->get('blacklist')->willReturn($blacklist);
    $config->get('graylist')->willReturn($graylist);
    $config->get('module')->willReturn($modules);
    $config->get('theme')->willReturn($themes);

    // The manager returns nothing but allows the filter to set up correctly.
    // This means that the blacklist is not enhanced but only the one passed
    // as an argument is used.
    $manager = $this->prophesize('Drupal\Core\Config\ConfigManagerInterface');
    $manager->findConfigEntityDependents(Argument::cetera())->willReturn([]);
    $manager->getConfigFactory()->willReturn($this->getConfigStorageStub([]));

    // Return a new filter that behaves as intended.
    return new SplitFilter($config->reveal(), $manager->reveal(), $storage);
  }

}
