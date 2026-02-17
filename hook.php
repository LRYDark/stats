<?php

function plugin_stats_install()
{
    include_once __DIR__ . '/sql/install.php';
    return PluginStatsInstall::install();
}

function plugin_stats_uninstall()
{
    include_once __DIR__ . '/sql/uninstall.php';
    return PluginStatsInstall::uninstall();
}
