<?php

class PluginStatsInstall
{
    public static function install(): bool
    {
        foreach (glob(__DIR__ . '/../inc/*.class.php') as $filepath) {
            if (preg_match("/inc.(.+)\\.class.php/", $filepath) !== 0) {
                include_once $filepath;
            }
        }

        $migration = new Migration(PLUGIN_STATS_VERSION);
        if (class_exists(PluginStatsProfile::class)) {
            PluginStatsProfile::install($migration);
        }
        $migration->executeMigration();

        return true;
    }

    public static function uninstall(): bool
    {
        foreach (glob(__DIR__ . '/../inc/*.class.php') as $filepath) {
            if (preg_match("/inc.(.+)\\.class.php/", $filepath) !== 0) {
                include_once $filepath;
            }
        }

        $migration = new Migration(PLUGIN_STATS_VERSION);
        if (class_exists(PluginStatsProfile::class)) {
            PluginStatsProfile::removeRights();
        }
        $migration->executeMigration();

        return true;
    }
}
