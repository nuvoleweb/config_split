<?php
declare(strict_types=1);

namespace Drupal\Tests\config_split\Unit;

use Drupal\config_split\Config\ConfigPatch;
use Drupal\config_split\Config\ConfigPatchMerge;
use Drupal\config_split\Config\ConfigSorter;
use PHPUnit\Framework\TestCase;

class ConfigPatchTest extends TestCase {

  protected $patchMerge;

  public function setUp(): void{
    parent::setUp();

    $this->patchMerge = new ConfigPatchMerge($this->prophesize(ConfigSorter::class)->reveal());
  }

  public function testSimpleMergeExample() {
    // This is a much simplified version of some config. We use the complete
    // split to split off the module 'a' but we also partially split the config.
    // This is the active config.
    $active = [
      'dependencies' => ['a', 'b'],
      'something' => 'A'
    ];

    // This is the config in the sync storage before changes were made.
    $sync = [
      'dependencies' => ['a', 'b'],
      'something_else' => 'B'
    ];

    // This is the config which was updated by removing 'a'.
    // The patch already created by the complete split would contain this.
    $updated = [
      'dependencies' => ['b'],
      'something' => 'A'
    ];

    // This is what we expect to be exported at then end.
    $expected = [
      'dependencies' => ['b'],
      'something_else' => 'B'
    ];

    // This is the patch which is already in the split storage.
    $patch1 = $this->patchMerge->createPatch($active, $updated);
    // This is the "fixed" sync storage so that we can create a merged patch.
    $fixed = $this->patchMerge->mergePatch($sync, $patch1);

    // This is the patch we want to export.
    $patch2 = $this->patchMerge->createPatch($active, $fixed);
    // This is what we export.
    $export = $this->patchMerge->mergePatch($active, $patch2);
    self::assertEqualsCanonicalizing($expected, $export);

    // When doing the reverse we expect it to work again.
    $import = $this->patchMerge->mergePatch($sync, $patch2->invert());
    self::assertEqualsCanonicalizing($active, $import);
    $import = $this->patchMerge->mergePatch($export, $patch2->invert());
    self::assertEqualsCanonicalizing($active, $import);
  }

}
