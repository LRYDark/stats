<?php

/**
 * Onglet « Stats bons de livraison » — signatures produites via le plugin Gestion.
 *
 * Même lecture que l'onglet des rapports, sur l'autre document : combien de bons
 * chaque technicien fait signer, à quel rythme, et lesquels traînent sans
 * signature.
 *
 * Ne s'affiche que si le plugin Gestion est actif (cf. front/stats.php).
 */
class PluginStatsGestion
{
    public const VIEW = 'gestion';

    private const TABLE = 'glpi_plugin_gestion_surveys';

    /** Ancienneté à partir de laquelle un bon non signé est considéré en retard. */
    private const LATE_DAYS = 14;

    /**
     * @param array $helpers closures partagées de front/stats.php
     */
    public static function show(array $helpers): void
    {
        global $DB;

        $period   = PluginStatsSignature::period(self::VIEW);
        $entities = $helpers['normalizeIntList']($_GET[self::VIEW . '_entities_id'] ?? []);
        $users    = $helpers['normalizeIntList']($_GET[self::VIEW . '_users_id'] ?? []);

        PluginStatsSignature::filterForm(self::VIEW, $helpers, [
            'entities' => $entities,
            'users'    => $users,
            'begin'    => $period['begin'],
            'end'      => $period['end'],
        ]);

        $from = $period['begin'] . ' 00:00:00';
        $to   = $period['end']   . ' 23:59:59';

        /*
         * `signed = 1` ET `doc_date` renseignée, les deux ensemble.
         *
         * Le cron d'import SharePoint du plugin Gestion écrit `doc_date` avec la
         * date de création du fichier, y compris sur des bons NON signés : s'en
         * remettre à la seule présence de la date ferait entrer ces lignes dans
         * les statistiques de signature.
         */
        $where = [
            self::TABLE . '.signed' => 1,
            'NOT'                   => [self::TABLE . '.doc_date' => null],
            self::TABLE . '.doc_date' => ['>=', $from],
        ] + PluginStatsSignature::entityCriteria(self::TABLE, $entities, $helpers['expandEntityScope']);
        $where[] = [self::TABLE . '.doc_date' => ['<=', $to]];

        if (count($users) > 0) {
            $where[self::TABLE . '.users_id'] = $users;
        }

        try {
            $rows = self::aggregate($DB, $where);
            $late = self::countLate($DB, $entities, $helpers);
        } catch (\Throwable $e) {
            Toolbox::logInFile('plugin-stats', 'Stats Gestion : ' . $e->getMessage() . "\n");
            PluginStatsSignature::emptyState(
                __("Les statistiques n'ont pas pu être calculées. Le détail a été journalisé.", 'stats')
            );
            return;
        }

        self::render($rows, $late, $period, $helpers, $DB, $where);
    }

    /**
     * Bons signés, regroupés par technicien.
     *
     * @return array<int, array{nb:int, last:string, by_month:array<string,int>, days:array<string,bool>}>
     */
    private static function aggregate(DBmysql $DB, array $where): array
    {
        $rows = [];

        foreach ($DB->request([
            'SELECT' => [self::TABLE . '.users_id', self::TABLE . '.doc_date'],
            'FROM'   => self::TABLE,
            'WHERE'  => $where,
        ]) as $row) {
            /*
             * `users_id` peut être nul ou zéro : bon importé par le cron, ou
             * signé sur tablette avec un nom de technicien saisi librement que le
             * plugin n'a pas su rapprocher d'un compte. Ces bons existent bel et
             * bien — les écarter fausserait le total, alors qu'une ligne « non
             * attribué » dit exactement ce qu'il en est.
             */
            $uid   = (int) ($row['users_id'] ?? 0);
            $date  = (string) $row['doc_date'];
            $month = substr($date, 0, 7);
            $day   = substr($date, 0, 10);

            if (!isset($rows[$uid])) {
                $rows[$uid] = ['nb' => 0, 'last' => '', 'by_month' => [], 'days' => []];
            }

            $rows[$uid]['nb']++;
            $rows[$uid]['by_month'][$month] = ($rows[$uid]['by_month'][$month] ?? 0) + 1;
            $rows[$uid]['days'][$day]       = true;

            if ($date > $rows[$uid]['last']) {
                $rows[$uid]['last'] = $date;
            }
        }

        return $rows;
    }

    /**
     * Bons non signés depuis plus de LATE_DAYS jours.
     *
     * Comptés sur `date_creation` et non `doc_date` : un bon jamais signé n'a pas
     * de date de signature. Aucune période n'est appliquée non plus — un retard
     * ne cesse pas d'en être un parce qu'il sort de la fenêtre affichée.
     */
    private static function countLate(DBmysql $DB, array $entities, array $helpers): int
    {
        $limit = date('Y-m-d H:i:s', time() - (self::LATE_DAYS * DAY_TIMESTAMP));

        $where = [
            self::TABLE . '.signed'        => 0,
            self::TABLE . '.date_creation' => ['<', $limit],
        ] + PluginStatsSignature::entityCriteria(self::TABLE, $entities, $helpers['expandEntityScope']);

        return countElementsInTable(self::TABLE, $where);
    }

