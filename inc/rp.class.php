<?php

/**
 * Onglet « Stats rapports » — signatures produites via le plugin RP.
 *
 * Répond à une question de terrain : qui fait signer ses rapports, et qui
 * travaille sans en produire. Un ticket sur lequel un technicien a écrit des
 * tâches mais qui ne porte aucun rapport signé est une intervention sans trace
 * opposable au client — c'est exactement ce que la colonne « Couverture » met en
 * évidence.
 *
 * Ne s'affiche que si le plugin RP est actif : cette classe n'est jamais
 * instanciée autrement (cf. front/stats.php).
 */
class PluginStatsRp
{
    /** Vue, telle qu'attendue dans l'URL et par les filtres favoris. */
    public const VIEW = 'rp';

    private const TABLE = 'glpi_plugin_rp_cridetails';

    /**
     * Types de rapport retenus.
     *
     * Le type 2 (hotline) est ÉCARTÉ : l'export massif du plugin RP écrit ses
     * lignes avec `type = 2` en dur et les crédite à celui qui lance l'export.
     * Un passage en masse sur 300 tickets fabriquerait donc 300 « rapports » pour
     * une seule personne, à la même seconde. Le signal n'est pas exploitable.
     */
    private const TYPES = [0, 1, 3];

    /** Types portant une signature CLIENT (le 3 inscrit le nom du technicien). */
    private const TYPES_CLIENT = [0, 1];

    private static function typeLabel(int $type): string
    {
        $labels = [
            0 => __('Fiche de prise en charge', 'stats'),
            1 => __("Rapport d'intervention", 'stats'),
            3 => __("Rapport d'atelier", 'stats'),
        ];
        return $labels[$type] ?? (string) $type;
    }

    /**
     * @param array $helpers closures partagées de front/stats.php :
     *                       openFilterCard, closeFilterCard, favoritesBar,
     *                       infoIcon, expandEntityScope, normalizeIntList
     */
    public static function show(array $helpers): void
    {
        global $DB, $CFG_GLPI;

        $period   = PluginStatsSignature::period(self::VIEW);
        $entities = $helpers['normalizeIntList']($_GET[self::VIEW . '_entities_id'] ?? []);
        $users    = $helpers['normalizeIntList']($_GET[self::VIEW . '_users_id'] ?? []);

        PluginStatsSignature::filterForm(self::VIEW, $helpers, [
            'entities' => $entities,
            'users'    => $users,
            'begin'    => $period['begin'],
            'end'      => $period['end'],
        ]);

        /*
         * Bornes élargies à la journée entière : la borne de fin est une date
         * seule, et un rapport signé à 16 h le dernier jour serait sinon exclu.
         */
        $from = $period['begin'] . ' 00:00:00';
        $to   = $period['end']   . ' 23:59:59';

        $where = [
            self::TABLE . '.type' => self::TYPES,
            self::TABLE . '.date' => ['>=', $from],
        ] + PluginStatsSignature::entityCriteria(self::TABLE, $entities, $helpers['expandEntityScope']);
        $where[] = [self::TABLE . '.date' => ['<=', $to]];

        if (count($users) > 0) {
            $where[self::TABLE . '.users_id'] = $users;
        }

        /*
         * « Signé par le client » : même règle que la liste des rapports du
         * plugin RP — un type client ET un nom de signataire renseigné. Le nom
         * est le seul témoin persistant : l'image de la signature n'est jamais
         * conservée en base, elle part directement dans le PDF.
         */
        $signedWhere = $where;
        $signedWhere[self::TABLE . '.type'] = self::TYPES_CLIENT;
        $signedWhere['NOT'] = [self::TABLE . '.nameclient' => null];
        $signedWhere[self::TABLE . '.nameclient'] = ['<>', ''];

        /*
         * Le tiret n'est pas une signature.
         *
         * L'API de génération du plugin RP écrit `'-'` quand aucun nom de
         * signataire ne lui est fourni : la ligne ressemble à un rapport signé
         * sans que personne n'ait rien signé. La liste des rapports du plugin RP
         * les compte encore — pas ces statistiques, et un écart de quelques
         * unités entre les deux écrans vient de là.
         *
         * Clause SÉPARÉE, à index numérique : réunir deux conditions sur
         * `nameclient` dans un même tableau associatif en aurait écrasé une.
         */
        $signedWhere[] = [self::TABLE . '.nameclient' => ['<>', '-']];

        try {
            $rows = self::aggregate($DB, $signedWhere);
            $work = self::workload($DB, $period, $entities, $users, $helpers);
        } catch (\Throwable $e) {
            Toolbox::logInFile('plugin-stats', 'Stats RP : ' . $e->getMessage() . "\n");
            PluginStatsSignature::emptyState(
                __("Les statistiques n'ont pas pu être calculées. Le détail a été journalisé.", 'stats')
            );
            return;
        }

        self::render($rows, $work, $period, $helpers, $DB, $signedWhere, $CFG_GLPI);
    }

