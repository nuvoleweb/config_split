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
   * Graylist of configuration names.
   *
   * @var string[]
   */
  protected $graylist;

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
    $blacklist = array_filter($manager->getConfigFactory()->listAll(), function ($name) use ($extensions, $blacklist) {
      // Filter the list of config objects since they are not included in
      // findConfigEntityDependents.
      foreach ($extensions as $extension) {
        if (strpos($name, $extension . '.') === 0) {
          return TRUE;
        }
      }

      // Add the config name to the blacklist if it is in the wildcard list.
      return self::inFilterList($name, $blacklist);
    });
    sort($blacklist);
    // Finally merge all dependencies of the blacklisted config.
    $this->blacklist = array_unique(array_merge($blacklist, array_keys($manager->findConfigEntityDependents('config', $blacklist))));

    $graylist = $config->get('graylist');
    $graylist = array_filter($manager->getConfigFactory()->listAll(), function ($name) use ($graylist) {
      // Add the config name to the graylist if it is in the wildcard list.
      return self::inFilterList($name, $graylist);
    });
    sort($graylist);

    if ($config->get('graylist_dependents')) {
      // Find dependent configuration and add it to the list.
      $graylist = array_unique(array_merge($graylist, array_keys($manager->findConfigEntityDependents('config', $graylist))));
    }

    $this->graylist = $graylist;
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

    $modules = $this->config->get('module');
    $themes = $this->config->get('theme');

    if ($this->wrapped) {
      // When filtering the 'read' operation, we are about to import the sync
      // configuration. The configuration of the filter is the active config,
      // but we are about to decide which modules should be enabled in addition
      // to the ones defined in the primary storages 'core.extension'.
      // So we need to read the configuration as it will be imported, as the
      // filter configuration could be split off itself.
      $updated = $this->wrapped->read($this->config->getName());
      $modules = $updated['module'];
      $themes = $updated['theme'];
    }

    $data['module'] = array_merge($data['module'], $modules);
    $data['theme'] = array_merge($data['theme'], $themes);
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
  public function filterWrite($name, array $data) {
    if (in_array($name, $this->blacklist)) {
      if ($this->secondaryStorage) {
        $this->secondaryStorage->write($name, $data);
      }

      return NULL;
    }
    elseif (in_array($name, $this->graylist)) {
      if (!$this->config->get('graylist_skip_equal') || !$this->source || $this->source->read($name) != $data) {
        // The configuration is in the graylist but skip-equal is not set or
        // the source does not have the same data, so write to secondary and
        // return source data or null if it doesn't exist in the source.
        if ($this->secondaryStorage) {
          $this->secondaryStorage->write($name, $data);
        }

        // If the source has it, return that so it doesn't get changed.
        if ($this->source) {
          return $this->source->read($name);
        }

        return NULL;
      }
    }

    if ($this->secondaryStorage && $this->secondaryStorage->exists($name)) {
      // If the secondary storage has the file but should not then delete it.
      $this->secondaryStorage->delete($name);
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

  /**
   * Check whether the needle is in the haystack.
   *
   * @param $name
   *   The needle which is checked.
   * @param $list
   *   The haystack, a list of identifiers to determine whether $name is in it.
   *
   * @return bool
   *   True if the name is considered to be in the list.
   */
  protected static function inFilterList($name, $list) {
    // Prepare the list for regex matching by qoting all regex symbols and
    // replacing back the original '*' with '.*' to allow it to catch all.
    $list = array_map(function ($line) {
      return str_replace('\*', '.*', preg_quote($line, '/'));
    }, $list);
    foreach ($list as $line) {
      if (preg_match('/^' . $line . '$/', $name)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
