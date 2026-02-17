<?php

class PluginStatsMenu extends CommonGLPI
{
    public static $rightname = PluginStatsProfile::RIGHTNAME;

    public static function getMenuName()
    {
        return __('Statistiques', 'stats');
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (Session::haveRight(self::$rightname, PluginStatsProfile::RIGHT_READ)) {
            $menu['title'] = self::getMenuName();
            $menu['page'] = '/plugins/stats/front/stats.php';
            $menu['links']['search'] = '/plugins/stats/front/stats.php';
            $menu['icon'] = self::getIcon();
        }
        return $menu;
    }

    static function getIcon() {
        return "fa-solid fa-line-chart";
    }
}