    /**
     * Rapports signés, regroupés par technicien.
     *
     * @return array<int, array{users_id:int, nb:int, last:string, by_type:array<int,int>, by_month:array<string,int>}>
     */
    private static function aggregate(DBmysql $DB, array $where): array
    {
        $rows = [];

        foreach ($DB->request([
            'SELECT' => [
                self::TABLE . '.users_id',
                self::TABLE . '.type',
                self::TABLE . '.date',
                self::TABLE . '.id_ticket',
            ],
            'FROM'  => self::TABLE,
            'WHERE' => $where,
        ]) as $row) {
            $uid = (int) $row['users_id'];
            if (!isset($rows[$uid])) {
                $rows[$uid] = [
                    'users_id' => $uid,
                    'nb'       => 0,
                    'last'     => '',
                    'by_type'  => [],
                    'by_month' => [],
                    'tickets'  => [],
                ];
            }

            $type  = (int) $row['type'];
            $date  = (string) $row['date'];
            $month = substr($date, 0, 7);

            $rows[$uid]['nb']++;
            $rows[$uid]['by_type'][$type]   = ($rows[$uid]['by_type'][$type] ?? 0) + 1;
            $rows[$uid]['by_month'][$month] = ($rows[$uid]['by_month'][$month] ?? 0) + 1;
            $rows[$uid]['tickets'][(int) $row['id_ticket']] = true;

            if ($date > $rows[$uid]['last']) {
                $rows[$uid]['last'] = $date;
            }
        }

        return $rows;
    }

