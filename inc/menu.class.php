<?php

class PluginStatsMenu extends CommonGLPI
{
    // Droit de secours pour les méthodes canXxx() héritées
    public static $rightname = PluginStatsProfile::RIGHTNAME_TICKETS;

    public static function getMenuName()
    {
        return __('Statistiques', 'stats');
    }

    /** Vrai si l'utilisateur a accès à au moins un onglet */
    public static function canView(): bool
    {
        return Session::haveRight(PluginStatsProfile::RIGHTNAME_CREDITS, PluginStatsProfile::RIGHT_READ)
            || Session::haveRight(PluginStatsProfile::RIGHTNAME_TICKETS, PluginStatsProfile::RIGHT_READ)
            || Session::haveRight(PluginStatsProfile::RIGHTNAME_SATISFACTION, PluginStatsProfile::RIGHT_READ);
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (self::canView()) {
            $menu['title']           = self::getMenuName();
            $menu['page']            = '/plugins/stats/front/stats.php';
            $menu['links']['search'] = '/plugins/stats/front/stats.php';
            $menu['icon']            = self::getIcon();
        }
        return $menu;
    }

    public static function getIcon()
    {
        return 'fa-solid fa-line-chart';
    }
}
