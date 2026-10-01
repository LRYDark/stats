<?php

class PluginStatsMenu extends CommonGLPI
{
    // Droit de secours pour les méthodes canXxx() héritées
    public static $rightname = PluginStatsProfile::RIGHTNAME_TICKETS;

    public static function getMenuName()
    {
        return __('Statistiques', 'stats');
    }

    /**
     * Vrai si l'utilisateur a accès à au moins un onglet.
     *
     * Parcourt la liste des droits plutôt que de les énumérer : un onglet ajouté
     * sans être repris ici serait accessible par son URL mais introuvable dans le
     * menu.
     */
    public static function canView(): bool
    {
        foreach (PluginStatsProfile::getRightNames() as $right) {
            if (Session::haveRight($right, PluginStatsProfile::RIGHT_READ)) {
                return true;
            }
        }
        return false;
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

    /**
     * Onglets de la page Statistiques : lesquels peuvent exister (plugin source
     * présent) et lesquels l'utilisateur peut ouvrir.
     *
     * Calcul UNIQUE, partagé par front/stats.php et par la page de la liste
     * « Rapports hotline à générer » (front/hotlineticket.php), qui dessinent
     * la même barre d'onglets : deux copies auraient fini par diverger.
     *
     * Un onglet n'existe que si son plugin source est installé, actif, ET que
     * sa table est bien là. Un plugin désinstallé laisse parfois ses fichiers
     * derrière lui, mais jamais ses tables.
     *
     * @return array{enabled: array<string, bool>, access: array<string, bool>}
     *         dans l'ordre d'affichage des onglets
     */
    public static function getViews(): array
    {
        global $DB;

        static $views = null;
        if ($views !== null) {
            return $views;
        }

        $plugin = new Plugin();

        // Crédits : plugins credit + creditalert, et configuration de creditalert chargée.
        $creditPluginActive = $plugin->isInstalled('credit') && $plugin->isActivated('credit');
        $creditAlertActive  = $plugin->isInstalled('creditalert') && $plugin->isActivated('creditalert');
        $creditConfigLoaded = false;
        if ($creditAlertActive) {
            if (!class_exists('PluginCreditalertConfig')) {
                $creditalertConfig = GLPI_ROOT . '/plugins/creditalert/inc/config.class.php';
                if (file_exists($creditalertConfig)) {
                    include_once $creditalertConfig;
                }
            }
            $creditConfigLoaded = class_exists('PluginCreditalertConfig');
        }
        $creditEnabled = $creditPluginActive && $creditAlertActive && $creditConfigLoaded;
        if ($creditEnabled) {
            PluginCreditalertConfig::ensureViews();
        }

        // Satisfaction : plugin satisfactionclient, sa classe de questions et sa table.
        $satisfactionPluginActive    = $plugin->isInstalled('satisfactionclient') && $plugin->isActivated('satisfactionclient');
        $satisfactionQuestionsLoaded = false;
        if ($satisfactionPluginActive) {
            if (!class_exists('PluginSatisfactionclientQuestion')) {
                $questionClass = GLPI_ROOT . '/plugins/satisfactionclient/inc/question.class.php';
                if (file_exists($questionClass)) {
                    include_once $questionClass;
                }
            }
            $satisfactionQuestionsLoaded = class_exists('PluginSatisfactionclientQuestion');
        }

        $enabled = [
            'credits'      => $creditEnabled,
            'tickets'      => true,
            'satisfaction' => $satisfactionPluginActive
                && $satisfactionQuestionsLoaded
                && $DB->tableExists('glpi_plugin_satisfactionclient_answers'),
            // Statistiques de signature : plugins RP (rapports) et Gestion (bons de livraison).
            'rp'           => $plugin->isInstalled('rp')
                && $plugin->isActivated('rp')
                && $DB->tableExists('glpi_plugin_rp_cridetails'),
            'gestion'      => $plugin->isInstalled('gestion')
                && $plugin->isActivated('gestion')
                && $DB->tableExists('glpi_plugin_gestion_surveys'),
            // Rapports hotline à générer : plugins RP ET Credit.
            PluginStatsHotline::VIEW => PluginStatsHotline::isAvailable(),
        ];

        $rights = [
            'credits'                => PluginStatsProfile::RIGHTNAME_CREDITS,
            'tickets'                => PluginStatsProfile::RIGHTNAME_TICKETS,
            'satisfaction'           => PluginStatsProfile::RIGHTNAME_SATISFACTION,
            'rp'                     => PluginStatsProfile::RIGHTNAME_RP,
            'gestion'                => PluginStatsProfile::RIGHTNAME_GESTION,
            PluginStatsHotline::VIEW => PluginStatsProfile::RIGHTNAME_HOTLINE,
        ];

        $access = [];
        foreach ($enabled as $view => $ok) {
            $access[$view] = $ok && Session::haveRight($rights[$view], PluginStatsProfile::RIGHT_READ);
        }

        return $views = ['enabled' => $enabled, 'access' => $access];
    }

    /**
     * Adresse d'un onglet. La liste hotline a sa propre page : c'est une liste
     * native GLPI, affichée comme toutes les autres (cf. front/hotlineticket.php).
     */
    public static function getViewURL(string $view): string
    {
        global $CFG_GLPI;

        if ($view === PluginStatsHotline::VIEW) {
            return $CFG_GLPI['root_doc'] . '/plugins/stats/front/hotlineticket.php';
        }

        return $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php?view=' . rawurlencode($view);
    }

    /** Barre d'onglets, commune aux deux pages. */
    public static function showTabs(string $active): void
    {
        $labels = [
            'credits'                => __('Stats credits', 'stats'),
            'tickets'                => __('Stats tickets', 'stats'),
            'satisfaction'           => __('Stats satisfaction', 'stats'),
            'rp'                     => __('Stats rapports', 'stats'),
            'gestion'                => __('Stats bons de livraison', 'stats'),
            PluginStatsHotline::VIEW => __('Rapports hotline à générer', 'stats'),
        ];

        $access = self::getViews()['access'];

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<ul class='nav nav-tabs' role='tablist'>";
        foreach ($labels as $view => $label) {
            if (empty($access[$view])) {
                continue;
            }
            echo "<li class='nav-item' role='presentation'>";
            echo "<a class='nav-link" . ($view === $active ? ' active' : '') . "'"
                . " href='" . htmlescape(self::getViewURL($view)) . "'>" . htmlescape($label) . "</a>";
            echo "</li>";
        }
        echo "</ul>";
        echo "</div></div>";
    }
}
