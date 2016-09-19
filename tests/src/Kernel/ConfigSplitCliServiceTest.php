<?php

namespace Drupal\Tests\config_split\Kernel;

use Drupal\config\Controller\ConfigController;
use Drupal\Core\Archiver\Tar;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\KernelTests\KernelTestBase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamContent;

/**
 * Class ConfigSplitCliServiceTest.
 *
 * @group config_split
 */
class ConfigSplitCliServiceTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'config',
    'config_test',
    'config_split',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'config_test']);

  }

  /**
   * Test that our export behaves the same as Drupal core without a split.
   */
  public function testVanillaExport() {
    // Export the configuration the way drupal core does it.
    $configController = ConfigController::create($this->container);
    // Download and open the tar file.
    $file = $configController->downloadExport()->getFile()->openFile();
    $archive_data = $file->fread($file->getSize());
    // Save the tar file to unpack and read it.
    // See \Drupal\config\Tests\ConfigExportUITest::testExport()
    $uri = file_unmanaged_save_data($archive_data, 'temporary://config.tar.gz');
    $file_path = file_directory_temp() . '/' . file_uri_target($uri);
    $archiver = new Tar($file_path);
    $this->assertNotEmpty($archiver->listContents(), 'Downloaded archive file is not empty.');

    // Extract the zip to a virtual file system.
    $core_export = vfsStream::setup('core-export');
    $archiver->extract($core_export->url());
    $this->assertNotEmpty($core_export->getChildren(), 'Successfully extract archive.');

    // Set a new virtual file system for the split export.
    $split_export = vfsStream::setup('split-export');
    $primary = new FileStorage($split_export->url());
    $this->assertEmpty($split_export->getChildren(), 'Before exporting the folder is empty.');

    // Do the export without a split configuration to the export folder.
    $this->container->get('config_split.cli')->export([], $primary);

    // Assert that the exported configuration is the same in both cases.
    $this->assertEquals(count($core_export->getChildren()), count($split_export->getChildren()), 'The same amount of config is exported.');
    foreach ($core_export->getChildren() as $child) {
      $name = $child->getName();
      if ($child->getType() == vfsStreamContent::TYPE_FILE) {
        // If it is a file we can compare the content.
        $this->assertEquals($child->getContent(), $split_export->getChild($name)->getContent(), 'The content of the exported file is the same.');
      }
    }

  }

  /**
   * Test a simple export split.
   */
  public function testSimpleSplitExport() {
    // Export the configuration the way Drupal core does.
    $vanilla = vfsStream::setup('vanilla');
    $vanilla_primary = new FileStorage($vanilla->url());
    $this->container->get('config_split.cli')->export([], $vanilla_primary);

    // Set the split stream up.
    $split = vfsStream::setup('split');
    $primary = new FileStorage($split->url() . '/sync');
    $config = new ImmutableConfig('test_split', $this->container->get('config.storage'), $this->container->get('event_dispatcher'), $this->container->get('config.typed'));
    $config->initWithData([
      'folder' => $split->url() . '/split',
      'module' => ['config_test' => 0],
      'theme' => [],
      'blacklist' => [],
    ]);

    // Export the configuration without the test configuration.
    $this->container->get('config_split.cli')->export([$config], $primary);

    // Extract the configuration for easier comparison.
    $vanilla_config = [];
    foreach ($vanilla->getChildren() as $child) {
      if ($child->getType() == vfsStreamContent::TYPE_FILE && $child->getName() != '.htaccess') {
        $vanilla_config[$child->getName()] = $child->getContent();
      }
    }

    $sync_config = [];
    foreach ($split->getChild('sync')->getChildren() as $child) {
      if ($child->getType() == vfsStreamContent::TYPE_FILE && $child->getName() != '.htaccess') {
        $sync_config[$child->getName()] = $child->getContent();
      }
    }

    $split_config = [];
    foreach ($split->getChild('split')->getChildren() as $child) {
      if ($child->getType() == vfsStreamContent::TYPE_FILE && $child->getName() != '.htaccess') {
        $split_config[$child->getName()] = $child->getContent();
      }
    }
    $this->assertNotEmpty($split_config, 'There is split off configuration.');
    $this->assertEquals(count($vanilla_config), count($sync_config) + count($split_config), 'All the config is still here.');

    foreach ($vanilla_config as $name => $content) {
      if ($name == 'core.extension.yml') {
        continue;
      }
      // All the filtered test config has config_test in its name.
      if (strpos($name, 'config_test') === FALSE) {
        $this->assertEquals($content, $sync_config[$name], 'The configuration is complete.');
        $this->assertNotContains($name, array_keys($split_config), 'And it does not exist in the other folder.');
      }
      else {
        $this->assertEquals($content, $split_config[$name], 'The configuration is complete.');
        $this->assertNotContains($name, array_keys($sync_config), 'And it does not exist in the other folder.');
      }
    }

    $this->assertNotFalse(strpos($vanilla_config['core.extension.yml'], 'config_test'), 'config_test is enabled.');
    $this->assertFalse(strpos($sync_config['core.extension.yml'], 'config_test'), 'config_test is not enabled.');

  }

}
