<?php

namespace Drupal\config_split_filter_plugin;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Disable the config transformation of config_split when we use the 1.x filter.
 */
class ConfigSplitFilterPluginServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Just flat out remove the event subscriber.
    // This module provides the Config Split 1.x filter plugins.
    $container->removeDefinition('config_split.event_subscriber');
  }

}
