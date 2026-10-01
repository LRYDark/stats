<?php

class PluginStatsProfile extends Profile
{
    // Ancienne constante (migration uniquement — sera supprimée de la BDD)
    private const RIGHTNAME_LEGACY = 'plugin_stats';

    // Un droit par onglet
    public const RIGHTNAME_CREDITS     = 'plugin_stats_credits';
    public const RIGHTNAME_TICKETS     = 'plugin_stats_tickets';
    public const RIGHTNAME_SATISFACTION = 'plugin_stats_satisfaction';
    public const RIGHTNAME_RP          = 'plugin_stats_rp';
    public const RIGHTNAME_GESTION     = 'plugin_stats_gestion';
    public const RIGHTNAME_HOTLINE     = 'plugin_stats_hotline';

    public const RIGHT_READ = 1;

    // Utilisé par GLPI pour les méthodes canXxx() héritées
    public static $rightname = self::RIGHTNAME_TICKETS;

    public static function getTypeName($nb = 0)
    {
        return _n('Statistique', 'Statistiques', $nb, 'stats');
    }

    public static function getIcon()
    {
        return 'fa-solid fa-line-chart';
    }

    /**
     * Définition des droits, un par onglet.
     *
     * SOURCE UNIQUE : la matrice du profil, l'installation, la désinstallation et
     * le rechargement en session la parcourent tous. Ajouter un onglet se fait
     * donc ici, et nulle part ailleurs.
     */
    public static function getAllRights($all = false): array
    {
        return [
            [
                'itemtype' => self::class,
                'label'    => __('Stats crédits', 'stats'),
                'field'    => self::RIGHTNAME_CREDITS,
                'rights'   => [self::RIGHT_READ => __('Voir Stats crédits', 'stats')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Stats tickets', 'stats'),
                'field'    => self::RIGHTNAME_TICKETS,
                'rights'   => [self::RIGHT_READ => __('Voir Stats tickets', 'stats')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Satisfaction', 'stats'),
                'field'    => self::RIGHTNAME_SATISFACTION,
                'rights'   => [self::RIGHT_READ => __('Voir Satisfaction', 'stats')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Stats rapports (RP)', 'stats'),
                'field'    => self::RIGHTNAME_RP,
                'rights'   => [self::RIGHT_READ => __('Voir les statistiques de signature des rapports', 'stats')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Stats bons de livraison (Gestion)', 'stats'),
                'field'    => self::RIGHTNAME_GESTION,
                'rights'   => [self::RIGHT_READ => __('Voir les statistiques de signature des bons de livraison', 'stats')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Rapports hotline à générer (RP + Credit)', 'stats'),
                'field'    => self::RIGHTNAME_HOTLINE,
                'rights'   => [self::RIGHT_READ => __('Voir les tickets facturés en crédit sans rapport hotline', 'stats')],
            ],
        ];
    }

    /** Noms des droits, dans l'ordre de getAllRights(). */
    public static function getRightNames(): array
    {
        return array_column(self::getAllRights(), 'field');
    }

    /**
     * Installation : migration de l'ancien droit unique vers les 3 nouveaux.
     * - Profils ayant l'ancien droit → reçoivent les 3 nouveaux avec RIGHT_READ
     * - Autres profils              → reçoivent les 3 nouveaux avec 0 (pas d'accès)
     */
    public static function install(Migration $migration): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_profilerights') || !$DB->tableExists('glpi_profiles')) {
            return;
        }

        // Dérivés de getAllRights() : une liste recopiée ici aurait été oubliée
        // au premier onglet ajouté, et le droit correspondant n'aurait jamais
        // existé en base — onglet invisible pour tout le monde, sans erreur.
        $newRights = self::getRightNames();

        // Profils qui avaient l'ancien droit (valeur > 0)
        $legacyProfileIds = [];
        foreach ($DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => self::RIGHTNAME_LEGACY, 'rights' => ['>', 0]],
        ]) as $row) {
            $legacyProfileIds[(int) $row['profiles_id']] = true;
        }

        // Tous les profils
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $row) {
            $profileId = (int) $row['id'];
            $value     = isset($legacyProfileIds[$profileId]) ? self::RIGHT_READ : 0;
            foreach ($newRights as $rightName) {
                $dbu = new DbUtils();
                if (!$dbu->countElementsInTable('glpi_profilerights', [
                    'profiles_id' => $profileId,
                    'name'        => $rightName,
                ])) {
                    $DB->insert('glpi_profilerights', [
                        'profiles_id' => $profileId,
                        'name'        => $rightName,
                        'rights'      => $value,
                    ]);
                }
            }
        }

