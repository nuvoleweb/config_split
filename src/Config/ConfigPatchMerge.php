<?php

declare(strict_types=1);

namespace Drupal\config_split\Config;

use Drupal\Component\Utility\DiffArray;
use Drupal\Component\Utility\NestedArray;

/**
 * The patch merging service.
 *
 * @internal This is not an API, anything here might change without notice. Use config_merge 2.x instead.
 */
class ConfigPatchMerge {

  /**
   * The sorter service.
   *
   * @var \Drupal\config_split\Config\ConfigSorter
   */
  protected $configSorter;

  /**
   * The service constructor.
   *
   * @param \Drupal\config_split\Config\ConfigSorter $configSorter
   *   The sorter.
   */
  public function __construct(ConfigSorter $configSorter) {
    $this->configSorter = $configSorter;
  }

  /**
   * Create a patch object given two arrays.
   *
   * @param array $original
   *   The original data.
   * @param array $new
   *   The new data.
   *
   * @return \Drupal\config_split\Config\ConfigPatch
   *   The patch object.
   */
  public function createPatch(array $original, array $new): ConfigPatch {
    return ConfigPatch::fromArray([
      'added' => DiffArray::diffAssocRecursive($new, $original),
      'removed' => DiffArray::diffAssocRecursive($original, $new),
    ]);
  }

  /**
   * Apply a patch to a config array.
   *
   * @param array $config
   *   The config data.
   * @param \Drupal\config_split\Config\ConfigPatch $patch
   *   The patch object.
   * @param string|null $name
   *   The config name to sort it correctly.
   *
   * @return array
   *   The changed config data.
   */
  public function mergePatch(array $config, ConfigPatch $patch, string $name = NULL): array {
    if ($patch->isEmpty()) {
      return $config;
    }

    $changed = DiffArray::diffAssocRecursive($config, $patch->getRemoved());
    $changed = NestedArray::mergeDeepArray([$changed, $patch->getAdded()], TRUE);

    // Make sure not to remove the dependencies key from config entities.
    if (isset($config['dependencies']) && !isset($changed['dependencies'])) {
      $changed['dependencies'] = [];
    }
    // Make sure the order of the keys is still the same.
    $changed = array_replace(array_intersect_key($config, $changed), $changed);

    if ($name !== NULL) {
      // Also sort the config if we know the name.
      $changed = $this->configSorter->sort($name, $changed);
    }

    return $changed;
  }

}
