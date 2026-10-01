<?php

/**
 * Suivi de l'action massive « Générer le rapport hotline (RP) ».
 *
 * L'action massive ne fait que confier les tickets choisis à cette page
 * (cf. PluginStatsHotlineticket::processMassiveActionsForOneItemtype) : c'est
 * ici, ticket par ticket et à la vue de l'utilisateur, que les rapports sont
 * produits par le plugin RP.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!PluginStatsHotlineticket::canView() || !PluginStatsHotline::canGenerate()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$key = (string) ($_GET['job'] ?? '');
$job = $_SESSION[PluginStatsHotlineticket::SESSION_JOBS][$key] ?? null;

if (!is_array($job) || empty($job['ids'])) {
    Session::addMessageAfterRedirect(
        __s('Cette génération n\'existe plus (session expirée). Relancez-la depuis la liste.', 'stats'),
        false,
        WARNING
    );
    Html::redirect(PluginStatsHotlineticket::getSearchURL());
}

/*
 * L'action massive de GLPI annonce « Opération réussie » dès qu'elle a confié
 * les tickets à cette page, avant le moindre rapport. Le message est retiré à
 * la première arrivée : c'est cette page qui dit ce qui a réussi, ligne par
 * ligne.
 */
if (empty($job['announced'])) {
    $success = __('Operation successful');
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? [] as $i => $message) {
        if (str_starts_with(strip_tags((string) $message), $success)) {
            unset($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO][$i]);
        }
    }
    // GLPI affiche un toast par type de message, même sans message : une liste
    // vidée afficherait un toast « Information » vide.
    if (empty($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO])) {
        unset($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO]);
    }
    $_SESSION[PluginStatsHotlineticket::SESSION_JOBS][$key]['announced'] = true;
}

Html::header(__('Génération des rapports hotline', 'stats'), $_SERVER['PHP_SELF'], 'tools', 'stats');

PluginStatsHotline::showGenerationPage(array_map('intval', (array) $job['ids']));

Html::footer();
