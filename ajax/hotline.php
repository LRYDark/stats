<?php

/**
 * Onglet « Rapports hotline à générer » : appels de la page.
 *
 * Actions (POST, jeton CSRF en en-tête comme tout appel AJAX de GLPI) :
 *   - check    : le ticket a-t-il déjà un rapport hotline ? (lecture seule)
 *   - finalize : après une génération, retrouve le rapport et rattache son PDF
 *                au ticket ; `collect_messages=1` rend aussi les messages que
 *                le plugin RP a laissés en session.
 *
 * Réponses JSON : { status: todo|exists|done|missing|forbidden|error, ... }
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');
Html::header_nocache();

Session::checkLoginUser();

$respond = static function (int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    exit;
};

if (!PluginStatsHotlineticket::canView()) {
    $respond(403, ['status' => 'forbidden', 'message' => __('Accès refusé à cet onglet.', 'stats')]);
}

$action   = (string) ($_POST['action'] ?? '');
$ticketId = (int) ($_POST['tickets_id'] ?? 0);

// Le ticket doit être visible de l'utilisateur, comme dans la liste.
$ticket = new Ticket();
if ($ticketId <= 0 || !$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
    $respond(403, ['status' => 'forbidden', 'message' => __("Vous n'avez pas accès à ce ticket.", 'stats')]);
}

switch ($action) {
    case 'check':
        $respond(200, ['status' => PluginStatsHotline::hasReport($ticketId) ? 'exists' : 'todo']);
        break;

    case 'finalize':
        // Rattacher un document au ticket est une écriture : même droit que la génération.
        if (!PluginStatsHotline::canGenerate()) {
            $respond(403, ['status' => 'forbidden', 'message' => __('Droit « Rapport hotline » du plugin RP requis.', 'stats')]);
        }
        $result = PluginStatsHotline::finalize($ticketId);
        if (!empty($_POST['collect_messages'])) {
            $result['messages'] = PluginStatsHotline::takeSessionMessages();
        }
        $respond(200, $result);
        break;

    default:
        $respond(400, ['status' => 'error', 'message' => 'unknown_action']);
}
