<?php

/**
 * @file
 * This file contains post update hooks.
 */

declare(strict_types=1);

/**
 * Change the config schema for split entities.
 */
function config_split_post_update_schema_change(&$sandbox) {
  $configFactory = \Drupal::configFactory();
  foreach ($configFactory->listAll('config_split.config_split') as $name) {
    $split = $configFactory->getEditable($name);
    $data = $split->getRawData();

    $storage = 'folder';
    if ($data['folder'] === '') {
      $storage = 'database';
    }
    $key = array_search('folder', array_keys($data), TRUE);
    $data = array_slice($data, 0, $key, TRUE) +
      ['storage' => $storage] +
      array_slice($data, $key, NULL, TRUE);

    foreach (['black' => 'complete', 'gray' => 'partial'] as $list => $new) {
      $list .= 'list';
      $new .= '_list';
      $data[$new] = $data[$list] ?? [];
      unset($data[$list]);
      unset($data[$list . '_dependents']);
      unset($data[$list . '_skip_equal']);
    }
    $split->setData($data);
    $split->save();
  }
}
