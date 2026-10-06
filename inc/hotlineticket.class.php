<?php

use Glpi\DBAL\QueryExpression;
use Glpi\Search\DefaultSearchRequestInterface;

/**
 * Liste native GLPI des « Rapports hotline à générer ».
 *
 * Chaque ligne est un TICKET (même identifiant) résolu ou clos, qui a consommé
 * du crédit (plugin Credit) et ne porte aucun rapport hotline (plugin RP). La
 * source est une vue SQL, `glpi_plugin_stats_hotlinetickets` : le moteur de
 * recherche de GLPI s'y branche comme sur n'importe quel objet, et l'on obtient
 * sans code maison ses critères, son tri, son choix de colonnes, ses exports,
 * ses recherches sauvegardées, sa pagination et ses actions massives.
 *
 * Un objet à part, plutôt que des critères ajoutés à la liste des tickets : la
 * recherche GLPI mémorise ses critères par type d'objet. Branchée sur Ticket,
 * cette liste aurait remplacé la dernière recherche de tickets de l'utilisateur
 * à chaque visite de l'onglet.
 *
 * Lecture seule : la vue ne s'écrit pas, et seule l'action « Générer le rapport
 * hotline » est proposée — elle passe par le formulaire et le générateur du
 * plugin RP (cf. PluginStatsHotline).
 */
class PluginStatsHotlineticket extends CommonDBTM implements DefaultSearchRequestInterface
{
    public static $rightname = PluginStatsProfile::RIGHTNAME_HOTLINE;

    /** Type « rapport hotline » dans la table des rapports du plugin RP. */
    public const RP_TYPE_HOTLINE = 2;

    public const RP_TABLE     = 'glpi_plugin_rp_cridetails';
    public const CREDIT_TABLE = 'glpi_plugin_credit_tickets';

    /** Crédits (bons) du plugin Credit, dont le nom dit s'ils sont à facturer. */
    public const CREDIT_ENTITY_TABLE = 'glpi_plugin_credit_entities';

    /** Nom des crédits à mettre en facturation, critère proposé à l'ouverture. */
    public const BILLING_CREDIT = 'à Mettre en facturation';

    /** Action massive de génération (cf. getSpecificMassiveActions()). */
    public const MA_GENERATE = 'generate';

    /** Clé de session des générations lancées par action massive. */
    public const SESSION_JOBS = 'plugin_stats_hotline_jobs';

    /** Options de recherche. */
    public const SO_TICKET   = 1;
    public const SO_ID       = 2;
    public const SO_TITLE    = 3;
    public const SO_CATEGORY = 7;
    public const SO_STATUS   = 12;
    public const SO_OPENED   = 15;
    public const SO_CLOSED   = 16;
    public const SO_SOLVED   = 17;
    public const SO_ENTITY   = 80;
    public const SO_CREDIT   = 101;
    public const SO_TECH     = 102;
    public const SO_ACTION   = 103;
    public const SO_CREDIT_NAME = 104;

    /**
     * Ancienne option « Groupe de catégories », qui faisait une colonne à part.
     * Le groupe se choisit désormais dans le critère « Catégorie », déjà affiché
     * en colonne. Ses préférences de colonne et les recherches qui la citaient
     * sont nettoyées.
     */
    public const SO_RETIRED = [100];

    /** Valeurs du critère « Catégorie » : tout un groupe, ou une catégorie. */
    private const SCOPE_GROUP    = 'e';
    private const SCOPE_CATEGORY = 'c';

    /**
     * Colonnes de la vue. Leur présence complète, lue dans le schéma, dit si la
     * vue est à jour : une colonne manquante la fait recréer.
     */
    private const VIEW_COLUMNS = [
        'id', 'tickets_id', 'title', 'entities_id', 'status', 'date', 'solvedate',
        'closedate', 'itilcategories_id', 'itilcategory_name', 'consumed',
        'users_id_tech',
    ];

