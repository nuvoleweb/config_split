<?php

namespace Drupal\config_split\Command;

use Drupal\config_split\ConfigSplitCliService;
use Drupal\Core\Config\ConfigImporterException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Core\Style\DrupalStyle;

/**
 * Class ImportCommand.
 *
 * @package Drupal\config_split
 */
class ImportCommand extends SplitCommandBase {

  use ContainerAwareCommandTrait;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('config_split:import')
      ->setDescription($this->trans('commands.config_split.import.description'))
      ->addOption('split');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new DrupalStyle($input, $output);
    $cliService = \Drupal::service('config_split.cli');
    try {
      $config_name = NULL;
      $primary = NULL;
      $split = $input->getOption('split');

      if (!$split) {
        // Do a normal import through the cli service.
        $message = 'Do a normal (including filters) config import?';
      }
      else {
        $config_name = $this->getSplitName($split);
        $destination = \Drupal::config($config_name)->get('folder');

        // Set the primary to the active storage so we only import the split.
        $primary = \Drupal::getContainer()->get('config.storage');

        $message = $this->trans('commands.config_split.import.messages.directories');
        $message .= "\n";
        $message .= $destination;
        $message .= "\n";
        $message .= $this->trans('commands.config_split.import.messages.question');
      }

      if ($io->confirm($message)) {
        $state = $cliService->import($config_name, $primary);

        switch ($state) {
          case ConfigSplitCliService::COMPLETE:
            $io->success($this->trans('commands.config_split.import.messages.success'));
            break;

          case ConfigSplitCliService::NO_CHANGES:
            $io->info($this->trans('commands.config_split.import.messages.nothing-to-do'));
            break;

          case ConfigSplitCliService::ALREADY_IMPORTING:
            $io->warning($this->trans('commands.config_split.import.messages.already-imported'));
            break;

          default:
            $io->warning("Something unexpected happened");
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
