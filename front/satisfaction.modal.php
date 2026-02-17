<?php

include('../../../inc/includes.php');
/** @var array $CFG_GLPI */
/** @var DBmysql $DB */
global $CFG_GLPI, $DB;

Session::checkLoginUser();
Session::checkRight(PluginStatsProfile::$rightname, PluginStatsProfile::RIGHT_READ);

$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);
$isExport = (($_GET['export'] ?? '') === 'csv');

if (!in_array($type, ['tech', 'entity'], true) || $id <= 0) {
    if ($isExport) {
        header('Content-Type: text/plain; charset=utf-8');
        echo __('Parametres invalides.', 'stats');
        return;
    }
    Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);
    echo "<div class='alert alert-danger'>";
    echo __('Parametres invalides.', 'stats');
    echo "</div>";
    Html::popFooter();
    return;
}

$questionKey = (string) ($_GET['question_key'] ?? '');
if ($questionKey === '') {
    if ($isExport) {
        header('Content-Type: text/plain; charset=utf-8');
        echo __('Parametres invalides.', 'stats');
        return;
    }
    Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);
    echo "<div class='alert alert-danger'>";
    echo __('Parametres invalides.', 'stats');
    echo "</div>";
    Html::popFooter();
    return;
}

$questionType = 'rating';
$questionScale = 5;
if (!class_exists('PluginSatisfactionclientQuestion')) {
    $questionClass = GLPI_ROOT . '/plugins/satisfactionclient/inc/question.class.php';
    if (file_exists($questionClass)) {
        include_once $questionClass;
    }
}
if (class_exists('PluginSatisfactionclientQuestion')) {
    foreach (PluginSatisfactionclientQuestion::getActiveQuestions() as $question) {
        if (($question['key'] ?? '') === $questionKey) {
            $questionType = (string) ($question['type'] ?? $questionType);
            $questionScale = (int) ($question['scale'] ?? $questionScale);
            break;
        }
    }
} else {
    $questionsTable = 'glpi_plugin_satisfactionclient_questions';
    if ($DB->tableExists($questionsTable)) {
        $row = $DB->request([
            'SELECT' => ['question_type', 'scale'],
            'FROM' => $questionsTable,
            'WHERE' => ['question_key' => $questionKey],
            'LIMIT' => 1,
        ])->current();
        if (!empty($row)) {
            $questionType = (string) ($row['question_type'] ?? $questionType);
            $questionScale = (int) ($row['scale'] ?? $questionScale);
        }
    }
}

$dateBegin = $_GET['date_begin'] ?? '';
$dateEnd = $_GET['date_end'] ?? '';
$dateStart = $dateBegin !== '' ? $dateBegin . ' 00:00:00' : '';
$dateStop = $dateEnd !== '' ? $dateEnd . ' 23:59:59' : '';

$filterEntities = $_GET['entities_id'] ?? [];
if (!is_array($filterEntities)) {
    $filterEntities = [$filterEntities];
}
$filterEntities = array_values(array_filter(array_map('intval', $filterEntities)));

$filterTechs = $_GET['technicians_id'] ?? [];
if (!is_array($filterTechs)) {
    $filterTechs = [$filterTechs];
}
$filterTechs = array_values(array_filter(array_map('intval', $filterTechs)));

$answersTable = 'glpi_plugin_satisfactionclient_answers';
$ticketTable = 'glpi_tickets';
$questionsTable = 'glpi_plugin_satisfactionclient_questions';
if (!$DB->tableExists($answersTable)) {
    if ($isExport) {
        header('Content-Type: text/plain; charset=utf-8');
        echo __('Aucune donnee a afficher.', 'stats');
        return;
    }
    Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);
    echo "<div class='alert alert-danger'>";
    echo __('Aucune donnee a afficher.', 'stats');
    echo "</div>";
    Html::popFooter();
    return;
}