    private static function render(
        array $rows,
        int $late,
        array $period,
        array $helpers,
        DBmysql $DB,
        array $where
    ): void {
        global $CFG_GLPI;

        $months    = PluginStatsSignature::monthRange($period['begin'], $period['end']);
        $nb_months = max(1, count($months));

        $total    = 0;
        $by_month = array_fill_keys($months, 0);
        $all_days = [];

        foreach ($rows as $r) {
            $total += $r['nb'];
            foreach ($r['by_month'] as $m => $n) {
                if (array_key_exists($m, $by_month)) {
                    $by_month[$m] += $n;
                }
            }
            foreach (array_keys($r['days']) as $d) {
                $all_days[$d] = true;
            }
        }

        /*
         * Moyenne par jour rapportée aux jours TRAVAILLÉS, pas aux jours du
         * calendrier : diviser par 365 dirait « 0,4 bon par jour » d'une personne
         * qui en fait signer six chaque jour de terrain.
         */
        $nb_days = max(1, count($all_days));

        PluginStatsSignature::tiles([
            [
                'label'   => __('Bons signés', 'stats'),
                'value'   => (string) $total,
                'color'   => 'green',
                'icon'    => 'ti ti-circle-check',
                'tooltip' => __('Bons de livraison signés sur la période', 'stats'),
            ],
            [
                'label'   => __('Techniciens', 'stats'),
                'value'   => (string) count($rows),
                'color'   => 'azure',
                'icon'    => 'ti ti-users',
                'tooltip' => __('Techniciens ayant fait signer au moins un bon', 'stats'),
            ],
            [
                'label'   => __('Par jour travaillé', 'stats'),
                'value'   => (string) round($total / $nb_days, 1),
                'color'   => 'primary',
                'icon'    => 'ti ti-calendar-stats',
                'tooltip' => sprintf(
                    __('Moyenne sur les %d jours où au moins un bon a été signé', 'stats'),
                    $nb_days
                ),
            ],
            [
                'label'   => __('Par mois', 'stats'),
                'value'   => (string) round($total / $nb_months, 1),
                'color'   => 'indigo',
                'icon'    => 'ti ti-chart-bar',
                'tooltip' => sprintf(__('Moyenne mensuelle sur les %d mois de la période', 'stats'), $nb_months),
            ],
            [
                'label'   => sprintf(__('En retard (+%d j)', 'stats'), self::LATE_DAYS),
                'value'   => (string) $late,
                'color'   => $late > 0 ? 'red' : 'secondary',
                'icon'    => 'ti ti-alert-triangle',
                'tooltip' => sprintf(
                    __('Bons de livraison non signés depuis plus de %d jours, toutes périodes confondues', 'stats'),
                    self::LATE_DAYS
                ),
                'url'     => $CFG_GLPI['root_doc'] . '/plugins/gestion/front/survey.php?reset=reset'
                    . '&criteria[0][link]=AND&criteria[0][field]=5'
                    . '&criteria[0][searchtype]=equals&criteria[0][value]=0'
                    . '&criteria[1][link]=AND&criteria[1][field]=6'
                    . '&criteria[1][searchtype]=lessthan&criteria[1][value]='
                    . urlencode(date('Y-m-d', time() - (self::LATE_DAYS * DAY_TIMESTAMP))),
            ],
        ]);

        if (count($rows) === 0) {
            PluginStatsSignature::emptyState(
                __('Aucun bon de livraison signé sur cette période. Élargissez la période ou les filtres.', 'stats')
            );
            return;
        }

        // ---- Tableau par technicien -----------------------------------------
        $list = $CFG_GLPI['root_doc'] . '/plugins/gestion/front/survey.php';

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'>" . __('Par technicien', 'stats')
            . $helpers['infoIcon'](__("Bons de livraison signés sur la période, par celui qui les a fait signer. Un bon peut avoir été signé sans technicien identifié : il apparaît alors sur la ligne « Non attribué ».", 'stats'))
            . "</h3></div>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm table-hover card-table'>";
        echo "<thead><tr>";
        echo "<th>" . __('Technicien', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Bons signés', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Jours actifs', 'stats')
            . $helpers['infoIcon'](__('Nombre de journées distinctes où il a fait signer au moins un bon', 'stats')) . "</th>";
        echo "<th class='text-end'>" . __('Par jour', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Par mois', 'stats') . "</th>";
        echo "<th>" . __('Dernier bon', 'stats') . "</th>";
        echo "</tr></thead><tbody>";

        uasort($rows, static fn($a, $b) => $b['nb'] <=> $a['nb']);

        foreach ($rows as $uid => $r) {
            $days = max(1, count($r['days']));

            if ($uid > 0) {
                // Option de recherche 9 = technicien, dans la liste des BL.
                $url  = $list . '?reset=reset'
                    . '&criteria[0][link]=AND&criteria[0][field]=9'
                    . '&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid;
                $name = "<a href='" . htmlescape($url) . "'>" . htmlescape(getUserName($uid)) . "</a>";
            } else {
                $name = "<span class='text-muted'>" . __('Non attribué', 'stats') . "</span>"
                    . $helpers['infoIcon'](__("Bons importés automatiquement, ou signés sur tablette avec un nom de technicien que le plugin n'a pas su rapprocher d'un compte GLPI", 'stats'));
            }

            echo "<tr>";
            echo "<td>" . $name . "</td>";
            echo "<td class='text-end fw-bold'>" . (int) $r['nb'] . "</td>";
            echo "<td class='text-end'>" . count($r['days']) . "</td>";
            echo "<td class='text-end'>" . round($r['nb'] / $days, 1) . "</td>";
            echo "<td class='text-end'>" . round($r['nb'] / $nb_months, 1) . "</td>";
            echo "<td>" . htmlescape($r['last'] !== '' ? Html::convDateTime($r['last']) : '—') . "</td>";
            echo "</tr>";
        }

        echo "</tbody></table></div></div>";

        // ---- Courbe mensuelle ------------------------------------------------
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<div class='text-muted small mb-2'>" . __('Bons signés par mois', 'stats')
            . $helpers['infoIcon'](__("Nombre de bons de livraison signés, mois par mois. Les mois sans aucune signature apparaissent à zéro : un creux se voit, il n'est pas masqué.", 'stats'))
            . "</div>";
        echo "<div id='stats_gestion_months' style='height:260px'></div>";
        echo "</div></div>";

        PluginStatsSignature::charts([
            'stats_gestion_months' => [
                'type'   => 'bar',
                'labels' => array_map([PluginStatsSignature::class, 'monthLabel'], $months),
                'data'   => array_values($by_month),
            ],
        ]);

        self::renderDetail($DB, $where, $helpers);
    }

    /** Derniers bons signés : qui, quand, quel bon, quel signataire. */
    private static function renderDetail(DBmysql $DB, array $where, array $helpers): void
    {
        global $CFG_GLPI;

        $limit = 30;

        // `tech_ext` est créée à chaud par le plugin Gestion, hors migration :
        // son absence est un cas normal sur une installation qui n'a jamais servi
        // la signature sur tablette.
        $has_tech_ext = $DB->fieldExists(self::TABLE, 'tech_ext');

        $fields = [
            self::TABLE . '.doc_date',
            self::TABLE . '.users_id',
            self::TABLE . '.bl',
            self::TABLE . '.tickets_id',
            self::TABLE . '.users_ext',
        ];
        if ($has_tech_ext) {
            $fields[] = self::TABLE . '.tech_ext';
        }

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'>"
            . sprintf(__('Les %d derniers bons signés', 'stats'), $limit)
            . $helpers['infoIcon'](__("Le détail de ce que résume le tableau ci-dessus : chaque bon signé sur la période, du plus récent au plus ancien, avec le nom saisi par le client au moment de signer.", 'stats'))
            . "</h3></div>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm table-hover card-table'>";
        echo "<thead><tr>";
        echo "<th>" . __('Date', 'stats') . "</th>";
        echo "<th>" . __('Technicien', 'stats') . "</th>";
        echo "<th>" . __('Bon de livraison', 'stats') . "</th>";
        echo "<th>" . __('Ticket', 'stats') . "</th>";
        echo "<th>" . __('Signataire', 'stats') . "</th>";
        echo "</tr></thead><tbody>";

        $nb = 0;
        foreach ($DB->request([
            'SELECT' => $fields,
            'FROM'   => self::TABLE,
            'WHERE'  => $where,
            'ORDER'  => [self::TABLE . '.doc_date DESC'],
            'LIMIT'  => $limit,
        ]) as $row) {
            $nb++;

            $uid  = (int) ($row['users_id'] ?? 0);
            $tech = $uid > 0
                ? getUserName($uid)
                : trim((string) ($row['tech_ext'] ?? ''));
            if ($tech === '') {
                $tech = __('Non attribué', 'stats');
            }

            $ticket_id = (int) $row['tickets_id'];
            $ticket    = $ticket_id > 0
                ? "<a href='" . htmlescape($CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticket_id) . "'>"
                    . htmlescape(sprintf('#%07d', $ticket_id)) . "</a>"
                : "<span class='text-muted'>—</span>";

            echo "<tr>";
            echo "<td>" . htmlescape(Html::convDateTime((string) $row['doc_date'])) . "</td>";
            echo "<td>" . htmlescape($tech) . "</td>";
            echo "<td>" . htmlescape((string) $row['bl']) . "</td>";
            echo "<td>" . $ticket . "</td>";
            echo "<td>" . htmlescape((string) $row['users_ext']) . "</td>";
            echo "</tr>";
        }

        if ($nb === 0) {
            echo "<tr><td colspan='5' class='text-muted text-center py-3'>"
                . __('Aucun bon signé sur cette période.', 'stats') . "</td></tr>";
        }

        echo "</tbody></table></div></div>";
    }
}
