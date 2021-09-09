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
 * The form for importing a split.
 */
class ConfigSplitImportForm extends FormBase {

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
    return 'config_split_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $split = $this->getSplit();
    $comparer = new StorageComparer($this->manager->singleImport($split, !$split->get('status')), $this->activeStorage);
    $options = [
      'route' => [
        'config_split' => $split->getName(),
        'operation' => 'import',
      ],
      'operation label' => $this->t('Import all'),
    ];
    $form = $this->buildFormWithStorageComparer($form, $form_state, $comparer, $options);

    if (!$split->get('status')) {
      $locallyDeactivated = $this->statusOverride->getSplitOverride($split->getName()) === FALSE;
      $form['activate_local_only'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Activate locally only'),
        '#description' => $this->t('If this is set, the split config will not be made active by default but instead it will be locally overwritten to be active.'),
        '#default_value' => !$locallyDeactivated,
      ];

      if ($locallyDeactivated) {
        $form['deactivation_notice'] = [
          '#type' => 'markup',
          '#markup' => $this->t('The local inactivation state override will be removed'),
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $split = $this->getSplit();
    $activate = !$split->get('status');
    if ($activate) {
      if ($form_state->getValue('activate_local_only')) {
        $this->statusOverride->setSplitOverride($split->getName(), TRUE);
        $activate = FALSE;
      }
      else {
        $this->statusOverride->setSplitOverride($split->getName(), NULL);
      }
    }

    $comparer = new StorageComparer($this->manager->singleImport($split, $activate), $this->activeStorage);
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
      ->andIf(AccessResult::allowedIf($split->get('status') || $split->get('storage') === 'collection'))
      ->addCacheableDependency($split);
  }

}