    /** Colonnes affichées par défaut (la colonne 1, le ticket, l'est toujours). */
    private const DEFAULT_COLUMNS = [
        self::SO_TITLE, self::SO_ENTITY, self::SO_STATUS, self::SO_CREDIT,
        self::SO_CREDIT_NAME, self::SO_OPENED, self::SO_CLOSED, self::SO_TECH,
        self::SO_CATEGORY, self::SO_ACTION,
    ];

    /**
     * Version des critères d'ouverture. Une recherche mémorisée en session
     * avant un changement de ces critères est oubliée une fois, pour que la
     * liste se rouvre sur les nouveaux (cf. forgetRetiredSearch()).
     */
    private const DEFAULTS_VERSION = 3;
    private const SESSION_DEFAULTS = 'plugin_stats_hotline_defaults';

    /** Groupe de catégories proposé par défaut, reconnu au nom de son entité. */
    private const DEFAULT_ENTITY_KEYS = ['easisupport', 'easysupport'];

    public static function getTypeName($nb = 0)
    {
        return _n('Rapport hotline à générer', 'Rapports hotline à générer', $nb, 'stats');
    }

    public static function getIcon()
    {
        return 'ti ti-file-alert';
    }

    // ------------------------------------------------------------------
    // Droits : lecture seule
    // ------------------------------------------------------------------

    /**
     * Droit de l'onglet ET plugins sources présents : sans RP ou Credit, la vue
     * ne peut pas exister, et le moteur de recherche échouerait en l'interrogeant.
     */
    public static function canView(): bool
    {
        return Session::haveRight(static::$rightname, READ) && PluginStatsHotline::isAvailable();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------
    // Adresses : la liste a sa page, les lignes mènent au ticket
    // ------------------------------------------------------------------

    /** Page de la liste, construite comme toute liste GLPI (front/hotlineticket.php). */
    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/stats/front/hotlineticket.php';
    }

    /** Une ligne EST un ticket : ses liens ouvrent le ticket. */
    public static function getFormURL($full = true)
    {
        return Ticket::getFormURL($full);
    }

    // ------------------------------------------------------------------
    // Vue SQL
    // ------------------------------------------------------------------

    /**
     * Crée la vue si elle manque ou si elle n'a pas toutes ses colonnes.
     *
     * Appelée à l'installation ET à l'affichage : le plugin était déjà en
     * 1.0.6 quand la vue est apparue, et la créer à la volée évite de repasser
     * par « Mettre à jour ». Même pratique que le plugin creditalert pour ses
     * propres vues.
     *
     * @return bool vue utilisable
     */
    public static function ensureView(): bool
    {
        global $DB;

        $table = static::getTable();

        $found = [];
        foreach ($DB->request([
            'SELECT' => ['COLUMN_NAME'],
            'FROM'   => 'information_schema.columns',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'TABLE_NAME'   => $table,
            ],
        ]) as $row) {
            $found[strtolower((string) $row['COLUMN_NAME'])] = true;
        }

        if (count(array_diff(self::VIEW_COLUMNS, array_keys($found))) > 0) {
            if (!$DB->tableExists(self::RP_TABLE) || !$DB->tableExists(self::CREDIT_TABLE)) {
                return false;
            }
            $DB->doQuery('CREATE OR REPLACE VIEW ' . $DB->quoteName($table) . ' AS ' . self::viewSql());
        }

        self::ensureRpIndex();
        self::ensureDisplayPreferences();

