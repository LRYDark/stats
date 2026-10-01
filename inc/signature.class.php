<?php

/**
 * Outillage commun aux deux onglets de statistiques de signature
 * (« Stats rapports » côté plugin RP, « Stats bons de livraison » côté Gestion).
 *
 * Les deux onglets répondent à la même question — qui fait signer, combien, à
 * quel rythme, et qui ne le fait pas — sur deux sources différentes. Tout ce qui
 * ne dépend pas de la source vit donc ici : période, cloisonnement par entité,
 * tuiles, graphiques, mise en forme. Une seconde copie aurait divergé au premier
 * ajustement de présentation.
 */
class PluginStatsSignature
{
    /** Nombre de mois affichés par défaut à l'ouverture d'un onglet. */
    public const DEFAULT_MONTHS = 12;

    /**
     * Période demandée, ou les douze derniers mois.
     *
     * Les deux bornes sont retournées en date seule (Y-m-d) ; les comparaisons
     * SQL les élargissent ensuite à la journée entière, sans quoi un document
     * signé l'après-midi du dernier jour tomberait hors période.
     *
     * @param string $prefix préfixe des paramètres GET, propre à l'onglet
     * @return array{begin:string, end:string}
     */
    public static function period(string $prefix): array
    {
        $valid = static function ($value): string {
            $value = trim((string) $value);
            if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return '';
            }
            return $value;
        };

        $begin = $valid($_GET[$prefix . '_date_begin'] ?? '');
        $end   = $valid($_GET[$prefix . '_date_end'] ?? '');

        if ($begin === '') {
            $begin = date('Y-m-d', strtotime('-' . (self::DEFAULT_MONTHS - 1) . ' months first day of this month'));
        }
        if ($end === '') {
            $end = date('Y-m-d');
        }

        // Bornes inversées par mégarde : on les remet dans l'ordre plutôt que de
        // renvoyer un tableau vide sans explication.
        if ($begin > $end) {
            [$begin, $end] = [$end, $begin];
        }

