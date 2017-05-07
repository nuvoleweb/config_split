<?php

namespace Drupal\config_split\Form;

use Drupal\Core\Entity\EntityForm;
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
    ];

    $form['static_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Static Settings'),
      '#description' => $this->t("These settings need a cache clear when overridden in settings.php and the split needs to be single imported before the config import for new values to take effect."),
    ];
    $form['static_fieldset']['folder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Folder'),
      '#description' => $this->t('The directory, relative to the Drupal root, to which to save the filtered config. Recommended is a sibbling directory of what you defined in <code>$config_directories[CONFIG_SYNC_DIRECTORY]</code> in settings.php<br/>Configuration related to the "filtered" items below will be split from the main configuration and exported to this folder.<br/>Leave the folder empty to use a special database storage if you do not want to share the configuration.'),
      '#default_value' => $config->get('folder'),
    ];
    $form['static_fieldset']['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#description' => $this->t('The weight to order the splits.'),
      '#default_value' => $config->get('weight'),
    ];
    $form['static_fieldset']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#description' => $this->t('Active splits get used by default, this property can be overwritten like any other config entity in settings.php.'),
      '#default_value' => ($config->get('status') ? TRUE : FALSE),
    ];

    $form['blacklist_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Blacklist'),
      '#description' => $this->t("Configuration listed here will be removed from the sync directory and saved in the split directory instead.<br/>Use this part for configuration that should be completely excluded from the normal sync directory."),
    ];

    $module_handler = $this->moduleHandler;
    $modules = array_map(function ($module) use ($module_handler) {
      return $module_handler->getName($module->getName());
    }, $module_handler->getModuleList());
    // Add the existing ones with the machine name so they do not get lost.
    $modules = $modules + array_combine(array_keys($config->get('module')), array_keys($config->get('module')));
    $form['blacklist_fieldset']['module'] = [
      '#type' => 'select',
      '#title' => $this->t('Modules'),
      '#description' => $this->t('Select modules to filter. Configuration depending on the modules is automatically blacklisted too.'),
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
    $form['blacklist_fieldset']['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Themes'),
      '#description' => $this->t('Select themes to filter.'),
      '#options' => $themes,
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => array_keys($config->get('theme')),
    ];
    // At this stage we do not support themes. @TODO: support themes.
    $form['blacklist_fieldset']['theme']['#access'] = FALSE;

    $options = array_combine($this->configFactory()->listAll(), $this->configFactory()->listAll());
    $form['blacklist_fieldset']['blacklist_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Blacklist'),
      '#description' => $this->t('Select configuration to filter.'),
      '#options' => $options,
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => array_intersect($config->get('blacklist'), array_keys($options)),
    ];
    $form['blacklist_fieldset']['blacklist_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blacklist'),
      '#description' => $this->t('Select additional configuration to filter. One configuration key per line.'),
      '#size' => 5,
      '#default_value' => implode("\n", array_diff($config->get('blacklist'), array_keys($options))),
    ];

    $form['graylist_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Graylist*'),
      '#description' => $this->t("Configuration listed here will not be removed from the sync directory, instead a copy with the currently active settings will be stored in the split directory.<br>If the configuration does not exist in the sync storage the behavior is the same as the blacklist.<br/>Use this part for configuration that is different on your site but which should remain part of the main sync directory."),
    ];
    $form['graylist_fieldset']['#description'] .= $this->t('<br/>* Suggest a better name for "graylist" in the <a href="https://www.drupal.org/node/2865280">issue on drupal.org.</a>');

    $form['graylist_fieldset']['graylist_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Graylist'),
      '#description' => $this->t('Select configuration to ignore.'),
      '#options' => $options,
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => array_intersect($config->get('graylist'), array_keys($options)),
    ];
    $form['graylist_fieldset']['graylist_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Graylist'),
      '#description' => $this->t('Select additional configuration to ignore. One configuration key per line.'),
      '#size' => 5,
      '#default_value' => implode("\n", array_diff($config->get('graylist'), array_keys($options))),
    ];

    $form['graylist_fieldset']['graylist_dependents'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include dependent configuration for graylist'),
      '#description' => $this->t('If this is set, graylisted configuration will also include configuration that depend on it.'),
      '#default_value' => ($config->get('graylist_dependents') ? TRUE : FALSE),
    ];

    $form['graylist_fieldset']['graylist_skip_equal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Split graylist only when different'),
      '#description' => $this->t('If this is set, graylisted configuration will not be in the split directory if it is equal to the one in the main sync directory.'),
      '#default_value' => ($config->get('graylist_skip_equal') ? TRUE : FALSE),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Transform the values from the form to correctly save the entity.
    $extensions = $this->config('core.extension');
    // Add the configs modules so we can save inactive splits.
    $module_list = $extensions->get('module') + $this->entity->get('module');
    $form_state->setValue('module', array_intersect_key($module_list, $form_state->getValue('module')));
    $form_state->setValue('theme', array_intersect_key($extensions->get('theme'), $form_state->getValue('theme')));
    $form_state->setValue('blacklist', array_merge(
      array_keys($form_state->getValue('blacklist_select')),
      $this->filterConfigNames($form_state->getValue('blacklist_text'))
    ));
    $form_state->setValue('graylist', array_merge(
      array_keys($form_state->getValue('graylist_select')),
      $this->filterConfigNames($form_state->getValue('graylist_text'))
    ));

    parent::submitForm($form, $form_state);
  }

  /**
   * Filter text input for valid configuration names (including wildcards).
   *
   * @param string|string[] $text
   *   The configuration names, one name per line.
   *
   * @return string[]
   *   The array of configuration names.
   */
  protected function filterConfigNames($text) {
    if (!is_array($text)) {
      $text = explode("\n", $text);
    }
    // Filter out illegal characters.
    return array_filter(preg_replace('/[^a-z0-9\._\*]+/', '', $text));
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
    $form_state->setRedirectUrl($config_split->toUrl('collection'));
  }

}