        return true;
    }

    /**
     * Index (`id_ticket`, `type`) sur la table des rapports du plugin RP.
     *
     * La vue écarte chaque ticket qui a déjà un rapport hotline (`NOT EXISTS`
     * sur `id_ticket`). Le plugin RP n'indexe pas cette colonne : la table
     * entière était relue pour CHAQUE ticket résolu ou clos ayant consommé du
     * crédit, et pas seulement pour la page affichée, car les critères de
     * crédit ne s'appliquent qu'après regroupement. Près d'une minute à
     * l'ouverture de la liste.
     *
     * Posé ici, à l'affichage comme la vue, faute de migration côté RP. Tout
     * index commençant par `id_ticket` suffit : s'il en existe un, rien n'est
     * ajouté. Un échec ralentit la liste sans la casser : il est journalisé.
     */
    private static function ensureRpIndex(): void
    {
        global $DB;

        try {
            $res = $DB->doQuery(
                'SHOW INDEX FROM ' . $DB->quoteName(self::RP_TABLE)
                . " WHERE Column_name = 'id_ticket' AND Seq_in_index = 1"
            );
            if ($res && $DB->numrows($res) === 0) {
                $DB->doQuery(
                    'ALTER TABLE ' . $DB->quoteName(self::RP_TABLE)
                    . ' ADD INDEX `id_ticket_type` (`id_ticket`, `type`)'
                );
            }
        } catch (\Throwable $e) {
            Toolbox::logInFile(
                'plugin-stats',
                'Rapports hotline à générer (index ' . self::RP_TABLE . ') : ' . $e->getMessage() . "\n"
            );
        }
    }

    public static function dropView(): void
    {
        global $DB;

        $DB->doQuery('DROP VIEW IF EXISTS ' . $DB->quoteName(static::getTable()));
        $DB->delete(DisplayPreference::getTable(), ['itemtype' => static::class]);
        // Recherches sauvegardées sur cette liste : orphelines sans elle.
        (new SavedSearch())->deleteByCriteria(['itemtype' => static::class], true);
    }

    /**
     * Définition de la vue.
     *
     * - crédit : SOMME des consommations du ticket (une ligne par passage dans
     *   le plugin Credit), agrégée une fois pour toutes dans une sous-table ;
     * - rapport hotline : toute ligne de type 2, qu'elle vienne du ticket, de
     *   cet onglet ou de l'export massif RP. `NOT EXISTS` et non `NOT IN` : la
     *   colonne `id_ticket` accepte NULL, et un seul NULL dans un `NOT IN` vide
     *   la liste entière sans erreur ;
     * - catégorie : son nom complet, affiché et trié tel quel ;
     * - technicien : auteur de la dernière solution non refusée, sinon premier
     *   technicien attribué.
     */
    private static function viewSql(): string
    {
        $solution = ITILSolution::getTable();
        $actors   = Ticket_User::getTable();

        return "
            SELECT
                `t`.`id`                AS `id`,
                `t`.`id`                AS `tickets_id`,
                `t`.`name`              AS `title`,
                `t`.`entities_id`       AS `entities_id`,
                `t`.`status`            AS `status`,
                `t`.`date`              AS `date`,
                `t`.`solvedate`         AS `solvedate`,
                `t`.`closedate`         AS `closedate`,
                `t`.`itilcategories_id` AS `itilcategories_id`,
                `cat`.`completename`    AS `itilcategory_name`,
                `c`.`consumed`          AS `consumed`,
                COALESCE(
                    (SELECT `s`.`users_id` FROM `{$solution}` `s`
                      WHERE `s`.`itemtype` = 'Ticket' AND `s`.`items_id` = `t`.`id`
                        AND `s`.`users_id` > 0 AND `s`.`status` <> " . (int) CommonITILValidation::REFUSED . "
                      ORDER BY `s`.`date_creation` DESC, `s`.`id` DESC LIMIT 1),
                    (SELECT `tu`.`users_id` FROM `{$actors}` `tu`
                      WHERE `tu`.`tickets_id` = `t`.`id` AND `tu`.`type` = " . (int) CommonITILActor::ASSIGN . "
                        AND `tu`.`users_id` > 0
                      ORDER BY `tu`.`id` ASC LIMIT 1),
                    0
                ) AS `users_id_tech`
            FROM `glpi_tickets` `t`
            INNER JOIN (
                SELECT `tickets_id`, SUM(`consumed`) AS `consumed`
                FROM `" . self::CREDIT_TABLE . "`
                GROUP BY `tickets_id`
            ) `c` ON `c`.`tickets_id` = `t`.`id`
            LEFT JOIN `glpi_itilcategories` `cat` ON `cat`.`id` = `t`.`itilcategories_id`
            WHERE `t`.`is_deleted` = 0
              AND `t`.`status` IN (" . (int) CommonITILObject::SOLVED . ", " . (int) CommonITILObject::CLOSED . ")
              AND NOT EXISTS (
                  SELECT 1 FROM `" . self::RP_TABLE . "` `r`
                  WHERE `r`.`id_ticket` = `t`.`id` AND `r`.`type` = " . self::RP_TYPE_HOTLINE . "
              )";
    }

    /**
     * Colonnes par défaut de la liste (préférences générales, users_id = 0) :
     * posées à la première ouverture, puis complétées par une colonne par
     * défaut apparue depuis, à sa place (juste après celle qui la précède dans
     * DEFAULT_COLUMNS). Les colonnes ôtées ou déplacées ensuite ne sont pas
     * touchées, et chacun peut choisir les siennes.
     *
     * Les colonnes d'options retirées sont effacées, pour tous : GLPI les
     * afficherait sinon vides, sous un titre inconnu.
     */
    private static function ensureDisplayPreferences(): void
    {
        global $DB;

        $table = DisplayPreference::getTable();
        $DB->delete($table, ['itemtype' => static::class, 'num' => self::SO_RETIRED]);

        $ranks = [];
        foreach ($DB->request([
            'SELECT' => ['num', 'rank'],
            'FROM'   => $table,
            'WHERE'  => ['itemtype' => static::class, 'users_id' => 0],
        ]) as $row) {
            $ranks[(int) $row['num']] = (int) $row['rank'];
        }

        $hasInterface = $DB->fieldExists($table, 'interface');
        $previous     = 0;
        foreach (self::DEFAULT_COLUMNS as $num) {
            if (!isset($ranks[$num])) {
                // Juste après la colonne précédente, les suivantes reculent d'un rang.
                $rank = ($ranks[$previous] ?? (count($ranks) > 0 ? max($ranks) : 0)) + 1;
                $DB->update(
                    $table,
                    ['rank' => new QueryExpression($DB->quoteName('rank') . ' + 1')],
                    ['itemtype' => static::class, 'users_id' => 0, 'rank' => ['>=', $rank]]
                );
                foreach ($ranks as $other => $otherRank) {
                    if ($otherRank >= $rank) {
                        $ranks[$other] = $otherRank + 1;
                    }
                }

                $row = [
                    'itemtype' => static::class,
                    'num'      => $num,
                    'rank'     => $rank,
                    'users_id' => 0,
                ];
                if ($hasInterface) {
                    $row['interface'] = 'central';
                }
                $DB->insert($table, $row);
                $ranks[$num] = $rank;
            }
            $previous = $num;
        }
    }

    /**
     * Recherche mémorisée en session qui cite une option retirée : oubliée,
     * pour retomber sur les critères par défaut plutôt que sur une recherche
     * amputée de son filtre de catégories.
     */
    public static function forgetRetiredSearch(): void
    {
        // Critères d'ouverture changés depuis la recherche mémorisée : oubliée une
        // fois, à une ouverture SANS critère dans l'adresse. Une adresse qui en
        // porte (page rechargée après un tri) les remettrait aussitôt en session.
        if (($_SESSION[self::SESSION_DEFAULTS] ?? 0) < self::DEFAULTS_VERSION && !isset($_GET['criteria'])) {
            unset($_SESSION['glpisearch'][static::class]);
            $_SESSION[self::SESSION_DEFAULTS] = self::DEFAULTS_VERSION;
            return;
        }

        $criteria = $_SESSION['glpisearch'][static::class]['criteria'] ?? [];
        if (!is_array($criteria)) {
            return;
        }
        foreach ($criteria as $criterion) {
            if (is_array($criterion) && in_array((int) ($criterion['field'] ?? 0), self::SO_RETIRED, true)) {
                unset($_SESSION['glpisearch'][static::class]);
                return;
            }
        }
    }

    // ------------------------------------------------------------------
    // Catégories : groupes et catégories de premier niveau
    // ------------------------------------------------------------------

    /**
     * Catégories visibles, rangées comme dans la liste déroulante native :
     * par entité propriétaire (le « groupe »), puis par nom complet.
     *
     * @return array{
     *     cats: array<int, array{name:string, parent:int, entity:int}>,
     *     children: array<int, int[]>,
     *     groups: array<int, array{label:string, name:string, ids:int[], roots:int[]}>
     * }
     */
    private static function categoryTree(): array
    {
        global $DB;

        static $tree = null;
        if ($tree !== null) {
            return $tree;
        }

        $table    = ITILCategory::getTable();
        $entities = Entity::getTable();

        $where    = [];
        $restrict = getEntitiesRestrictCriteria($table, '', '', true);
        if (count($restrict) > 0) {
            $where[] = $restrict;
        }

        $tree = ['cats' => [], 'children' => [], 'groups' => []];
        foreach ($DB->request([
            'SELECT'    => [
                $table . '.id',
                $table . '.name',
                $table . '.itilcategories_id',
                $table . '.entities_id',
                $entities . '.name AS entity_name',
                $entities . '.completename AS entity_completename',
            ],
            'FROM'      => $table,
            'LEFT JOIN' => [
                $entities => ['ON' => [$entities => 'id', $table => 'entities_id']],
            ],
            'WHERE'     => $where,
            'ORDER'     => [$entities . '.completename ASC', $table . '.completename ASC'],
        ]) as $row) {
            $id     = (int) $row['id'];
            $parent = (int) $row['itilcategories_id'];
            $entity = (int) $row['entities_id'];

            $tree['cats'][$id]           = ['name' => (string) $row['name'], 'parent' => $parent, 'entity' => $entity];
            $tree['children'][$parent][] = $id;

            if (!isset($tree['groups'][$entity])) {
                $tree['groups'][$entity] = [
                    'label' => (string) ($row['entity_completename'] ?? ''),
                    'name'  => (string) ($row['entity_name'] ?? ''),
                    'ids'   => [],
                    'roots' => [],
                ];
            }
            $tree['groups'][$entity]['ids'][] = $id;
        }

        // Racines d'un groupe : catégories dont la parente n'est pas dans ce groupe.
        foreach ($tree['groups'] as $entity => $group) {
            foreach ($group['ids'] as $id) {
                $parent = $tree['cats'][$id]['parent'];
                if (!isset($tree['cats'][$parent]) || $tree['cats'][$parent]['entity'] !== $entity) {
                    $tree['groups'][$entity]['roots'][] = $id;
                }
            }
        }

        return $tree;
    }

    /**
     * @param int[] $ids
     * @return int[] catégories données et toute leur descendance
     */
    private static function descendants(array $ids, array $children): array
    {
        $seen  = [];
        $queue = $ids;
        while (count($queue) > 0) {
            $id = (int) array_pop($queue);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }
        return array_keys($seen);
    }

    /**
     * Catégories couvertes par une valeur du critère « Catégorie ».
     *
     * « e:<entité> » : tout le groupe ; « c:<catégorie> » ou un simple numéro :
     * la catégorie. Sous-catégories toujours comprises.
     *
     * @return int[]|null null si la valeur n'est pas reconnue
     */
    public static function resolveCategoryValue(string $value): ?array
    {
        $tree = self::categoryTree();

        if (preg_match('/^(' . self::SCOPE_GROUP . '|' . self::SCOPE_CATEGORY . '):(\d+)$/', $value, $m)) {
            $id   = (int) $m[2];
            $base = $m[1] === self::SCOPE_GROUP ? ($tree['groups'][$id]['ids'] ?? []) : [$id];
            return self::descendants($base, $tree['children']);
        }
        if (ctype_digit($value) && (int) $value > 0) {
            return self::descendants([(int) $value], $tree['children']);
        }

        return null;
    }

    /**
     * Choix du critère « Catégorie » : pour chaque groupe, le groupe entier,
     * puis ses catégories de premier niveau. Les sous-catégories suivent leur
     * parente : les lister toutes rendrait la liste interminable.
     *
     * @return array<string, string>
     */
    private static function categoryChoices(): array
    {
        $tree    = self::categoryTree();
        $choices = [];

        foreach ($tree['groups'] as $entity => $group) {
            $count = count(self::descendants($group['ids'], $tree['children']));
            $choices[self::SCOPE_GROUP . ':' . $entity] = sprintf(
                _n('%1$s : toute la catégorie (%2$d)', '%1$s : toutes les catégories (%2$d)', $count, 'stats'),
                $group['label'] !== '' ? $group['label'] : __('Sans entité', 'stats'),
                $count
            );

            foreach ($group['roots'] as $id) {
                $subs  = count(self::descendants([$id], $tree['children'])) - 1;
                $label = "\u{00A0}\u{00A0}\u{00A0}\u{00A0}» " . $tree['cats'][$id]['name'];
                if ($subs > 0) {
                    $label .= ' ' . sprintf(_n('(+%d sous-catégorie)', '(+%d sous-catégories)', $subs, 'stats'), $subs);
                }
                $choices[self::SCOPE_CATEGORY . ':' . $id] = $label;
            }
        }

        return $choices;
    }

    /** Groupe « EASI SUPPORT » : entité dont le nom le contient, la plus haute ; 0 si aucun. */
    public static function findEasiSupportGroup(): int
    {
        foreach (self::categoryTree()['groups'] as $entity => $group) {
            $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($group['name']));
            foreach (self::DEFAULT_ENTITY_KEYS as $wanted) {
                if (str_contains($key, $wanted)) {
                    return (int) $entity;
                }
            }
        }

        return 0;
    }

    // ------------------------------------------------------------------
    // Recherche
    // ------------------------------------------------------------------

    public function rawSearchOptions()
    {
        $table = static::getTable();
        $tab   = [];

        $tab[] = ['id' => 'common', 'name' => static::getTypeName(2)];

        // Colonne 1, toujours affichée en tête par GLPI : le numéro du ticket, cliquable.
        $tab[] = [
            'id'            => self::SO_TICKET,
            'table'         => $table,
            'field'         => 'id',
            'name'          => Ticket::getTypeName(1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => self::SO_ID,
            'table'         => $table,
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        /*
         * Titre : colonne `title` de la vue, et non `name`. GLPI place d'office
         * en tête la colonne du nom de l'objet, et à défaut celle de son
         * identifiant : sans champ `name`, c'est le numéro du ticket qui ouvre
         * la liste, comme dans la liste native des tickets.
         */
        $tab[] = [
            'id'            => self::SO_TITLE,
            'table'         => $table,
            'field'         => 'title',
            'name'          => __('Title'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        /*
         * Catégorie : la colonne montre le nom complet ; le critère propose
         * aussi les GROUPES de la liste native (« Root entity > EASI SUPPORT »),
         * qu'on ne peut pas y sélectionner. Le SQL de ce critère est écrit par
         * addWhere(), point d'extension prévu par GLPI pour un type d'objet.
         * Filtrer ainsi n'ajoute aucune colonne : celle-ci est déjà affichée.
         */
        $tab[] = [
            'id'            => self::SO_CATEGORY,
            'table'         => $table,
            'field'         => 'itilcategory_name',
            'name'          => _n('Category', 'Categories', 1),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => self::SO_STATUS,
            'table'         => $table,
            'field'         => 'status',
            'name'          => __('Status'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => self::SO_OPENED,
            'table'         => $table,
            'field'         => 'date',
            'name'          => __('Opening date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => self::SO_CLOSED,
            'table'         => $table,
            'field'         => 'closedate',
            'name'          => __('Closing date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => self::SO_SOLVED,
            'table'         => $table,
            'field'         => 'solvedate',
            'name'          => __('Resolution date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => self::SO_ENTITY,
            'table'         => Entity::getTable(),
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        /*
         * Crédit consommé et Crédit : chaque consommation du plugin Credit,
         * jointe au ticket. Les deux options déclarent la MÊME jointure : GLPI
         * ne joint la table qu'une fois, et deux critères portent alors sur la
         * même consommation. « Crédit consommé > 1 » ET « Crédit contient à
         * Mettre en facturation » = plus d'un crédit consommé sur un crédit à
         * facturer ; ce qui est consommé sur un autre crédit ne compte pas.
         */
        $consumption = [
            'jointype'  => 'child',
            'linkfield' => 'tickets_id',
        ];

        $tab[] = [
            'id'            => self::SO_CREDIT,
            'table'         => self::CREDIT_TABLE,
            'field'         => 'consumed',
            'name'          => __('Crédit consommé', 'stats'),
            'datatype'      => 'number',
            'forcegroupby'  => true,
            'massiveaction' => false,
            'joinparams'    => $consumption,
        ];

        $tab[] = [
            'id'            => self::SO_CREDIT_NAME,
            'table'         => self::CREDIT_ENTITY_TABLE,
            'field'         => 'name',
            'name'          => __('Crédit', 'stats'),
            'datatype'      => 'string',
            'forcegroupby'  => true,
            'massiveaction' => false,
            'joinparams'    => [
                'beforejoin' => [
                    'table'      => self::CREDIT_TABLE,
                    'joinparams' => $consumption,
                ],
            ],
        ];

        $tab[] = [
            'id'            => self::SO_TECH,
            'table'         => User::getTable(),
            'field'         => 'name',
            'linkfield'     => 'users_id_tech',
            'name'          => __('Technicien (solution)', 'stats'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => self::SO_ACTION,
            'table'         => $table,
            'field'         => 'tickets_id',
            'name'          => __('Rapport hotline', 'stats'),
            'datatype'      => 'specific',
            'nosearch'      => true,
            'nosort'        => true,
            'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * SQL du critère « Catégorie » (groupe ou catégorie, sous-catégories
     * comprises). Appelée par le moteur de recherche de GLPI pour chaque
     * critère : chaîne vide = traitement standard.
     */
    public static function addWhere($link, $nott, $itemtype, $ID, $searchtype, $val)
    {
        global $DB;

        if ((int) $ID !== self::SO_CATEGORY || !in_array($searchtype, ['equals', 'notequals'], true)) {
            return '';
        }

        $ids = self::resolveCategoryValue((string) $val);
        if ($ids === null) {
            return '';
        }

        // « n'est pas », ou « ET PAS » devant le critère : les deux inversent.
        $negate = (bool) $nott xor ($searchtype === 'notequals');
        $column = $DB->quoteName(static::getTable() . '.itilcategories_id');
        $list   = count($ids) > 0 ? implode(',', array_map('intval', $ids)) : '0';

        return $link . ' ' . $column . ($negate ? ' NOT IN (' : ' IN (') . $list . ')';
    }

    /**
     * Critères à l'ouverture : plus d'un crédit consommé sur un crédit « à
     * Mettre en facturation » (les deux premiers critères visent la même
     * consommation, cf. rawSearchOptions()), et catégories du groupe EASI
     * SUPPORT. Tous portent sur des colonnes déjà affichées : GLPI n'en ajoute
     * aucune. Tri : dernières clôtures d'abord.
     */
    public static function getDefaultSearchRequest(): array
    {
        $criteria = [[
            'field'      => self::SO_CREDIT,
            'searchtype' => 'contains',
            'value'      => '>1',
        ], [
            'link'       => 'AND',
            'field'      => self::SO_CREDIT_NAME,
            'searchtype' => 'contains',
            'value'      => self::BILLING_CREDIT,
        ]];

        $group = self::findEasiSupportGroup();
        if ($group > 0) {
            $criteria[] = [
                'link'       => 'AND',
                'field'      => self::SO_CATEGORY,
                'searchtype' => 'equals',
                'value'      => self::SCOPE_GROUP . ':' . $group,
            ];
        }

        return [
            'criteria' => $criteria,
            'sort'     => self::SO_CLOSED,
            'order'    => 'DESC',
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'status':
                $status = (int) ($values[$field] ?? 0);
                return Ticket::getStatusIcon($status) . htmlescape(Ticket::getStatus($status));

            case 'itilcategory_name':
                return htmlescape((string) ($values[$field] ?? ''));

            case 'tickets_id':
                return PluginStatsHotline::generateButton((int) ($values[$field] ?? 0));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            // Statut : seuls « Résolu » et « Clos » existent dans cette liste.
            case 'status':
                return Dropdown::showFromArray($name, [
                    CommonITILObject::SOLVED => Ticket::getStatus(CommonITILObject::SOLVED),
                    CommonITILObject::CLOSED => Ticket::getStatus(CommonITILObject::CLOSED),
                ], [
                    'value'   => $values[$field] ?? '',
                    'display' => false,
                ]);

            // Catégorie : groupes entiers et catégories de premier niveau.
            case 'itilcategory_name':
                return Dropdown::showFromArray($name, self::categoryChoices(), [
                    'value'   => $values[$field] ?? '',
                    'display' => false,
                    'width'   => '100%',
                ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // ------------------------------------------------------------------
    // Action massive : générer le rapport hotline
    // ------------------------------------------------------------------

    public function getSpecificMassiveActions($checkitem = null)
    {
        $actions = parent::getSpecificMassiveActions($checkitem);

        if (PluginStatsHotline::canGenerate()) {
            $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . self::MA_GENERATE]
                = "<i class='ti ti-file-plus'></i>" . __s('Générer le rapport hotline (RP)', 'stats');
        }

        return $actions;
    }

    /** Liste en lecture seule : aucune action standard de modification. */
    public function getForbiddenStandardMassiveAction()
    {
        return array_merge(parent::getForbiddenStandardMassiveAction(), [
            'update', 'clone', 'create_template', 'delete', 'purge', 'restore',
            'add_transfer_list', 'amend_comment', 'add_note', 'associate_group',
            'dissociate_group', 'add_contract_item', 'remove_contract_item',
            'add_document_item', 'remove_document_item', 'add_infocom',
            'activate_infocoms', 'export_to_pdf',
        ]);
    }

    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() === self::MA_GENERATE) {
            echo "<div class='alert alert-info'>";
            echo "<div class='fw-bold mb-1'>" . __s('Génération des rapports hotline', 'stats') . "</div>";
            echo "<ul class='mb-0'>";
            echo "<li>" . __s("Chaque rapport est produit par le plugin RP, avec son formulaire et ses réglages, comme depuis le ticket.", 'stats') . "</li>";
            echo "<li>" . __s("Le PDF est rattaché au ticket, dans ses documents.", 'stats') . "</li>";
            echo "<li>" . __s("Aucun mail n'est envoyé au client.", 'stats') . "</li>";
            echo "<li>" . __s("Une page suit la génération ticket par ticket. Un ticket qui a déjà son rapport est passé.", 'stats') . "</li>";
            echo "</ul></div>";
            echo Html::submit(__('Lancer la génération', 'stats'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']);
            return true;
        }

        return parent::showMassiveActionsSubForm($ma);
    }

    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids)
    {
        global $CFG_GLPI;

        if ($ma->getAction() !== self::MA_GENERATE) {
            parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
            return;
        }

        // Les identifiants sont les CLÉS (même lecture que l'action massive du plugin RP).
        $tickets = [];
        foreach ($ids as $key => $val) {
            if ($val && (int) $key > 0) {
                $tickets[] = (int) $key;
            }
        }

        if (!PluginStatsHotline::canGenerate()) {
            foreach ($tickets as $id) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
            }
            $ma->addMessage(__s('Droit « Rapport hotline » du plugin RP requis.', 'stats'));
            return;
        }

        /*
         * La génération elle-même ne se fait PAS ici : un PDF prend plusieurs
         * secondes, cent d'affilée dépasseraient le délai d'une requête, et rien
         * ne s'afficherait avant la fin. Les tickets sont confiés à une page de
         * suivi qui les traite un par un, à la vue de l'utilisateur.
         */
        $key  = bin2hex(random_bytes(8));
        $jobs = $_SESSION[self::SESSION_JOBS] ?? [];
        $jobs[$key] = ['ids' => $tickets, 'created' => time()];
        // Les dix dernières suffisent : une session ne doit pas grossir sans fin.
        $_SESSION[self::SESSION_JOBS] = array_slice($jobs, -10, null, true);

        foreach ($tickets as $id) {
            $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
        }

        $ma->setRedirect($CFG_GLPI['root_doc'] . '/plugins/stats/front/hotlinegenerate.php?job=' . $key);
    }
}
