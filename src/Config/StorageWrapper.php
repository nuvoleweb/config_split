<?php

/**
 * @file
 * Definition of Drush\Config\StorageWrapper.
 */

namespace Drupal\config_filter\Config;

use Drupal\Core\Config\StorageInterface;

class StorageWrapper implements StorageInterface {

  /**
   * The storage container that we are wrapping.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $storage;

  /**
   * The storage filters.
   * 
   * @var \Drupal\config_filter\Config\StorageFilterInterface[]
   */
  protected $filters;

  /**
   * Create a StorageWrapper with some storage and a filter.
   */
  function __construct($storage, $filterOrFilters) {
    $this->storage = $storage;
    $this->filters = is_array($filterOrFilters) ? $filterOrFilters : array($filterOrFilters);
  }

  /**
   * {@inheritdoc}
   */
  public function exists($name) {
    $exists = $this->storage->exists($name);
    foreach ($this->filters as $filter) {
      $exists = $filter->filterExists($name, $exists);
    }
    return $exists;
  }

  /**
   * {@inheritdoc}
   */
  public function read($name) {
    $data = $this->storage->read($name);

    foreach ($this->filters as $filter) {
      $data = $filter->filterRead($name, $data);
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function readMultiple(array $names) {
    $dataList = $this->storage->readMultiple($names);
    $result = [];

    foreach ($names as $name) {
      $data = isset($dataList[$name]) ? $dataList[$name] : NULL;
      foreach ($this->filters as $filter) {
        $data = $filter->filterRead($name, $data);
      }
      if ($data) {
        $result[$name] = $data;
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function write($name, array $data) {
    foreach ($this->filters as $filter) {
      $data = $filter->filterWrite($name, $data, $this->storage);
    }
    if ($data) {
      return $this->storage->write($name, $data);
    }
    return $this->storage->delete($name);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($name) {
    $success = $this->storage->delete($name);
    foreach ($this->filters as $filter) {
      $success = $filter->filterDelete($name, $success);
    }
    return $success;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($name, $new_name) {
    return $this->storage->rename($name, $new_name);
  }

  /**
   * {@inheritdoc}
   */
  public function encode($data) {
    return $this->storage->encode($data);
  }

  /**
   * {@inheritdoc}
   */
  public function decode($raw) {
    return $this->storage->decode($raw);
  }

  /**
   * {@inheritdoc}
   */
  public function listAll($prefix = '') {
    $data = $this->storage->listAll($prefix);
    foreach ($this->filters as $filter) {
      $data = $filter->filterListAll($data, $prefix);
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll($prefix = '') {
    return $this->storage->deleteAll($prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function createCollection($collection) {
    return $this->storage->createCollection($collection);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllCollectionNames() {
    return $this->storage->getAllCollectionNames();
  }

  /**
   * {@inheritdoc}
   */
  public function getCollectionName() {
    return $this->storage->getCollectionName();
  }

}
