<?php
/**
 * Created by PhpStorm.
 * User: fabian
 * Date: 16.08.16
 * Time: 08:44
 */

namespace Drupal\config_filter\Config;


use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;

class SplitFilter extends StorageFilterBase implements StorageFilterInterface {

  /**
   * The modules configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The Configuration manager to calculate the dependencies.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $manager;

  /**
   * The storage for the config which is not part of the directory to sync.
   * 
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $secondaryStorage;

  /**
   * Blacklist of configuration names.
   *
   * @var string[]
   */
  protected $blacklist;
  
  public function __construct(Config $config, ConfigManagerInterface $manager, StorageInterface $secondary = NULL) {
    $this->config = $config;
    $this->manager = $manager;
    $this->secondaryStorage = $secondary;

    $blacklist = $config->get('blacklist');
    if ($modules = array_keys($config->get('module'))) {
      $blacklist = array_merge($blacklist, array_keys($manager->findConfigEntityDependents('module', $modules)));
    }
    if ($themes = array_keys($config->get('theme'))) {
      $blacklist = array_merge($blacklist, array_keys($manager->findConfigEntityDependents('theme', $themes)));
    }
    $extensions = array_merge($modules, $themes);
    $blacklist = array_merge($blacklist, array_filter($manager->getConfigFactory()->listAll(), function ($name) use ($extensions) {
      // Filter the list of config objects since they are not included in
      // findConfigEntityDependents.
      foreach ($extensions as $extension) {
        if (strpos($name, $extension . '.') === 0) {
          return TRUE;
        }
      }
      return FALSE;
    }));
    // Finally merge all dependencies of the blacklisted config.
    $this->blacklist = array_merge($blacklist, array_keys($manager->findConfigEntityDependents('config', $blacklist)));
  }

  /**
   * {@inheritdoc}
   */
  public function filterRead($name, $data) {
    if ($this->secondaryStorage) {
      if ($alternative = $this->secondaryStorage->read($name)) {
        return $alternative;
      }
    }
    if ($name != 'core.extension') {
      return $data;
    }
    $data['module'] = array_merge($data['module'], $this->config->get('module'));
    $data['theme'] = array_merge($data['theme'], $this->config->get('theme'));
    // Sort the modules.
    uksort($data['module'], function ($a, $b) use ($data) {
      if ($data['module'][$a] != $data['module'][$b]) {
        return $data['module'][$a] > $data['module'][$b] ? 1 : -1;
      }
      return $a > $b ? 1 : -1;
    });
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterWrite($name, array $data, StorageInterface $storage = NULL) {
    if (in_array($name, $this->blacklist)) {
      if ($this->secondaryStorage) {
        $this->secondaryStorage->write($name, $data);
      }
      return NULL;
    }
    if ($name != 'core.extension') {
      return $data;
    }
    $data['module'] = array_diff_key($data['module'], $this->config->get('module'));
    $data['theme'] = array_diff_key($data['theme'], $this->config->get('theme'));
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterExists($name, $exists) {
    if (!$exists && $this->secondaryStorage) {
      $exists = $this->secondaryStorage->exists($name);
    }
    return $exists;
  }

  /**
   * {@inheritdoc}
   */
  public function filterDelete($name, $success) {
    if (!$success && $this->secondaryStorage) {
      $success = $this->secondaryStorage->delete($name);
    }
    return $success;
  }

  /**
   * {@inheritdoc}
   */
  public function filterListAll($data, $prefix = '') {
    if ($this->secondaryStorage) {
      $data = array_merge($data, $this->secondaryStorage->listAll($prefix));
    }
    return $data;
  }


}