<?php

include('../../../inc/includes.php');
/** @var array $CFG_GLPI */
/** @var DBmysql $DB */
global $CFG_GLPI, $DB;

Session::checkRight(PluginStatsProfile::$rightname, PluginStatsProfile::RIGHT_READ);

$plugin = new Plugin();
$creditPluginActive = $plugin->isInstalled('credit') && $plugin->isActivated('credit');
$creditAlertActive = $plugin->isInstalled('creditalert') && $plugin->isActivated('creditalert');
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

$satisfactionPluginActive = $plugin->isInstalled('satisfactionclient') && $plugin->isActivated('satisfactionclient');
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
$satisfactionEnabled = $satisfactionPluginActive
    && $satisfactionQuestionsLoaded
    && $DB->tableExists('glpi_plugin_satisfactionclient_answers');

$view = $_GET['view'] ?? ($creditEnabled ? 'credits' : 'tickets');
if (!$creditEnabled && $view === 'credits') {
    $view = 'tickets';
}
if (!$satisfactionEnabled && $view === 'satisfaction') {
    $view = $creditEnabled ? 'credits' : 'tickets';
}
$runStats = isset($_GET['run']) ? (int) $_GET['run'] : 1;
$dateBegin = $_GET['date_begin'] ?? '';
$dateEnd = $_GET['date_end'] ?? '';
$dateStart = $dateBegin !== '' ? $dateBegin . ' 00:00:00' : '';
$dateStop = $dateEnd !== '' ? $dateEnd . ' 23:59:59' : '';
$isAjaxRequest = isset($_GET['ajax']) && $_GET['ajax'] !== '';
$isExportRequest = ($view === 'tickets' && isset($_GET['export']) && $_GET['export'] !== '')
    || ($view === 'satisfaction' && isset($_GET['export']) && $_GET['export'] !== '');
$infoIcon = static function (string $text): string {
    if ($text === '') {
        return '';
    }
    return " <span class='text-info' data-bs-toggle='tooltip' title='"
        . htmlescape($text) . "'><i class='ti ti-info-circle-filled'></i></span>";
};

