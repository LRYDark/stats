<?php

class PluginStatsInstall
{
    public static function install(): bool
    {
        global $DB;

        // Chargement de tous les fichiers de classes inc/
        foreach (glob(__DIR__ . '/../inc/*.class.php') as $filepath) {
            if (preg_match("/inc.(.+)\\.class.php/", $filepath) !== 0) {
                include_once $filepath;
            }
        }

        // Table des filtres favoris (un enregistrement = un favori nomme, prive a un utilisateur).
        if (!$DB->tableExists('glpi_plugin_stats_filters')) {
            $charset   = method_exists($DB, 'getDefaultCharset') ? $DB->getDefaultCharset() : 'utf8mb4';
            $collation = method_exists($DB, 'getDefaultCollation') ? $DB->getDefaultCollation() : 'utf8mb4_unicode_ci';
            $query = "CREATE TABLE `glpi_plugin_stats_filters` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL,
                `view` varchar(50) NOT NULL DEFAULT 'tickets',
                `params` text NULL,
                `is_default` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `users_id` (`users_id`),
                KEY `view` (`view`),
                UNIQUE KEY `unicity` (`users_id`,`view`,`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";
            $DB->doQuery($query);
        }

        $migration = new Migration(PLUGIN_STATS_VERSION);

        // Droits (migration ancien droit unique → 3 droits par onglet)
        if (class_exists(PluginStatsProfile::class)) {
            PluginStatsProfile::install($migration);
        }

        $migration->executeMigration();

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        foreach (glob(__DIR__ . '/../inc/*.class.php') as $filepath) {
            if (preg_match("/inc.(.+)\\.class.php/", $filepath) !== 0) {
                include_once $filepath;
            }
        }

        if ($DB->tableExists('glpi_plugin_stats_filters')) {
            $DB->doQuery('DROP TABLE `glpi_plugin_stats_filters`');
        }

        $migration = new Migration(PLUGIN_STATS_VERSION);

        if (class_exists(PluginStatsProfile::class)) {
            PluginStatsProfile::removeRights();
        }

        $migration->executeMigration();

        return true;
    }
}
