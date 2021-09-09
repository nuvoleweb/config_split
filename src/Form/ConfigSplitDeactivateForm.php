<?php

namespace Drupal\config_split\Form;

use Drupal\config_split\Config\StatusOverride;
use Drupal\config_split\ConfigSplitManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The form for de-activating a split.
 */
class ConfigSplitDeactivateForm extends FormBase {

  use ConfigImportFormTrait;

  /**
   * The active config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * The split manager.
   *
   * @var \Drupal\config_split\ConfigSplitManager
   */
  protected $manager;

  /**
   * The status override service.
   *
   * @var \Drupal\config_split\Config\StatusOverride
   */
  protected $statusOverride;

  /**
   * The constructor.
   *
   * @param \Drupal\Core\Config\StorageInterface $activeStorage
   *   The active config storage.
   * @param \Drupal\config_split\ConfigSplitManager $configSplitManager
   *   The split manager.
   * @param \Drupal\config_split\Config\StatusOverride $statusOverride
   *   The status override service.
   */
  public function __construct(
    StorageInterface $activeStorage,
    ConfigSplitManager $configSplitManager,
    StatusOverride $statusOverride
  ) {
    $this->activeStorage = $activeStorage;
    $this->manager = $configSplitManager;
    $this->statusOverride = $statusOverride;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.storage'),
      $container->get('config_split.manager'),
      $container->get('config_split.status_override')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_split_deactivate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $split = $this->getSplit();

    $comparer = new StorageComparer($this->manager->singleDeactivate($split, FALSE), $this->activeStorage);
    $options = [
      'route' => [
        'config_split' => $split->getName(),
        'operation' => 'deactivate',
      ],
      'operation label' => $this->t('Import all'),
    ];
    $form = $this->buildFormWithStorageComparer($form, $form_state, $comparer, $options);

    $locallyActivated = $this->statusOverride->getSplitOverride($split->getName()) === TRUE;
    $form['deactivate_local_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Deactivate locally only'),
      '#description' => $this->t('If this is set, the split config will not be made inactive by default but instead it will be locally overwritten to be inactive.'),
      '#default_value' => !$locallyActivated,
    ];

    if ($locallyActivated) {
      $form['deactivation_notice'] = [
        '#type' => 'markup',
        '#markup' => $this->t('The local activation state override will be removed'),
      ];
    }

    $entity = $this->manager->getSplitEntity($split->getName());
    $form['export_before'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Export the config before deactivating.'),
      '#description' => $this->t('To manually export and see what is exported check <a href="@export-page">the export page</a>.', ['@export-page' => $entity->toUrl('export')->toString()]),
      '#default_value' => !$locallyActivated,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $split = $this->getSplit();

    $override = FALSE;
    if ($form_state->getValue('deactivate_local_only')) {
      $this->statusOverride->setSplitOverride($split->getName(), FALSE);
      $override = TRUE;
    }
    else {
      $this->statusOverride->setSplitOverride($split->getName(), NULL);
    }

    $comparer = new StorageComparer($this->manager->singleDeactivate($split, $form_state->getValue('export_before'), $override), $this->activeStorage);
    $this->launchImport($comparer);
  }

  /**
   * Checks access for a specific request.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    $split = $this->getSplit();
    return AccessResult::allowedIfHasPermission($account, 'administer configuration split')
      ->andIf(AccessResult::allowedIf($split->get('status')))
      ->addCacheableDependency($split);
  }

}
