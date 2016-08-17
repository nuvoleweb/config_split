<?php

namespace Drupal\config_filter\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SettingsConfigForm.
 *
 * @package Drupal\config_filter\Form
 */
class SettingsConfigForm extends ConfigFormBase {

  /**
   * Drupal\Core\Extension\ModuleHandler definition.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;
  /**
   * Drupal\Core\Extension\ThemeHandler definition.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * SplitFilterConfigForm constructor.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, ThemeHandlerInterface $theme_handler) {
    parent::__construct($config_factory);
    $this->moduleHandler = $module_handler;
    $this->themeHandler = $theme_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('theme_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'config_filter.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_filter_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('config_filter.settings');
    $form['folder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Folder'),
      '#description' => $this->t('The folder to which to save the filtered config'),
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

    $theme_handler = $this->themeHandler;
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
    $form['blacklist'] = [
      '#type' => 'select',
      '#title' => $this->t('Blacklist'),
      '#description' => $this->t('Select configuration to filter.'),
      '#options' => array_combine($this->configFactory()->listAll(), $this->configFactory()->listAll()),
      '#size' => 5,
      '#multiple' => TRUE,
      '#default_value' => $config->get('blacklist'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $extensions = $this->config('core.extension');
    $this->config('config_filter.settings')
      ->set('folder', $form_state->getValue('folder'))
      ->set('module', array_intersect_key($extensions->get('module'), $form_state->getValue('module')))
      ->set('theme', array_intersect_key($extensions->get('theme'), $form_state->getValue('theme')))
      ->set('blacklist', array_keys($form_state->getValue('blacklist')))
      ->save();
  }

}
