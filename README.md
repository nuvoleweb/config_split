# Configuration split

This module provides a storage filter and uses it in Drupal Console and drush 
commands to filter configuration both in import and export.

The purpose of this is that one can store a site configuration in
<code>CONFIG_SYNC_DIRECTORY</code> and work with a superset of configuration
for development.
In other words one can have additional modules enabled and development
configuration exported to a separate directory; this items will be filtered out
of the configuration to be deployed.

The Drupal 8 configuration management works best when importing and exporting
the whole set of the sites configuration. However, sometimes developers like to
opt out of the robustness of CM and have a super-set of configuration active on
their development machine. The canonical example for this is to have the 
<code>devel</code> module enabled or having a few block placements or views in
the development environment and then not export them into the set of 
configuration to be deployed, yet still being able to share the development
configuration with colleagues.

Enter <code>config_split</code> that provides a Drupal console and drush
commands for importing and exporting filtered configuration. The native drush
commands for importing and exporting configuration already respect the filters.

The important part to remember is to use Drupal 8's configuration management
the way it was intended to be used. This module does not interfere with the
active configuration but instead filters on the import/export pipeline.
