<?php

namespace Drupal\config_split\Command;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Drupal\Console\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Style\DrupalStyle;

/**
 * Class ExportCommand.
 *
 * @package Drupal\config_split\Command
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
      $splitDirectory = $input->getOption('split-directory');

      if (!$directory) {
        $directory = config_get_config_directory(CONFIG_SYNC_DIRECTORY);
      }

      // Here we could load the configuration according to the split name.
      // $split = $input->getOption('split');
      // But for now we load the settings.
      /** @var ImmutableConfig[] $config */
      $config = \Drupal::service('config_split.cli')->getAllConfig();

      $primary = new FileStorage($directory);
      $secondary = NULL;
      if ($splitDirectory) {
        $secondary = new FileStorage($splitDirectory);
      }

      \Drupal::service('config_split.cli')->export($config, $primary, $secondary);

      $io->success($this->trans('commands.config.export.messages.directory'));
      $io->simple($directory);
      $io->simple($secondary->getFilePath('split'));

    }
    catch (\Exception $e) {
      $io->error($e->getMessage());
    }
  }

}
