<?php

namespace Drupal\config_split\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\FileStorage;

/**
 * Defines the Configuration Split Setting entity.
 *
 * @ConfigEntityType(
 *   id = "config_split",
 *   label = @Translation("Configuration Split Setting"),
 *   handlers = {
 *     "list_builder" = "Drupal\config_split\ConfigSplitEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\config_split\Form\ConfigSplitEntityForm",
 *       "edit" = "Drupal\config_split\Form\ConfigSplitEntityForm",
 *       "delete" = "Drupal\config_split\Form\ConfigSplitEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\config_split\ConfigSplitEntityHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "config_split",
 *   admin_permission = "administer configuration split",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/admin/config/development/configuration/config-split/{config_split}",
 *     "add-form" = "/admin/config/development/configuration/config-split/add",
 *     "edit-form" = "/admin/config/development/configuration/config-split/{config_split}/edit",
 *     "delete-form" = "/admin/config/development/configuration/config-split/{config_split}/delete",
 *     "collection" = "/admin/config/development/configuration/config-split"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "folder",
 *     "module",
 *     "theme",
 *     "blacklist",
 *     "graylist",
 *     "weight",
 *     "status",
 *   }
 * )
 */
class ConfigSplitEntity extends ConfigEntityBase implements ConfigSplitEntityInterface {

  /**
   * The Configuration Split Setting ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Configuration Split Setting label.
   *
   * @var string
   */
  protected $label;

  /**
   * The folder to export to.
   *
   * @var string
   */
  protected $folder = '';

  /**
   * The modules to split.
   *
   * @var array
   */
  protected $module = [];

  /**
   * The themes to split.
   *
   * @var array
   */
  protected $theme = [];

  /**
   * The explicit configuration to filter out.
   *
   * @var string[]
   */
  protected $blacklist = [];

  /**
   * The configuration to ignore.
   *
   * @var string[]
   */
  protected $graylist = [];

  /**
   * The weight of the configuration when spliting several folders.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * The status, whether to be used by default.
   */
  protected $status = TRUE;

}
