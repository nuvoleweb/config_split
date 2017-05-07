<?php

namespace Drupal\config_split\Command;

use Drupal\config_split\Config\GhostStorage;
use Drupal\Core\Config\FileStorageFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Core\Style\DrupalStyle;

/**
 * Class ExportCommand.
 *
 * @package Drupal\config_split
 */
class ExportCommand extends SplitCommandBase {

  use ContainerAwareCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('config_split:export')
      ->setDescription($this->trans('commands.config_split.export.description'))
      ->addOption('split');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new DrupalStyle($input, $output);
    try {
      $config_name = NULL;
      $primary = NULL;
      $split = $input->getOption('split');

      if (!$split) {
        // Do a normal export through the cli service.
        $message = 'Do a normal (including filters) config export?';
      }
      else {
        $config_name = $this->getSplitName($split);

        $destination = \Drupal::config($config_name)->get('folder');

        // Set the primary to a GhostStorage so that we only export the split.
        $plugin_id = \Drupal::service('config_split.cli')->getPliginIdFromConfigName($config_name);
        $storage = \Drupal::service('config_filter.storage_factory')->getFilteredStorage(FileStorageFactory::getSync(), ['config.storage.sync'], [$plugin_id]);
        $primary = new GhostStorage($storage);

        $message = $this->trans('commands.config_split.export.messages.directories');
        $message .= "\n";
        $message .= $destination;
        $message .= "\n";
        $message .= $this->trans('commands.config_split.export.messages.question');
      }

      if ($io->confirm($message)) {
        \Drupal::service('config_split.cli')->export($config_name, $primary);
        $io->success($this->trans('commands.config_split.export.messages.success'));
      }

    }
    catch (\Exception $e) {
      $io->error($e->getMessage());
    }

  }

}
