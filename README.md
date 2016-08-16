# Config filter

This module provides a storage filter and uses it in drupal console commands
to filter the imported and exported configuration.

The purpose of this is that one can a set of configuration in CONFIG_SYNC_DIRECTORY
and work with a superset of configuration for development.
In other words one can have additional modules enabled and development configuration
in a separate directory.
