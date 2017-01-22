<?php

namespace Drupal\config_split\Config;

use Drupal\Core\Config\StorageInterface;

/**
 * Class StorageFilterBase.
 *
 * Pass everything along as it came in. This is a transparent filter.
 */
class StorageFilterBase implements StorageFilterInterface {

  /**
   * The storage on which the filter operations are performed.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $storage;

  /**
   * {@inheritdoc}
   */
  public function setStorage(StorageInterface $storage) {
    $this->storage = $storage;
  }

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
  public function filterWriteEmptyIsDelete($name) {
    return FALSE;
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
  public function filterDelete($name, $delete) {
    return $delete;
  }

  /**
   * {@inheritdoc}
   */
  public function filterReadMultiple(array $names, array $data) {
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterRename($name, $new_name, $rename) {
    return $rename;
  }

  /**
   * {@inheritdoc}
   */
  public function filterListAll($prefix, array $data) {
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function filterDeleteAll($prefix, $delete) {
    return $delete;
  }

  /**
   * {@inheritdoc}
   */
  public function filterCreateCollection($collection) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function filterGetAllCollectionNames($collections) {
    return $collections;
  }

  /**
   * {@inheritdoc}
   */
  public function filterGetCollectionName($collection) {
    return $collection;
  }

}
