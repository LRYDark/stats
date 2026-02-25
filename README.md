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

## Droits / profils

- L'accès à la page se fait via les droits du plugin `Stats`.
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
