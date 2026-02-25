<?php

include('../../../inc/includes.php');
/** @var array $CFG_GLPI */
global $CFG_GLPI, $DB;

Session::checkLoginUser();
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
$includeCredit = $creditPluginActive && $creditAlertActive && $creditConfigLoaded;

$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);
$listLimit = (int) ($_REQUEST['glpilist_limit'] ?? ($_SESSION['glpilist_limit'] ?? 30));
if ($listLimit <= 0) {
    $listLimit = 30;
}
$start = (int) ($_GET['start'] ?? 0);
$previousListLimit = $_SESSION['glpilist_limit'] ?? null;
$_SESSION['glpilist_limit'] = $listLimit;
$isExport = (($_GET['export'] ?? '') === 'csv');
$getEntityLabel = static function (int $entityId): string {
    static $cache = [];
    if ($entityId <= 0) {
        return '';
    }
    if (!array_key_exists($entityId, $cache)) {
        $cache[$entityId] = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars(
            Dropdown::getDropdownName('glpi_entities', $entityId)
        );
    }
    return (string) $cache[$entityId];
};
$getUserLabel = static function (int $userId): string {
    static $cache = [];
    if ($userId <= 0) {
        return '';
    }
    if (!array_key_exists($userId, $cache)) {
        $cache[$userId] = (string) getUserName($userId);
    }
    return (string) $cache[$userId];
};

if (!in_array($type, ['tech', 'entity'], true) || $id <= 0) {
    if ($isExport) {
        header('Content-Type: text/plain; charset=utf-8');
        echo __('Parametres invalides.', 'stats');
        if ($previousListLimit !== null) {
            $_SESSION['glpilist_limit'] = $previousListLimit;
        } else {
            unset($_SESSION['glpilist_limit']);
        }
        return;
    }
    Html::popHeader(__('Details tickets', 'stats'), $_SERVER['PHP_SELF'], true);
    echo "<div class='alert alert-danger'>";
    echo __('Parametres invalides.', 'stats');
    echo "</div>";
    if ($previousListLimit !== null) {
        $_SESSION['glpilist_limit'] = $previousListLimit;
    } else {
        unset($_SESSION['glpilist_limit']);
    }
    Html::popFooter();
    return;
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

$taskTable = 'glpi_tickettasks';
$ticketTable = 'glpi_tickets';
$where = [
    "$taskTable.actiontime" => ['>', 0],
];
if ($DB->fieldExists($taskTable, 'is_deleted')) {
    $where["$taskTable.is_deleted"] = 0;
}

$title = '';
if ($type === 'tech') {
    $where["$taskTable.users_id_tech"] = $id;
    $title = sprintf(__('Tickets du technicien %s', 'stats'), $getUserLabel($id));

    if (!empty($filterEntities)) {
        $entityScope = [];
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
            $where["$ticketTable.entities_id"] = $entityScope;
        }
    }
} else {
    $where["$ticketTable.entities_id"] = $id;
    if (!empty($filterTechs)) {
        $where["$taskTable.users_id_tech"] = $filterTechs;
    }
    $entityLabel = $getEntityLabel($id);
    $title = sprintf(__('Tickets de l entite %s', 'stats'), $entityLabel);
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

$rows = [];
$countRow = $DB->request([
    'SELECT' => [
        new QueryExpression("COUNT(DISTINCT $ticketTable.id) AS total"),
    ],
    'FROM' => $taskTable,
    'LEFT JOIN' => $joins,
    'WHERE' => $where,
])->current();
$totalRows = (int) ($countRow['total'] ?? 0);

$rowsRequest = [
    'SELECT' => [
        "$ticketTable.id AS tickets_id",
        "$ticketTable.name AS ticket_name",
        "$ticketTable.date AS ticket_date",
        "$ticketTable.status AS ticket_status",
        "$ticketTable.entities_id AS entities_id",
        new QueryExpression("SUM($taskTable.actiontime) AS total_time"),
    ],
    'FROM' => $taskTable,
    'LEFT JOIN' => $joins,
    'WHERE' => $where,
    'GROUPBY' => ["$ticketTable.id"],
    'ORDER' => [new QueryExpression('total_time DESC')],
];
if (!$isExport) {
    $rowsRequest['START'] = $start;
    $rowsRequest['LIMIT'] = $listLimit;
}
foreach ($DB->request($rowsRequest) as $row) {
    $rows[] = $row;
}

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

$creditTotalsByTicket = [];
if ($includeCredit) {
    $consumptionTable = 'glpi_plugin_credit_tickets';
    $fieldTicket = 'tickets_id';
    $fieldUsed = 'consumed';
    $config = PluginCreditalertConfig::getConfig();
    $consumptionTable = $config['consumption_table'] ?? $consumptionTable;
    $fieldTicket = $config['field_ticket'] ?? $fieldTicket;
    $fieldUsed = $config['field_used'] ?? $fieldUsed;

    $ticketIds = [];
    foreach ($rows as $row) {
        $ticketId = (int) ($row['tickets_id'] ?? 0);
        if ($ticketId > 0) {
            $ticketIds[$ticketId] = true;
        }
    }
    $ticketIds = array_keys($ticketIds);
    if (!empty($ticketIds) && $DB->tableExists($consumptionTable)) {
        foreach ($DB->request([
            'SELECT' => [
                "$consumptionTable.$fieldTicket AS ticket_id",
                new QueryExpression("SUM($consumptionTable.$fieldUsed) AS total_credit"),
            ],
            'FROM' => $consumptionTable,
            'WHERE' => [
                "$consumptionTable.$fieldTicket" => $ticketIds,
            ],
            'GROUPBY' => ["$consumptionTable.$fieldTicket"],
        ]) as $row) {
            $ticketId = (int) ($row['ticket_id'] ?? 0);
            if ($ticketId > 0) {
                $creditTotalsByTicket[$ticketId] = (float) ($row['total_credit'] ?? 0);
            }
        }
    }
}

if ($isExport) {
    $filename = $type === 'tech' ? 'stats_tickets_technicien.csv' : 'stats_tickets_entite.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $headers = [
        __('ID', 'stats'),
        __('Ticket', 'stats'),
        __('Entite', 'stats'),
        __('Date ouverture', 'stats'),
        __('Statut', 'stats'),
        __('Temps tâche', 'stats'),
    ];
    if ($includeCredit) {
        $headers[] = __('Temps credit', 'stats');
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
            ? $getEntityLabel($entityId)
            : '';
        $status = (int) ($row['ticket_status'] ?? 0);
        $statusLabel = Ticket::getStatus($status);
        $dateOpen = (string) ($row['ticket_date'] ?? '');
        $dateOpen = $dateOpen !== '' ? substr($dateOpen, 0, 16) : '';
        $time = $formatHours((int) ($row['total_time'] ?? 0));
        $csvRow = [
            $ticketId,
            $ticketLabel,
            $entityLabel,
            $dateOpen,
            $statusLabel,
            $time,
        ];
        if ($includeCredit) {
            $creditTotal = (float) ($creditTotalsByTicket[$ticketId] ?? 0);
            $creditTime = $creditTotal > 0 ? $formatCredit($creditTotal) : '-';
            $csvRow[] = $creditTime;
        }
        fputcsv($out, $csvRow, ';');
    }
    fclose($out);
    if ($previousListLimit !== null) {
        $_SESSION['glpilist_limit'] = $previousListLimit;
    } else {
        unset($_SESSION['glpilist_limit']);
    }
    exit;
}