if (!$isAjaxRequest && !$isExportRequest) {
    echo "<style>
.stats-filters .select2-container--default .select2-selection--multiple {
  min-height: 38px;
  padding: 4px 8px;
}
.stats-filters .select2-container--default.select2-container--focus .select2-selection--multiple {
  min-height: 38px;
}
.stats-filters .select2-container--default .select2-selection--multiple .select2-selection__rendered {
  min-height: 30px;
}
.stats-filters .select2-container--default .select2-selection--multiple .select2-selection__rendered li {
  margin-top: 4px;
}
.stats-filters .select2-container {
  width: 100% !important;
}
.stats-filters .select2-selection__rendered {
  min-height: 30px;
}
</style>";
    Html::header(__('Stats', 'stats'), $_SERVER['PHP_SELF'], 'tools', 'stats');
    echo "<div class='card mb-3'><div class='card-body'>";
    echo "<ul class='nav nav-tabs' role='tablist'>";
    $tabs = [];
    if ($creditEnabled) {
        $tabs['credits'] = __('Stats credits', 'stats');
    }
    $tabs['tickets'] = __('Stats tickets', 'stats');
    if ($satisfactionEnabled) {
        $tabs['satisfaction'] = __('Satisfaction', 'stats');
    }
    foreach ($tabs as $tabKey => $label) {
        $active = $view === $tabKey ? 'active' : '';
        $url = Html::cleanInputText($CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php?view=' . $tabKey);
        echo "<li class='nav-item' role='presentation'>";
        echo "<a class='nav-link $active' href='$url'>" . $label . "</a>";
        echo "</li>";
    }
    echo "</ul>";
    echo "</div></div>";
}

if ($view === 'tickets') {
    /** @var DBmysql $DB */
    global $DB;
    $listLimit = (int) ($_REQUEST['glpilist_limit'] ?? ($_SESSION['glpilist_limit'] ?? 15));
    if ($listLimit <= 0) {
        $listLimit = 15;
    }
    $start = (int) ($_GET['start'] ?? 0);
    $previousListLimit = $_SESSION['glpilist_limit'] ?? null;
    $_SESSION['glpilist_limit'] = $listLimit;
    $exportType = (string) ($_GET['export'] ?? '');
    $isExport = in_array($exportType, ['tech', 'entity'], true);

    $selectedEntities = $_GET['ticket_entities_id'] ?? [];
    if (!is_array($selectedEntities)) {
        $selectedEntities = [$selectedEntities];
    }
    $selectedEntities = array_values(array_filter(array_map('intval', $selectedEntities)));

    $selectedTechs = $_GET['technicians_id'] ?? [];
    if (!is_array($selectedTechs)) {
        $selectedTechs = [$selectedTechs];
    }
    $selectedTechs = array_values(array_filter(array_map('intval', $selectedTechs)));

    $entityScope = [];
    foreach ($selectedEntities as $entityId) {
        if ($entityId <= 0) {
            continue;
        }
        $sons = getSonsOf('glpi_entities', $entityId);
        if (!is_array($sons)) {
            $sons = [$entityId];
        }
        foreach ($sons as $sonId) {
            $sonId = (int) $sonId;
            if ($sonId > 0) {
                $entityScope[$sonId] = true;
            }
        }
    }
    $entityScope = array_keys($entityScope);

    $taskTable = 'glpi_tickettasks';
    $ticketTable = 'glpi_tickets';
    $where = [
        "$taskTable.actiontime" => ['>', 0],
    ];
    if ($DB->fieldExists($taskTable, 'is_deleted')) {
        $where["$taskTable.is_deleted"] = 0;
    }
    if (!empty($selectedTechs)) {
        $where["$taskTable.users_id_tech"] = $selectedTechs;
    } else {
        $where["$taskTable.users_id_tech"] = ['>', 0];
    }
    if (!empty($entityScope)) {
        $where["$ticketTable.entities_id"] = $entityScope;
    }
    if ($dateStart !== '' || $dateStop !== '') {
        $dateCriteria = [];
        if ($dateStart !== '') {
            $dateCriteria[] = ["$ticketTable.date" => ['>=', $dateStart]];
        }
        if ($dateStop !== '') {
            $dateCriteria[] = ["$ticketTable.date" => ['<=', $dateStop]];
        }
        if (!empty($dateCriteria)) {
            if (!isset($where['AND']) || !is_array($where['AND'])) {
                $where['AND'] = [];
            }
            $where['AND'] = array_merge($where['AND'], $dateCriteria);
        }
    }

    $joins = [
        $ticketTable => [
            'ON' => [
                $ticketTable => 'id',
                $taskTable => 'tickets_id',
            ],
        ],
    ];

    $formatHours = static function (int $seconds): string {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, HOUR_TIMESTAMP);
        $minutes = (int) round(($seconds % HOUR_TIMESTAMP) / 60);
        if ($minutes === 60) {
            $hours++;
            $minutes = 0;
        }
        return sprintf('%d h %02d min', $hours, $minutes);
    };
    $formatCredit = static function ($value) use ($formatHours): string {
        $seconds = (int) round(((float) $value) * 60);
        return $formatHours($seconds);
    };
    $buildModalUrl = static function (array $params) use ($CFG_GLPI): string {
        $base = $CFG_GLPI['root_doc'] . '/plugins/stats/front/tickets.modal.php';
        return $base . '?' . http_build_query($params);
    };
    $isAjax = (($_GET['ajax'] ?? '') === 'tickets');
    $exportType = (string) ($_GET['export'] ?? '');
    $isExport = in_array($exportType, ['tech', 'entity'], true);
    $includeCredit = $creditEnabled;

    if ($isAjax || $isExport) {
        $totalCredit = 0.0;
        $techCreditTotalsMap = [];
        $entityCreditTotalsMap = [];
        if ($includeCredit) {
            $config = PluginCreditalertConfig::getConfig();
            $consumptionTable = $config['consumption_table'] ?? 'glpi_plugin_credit_tickets';
            $fieldTicket = $config['field_ticket'] ?? 'tickets_id';
            $fieldUsed = $config['field_used'] ?? 'consumed';

            $ticketIds = [];
            $creditTaskWhere = $where;
            if (!empty($selectedTechs)) {
                $creditTaskWhere["$taskTable.users_id_tech"] = ['>', 0];
            }
            foreach ($DB->request([
                'SELECT' => [
                    "$ticketTable.id AS ticket_id",
                ],
                'FROM' => $taskTable,
                'LEFT JOIN' => $joins,
                'WHERE' => $creditTaskWhere,
                'GROUPBY' => ["$ticketTable.id"],
            ]) as $row) {
                $ticketId = (int) ($row['ticket_id'] ?? 0);
                if ($ticketId > 0) {
                    $ticketIds[$ticketId] = true;
                }
            }
            $ticketIds = array_keys($ticketIds);
            if (!empty($ticketIds) && $DB->tableExists($consumptionTable)) {
                $creditWhere = [
                    "$consumptionTable.$fieldTicket" => $ticketIds,
                ];
                $hasCreditUsers = $DB->fieldExists($consumptionTable, 'users_id');
                if ($hasCreditUsers && !empty($selectedTechs)) {
                    $creditWhere["$consumptionTable.users_id"] = $selectedTechs;
                }
                $creditRow = $DB->request([
                    'SELECT' => [
                        new QueryExpression("COALESCE(SUM($consumptionTable.$fieldUsed), 0) AS total_credit"),
                    ],
                    'FROM' => $consumptionTable,
                    'WHERE' => $creditWhere,
                ])->current() ?: [];
                $totalCredit = (float) ($creditRow['total_credit'] ?? 0);

                if ($hasCreditUsers) {
                    $creditTechWhere = $creditWhere;
                    if (!empty($selectedTechs)) {
                        $creditTechWhere["$consumptionTable.users_id"] = $selectedTechs;
                    }
                    foreach ($DB->request([
                        'SELECT' => [
                            "$consumptionTable.users_id AS tech_id",
                            new QueryExpression("COALESCE(SUM($consumptionTable.$fieldUsed), 0) AS total_credit"),
                        ],
                        'FROM' => $consumptionTable,
                        'WHERE' => $creditTechWhere,
                        'GROUPBY' => ["$consumptionTable.users_id"],
                    ]) as $row) {
                        $techId = (int) ($row['tech_id'] ?? 0);
                        if ($techId > 0) {
                            $techCreditTotalsMap[$techId] = (float) ($row['total_credit'] ?? 0);
                        }
                    }
                }

                foreach ($DB->request([
                    'SELECT' => [
                        "$ticketTable.entities_id AS entities_id",
                        new QueryExpression("COALESCE(SUM($consumptionTable.$fieldUsed), 0) AS total_credit"),
                    ],
                    'FROM' => $consumptionTable,
                    'LEFT JOIN' => [
                        $ticketTable => [
                            'ON' => [
                                $ticketTable => 'id',
                                $consumptionTable => $fieldTicket,
                            ],
                        ],
                    ],
                    'WHERE' => $creditWhere,
                    'GROUPBY' => ["$ticketTable.entities_id"],
                ]) as $row) {
                    $entityId = (int) ($row['entities_id'] ?? 0);
                    if ($entityId > 0) {
                        $entityCreditTotalsMap[$entityId] = (float) ($row['total_credit'] ?? 0);
                    }
                }
            }
        }

        $techTotalsMap = [];
        $techTicketsMap = [];
        $entityTotalsMap = [];
        foreach ($DB->request([
            'SELECT' => [
                "$taskTable.users_id_tech AS tech_id",
                "$ticketTable.entities_id AS entities_id",
                new QueryExpression("SUM($taskTable.actiontime) AS total_time"),
                new QueryExpression("COUNT(DISTINCT $taskTable.tickets_id) AS ticket_count"),
            ],
            'FROM' => $taskTable,
            'LEFT JOIN' => $joins,
            'WHERE' => $where,
            'GROUPBY' => ["$taskTable.users_id_tech", "$ticketTable.entities_id"],
        ]) as $row) {
            $techId = (int) ($row['tech_id'] ?? 0);
            $entityId = (int) ($row['entities_id'] ?? 0);
            $seconds = (int) ($row['total_time'] ?? 0);
            $ticketCount = (int) ($row['ticket_count'] ?? 0);
            if ($techId > 0) {
                $techTotalsMap[$techId] = ($techTotalsMap[$techId] ?? 0) + $seconds;
                $techTicketsMap[$techId] = ($techTicketsMap[$techId] ?? 0) + $ticketCount;
            }
            if ($entityId > 0) {
                $entityTotalsMap[$entityId] = ($entityTotalsMap[$entityId] ?? 0) + $seconds;
            }
        }
        $entityTicketsMap = [];
        foreach ($DB->request([
            'SELECT' => [
                "$ticketTable.entities_id AS entities_id",
                new QueryExpression("COUNT(DISTINCT $taskTable.tickets_id) AS ticket_count"),
            ],
            'FROM' => $taskTable,
            'LEFT JOIN' => $joins,
            'WHERE' => $where,
            'GROUPBY' => ["$ticketTable.entities_id"],
        ]) as $row) {
            $entityId = (int) ($row['entities_id'] ?? 0);
            if ($entityId > 0) {
                $entityTicketsMap[$entityId] = (int) ($row['ticket_count'] ?? 0);
            }
        }
        arsort($techTotalsMap);
        arsort($entityTotalsMap);

        $techTotals = [];
        foreach ($techTotalsMap as $techId => $seconds) {
            $techTotals[] = [
                'tech_id' => (int) $techId,
                'seconds' => (int) $seconds,
                'ticket_count' => (int) ($techTicketsMap[$techId] ?? 0),
            ];
        }
        $entityTotals = [];
        foreach ($entityTotalsMap as $entityId => $seconds) {
            $entityTotals[] = [
                'entities_id' => (int) $entityId,
                'seconds' => (int) $seconds,
                'ticket_count' => (int) ($entityTicketsMap[$entityId] ?? 0),
            ];
        }

        $techTotalCount = count($techTotals);
        $entityTotalCount = count($entityTotals);
        $techRows = array_slice($techTotals, $start, $listLimit);
        $entityRows = array_slice($entityTotals, $start, $listLimit);
        $pagerParams = $_GET;
        unset($pagerParams['start'], $pagerParams['ajax'], $pagerParams['export']);
        $pagerQuery = http_build_query($pagerParams);
        $pagerTarget = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php';

        $totalSeconds = array_sum($techTotalsMap);

        $techChartData = [];
        foreach ($techTotals as $row) {
            $techId = (int) ($row['tech_id'] ?? 0);
            $name = $techId > 0 ? getUserName($techId) : '';
            $techChartData[] = [
                'name'  => $name,
                'value' => round(((int) $row['seconds']) / HOUR_TIMESTAMP, 2),
            ];
        }
        $entityChartData = [];
        foreach ($entityTotals as $row) {
            $entityId = (int) ($row['entities_id'] ?? 0);
            $label = $entityId > 0
                ? \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(Dropdown::getDropdownName('glpi_entities', $entityId))
                : '';
            $entityChartData[] = [
                'name'  => $label,
                'value' => round(((int) $row['seconds']) / HOUR_TIMESTAMP, 2),
            ];
        }

        if ($isExport) {
            $filename = $exportType === 'tech'
                ? 'stats_temps_techniciens.csv'
                : 'stats_temps_entites.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($exportType === 'tech') {
                $headers = [
                    __('Technicien', 'stats'),
                    __('Tickets', 'stats'),
                    __('Temps tâche', 'stats'),
                ];
                if ($includeCredit) {
                    $headers[] = __('Temps credit', 'stats');
                }
                fputcsv($out, $headers, ';');
                foreach ($techTotals as $row) {
                    $techId = (int) ($row['tech_id'] ?? 0);
                    $name = $techId > 0 ? getUserName($techId) : '';
                    $csvRow = [
                        $name,
                        (string) ((int) ($row['ticket_count'] ?? 0)),
                        $formatHours((int) ($row['seconds'] ?? 0)),
                    ];
                    if ($includeCredit) {
                        $creditValue = (float) ($techCreditTotalsMap[$techId] ?? 0);
                        $creditLabel = $creditValue > 0 ? $formatCredit($creditValue) : '-';
                        $csvRow[] = $creditLabel;
                    }
                    fputcsv($out, $csvRow, ';');
                }
            } else {
                $headers = [
                    __('Entite', 'stats'),
                    __('Tickets', 'stats'),
                    __('Temps', 'stats'),
                ];
                if ($includeCredit) {
                    $headers[] = __('Temps credit', 'stats');
                }
                fputcsv($out, $headers, ';');
                foreach ($entityTotals as $row) {
                    $entityId = (int) ($row['entities_id'] ?? 0);
                    $label = $entityId > 0
                        ? \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(Dropdown::getDropdownName('glpi_entities', $entityId))
                        : '';
                    $csvRow = [
                        $label,
                        (string) ((int) ($row['ticket_count'] ?? 0)),
                        $formatHours((int) ($row['seconds'] ?? 0)),
                    ];
                    if ($includeCredit) {
                        $creditValue = (float) ($entityCreditTotalsMap[$entityId] ?? 0);
                        $creditLabel = $creditValue > 0 ? $formatCredit($creditValue) : '-';
                        $csvRow[] = $creditLabel;
                    }
                    fputcsv($out, $csvRow, ';');
                }
            }
            fclose($out);
            if ($previousListLimit !== null) {
                $_SESSION['glpilist_limit'] = $previousListLimit;
            } else {
                unset($_SESSION['glpilist_limit']);
            }
            exit;
        }

        $buildTable = static function (
            array $rows,
            string $firstHeader,
            string $ticketsHeader,
            callable $rowBuilder,
            int $start,
            int $totalCount,
            string $pagerTarget,
            string $pagerQuery,
            bool $includeCredit
        ): string {
            ob_start();
            if ($totalCount > 0) {
                Html::printPager($start, $totalCount, $pagerTarget, $pagerQuery);
            }
            echo "<div class='table-responsive'>";
            echo "<table class='table table-sm'>";
            echo "<thead><tr>";
            echo "<th>" . $firstHeader . "</th>";
            echo "<th class='text-end'>" . $ticketsHeader . "</th>";
            echo "<th class='text-end'>" . __('Temps tâche', 'stats') . "</th>";
            if ($includeCredit) {
                echo "<th class='text-end'>" . __('Temps credit', 'stats') . "</th>";
            }
            echo "</tr></thead><tbody>";
            if (empty($rows)) {
                $colspan = $includeCredit ? 4 : 3;
                echo "<tr><td colspan='" . $colspan . "' class='text-center text-muted'>"
                    . __('Aucune donnee a afficher.', 'stats') . "</td></tr>";
            } else {
                foreach ($rows as $row) {
                    $rowBuilder($row);
                }
            }
            echo "</tbody></table></div>";
            if ($totalCount > 0) {
                Html::printPager($start, $totalCount, $pagerTarget, $pagerQuery);
            }
            return ob_get_clean();
        };

        $techTableHtml = $buildTable(
            $techRows,
            __('Technicien', 'stats'),
            __('Tickets', 'stats'),
            function (array $row) use ($formatHours, $formatCredit, $techCreditTotalsMap, $buildModalUrl, $dateBegin, $dateEnd, $selectedEntities, $includeCredit) {
                $techId = (int) ($row['tech_id'] ?? 0);
                $name = $techId > 0 ? getUserName($techId) : '';
                $modalParams = [
                    'type'       => 'tech',
                    'id'         => $techId,
                    'date_begin' => $dateBegin,
                    'date_end'   => $dateEnd,
                ];
                if (!empty($selectedEntities)) {
                    $modalParams['entities_id'] = $selectedEntities;
                }
                $modalUrl = $buildModalUrl($modalParams) . '&_in_modal=1';
                $title = sprintf(__('Tickets du technicien %s', 'stats'), $name);
                $link = $name !== ''
                    ? "<a href='#' class='stats-ticket-modal' data-modal-url='"
                        . htmlescape($modalUrl) . "' data-modal-title='" . htmlescape($title) . "'>"
                        . htmlescape($name) . "</a>"
                    : '';
                echo "<tr>";
                echo "<td>" . $link . "</td>";
                echo "<td class='text-end'>" . htmlescape((string) ($row['ticket_count'] ?? 0)) . "</td>";
                echo "<td class='text-end'>" . $formatHours((int) $row['seconds']) . "</td>";
                if ($includeCredit) {
                    $creditValue = (float) ($techCreditTotalsMap[$techId] ?? 0);
                    $creditLabel = $creditValue > 0 ? $formatCredit($creditValue) : '-';
                    echo "<td class='text-end'>" . htmlescape($creditLabel) . "</td>";
                }
                echo "</tr>";
            },
            $start,
            $techTotalCount,
            $pagerTarget,
            $pagerQuery,
            $includeCredit
        );

        $entityTableHtml = $buildTable(
            $entityRows,
            __('Entite', 'stats'),
            __('Tickets', 'stats'),
            function (array $row) use ($formatHours, $formatCredit, $entityCreditTotalsMap, $buildModalUrl, $dateBegin, $dateEnd, $selectedTechs, $includeCredit) {
                $entityId = (int) ($row['entities_id'] ?? 0);
                $label = $entityId > 0
                    ? \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(Dropdown::getDropdownName('glpi_entities', $entityId))
                    : '';
                $modalParams = [
                    'type'       => 'entity',
                    'id'         => $entityId,
                    'date_begin' => $dateBegin,
                    'date_end'   => $dateEnd,
                ];
                if (!empty($selectedTechs)) {
                    $modalParams['technicians_id'] = $selectedTechs;
                }
                $modalUrl = $buildModalUrl($modalParams) . '&_in_modal=1';
                $title = sprintf(__('Tickets de l entite %s', 'stats'), $label);
                $link = $label !== ''
                    ? "<a href='#' class='stats-ticket-modal' data-modal-url='"
                        . htmlescape($modalUrl) . "' data-modal-title='" . htmlescape($title) . "'>"
                        . htmlescape($label) . "</a>"
                    : '';
                echo "<tr>";
                echo "<td>" . $link . "</td>";
                echo "<td class='text-end'>" . htmlescape((string) ($row['ticket_count'] ?? 0)) . "</td>";
                echo "<td class='text-end'>" . $formatHours((int) $row['seconds']) . "</td>";
                if ($includeCredit) {
                    $creditValue = (float) ($entityCreditTotalsMap[$entityId] ?? 0);
                    $creditLabel = $creditValue > 0 ? $formatCredit($creditValue) : '-';
                    echo "<td class='text-end'>" . htmlescape($creditLabel) . "</td>";
                }
                echo "</tr>";
            },
            $start,
            $entityTotalCount,
            $pagerTarget,
            $pagerQuery,
            $includeCredit
        );

        $response = [
            'total' => $formatHours((int) $totalSeconds),
            'charts' => [
                'techs' => $techChartData,
                'entities' => $entityChartData,
            ],
            'tech_table' => $techTableHtml,
            'entity_table' => $entityTableHtml,
        ];
        if ($includeCredit) {
            $response['total_credit'] = $formatCredit($totalCredit);
        }

        header('Content-Type: application/json');
        echo json_encode($response, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        if ($previousListLimit !== null) {
            $_SESSION['glpilist_limit'] = $previousListLimit;
        } else {
            unset($_SESSION['glpilist_limit']);
        }
        exit;
    }

    echo "<div class='card mb-3'><div class='card-body'>";
    echo "<form method='get' action='" . $CFG_GLPI['root_doc'] . "/plugins/stats/front/stats.php' class='row g-3 align-items-end stats-filters'>";
    echo Html::hidden('view', ['value' => 'tickets']);
    echo "<div class='col-md-6'>";
    echo "<label class='form-label mb-1'>" . __('Entites', 'stats') . "</label>";
    Dropdown::show('Entity', [
        'name'     => 'ticket_entities_id[]',
        'value'    => $selectedEntities,
        'multiple' => true,
        'width'    => '100%',
    ]);
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<label class='form-label mb-1'>" . __('Techniciens', 'stats') . "</label>";
    Dropdown::show('User', [
        'name'     => 'technicians_id[]',
        'value'    => $selectedTechs,
        'multiple' => true,
        'right'    => 'all',
    ]);
    echo "</div>";
    echo "<div class='col-md-3'>";
    echo "<label class='form-label mb-1'>" . __('Date de debut', 'stats') . "</label>";
    Html::showDateField('date_begin', [
        'value'       => $dateBegin,
        'placeholder' => __('Date de debut', 'stats'),
        'display'     => true,
    ]);
    echo "</div>";
    echo "<div class='col-md-3'>";
    echo "<label class='form-label mb-1'>" . __('Date de fin', 'stats') . "</label>";
    Html::showDateField('date_end', [
        'value'       => $dateEnd,
        'placeholder' => __('Date de fin', 'stats'),
        'display'     => true,
    ]);
    echo "</div>";
    echo "<div class='col-12 text-end'>";
    echo Html::submit(__('Filtrer', 'stats'), ['class' => 'btn btn-primary']);
    echo "</div>";
    echo "</form>";
    echo "</div></div>";

    $spinner = "<div class='text-center py-4'><div class='spinner-border text-secondary' role='status'></div></div>";
    $tooltipTotalTask = __s('Addition de tous les temps saisis sur les taches des tickets qui respectent les filtres Entites, Techniciens et Dates.');
    $tooltipTotalCredit = __s('Addition de tous les credits consommes sur les tickets filtres. Si un technicien est choisi, on compte uniquement les credits qu il a saisis.');
    $tooltipByTech = __s('Repartition du temps de tache par technicien, basee sur les taches qu il a realisees sur les tickets filtres.');
    $tooltipByEntity = __s('Repartition du temps de tache par entite, basee sur les tickets filtres.');
    $tooltipTableTech = __s('Pour chaque technicien : nombre de tickets ou il intervient, temps total de ses taches, et credits qu il a saisis (les credits saisis par d autres ne sont pas ajoutes).');
    $tooltipTableEntity = __s('Pour chaque entite : nombre de tickets, temps total des taches et credits consommes sur ces tickets. Si un technicien est filtre, seuls ses credits sont comptes.');
    echo "<div class='row g-3 mb-3'>";
    $totalColClass = $includeCredit ? 'col-md-6' : 'col-md-12';
    echo "<div class='" . $totalColClass . "'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Temps total tâche', 'stats') . $infoIcon($tooltipTotalTask) . "</div>";
    echo "<div class='fs-4 fw-semibold' id='stats_ticket_total_value'>" . $spinner . "</div>";
    echo "</div></div></div>";
    if ($includeCredit) {
        echo "<div class='col-md-6'><div class='card h-100'><div class='card-body'>";
        echo "<div class='text-muted small'>" . __('Temps total credit', 'stats') . $infoIcon($tooltipTotalCredit) . "</div>";
        echo "<div class='fs-4 fw-semibold' id='stats_ticket_total_credit_value'>" . $spinner . "</div>";
        echo "</div></div></div>";
    }
    echo "</div>";

    echo "<div class='row g-3 mb-3'>";
    echo "<div class='col-md-6'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small mb-2'>" . __('Temps par technicien', 'stats') . $infoIcon($tooltipByTech) . "</div>";
    echo "<div id='stats_ticket_chart_tech' style='height:280px'>" . $spinner . "</div>";
    echo "</div></div></div>";
    echo "<div class='col-md-6'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small mb-2'>" . __('Temps par entite', 'stats') . $infoIcon($tooltipByEntity) . "</div>";
    echo "<div id='stats_ticket_chart_entity' style='height:280px'>" . $spinner . "</div>";
    echo "</div></div></div>";
    echo "</div>";

    $exportParams = $_GET;
    unset($exportParams['ajax'], $exportParams['start'], $exportParams['export']);
    $exportParams['view'] = 'tickets';
    $exportTechParams = $exportParams;
    $exportTechParams['export'] = 'tech';
    $exportEntityParams = $exportParams;
    $exportEntityParams['export'] = 'entity';
    $exportTechUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php?' . http_build_query($exportTechParams);
    $exportEntityUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php?' . http_build_query($exportEntityParams);

    echo "<div class='row g-3'>";
    echo "<div class='col-md-6'>";
    echo "<div class='card h-100'><div class='card-body'>";
    echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
    echo "<h3 class='h6 mb-0'>" . __('Temps par technicien', 'stats') . $infoIcon($tooltipTableTech) . "</h3>";
    echo "<a class='btn btn-sm btn-outline-secondary stats-export-btn' href='" . htmlescape($exportTechUrl) . "'"
        . " title='" . htmlescape(__('Export CSV')) . "' aria-label='" . htmlescape(__('Export CSV')) . "'>";
    echo "<span class='export-icon'><i class='ti ti-download'></i></span>";
    echo "<span class='export-spinner spinner-border spinner-border-sm d-none' role='status' aria-hidden='true'></span>";
    echo "<span class='visually-hidden'>" . __('Export CSV') . "</span>";
    echo "</a>";
    echo "</div>";
    echo "<div id='stats_ticket_table_tech'>" . $spinner . "</div>";
    echo "</div></div></div>";

    echo "<div class='col-md-6'>";
    echo "<div class='card h-100'><div class='card-body'>";
    echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
    echo "<h3 class='h6 mb-0'>" . __('Temps par entite', 'stats') . $infoIcon($tooltipTableEntity) . "</h3>";
    echo "<a class='btn btn-sm btn-outline-secondary stats-export-btn' href='" . htmlescape($exportEntityUrl) . "'"
        . " title='" . htmlescape(__('Export CSV')) . "' aria-label='" . htmlescape(__('Export CSV')) . "'>";
    echo "<span class='export-icon'><i class='ti ti-download'></i></span>";
    echo "<span class='export-spinner spinner-border spinner-border-sm d-none' role='status' aria-hidden='true'></span>";
    echo "<span class='visually-hidden'>" . __('Export CSV') . "</span>";
    echo "</a>";
    echo "</div>";
    echo "<div id='stats_ticket_table_entity'>" . $spinner . "</div>";
    echo "</div></div></div>";
    echo "</div>";
    echo "</div>";

    $modalHtml = <<<HTML
        <div id="statsTicketsModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" style="max-width:75vw;width:75vw;height:90vh;">
            <div class="modal-content" style="height:90vh;">
            <div class="modal-header">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                <h3 id="statsTicketsModalTitle"></h3>
            </div>
            <div class="modal-body" style="height:calc(90vh - 60px);overflow:auto;">
                <iframe id="statsTicketsModalFrame" class="iframe hidden" style="width:100%;height:100%;" frameborder="0"></iframe>
            </div>
            </div>
        </div>
        </div>
    HTML;
    echo $modalHtml;

    $ajaxParams = $_GET;
    $ajaxParams['view'] = 'tickets';
    $ajaxParams['ajax'] = 'tickets';
    $ajaxParams['start'] = $start;
    $ajaxUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php?' . http_build_query($ajaxParams);
    $noDataMessage = __('Aucune donnee a afficher.', 'stats');

    $echartsUrls = [
        Html::cleanInputText($CFG_GLPI['root_doc'] . '/lib/echarts.min.js'),
        Html::cleanInputText($CFG_GLPI['root_doc'] . '/public/lib/echarts.min.js'),
        '/lib/echarts.min.js',
    ];
    $echartsUrlsJson = json_encode($echartsUrls, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $ajaxUrlJson = json_encode($ajaxUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $noDataJson = json_encode($noDataMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $chartsJs = <<<JS
(function() {
  var echartsUrls = {$echartsUrlsJson};
  var ajaxUrl = {$ajaxUrlJson};
  var noDataMessage = {$noDataJson};
  var charts = [];

  var renderPie = function(targetId, data) {
    var el = document.getElementById(targetId);
    if (!el) {
      return;
    }
    if (!data || !data.length) {
      el.innerHTML = "<div class='text-muted small'>" + noDataMessage + "</div>";
      return;
    }
    if (!window.echarts) {
      return;
    }
    el.innerHTML = '';
    var instance = echarts.getInstanceByDom(el) || echarts.init(el);
    instance.setOption({
      tooltip: { trigger: 'item' },
      series: [{
        type: 'pie',
        radius: ['40%', '70%'],
        avoidLabelOverlap: true,
        label: { formatter: '{b}: {c} h' },
        data: data
      }]
    });
    charts.push(instance);
  };

  var loadEcharts = function(done) {
    if (window.echarts) {
      done();
      return;
    }
    var index = 0;
    var tryNext = function() {
      if (index >= echartsUrls.length) {
        return;
      }
      var script = document.createElement('script');
      script.src = echartsUrls[index++];
      script.onload = function() {
        if (window.echarts) {
          done();
        } else {
          tryNext();
        }
      };
      script.onerror = tryNext;
      document.head.appendChild(script);
    };
    tryNext();
  };

  var renderCharts = function(payload) {
    if (!payload) {
      return;
    }
    loadEcharts(function() {
      charts = [];
      renderPie('stats_ticket_chart_tech', payload.techs || []);
      renderPie('stats_ticket_chart_entity', payload.entities || []);
      window.addEventListener('resize', function() {
        charts.forEach(function(chart) { chart.resize(); });
      });
    });
  };

  var loadTables = function() {
    var techContainer = document.getElementById('stats_ticket_table_tech');
    var entityContainer = document.getElementById('stats_ticket_table_entity');
    var totalValue = document.getElementById('stats_ticket_total_value');
    var totalCreditValue = document.getElementById('stats_ticket_total_credit_value');

      fetch(ajaxUrl, { credentials: 'same-origin' })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (totalValue) {
            totalValue.textContent = data.total || '0 h';
          }
          if (totalCreditValue) {
            totalCreditValue.textContent = data.total_credit || '0 h';
          }
          if (techContainer) {
            techContainer.innerHTML = data.tech_table || '';
          }
          if (entityContainer) {
          entityContainer.innerHTML = data.entity_table || '';
        }
        renderCharts(data.charts || {});
      })
      .catch(function() {
        if (techContainer) {
          techContainer.innerHTML = "<div class='alert alert-warning'>" + noDataMessage + "</div>";
        }
        if (entityContainer) {
          entityContainer.innerHTML = "<div class='alert alert-warning'>" + noDataMessage + "</div>";
        }
        });
    };

  var getExportFilename = function(response, fallback) {
    var header = '';
    try {
      header = response.headers.get('Content-Disposition') || '';
    } catch (e) {
      header = '';
    }
    var match = header.match(/filename\*=UTF-8''([^;]+)/i);
    if (match && match[1]) {
      try {
        return decodeURIComponent(match[1]);
      } catch (e) {
        return match[1];
      }
    }
    match = header.match(/filename="?([^\";]+)"?/i);
    if (match && match[1]) {
      return match[1];
    }
    return fallback;
  };

  var setExportLoading = function(btn, isLoading) {
    btn.dataset.loading = isLoading ? '1' : '0';
    btn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    if (isLoading) {
      btn.classList.add('disabled');
    } else {
      btn.classList.remove('disabled');
    }
    var icon = btn.querySelector('.export-icon');
    var spinner = btn.querySelector('.export-spinner');
    if (icon) {
      icon.classList.toggle('d-none', isLoading);
    }
    if (spinner) {
      spinner.classList.toggle('d-none', !isLoading);
    }
  };

  var bindExportButtons = function() {
    document.querySelectorAll('.stats-export-btn').forEach(function(btn) {
      if (btn.dataset.bound === '1') {
        return;
      }
      btn.dataset.bound = '1';
      btn.addEventListener('click', function(e) {
        if (btn.dataset.loading === '1') {
          e.preventDefault();
          return;
        }
        if (!window.fetch || !window.Blob || !window.URL) {
          return;
        }
        e.preventDefault();
        setExportLoading(btn, true);
        var fallbackName = 'export.csv';
        fetch(btn.href, { credentials: 'same-origin' })
          .then(function(response) {
            if (!response.ok) {
              throw new Error('export_failed');
            }
            var filename = getExportFilename(response, fallbackName);
            return response.blob().then(function(blob) {
              var url = window.URL.createObjectURL(blob);
              var link = document.createElement('a');
              link.style.display = 'none';
              link.href = url;
              link.download = filename;
              document.body.appendChild(link);
              link.click();
              setTimeout(function() {
                window.URL.revokeObjectURL(url);
                link.remove();
              }, 1000);
            });
          })
          .catch(function() {
            window.location.href = btn.href;
          })
          .finally(function() {
            setExportLoading(btn, false);
          });
      });
    });
  };

  var boot = function() {
    loadTables();
    bindExportButtons();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.stats-ticket-modal');
    if (!link) {
      return;
    }
    e.preventDefault();
    var modalEl = document.getElementById('statsTicketsModal');
    if (!modalEl) {
      return;
    }
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var iframe = document.getElementById('statsTicketsModalFrame');
    if (iframe) {
      iframe.setAttribute('src', link.getAttribute('data-modal-url') || '');
      iframe.classList.remove('hidden');
    }
    var titleEl = document.getElementById('statsTicketsModalTitle');
    if (titleEl) {
      titleEl.textContent = link.getAttribute('data-modal-title') || '';
    }
    modal.show();
  });
})();
JS;
    echo Html::scriptBlock($chartsJs);

    if ($previousListLimit !== null) {
        $_SESSION['glpilist_limit'] = $previousListLimit;
    } else {
        unset($_SESSION['glpilist_limit']);
    }
    Html::footer();
    return;
}

