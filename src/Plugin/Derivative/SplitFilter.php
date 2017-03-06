<?php

namespace Drupal\config_split\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides block plugin definitions for custom menus.
 *
 * @see \Drupal\config_split\Plugin\ConfigFilter\SplitFilter
 */
class SplitFilter extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The menu storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityStorage;

  /**
   * The config Factory to load the overridden configuration.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs new SystemMenuBlock.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $menu_storage
   *   The menu storage.
   */
  public function __construct(EntityStorageInterface $menu_storage, ConfigFactoryInterface $config_factory) {
    $this->entityStorage = $menu_storage;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager')->getStorage('config_split'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityStorage->loadMultiple() as $name => $entity) {
      $config = $this->configFactory->get($entity->getConfigDependencyName());
      $this->derivatives[$name] = $base_plugin_definition;
      $this->derivatives[$name]['label'] = $entity->label();
      $this->derivatives[$name]['config_name'] = $entity->getConfigDependencyName();
      $this->derivatives[$name]['weight'] = $config->get('weight');
      $this->derivatives[$name]['status'] = $config->get('status');
      $this->derivatives[$name]['config_dependencies']['config'] = [$entity->getConfigDependencyName()];
    }
    return $this->derivatives;
  }

}
