<?php

namespace Drupal\config_split;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Class ConfigSplitServiceProvider.
 *
 * @package Drupal\config_split
 */
class ConfigSplitServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    try {
      $container->getDefinition('plugin.manager.config_filter');
    }
    catch (ServiceNotFoundException $exception) {
      // The config_split.cli service depends on the config_filter plugin
      // manager, since this might not exist for the updates from pre-beta3
      // we remove the service so that the update hook can run.
      $container->removeDefinition('config_split.cli');
    }
  }

}