if ($view === 'satisfaction') {
    $answersTable = 'glpi_plugin_satisfactionclient_answers';
    $questionsTable = 'glpi_plugin_satisfactionclient_questions';
    $exportType = (string) ($_GET['export'] ?? '');
    $isExport = in_array($exportType, ['tech', 'entity'], true);
    $listLimit = (int) ($_REQUEST['glpilist_limit'] ?? ($_SESSION['glpilist_limit'] ?? 15));
    if ($listLimit <= 0) {
        $listLimit = 15;
    }
    $start = (int) ($_GET['start'] ?? 0);
    $previousListLimit = $_SESSION['glpilist_limit'] ?? null;
    $_SESSION['glpilist_limit'] = $listLimit;

    $questions = [];
    if (class_exists('PluginSatisfactionclientQuestion')) {
        $questions = PluginSatisfactionclientQuestion::getActiveQuestions();
    } elseif ($DB->tableExists($questionsTable)) {
        foreach ($DB->request([
            'FROM' => $questionsTable,
            'WHERE' => [
                'is_active' => 1,
            ],
            'ORDER' => ['position ASC', 'id ASC'],
        ]) as $row) {
            $questions[] = [
                'key' => $row['question_key'],
                'label' => $row['question_label'],
                'type' => $row['question_type'],
                'required' => !empty($row['is_required']),
                'scale' => (int) $row['scale'],
            ];
        }
    }

    $questionMap = [];
    $questionOptions = [];
    foreach ($questions as $question) {
        if (!in_array($question['type'], ['rating', 'yesno'], true)) {
            continue;
        }
        $questionMap[$question['key']] = $question;
        $questionOptions[$question['key']] = $question['label'];
    }

    if (empty($questionOptions)) {
        echo "<div class='alert alert-warning'>";
        echo __('Aucune question de satisfaction exploitable.', 'stats');
        echo "</div>";
        if ($previousListLimit !== null) {
            $_SESSION['glpilist_limit'] = $previousListLimit;
        } else {
            unset($_SESSION['glpilist_limit']);
        }
        Html::footer();
        return;
    }

    $questionKey = (string) ($_GET['question_key'] ?? '');
    if ($questionKey === '' || !array_key_exists($questionKey, $questionMap)) {
        $questionKey = array_key_first($questionMap);
    }
    $currentQuestion = $questionMap[$questionKey];
    $questionLabel = (string) ($currentQuestion['label'] ?? '');
    $questionType = (string) ($currentQuestion['type'] ?? 'rating');
    $questionScale = (int) ($currentQuestion['scale'] ?? 5);

    $selectedEntities = $_GET['satisfaction_entities_id'] ?? [];
    if (!is_array($selectedEntities)) {
        $selectedEntities = [$selectedEntities];
    }
    $selectedEntities = array_values(array_filter(array_map('intval', $selectedEntities)));

    $selectedTechs = $_GET['satisfaction_technicians_id'] ?? [];
    if (!is_array($selectedTechs)) {
        $selectedTechs = [$selectedTechs];
    }
    $selectedTechs = array_values(array_filter(array_map('intval', $selectedTechs)));

    $entityScope = [];
    foreach ($selectedEntities as $entityId) {
        if ($entityId <= 0) {
            continue;
        }
        $sons = getSonsOf('glpi_entities', $entityId);
        if (!is_array($sons)) {
            $sons = [$entityId];
        }
        foreach ($sons as $sonId) {
            $sonId = (int) $sonId;
            if ($sonId > 0) {
                $entityScope[$sonId] = true;
            }
        }
    }
    $entityScope = array_keys($entityScope);

    $baseWhere = [
        'question_key' => $questionKey,
        'answer_value' => ['<>', ''],
    ];
    if (!empty($entityScope)) {
        $baseWhere['entities_id'] = $entityScope;
    }
    if (!empty($selectedTechs)) {
        $baseWhere['users_id_technician'] = $selectedTechs;
    }

    $dateCriteria = [];
    if ($dateStart !== '') {
        $dateCriteria[] = ['date_answered' => ['>=', $dateStart]];
    }
    if ($dateStop !== '') {
        $dateCriteria[] = ['date_answered' => ['<=', $dateStop]];
    }
    if (!empty($dateCriteria)) {
        if (!isset($baseWhere['AND']) || !is_array($baseWhere['AND'])) {
            $baseWhere['AND'] = [];
        }
        $baseWhere['AND'] = array_merge($baseWhere['AND'], $dateCriteria);
    }

    $formatNumber = static function ($value, int $decimals = 0): string {
        return Html::formatNumber((float) $value, false, $decimals);
    };
    $formatScore = static function (float $value, bool $hasResponses) use ($formatNumber, $questionType, $questionScale): string {
        if (!$hasResponses) {
            return '-';
        }
        if ($questionType === 'yesno') {
            return $formatNumber($value * 100, 1) . ' %';
        }
        return $formatNumber($value, 2) . ' / ' . $questionScale;
    };

    $summary = $DB->request([
        'SELECT' => [
            new QueryExpression('COUNT(*) AS responses'),
            new QueryExpression('AVG(CAST(answer_value AS DECIMAL(10,2))) AS avg_value'),
        ],
        'FROM' => $answersTable,
        'WHERE' => $baseWhere,
    ])->current() ?: [];
    $totalResponses = (int) ($summary['responses'] ?? 0);
    $avgValue = (float) ($summary['avg_value'] ?? 0);

    $techWhere = $baseWhere;
    if (empty($selectedTechs)) {
        $techWhere['users_id_technician'] = ['>', 0];
    }
    $techRows = [];
    foreach ($DB->request([
        'SELECT' => [
            'users_id_technician AS tech_id',
            new QueryExpression('COUNT(*) AS responses'),
            new QueryExpression('AVG(CAST(answer_value AS DECIMAL(10,2))) AS avg_value'),
        ],
        'FROM' => $answersTable,
        'WHERE' => $techWhere,
        'GROUPBY' => ['users_id_technician'],
        'ORDER' => [
            new QueryExpression('avg_value ASC'),
            new QueryExpression('responses DESC'),
        ],
    ]) as $row) {
        $techRows[] = [
            'tech_id' => (int) ($row['tech_id'] ?? 0),
            'responses' => (int) ($row['responses'] ?? 0),
            'avg_value' => (float) ($row['avg_value'] ?? 0),
        ];
    }

    $entityWhere = $baseWhere;
    if (empty($entityScope)) {
        $entityWhere['entities_id'] = ['>', 0];
    }
    $entityRows = [];
    foreach ($DB->request([
        'SELECT' => [
            'entities_id',
            new QueryExpression('COUNT(*) AS responses'),
            new QueryExpression('AVG(CAST(answer_value AS DECIMAL(10,2))) AS avg_value'),
        ],
        'FROM' => $answersTable,
        'WHERE' => $entityWhere,
        'GROUPBY' => ['entities_id'],
        'ORDER' => [
            new QueryExpression('avg_value ASC'),
            new QueryExpression('responses DESC'),
        ],
    ]) as $row) {
        $entityRows[] = [
            'entities_id' => (int) ($row['entities_id'] ?? 0),
            'responses' => (int) ($row['responses'] ?? 0),
            'avg_value' => (float) ($row['avg_value'] ?? 0),
        ];
    }

    $techTotalCount = count($techRows);
    $entityTotalCount = count($entityRows);
    $techPageRows = array_slice($techRows, $start, $listLimit);
    $entityPageRows = array_slice($entityRows, $start, $listLimit);
    $pagerParams = $_GET;
    unset($pagerParams['start']);
    $pagerQuery = http_build_query($pagerParams);
    $pagerTarget = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php';
    $exportParams = $_GET;
    unset($exportParams['start'], $exportParams['export']);
    $exportParams['view'] = 'satisfaction';
    $exportTechParams = $exportParams;
    $exportTechParams['export'] = 'tech';
    $exportEntityParams = $exportParams;
    $exportEntityParams['export'] = 'entity';
    $exportTechUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php?' . http_build_query($exportTechParams);
    $exportEntityUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php?' . http_build_query($exportEntityParams);

    $scoreHeader = $questionType === 'yesno'
        ? __('Taux moyen', 'stats')
        : __('Note moyenne', 'stats');

    if ($isExport) {
        $filename = $exportType === 'tech'
            ? 'stats_satisfaction_techniciens.csv'
            : 'stats_satisfaction_entites.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        $headers = [
            $exportType === 'tech' ? __('Technicien', 'stats') : __('Entite', 'stats'),
            __('Reponses', 'stats'),
            $scoreHeader,
        ];
        fputcsv($out, $headers, ';');

        if ($exportType === 'tech') {
            foreach ($techRows as $row) {
                $techId = (int) ($row['tech_id'] ?? 0);
                $name = $techId > 0 ? getUserName($techId) : '';
                $responses = (int) ($row['responses'] ?? 0);
                $score = $formatScore((float) ($row['avg_value'] ?? 0), $responses > 0);
                fputcsv($out, [
                    $name,
                    (string) $responses,
                    $score,
                ], ';');
            }
        } else {
            foreach ($entityRows as $row) {
                $entityId = (int) ($row['entities_id'] ?? 0);
                $label = $entityId > 0
                    ? \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(
                        Dropdown::getDropdownName('glpi_entities', $entityId)
                    )
                    : '';
                $responses = (int) ($row['responses'] ?? 0);
                $score = $formatScore((float) ($row['avg_value'] ?? 0), $responses > 0);
                fputcsv($out, [
                    $label,
                    (string) $responses,
                    $score,
                ], ';');
            }
        }
        fclose($out);
        if ($previousListLimit !== null) {
            $_SESSION['glpilist_limit'] = $previousListLimit;
        } else {
            unset($_SESSION['glpilist_limit']);
        }
        exit;
    }
    $buildTable = static function (
        array $rows,
        string $firstHeader,
        string $scoreHeader,
        callable $rowBuilder,
        int $start,
        int $totalCount,
        string $pagerTarget,
        string $pagerQuery
    ): string {
        ob_start();
        if ($totalCount > 0) {
            Html::printPager($start, $totalCount, $pagerTarget, $pagerQuery);
        }
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm'>";
        echo "<thead><tr>";
        echo "<th>" . $firstHeader . "</th>";
        echo "<th class='text-end'>" . __('Reponses', 'stats') . "</th>";
        echo "<th class='text-end'>" . $scoreHeader . "</th>";
        echo "</tr></thead><tbody>";
        if (empty($rows)) {
            echo "<tr><td colspan='3' class='text-center text-muted'>"
                . __('Aucune donnee a afficher.', 'stats') . "</td></tr>";
        } else {
            foreach ($rows as $row) {
                $rowBuilder($row);
            }
        }
        echo "</tbody></table></div>";
        if ($totalCount > 0) {
            Html::printPager($start, $totalCount, $pagerTarget, $pagerQuery);
        }
        return ob_get_clean();
    };

    echo "<div class='card mb-3'><div class='card-body'>";
    echo "<form method='get' action='" . $CFG_GLPI['root_doc'] . "/plugins/stats/front/stats.php' class='row g-3 align-items-end stats-filters'>";
    echo Html::hidden('view', ['value' => 'satisfaction']);
    echo "<div class='col-md-4'>";
    echo "<label class='form-label mb-1'>" . __('Entites', 'stats') . "</label>";
    Dropdown::show('Entity', [
        'name'     => 'satisfaction_entities_id[]',
        'value'    => $selectedEntities,
        'multiple' => true,
        'width'    => '100%',
    ]);
    echo "</div>";
    echo "<div class='col-md-4'>";
    echo "<label class='form-label mb-1'>" . __('Techniciens', 'stats') . "</label>";
    Dropdown::show('User', [
        'name'     => 'satisfaction_technicians_id[]',
        'value'    => $selectedTechs,
        'multiple' => true,
        'width'    => '100%',
        'right'    => 'all',
    ]);
    echo "</div>";
    echo "<div class='col-md-4'>";
    echo "<label class='form-label mb-1'>" . __('Question', 'stats') . "</label>";
    echo "<select class='form-select' name='question_key'>";
    foreach ($questionOptions as $key => $label) {
        $selected = $key === $questionKey ? 'selected' : '';
        echo "<option value='" . htmlescape($key) . "' $selected>" . htmlescape($label) . "</option>";
    }
    echo "</select>";
    echo "</div>";
    echo "<div class='col-md-3'>";
    echo "<label class='form-label mb-1'>" . __('Date de debut', 'stats') . "</label>";
    Html::showDateField('date_begin', [
        'value'       => $dateBegin,
        'placeholder' => __('Date de debut', 'stats'),
        'display'     => true,
    ]);
    echo "</div>";
    echo "<div class='col-md-3'>";
    echo "<label class='form-label mb-1'>" . __('Date de fin', 'stats') . "</label>";
    Html::showDateField('date_end', [
        'value'       => $dateEnd,
        'placeholder' => __('Date de fin', 'stats'),
        'display'     => true,
    ]);
    echo "</div>";
    echo "<div class='col-12 text-end'>";
    echo Html::submit(__('Filtrer', 'stats'), ['class' => 'btn btn-primary']);
    echo "</div>";
    echo "</form>";
    echo "</div></div>";

    echo "<div class='text-muted small mb-2'>"
        . __('Question analysee', 'stats') . " : " . htmlescape($questionLabel)
        . "</div>";

    if ($totalResponses <= 0) {
        echo "<div class='alert alert-info'>";
        echo __('Aucune donnee a afficher.', 'stats');
        echo "</div>";
        if ($previousListLimit !== null) {
            $_SESSION['glpilist_limit'] = $previousListLimit;
        } else {
            unset($_SESSION['glpilist_limit']);
        }
        Html::footer();
        return;
    }

    $tooltipResponses = __s('Nombre total de reponses a la question choisie, apres application des filtres Entites, Techniciens et Dates.');
    $tooltipScore = __s('Moyenne des reponses a la question choisie (note ou pourcentage), avec les memes filtres.');
    $tooltipTopSatisfied = __s('Top 5 entites avec la meilleure moyenne de reponses a la question choisie (selon les filtres).');
    $tooltipTopUnsatisfied = __s('Top 5 entites avec la plus faible moyenne de reponses a la question choisie (selon les filtres).');
    $tooltipTopTech = __s('Top 5 techniciens avec la meilleure moyenne de reponses a la question choisie (selon les filtres).');
    $tooltipTableTechSat = __s('Pour chaque technicien : nombre de reponses et moyenne a la question choisie, selon les filtres.');
    $tooltipTableEntitySat = __s('Pour chaque entite : nombre de reponses et moyenne a la question choisie, selon les filtres.');

    echo "<div class='row g-3 mb-3'>";
    echo "<div class='col-md-6'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Reponses', 'stats') . $infoIcon($tooltipResponses) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatNumber($totalResponses) . "</div>";
    echo "</div></div></div>";
    echo "<div class='col-md-6'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . htmlescape($scoreHeader) . $infoIcon($tooltipScore) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . htmlescape($formatScore($avgValue, $totalResponses > 0)) . "</div>";
    $scoreMax = $questionType === 'yesno' ? 100 : max(1, $questionScale);
    $scoreValue = 0.0;
    if ($totalResponses > 0) {
        $scoreValue = $questionType === 'yesno' ? ($avgValue * 100) : $avgValue;
    }
    $scorePercent = 0.0;
    if ($scoreMax > 0) {
        $scorePercent = max(0.0, min(100.0, ($scoreValue / $scoreMax) * 100));
    }
    echo "<div class='mt-2'>";
    echo "<div style='position:relative;height:10px;border-radius:999px;"
        . "background:linear-gradient(90deg,#d9534f 0%,#f0ad4e 50%,#5cb85c 100%);'>";
    $left = $scorePercent;
    echo "<div style='position:absolute;left:calc(" . $left . "% - 6px);top:-6px;width:0;height:0;"
        . "border-left:6px solid transparent;border-right:6px solid transparent;border-bottom:10px solid #343a40;'></div>";
    echo "</div>";
    $scaleLabel = $questionType === 'yesno' ? '100 %' : (string) $questionScale;
    echo "<div class='text-muted small mt-1'>0 – " . htmlescape($scaleLabel) . "</div>";
    echo "</div>";
    echo "</div></div></div>";
    echo "</div>";

    $buildModalUrl = static function (array $params) use ($CFG_GLPI): string {
        $base = $CFG_GLPI['root_doc'] . '/plugins/stats/front/satisfaction.modal.php';
        return $base . '?' . http_build_query($params);
    };

    $buildEntityLabel = static function (int $entityId): string {
        if ($entityId <= 0) {
            return '';
        }
        return \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(
            Dropdown::getDropdownName('glpi_entities', $entityId)
        );
    };

    $buildTechLabel = static function (int $techId): string {
        return $techId > 0 ? getUserName($techId) : '';
    };

    $entityAvgRows = array_filter($entityRows, function (array $row): bool {
        return (int) ($row['responses'] ?? 0) > 0;
    });
    $techAvgRows = array_filter($techRows, function (array $row): bool {
        return (int) ($row['responses'] ?? 0) > 0;
    });

    $entityAvgDesc = $entityAvgRows;
    usort($entityAvgDesc, function (array $a, array $b): int {
        return ($b['avg_value'] <=> $a['avg_value']);
    });
    $entityAvgAsc = $entityAvgRows;
    usort($entityAvgAsc, function (array $a, array $b): int {
        return ($a['avg_value'] <=> $b['avg_value']);
    });
    $techAvgDesc = $techAvgRows;
    usort($techAvgDesc, function (array $a, array $b): int {
        return ($b['avg_value'] <=> $a['avg_value']);
    });

    $valueFactor = $questionType === 'yesno' ? 100 : 1;
    $topSatisfiedEntities = [];
    foreach (array_slice($entityAvgDesc, 0, 5) as $row) {
        $entityId = (int) ($row['entities_id'] ?? 0);
        $label = $buildEntityLabel($entityId);
        if ($label === '') {
            continue;
        }
        $value = round(((float) ($row['avg_value'] ?? 0)) * $valueFactor, 2);
        $topSatisfiedEntities[] = ['name' => $label, 'value' => $value];
    }
    $topUnsatisfiedEntities = [];
    foreach (array_slice($entityAvgAsc, 0, 5) as $row) {
        $entityId = (int) ($row['entities_id'] ?? 0);
        $label = $buildEntityLabel($entityId);
        if ($label === '') {
            continue;
        }
        $value = round(((float) ($row['avg_value'] ?? 0)) * $valueFactor, 2);
        $topUnsatisfiedEntities[] = ['name' => $label, 'value' => $value];
    }
    $topTechAvg = [];
    foreach (array_slice($techAvgDesc, 0, 5) as $row) {
        $techId = (int) ($row['tech_id'] ?? 0);
        $label = $buildTechLabel($techId);
        if ($label === '') {
            continue;
        }
        $value = round(((float) ($row['avg_value'] ?? 0)) * $valueFactor, 2);
        $topTechAvg[] = ['name' => $label, 'value' => $value];
    }

    echo "<div class='row g-3 mb-3'>";
    echo "<div class='col-md-4'><div class='card h-100'><div class='card-body'>";
    $topLabelSuffix = $questionType === 'yesno' ? __('(taux moyen)', 'stats') : __('(note moyenne)', 'stats');
    echo "<div class='text-muted small mb-2'>" . __('Top clients satisfaits', 'stats') . " " . htmlescape($topLabelSuffix)
        . $infoIcon($tooltipTopSatisfied) . "</div>";
    echo "<div id='stats_satisfaction_top_positive' style='height:240px'></div>";
    echo "</div></div></div>";
    echo "<div class='col-md-4'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small mb-2'>" . __('Top clients non satisfaits', 'stats') . " " . htmlescape($topLabelSuffix)
        . $infoIcon($tooltipTopUnsatisfied) . "</div>";
    echo "<div id='stats_satisfaction_top_negative' style='height:240px'></div>";
    echo "</div></div></div>";
    echo "<div class='col-md-4'><div class='card h-100'><div class='card-body'>";
    $topTechLabel = $questionType === 'yesno'
        ? __('Top techniciens (taux moyen)', 'stats')
        : __('Top techniciens (note moyenne)', 'stats');
    echo "<div class='text-muted small mb-2'>" . htmlescape($topTechLabel) . $infoIcon($tooltipTopTech) . "</div>";
    echo "<div id='stats_satisfaction_top_tech' style='height:240px'></div>";
    echo "</div></div></div>";
    echo "</div>";

    $techTableHtml = $buildTable(
        $techPageRows,
        __('Technicien', 'stats'),
        $scoreHeader,
        function (array $row) use ($formatNumber, $formatScore, $buildModalUrl, $questionKey, $dateBegin, $dateEnd, $selectedEntities) {
            $techId = (int) ($row['tech_id'] ?? 0);
            $name = $techId > 0 ? getUserName($techId) : '';
            $responses = (int) ($row['responses'] ?? 0);
            $score = $formatScore((float) ($row['avg_value'] ?? 0), $responses > 0);
            $link = '';
            if ($techId > 0 && $name !== '') {
                $modalParams = [
                    'type' => 'tech',
                    'id' => $techId,
                    'question_key' => $questionKey,
                    'date_begin' => $dateBegin,
                    'date_end' => $dateEnd,
                ];
                if (!empty($selectedEntities)) {
                    $modalParams['entities_id'] = $selectedEntities;
                }
                $modalUrl = $buildModalUrl($modalParams) . '&_in_modal=1';
                $title = sprintf(__('Satisfaction du technicien %s', 'stats'), $name);
                $link = "<a href='#' class='stats-satisfaction-modal' data-modal-url='"
                    . htmlescape($modalUrl) . "' data-modal-title='" . htmlescape($title) . "'>"
                    . htmlescape($name) . "</a>";
            }
            echo "<tr>";
            echo "<td>" . ($link !== '' ? $link : htmlescape($name)) . "</td>";
            echo "<td class='text-end'>" . htmlescape($formatNumber($responses)) . "</td>";
            echo "<td class='text-end'>" . htmlescape($score) . "</td>";
            echo "</tr>";
        },
        $start,
        $techTotalCount,
        $pagerTarget,
        $pagerQuery
    );

    $entityTableHtml = $buildTable(
        $entityPageRows,
        __('Entite', 'stats'),
        $scoreHeader,
        function (array $row) use ($formatNumber, $formatScore, $buildModalUrl, $questionKey, $dateBegin, $dateEnd, $selectedTechs) {
            $entityId = (int) ($row['entities_id'] ?? 0);
            $label = $entityId > 0
                ? \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(Dropdown::getDropdownName('glpi_entities', $entityId))
                : '';
            $responses = (int) ($row['responses'] ?? 0);
            $score = $formatScore((float) ($row['avg_value'] ?? 0), $responses > 0);
            $link = '';
            if ($entityId > 0 && $label !== '') {
                $modalParams = [
                    'type' => 'entity',
                    'id' => $entityId,
                    'question_key' => $questionKey,
                    'date_begin' => $dateBegin,
                    'date_end' => $dateEnd,
                ];
                if (!empty($selectedTechs)) {
                    $modalParams['technicians_id'] = $selectedTechs;
                }
                $modalUrl = $buildModalUrl($modalParams) . '&_in_modal=1';
                $title = sprintf(__('Satisfaction de l entite %s', 'stats'), $label);
                $link = "<a href='#' class='stats-satisfaction-modal' data-modal-url='"
                    . htmlescape($modalUrl) . "' data-modal-title='" . htmlescape($title) . "'>"
                    . htmlescape($label) . "</a>";
            }
            echo "<tr>";
            echo "<td>" . ($link !== '' ? $link : htmlescape($label)) . "</td>";
            echo "<td class='text-end'>" . htmlescape($formatNumber($responses)) . "</td>";
            echo "<td class='text-end'>" . htmlescape($score) . "</td>";
            echo "</tr>";
        },
        $start,
        $entityTotalCount,
        $pagerTarget,
        $pagerQuery
    );

    echo "<div class='row g-3'>";
    echo "<div class='col-md-6'><div class='card h-100'><div class='card-body'>";
    echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
    echo "<h3 class='h6 mb-0'>" . __('Satisfaction par technicien', 'stats') . $infoIcon($tooltipTableTechSat) . "</h3>";
    echo "<a class='btn btn-sm btn-outline-secondary stats-export-btn' href='" . htmlescape($exportTechUrl) . "'"
        . " title='" . htmlescape(__('Export CSV')) . "' aria-label='" . htmlescape(__('Export CSV')) . "'>";
    echo "<span class='export-icon'><i class='ti ti-download'></i></span>";
    echo "<span class='export-spinner spinner-border spinner-border-sm d-none' role='status' aria-hidden='true'></span>";
    echo "<span class='visually-hidden'>" . __('Export CSV') . "</span>";
    echo "</a>";
    echo "</div>";
    echo $techTableHtml;
    echo "</div></div></div>";
    echo "<div class='col-md-6'><div class='card h-100'><div class='card-body'>";
    echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
    echo "<h3 class='h6 mb-0'>" . __('Satisfaction par entite', 'stats') . $infoIcon($tooltipTableEntitySat) . "</h3>";
    echo "<a class='btn btn-sm btn-outline-secondary stats-export-btn' href='" . htmlescape($exportEntityUrl) . "'"
        . " title='" . htmlescape(__('Export CSV')) . "' aria-label='" . htmlescape(__('Export CSV')) . "'>";
    echo "<span class='export-icon'><i class='ti ti-download'></i></span>";
    echo "<span class='export-spinner spinner-border spinner-border-sm d-none' role='status' aria-hidden='true'></span>";
    echo "<span class='visually-hidden'>" . __('Export CSV') . "</span>";
    echo "</a>";
    echo "</div>";
    echo $entityTableHtml;
    echo "</div></div></div>";
    echo "</div>";

    $exportJs = <<<JS
