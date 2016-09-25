<?php

namespace Drupal\config_split\Config;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Class SplitFilter.
 *
 * @package Drupal\config_split\Config
 */
class SplitFilter extends StorageFilterBase implements StorageFilterInterface {

  /**
   * The configuration for the filter.
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

  /**
   * SplitFilter constructor.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The filter config that has 'blacklist', 'module', and 'theme'.
   * @param \Drupal\Core\Config\ConfigManagerInterface $manager
   *   The config manager for retrieving dependent config.
   * @param \Drupal\Core\Config\StorageInterface|null $secondary
   *   The config storage for the blacklisted config.
   */
  public function __construct(Config $config, ConfigManagerInterface $manager, StorageInterface $secondary = NULL) {
    $this->config = $config;
    $this->manager = $manager;
    $this->secondaryStorage = $secondary;

    $blacklist = $config->get('blacklist');
    $modules = array_keys($config->get('module'));
    if ($modules) {
      $blacklist = array_merge($blacklist, array_keys($manager->findConfigEntityDependents('module', $modules)));
    }

    $themes = array_keys($config->get('theme'));
    if ($themes) {
      $blacklist = array_merge($blacklist, array_keys($manager->findConfigEntityDependents('theme', $themes)));
    }

    $extensions = array_merge([], $modules, $themes);
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
    $this->blacklist = array_unique(array_merge($blacklist, array_keys($manager->findConfigEntityDependents('config', $blacklist))));
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
      // Sort by module weight, this assumes the schema of core.extensions.
      if ($data['module'][$a] != $data['module'][$b]) {
        return $data['module'][$a] > $data['module'][$b] ? 1 : -1;
      }
      // Or sort by module name.
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
    elseif (in_array($name, $this->config->get('graylist'))) {
      if ($this->secondaryStorage) {
        $this->secondaryStorage->write($name, $data);
      }

      if ($storage) {
        return $storage->read($name);
      }

      return NULL;
    }
    else {
      if ($this->secondaryStorage && $this->secondaryStorage->exists($name)) {
        // If the secondary storage has the file but should not then delete it.
        $this->secondaryStorage->delete($name);
      }
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
  public function filterWriteEmptyIsDelete($name) {
    return $name != 'core.extension';
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
  public function filterDelete($name, $delete) {
    if ($delete && $this->secondaryStorage) {
      // Call delete on the secondary storage anyway.
      $this->secondaryStorage->delete($name);
    }

    return $delete;
  }

  /**
   * {@inheritdoc}
   */
  public function filterReadMultiple(array $names, array $data) {
    if ($this->secondaryStorage) {
      $data = array_merge($data, $this->secondaryStorage->readMultiple($names));
    }

    if (in_array('core.extension', $names)) {
      $data['core.extension'] = $this->filterRead('core.extension', $data['core.extension']);
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterListAll($prefix, array $data) {
    if ($this->secondaryStorage) {
      $data = array_unique(array_merge($data, $this->secondaryStorage->listAll($prefix)));
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterDeleteAll($prefix, $delete) {
    if ($delete && $this->secondaryStorage) {
      $this->secondaryStorage->deleteAll($prefix);
    }

    return $delete;
  }

  /**
   * {@inheritdoc}
   */
  public function filterCreateCollection($collection) {
    if ($this->secondaryStorage) {
      return new static($this->config, $this->manager, $this->secondaryStorage->createCollection($collection));
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function filterGetAllCollectionNames($collections) {
    if ($this->secondaryStorage) {
      $collections = array_merge($collections, $this->secondaryStorage->getAllCollectionNames());
    }

    return $collections;
  }

}