$entityScope = [];
$baseWhere = [
    "$answersTable.answer_value" => ['<>', ''],
];
if ($type === 'tech') {
    $baseWhere["$answersTable.users_id_technician"] = $id;
    if (!empty($filterEntities)) {
        foreach ($filterEntities as $entityId) {
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
        if (!empty($entityScope)) {
            $baseWhere["$answersTable.entities_id"] = $entityScope;
        }
    }
} else {
    $baseWhere["$answersTable.entities_id"] = $id;
    if (!empty($filterTechs)) {
        $baseWhere["$answersTable.users_id_technician"] = $filterTechs;
    }
}

$dateCriteria = [];
if ($dateStart !== '') {
    $dateCriteria[] = ["$answersTable.date_answered" => ['>=', $dateStart]];
}
if ($dateStop !== '') {
    $dateCriteria[] = ["$answersTable.date_answered" => ['<=', $dateStop]];
}
if (!empty($dateCriteria)) {
    $baseWhere['AND'] = $dateCriteria;
}

$responseWhere = $baseWhere;
$responseWhere["$answersTable.question_key"] = $questionKey;

$joins = [
    $ticketTable => [
        'ON' => [
            $ticketTable => 'id',
            $answersTable => 'tickets_id',
        ],
    ],
];

$listLimit = (int) ($_REQUEST['glpilist_limit'] ?? ($_SESSION['glpilist_limit'] ?? 30));
if ($listLimit <= 0) {
    $listLimit = 30;
}
$start = (int) ($_GET['start'] ?? 0);
$previousListLimit = $_SESSION['glpilist_limit'] ?? null;
$_SESSION['glpilist_limit'] = $listLimit;

$countRow = $DB->request([
    'SELECT' => [
        new QueryExpression(
            $isExport
                ? "COUNT(DISTINCT CONCAT($answersTable.tickets_id,'|',$answersTable.date_answered)) AS total"
                : "COUNT(DISTINCT $answersTable.tickets_id) AS total"
        ),
    ],
    'FROM' => $answersTable,
    'LEFT JOIN' => $joins,
    'WHERE' => $responseWhere,
])->current();
$totalRows = (int) ($countRow['total'] ?? 0);

$rows = [];
if ($isExport) {
    $rowsRequest = [
        'SELECT' => [
            "$answersTable.tickets_id AS tickets_id",
            "$ticketTable.name AS ticket_name",
            "$ticketTable.date AS ticket_date",
            "$ticketTable.status AS ticket_status",
            new QueryExpression("MAX($answersTable.entities_id) AS entities_id"),
            new QueryExpression("MAX($answersTable.users_id_technician) AS tech_id"),
            "$answersTable.date_answered AS date_answered",
        ],
        'FROM' => $answersTable,
        'LEFT JOIN' => $joins,
        'WHERE' => $responseWhere,
        'GROUPBY' => ["$answersTable.tickets_id", "$answersTable.date_answered"],
        'ORDER' => ["$answersTable.date_answered DESC"],
    ];
} else {
    $rowsRequest = [
        'SELECT' => [
            "$answersTable.tickets_id AS tickets_id",
            "$ticketTable.name AS ticket_name",
            "$ticketTable.date AS ticket_date",
            "$ticketTable.status AS ticket_status",
            new QueryExpression("MAX($answersTable.entities_id) AS entities_id"),
            new QueryExpression("MAX($answersTable.users_id_technician) AS tech_id"),
            new QueryExpression("MAX($answersTable.date_answered) AS date_answered"),
        ],
        'FROM' => $answersTable,
        'LEFT JOIN' => $joins,
        'WHERE' => $responseWhere,
        'GROUPBY' => ["$answersTable.tickets_id"],
        'ORDER' => ["date_answered DESC"],
    ];
}
if (!$isExport) {
    $rowsRequest['START'] = $start;
    $rowsRequest['LIMIT'] = $listLimit;
}
foreach ($DB->request($rowsRequest) as $row) {
    $rows[] = $row;
}

$title = '';
if ($type === 'tech') {
    $title = sprintf(__('Satisfaction du technicien %s', 'stats'), getUserName($id));
} else {
    $entityLabel = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(
        Dropdown::getDropdownName('glpi_entities', $id)
    );
    $title = sprintf(__('Satisfaction de l entite %s', 'stats'), $entityLabel);
}

$formatAnswer = static function (string $value) use ($questionType, $questionScale): string {
    $value = trim($value);
    if ($value === '') {
        return '-';
    }
    if ($questionType === 'yesno') {
        return $value === '1' ? __('Oui', 'stats') : __('Non', 'stats');
    }
    if ($questionType === 'rating') {
        return $value . ' / ' . $questionScale;
    }
    return $value;
};

$answerLookup = [];
foreach ($DB->request([
    'SELECT' => [
        "$answersTable.tickets_id AS ticket_id",
        "$answersTable.date_answered AS date_answered",
        "$answersTable.answer_value AS answer_value",
        "$answersTable.answer_text AS answer_text",
    ],
    'FROM' => $answersTable,
    'WHERE' => $responseWhere,
]) as $row) {
    $ticketId = (int) ($row['ticket_id'] ?? 0);
    $dateAnsweredRaw = (string) ($row['date_answered'] ?? '');
    if ($ticketId <= 0 || $dateAnsweredRaw === '') {
        continue;
    }
    $answerText = trim((string) ($row['answer_text'] ?? ''));
    $answerValue = trim((string) ($row['answer_value'] ?? ''));
    $value = $answerText !== '' ? $answerText : $answerValue;
    $answerLookup[$ticketId . '|' . $dateAnsweredRaw] = $formatAnswer($value);
}

if ($isExport) {
    $formatAnswerCsv = static function (string $value, string $type, bool $looksNumeric): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $type = strtolower(trim($type));
        if (in_array($type, ['yesno', 'yes_no', 'boolean', 'bool'], true)) {
            return $value === '1' ? __('Oui', 'stats') : __('Non', 'stats');
        }
        if ($type === 'rating') {
            return $value;
        }
        if (!$looksNumeric && ($value === '0' || $value === '1')) {
            return $value === '1' ? __('Oui', 'stats') : __('Non', 'stats');
        }
        return $value;
    };

    $detailsByTicket = [];
    $questionMeta = [];
    $questionHeaders = [];
    $questionInfoByKey = [];
    if (class_exists('PluginSatisfactionclientQuestion')) {
        foreach (PluginSatisfactionclientQuestion::getAll() as $question) {
            $key = (string) ($question['question_key'] ?? $question['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $questionInfoByKey[$key] = [
                'label' => (string) ($question['question_label'] ?? $question['label'] ?? $key),
                'type' => (string) ($question['question_type'] ?? $question['type'] ?? ''),
                'scale' => (int) ($question['scale'] ?? 5),
            ];
        }
    } elseif ($DB->tableExists($questionsTable)) {
        foreach ($DB->request([
            'SELECT' => ['question_key', 'question_label', 'question_type', 'scale'],
            'FROM' => $questionsTable,
            'ORDER' => ['position ASC', 'id ASC'],
        ]) as $row) {
            $key = (string) ($row['question_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $questionInfoByKey[$key] = [
                'label' => (string) ($row['question_label'] ?? $key),
                'type' => (string) ($row['question_type'] ?? ''),
                'scale' => (int) ($row['scale'] ?? 5),
            ];
        }
    }
    $ticketIds = [];
    foreach ($rows as $row) {
        $ticketId = (int) ($row['tickets_id'] ?? 0);
        if ($ticketId > 0) {
            $ticketIds[$ticketId] = true;
        }
    }
    $ticketIds = array_keys($ticketIds);

    if (!empty($ticketIds)) {
        $detailSelect = [
            "$answersTable.tickets_id AS ticket_id",
            "$answersTable.question_key AS question_key",
            "$answersTable.question_label AS question_label",
            "$answersTable.answer_value AS answer_value",
            "$answersTable.answer_text AS answer_text",
            "$answersTable.date_answered AS date_answered",
        ];
        $detailRequest = [
            'SELECT' => $detailSelect,
            'FROM' => $answersTable,
            'WHERE' => [
                "$answersTable.tickets_id" => $ticketIds,
            ],
            'ORDER' => [
                "$answersTable.date_answered DESC",
                "$answersTable.id DESC",
            ],
        ];
        if ($type === 'tech') {
            $detailRequest['WHERE']["$answersTable.users_id_technician"] = $id;
            if (!empty($entityScope)) {
                $detailRequest['WHERE']["$answersTable.entities_id"] = $entityScope;
            }
        } else {
            $detailRequest['WHERE']["$answersTable.entities_id"] = $id;
            if (!empty($filterTechs)) {
                $detailRequest['WHERE']["$answersTable.users_id_technician"] = $filterTechs;
            }
        }
        if (!empty($dateCriteria)) {
            $detailRequest['WHERE']['AND'] = $dateCriteria;
        }
        if ($DB->tableExists($questionsTable)) {
            $detailRequest['SELECT'][] = "$questionsTable.question_type AS question_type";
            $detailRequest['SELECT'][] = "$questionsTable.scale AS question_scale";
            $detailRequest['LEFT JOIN'] = [
                $questionsTable => [
                    'ON' => [
                        $questionsTable => 'question_key',
                        $answersTable => 'question_key',
                    ],
                ],
            ];
        }

        foreach ($DB->request($detailRequest) as $row) {
            $ticketId = (int) ($row['ticket_id'] ?? 0);
            $questionKey = (string) ($row['question_key'] ?? '');
            $dateKey = (string) ($row['date_answered'] ?? '');
            if ($ticketId <= 0 || $questionKey === '' || $dateKey === '') {
                continue;
            }
            if (!isset($questionMeta[$questionKey])) {
                $meta = $questionInfoByKey[$questionKey] ?? null;
                $label = trim((string) (($meta['label'] ?? '') ?: ($row['question_label'] ?? '')));
                if ($label === '') {
                    $label = $questionKey;
                }
                $questionMeta[$questionKey] = [
                    'label' => $label,
                    'type' => (string) (($meta['type'] ?? '') ?: ($row['question_type'] ?? '')),
                    'scale' => (int) (($meta['scale'] ?? 0) ?: ($row['question_scale'] ?? 5)),
                ];
            }
            if (!isset($detailsByTicket[$ticketId])) {
                $detailsByTicket[$ticketId] = [];
            }
            if (!isset($detailsByTicket[$ticketId][$dateKey])) {
                $detailsByTicket[$ticketId][$dateKey] = [];
            }
            if (!array_key_exists($questionKey, $detailsByTicket[$ticketId][$dateKey])) {
                $answerText = trim((string) ($row['answer_text'] ?? ''));
                $answerValue = trim((string) ($row['answer_value'] ?? ''));
                $rawValue = $answerText !== '' ? $answerText : $answerValue;
                $meta = $questionMeta[$questionKey] ?? null;
                $questionTypeRow = (string) (($meta['type'] ?? '') ?: ($row['question_type'] ?? ''));
                $labelLower = strtolower(trim((string) (($meta['label'] ?? '') ?: ($row['question_label'] ?? ''))));
                $looksNumeric = ($labelLower !== '')
                    && (strpos($labelLower, 'note') !== false
                        || strpos($labelLower, 'rating') !== false
                        || strpos($labelLower, '1-') !== false
                        || strpos($labelLower, '1 -') !== false);
                $answerLabel = $rawValue;
                if ($answerLabel === '') {
                    $answerLabel = '';
                } else {
                    $answerLabel = $formatAnswerCsv($answerLabel, $questionTypeRow, $looksNumeric);
                }
                $detailsByTicket[$ticketId][$dateKey][$questionKey] = $answerLabel;
            }
        }
    }

    $questionOrder = [];
    if (class_exists('PluginSatisfactionclientQuestion')) {
        foreach (PluginSatisfactionclientQuestion::getActiveQuestions() as $question) {
            if (!empty($question['key'])) {
                $questionOrder[] = $question['key'];
            }
        }
    } elseif ($DB->tableExists($questionsTable)) {
        foreach ($DB->request([
            'SELECT' => ['question_key'],
            'FROM' => $questionsTable,
            'WHERE' => [
                'is_active' => 1,
            ],
            'ORDER' => ['position ASC', 'id ASC'],
        ]) as $row) {
            if (!empty($row['question_key'])) {
                $questionOrder[] = $row['question_key'];
            }
        }
    }
    foreach ($questionMeta as $key => $_meta) {
        if (!in_array($key, $questionOrder, true)) {
            $questionOrder[] = $key;
        }
    }

    $usedLabels = [];
    foreach ($questionOrder as $key) {
        if (!isset($questionMeta[$key])) {
            continue;
        }
        $label = trim((string) ($questionMeta[$key]['label'] ?? ''));
        if ($label === '') {
            $label = $key;
        }
        $base = $label;
        $suffix = 2;
        while (in_array($label, $usedLabels, true)) {
            $label = $base . ' (' . $suffix . ')';
            $suffix++;
        }
        $usedLabels[] = $label;
        $questionHeaders[$key] = $label;
    }

    $filename = $type === 'tech'
        ? 'stats_satisfaction_technicien.csv'
        : 'stats_satisfaction_entite.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $headers = [
        __('ID', 'stats'),
        __('Ticket', 'stats'),
        __('Entite', 'stats'),
        __('Technicien', 'stats'),
        __('Date satisfaction', 'stats'),
        __('Reponse', 'stats'),
    ];
    foreach ($questionHeaders as $label) {
        $headers[] = $label;
    }
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        $ticketId = (int) ($row['tickets_id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }
        $ticketName = (string) ($row['ticket_name'] ?? '');
        $ticketLabel = $ticketName !== '' ? $ticketName : sprintf(__('Ticket %d', 'stats'), $ticketId);
        $entityId = (int) ($row['entities_id'] ?? 0);
        $entityLabel = $entityId > 0
            ? \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(Dropdown::getDropdownName('glpi_entities', $entityId))
            : '';
        $techId = (int) ($row['tech_id'] ?? 0);
        $techName = $techId > 0 ? getUserName($techId) : '';
        $dateAnsweredRaw = (string) ($row['date_answered'] ?? '');
        $dateAnswered = $dateAnsweredRaw !== '' ? substr($dateAnsweredRaw, 0, 16) : '';
        $answerLabel = $answerLookup[$ticketId . '|' . ($row['date_answered'] ?? '')] ?? '';
        $csvRow = [
            $ticketId,
            $ticketLabel,
            $entityLabel,
            $techName,
            $dateAnswered,
            $answerLabel,
        ];
        $detailRow = $dateAnsweredRaw !== ''
            ? ($detailsByTicket[$ticketId][$dateAnsweredRaw] ?? [])
            : [];
        foreach ($questionHeaders as $key => $_label) {
            $csvRow[] = $detailRow[$key] ?? '';
        }
        fputcsv($out, $csvRow, ';');
    }
    fclose($out);
    if ($previousListLimit !== null) {
        $_SESSION['glpilist_limit'] = $previousListLimit;
    } else {
        unset($_SESSION['glpilist_limit']);
    }
    return;
}

Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);

if (empty($rows)) {
    echo "<div class='alert alert-warning'>";
    echo __('Aucune donnee a afficher.', 'stats');
    echo "</div>";
    if ($previousListLimit !== null) {
        $_SESSION['glpilist_limit'] = $previousListLimit;
    } else {
        unset($_SESSION['glpilist_limit']);
    }
    Html::popFooter();
    return;
}

echo "<div class='mb-3 fw-semibold' style='display:none'>" . htmlescape($title) . "</div>";
if ($totalRows > 0) {
    $pagerParams = $_GET;
    unset($pagerParams['start']);
    $pagerQuery = http_build_query($pagerParams);
    $pagerTarget = $CFG_GLPI['root_doc'] . '/plugins/stats/front/satisfaction.modal.php';
    Html::printPager($start, $totalRows, $pagerTarget, $pagerQuery);
}
if ($totalRows > 0) {
    $exportParams = $_GET;
    unset($exportParams['start'], $exportParams['export']);
    $exportParams['export'] = 'csv';
    $exportUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/satisfaction.modal.php?' . http_build_query($exportParams);
    echo "<div class='d-flex justify-content-end mb-2'>";
    echo "<a class='btn btn-sm btn-outline-secondary stats-export-btn' href='" . htmlescape($exportUrl) . "'"
        . " title='" . htmlescape(__('Export CSV')) . "' aria-label='" . htmlescape(__('Export CSV')) . "'>";
    echo "<span class='export-icon'><i class='ti ti-download'></i></span>";
    echo "<span class='export-spinner spinner-border spinner-border-sm d-none' role='status' aria-hidden='true'></span>";
    echo "<span class='visually-hidden'>" . __('Export CSV') . "</span>";
    echo "</a>";
    echo "</div>";
}

echo "<div class='table-responsive'>";
echo "<table class='table table-sm'>";
echo "<thead><tr>";
echo "<th>" . __('ID', 'stats') . "</th>";
echo "<th>" . __('Ticket', 'stats') . "</th>";
echo "<th>" . __('Entite', 'stats') . "</th>";
echo "<th>" . __('Technicien', 'stats') . "</th>";
echo "<th>" . __('Date satisfaction', 'stats') . "</th>";
echo "<th class='text-center'>" . __('Details', 'stats') . "</th>";
echo "<th class='text-end'>" . __('Reponse', 'stats') . "</th>";
echo "</tr></thead><tbody>";

foreach ($rows as $row) {
    $ticketId = (int) ($row['tickets_id'] ?? 0);
    if ($ticketId <= 0) {
        continue;
    }
    $ticketName = (string) ($row['ticket_name'] ?? '');
    $ticketUrl = $ticketId > 0 ? Ticket::getFormURLWithID($ticketId) : '#';
    $ticketLabel = $ticketName !== '' ? $ticketName : sprintf(__('Ticket %d', 'stats'), $ticketId);
    $entityId = (int) ($row['entities_id'] ?? 0);
    $entityLabel = $entityId > 0
        ? \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(Dropdown::getDropdownName('glpi_entities', $entityId))
        : '';
    $techId = (int) ($row['tech_id'] ?? 0);
    $techName = $techId > 0 ? getUserName($techId) : '';
    $dateAnsweredRaw = (string) ($row['date_answered'] ?? '');
    $dateAnswered = $dateAnsweredRaw !== '' ? substr($dateAnsweredRaw, 0, 16) : '';
    $answerLabel = $answerLookup[$ticketId . '|' . $dateAnsweredRaw] ?? '-';
    $detailParams = [
        'ticket_id' => $ticketId,
        'question_key' => $questionKey,
        'date_begin' => $dateBegin,
        'date_end' => $dateEnd,
    ];
    $detailUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/satisfaction.details.php?' . http_build_query($detailParams);
    $detailTitle = sprintf(__('Satisfaction du ticket %d', 'stats'), $ticketId);

    echo "<tr>";
    echo "<td>" . htmlescape((string) $ticketId) . "</td>";
    echo "<td><a href='" . htmlescape($ticketUrl) . "' target='_blank'>"
        . htmlescape($ticketLabel) . "</a></td>";
    echo "<td>" . htmlescape($entityLabel) . "</td>";
    echo "<td>" . htmlescape($techName) . "</td>";
    echo "<td>" . htmlescape($dateAnswered) . "</td>";
    echo "<td class='text-center'>";
    echo "<a class='btn btn-sm btn-outline-secondary stats-satisfaction-detail' href='#'"
        . " data-modal-url='" . htmlescape($detailUrl) . "' data-modal-title='"
        . htmlescape($detailTitle) . "'>";
    echo "<i class='ti ti-eye'></i>";
    echo "</a>";
    echo "</td>";
    echo "<td class='text-end'>" . htmlescape($answerLabel) . "</td>";
    echo "</tr>";
}

echo "</tbody></table></div>";
if ($totalRows > 0) {
    Html::printPager($start, $totalRows, $pagerTarget, $pagerQuery);
}

$detailModalHtml = <<<HTML
    <div id="statsSatisfactionDetailsModal" class="modal fade" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" style="max-width:75vw;width:75vw;height:90vh;">
        <div class="modal-content" style="height:90vh;">
        <div class="modal-header">
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            <h3 id="statsSatisfactionDetailsModalTitle"></h3>
        </div>
        <div class="modal-body" style="height:calc(90vh - 60px);overflow:auto;">
            <iframe id="statsSatisfactionDetailsModalFrame" class="iframe hidden" style="width:100%;height:100%;" frameborder="0"></iframe>
        </div>
        </div>
    </div>
    </div>
HTML;
echo $detailModalHtml;

$detailModalJs = <<<JS
(function() {
  document.addEventListener('click', function(e) {
    var link = e.target.closest('.stats-satisfaction-detail');
    if (!link) {
      return;
    }
    e.preventDefault();
    var modalEl = document.getElementById('statsSatisfactionDetailsModal');
    if (!modalEl) {
      return;
    }
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var iframe = document.getElementById('statsSatisfactionDetailsModalFrame');
    if (iframe) {
      iframe.setAttribute('src', link.getAttribute('data-modal-url') || '');
      iframe.classList.remove('hidden');
    }
    var titleEl = document.getElementById('statsSatisfactionDetailsModalTitle');
    if (titleEl) {
      titleEl.textContent = link.getAttribute('data-modal-title') || '';
    }
    modal.show();
  });
})();
JS;
echo Html::scriptBlock($detailModalJs);

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

if ($previousListLimit !== null) {
    $_SESSION['glpilist_limit'] = $previousListLimit;
} else {
    unset($_SESSION['glpilist_limit']);
}
Html::popFooter();