(function() {
  var getExportFilename = function(response, fallback) {
    var header = '';
    try {
      header = response.headers.get('Content-Disposition') || '';
    } catch (e) {
      header = '';
    }
    var match = header.match(/filename\\*=UTF-8''([^;]+)/i);
    if (match && match[1]) {
      try {
        return decodeURIComponent(match[1]);
      } catch (e) {
        return match[1];
      }
    }
    match = header.match(/filename=\"?([^\\\";]+)\"?/i);
    if (match && match[1]) {
      return match[1];
    }
    return fallback;
  };

  var setExportLoading = function(btn, isLoading) {
    btn.dataset.loading = isLoading ? '1' : '0';
    btn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    if (isLoading) {
      btn.classList.add('disabled');
    } else {
      btn.classList.remove('disabled');
    }
    var icon = btn.querySelector('.export-icon');
    var spinner = btn.querySelector('.export-spinner');
    if (icon) {
      icon.classList.toggle('d-none', isLoading);
    }
    if (spinner) {
      spinner.classList.toggle('d-none', !isLoading);
    }
  };

  document.querySelectorAll('.stats-export-btn').forEach(function(btn) {
    if (btn.dataset.bound === '1') {
      return;
    }
    btn.dataset.bound = '1';
    btn.addEventListener('click', function(e) {
      if (btn.dataset.loading === '1') {
        e.preventDefault();
        return;
      }
      if (!window.fetch || !window.Blob || !window.URL) {
        return;
      }
      e.preventDefault();
      setExportLoading(btn, true);
      var fallbackName = 'export.csv';
      fetch(btn.href, { credentials: 'same-origin' })
        .then(function(response) {
          if (!response.ok) {
            throw new Error('export_failed');
          }
          var filename = getExportFilename(response, fallbackName);
          return response.blob().then(function(blob) {
            var url = window.URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.style.display = 'none';
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            setTimeout(function() {
              window.URL.revokeObjectURL(url);
              link.remove();
            }, 1000);
          });
        })
        .catch(function() {
          window.location.href = btn.href;
        })
        .finally(function() {
          setExportLoading(btn, false);
        });
    });
  });
})();
JS;
    echo Html::scriptBlock($exportJs);

    $modalHtml = <<<HTML
        <div id="statsSatisfactionModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" style="max-width:75vw;width:75vw;height:90vh;">
            <div class="modal-content" style="height:90vh;">
            <div class="modal-header">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                <h3 id="statsSatisfactionModalTitle"></h3>
            </div>
            <div class="modal-body" style="height:calc(90vh - 60px);overflow:auto;">
                <iframe id="statsSatisfactionModalFrame" class="iframe hidden" style="width:100%;height:100%;" frameborder="0"></iframe>
            </div>
            </div>
        </div>
        </div>
    HTML;
    echo $modalHtml;

    $modalJs = <<<JS
(function() {
  document.addEventListener('click', function(e) {
    var link = e.target.closest('.stats-satisfaction-modal');
    if (!link) {
      return;
    }
    e.preventDefault();
    var modalEl = document.getElementById('statsSatisfactionModal');
    if (!modalEl) {
      return;
    }
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var iframe = document.getElementById('statsSatisfactionModalFrame');
    if (iframe) {
      iframe.setAttribute('src', link.getAttribute('data-modal-url') || '');
      iframe.classList.remove('hidden');
    }
    var titleEl = document.getElementById('statsSatisfactionModalTitle');
    if (titleEl) {
      titleEl.textContent = link.getAttribute('data-modal-title') || '';
    }
    modal.show();
  });
})();
JS;
    echo Html::scriptBlock($modalJs);

    $chartsPayload = [
        'positive' => $topSatisfiedEntities,
        'negative' => $topUnsatisfiedEntities,
        'techs' => $topTechAvg,
        'suffix' => $questionType === 'yesno' ? ' %' : (' / ' . $questionScale),
        'empty' => __('Aucune donnee a afficher.', 'stats'),
    ];
    $chartsJson = json_encode($chartsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $echartsUrls = [
        Html::cleanInputText($CFG_GLPI['root_doc'] . '/lib/echarts.min.js'),
        Html::cleanInputText($CFG_GLPI['root_doc'] . '/public/lib/echarts.min.js'),
        '/lib/echarts.min.js',
    ];
    $echartsUrlsJson = json_encode($echartsUrls, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $chartsJs = <<<JS
(function() {
  var payload = {$chartsJson};
  var echartsUrls = {$echartsUrlsJson};
  var charts = [];

  var renderPie = function(targetId, data, suffix) {
    var el = document.getElementById(targetId);
    if (!el) {
      return;
    }
    if (!data || !data.length) {
      el.innerHTML = "<div class='text-muted small'>" + (payload.empty || '') + "</div>";
      return;
    }
    if (!window.echarts) {
      return;
    }
    var instance = echarts.getInstanceByDom(el) || echarts.init(el);
    instance.setOption({
      tooltip: { formatter: '{b}: {c}' + (suffix || '') },
      series: [{
        type: 'pie',
        radius: ['40%', '70%'],
        avoidLabelOverlap: true,
        label: { formatter: '{b}' },
        data: data
      }]
    });
    charts.push(instance);
  };

  var renderAll = function() {
    charts = [];
    renderPie('stats_satisfaction_top_positive', payload.positive, payload.suffix);
    renderPie('stats_satisfaction_top_negative', payload.negative, payload.suffix);
    renderPie('stats_satisfaction_top_tech', payload.techs, payload.suffix);
  };

  var loadEcharts = function(done) {
    if (window.echarts) {
      done();
      return;
    }
    var index = 0;
    var tryNext = function() {
      if (index >= echartsUrls.length) {
        return;
      }
      var script = document.createElement('script');
      script.src = echartsUrls[index++];
      script.onload = function() {
        if (window.echarts) {
          done();
        } else {
          tryNext();
        }
      };
      script.onerror = tryNext;
      document.head.appendChild(script);
    };
    tryNext();
  };

  var boot = function() {
    loadEcharts(function() {
      renderAll();
      window.addEventListener('resize', function() {
        charts.forEach(function(chart) { chart.resize(); });
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
JS;
    echo Html::scriptBlock($chartsJs);

    if ($previousListLimit !== null) {
        $_SESSION['glpilist_limit'] = $previousListLimit;
    } else {
        unset($_SESSION['glpilist_limit']);
    }
    Html::footer();
    return;
}

/** @var DBmysql $DB */
global $DB;
$listLimit = (int) ($_REQUEST['glpilist_limit'] ?? ($_SESSION['glpilist_limit'] ?? 15));
if ($listLimit <= 0) {
    $listLimit = 15;
}
$start = (int) ($_GET['start'] ?? 0);
$previousListLimit = $_SESSION['glpilist_limit'] ?? null;
$_SESSION['glpilist_limit'] = $listLimit;
$shouldRunCredits = $runStats === 1;

$creditsView = 'glpi_plugin_creditalert_vcredits';
$statsEntityId = (int) ($_GET['stats_entities_id'] ?? 0);
$entityScope = [];
if ($statsEntityId > 0) {
    $entityScope = getSonsOf('glpi_entities', $statsEntityId);
    if (!is_array($entityScope)) {
        $entityScope = [$statsEntityId];
    }
    $entityScope = array_values(array_unique(array_filter(array_map('intval', $entityScope))));
    if (empty($entityScope)) {
        $entityScope = [$statsEntityId];
    }
}

$creditsDateCriteria = [];
if ($dateStart !== '') {
    $creditsDateCriteria[] = ['end_date' => ['>=', $dateStart]];
}
if ($dateStop !== '') {
    $creditsDateCriteria[] = ['end_date' => ['<=', $dateStop]];
}
$baseCreditsWhere = [];
if (!empty($creditsDateCriteria)) {
    $baseCreditsWhere['AND'] = $creditsDateCriteria;
}

$formatNumber = static function ($value, int $decimals = 0): string {
    return Html::formatNumber((float) $value, false, $decimals);
};
$formatPercent = static function ($value) use ($formatNumber): string {
    return $formatNumber($value, 2) . ' %';
};

$globalTotals = [
    'total' => 0,
    'total_sold' => 0,
    'total_used' => 0,
    'used_count' => 0,
    'unused_count' => 0,
    'active_count' => 0,
    'inactive_count' => 0,
    'prov_count' => 0,
    'status_ok' => 0,
    'status_warning' => 0,
    'status_over' => 0,
    'status_expired' => 0,
];
$statusCounts = [
    'OK'      => 0,
    'WARNING' => 0,
    'OVER'    => 0,
    'EXPIRED' => 0,
];
$provTotals = ['total' => 0];
$entityTotals = [];
$entityStatusCounts = [];
$provEntityTotals = [];
$entityCredits = [];
$topCredits = [];
$entityLabels = [];
$topEntities = [];
$topEntityLabels = [];

$statsSelect = [
    new QueryExpression('COUNT(*) AS total'),
    new QueryExpression('COALESCE(SUM(quantity_sold), 0) AS total_sold'),
    new QueryExpression('COALESCE(SUM(quantity_used), 0) AS total_used'),
    new QueryExpression('COALESCE(SUM(CASE WHEN quantity_used > 0 THEN 1 ELSE 0 END), 0) AS used_count'),
    new QueryExpression('COALESCE(SUM(CASE WHEN quantity_used = 0 THEN 1 ELSE 0 END), 0) AS unused_count'),
    new QueryExpression('COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count'),
    new QueryExpression('COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) AS inactive_count'),
    new QueryExpression("COALESCE(SUM(CASE WHEN client_label LIKE 'PROV%' THEN 1 ELSE 0 END), 0) AS prov_count"),
    new QueryExpression("COALESCE(SUM(CASE WHEN status = 'OK' THEN 1 ELSE 0 END), 0) AS status_ok"),
    new QueryExpression("COALESCE(SUM(CASE WHEN status = 'WARNING' THEN 1 ELSE 0 END), 0) AS status_warning"),
    new QueryExpression("COALESCE(SUM(CASE WHEN status = 'OVER' THEN 1 ELSE 0 END), 0) AS status_over"),
    new QueryExpression("COALESCE(SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END), 0) AS status_expired"),
];

if ($shouldRunCredits) {
    $globalTotalsCriteria = [
        'SELECT' => $statsSelect,
        'FROM' => $creditsView,
    ];
    if (!empty($baseCreditsWhere)) {
        $globalTotalsCriteria['WHERE'] = $baseCreditsWhere;
    }
    $globalTotals = $DB->request($globalTotalsCriteria)->current() ?: [];

    $statusCounts = [
        'OK'      => (int) ($globalTotals['status_ok'] ?? 0),
        'WARNING' => (int) ($globalTotals['status_warning'] ?? 0),
        'OVER'    => (int) ($globalTotals['status_over'] ?? 0),
        'EXPIRED' => (int) ($globalTotals['status_expired'] ?? 0),
    ];
    $provTotals = [
        'total' => (int) ($globalTotals['prov_count'] ?? 0),
    ];

    if ($statsEntityId > 0) {
        $entityWhere = $baseCreditsWhere;
        $entityWhere['entities_id'] = $entityScope;
        $entityTotals = $DB->request([
            'SELECT' => $statsSelect,
            'FROM' => $creditsView,
            'WHERE' => $entityWhere,
        ])->current() ?: [];

        $entityStatusCounts = [
            'OK'      => (int) ($entityTotals['status_ok'] ?? 0),
            'WARNING' => (int) ($entityTotals['status_warning'] ?? 0),
            'OVER'    => (int) ($entityTotals['status_over'] ?? 0),
            'EXPIRED' => (int) ($entityTotals['status_expired'] ?? 0),
        ];
        $provEntityTotals = [
            'total' => (int) ($entityTotals['prov_count'] ?? 0),
        ];

        $pagerParams = $_GET;
        unset($pagerParams['start']);
        $pagerQuery = http_build_query($pagerParams);
        $pagerTarget = $CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php';
        $entityCreditsTotal = (int) ($entityTotals['total'] ?? 0);

        foreach ($DB->request([
            'SELECT' => [
                'id',
                'client_label',
                'quantity_sold',
                'quantity_used',
                'percentage_used',
                'status',
                'end_date',
                'is_active',
            ],
            'FROM' => $creditsView,
            'WHERE' => $entityWhere,
            'ORDER' => ['client_label'],
            'START' => $start,
            'LIMIT' => $listLimit,
        ]) as $row) {
            $entityCredits[] = $row;
        }
    }

    if ($statsEntityId <= 0) {
        $topCreditsCriteria = [
            'SELECT' => [
                'id',
                'client_label',
                'entities_id',
                'quantity_sold',
                'quantity_used',
                'percentage_used',
                'status',
                'end_date',
                'is_active',
            ],
            'FROM' => $creditsView,
            'ORDER' => [
                new QueryExpression('percentage_used DESC'),
                new QueryExpression('quantity_used DESC'),
            ],
            'LIMIT' => 5,
        ];
        if (!empty($baseCreditsWhere)) {
            $topCreditsCriteria['WHERE'] = $baseCreditsWhere;
        }
        foreach ($DB->request($topCreditsCriteria) as $row) {
            $entityId = (int) ($row['entities_id'] ?? 0);
            if ($entityId > 0 && !array_key_exists($entityId, $entityLabels)) {
                $entityLabels[$entityId] = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(
                    Dropdown::getDropdownName('glpi_entities', $entityId)
                );
            }
            $row['entity_label'] = $entityLabels[$entityId] ?? '';
            $topCredits[] = $row;
        }

        $topEntitiesCriteria = [
            'SELECT' => [
                'entities_id',
                new QueryExpression('COALESCE(SUM(quantity_used), 0) AS total_used'),
                new QueryExpression('COALESCE(SUM(quantity_sold), 0) AS total_sold'),
            ],
            'FROM' => $creditsView,
            'GROUPBY' => ['entities_id'],
            'ORDER' => [new QueryExpression('total_used DESC')],
            'LIMIT' => 8,
        ];
        if (!empty($baseCreditsWhere)) {
            $topEntitiesCriteria['WHERE'] = $baseCreditsWhere;
        }
        foreach ($DB->request($topEntitiesCriteria) as $row) {
            $entityId = (int) ($row['entities_id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }
            if (!array_key_exists($entityId, $topEntityLabels)) {
                $topEntityLabels[$entityId] = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(
                    Dropdown::getDropdownName('glpi_entities', $entityId)
                );
            }
            $topEntities[] = [
                'name'  => $topEntityLabels[$entityId] ?? (string) $entityId,
                'value' => (float) ($row['total_used'] ?? 0),
            ];
        }
    }
}

$statusLabels = [
    'OK'      => __('OK', 'stats'),
    'WARNING' => __('Alerte', 'stats'),
    'OVER'    => __('Depassement', 'stats'),
    'EXPIRED' => __('Expire', 'stats'),
];
$statusBadge = [
    'OK'      => 'badge bg-success',
    'WARNING' => 'badge bg-warning text-dark',
    'OVER'    => 'badge bg-danger',
    'EXPIRED' => 'badge bg-secondary',
];

$globalSold = (float) ($globalTotals['total_sold'] ?? 0);
$globalUsed = (float) ($globalTotals['total_used'] ?? 0);
$globalPercent = $globalSold > 0 ? round(($globalUsed / $globalSold) * 100, 2) : 0.0;

$entitySold = (float) ($entityTotals['total_sold'] ?? 0);
$entityUsed = (float) ($entityTotals['total_used'] ?? 0);
$entityPercent = $entitySold > 0 ? round(($entityUsed / $entitySold) * 100, 2) : 0.0;

$chartTotals = $statsEntityId > 0 ? $entityTotals : $globalTotals;
$chartStatusCounts = $statsEntityId > 0
    ? array_replace(array_fill_keys(array_keys($statusCounts), 0), $entityStatusCounts)
    : $statusCounts;
$chartProvTotals = $statsEntityId > 0 ? $provEntityTotals : $provTotals;

echo "<div class='card mb-3'><div class='card-body'>";
echo "<form method='get' action='" . $CFG_GLPI['root_doc'] . "/plugins/stats/front/stats.php' class='row g-3 align-items-end stats-filters'>";
echo Html::hidden('view', ['value' => 'credits']);
echo Html::hidden('run', ['value' => 1]);
echo "<div class='col-md-6'>";
echo "<label class='form-label mb-1'>" . __('Entite (inclut les sous-entites)', 'stats') . "</label>";
Dropdown::show('Entity', [
    'name'  => 'stats_entities_id',
    'value' => $statsEntityId,
    'width' => '100%',
]);
echo "</div>";
echo "<div class='col-md-3'>";
echo "<label class='form-label mb-1'>" . __('Date de debut', 'stats') . "</label>";
Html::showDateField('date_begin', [
    'value'       => $dateBegin,
    'placeholder' => __('Date de debut', 'stats'),
    'display'     => true,
]);
echo "</div>";
echo "<div class='col-md-3'>";
echo "<label class='form-label mb-1'>" . __('Date de fin', 'stats') . "</label>";
Html::showDateField('date_end', [
    'value'       => $dateEnd,
    'placeholder' => __('Date de fin', 'stats'),
    'display'     => true,
]);
echo "</div>";
echo "<div class='col-12 text-end'>";
echo Html::submit(__('Filtrer', 'stats'), ['class' => 'btn btn-primary']);
echo "</div>";
echo "</form>";
echo "</div></div>";

if (!$shouldRunCredits) {
    echo "<div class='alert alert-info'>";
    echo __('Appliquez un filtre puis cliquez sur Filtrer pour charger les statistiques.', 'stats');
    echo "</div>";
    if ($previousListLimit !== null) {
        $_SESSION['glpilist_limit'] = $previousListLimit;
    } else {
        unset($_SESSION['glpilist_limit']);
    }
    Html::footer();
    return;
}

$statusChartData = [];
foreach ($statusLabels as $status => $label) {
    $statusChartData[] = [
        'name'  => $label,
        'value' => (int) ($chartStatusCounts[$status] ?? 0),
    ];
}
$activeChartData = [
    [
        'name'  => __('Actifs', 'stats'),
        'value' => (int) ($chartTotals['active_count'] ?? 0),
    ],
    [
        'name'  => __('Inactifs', 'stats'),
        'value' => (int) ($chartTotals['inactive_count'] ?? 0),
    ],
];
$usageChartData = [
    [
        'name'  => __('Avec consommation', 'stats'),
        'value' => (int) ($chartTotals['used_count'] ?? 0),
    ],
    [
        'name'  => __('Sans consommation', 'stats'),
        'value' => (int) ($chartTotals['unused_count'] ?? 0),
    ],
];
$provTotal = (int) ($chartProvTotals['total'] ?? 0);
$provChartData = [
    [
        'name'  => __('Credits PROV', 'stats'),
        'value' => $provTotal,
    ],
    [
        'name'  => __('Autres credits', 'stats'),
        'value' => max(0, (int) ($chartTotals['total'] ?? 0) - $provTotal),
    ],
];

$tooltipCreditStatus = __s('Repartition des credits par statut (OK, Warning, Over, Expired), selon la periode et l entite choisies.');
$tooltipCreditActive = __s('Repartition des credits actifs et inactifs, selon la periode et l entite choisies.');
$tooltipCreditUsage = __s('Repartition des credits avec consommation ou sans consommation, selon la periode et l entite choisies.');
$tooltipCreditProv = __s('Part des credits PROV, selon la periode et l entite choisies.');
$tooltipTotalSold = __s('Total des credits vendus sur la periode (et sur l entite si un filtre est applique).');
$tooltipTotalUsed = __s('Total des credits consommes sur la periode (et sur l entite si un filtre est applique).');
$tooltipTotalPercent = __s('Pourcentage consomme = credits consommes / credits vendus, sur la periode.');
$tooltipTopEntitiesCredits = __s('Classement des entites selon le volume de credits consommes sur la periode.');
$tooltipStatsEntity = __s('Resume des credits pour l entite selectionnee, sur la periode.');
$tooltipEntityTotal = __s('Nombre total de credits pour l entite selectionnee.');
$tooltipEntitySold = __s('Total des credits vendus pour l entite selectionnee.');
$tooltipEntityUsed = __s('Total des credits consommes pour l entite selectionnee.');
$tooltipEntityPercent = __s('Pourcentage consomme pour l entite selectionnee.');
$tooltipEntityStatus = __s('Repartition des statuts des credits pour l entite selectionnee.');
$tooltipEntityCredits = __s('Liste des credits de l entite selectionnee (libelle, vendu, consomme, statut, expiration, actif).');
$tooltipTopCredits = __s('Liste des credits les plus consommes sur la periode.');

echo "<div class='row g-3 mb-3'>";
echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
echo "<div class='text-muted small mb-2'>" . __('Statuts des credits', 'stats') . $infoIcon($tooltipCreditStatus) . "</div>";
echo "<div id='stats_chart_status' style='height:240px'></div>";
echo "</div></div></div>";

echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
echo "<div class='text-muted small mb-2'>" . __('Credits actifs / inactifs', 'stats') . $infoIcon($tooltipCreditActive) . "</div>";
echo "<div id='stats_chart_active' style='height:240px'></div>";
echo "</div></div></div>";

echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
echo "<div class='text-muted small mb-2'>" . __('Credits avec consommation', 'stats') . $infoIcon($tooltipCreditUsage) . "</div>";
echo "<div id='stats_chart_usage' style='height:240px'></div>";
echo "</div></div></div>";

echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
echo "<div class='text-muted small mb-2'>" . __('Credits PROV', 'stats') . $infoIcon($tooltipCreditProv) . "</div>";
echo "<div id='stats_chart_prov' style='height:240px'></div>";
echo "</div></div></div>";
echo "</div>";

if ($statsEntityId <= 0) {
    echo "<div class='row g-3 mt-1'>";
    echo "<div class='col-md-4'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Quantite vendue totale', 'stats') . $infoIcon($tooltipTotalSold) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatNumber($globalSold, 2) . "</div>";
    echo "</div></div></div>";

    echo "<div class='col-md-4'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Quantite consommee totale', 'stats') . $infoIcon($tooltipTotalUsed) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatNumber($globalUsed, 2) . "</div>";
    echo "</div></div></div>";

    echo "<div class='col-md-4'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Consommation globale', 'stats') . $infoIcon($tooltipTotalPercent) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatPercent($globalPercent) . "</div>";
    echo "</div></div></div>";
    echo "</div>";
}

if ($statsEntityId <= 0 && !empty($topEntities)) {
    echo "<div class='card mt-3'><div class='card-body'>";
    echo "<div class='text-muted small mb-2'>" . __('Top entites par consommation', 'stats') . $infoIcon($tooltipTopEntitiesCredits) . "</div>";
    echo "<div id='stats_chart_entities' style='height:320px'></div>";
    echo "</div></div>";
}

if ($statsEntityId > 0) {
    $entityLabel = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(
        Dropdown::getDropdownName('glpi_entities', $statsEntityId)
    );
    echo "<div class='card mt-3'><div class='card-body'>";
    echo "<h3 class='h6 mb-3'>" . __('Statistiques pour', 'stats') . " " . htmlescape($entityLabel)
        . $infoIcon($tooltipStatsEntity) . "</h3>";
    echo "<div class='row g-3'>";

    echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Credits total', 'stats') . $infoIcon($tooltipEntityTotal) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatNumber($entityTotals['total'] ?? 0) . "</div>";
    echo "</div></div></div>";

    echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Quantite vendue', 'stats') . $infoIcon($tooltipEntitySold) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatNumber($entitySold, 2) . "</div>";
    echo "</div></div></div>";

    echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Quantite consommee', 'stats') . $infoIcon($tooltipEntityUsed) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatNumber($entityUsed, 2) . "</div>";
    echo "</div></div></div>";

    echo "<div class='col-md-3'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . __('Consommation', 'stats') . $infoIcon($tooltipEntityPercent) . "</div>";
    echo "<div class='fs-4 fw-semibold'>" . $formatPercent($entityPercent) . "</div>";
    echo "</div></div></div>";

    echo "</div>";

    if (!empty($entityStatusCounts)) {
        echo "<div class='mt-3'>";
        echo "<div class='text-muted small mb-2'>" . __('Statuts des credits', 'stats') . $infoIcon($tooltipEntityStatus) . "</div>";
        foreach ($statusLabels as $status => $label) {
            $count = $entityStatusCounts[$status] ?? 0;
            echo "<span class='" . ($statusBadge[$status] ?? 'badge bg-secondary') . " me-2'>"
                . htmlescape($label) . ' ' . $formatNumber($count) . "</span>";
        }
        echo "</div>";
    }

    if (!empty($entityCredits)) {
        echo "<div class='mt-4'>";
        echo "<h4 class='h6 mb-2'>" . __('Credits de l\'entite', 'stats') . $infoIcon($tooltipEntityCredits) . "</h4>";
        Html::printPager($start, $entityCreditsTotal, $pagerTarget, $pagerQuery);
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm'>";
        echo "<thead><tr>";
        echo "<th>" . __('Client / Libelle', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Quantite vendue', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('Quantite consommee', 'stats') . "</th>";
        echo "<th class='text-end'>" . __('% Consomme', 'stats') . "</th>";
        echo "<th>" . __('Statut', 'stats') . "</th>";
        echo "<th>" . __('Expiration', 'stats') . "</th>";
        echo "<th>" . __('Actif', 'stats') . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($entityCredits as $credit) {
            $status = (string) ($credit['status'] ?? '');
            $badge = $statusBadge[$status] ?? 'badge bg-secondary';
            $label = $statusLabels[$status] ?? $status;
            $activeLabel = ((int) ($credit['is_active'] ?? 0)) === 1
                ? __('Oui', 'stats')
                : __('Non', 'stats');
            $endDate = (string) ($credit['end_date'] ?? '');
            $endDate = $endDate !== '' ? substr($endDate, 0, 10) : '';

            echo "<tr>";
            echo "<td>" . htmlescape((string) ($credit['client_label'] ?? '')) . "</td>";
            echo "<td class='text-end'>" . $formatNumber($credit['quantity_sold'] ?? 0, 2) . "</td>";
            echo "<td class='text-end'>" . $formatNumber($credit['quantity_used'] ?? 0, 2) . "</td>";
            echo "<td class='text-end'>" . $formatPercent($credit['percentage_used'] ?? 0) . "</td>";
            echo "<td><span class='{$badge}'>" . htmlescape($label) . "</span></td>";
            echo "<td>" . htmlescape($endDate) . "</td>";
            echo "<td>" . htmlescape($activeLabel) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div></div>";
        Html::printPager($start, $entityCreditsTotal, $pagerTarget, $pagerQuery);
    }

    echo "</div></div>";
}

if ($statsEntityId <= 0 && !empty($topCredits)) {
    echo "<div class='card mt-3'><div class='card-body'>";
    echo "<h3 class='h6 mb-3'>" . __('Top credits consommes', 'stats') . $infoIcon($tooltipTopCredits) . "</h3>";
    echo "<div class='table-responsive'>";
    echo "<table class='table table-sm'>";
    echo "<thead><tr>";
    echo "<th>" . __('Credit', 'stats') . "</th>";
    echo "<th>" . __('Entite', 'stats') . "</th>";
    echo "<th class='text-end'>" . __('Vendu', 'stats') . "</th>";
    echo "<th class='text-end'>" . __('Consomme', 'stats') . "</th>";
    echo "<th class='text-end'>" . __('% Consomme', 'stats') . "</th>";
    echo "<th>" . __('Statut', 'stats') . "</th>";
    echo "</tr></thead><tbody>";
    foreach ($topCredits as $row) {
        $status = (string) ($row['status'] ?? '');
        $badge = $statusBadge[$status] ?? 'badge bg-secondary';
        $label = $statusLabels[$status] ?? $status;
        echo "<tr>";
        echo "<td>" . htmlescape((string) ($row['client_label'] ?? '')) . "</td>";
        echo "<td>" . htmlescape((string) ($row['entity_label'] ?? '')) . "</td>";
        echo "<td class='text-end'>" . $formatNumber($row['quantity_sold'] ?? 0, 2) . "</td>";
        echo "<td class='text-end'>" . $formatNumber($row['quantity_used'] ?? 0, 2) . "</td>";
        echo "<td class='text-end'>" . $formatPercent($row['percentage_used'] ?? 0) . "</td>";
        echo "<td><span class='{$badge}'>" . htmlescape($label) . "</span></td>";
        echo "</tr>";
    }
    echo "</tbody></table></div></div></div>";
}

$chartsPayload = [
    'status'   => $statusChartData,
    'active'   => $activeChartData,
    'usage'    => $usageChartData,
    'prov'     => $provChartData,
    'entities' => $topEntities,
];
$chartsJson = json_encode($chartsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$echartsUrls = [
    Html::cleanInputText($CFG_GLPI['root_doc'] . '/lib/echarts.min.js'),
    Html::cleanInputText($CFG_GLPI['root_doc'] . '/public/lib/echarts.min.js'),
    '/lib/echarts.min.js',
];
$echartsUrlsJson = json_encode($echartsUrls, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$chartsJs = <<<JS
(function() {
  var payload = {$chartsJson};
  var echartsUrls = {$echartsUrlsJson};
  var charts = [];

  var renderPie = function(targetId, data) {
    var el = document.getElementById(targetId);
    if (!el || !data || !data.length || !window.echarts) {
      return;
    }
    var instance = echarts.getInstanceByDom(el) || echarts.init(el);
    instance.setOption({
      tooltip: { trigger: 'item' },
      series: [{
        type: 'pie',
        radius: ['40%', '70%'],
        avoidLabelOverlap: true,
        label: { formatter: '{b}: {c}' },
        data: data
      }]
    });
    charts.push(instance);
  };

  var renderBar = function(targetId, data) {
    var el = document.getElementById(targetId);
    if (!el || !data || !data.length || !window.echarts) {
      return;
    }
    var instance = echarts.getInstanceByDom(el) || echarts.init(el);
    instance.setOption({
      tooltip: { trigger: 'axis' },
      grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
      xAxis: { type: 'value' },
      yAxis: {
        type: 'category',
        data: data.map(function(item) { return item.name; }),
        axisLabel: { interval: 0 }
      },
      series: [{
        type: 'bar',
        data: data.map(function(item) { return item.value; }),
        itemStyle: { color: '#3a7bd5' }
      }]
    });
    charts.push(instance);
  };

  var renderAll = function() {
    charts = [];
    renderPie('stats_chart_status', payload.status);
    renderPie('stats_chart_active', payload.active);
    renderPie('stats_chart_usage', payload.usage);
    renderPie('stats_chart_prov', payload.prov);
    renderBar('stats_chart_entities', payload.entities);
  };

  var loadEcharts = function(done) {
    if (window.echarts) {
      done();
      return;
    }
    var index = 0;
    var tryNext = function() {
      if (index >= echartsUrls.length) {
        return;
      }
      var script = document.createElement('script');
      script.src = echartsUrls[index++];
      script.onload = function() {
        if (window.echarts) {
          done();
        } else {
          tryNext();
        }
      };
      script.onerror = tryNext;
      document.head.appendChild(script);
    };
    tryNext();
  };

  var boot = function() {
    loadEcharts(function() {
      renderAll();
      window.addEventListener('resize', function() {
        charts.forEach(function(chart) { chart.resize(); });
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
JS;
echo Html::scriptBlock($chartsJs);

if ($previousListLimit !== null) {
    $_SESSION['glpilist_limit'] = $previousListLimit;
} else {
    unset($_SESSION['glpilist_limit']);
}
Html::footer();
