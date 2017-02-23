<?php

namespace Drupal\config_split\Command;

use Drupal\config_split\Entity\ConfigSplitEntity;
use Symfony\Component\Console\Command\Command;

/**
 * Class SplitCommandBase for shared functionality.
 */
abstract class SplitCommandBase extends Command {

  /**
   * Get the configuration name from the short name.
   *
   * @param string $name
   *   The name to get the config name for.
   *
   * @return string
   *   The split configuration name.
   */
  protected function getSplitName($name) {

    if (strpos($name, 'config_split.config_split.') != 0) {
      $name = 'config_split.config_split.' . $name;
    }

    $splits = array_map(function (ConfigSplitEntity $split) {
      return $split->getConfigDependencyName();
    },
      \Drupal::entityTypeManager()->getStorage('config_split')->loadMultiple());

    if (!in_array($name, $splits)) {
      throw new \InvalidArgumentException('The following splits is not available: ' . $name);
    }

    return $name;
  }

}
