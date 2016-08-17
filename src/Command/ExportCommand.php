<?php

namespace Drupal\config_filter\Command;

use Drupal\config_filter\Config\SplitFilter;
use Drupal\config_filter\Config\StorageWrapper;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\StorageInterface;
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
    /** @var ConfigManagerInterface $configManager */
    $configManager = $this->getDrupalService('config.manager');
    /** @var ImmutableConfig $filterConfig */
    $filterConfig = \Drupal::config('config_filter.settings');

    try {
      $primary_storage = new FileStorage($directory);
      $secondary_storage = new FileStorage($filterConfig->get('folder'));
      $configFilter = new SplitFilter($filterConfig, $configManager, $secondary_storage);

      /** @var StorageInterface $source_storage */
      $source_storage = \Drupal::service('config.storage');
      $destination_storage = new StorageWrapper($primary_storage, $configFilter);
      // Remove everything in the storage and write it again.
      $destination_storage->deleteAll();
      $secondary_storage->deleteAll();
      foreach ($source_storage->listAll() as $name) {
        $destination_storage->write($name, $source_storage->read($name));
      }

      // Export configuration collections.
      foreach (\Drupal::service('config.storage')->getAllCollectionNames() as $collection) {
        $collection_source_storage = $source_storage->createCollection($collection);
        $collection_destination_storage = $destination_storage->createCollection($collection);
        try {
          $collection_destination_storage->deleteAll();
        }
        catch (\UnexpectedValueException $e) {
          // The folder doesn't exist yet.
        }
        foreach ($collection_source_storage->listAll() as $name) {
          $collection_destination_storage->write($name, $collection_source_storage->read($name));
        }
      }

    } catch (\Exception $e) {
      $io->error($e->getMessage());
    }

    $io->success($this->trans('commands.config.export.messages.directory'));
    $io->simple($directory);
    $io->simple($filterConfig->get('folder'));
  }
}
