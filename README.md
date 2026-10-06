# Stats

Plugin GLPI de tableaux de bord orientés exploitation: statistiques tickets, statistiques crédits (si les plugins `credit` et `creditalert` sont présents) et statistiques de satisfaction (si `satisfactionclient` est présent).

Le but du plugin est de donner une vue rapide de l'activité, avec filtres par période, entité et technicien, puis d'ouvrir des vues de détail exportables.

## Ce que fait le plugin (lecture rapide)

- Ajoute un menu `Outils > Stats`.
- Affiche des onglets dynamiques selon les plugins complémentaires installés.
- Permet de filtrer par dates, entités et techniciens.
- Ouvre des modals de détail (tickets / satisfaction) pour vérifier les chiffres.
- Permet l'export CSV depuis certaines vues de détail.

## Fonctionnement (parcours type)

1. Ouvrir `Outils > Stats`.
2. Choisir l'onglet souhaité (`tickets`, `credits`, `satisfaction`) selon ce qui est disponible.
3. Définir la période à analyser.
4. Limiter aux entités voulues (avec prise en charge des entités filles selon le contexte du plugin).
5. Filtrer par technicien si besoin.
6. Ouvrir les détails puis exporter CSV si vous voulez retraiter les données.

## Onglet « Rapports hotline à générer » (1.0.6)

Liste de travail : les tickets facturés en crédit qui n'ont pas encore leur rapport hotline.

### Une liste native GLPI

L'onglet affiche une vraie liste du moteur de recherche GLPI : critères, tri, pagination, choix des colonnes, exports, recherches sauvegardées et actions massives sont ceux de GLPI. Elle a sa propre mémoire de recherche, et ne touche donc pas à la dernière recherche de la liste des tickets.

Elle a sa propre page, `front/hotlineticket.php`, construite comme une page de liste GLPI : en-tête, moteur de recherche, pied de page. La barre d'onglets de la page Statistiques est affichée au-dessus. L'ancienne adresse `stats.php?view=hotline` y redirige.

Sur grand écran, GLPI donne à une liste la hauteur exacte de l'écran : la barre de contrôle reste en haut, la pagination en bas, et les lignes défilent entre les deux. Onglets et liste se partagent cette hauteur. Sans cela, la barre d'onglets poussait la pagination hors de l'écran.

La liste s'ouvre toujours sur le nombre de lignes par page par défaut de l'utilisateur (sa préférence, sinon celle de GLPI : 50). GLPI garde pour toute la session le dernier nombre choisi dans une liste : sans cela, un « 2000 » choisi ailleurs s'appliquait ici aussi. Un nombre choisi dans cette liste reste valable pour le tri et le changement de page, jusqu'à la prochaine ouverture.

La source est une vue SQL, `glpi_plugin_stats_hotlinetickets`. Elle contient chaque ticket :
- résolu ou clos, et non supprimé ;
- qui a consommé du crédit (somme des consommations du plugin `credit`) ;
- sans aucun rapport hotline dans le plugin `rp`, quelle que soit son origine : bouton du ticket, cet onglet, export massif RP.

La vue est créée à l'installation, ou à la première ouverture de l'onglet si elle manque. Elle est supprimée à la désinstallation, avec les colonnes et les recherches sauvegardées de la liste.

La vue cherche les rapports hotline par numéro de ticket dans `glpi_plugin_rp_cridetails`, une colonne que le plugin `rp` n'indexe pas. Le plugin pose donc l'index `id_ticket_type` (`id_ticket`, `type`) sur cette table : à l'installation, à la mise à jour, et à l'ouverture de la liste s'il manque. Sans lui, la liste mettait environ une minute à s'afficher en production. L'index est conservé à la désinstallation.

Critères à l'ouverture, modifiables comme dans toute liste GLPI :
- « Crédit consommé » `>1`, soit un crédit supérieur à 1 ;
- « Crédit » contient « à Mettre en facturation » : le nom du crédit du plugin `credit` sur lequel la consommation est faite. Ce critère et le précédent portent sur la même consommation, car GLPI ne joint qu'une fois la table des consommations : seul ce qui est consommé sur un crédit à facturer compte. Les variantes du nom (`PROV - …`, `z…`, tabulation en tête) sont comprises ;
- « Catégorie » est « Root entity > EASI SUPPORT : toutes les catégories ». Les catégories EasiSupport sont rangées sous cette entité. Dans la liste native des catégories, c'est un titre de groupe qu'on ne peut pas sélectionner. Le critère « Catégorie » de cette liste propose chaque groupe entier, puis ses catégories de premier niveau, sous-catégories toujours comprises. Le groupe EASI SUPPORT est reconnu au nom de son entité contenant « EasiSupport », casse, espaces et tirets ignorés ;
- tri par date de clôture, la plus récente d'abord.

Ces critères portent sur des colonnes déjà affichées. GLPI ajoute en colonne tout champ filtré : un critère sur un autre champ ferait apparaître une colonne de plus. Le SQL du critère « Catégorie » est écrit par `PluginStatsHotlineticket::addWhere()`, le point d'extension que GLPI prévoit pour un type d'objet.

