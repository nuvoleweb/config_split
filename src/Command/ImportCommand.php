<?php

namespace Drupal\config_split\Command;

use Drupal\config_split\Config\SplitFilter;
use Drupal\config_split\Config\StorageWrapper;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Drupal\Console\Command\Shared\ContainerAwareCommandTrait;
use Drupal\Console\Style\DrupalStyle;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;

class ImportCommand extends Command
{
  use ContainerAwareCommandTrait;
  /**
   * {@inheritdoc}
   */
  protected function configure()
  {
    $this
      ->setName('config_split:import')
      ->setDescription($this->trans('commands.config_split.import.description'))
      ->addOption(
        'directory',
        null,
        InputOption::VALUE_OPTIONAL,
        $this->trans('commands.config.import.arguments.directory')
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


    /** @var ConfigManagerInterface $config_manager */
    $config_manager = $this->getDrupalService('config.manager');
    /** @var ImmutableConfig $filter_config */
    $filter_config = \Drupal::config('config_split.settings');

    $active_storage = \Drupal::service('config.storage');
    $source_storage = new FileStorage($directory);
    $secondary_storage = new FileStorage($filter_config->get('folder'));

    $config_split = new SplitFilter($filter_config, $config_manager, $secondary_storage);
    $import_storage = new StorageWrapper($source_storage, $config_split);

    $storage_comparer = new StorageComparer($import_storage, $active_storage, $config_manager);

    if (!$storage_comparer->createChangelist()->hasChanges()) {
      $io->success($this->trans('commands.config.import.messages.nothing-to-do'));

    }

    if ($this->configImport($io,$storage_comparer)) {
      $io->success($this->trans('commands.config.import.messages.imported'));

    }

  }


  private function configImport($io,StorageComparer $storage_comparer)
  {
    $config_importer = new ConfigImporter(
      $storage_comparer,
      \Drupal::service('event_dispatcher'),
      \Drupal::service('config.manager'),
      \Drupal::lock(),
      \Drupal::service('config.typed'),
      \Drupal::moduleHandler(),
      \Drupal::service('module_installer'),
      \Drupal::service('theme_handler'),
      \Drupal::service('string_translation')
    );

    if ($config_importer->alreadyImporting()) {
      $io->success($this->trans('commands.config.import.messages.already-imported'));


    }

    else{
      try {
        $config_importer->import();
        $io->info($this->trans('commands.config.import.messages.importing'));

      }
      catch (ConfigImporterException $e) {
        $message = 'The import failed due for the following reasons:' . "\n";
        $message .= implode("\n", $config_importer->getErrors());
        $io->error(
          sprintf(
            $this->trans('commands.site.import.local.messages.error-writing'),
            $message
          )
        );
      }

      catch (\Exception $e){
        $io->error(
          sprintf(
            $this->trans('commands.site.import.local.messages.error-writing'),
            $e->getMessage()
          )
        );

      }
    }
  }

}
