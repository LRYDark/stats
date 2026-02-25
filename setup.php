<?php

define('PLUGIN_STATS_VERSION', '1.0.1');
define('PLUGIN_STATS_MIN_GLPI', '11.0.0');
define('PLUGIN_STATS_MAX_GLPI', '11.0.99');

function plugin_init_stats()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['stats'] = true;
    $PLUGIN_HOOKS['change_profile']['stats'] = [PluginStatsProfile::class, 'initProfile'];

    $plugin = new Plugin();
    if ($plugin->isInstalled('stats') && $plugin->isActivated('stats')) {
        Plugin::registerClass(
            PluginStatsProfile::class,
            ['addtabon' => Profile::class],
        );

        $PLUGIN_HOOKS['menu_toadd']['stats'] = ['tools' => PluginStatsMenu::class];
        $PLUGIN_HOOKS['menu_entries']['stats'] = true;
        $PLUGIN_HOOKS['submenu_entry']['stats']['search'] = 'front/stats.php';
    }
}

function plugin_version_stats()
{
    return [
        'name'         => __('Stats', 'stats'),
        'version'      => PLUGIN_STATS_VERSION,
        'author'       => 'REINERT Joris',
        'license'      => 'GPLv3',
        'homepage'     => 'https://github.com/pluginsGLPI/stats',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_STATS_MIN_GLPI,
                'max' => PLUGIN_STATS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_stats_check_prerequisites(){
    if (version_compare(GLPI_VERSION, PLUGIN_STATS_MIN_GLPI, '<')) {
        return false;
    }
    if (version_compare(GLPI_VERSION, PLUGIN_STATS_MAX_GLPI, '>=')) {
        return false;
    }
    return true;
}

function plugin_stats_check_config($verbose = false)
{
    return true;
}
