<?php

namespace Drupal\config_split\Command;

use Drupal\Core\Config\NullStorage;
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
        // Here we could call the default command...
        // $io->info("Consider using the native drush commands for exporting.");
        $message = 'Do a normal (including filters) config export?';
      }
      else {
        $config_name = $this->getSplitName($split);

        $destination = \Drupal::config($config_name)->get('folder');

        // Set the primary to the NullStorage so that we only export the split.
        $primary = new NullStorage();

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
