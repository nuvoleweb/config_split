<?php

namespace Drupal\config_split\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\ConfigFormBaseTrait;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ConfigSplitEntityForm.
 *
 * @package Drupal\config_split\Form
 */
class ConfigSplitEntityForm extends EntityForm {

  /**
   * Drupal\Core\Extension\ThemeHandler definition.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\config_split\Entity\ConfigSplitEntityInterface $config */
    $config = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $config->label(),
      '#description' => $this->t("Label for the Configuration Split Setting."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $config->id(),
      '#machine_name' => [
        'exists' => '\Drupal\config_split\Entity\ConfigSplitEntity::load',
      ],
      '#disabled' => !$config->isNew(),
    ];

    $form['folder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Folder'),
      '#description' => $this->t('The folder, relative to the Drupal root, to which to save the filtered config.<br/>Configuration related to the "filtered" items below will be split from the main configuration and exported to this folder by <code>drupal config_split:export</code>.'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('folder'),
    ];

    $module_handler = $this->moduleHandler;
    $modules = array_map(function ($module) use ($module_handler) {
      return $module_handler->getName($module->getName());
    }, $module_handler->getModuleList());
    $form['module'] = [
      '#type' => 'select',
      '#title' => $this->t('Modules'),
      '#description' => $this->t('Select modules to filter.'),
      '#options' => $modules,
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => array_keys($config->get('module')),
    ];

    // We should probably find a better way for this.
    $theme_handler = \Drupal::service('theme_handler');
    $themes = array_map(function ($theme) use ($theme_handler) {
      return $theme_handler->getName($theme->getName());
    }, $theme_handler->listInfo());
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Themes'),
      '#description' => $this->t('Select themes to filter.'),
      '#options' => $themes,
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => array_keys($config->get('theme')),
    ];
    // At this stage we do not support themes. @TODO: support themes.
    $form['theme']['#access'] = FALSE;

    $form['blacklist'] = [
      '#type' => 'select',
      '#title' => $this->t('Blacklist'),
      '#description' => $this->t('Select configuration to filter.'),
      '#options' => array_combine($this->configFactory()->listAll(), $this->configFactory()->listAll()),
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => $config->get('blacklist'),
    ];

    $form['graylist'] = [
      '#type' => 'select',
      '#title' => $this->t('Graylist'),
      '#description' => $this->t('Select configuration to ignore.'),
      '#options' => array_combine($this->configFactory()->listAll(), $this->configFactory()->listAll()),
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => $config->get('graylist'),
    ];

    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#description' => $this->t('The weight to order the splits.'),
      '#default_value' => $config->get('weight'),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#description' => $this->t('Active splits get used by default, this property can be overwritten like any other config entity in settings.php.'),
      '#default_value' => ($config->get('status') ? TRUE : FALSE),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Transform the values from the form to correctly save the entity.
    $extensions = $this->config('core.extension');
    $form_state->setValue('module', array_intersect_key($extensions->get('module'), $form_state->getValue('module')));
    $form_state->setValue('theme', array_intersect_key($extensions->get('theme'), $form_state->getValue('theme')));
    $form_state->setValue('blacklist', array_keys($form_state->getValue('blacklist')));
    $form_state->setValue('graylist', array_keys($form_state->getValue('graylist')));

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $config_split = $this->entity;
    $status = $config_split->save();

    switch ($status) {
      case SAVED_NEW:
        drupal_set_message($this->t('Created the %label Configuration Split Setting.', [
          '%label' => $config_split->label(),
        ]));
        break;

      default:
        drupal_set_message($this->t('Saved the %label Configuration Split Setting.', [
          '%label' => $config_split->label(),
        ]));
    }
    $form_state->setRedirectUrl($config_split->urlInfo('collection'));
  }

}
