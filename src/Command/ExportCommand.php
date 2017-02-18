<?php

namespace Drupal\config_split\Command;

use Drupal\Core\Config\FileStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Drupal\Console\Core\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Core\Style\DrupalStyle;

/**
 * Class ExportCommand.
 *
 * @package Drupal\config_split
 */
class ExportCommand extends Command {

  use ContainerAwareCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('config_split:export')
      ->setDescription($this->trans('commands.config_split.export.description'))
      ->addOption('directory', 'dir')
      ->addOption('split-directory', 'split-dir')
      ->addOption('split');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new DrupalStyle($input, $output);
    try {
      $directory = $input->getOption('directory');

      if (!$directory) {
        $directory = config_get_config_directory(CONFIG_SYNC_DIRECTORY);
      }

      // Here we could load the configuration according to the split name.
      // $split = $input->getOption('split');
      // But for now we load the settings.
      /** @var ImmutableConfig[] $configs */
      $configs =\Drupal::service('config_split.manager')->getActiveSplitConfig();

      $primary = new FileStorage($directory);
      $destinations = [
        'primary' => $directory,
      ];

      $storages = [];
      foreach ($configs as $key => $config) {
        $destinations[$key] = $config->get('folder');
      }
      $destinations = array_filter($destinations);

      $message = $this->trans('commands.config_split.export.messages.directories');
      $message .= "\n";
      $message .= implode("\n", $destinations);
      $message .= "\n";
      $message .= $this->trans('commands.config_split.export.messages.question');

      if ($io->confirm($message)) {
        \Drupal::service('config_split.cli')->export($configs, $primary, $storages);
        $io->success($this->trans('commands.config_split.export.messages.success'));
      }

    }
    catch (\Exception $e) {
      $io->error($e->getMessage());
    }

  }
}
