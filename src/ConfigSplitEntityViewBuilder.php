<?php

namespace Drupal\config_split;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * EntityViewBuilder for Config Split entities.
 */
class ConfigSplitEntityViewBuilder extends EntityViewBuilder {

  /**
   * The split manager.
   *
   * @var \Drupal\config_split\ConfigSplitManager
   */
  protected $splitManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $handler = parent::createInstance($container, $entity_type);
    $handler->splitManager = $container->get('config_split.manager');
    return $handler;
  }

  /**
   * {@inheritdoc}
   */
  public function viewMultiple(array $entities = [], $view_mode = 'full', $langcode = NULL) {
    /** @var \Drupal\config_split\Entity\ConfigSplitEntityInterface[] $entities */
    $build = [];

    /**
     * @var string $entity_id
     * @var \Drupal\config_split\Entity\ConfigSplitEntity $entity
     */
    foreach ($entities as $entity_id => $entity) {
      $config = $this->splitManager->getSplitConfig($entity->getConfigDependencyName());

      // @todo: make this prettier.
      $build[$entity_id] = [
        'complete' => [
          '#type' => 'container',
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Complete Split Config'),
          ],
          'items' => [
            '#theme' => 'item_list',
            '#items' => $this->splitManager->calculateCompleteSplitList($config),
            '#list_type' => 'ul',
          ],
        ],
        'conditional' => [
          '#type' => 'container',
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Conditional Split Config'),
          ],
          'items' => [
            '#theme' => 'item_list',
            '#items' => $this->splitManager->calculateCondiionalSplitList($config),
            '#list_type' => 'ul',
          ],
        ],
        '#cache' => [
          'tags' => $entity->getCacheTags(),
        ],
      ];
    }

    return $build;
  }

}
