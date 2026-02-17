<?php

include('../../../inc/includes.php');
/** @var array $CFG_GLPI */
/** @var DBmysql $DB */
global $CFG_GLPI, $DB;

Session::checkLoginUser();
Session::checkRight(PluginStatsProfile::$rightname, PluginStatsProfile::RIGHT_READ);

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);
    echo "<div class='alert alert-danger'>";
    echo __('Parametres invalides.', 'stats');
    echo "</div>";
    Html::popFooter();
    return;
}

$questionKey = (string) ($_GET['question_key'] ?? '');

$dateBegin = $_GET['date_begin'] ?? '';
$dateEnd = $_GET['date_end'] ?? '';
$dateStart = $dateBegin !== '' ? $dateBegin . ' 00:00:00' : '';
$dateStop = $dateEnd !== '' ? $dateEnd . ' 23:59:59' : '';

$answersTable = 'glpi_plugin_satisfactionclient_answers';
$questionsTable = 'glpi_plugin_satisfactionclient_questions';
if (!$DB->tableExists($answersTable)) {
    Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);
    echo "<div class='alert alert-danger'>";
    echo __('Aucune donnee a afficher.', 'stats');
    echo "</div>";
    Html::popFooter();
    return;
}

$where = [
    "$answersTable.tickets_id" => $ticketId,
];
$dateCriteria = [];
if ($dateStart !== '') {
    $dateCriteria[] = ["$answersTable.date_answered" => ['>=', $dateStart]];
}
if ($dateStop !== '') {
    $dateCriteria[] = ["$answersTable.date_answered" => ['<=', $dateStop]];
}
if (!empty($dateCriteria)) {
    $where['AND'] = $dateCriteria;
}

$allowedDates = [];
if ($questionKey !== '') {
    $dateWhere = [
        "$answersTable.tickets_id" => $ticketId,
        "$answersTable.question_key" => $questionKey,
    ];
    if (!empty($dateCriteria)) {
        $dateWhere['AND'] = $dateCriteria;
    }
    foreach ($DB->request([
        'SELECT' => [
            new QueryExpression("DISTINCT $answersTable.date_answered AS date_answered"),
        ],
        'FROM' => $answersTable,
        'WHERE' => $dateWhere,
    ]) as $row) {
        $dateValue = (string) ($row['date_answered'] ?? '');
        if ($dateValue !== '') {
            $allowedDates[$dateValue] = true;
        }
    }
    if (empty($allowedDates)) {
        Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);
        echo "<div class='alert alert-warning'>";
        echo __('Aucune donnee a afficher.', 'stats');
        echo "</div>";
        Html::popFooter();
        return;
    }
    $where["$answersTable.date_answered"] = array_keys($allowedDates);
}

$joins = [];
$select = [
    "$answersTable.question_key AS question_key",
    "$answersTable.question_label AS question_label",
    "$answersTable.answer_value AS answer_value",
    "$answersTable.answer_text AS answer_text",
    "$answersTable.date_answered AS date_answered",
    "$answersTable.users_id_requester AS requester_id",
    "$answersTable.users_id_technician AS tech_id",
];
if ($DB->tableExists($questionsTable)) {
    $joins = [
        $questionsTable => [
            'ON' => [
                $questionsTable => 'question_key',
                $answersTable => 'question_key',
            ],
        ],
    ];
    $select[] = "$questionsTable.question_type AS question_type";
    $select[] = "$questionsTable.scale AS question_scale";
    $select[] = "$questionsTable.question_label AS question_label_current";
}

$request = [
    'SELECT' => $select,
    'FROM' => $answersTable,
    'WHERE' => $where,
    'ORDER' => [
        "$answersTable.date_answered DESC",
        "$answersTable.id ASC",
    ],
];
if (!empty($joins)) {
    $request['LEFT JOIN'] = $joins;
}

$rows = [];
foreach ($DB->request($request) as $row) {
    $rows[] = $row;
}

Html::popHeader(__('Details satisfaction', 'stats'), $_SERVER['PHP_SELF'], true);

if (empty($rows)) {
    echo "<div class='alert alert-warning'>";
    echo __('Aucune donnee a afficher.', 'stats');
    echo "</div>";
    Html::popFooter();
    return;
}

echo "<div class='table-responsive'>";
echo "<table class='table table-sm'>";
echo "<thead><tr>";
echo "<th>" . __('Question', 'stats') . "</th>";
echo "<th>" . __('Reponse', 'stats') . "</th>";
echo "<th>" . __('Demandeur', 'stats') . "</th>";
echo "</tr></thead><tbody>";

    $currentGroupDate = null;
    foreach ($rows as $row) {
    $questionLabel = trim((string) ($row['question_label_current'] ?? ''));
    if ($questionLabel === '') {
        $questionLabel = (string) ($row['question_label'] ?? '');
    }
    $answerText = trim((string) ($row['answer_text'] ?? ''));
    $answerValue = trim((string) ($row['answer_value'] ?? ''));
    $questionType = (string) ($row['question_type'] ?? '');
    $questionScale = (int) ($row['question_scale'] ?? 5);
    $answerLabel = $answerText !== '' ? $answerText : $answerValue;
    $questionType = strtolower(trim($questionType));
    $labelLower = strtolower(trim($questionLabel));
    $looksNumeric = ($labelLower !== '')
        && (strpos($labelLower, 'note') !== false
            || strpos($labelLower, 'rating') !== false
            || strpos($labelLower, '1-') !== false
            || strpos($labelLower, '1 -') !== false);

    if ($answerLabel === '') {
        $answerLabel = '-';
    } elseif (in_array($questionType, ['yesno', 'yes_no', 'boolean', 'bool'], true)) {
        $answerLabel = $answerLabel === '1' ? __('Oui', 'stats') : __('Non', 'stats');
    } elseif ($questionType === 'rating') {
        $answerLabel = $answerLabel . ' / ' . $questionScale;
    } elseif (!$looksNumeric && ($answerLabel === '0' || $answerLabel === '1')) {
        $answerLabel = $answerLabel === '1' ? __('Oui', 'stats') : __('Non', 'stats');
    }

    $dateAnswered = (string) ($row['date_answered'] ?? '');
    $dateAnswered = $dateAnswered !== '' ? substr($dateAnswered, 0, 16) : '';
    $requesterId = (int) ($row['requester_id'] ?? 0);
    $requesterName = $requesterId > 0 ? getUserName($requesterId) : '';

    if ($dateAnswered !== $currentGroupDate) {
        $currentGroupDate = $dateAnswered;
        $groupLabel = $dateAnswered !== ''
            ? sprintf(__('Satisfaction du %s', 'stats'), $dateAnswered)
            : __('Satisfaction', 'stats');
        echo "<tr class='table-secondary'>";
        echo "<td colspan='3' class='fw-semibold'>" . htmlescape($groupLabel) . "</td>";
        echo "</tr>";
    }

    echo "<tr>";
    echo "<td>" . htmlescape($questionLabel) . "</td>";
    echo "<td>" . htmlescape($answerLabel) . "</td>";
    echo "<td>" . htmlescape($requesterName) . "</td>";
    echo "</tr>";
    }

echo "</tbody></table></div>";
Html::popFooter();
