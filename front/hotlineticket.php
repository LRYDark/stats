<?php

/**
 * Liste « Rapports hotline à générer » — onglet de la page Statistiques.
 *
 * Page de liste GLPI standard, construite comme front/ticket.php : en-tête,
 * moteur de recherche natif, pied de page. Rien n'est émis avant l'en-tête, ce
 * qui garde le navigateur en mode standard ; pagination, actions massives,
 * colonnes et exports sont ceux de GLPI, sans adaptation.
 *
 * Seule la barre d'onglets de la page Statistiques est ajoutée au-dessus, pour
 * passer d'un onglet à l'autre (cf. PluginStatsMenu::showTabs).
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!PluginStatsHotlineticket::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

/*
 * Éditeur riche (TinyMCE) demandé AVANT l'en-tête, seul moment où GLPI
 * l'accepte : la fenêtre hotline du plugin RP arrive plus tard en AJAX et ses
 * zones de texte l'appellent. Sans lui, elles restaient de simples zones de
 * texte brut, plus petites que depuis le ticket.
 */
Html::requireJs('tinymce');

// Ouverture sur le nombre de lignes par défaut de l'utilisateur (50).
PluginStatsHotline::resetListLimit();

Html::header(
    PluginStatsHotlineticket::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'tools',
    'stats'
);

/*
 * Mise en page : sur grand écran, GLPI donne à la liste la hauteur exacte de
 * l'écran (`.search-container`), avec la barre de contrôle collée en haut et
 * la pagination collée en bas, et fait défiler les lignes à l'intérieur. La
 * barre d'onglets, au-dessus, la décalait d'autant vers le bas : la pagination
 * sortait de l'écran, et la barre « Actions » partait avec le haut de la page.
 * Onglets et liste partagent donc cette même hauteur, calculée avec les
 * variables de GLPI ; la liste prend la place qui reste. En mode débogage,
 * GLPI réserve 50 px de plus à sa barre : même réserve ici, et le conteneur
 * est désigné aussi précisément que dans la règle de GLPI pour la remplacer.
 */
echo "<style>
@media (min-width: 992px) {
    .plugin-stats-hotline {
        display: flex;
        flex-direction: column;
        height: calc(100vh - var(--glpi-contextbar-height) - var(--glpi-content-margin));
    }
    body.debug-active:not(.debug-folded) .plugin-stats-hotline {
        height: calc(100vh - var(--glpi-contextbar-height) - var(--glpi-content-margin) - 50px);
    }
    .plugin-stats-hotline > .search_page {
        flex: 1 1 auto;
        min-height: 0;
    }
    body .plugin-stats-hotline > .search_page > .search-container,
    body.debug-active:not(.debug-folded) .plugin-stats-hotline > .search_page > .search-container {
        height: 100%;
    }
}
</style>";

echo "<div class='plugin-stats-hotline'>";
PluginStatsMenu::showTabs(PluginStatsHotline::VIEW);
PluginStatsHotline::show();
echo "</div>";

Html::footer();
