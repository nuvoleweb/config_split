<?php

namespace Drupal\config_split\Command;

use Drupal\config_split\ConfigSplitCliService;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\FileStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Drupal\Console\Core\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Core\Style\DrupalStyle;

/**
 * Class ImportCommand.
 *
 * @package Drupal\config_split
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

      if (!$directory) {
        $directory = config_get_config_directory(CONFIG_SYNC_DIRECTORY);
      }

      // Here we could load the configuration according to the split name.
      // $split = $input->getOption('split');
      // But for now we load the settings.
      /** @var ImmutableConfig[] $config */
      $configs = \Drupal::service('config_split.manager')->getActiveSplitConfig();

      $primary = new FileStorage($directory);
      $destinations = [
        'primary' => $directory,
      ];

      $storages = [];
      foreach ($configs as $key => $config) {
        $destinations[$key] = $config->get('folder');
      }
      $destinations = array_filter($destinations);


      $message = $this->trans('commands.config_split.import.messages.directories');
      $message .= "\n";
      $message .= implode("\n", $destinations);
      $message .= "\n";
      $message .= $this->trans('commands.config_split.import.messages.question');

      if ($io->confirm($message)) {
        $cliService = \Drupal::service('config_split.cli');
        $state = $cliService->import($configs, $primary, $storages);

        switch ($state) {
          case ConfigSplitCliService::COMPLETE:
            $io->success($this->trans('commands.config_split.import.messages.success'));
            break;

          case ConfigSplitCliService::NO_CHANGES:
            $io->success($this->trans('commands.config_split.import.messages.nothing-to-do'));
            break;

          case ConfigSplitCliService::ALREADY_IMPORTING:
            $io->success($this->trans('commands.config_split.import.messages.already-imported'));
            break;
        }

      }
    }
    catch (ConfigImporterException $e) {
      $io->error(
        sprintf(
          $this->trans('commands.config_split.import.messages.error-writing'),
          implode("\n", $cliService->getErrors())
        )
      );
    }
    catch (\Exception $e) {
      $io->error(
        sprintf(
          $this->trans('commands.config_split.import.messages.error-writing'),
          $e->getMessage()
        )
      );
    }
  }
}
