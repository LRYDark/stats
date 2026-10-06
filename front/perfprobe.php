<?php

/**
 * DIAGNOSTIC TEMPORAIRE — lenteur de la liste « Rapports hotline à générer ».
 * Mesure la requête de la liste AVANT puis APRÈS l'index RP (id_ticket, type).
 * Seule écriture : l'index, posé par ensureView() comme à l'affichage de la liste.
 * À SUPPRIMER une fois l'analyse faite.
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

/** @var DBmysql $DB */
global $DB;

@set_time_limit(900);
header('Content-Type: text/plain; charset=utf-8');

$out = [];
$log = static function (string $line) use (&$out): void {
    $out[] = $line;
    echo $line, "\n";
    @ob_flush();
    flush();
};
$timed = static function (string $label, callable $fn) use ($log) {
    $t = microtime(true);
    try {
        $r = $fn();
        $log(sprintf('[%9.1f ms] %s', (microtime(true) - $t) * 1000, $label));
        return $r;
    } catch (\Throwable $e) {
        $log(sprintf('[%9.1f ms] %s  !! %s', (microtime(true) - $t) * 1000, $label, $e->getMessage()));
        return null;
    }
};
$rows = static function (string $sql) use ($DB): array {
    $res = $DB->doQuery($sql);
    $all = [];
    while ($res && ($row = $DB->fetchAssoc($res))) {
        $all[] = $row;
    }
    return $all;
};
$rpIndexes = static function () use ($rows, $log): bool {
    $has = false;
    foreach ($rows('SHOW INDEX FROM `glpi_plugin_rp_cridetails`') as $i) {
        $log("  {$i['Key_name']} #{$i['Seq_in_index']} {$i['Column_name']}");
        if ($i['Column_name'] === 'id_ticket' && (int) $i['Seq_in_index'] === 1) {
            $has = true;
        }
    }
    return $has;
};
$searchSql = static function () use ($timed): string {
    $itemtype = PluginStatsHotlineticket::class;
    $params   = Search::manageParams($itemtype, []);
    $data     = Search::prepareDatasForSearch($itemtype, $params);
    Search::constructSQL($data);
    return (string) ($data['sql']['search'] ?? '');
};

$log('=== Serveur ===');
$log(json_encode($rows('SELECT VERSION() AS v, @@innodb_lock_wait_timeout AS lockwait, @@max_statement_time AS maxstmt')));
$log('PHP ' . PHP_VERSION . ', memory_limit ' . ini_get('memory_limit') . ', max_execution_time ' . ini_get('max_execution_time'));
$log('Correctif index publié (ensureRpIndex) : '
    . (method_exists(PluginStatsHotlineticket::class, 'ensureRpIndex') ? 'OUI' : 'NON — publier inc/hotlineticket.class.php'));

$log("\n=== Volumes ===");
foreach ([
    'glpi_tickets', 'glpi_plugin_rp_cridetails', 'glpi_plugin_credit_tickets',
    'glpi_itilsolutions', 'glpi_tickets_users', 'glpi_users',
] as $t) {
    $log("$t = " . ($rows("SELECT COUNT(*) AS n FROM `$t`")[0]['n'] ?? '?'));
}

$log("\n=== Index RP AVANT ===");
$hadIndex = $rpIndexes();

$viewExists = $DB->tableExists('glpi_plugin_stats_hotlinetickets');
if (!$hadIndex && $viewExists) {
    $log("\n=== Liste SANS index ===");
    $sql = $searchSql();
    foreach ($rows('EXPLAIN ' . $sql) as $e) {
        $log(json_encode($e, JSON_UNESCAPED_UNICODE));
    }
    $timed('requête de la liste SANS index', static fn () => count($rows($sql)) . ' lignes');
} else {
    $log($hadIndex ? "\n(index déjà présent : pas de mesure « sans index »)" : "\n(vue absente : pas de mesure « sans index »)");
}

$log("\n=== Préparation (vue + index) ===");
$timed('ensureView()', static fn () => PluginStatsHotlineticket::ensureView());
$log('Index RP APRÈS :');
$rpIndexes();

$log("\n=== Liste AVEC index ===");
$sql = $searchSql();
$timed('requête de la liste', static fn () => count($rows($sql)) . ' lignes');
$timed('COUNT(*) FROM vue', static fn () => $rows('SELECT COUNT(*) FROM `glpi_plugin_stats_hotlinetickets`'));
$timed('SUM(users_id_tech) FROM vue (technicien)', static fn () => $rows('SELECT SUM(`users_id_tech`) FROM `glpi_plugin_stats_hotlinetickets`'));

$log("\n--- SQL de la liste ---\n" . $sql);

$log("\n=== ANALYZE FORMAT=JSON (temps réels par table) ===");
$an = $timed('ANALYZE', static fn () => $rows('ANALYZE FORMAT=JSON ' . $sql));
if (is_array($an) && isset($an[0])) {
    $log((string) (reset($an[0]) ?: ''));
}

@file_put_contents(GLPI_LOG_DIR . '/stats-probe.log', implode("\n", $out) . "\n");
$log("\n(écrit dans files/_log/stats-probe.log)");
