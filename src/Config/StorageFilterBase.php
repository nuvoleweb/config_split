<?php

namespace Drupal\config_filter\Config;


use Drupal\Core\Config\StorageInterface;

/**
 * Class StorageFilterBase
 * Pass Everything along as it came in.
 */
class StorageFilterBase implements StorageFilterInterface{

  /**
   * {@inheritdoc}
   */
  public function filterRead($name, $data) {
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterWrite($name, array $data, StorageInterface $storage = NULL) {
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterListAll($data, $prefix = '') {
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterExists($name, $exists) {
    return $exists;
  }

  /**
   * {@inheritdoc}
   */
  public function filterDelete($name, $success) {
    return $success;
  }
}