        return ['begin' => $begin, 'end' => $end];
    }

    /**
     * Critères d'entité pour une table donnée.
     *
     * Le socle est TOUJOURS le périmètre de l'utilisateur connecté : la sélection
     * faite dans le formulaire ne peut que le restreindre davantage, jamais
     * l'élargir. Sans cette intersection, il suffisait d'écrire une entité dans
     * l'URL pour lire les chiffres d'un périmètre auquel on n'a pas accès.
     *
     * @param array    $requested entités choisies dans le filtre
     * @param callable $expand    closure d'expansion aux sous-entités
     */
    public static function entityCriteria(string $table, array $requested, callable $expand): array
    {
        $criteria = getEntitiesRestrictCriteria($table);

        $scope = $expand($requested);
        if (count($scope) > 0) {
            /*
             * AJOUTÉE en clause distincte, jamais écrite sur la clé
             * `<table>.entities_id` déjà posée ci-dessus : l'y écraser aurait
             * remplacé le périmètre autorisé par celui demandé, et il aurait
             * suffi d'écrire une entité dans l'URL pour lire des chiffres
             * auxquels on n'a pas droit. Les deux clauses se cumulent, la
             * sélection ne peut donc que restreindre.
             */
            $criteria[] = [$table . '.entities_id' => $scope];
        }

        return $criteria;
    }

    /**
     * Barre de tuiles, même présentation que celles des plugins RP et Gestion.
     *
     * Reprise volontaire : le technicien retrouve ici l'habillage qu'il voit déjà
     * en tête de la liste des rapports et de celle des bons.
     *
     * Pas de pastille d'aide ici, volontairement : cette barre doit se lire d'un
     * coup d'œil, et quatre icônes au milieu des chiffres la rendaient bavarde.
     * Le texte d'aide reste porté par l'attribut `title`, au survol — même
     * comportement que les barres de chiffres des plugins RP et Gestion. Les
     * explications visibles sont sur les titres des cartes, en dessous.
     *
     * @param array<int, array{label:string, value:string, color:string, icon:string, tooltip?:string, url?:string}> $cards
     */
    public static function tiles(array $cards): void
    {
        echo "<div class='card mb-3'><div class='card-body py-2 px-3 d-flex flex-wrap align-items-center'>";
        $first = true;
        foreach ($cards as $c) {
            if (!$first) {
                echo "<div class='vr mx-3 my-1'></div>";
            }
            $first = false;

            // Libellé tronqué dans la barre : l'infobulle porte la phrase entière,
            // sans quoi « … dont plus de 7 jours » ne veut rien dire lu seul.
            $title = trim((string) ($c['tooltip'] ?? '')) !== '' ? $c['tooltip'] : $c['label'];
            $url   = (string) ($c['url'] ?? '');
            $tag   = $url !== '' ? 'a' : 'span';
            $href  = $url !== '' ? " href='" . htmlescape($url) . "'" : '';

            echo "<{$tag}{$href} class='d-flex align-items-center gap-2 text-decoration-none text-reset py-1'"
                . " title='" . htmlescape($title) . "'>";
            echo "<span class='avatar avatar-sm bg-" . htmlescape($c['color']) . "-lt'>"
                . "<i class='" . htmlescape($c['icon']) . "'></i></span>";
            echo "<span class='d-flex flex-column lh-sm'>";
            echo "<span class='h2 fw-bold mb-0'>" . htmlescape((string) $c['value']) . "</span>";
            echo "<span class='text-muted small'>" . htmlescape($c['label']) . "</span>";
            echo "</span></{$tag}>";
        }
        echo "</div></div>";
    }

    /**
     * Liste des mois de la période, au format 'Y-m'.
     *
     * Construite en PHP plutôt qu'en SQL : un mois sans aucune signature doit
     * apparaître à zéro sur la courbe. Le laisser produire par un GROUP BY
     * l'aurait purement et simplement omis, et la courbe aurait relié deux mois
     * non contigus comme s'ils se suivaient.
     *
     * @return array<int, string>
     */
    public static function monthRange(string $begin, string $end): array
    {
        $months  = [];
        $cursor  = strtotime(substr($begin, 0, 7) . '-01');
        $last    = strtotime(substr($end, 0, 7) . '-01');
        $guard   = 0;

        while ($cursor !== false && $cursor <= $last && $guard < 120) {
            $months[] = date('Y-m', $cursor);
            $cursor   = strtotime('+1 month', $cursor);
            $guard++;
        }

        return $months;
    }

    /** Libellé lisible d'un mois 'Y-m' → '03/2026'. */
    public static function monthLabel(string $ym): string
    {
        $ts = strtotime($ym . '-01');
        return $ts === false ? $ym : date('m/Y', $ts);
    }

    /** Pourcentage arrondi, ou '—' quand il n'y a rien à rapporter. */
    public static function percent(int $part, int $total): string
    {
        if ($total <= 0) {
            return '—';
        }
        return round(($part / $total) * 100) . ' %';
    }

    /**
     * Couleur Tabler d'un taux de couverture.
     *
     * Le seuil n'est pas une note : il sert à faire ressortir du tableau les
     * lignes qui demandent une conversation, pas à classer les gens.
     */
    public static function coverageColor(int $part, int $total): string
    {
        if ($total <= 0) {
            return 'secondary';
        }
        $rate = ($part / $total) * 100;
        if ($rate >= 90) {
            return 'green';
        }
        if ($rate >= 60) {
            return 'yellow';
        }
        return 'red';
    }

    /** Durée en jours, arrondie, ou '—'. */
    public static function days(?float $days): string
    {
        if ($days === null) {
            return '—';
        }
        if ($days < 1) {
            return __('le jour même', 'stats');
        }
        return sprintf(_n('%s jour', '%s jours', (int) round($days), 'stats'), round($days, 1));
    }

    /**
     * Formulaire de filtres commun : entité, technicien, période.
     *
     * @param array $helpers closures partagées de front/stats.php
     */
    public static function filterForm(string $view, array $helpers, array $selected): void
    {
        global $CFG_GLPI;

        $helpers['openFilterCard']();

        echo "<form method='get' action='" . htmlescape($CFG_GLPI['root_doc'] . '/plugins/stats/front/stats.php')
            . "' class='row g-3 align-items-end stats-filters'>";
        echo Html::hidden('view', ['value' => $view]);

        /*
         * `value` au singulier, même en sélection multiple : c'est la clé
         * qu'attend Dropdown::show, et celle qu'emploient déjà les autres
         * onglets. Le pluriel serait ignoré en silence, et la sélection
         * disparaîtrait à chaque application du filtre.
         */
        echo "<div class='col-md-4'>";
        echo "<label class='form-label'>" . __('Entités', 'stats') . "</label>";
        Dropdown::show('Entity', [
            'name'     => $view . '_entities_id[]',
            'value'    => $selected['entities'],
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo "</div>";

        echo "<div class='col-md-4'>";
        echo "<label class='form-label'>" . __('Techniciens', 'stats') . "</label>";
        Dropdown::show('User', [
            'name'     => $view . '_users_id[]',
            'value'    => $selected['users'],
            'multiple' => true,
            'right'    => 'all',
            'width'    => '100%',
        ]);
        echo "</div>";

        echo "<div class='col-md-2'>";
        echo "<label class='form-label'>" . __('Du', 'stats') . "</label>";
        Html::showDateField($view . '_date_begin', [
            'value'       => $selected['begin'],
            'placeholder' => __('Date de début', 'stats'),
            'display'     => true,
        ]);
        echo "</div>";

        echo "<div class='col-md-2'>";
        echo "<label class='form-label'>" . __('Au', 'stats') . "</label>";
        Html::showDateField($view . '_date_end', [
            'value'       => $selected['end'],
            'placeholder' => __('Date de fin', 'stats'),
            'display'     => true,
        ]);
        echo "</div>";

        $helpers['favoritesBar']($view);

        /*
         * Sans attribut `name`, comme sur les autres onglets : le formulaire est
         * en GET, un bouton nommé ajouterait son paramètre à l'URL et se
         * retrouverait enregistré dans les filtres favoris.
         */
        echo "<div class='col-12 text-end'>";
        echo Html::submit(__('Filtrer', 'stats'), ['class' => 'btn btn-primary']);
        echo "</div>";

        Html::closeForm();
        $helpers['closeFilterCard']();
    }

    /**
     * Rend un ou plusieurs graphiques ECharts.
     *
     * Même chargeur que les onglets existants : ECharts est cherché à trois
     * emplacements possibles selon la version de GLPI, et l'absence de la
     * bibliothèque laisse simplement les conteneurs vides — jamais d'erreur JS
     * qui casserait le reste de la page.
     *
     * @param array<string, array{type:string, data:array, labels?:array}> $charts id du conteneur => définition
     */
    public static function charts(array $charts): void
    {
        global $CFG_GLPI;

        $payload = json_encode($charts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $urls    = json_encode([
            Html::cleanInputText($CFG_GLPI['root_doc'] . '/lib/echarts.min.js'),
            Html::cleanInputText($CFG_GLPI['root_doc'] . '/public/lib/echarts.min.js'),
            '/lib/echarts.min.js',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $js = <<<JS
(function() {
  var payload = {$payload};
  var urls = {$urls};
  var charts = [];

  var render = function(id, def) {
    var el = document.getElementById(id);
    if (!el || !window.echarts || !def || !def.data || !def.data.length) {
      return;
    }
    var instance = echarts.getInstanceByDom(el) || echarts.init(el);
    var option;
    if (def.type === 'pie') {
      option = {
        tooltip: { trigger: 'item' },
        series: [{
          type: 'pie',
          radius: ['40%', '70%'],
          avoidLabelOverlap: true,
          label: { formatter: '{b}: {c}' },
          data: def.data
        }]
      };
    } else {
      option = {
        tooltip: { trigger: 'axis' },
        grid: { left: '3%', right: '4%', bottom: '3%', top: '12%', containLabel: true },
        xAxis: { type: 'category', boundaryGap: def.type === 'bar', data: def.labels || [] },
        yAxis: { type: 'value', minInterval: 1 },
        series: [{
          type: def.type === 'bar' ? 'bar' : 'line',
          smooth: def.type !== 'bar',
          areaStyle: def.type === 'bar' ? undefined : {},
          data: def.data,
          itemStyle: { color: '#3a7bd5' }
        }]
      };
    }
    instance.setOption(option);
    charts.push(instance);
  };

  var renderAll = function() {
    charts = [];
    Object.keys(payload).forEach(function(id) { render(id, payload[id]); });
  };

  var load = function(done) {
    if (window.echarts) { done(); return; }
    var index = 0;
    var tryNext = function() {
      if (index >= urls.length) { return; }
      var script = document.createElement('script');
      script.src = urls[index++];
      script.onload = function() { if (window.echarts) { done(); } else { tryNext(); } };
      script.onerror = tryNext;
      document.head.appendChild(script);
    };
    tryNext();
  };

  var boot = function() {
    load(function() {
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

        echo Html::scriptBlock($js);
    }

    /** Message d'attente affiché quand la période ne contient rien. */
    public static function emptyState(string $message): void
    {
        echo "<div class='alert alert-info d-flex align-items-center'>";
        echo "<i class='ti ti-info-circle me-2'></i><span>" . htmlescape($message) . "</span>";
        echo "</div>";
    }
}
