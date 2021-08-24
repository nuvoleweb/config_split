<?php

namespace Drupal\config_split\Config;

/**
 * The config patch value object.
 *
 * @internal This is not an API, anything here might change without notice. Use config_merge 2.x instead.
 */
class ConfigPatch {

  /**
   * The added elements.
   *
   * @var array
   */
  protected $added;

  /**
   * The removed elements.
   *
   * @var array
   */
  protected $removed;

  /**
   * Create the object.
   *
   * @param array $added
   *   The elements added.
   * @param array $removed
   *   The elements removed.
   */
  public function __construct(array $added, array $removed) {
    $this->added = $added;
    $this->removed = $removed;
  }

  /**
   * Get the added elements.
   *
   * @return array
   *   The added elements.
   */
  public function getAdded(): array {
    return $this->added;
  }

  /**
   * Get the removed elements.
   *
   * @return array
   *   The removed elements.
   */
  public function getRemoved(): array {
    return $this->removed;
  }

  /**
   * Create an object from an array.
   *
   * @param array $patch
   *   The array containing added and removed keys.
   *
   * @return static
   *   The patch object.
   */
  public static function fromArray(array $patch): self {
    if (!isset($patch['added'], $patch['removed'])) {
      throw new \InvalidArgumentException(sprintf('The array passed to %s must contain the keys "added" and "removed", it contains %s', __METHOD__, implode(', ', array_keys($patch))));
    }

    return new self($patch['added'], $patch['removed']);
  }

  /**
   * Invert the patch.
   *
   * @return self
   *   A new patch object with inverted components.
   */
  public function invert(): self {
    return new self($this->removed, $this->added);
  }

  /**
   * Transform it to an array for persisting the patch.
   *
   * @return array
   *   The data.
   */
  public function toArray(): array {
    return [
      'added' => $this->added,
      'removed' => $this->removed,
    ];
  }

  /**
   * Check if the patch is empty.
   *
   * @return bool
   *   The patches' emptiness.
   */
  public function isEmpty(): bool {
    return empty($this->added) && empty($this->removed);
  }

}
