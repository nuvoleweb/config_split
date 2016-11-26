<?php

namespace Drupal\config_split\Command;

use Drupal\config_split\ConfigSplitCliService;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Drupal\Console\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Style\DrupalStyle;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\FileStorage;

/**
 * Class ImportCommand.
 *
 * @package Drupal\config_split\Command
 */
class ImportCommand extends Command {

  use ContainerAwareCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('config_split:import')
      ->setDescription($this->trans('commands.config_split.import.description'))
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

      $cliService = \Drupal::service('config_split.cli');
      $state = $cliService->import($config, $primary, $secondary);

      switch ($state) {
        case ConfigSplitCliService::COMPLETE:
          $io->success($this->trans('commands.config.export.messages.directory'));
          $io->simple($directory);
          $io->simple($secondary->getFilePath('split'));
          $io->success($this->trans('commands.config.import.messages.imported'));
          break;

        case ConfigSplitCliService::NO_CHANGES:
          $io->success($this->trans('commands.config.import.messages.nothing-to-do'));
          break;

        case ConfigSplitCliService::ALREADY_IMPORTING:
          $io->success($this->trans('commands.config.import.messages.already-imported'));
          break;
      }
    }
    catch (ConfigImporterException $e) {
      $message = 'The import failed due for the following reasons:' . "\n";
      $message .= implode("\n", $cliService->getErrors());
      $io->error(
        sprintf(
          $this->trans('commands.site.import.local.messages.error-writing'),
          $message
        )
      );
    }
    catch (\Exception $e) {
      $io->error(
        sprintf(
          $this->trans('commands.site.import.local.messages.error-writing'),
          $e->getMessage()
        )
      );
    }

  }

}
