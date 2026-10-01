<?php

/**
 * Onglet « Rapports hotline à générer ».
 *
 * La liste elle-même est une liste NATIVE de GLPI (cf. PluginStatsHotlineticket) :
 * critères, tri, colonnes, exports, recherches sauvegardées et actions massives
 * sont ceux du moteur de recherche. Cette classe n'apporte que ce qui manque :
 *
 *  - le bouton « Générer » de chaque ligne, qui ouvre la fenêtre hotline du
 *    plugin RP par SA fonction `rp_loadCriForm` ;
 *  - la page de suivi de l'action massive, qui fait produire les rapports un
 *    par un par le formulaire et le générateur du plugin RP ;
 *  - le rattachement du PDF produit au ticket, que le plugin RP ne fait pas
 *    (il ne renseigne que l'ancien champ `tickets_id` du document, que GLPI 11
 *    n'utilise plus pour rattacher un document à un ticket).
 *
 * Le plugin RP n'est pas modifié : tout passe par ses points d'entrée.
 */
class PluginStatsHotline
{
    /** Vue, telle qu'attendue dans l'URL de la page Stats. */
    public const VIEW = 'hotline';

    /**
     * L'onglet peut-il exister ?
     *
     * Plugin installé, actif ET table présente, pour chacun des deux : un plugin
     * désinstallé laisse parfois ses fichiers derrière lui, jamais ses tables.
     */
    public static function isAvailable(): bool
    {
        global $DB;

        static $available = null;
        if ($available === null) {
            $plugin    = new Plugin();
            $available = $plugin->isInstalled('rp') && $plugin->isActivated('rp')
                && $plugin->isInstalled('credit') && $plugin->isActivated('credit')
                && $DB->tableExists(PluginStatsHotlineticket::RP_TABLE)
                && $DB->tableExists(PluginStatsHotlineticket::CREDIT_TABLE);
        }

        return $available;
    }

    /** Droit de générer : mêmes règles que le bouton de l'onglet RP du ticket. */
    public static function canGenerate(): bool
    {
        static $can = null;
        if ($can === null) {
            $can = class_exists('PluginRpAccess') && PluginRpAccess::canUse('rapport_hotline', CREATE);
        }

        return $can;
    }

    // ------------------------------------------------------------------
    // Onglet
    // ------------------------------------------------------------------

    /**
     * Lignes par page à l'ouverture : la valeur par défaut de l'utilisateur
     * (sa préférence, sinon celle de GLPI : 50), et non le dernier choix fait
     * dans une liste.
     *
     * GLPI garde ce choix pour toute la session : après « 2000 lignes / page »
     * dans n'importe quelle liste, celle-ci s'ouvrait sur 2000 lignes — page de
     * 8 Mo, et actions massives désactivées par GLPI au-delà de 990 lignes.
     * Un choix fait dans la liste vaut tant qu'on y navigue (tri, pages : GLPI
     * le renvoie à chaque requête) ; la prochaine ouverture repart du défaut.
     * Même plafond qu'à la connexion (`list_limit_max`).
     *
     * À appeler avant Html::header() : n'émet rien.
     */
    public static function resetListLimit(): void
    {
        global $CFG_GLPI;

        if (isset($_REQUEST['glpilist_limit'])) {
            // Choix explicite, déjà appliqué par GLPI à la session.
            return;
        }

        $user = new User();
        if (!$user->getFromDB(Session::getLoginUserID())) {
            return;
        }
        $user->computePreferences();

        $limit = (int) ($user->fields['list_limit'] ?? $CFG_GLPI['list_limit']);
        $max   = (int) ($CFG_GLPI['list_limit_max'] ?? 0);
        if ($max > 0 && $limit > $max) {
            $limit = $max;
        }
        if ($limit > 0) {
            $_SESSION['glpilist_limit'] = $limit;
        }
    }

