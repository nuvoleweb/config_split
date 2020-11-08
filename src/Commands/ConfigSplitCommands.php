<?php

namespace Drupal\config_split\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\config_split\ConfigSplitCliService;
use Drush\Commands\DrushCommands;

/**
 * The Drush 10 commands.
 */
class ConfigSplitCommands extends DrushCommands {

  /**
   * The interoperability cli service.
   *
   * @var \Drupal\config_split\ConfigSplitCliService
   */
  protected $cliService;

  /**
   * ConfigSplitCommands constructor.
   *
   * @param \Drupal\config_split\ConfigSplitCliService $cliService
   *   The CLI service which allows interoperability.
   */
  public function __construct(ConfigSplitCliService $cliService) {
    parent::__construct();
    $this->cliService = $cliService;
  }

  /**
   * Export only split configuration to a directory.
   *
   * @param string $split
   *   The split configuration to export.
   *
   * @command config-split:export
   *
   * @usage drush config-split:export development
   *   Export configuration of the "development" split
   *
   * Propose and alias at:
   *   https://www.drupal.org/project/config_split/issues/3181368
   */
  public function splitExport($split) {
    $this->cliService->ioExport($split, $this->io(), 'dt');
  }

  /**
   * Import only config from a split.
   *
   * @param string $split
   *   The split configuration to import.
   *
   * @command config-split:import
   *
   * @usage drush config-split:import development
   *   Import configuration of the "development" split
   *
   * Propose and alias at:
   *   https://www.drupal.org/project/config_split/issues/3181368
   */
  public function splitImport($split) {
    $this->cliService->ioImport($split, $this->io(), 'dt');
  }

  /**
   * React to the config export, write the splits to their storages.
   *
   * @hook post-command config:export
   */
  public function postConfigExport($result, CommandData $commandData) {
    // The config export command aborts if it is not exporting.
    // So here we know that the config was exported, so we need to also export
    // the split config to where they need to be.
    $this->cliService->postExportAll();
  }

}
