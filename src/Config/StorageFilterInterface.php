<?php

/**
 * @file
 * Definition of Drush\Config\StorageFilter.
 */

namespace Drupal\config_filter\Config;

use Drupal\Core\Config\StorageInterface;

interface StorageFilterInterface {

  /**
   * Filters configuration data after it is read from storage.
   *
   * @param string $name
   *   The name of a configuration object to load.
   * @param array $data
   *   The configuration data to filter.
   *
   * @return array $data
   *   The filtered data.
   */
  public function filterRead($name, $data);

  /**
   * Filter configuration data before it is written to storage.
   *
   * @param string $name
   *   The name of a configuration object to save.
   * @param array $data
   *   The configuration data to filter.
   * @param StorageInterface
   *   (optional) The storage object that the filtered data will be
   *   written to.  Provided in case the filter needs to
   *   read the existing configuration before writing it.
   *
   * @return array $data
   *   The filtered data.
   */
  public function filterWrite($name, array $data, StorageInterface $storage = NULL);

  /**
   * Filters what listAll should return.
   *
   * @param array $data
   *   The data returned by the storage.
   * @param string $prefix
   *   (optional) The prefix to search for. If omitted, all configuration
   *   objects that exist will be deleted.
   *
   * @return array
   *   The filtered configuration set.
   */
  public function filterListAll($data, $prefix = '');

  /**
   * Filters whether a configuration object exists.
   *
   * @param string $name
   *   The name of a configuration object to test.
   * @param bool $exists
   *   The previous result to alter.
   *
   * @return bool
   *   TRUE if the configuration object exists, FALSE otherwise.
   */
  public function filterExists($name, $exists);

  /**
   * Deletes a configuration object from the storage.
   *
   * @param string $name
   *   The name of a configuration object to delete.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function filterDelete($name, $success);
}