    public static function show(): void
    {
        try {
            $ready = PluginStatsHotlineticket::ensureView();
        } catch (\Throwable $e) {
            Toolbox::logInFile('plugin-stats', 'Rapports hotline à générer (vue SQL) : ' . $e->getMessage() . "\n");
            $ready = false;
        }

        if (!$ready) {
            PluginStatsSignature::emptyState(
                __("La liste n'a pas pu être préparée (vue SQL). Le détail a été journalisé.", 'stats')
            );
            return;
        }

        /*
         * Zone d'erreur du plugin RP : sa fonction d'ouverture y écrit quand le
         * formulaire est refusé (droits, ticket inaccessible). Sans elle, elle
         * retombe sur une boîte d'alerte du navigateur.
         */
        echo "<div id='rp_cri_error' class='alert alert-danger' style='display:none'></div>";

        if (!self::canGenerate()) {
            echo "<div class='alert alert-info'><i class='ti ti-lock me-2'></i>"
                . __s("Votre profil n'a pas le droit de créer un rapport hotline dans le plugin RP : la liste est consultable, la génération est réservée.", 'stats')
                . "</div>";
        }

        PluginStatsHotlineticket::forgetRetiredSearch();
        Search::show(PluginStatsHotlineticket::class);

        self::tabScript();
    }

    /** Cellule « Rapport hotline » de la liste. */
    public static function generateButton(int $ticketId): string
    {
        if ($ticketId <= 0) {
            return '';
        }

        if (!self::canGenerate()) {
            return "<button type='button' class='btn btn-sm btn-outline-secondary' disabled"
                . " title='" . __s('Droit « Rapport hotline » du plugin RP requis', 'stats') . "'>"
                . "<i class='ti ti-lock me-1'></i>" . __s('Générer', 'stats') . "</button>";
        }

        return "<button type='button' class='btn btn-sm btn-primary' data-stats-hotline-generate"
            . " data-ticket='" . $ticketId . "'>"
            . "<i class='ti ti-file-plus me-1'></i>" . __s('Générer', 'stats') . "</button>";
    }

    // ------------------------------------------------------------------
    // Rapport et document
    // ------------------------------------------------------------------

    /** Le ticket a-t-il un rapport hotline, de quelque origine qu'il soit ? */
    public static function hasReport(int $ticketId): bool
    {
        return (new DbUtils())->countElementsInTable(PluginStatsHotlineticket::RP_TABLE, [
            'id_ticket' => $ticketId,
            'type'      => PluginStatsHotlineticket::RP_TYPE_HOTLINE,
        ]) > 0;
    }

    /**
     * Après une génération : retrouve le dernier rapport hotline du ticket et
     * rattache son PDF au ticket.
     *
     * @return array{status:string, documents_id?:int, document_name?:string, document_url?:string, link?:string}
     */
    public static function finalize(int $ticketId): array
    {
        global $DB, $CFG_GLPI;

        $row = $DB->request([
            'SELECT' => ['id', 'id_documents'],
            'FROM'   => PluginStatsHotlineticket::RP_TABLE,
            'WHERE'  => [
                'id_ticket' => $ticketId,
                'type'      => PluginStatsHotlineticket::RP_TYPE_HOTLINE,
            ],
            'ORDER'  => ['date DESC', 'id DESC'],
            'LIMIT'  => 1,
        ])->current();

        if (!$row) {
            return ['status' => 'missing'];
        }

        $docId    = (int) $row['id_documents'];
        $document = new Document();
        if ($docId <= 0 || !$document->getFromDB($docId)) {
            return ['status' => 'done', 'documents_id' => 0, 'link' => 'nodoc'];
        }

        return [
            'status'        => 'done',
            'documents_id'  => $docId,
            'document_name' => (string) ($document->fields['filename'] ?: $document->fields['name']),
            'document_url'  => $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $docId,
            'link'          => self::linkDocument($docId, $ticketId),
        ];
    }

