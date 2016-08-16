<?php

namespace Drupal\config_filter\Command;

use Drupal\config_filter\Config\SplitFilter;
use Drupal\config_filter\Config\StorageWrapper;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Drupal\Console\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Style\DrupalStyle;

class ExportCommand extends Command
{
  use ContainerAwareCommandTrait;
  /**
   * {@inheritdoc}
   */
  protected function configure()
  {
    $this
      ->setName('configfilter:export')
      ->setDescription($this->trans('commands.configextra.export.description'))
      ->addOption(
        'directory',
        null,
        InputOption::VALUE_OPTIONAL,
        $this->trans('commands.config.export.arguments.directory')
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {

    $io = new DrupalStyle($input, $output);
    $directory = $input->getOption('directory');

    if (!$directory) {
      $directory = config_get_config_directory(CONFIG_SYNC_DIRECTORY);
    }

    try {
      /** @var ConfigManagerInterface $configManager */
      $configManager = $this->getDrupalService('config.manager');
      /** @var ImmutableConfig $filterConfig */
      $filterConfig = \Drupal::config('config_filter.settings');

      $primary_storage = new FileStorage($directory);
      $secondary_storage = new FileStorage($filterConfig->get('folder'));
      $configFilter = new SplitFilter($filterConfig, $configManager, $secondary_storage);

      $export_storage = new StorageWrapper($primary_storage, $configFilter);
      // Remove everything in the storage and write it again.
      $export_storage->deleteAll();
      $secondary_storage->deleteAll();
      foreach ($configManager->getConfigFactory()->listAll() as $name) {
        // Get raw configuration data without overrides.
        $export_storage->write($name, $configManager->getConfigFactory()->get($name)->getRawData());
      }

    } catch (\Exception $e) {
      $io->error($e->getMessage());
    }

    $io->success($this->trans('commands.config.export.messages.directory'));
    $io->simple($directory);
  }
}