Une recherche sauvegardée marquée par défaut remplace ces critères.

Colonnes par défaut : ticket (numéro cliquable, toujours en tête), titre, entité, statut (pastille Résolu ou Clos), crédit consommé, crédit (nom), ouverture, clôture, technicien, catégorie, bouton « Générer ». La date de résolution peut être ajoutée par le choix des colonnes. Le technicien est l'auteur de la dernière solution non refusée, sinon le premier technicien attribué.

### Générer un rapport

- **Bouton « Générer » d'une ligne :** il ouvre la fenêtre hotline du plugin `rp` par sa propre fonction, avec son éditeur riche. Après la génération, la page se recharge, le ticket quitte la liste et son PDF est rattaché au ticket.
- **Action massive « Générer le rapport hotline (RP) » :** elle ouvre une page de suivi qui traite les tickets un par un, en temps réel : en attente, en cours, généré, déjà présent, échec avec son motif. Elle peut être arrêtée puis reprise. Un ticket qui a déjà son rapport est passé, si bien que relancer la page ne crée jamais de doublon.
  - Limite de GLPI, valable pour toute liste : au-delà de `max_input_vars - 10` lignes par page, GLPI n'affiche plus les actions massives. Avec la valeur PHP par défaut (1000), c'est 990 lignes. La barre « Actions » apparaît alors vide au premier coche, et recouvre la barre de contrôle. Il faut afficher moins de lignes par page, ou relever `max_input_vars` dans la configuration PHP du serveur.

Dans les deux cas, le rapport est produit par le formulaire et le générateur du plugin `rp`, avec ses réglages. Rien n'est recopié ni modifié dans `rp`.
- En génération de masse, aucun mail n'est envoyé au client.
- Le PDF est rattaché au ticket, dans ses documents et son fil. Le plugin `rp` ne renseigne que l'ancien champ `tickets_id` du document, que GLPI 11 n'utilise plus pour ce lien. Le rattachement se fait sans notification.
- Le lien « TICKET : N » imprimé dans le PDF pointe vers le ticket, comme pour un rapport généré depuis le ticket.
- Les deux suivent le droit « Rapport hotline » de `rp`, règles individuelles comprises.

L'onglet n'existe que si les plugins `rp` et `credit` sont installés et actifs.

## Configuration plugin

Le plugin `Stats` a volontairement peu de configuration propre.

Le comportement dépend principalement de:
- vos droits de profil (accès lecture au plugin)
- la présence des plugins complémentaires (`credit`, `creditalert`, `satisfactionclient`)
- les données réellement présentes dans la base GLPI

En pratique, l'administration consiste surtout à:
- activer le plugin
- attribuer les droits de lecture aux profils concernés
- vérifier les plugins optionnels si vous voulez les onglets `credits` / `satisfaction`

## Prérequis

- GLPI 11.x
- PHP compatible avec votre version GLPI
- Plugin `credit` + `creditalert` si vous voulez les statistiques crédits
- Plugin `satisfactionclient` si vous voulez les statistiques satisfaction
- Plugins `rp` + `credit` si vous voulez l'onglet « Rapports hotline à générer »

## Droits / profils

- L'accès à la page se fait via les droits du plugin `Stats`, un droit par onglet (`Administration > Profils > Statistiques`).
- Le droit « Rapports hotline à générer (RP + Credit) » est créé à la mise à jour 1.0.6, fermé pour tous les profils : il faut l'ouvrir aux profils concernés.
- Un profil dont aucun onglet n'est affichable (droit ouvert sur un onglet dont le plugin source est absent) reçoit un refus d'accès.
- Les vues de détail réutilisent les contrôles de droits du plugin.
- Les résultats restent limités par les droits GLPI de l'utilisateur (entités / objets visibles).

## Architecture (résumé court)

- Une page principale construit les tableaux et graphiques selon l'onglet choisi.
- Les onglets disponibles sont détectés dynamiquement selon les plugins/tables présents.
- Les modals de détail servent à vérifier le chiffre derrière un indicateur et à exporter.

## Vérifications rapides après mise à jour

- Ouvrir `Outils > Stats` sans erreur PHP.
- Vérifier l'affichage de l'onglet `tickets`.
- Vérifier la présence/absence logique des onglets `credits` et `satisfaction`.
- Tester un filtre de dates et une ouverture de modal.
- Tester un export CSV si vous l'utilisez.
- Onglet « Rapports hotline à générer » : vérifier les critères d'ouverture (crédit `>1`, groupe EASI SUPPORT), cliquer « Générer » sur une ligne, contrôler l'éditeur riche de la fenêtre RP, le lien « TICKET » du PDF, la disparition de la ligne et le PDF dans les documents du ticket.
- Action massive « Générer le rapport hotline (RP) » sur deux ou trois tickets : suivre la page de génération, puis vérifier les PDF sur les tickets.