    /**
     * Rattache le document au ticket : il apparaît alors dans les documents et
     * le fil du ticket, comme une pièce jointe.
     *
     * Sans notification : rattacher cent rapports d'un coup ne doit pas envoyer
     * cent mails « ticket mis à jour » aux clients. GLPI met seulement à jour la
     * date de modification du ticket.
     *
     * @return string already|linked|failed
     */
    private static function linkDocument(int $docId, int $ticketId): string
    {
        $link     = new Document_Item();
        $criteria = [
            'documents_id' => $docId,
            'itemtype'     => Ticket::class,
            'items_id'     => $ticketId,
        ];

        if ((new DbUtils())->countElementsInTable(Document_Item::getTable(), $criteria) > 0) {
            return 'already';
        }

        $ok = $link->add($criteria + [
            '_do_notif'     => false,
            '_disablenotif' => true,
        ]);

        return $ok ? 'linked' : 'failed';
    }

    /**
     * Messages laissés en session par le générateur RP pendant une génération
     * de masse : rendus à la ligne du ticket, et retirés de la session.
     *
     * Sans cela, cent générations laisseraient cent messages s'afficher d'un
     * bloc à la page suivante, sans qu'on sache à quel ticket ils se rapportent.
     *
     * @return array<int, array{type:string, text:string}>
     */
    public static function takeSessionMessages(): array
    {
        $types = [INFO => 'info', WARNING => 'warning', ERROR => 'error'];
        $out   = [];

        foreach ($types as $code => $label) {
            foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'][$code] ?? [] as $message) {
                $text = trim(html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], ' ', (string) $message))));
                if ($text !== '') {
                    $out[] = ['type' => $label, 'text' => $text];
                }
            }
            unset($_SESSION['MESSAGE_AFTER_REDIRECT'][$code]);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Page de suivi de l'action massive
    // ------------------------------------------------------------------

    /**
     * @param int[] $ids tickets confiés par l'action massive
     */
    public static function showGenerationPage(array $ids): void
    {
        global $DB, $CFG_GLPI;

        // Tickets visibles de l'utilisateur, avec leur état actuel.
        $tickets = [];
        if (count($ids) > 0) {
            $where = ['glpi_tickets.id' => $ids];
            $restrict = getEntitiesRestrictCriteria('glpi_tickets');
            if (count($restrict) > 0) {
                $where[] = $restrict;
            }
            foreach ($DB->request([
                'SELECT'    => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_entities.name AS entity'],
                'FROM'      => 'glpi_tickets',
                'LEFT JOIN' => [
                    'glpi_entities' => ['ON' => ['glpi_entities' => 'id', 'glpi_tickets' => 'entities_id']],
                ],
                'WHERE'     => $where,
            ]) as $row) {
                $tickets[(int) $row['id']] = $row;
            }
        }

        $reported = [];
        if (count($tickets) > 0) {
            foreach ($DB->request([
                'SELECT' => ['id_ticket'],
                'FROM'   => PluginStatsHotlineticket::RP_TABLE,
                'WHERE'  => [
                    'id_ticket' => array_keys($tickets),
                    'type'      => PluginStatsHotlineticket::RP_TYPE_HOTLINE,
                ],
            ]) as $row) {
                $reported[(int) $row['id_ticket']] = true;
            }
        }

        $listUrl = PluginStatsHotlineticket::getSearchURL();

        echo "<div class='card mb-3' data-stats-hotline-job>";
        echo "<div class='card-header d-flex flex-wrap align-items-center justify-content-between gap-2'>";
        echo "<div>";
        echo "<h3 class='card-title mb-0'><i class='ti ti-file-plus me-2'></i>" . __s('Génération des rapports hotline', 'stats') . "</h3>";
        echo "<div class='text-muted small'>"
            . __s("Chaque rapport est produit par le plugin RP puis rattaché au ticket. Aucun mail n'est envoyé au client.", 'stats')
            . "</div>";
        echo "</div>";
        echo "<div class='d-flex gap-2'>";
        echo "<button type='button' class='btn btn-outline-warning' data-job-stop><i class='ti ti-player-pause me-1'></i>" . __s('Arrêter après ce ticket', 'stats') . "</button>";
        echo "<button type='button' class='btn btn-outline-primary d-none' data-job-resume><i class='ti ti-player-play me-1'></i>" . __s('Reprendre', 'stats') . "</button>";
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($listUrl) . "'><i class='ti ti-list me-1'></i>" . __s('Retour à la liste', 'stats') . "</a>";
        echo "</div>";
        echo "</div>";

        echo "<div class='card-body'>";
        echo "<div class='progress progress-lg mb-2'><div class='progress-bar' style='width:0%' data-job-bar></div></div>";
        echo "<div class='d-flex flex-wrap gap-3 small' data-job-counters>";
        echo "<span><span class='badge bg-green-lt' data-count='done'>0</span> " . __s('générés', 'stats') . "</span>";
        echo "<span><span class='badge bg-azure-lt' data-count='exists'>0</span> " . __s('déjà présents', 'stats') . "</span>";
        echo "<span><span class='badge bg-red-lt' data-count='error'>0</span> " . __s('échecs', 'stats') . "</span>";
        echo "<span><span class='badge bg-secondary-lt' data-count='todo'>0</span> " . __s('restants', 'stats') . "</span>";
        echo "<span class='ms-auto text-muted' data-job-status></span>";
        echo "</div>";
        echo "</div>";

        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm table-vcenter card-table'>";
        echo "<thead><tr>";
        echo "<th>" . __s('Ticket', 'stats') . "</th>";
        echo "<th>" . __s('Titre', 'stats') . "</th>";
        echo "<th>" . __s('Entité', 'stats') . "</th>";
        echo "<th>" . __s('État', 'stats') . "</th>";
        echo "<th>" . __s('Document / détail', 'stats') . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($ids as $id) {
            $id = (int) $id;
            if (!isset($tickets[$id])) {
                echo "<tr data-ticket='" . $id . "' data-state='skipped'>";
                echo "<td>" . $id . "</td><td colspan='2' class='text-muted'>—</td>";
                echo "<td><span class='badge bg-secondary-lt'>" . __s('Inaccessible', 'stats') . "</span></td>";
                echo "<td class='text-muted small'>" . __s("Ticket hors de votre périmètre ou supprimé : passé.", 'stats') . "</td>";
                echo "</tr>";
                continue;
            }

            $state = isset($reported[$id]) ? 'exists' : 'todo';
            echo "<tr data-ticket='" . $id . "' data-state='" . $state . "'>";
            echo "<td><a href='" . htmlescape(Ticket::getFormURLWithID($id)) . "' target='_blank' class='fw-bold'>" . $id . "</a></td>";
            echo "<td>" . htmlescape((string) $tickets[$id]['name']) . "</td>";
            echo "<td>" . htmlescape((string) ($tickets[$id]['entity'] ?? '')) . "</td>";
            echo "<td data-cell='state'>" . ($state === 'exists'
                ? "<span class='badge bg-azure-lt'>" . __s('Déjà présent', 'stats') . "</span>"
                : "<span class='badge bg-secondary-lt'>" . __s('En attente', 'stats') . "</span>") . "</td>";
            echo "<td data-cell='detail' class='small'>" . ($state === 'exists'
                ? "<span class='text-muted'>" . __s('Le ticket avait déjà son rapport hotline : passé.', 'stats') . "</span>"
                : '') . "</td>";
            echo "</tr>";
        }

        echo "</tbody></table></div></div>";

        self::generationScript($listUrl);
    }

    // ------------------------------------------------------------------
    // Scripts
    // ------------------------------------------------------------------

    /** Configuration commune aux deux scripts. */
    private static function jsConfig(array $extra = []): string
    {
        global $CFG_GLPI;

        return (string) json_encode($extra + [
            // La valeur même que passe le bouton de l'onglet RP du ticket.
            'rpWebdir'  => defined('PLUGIN_RP_WEBDIR') ? PLUGIN_RP_WEBDIR : $CFG_GLPI['root_doc'] . '/plugins/rp',
            'ticketUrl' => Ticket::getFormURL() . '?id=',
            // Repli si le script RP manquait : l'onglet RP du ticket porte le même bouton.
            'rpTabUrl'  => Ticket::getFormURL() . '?forcetab=' . rawurlencode('PluginRpCriDetail$1') . '&id=',
            'statsAjax' => $CFG_GLPI['root_doc'] . '/plugins/stats/ajax/hotline.php',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Bouton « Générer » d'une ligne : ouvre la fenêtre hotline du plugin RP.
     *
     * Le lien « TICKET : N » imprimé dans le PDF est l'adresse de la page d'où
     * part le formulaire (le plugin RP la lit dans l'en-tête Referer). Envoyé
     * d'ici, ce lien aurait pointé vers cette page de statistiques, dans un
     * document remis au client. Le temps de l'envoi, l'adresse affichée devient
     * donc celle du ticket (`history.replaceState`, même origine, sans
     * rechargement) : le PDF est identique à celui produit depuis le ticket.
     *
     * L'adresse d'origine est rétablie :
     *  - aussitôt l'envoi parti quand la file hors-ligne du plugin RP l'a pris
     *    en charge (elle appelle `fetch` pendant l'évènement, l'en-tête est
     *    déjà fixé) — et avant le rechargement qu'elle programme ;
     *  - sinon au retour sur la page ou au bout d'1,5 s, le temps que le
     *    navigateur lance l'envoi classique.
     *
     * Le ticket envoyé est noté dans le stockage de l'onglet : au rechargement
     * qui suit la génération, son PDF est rattaché au ticket.
     */
    private static function tabScript(): void
    {
        $cfg = self::jsConfig();

        $js = <<<JS
(function () {
  'use strict';
  if (window.__statsHotlineBound) { return; }
  window.__statsHotlineBound = true;

  var cfg = {$cfg};
  var opened = 0;     // ticket dont la fenêtre RP a été ouverte depuis cet onglet
  var pending = null; // envoi en cours dont l'adresse est à rétablir
  var STORE = 'statsHotlinePending';

  var token = function () {
    if (typeof window.getAjaxCsrfToken === 'function') { return window.getAjaxCsrfToken(); }
    var meta = document.querySelector('meta[property="glpi:csrf_token"]');
    return meta ? meta.getAttribute('content') : '';
  };
  var readPending = function () {
    try { return JSON.parse(window.sessionStorage.getItem(STORE) || '{}') || {}; } catch (e) { return {}; }
  };
  var writePending = function (map) {
    try { window.sessionStorage.setItem(STORE, JSON.stringify(map)); } catch (e) { /* stockage indisponible */ }
  };

  document.addEventListener('click', function (event) {
    var btn = event.target && event.target.closest ? event.target.closest('[data-stats-hotline-generate]') : null;
    if (!btn) { return; }
    event.preventDefault();
    var id = parseInt(btn.getAttribute('data-ticket'), 10) || 0;
    if (id <= 0) { return; }
    if (typeof window.rp_loadCriForm !== 'function') {
      window.location.href = cfg.rpTabUrl + id;
      return;
    }
    opened = id;
    window.rp_loadCriForm('showCriForm', 'form_rapport_hotline', { job: id, root_doc: cfg.rpWebdir });
  });

  // Phase de CAPTURE sur window : passe avant les écouteurs du plugin RP,
  // posés sur document, donc avant l'envoi qu'ils déclenchent.
  window.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.nodeName !== 'FORM' || form.getAttribute('name') !== 'formReport') { return; }
    var field = form.querySelector('input[name="REPORT_ID"]');
    var id = field ? (parseInt(field.value, 10) || 0) : 0;
    if (id <= 0 || id !== opened) { return; }

    var map = readPending();
    map[id] = Date.now();
    writePending(map);

    var back = window.location.href;
    try {
      window.history.replaceState(window.history.state, '', cfg.ticketUrl + id);
    } catch (e) {
      return;
    }
    var done = false;
    var restore = function () {
      if (done) { return; }
      done = true;
      try { window.history.replaceState(window.history.state, '', back); } catch (e) { /* rien */ }
    };
    pending = { event: event, restore: restore };
    window.addEventListener('focus', restore, { once: true });
    window.setTimeout(restore, 1500);
  }, true);

  // Phase de BULLE sur window : dernier écouteur. Envoi intercepté par la file
  // RP (preventDefault) = requête déjà partie, l'adresse peut revenir.
  window.addEventListener('submit', function (event) {
    if (pending && pending.event === event && event.defaultPrevented) {
      pending.restore();
    }
    pending = null;
  });

  // Au chargement : rattacher au ticket le PDF des rapports produits depuis
  // cet onglet. Un rapport pas encore enregistré (génération en cours) est
  // retenté au chargement suivant, pendant un quart d'heure au plus.
  var map = readPending();
  Object.keys(map).forEach(function (id) {
    if (Date.now() - map[id] > 15 * 60 * 1000) { delete map[id]; writePending(map); return; }
    var body = new URLSearchParams({ action: 'finalize', tickets_id: id });
    fetch(cfg.statsAjax, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': token()
      },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res && (res.status === 'done' || res.status === 'forbidden')) {
        var current = readPending();
        delete current[id];
        writePending(current);
      }
    }).catch(function () { /* nouvel essai au prochain chargement */ });
  });
})();
JS;

        echo Html::scriptBlock($js);
    }

    /**
     * Génération de masse, ticket par ticket.
     *
     * Pour chaque ticket, exactement ce que ferait un technicien, sans rien
     * recopier du plugin RP :
     *  1. demander à RP son formulaire hotline (`ajax/cri.php`), avec ses
     *     réglages : tâches et suivis cochés par défaut, textes, charte ;
     *  2. l'envoyer tel quel à son générateur (`cripdf.form.php`), en retirant
     *     seulement l'envoi du mail au client. Le Referer est celui du ticket,
     *     pour le lien « TICKET : N » du PDF ;
     *  3. vérifier côté serveur que le rapport est enregistré, et rattacher son
     *     PDF au ticket.
     *
     * Un ticket qui a déjà son rapport est passé : relancer la page ne produit
     * jamais de doublon.
     */
    private static function generationScript(string $listUrl): void
    {
        $cfg = self::jsConfig([
            'listUrl' => $listUrl,
            'i18n'    => [
                'waiting'  => __('En attente', 'stats'),
                'running'  => __('En cours…', 'stats'),
                'done'     => __('Généré', 'stats'),
                'exists'   => __('Déjà présent', 'stats'),
                'error'    => __('Échec', 'stats'),
                'linked'   => __('PDF rattaché au ticket', 'stats'),
                'already'  => __('PDF déjà rattaché au ticket', 'stats'),
                'nolink'   => __("PDF non rattaché au ticket", 'stats'),
                'nodoc'    => __("Rapport enregistré par RP, sans document PDF", 'stats'),
                'missing'  => __("Le plugin RP n'a enregistré aucun rapport pour ce ticket.", 'stats'),
                'noform'   => __("Formulaire hotline du plugin RP introuvable.", 'stats'),
                'existsMsg' => __('Le ticket a déjà son rapport hotline : passé.', 'stats'),
                'finished' => __('Terminé.', 'stats'),
                'stopped'  => __('Arrêté.', 'stats'),
                'working'  => __('Génération en cours, ne fermez pas cette page.', 'stats'),
                'leave'    => __('La génération est en cours. Quitter la page l\'interrompt.', 'stats'),
            ],
        ]);

        $js = <<<JS
(function () {
  'use strict';
  var cfg = {$cfg};
  var root = document.querySelector('[data-stats-hotline-job]');
  if (!root) { return; }

  var running = false;
  var stopAsked = false;

  var token = function () {
    if (typeof window.getAjaxCsrfToken === 'function') { return window.getAjaxCsrfToken(); }
    var meta = document.querySelector('meta[property="glpi:csrf_token"]');
    return meta ? meta.getAttribute('content') : '';
  };
  var ajaxHeaders = function (extra) {
    var h = { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token() };
    Object.keys(extra || {}).forEach(function (k) { h[k] = extra[k]; });
    return h;
  };
  var esc = function (s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  };
  var rows = function () { return Array.prototype.slice.call(root.querySelectorAll('tr[data-ticket]')); };

  var badge = { todo: 'bg-secondary-lt', running: 'bg-blue-lt', done: 'bg-green-lt', exists: 'bg-azure-lt', error: 'bg-red-lt' };
  var label = { todo: cfg.i18n.waiting, running: cfg.i18n.running, done: cfg.i18n.done, exists: cfg.i18n.exists, error: cfg.i18n.error };

  var setState = function (row, state, detailHtml) {
    row.setAttribute('data-state', state);
    var cell = row.querySelector('[data-cell="state"]');
    if (cell) {
      cell.innerHTML = '<span class="badge ' + badge[state] + '">'
        + (state === 'running' ? '<span class="spinner-border spinner-border-sm me-1"></span>' : '')
        + esc(label[state]) + '</span>';
    }
    if (detailHtml !== undefined) {
      var d = row.querySelector('[data-cell="detail"]');
      if (d) { d.innerHTML = detailHtml; }
    }
    refresh();
  };

  var refresh = function () {
    var counts = { done: 0, exists: 0, error: 0, todo: 0 };
    var total = 0;
    rows().forEach(function (r) {
      var s = r.getAttribute('data-state');
      if (s === 'skipped') { return; }
      total++;
      if (s === 'running') { counts.todo++; } else if (counts[s] !== undefined) { counts[s]++; }
    });
    Object.keys(counts).forEach(function (k) {
      var el = root.querySelector('[data-count="' + k + '"]');
      if (el) { el.textContent = counts[k]; }
    });
    var bar = root.querySelector('[data-job-bar]');
    if (bar) {
      var pct = total > 0 ? Math.round(((total - counts.todo) / total) * 100) : 100;
      bar.style.width = pct + '%';
      bar.textContent = pct + ' %';
    }
  };

  var status = function (text) {
    var el = root.querySelector('[data-job-status]');
    if (el) { el.textContent = text || ''; }
  };

  var postStats = function (params) {
    return fetch(cfg.statsAjax, {
      method: 'POST',
      credentials: 'same-origin',
      headers: ajaxHeaders({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }),
      body: new URLSearchParams(params).toString()
    }).then(function (r) {
      return r.json().catch(function () { return { status: 'error', message: 'HTTP ' + r.status }; });
    });
  };

  // Champs d'un formulaire, comme le navigateur les enverrait.
  var collect = function (form) {
    var data = new FormData();
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) { return; }
      var type = (el.type || '').toLowerCase();
      if (type === 'file' || type === 'submit' || type === 'button' || type === 'reset' || type === 'image') { return; }
      if ((type === 'checkbox' || type === 'radio') && !el.checked) { return; }
      if (el.nodeName === 'SELECT') {
        Array.prototype.forEach.call(el.options, function (o) { if (o.selected) { data.append(el.name, o.value); } });
        return;
      }
      data.append(el.name, el.value);
    });
    return data;
  };

  var describeMessages = function (messages) {
    return (messages || []).map(function (m) {
      var cls = m.type === 'error' ? 'text-danger' : (m.type === 'warning' ? 'text-warning' : 'text-muted');
      return '<div class="' + cls + '">' + esc(m.text) + '</div>';
    }).join('');
  };

  var processOne = function (row) {
    var id = parseInt(row.getAttribute('data-ticket'), 10) || 0;
    setState(row, 'running', '');

    return postStats({ action: 'check', tickets_id: id }).then(function (chk) {
      if (chk.status === 'exists') {
        setState(row, 'exists', '<span class="text-muted">' + esc(cfg.i18n.existsMsg) + '</span>');
        return;
      }
      if (chk.status !== 'todo') {
        throw new Error(chk.message || chk.status || 'check');
      }

      // 1. Formulaire hotline du plugin RP
      var formBody = new URLSearchParams();
      formBody.append('action', 'showCriForm');
      formBody.append('modal', 'form_rapport_hotline');
      formBody.append('params[job]', String(id));
      formBody.append('params[root_doc]', cfg.rpWebdir);

      return fetch(cfg.rpWebdir + '/ajax/cri.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: ajaxHeaders({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }),
        body: formBody.toString()
      }).then(function (r) {
        return r.text().then(function (html) {
          if (!r.ok) { throw new Error((html || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 200) || ('HTTP ' + r.status)); }
          return html;
        });
      }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var form = doc.querySelector('form[name="formReport"]');
        if (!form) { throw new Error(cfg.i18n.noform); }

        // 2. Envoi au générateur RP, sans mail au client
        var data = collect(form);
        data.delete('mailtoclient');
        var action = new URL(form.getAttribute('action') || '', window.location.href).href;

        return fetch(action, {
          method: 'POST',
          credentials: 'same-origin',
          redirect: 'manual',
          referrer: new URL(cfg.ticketUrl + id, window.location.href).href,
          body: data
        });
      }).then(function (gen) {
        if (gen.type !== 'opaqueredirect' && !gen.ok) {
          return gen.text().then(function (t) {
            throw new Error((t || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 200) || ('HTTP ' + gen.status));
          });
        }
        // 3. Vérification et rattachement du PDF
        return postStats({ action: 'finalize', tickets_id: id, collect_messages: 1 }).then(function (fin) {
          var msgs = describeMessages(fin.messages);
          if (fin.status !== 'done') {
            setState(row, 'error', '<div class="text-danger">' + esc(cfg.i18n.missing) + '</div>' + msgs);
            return;
          }
          var html = '';
          if (fin.documents_id > 0) {
            html += '<a href="' + esc(fin.document_url) + '" target="_blank"><i class="ti ti-file-type-pdf me-1"></i>'
              + esc(fin.document_name) + '</a>';
            var linkText = fin.link === 'linked' ? cfg.i18n.linked : (fin.link === 'already' ? cfg.i18n.already : cfg.i18n.nolink);
            html += ' <span class="' + (fin.link === 'failed' ? 'text-danger' : 'text-muted') + '">· ' + esc(linkText) + '</span>';
          } else {
            html += '<span class="text-warning">' + esc(cfg.i18n.nodoc) + '</span>';
          }
          setState(row, 'done', html + msgs);
        });
      });
    }).catch(function (err) {
      setState(row, 'error', '<span class="text-danger">' + esc(err && err.message ? err.message : err) + '</span>');
    });
  };

  var setButtons = function () {
    var stop = root.querySelector('[data-job-stop]');
    var resume = root.querySelector('[data-job-resume]');
    var left = rows().some(function (r) { return r.getAttribute('data-state') === 'todo'; });
    if (stop) { stop.classList.toggle('d-none', !running); }
    if (resume) { resume.classList.toggle('d-none', running || !left); }
  };

  var run = function () {
    if (running) { return; }
    running = true;
    stopAsked = false;
    status(cfg.i18n.working);
    setButtons();

    var next = function () {
      var row = rows().find(function (r) { return r.getAttribute('data-state') === 'todo'; });
      if (!row || stopAsked) {
        running = false;
        status(stopAsked && row ? cfg.i18n.stopped : cfg.i18n.finished);
        setButtons();
        return;
      }
      processOne(row).then(next);
    };
    next();
  };

  window.addEventListener('beforeunload', function (e) {
    if (running) { e.preventDefault(); e.returnValue = cfg.i18n.leave; return cfg.i18n.leave; }
  });

  var stopBtn = root.querySelector('[data-job-stop]');
  if (stopBtn) { stopBtn.addEventListener('click', function () { stopAsked = true; }); }
  var resumeBtn = root.querySelector('[data-job-resume]');
  if (resumeBtn) { resumeBtn.addEventListener('click', run); }

  refresh();
  run();
})();
JS;

        echo Html::scriptBlock($js);
    }
}
