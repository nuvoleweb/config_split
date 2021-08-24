<?php

namespace Drupal\config_split\Config;

use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\TypedConfigManagerInterface;

/**
 * The config sorter service core should have had.
 *
 * @internal This is not an API, anything here might change without notice. Use config_normalizer 2.x instead.
 */
class ConfigSorter {

  /**
   * The typed config manager to get the schema from.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

  /**
   * ConfigCaster constructor.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager to look up the schema.
   */
  public function __construct(TypedConfigManagerInterface $typedConfigManager) {
    $this->typedConfigManager = $typedConfigManager;
  }

  /**
   * Cast and sort the config data in a normalised way depending on its schema.
   *
   * @param string $name
   *   The config name.
   * @param array $data
   *   The config data.
   *
   * @return array
   *   The cast and sorted data.
   */
  public function sort(string $name, array $data): array {
    // The sorter is an object extending from the core config class but doing
    // the casting and sorting only.
    // This is an anonymous class so that we are sure each object gets used only
    // once and nobody uses it for anything else. We extend the core class so
    // that we can access the methods and inherit the improvements made to it.
    $sorter = new class($this->typedConfigManager) extends StorableConfigBase {

      /**
       * Sort the config.
       *
       * @param string $name
       *   The config name.
       * @param array $data
       *   The data.
       *
       * @return array
       *   The sorted array.
       */
      public function anonymousSort(string $name, array $data): array {
        // Set the object up.
        self::validateName($name);
        $this->validateKeys($data);
        $this->setName($name)->initWithData($data);

        // This is essentially what \Drupal\Core\Config\Config::save does when
        // there is untrusted data before persisting it and dispatching events.
        if ($this->typedConfigManager->hasConfigSchema($this->name)) {
          // Once https://www.drupal.org/project/drupal/issues/2852557 is fixed
          // we do just: $this->data = $this->castValue(NULL, $this->data);.
          foreach ($this->data as $key => $value) {
            $this->data[$key] = $this->castValue($key, $value);
          }

          // Unfortunately the top level keys are not sorted yet.
          // This will be fixed too with issue #2852557.
          $schema = $this->getSchemaWrapper();
          if ($schema instanceof Mapping && count($this->data) > 1) {
            $mapping = $schema->getDataDefinition()['mapping'];
            // Only sort the keys in $sorted.
            $mapping = array_intersect_key($mapping, $this->data);
            // Sort the array in $sorted using the mapping definition.
            $this->data = array_replace($mapping, $this->data);
          }
        }
        else {
          foreach ($this->data as $key => $value) {
            $this->validateValue($key, $value);
          }
        }

        // This should now produce the same data as if the config object had
        // been saved and loaded. So we can return it.
        return $this->data;
      }

      /**
       * The constructor for passing the TypedConfigManager.
       *
       * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
       *   The taped config manager.
       */
      public function __construct(TypedConfigManagerInterface $typedConfigManager) {
        $this->typedConfigManager = $typedConfigManager;
      }

      /**
       * {@inheritdoc}
       */
      public function save($has_trusted_data = FALSE) {
        throw new \LogicException();
      }

      /**
       * {@inheritdoc}
       */
      public function delete() {
        throw new \LogicException();
      }

    };

    // Sort the data using the core class we extended.
    return $sorter->anonymousSort($name, $data);
  }

}
