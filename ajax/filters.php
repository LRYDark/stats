<?php

/**
 * Endpoint AJAX des filtres favoris du plugin stats.
 *
 * Actions (parametre `action`) :
 *   - list   (GET)  : liste des favoris de l'utilisateur connecte pour une `view`.
 *   - get    (GET)  : params d'un favori precis (appartenant a l'utilisateur).
 *   - save   (POST) : cree/met a jour un favori (CSRF requis).
 *   - delete (POST) : supprime un favori de l'utilisateur (CSRF requis).
 *
 * Toutes les reponses sont en JSON et incluent un champ `csrf` (token rafraichi).
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();

// Acces : au moins un des 3 droits stats (meme controle que front/stats.php).
if (!Session::haveRight(PluginStatsProfile::RIGHTNAME_CREDITS, PluginStatsProfile::RIGHT_READ)
    && !Session::haveRight(PluginStatsProfile::RIGHTNAME_TICKETS, PluginStatsProfile::RIGHT_READ)
    && !Session::haveRight(PluginStatsProfile::RIGHTNAME_SATISFACTION, PluginStatsProfile::RIGHT_READ)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$users_id = (int) Session::getLoginUserID();
$action   = (string) ($_REQUEST['action'] ?? '');

$respond = static function (array $payload): void {
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
};

switch ($action) {
    case 'list':
        $view = PluginStatsFilter::normalizeView($_GET['view'] ?? 'tickets');
        $respond([
            'success' => true,
            'filters' => PluginStatsFilter::getForUser($users_id, $view),
        ]);
        break;

    case 'get':
        $id     = (int) ($_GET['id'] ?? 0);
        $params = PluginStatsFilter::getParamsForCurrentUser($id);
        if ($params === null) {
            $respond(['success' => false, 'message' => 'not_found']);
        }
        $respond(['success' => true, 'params' => $params]);
        break;

    case 'save':
        // Protection CSRF assuree en amont par GLPI (CheckCsrfListener) : la requete AJAX
        // envoie le token dans l'en-tete X-Glpi-Csrf-Token (valide avec preserve_token=true).
        $name      = (string) ($_POST['name'] ?? '');
        $view      = (string) ($_POST['view'] ?? 'tickets');
        $params    = (string) ($_POST['params'] ?? '');
        $isDefault = !empty($_POST['is_default']);
        $id        = PluginStatsFilter::saveForCurrentUser($name, $view, $params, $isDefault);
        if ($id === false) {
            $respond(['success' => false, 'message' => 'save_failed']);
        }
        $respond([
            'success' => true,
            'id'      => $id,
            'filters' => PluginStatsFilter::getForUser($users_id, PluginStatsFilter::normalizeView($view)),
        ]);
        break;

    case 'delete':
        // Protection CSRF assuree en amont par GLPI (CheckCsrfListener), cf. action 'save'.
        $id   = (int) ($_POST['id'] ?? 0);
        $view = PluginStatsFilter::normalizeView($_POST['view'] ?? 'tickets');
        $ok   = PluginStatsFilter::deleteForCurrentUser($id);
        $respond([
            'success' => $ok,
            'filters' => PluginStatsFilter::getForUser($users_id, $view),
        ]);
        break;

    default:
        http_response_code(400);
        $respond(['success' => false, 'message' => 'unknown_action']);
}