    /**
     * Charge de travail par technicien : les tickets sur lesquels il a écrit une
     * tâche, et lesquels ont fini par porter un rapport signé.
     *
     * C'est l'AUTEUR de la tâche qui fait foi, choix assumé : c'est la trace du
     * travail réellement effectué, alors que l'attribution d'un ticket peut
     * n'avoir jamais été mise à jour. Un ticket travaillé à plusieurs compte donc
     * pour chacun — c'est voulu, chacun aurait pu produire le rapport.
     *
     * @return array<int, array{worked:int, covered:int, delay_sum:float, delay_nb:int}>
     */
    private static function workload(
        DBmysql $DB,
        array $period,
        array $entities,
        array $users,
        array $helpers
    ): array {
        $from = $period['begin'] . ' 00:00:00';
        $to   = $period['end']   . ' 23:59:59';

        /*
         * Le filtre « tâches publiques uniquement » du plugin RP est respecté :
         * s'il est actif, une tâche privée n'est pas un travail dont on attend un
         * rapport, et la compter ferait chuter la couverture sans raison.
         */
        $only_public = false;
        if (class_exists('PluginRpConfig')) {
            $rp_config   = PluginRpConfig::getInstance();
            $only_public = (int) ($rp_config->fields['use_publictask'] ?? 0) === 1;
        }

        $where = [
            'glpi_tickettasks.date' => ['>=', $from],
        ] + PluginStatsSignature::entityCriteria('glpi_tickets', $entities, $helpers['expandEntityScope']);
        $where[] = ['glpi_tickettasks.date' => ['<=', $to]];

        if ($only_public) {
            $where['glpi_tickettasks.is_private'] = 0;
        }
        if (count($users) > 0) {
            $where['glpi_tickettasks.users_id'] = $users;
        }

        /*
         * La tâche de livraison est écartée : elle est créée par le rapport
         * d'atelier lui-même, au nom de celui qui l'a produit. La compter comme
         * du travail à couvrir reviendrait à exiger un rapport pour un rapport.
         */
        if (class_exists('PluginRpTicketActions')) {
            $where['NOT'] = [
                'glpi_tickettasks.content' => ['LIKE', '%' . PluginRpTicketActions::LIVRAISON_MARQUEUR . '%'],
            ];
        }

        // Tickets travaillés, et date de la dernière tâche de chacun.
        $worked = [];
        foreach ($DB->request([
            'SELECT' => [
                'glpi_tickettasks.users_id',
                'glpi_tickettasks.tickets_id',
                'glpi_tickettasks.date',
            ],
            'FROM'       => 'glpi_tickettasks',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => ['glpi_tickettasks' => 'tickets_id', 'glpi_tickets' => 'id'],
                ],
            ],
            'WHERE' => $where,
        ]) as $row) {
            $uid = (int) $row['users_id'];
            $tid = (int) $row['tickets_id'];
            if ($uid <= 0 || $tid <= 0) {
                continue;
            }
            $date = (string) $row['date'];
            if (!isset($worked[$uid][$tid]) || $date > $worked[$uid][$tid]) {
                $worked[$uid][$tid] = $date;
            }
        }

        if (count($worked) === 0) {
            return ['users' => [], 'tickets_worked' => 0, 'tickets_covered' => 0];
        }

        /*
         * Rapports signés de ces tickets — SANS filtre de période ni de
         * technicien : un rapport signé le mois suivant couvre bien le travail,
         * et un rapport produit par un collègue couvre le ticket tout autant.
         * Restreindre ici aurait fait passer pour des manquements des dossiers
         * parfaitement en règle.
         */
        $ticket_ids = [];
        foreach ($worked as $tickets) {
            foreach (array_keys($tickets) as $tid) {
                $ticket_ids[$tid] = true;
            }
        }

        $signed = [];
        foreach ($DB->request([
            'SELECT' => [self::TABLE . '.id_ticket', self::TABLE . '.date'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                self::TABLE . '.id_ticket'  => array_keys($ticket_ids),
                self::TABLE . '.type'       => self::TYPES_CLIENT,
                'NOT'                       => [self::TABLE . '.nameclient' => null],
                self::TABLE . '.nameclient' => ['<>', ''],
            ],
        ]) as $row) {
            $tid  = (int) $row['id_ticket'];
            $date = (string) $row['date'];
            // Le PREMIER rapport signé fait foi pour le délai.
            if (!isset($signed[$tid]) || $date < $signed[$tid]) {
                $signed[$tid] = $date;
            }
        }

        $result = [];
        foreach ($worked as $uid => $tickets) {
            $covered   = 0;
            $delay_sum = 0.0;
            $delay_nb  = 0;

            foreach ($tickets as $tid => $last_task) {
                if (!isset($signed[$tid])) {
                    continue;
                }
                $covered++;

                $delta = strtotime($signed[$tid]) - strtotime($last_task);
                // Un rapport signé AVANT la dernière tâche du ticket (ajoutée
                // après coup) donnerait un délai négatif, dépourvu de sens.
                if ($delta >= 0) {
                    $delay_sum += $delta / DAY_TIMESTAMP;
                    $delay_nb++;
                }
            }

            $result[$uid] = [
                'worked'    => count($tickets),
                'covered'   => $covered,
                'delay_sum' => $delay_sum,
                'delay_nb'  => $delay_nb,
            ];
        }

        /*
         * Totaux généraux sur des tickets DISTINCTS.
         *
         * Additionner les colonnes du tableau aurait compté deux fois un ticket
         * travaillé par deux techniciens : chacun le porte légitimement dans SA
         * ligne, mais l'entreprise n'a qu'un dossier à couvrir. La tuile
         * « Couverture » annonçait donc une part de paires technicien-ticket, pas
         * une part de dossiers — un chiffre plus bas que la réalité dès qu'on
         * travaille à plusieurs.
         */
        $tickets_covered = 0;
        foreach (array_keys($ticket_ids) as $tid) {
            if (isset($signed[$tid])) {
                $tickets_covered++;
            }
        }

        return [
            'users'           => $result,
            'tickets_worked'  => count($ticket_ids),
            'tickets_covered' => $tickets_covered,
        ];
    }

    /** Techniciens n'ayant enregistré aucun paraphe : ils ne PEUVENT pas signer. */
    private static function withoutSignature(DBmysql $DB, array $users_id): array
    {
        if (count($users_id) === 0 || !$DB->tableExists('glpi_plugin_rp_signtech')) {
            return [];
        }

        $with = [];
        foreach ($DB->request([
            'SELECT' => ['user_id'],
            'FROM'   => 'glpi_plugin_rp_signtech',
            'WHERE'  => ['user_id' => $users_id, 'NOT' => ['seing' => null], 'seing' => ['<>', '']],
        ]) as $row) {
            $with[(int) $row['user_id']] = true;
        }

        return array_values(array_diff($users_id, array_keys($with)));
    }

    private static function render(
        array $rows,
        array $work,
        array $period,
        array $helpers,
        DBmysql $DB,
        array $signedWhere,
        array $CFG_GLPI
    ): void {
        $months     = PluginStatsSignature::monthRange($period['begin'], $period['end']);
        $nb_months  = max(1, count($months));

        // Chiffres par technicien, et totaux calculés sur des tickets distincts.
        $per_user      = $work['users'] ?? [];
        $total_worked  = (int) ($work['tickets_worked'] ?? 0);
        $total_covered = (int) ($work['tickets_covered'] ?? 0);

        // Union des techniciens : ceux qui ont signé, et ceux qui ont travaillé
        // sans rien signer — ces derniers sont précisément ceux qu'on cherche.
        $all_users = array_unique(array_merge(array_keys($rows), array_keys($per_user)));
        sort($all_users);

        $total_signed = 0;
        $by_month     = array_fill_keys($months, 0);
        $by_type      = [];

        foreach ($rows as $r) {
            $total_signed += $r['nb'];
            foreach ($r['by_month'] as $m => $n) {
                if (array_key_exists($m, $by_month)) {
                    $by_month[$m] += $n;
                }
            }
            foreach ($r['by_type'] as $t => $n) {
                $by_type[$t] = ($by_type[$t] ?? 0) + $n;
            }
        }

        // ---- Tuiles ----------------------------------------------------------
        $multi_doc_note = '';
        if (class_exists('PluginRpConfig')
            && (int) (PluginRpConfig::getInstance()->fields['multi_doc'] ?? 1) === 0) {
            /*
             * Réglage « un seul document par ticket » : régénérer un rapport met
             * la ligne à jour au lieu d'en créer une seconde. Le chiffre compte
             * alors des rapports EXISTANTS, pas des générations. Le dire vaut
             * mieux qu'afficher un total que personne ne saurait interpréter.
             */
            $multi_doc_note = __("Le plugin RP est réglé sur un seul document par ticket et par type : ce nombre compte les rapports existants, pas les régénérations.", 'stats');
        }

        PluginStatsSignature::tiles([
            [
                'label'   => __('Rapports signés', 'stats'),
                'value'   => (string) $total_signed,
                'color'   => 'green',
                'icon'    => 'ti ti-signature',
                'tooltip' => trim(__('Rapports portant la signature du client sur la période', 'stats')
                    . ($multi_doc_note !== '' ? ' — ' . $multi_doc_note : '')),
            ],
            [
                'label'   => __('Techniciens actifs', 'stats'),
                'value'   => (string) count($all_users),
                'color'   => 'azure',
                'icon'    => 'ti ti-users',
                'tooltip' => __('Techniciens ayant signé un rapport ou travaillé sur un ticket', 'stats'),
            ],
            [
                'label'   => __('Par mois', 'stats'),
                'value'   => (string) round($total_signed / $nb_months, 1),
                'color'   => 'primary',
                'icon'    => 'ti ti-calendar-stats',
                'tooltip' => sprintf(__('Moyenne mensuelle sur les %d mois de la période', 'stats'), $nb_months),
            ],
            [
                'label'   => __('Couverture', 'stats'),
                'value'   => PluginStatsSignature::percent($total_covered, $total_worked),
                'color'   => PluginStatsSignature::coverageColor($total_covered, $total_worked),
                'icon'    => 'ti ti-shield-check',
                'tooltip' => __("Part des tickets travaillés qui ont reçu un rapport signé. C'est la mesure de ce qui manque.", 'stats'),
            ],
        ]);

        if (count($all_users) === 0) {
            PluginStatsSignature::emptyState(
                __('Aucun rapport ni aucune tâche sur cette période. Élargissez la période ou les filtres.', 'stats')
            );
            return;
        }

        // ---- Tableau par technicien -----------------------------------------
        $rp_list = $CFG_GLPI['root_doc'] . '/plugins/rp/front/cridetail.php';

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'>" . __('Par technicien', 'stats')
            . $helpers['infoIcon'](__("Un technicien apparaît ici dès qu'il a signé un rapport OU travaillé sur un ticket. Ceux qui ont travaillé sans rien signer sont donc visibles, avec une couverture basse.", 'stats'))
            . "</h3></div>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm table-hover card-table'>";
        echo "<thead><tr>";
        echo "<th>" . __('Technicien', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Signés', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Par mois', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Tickets travaillés', 'stats')
            . $helpers['infoIcon'](__("Tickets sur lesquels ce technicien a écrit au moins une tâche", 'stats')) . "</th>";
        echo "<th class='text-end'>" . __('Couverture', 'stats')
            . $helpers['infoIcon'](__("Part de ses tickets travaillés qui portent un rapport signé, quel qu'en soit l'auteur", 'stats')) . "</th>";
        echo "<th class='text-end'>" . __('Délai moyen', 'stats')
            . $helpers['infoIcon'](__("Temps écoulé entre sa dernière tâche sur le ticket et le rapport signé", 'stats')) . "</th>";
        echo "<th>" . __('Dernier rapport', 'stats') . "</th>";
        echo "</tr></thead><tbody>";

        // Le plus fort volume d'abord, puis les couvertures les plus basses.
        usort($all_users, static function ($a, $b) use ($rows, $per_user) {
            $na = $rows[$a]['nb'] ?? 0;
            $nb = $rows[$b]['nb'] ?? 0;
            if ($na !== $nb) {
                return $nb <=> $na;
            }
            return ($per_user[$b]["worked"] ?? 0) <=> ($per_user[$a]["worked"] ?? 0);
        });

        foreach ($all_users as $uid) {
            $signed  = $rows[$uid]['nb'] ?? 0;
            $worked  = $per_user[$uid]['worked'] ?? 0;
            $covered = $per_user[$uid]['covered'] ?? 0;
            $delay   = ($per_user[$uid]['delay_nb'] ?? 0) > 0
                ? $per_user[$uid]['delay_sum'] / $per_user[$uid]['delay_nb']
                : null;

            // Option de recherche 8 = technicien, dans la liste des rapports RP.
            $url = $rp_list . '?reset=reset'
                . '&criteria[0][link]=AND&criteria[0][field]=8'
                . '&criteria[0][searchtype]=equals&criteria[0][value]=' . $uid;

            echo "<tr>";
            echo "<td><a href='" . htmlescape($url) . "'>" . htmlescape(getUserName($uid)) . "</a></td>";
            echo "<td class='text-end fw-bold'>" . $signed . "</td>";
            echo "<td class='text-end'>" . round($signed / $nb_months, 1) . "</td>";
            echo "<td class='text-end'>" . $worked . "</td>";
            echo "<td class='text-end'><span class='badge bg-"
                . PluginStatsSignature::coverageColor($covered, $worked) . "-lt'>"
                . PluginStatsSignature::percent($covered, $worked) . "</span></td>";
            echo "<td class='text-end'>" . htmlescape(PluginStatsSignature::days($delay)) . "</td>";
            echo "<td>" . htmlescape(($rows[$uid]['last'] ?? '') !== ''
                ? Html::convDateTime($rows[$uid]['last'])
                : '—') . "</td>";
            echo "</tr>";
        }

        echo "</tbody></table></div></div>";

        // ---- Techniciens sans paraphe ---------------------------------------
        $no_sig = self::withoutSignature($DB, $all_users);
        if (count($no_sig) > 0) {
            echo "<div class='alert alert-warning'>";
            echo "<div class='d-flex align-items-start'>";
            echo "<i class='ti ti-writing-off me-2 mt-1'></i>";
            echo "<div>";
            echo "<div class='fw-bold'>" . __('Techniciens sans paraphe enregistré', 'stats') . "</div>";
            echo "<div class='small'>"
                . __("Ils ne peuvent produire aucun rapport signé tant qu'ils n'ont pas enregistré leur signature dans le plugin RP. Un taux de couverture à zéro vient souvent de là, et non d'un oubli.", 'stats')
                . "</div>";
            $names = [];
            foreach ($no_sig as $uid) {
                $names[] = htmlescape(getUserName($uid));
            }
            echo "<div class='mt-2'>" . implode(', ', $names) . "</div>";
            echo "</div></div></div>";
        }

        // ---- Graphiques ------------------------------------------------------
        echo "<div class='row g-3 mb-3'>";
        echo "<div class='col-md-8'><div class='card h-100'><div class='card-body'>";
        echo "<div class='text-muted small mb-2'>" . __('Rapports signés par mois', 'stats')
            . $helpers['infoIcon'](__("Nombre de rapports signés par le client, mois par mois. Les mois sans aucune signature apparaissent à zéro : un creux se voit, il n'est pas masqué.", 'stats'))
            . "</div>";
        echo "<div id='stats_rp_months' style='height:260px'></div>";
        echo "</div></div></div>";
        echo "<div class='col-md-4'><div class='card h-100'><div class='card-body'>";
        echo "<div class='text-muted small mb-2'>" . __('Répartition par type', 'stats')
            . $helpers['infoIcon'](__("Part de chaque document dans les signatures de la période : fiche de prise en charge, rapport d'intervention, rapport d'atelier. Le rapport hotline en est absent, sa trace n'étant pas fiable.", 'stats'))
            . "</div>";
        echo "<div id='stats_rp_types' style='height:260px'></div>";
        echo "</div></div></div>";
        echo "</div>";

        $pie = [];
        foreach ($by_type as $type => $nb) {
            $pie[] = ['name' => self::typeLabel((int) $type), 'value' => $nb];
        }

        PluginStatsSignature::charts([
            'stats_rp_months' => [
                'type'   => 'line',
                'labels' => array_map([PluginStatsSignature::class, 'monthLabel'], $months),
                'data'   => array_values($by_month),
            ],
            'stats_rp_types' => [
                'type' => 'pie',
                'data' => $pie,
            ],
        ]);

        self::renderDetail($DB, $signedWhere, $helpers);
    }

    /** Derniers rapports signés : qui, quand, quoi, pour quel ticket. */
    private static function renderDetail(DBmysql $DB, array $where, array $helpers): void
    {
        global $CFG_GLPI;

        $limit = 30;

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'>"
            . sprintf(__('Les %d derniers rapports signés', 'stats'), $limit)
            . $helpers['infoIcon'](__("Le détail de ce que résume le tableau ci-dessus : chaque rapport signé sur la période, du plus récent au plus ancien, avec le nom saisi par le client au moment de signer.", 'stats'))
            . "</h3></div>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm table-hover card-table'>";
        echo "<thead><tr>";
        echo "<th>" . __('Date', 'stats') . "</th>";
        echo "<th>" . __('Technicien', 'stats') . "</th>";
        echo "<th>" . __('Type', 'stats') . "</th>";
        echo "<th>" . __('Ticket', 'stats') . "</th>";
        echo "<th>" . __('Signataire', 'stats') . "</th>";
        echo "</tr></thead><tbody>";

        $nb = 0;
        foreach ($DB->request([
            'SELECT' => [
                self::TABLE . '.date',
                self::TABLE . '.users_id',
                self::TABLE . '.type',
                self::TABLE . '.id_ticket',
                self::TABLE . '.nameclient',
            ],
            'FROM'  => self::TABLE,
            'WHERE' => $where,
            'ORDER' => [self::TABLE . '.date DESC'],
            'LIMIT' => $limit,
        ]) as $row) {
            $nb++;
            $ticket_id  = (int) $row['id_ticket'];
            $ticket_url = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticket_id;

            echo "<tr>";
            echo "<td>" . htmlescape(Html::convDateTime((string) $row['date'])) . "</td>";
            echo "<td>" . htmlescape(getUserName((int) $row['users_id'])) . "</td>";
            echo "<td>" . htmlescape(self::typeLabel((int) $row['type'])) . "</td>";
            echo "<td><a href='" . htmlescape($ticket_url) . "'>"
                . htmlescape(sprintf('#%07d', $ticket_id)) . "</a></td>";
            echo "<td>" . htmlescape((string) $row['nameclient']) . "</td>";
            echo "</tr>";
        }

        if ($nb === 0) {
            echo "<tr><td colspan='5' class='text-muted text-center py-3'>"
                . __('Aucun rapport signé sur cette période.', 'stats') . "</td></tr>";
        }

        echo "</tbody></table></div></div>";
    }
}
