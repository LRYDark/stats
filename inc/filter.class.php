<?php

/**
 * Filtre favori du plugin stats : une combinaison de filtres nommee, privee a un utilisateur.
 *
 * Le champ `params` stocke la chaine de requete (query string) du formulaire de filtre
 * de la vue concernee (`view`), ce qui permet de la reappliquer telle quelle.
 */
class PluginStatsFilter extends CommonDBTM
{
    // Droit de secours pour les helpers herites ; l'isolation reelle se fait par users_id.
    public static $rightname = PluginStatsProfile::RIGHTNAME_TICKETS;

    // Pas d'historique : on ne veut pas polluer glpi_logs a chaque sauvegarde de filtre.
    public $dohistory = false;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_stats_filters';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Filtre favori', 'Filtres favoris', $nb, 'stats');
    }

    /**
     * Vues autorisees pour un favori.
     */
    public static function getAllowedViews(): array
    {
        return ['tickets', 'satisfaction', 'credits'];
    }

    public static function normalizeView($view): string
    {
        $view = (string) $view;
        return in_array($view, self::getAllowedViews(), true) ? $view : 'tickets';
    }

    /**
     * Liste des favoris d'un utilisateur pour une vue donnee.
     *
     * @return array<int, array{id:int, name:string, is_default:int, params:string}>
     */
    public static function getForUser(int $users_id, string $view): array
    {
        global $DB;

        if ($users_id <= 0 || !$DB->tableExists(self::getTable())) {
            return [];
        }

        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'is_default', 'params'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'users_id' => $users_id,
                'view'     => self::normalizeView($view),
            ],
            'ORDER'  => ['name ASC'],
        ]) as $row) {
            $rows[] = [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'is_default' => (int) $row['is_default'],
                'params'     => (string) ($row['params'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * Favori marque "par defaut" d'un utilisateur pour une vue, ou null.
     *
     * @return array{id:int, name:string, params:string}|null
     */
    public static function getDefaultForUser(int $users_id, string $view): ?array
    {
        global $DB;

        if ($users_id <= 0 || !$DB->tableExists(self::getTable())) {
            return null;
        }

        $row = $DB->request([
            'SELECT' => ['id', 'name', 'params'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'users_id'   => $users_id,
                'view'       => self::normalizeView($view),
                'is_default' => 1,
            ],
            'LIMIT'  => 1,
        ])->current();

        if (!$row) {
            return null;
        }
        return [
            'id'     => (int) $row['id'],
            'name'   => (string) $row['name'],
            'params' => (string) ($row['params'] ?? ''),
        ];
    }

    /**
     * Retire le marqueur "par defaut" des autres favoris de la meme (users_id, view).
     */
    protected function clearOtherDefaults(int $users_id, string $view, int $exceptId): void
    {
        global $DB;

        $DB->update(
            self::getTable(),
            ['is_default' => 0],
            [
                'users_id'   => $users_id,
                'view'       => self::normalizeView($view),
                'is_default' => 1,
                'id'         => ['<>', $exceptId],
            ]
        );
    }

    public function prepareInputForAdd($input)
    {
        // Toujours rattacher le favori a l'utilisateur connecte (jamais de confiance dans l'input).
        $input['users_id']   = Session::getLoginUserID();
        $input['view']       = self::normalizeView($input['view'] ?? 'tickets');
        $input['name']       = trim((string) ($input['name'] ?? ''));
        $input['is_default'] = !empty($input['is_default']) ? 1 : 0;
        $input['params']     = (string) ($input['params'] ?? '');

        if ($input['name'] === '' || (int) $input['users_id'] <= 0) {
            return false;
        }

        $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $input['date_mod']      = $input['date_creation'];

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['view'])) {
            $input['view'] = self::normalizeView($input['view']);
        }
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                unset($input['name']);
            }
        }
        if (isset($input['is_default'])) {
            $input['is_default'] = !empty($input['is_default']) ? 1 : 0;
        }
        $input['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        return $input;
    }

    public function post_addItem()
    {
        if (!empty($this->fields['is_default'])) {
            $this->clearOtherDefaults(
                (int) $this->fields['users_id'],
                (string) $this->fields['view'],
                (int) $this->fields['id']
            );
        }
    }

    public function post_updateItem($history = true)
    {
        if (!empty($this->fields['is_default'])) {
            $this->clearOtherDefaults(
                (int) $this->fields['users_id'],
                (string) $this->fields['view'],
                (int) $this->fields['id']
            );
        }
    }

    /**
     * Cree ou met a jour un favori (upsert sur l'unicite users_id + view + name) pour l'utilisateur connecte.
     *
     * @return int|false id du favori, ou false en cas d'echec.
     */
    public static function saveForCurrentUser(string $name, string $view, string $params, bool $isDefault)
    {
        global $DB;

        $users_id = (int) Session::getLoginUserID();
        $name     = trim($name);
        $view     = self::normalizeView($view);

        if ($users_id <= 0 || $name === '') {
            return false;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $users_id, 'view' => $view, 'name' => $name],
            'LIMIT'  => 1,
        ])->current();

        $filter = new self();
        if ($existing) {
            $ok = $filter->update([
                'id'          => (int) $existing['id'],
                'params'      => $params,
                'is_default'  => $isDefault ? 1 : 0,
                '_no_message' => true,
            ]);
            return $ok ? (int) $existing['id'] : false;
        }

        $newId = $filter->add([
            'name'        => $name,
            'view'        => $view,
            'params'      => $params,
            'is_default'  => $isDefault ? 1 : 0,
            '_no_message' => true,
        ]);
        return $newId ? (int) $newId : false;
    }

    /**
     * Supprime un favori appartenant a l'utilisateur connecte. Renvoie true si supprime.
     */
    public static function deleteForCurrentUser(int $id): bool
    {
        $users_id = (int) Session::getLoginUserID();
        if ($users_id <= 0 || $id <= 0) {
            return false;
        }

        $filter = new self();
        if (!$filter->getFromDB($id)) {
            return false;
        }
        // Isolation stricte : on ne supprime que ses propres favoris.
        if ((int) $filter->fields['users_id'] !== $users_id) {
            return false;
        }
        return (bool) $filter->delete(['id' => $id, '_no_message' => true]);
    }

    /**
     * Renvoie les params d'un favori appartenant a l'utilisateur connecte, ou null.
     */
    public static function getParamsForCurrentUser(int $id): ?string
    {
        $users_id = (int) Session::getLoginUserID();
        if ($users_id <= 0 || $id <= 0) {
            return null;
        }

        $filter = new self();
        if (!$filter->getFromDB($id)) {
            return null;
        }
        if ((int) $filter->fields['users_id'] !== $users_id) {
            return null;
        }
        return (string) ($filter->fields['params'] ?? '');
    }
}