        // Suppression de l'ancien droit
        $DB->delete('glpi_profilerights', ['name' => self::RIGHTNAME_LEGACY]);
    }

    public static function removeRights(): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_profilerights')) {
            return;
        }

        $DB->delete('glpi_profilerights', [
            // Même source que l'installation, plus l'ancien droit unique.
            'name' => array_merge(self::getRightNames(), [self::RIGHTNAME_LEGACY]),
        ]);
    }

    // ------------------------------------------------------------------
    // Onglet "Statistiques" dans la page Profil (admin uniquement)
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && Session::haveRight('profile', READ)) {
            return self::createTabEntry(self::getTypeName(2));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            $profile = new self();
            /** @phpstan-ignore-next-line */
            $profile->showForm($item->getID());
        }
        return true;
    }

    public function showForm($ID, $options = [])
    {
        $canedit = Session::haveRight('profile', UPDATE);

        // S'assurer que les 3 droits existent pour ce profil (valeur 0 par défaut)
        foreach (self::getAllRights() as $rightDef) {
            self::addDefaultProfileInfos($ID, [$rightDef['field'] => 0]);
        }

        echo "<div class='spaced'>";
        $profile = new Profile();
        $profile->getFromDB($ID);
        echo "<form method='post' action='" . $profile->getFormURL() . "'>";

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'title'   => self::getTypeName(2),
            'canedit' => $canedit,
        ]);

        echo "<div class='center'>";
        echo Html::hidden('id', ['value' => $ID]);
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
        echo "</div>\n";
        Html::closeForm();
        echo "</div>";

        return true;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function addDefaultProfileInfos($profiles_id, array $rights, bool $drop_existing = false): void
    {
        $profileRight = new ProfileRight();
        $dbu          = new DbUtils();

        foreach ($rights as $right => $value) {
            if ($drop_existing && $dbu->countElementsInTable('glpi_profilerights', [
                'profiles_id' => $profiles_id,
                'name'        => $right,
            ])) {
                $profileRight->deleteByCriteria(['profiles_id' => $profiles_id, 'name' => $right]);
            }

            if (!$dbu->countElementsInTable('glpi_profilerights', [
                'profiles_id' => $profiles_id,
                'name'        => $right,
            ])) {
                $profileRight->add([
                    'profiles_id' => $profiles_id,
                    'name'        => $right,
                    'rights'      => $value,
                ]);
            }
        }
    }

    public static function createFirstAccess($profiles_id): void
    {
        // Tous les onglets ouverts au profil installateur, y compris ceux ajoutés
        // par la suite : la liste vient de getAllRights().
        $rights = [];
        foreach (self::getRightNames() as $name) {
            $rights[$name] = self::RIGHT_READ;
        }
        self::addDefaultProfileInfos($profiles_id, $rights, true);
    }

    public static function initProfile(): void
    {
        global $DB;

        $profile = new self();
        $dbu     = new DbUtils();

        foreach ($profile->getAllRights(true) as $data) {
            if ($dbu->countElementsInTable('glpi_profilerights', ['name' => $data['field']]) === 0) {
                ProfileRight::addProfileRights([$data['field']]);
            }
        }

        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            foreach (self::getAllRights() as $rightDef) {
                self::addDefaultProfileInfos($_SESSION['glpiactiveprofile']['id'], [$rightDef['field'] => 0]);
            }
            foreach ($DB->request([
                'FROM'  => 'glpi_profilerights',
                'WHERE' => [
                    'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                    'name'        => ['LIKE', 'plugin_stats%'],
                ],
            ]) as $prof) {
                $_SESSION['glpiactiveprofile'][$prof['name']] = $prof['rights'];
            }
        }
    }

    public static function removeRightsFromSession(): void
    {
        foreach (self::getAllRights(true) as $right) {
            if (isset($_SESSION['glpiactiveprofile'][$right['field']])) {
                unset($_SESSION['glpiactiveprofile'][$right['field']]);
            }
        }
    }
}