Html::popHeader(__('Details tickets', 'stats'), $_SERVER['PHP_SELF'], true);

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
    unset($pagerParams['start'], $pagerParams['export']);
    $pagerQuery = http_build_query($pagerParams);
    $pagerTarget = $CFG_GLPI['root_doc'] . '/plugins/stats/front/tickets.modal.php';
    Html::printPager($start, $totalRows, $pagerTarget, $pagerQuery);
}
if ($totalRows > 0) {
    $exportParams = $_GET;
    unset($exportParams['start'], $exportParams['export']);
    $exportParams['export'] = 'csv';
    $exportUrl = $CFG_GLPI['root_doc'] . '/plugins/stats/front/tickets.modal.php?' . http_build_query($exportParams);
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
echo "<th>" . __('Date ouverture', 'stats') . "</th>";
echo "<th>" . __('Statut', 'stats') . "</th>";
echo "<th class='text-end'>" . __('Temps tâche', 'stats') . "</th>";
if ($includeCredit) {
    echo "<th class='text-end'>" . __('Temps credit', 'stats') . "</th>";
}
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
        ? $getEntityLabel($entityId)
        : '';
    $status = (int) ($row['ticket_status'] ?? 0);
    $statusLabel = Ticket::getStatus($status);
    $dateOpen = (string) ($row['ticket_date'] ?? '');
    $dateOpen = $dateOpen !== '' ? substr($dateOpen, 0, 16) : '';
    $time = $formatHours((int) ($row['total_time'] ?? 0));

    echo "<tr>";
    echo "<td>" . htmlescape((string) $ticketId) . "</td>";
    echo "<td><a href='" . htmlescape($ticketUrl) . "' target='_blank'>"
        . htmlescape($ticketLabel) . "</a></td>";
    echo "<td>" . htmlescape($entityLabel) . "</td>";
    echo "<td>" . htmlescape($dateOpen) . "</td>";
    echo "<td>" . htmlescape($statusLabel) . "</td>";
    echo "<td class='text-end'>" . htmlescape($time) . "</td>";
    if ($includeCredit) {
        $creditTotal = (float) ($creditTotalsByTicket[$ticketId] ?? 0);
        $creditTime = $creditTotal > 0 ? $formatCredit($creditTotal) : '-';
        echo "<td class='text-end'>" . htmlescape($creditTime) . "</td>";
    }
    echo "</tr>";
}

echo "</tbody></table></div>";
if ($totalRows > 0) {
    Html::printPager($start, $totalRows, $pagerTarget, $pagerQuery);
}

$exportJs = <<<JS
(function() {
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
