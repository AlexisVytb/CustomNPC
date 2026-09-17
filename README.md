# CustomNPC 3.1.0

Plugin de PNJ personnalisables pour **PocketMine-MP 5** (Minecraft Bedrock).

## Dépendances

| Dépendance | Type | Obligatoire |
|---|---|---|
| FormAPI (jojoe77777) | plugin | oui |
| InvMenu (Muqsit) | virion | non, mais recommandé |

InvMenu est déclaré dans `.poggit.yml` et est intégré automatiquement à la compilation Poggit. Sans lui, les coffres d'équipement et de drops sont remplacés par des formulaires texte.

## Démarrage rapide

```
/npc admin          active le mode admin et donne la baguette
/npc create         crée un PNJ à ta position
/npc select         sélectionne le PNJ visé
/npc edit           ouvre le menu d'édition
```

L'UUID est optionnel dans toutes les commandes : le PNJ sélectionné, ou celui que tu vises, est utilisé automatiquement. `/npc id <nom>` donne un identifiant court utilisable partout à la place de l'UUID.

## Commandes

| Commande | Rôle |
|---|---|
| `/npc admin` | Mode admin : tags créateur visibles + baguette |
| `/npc wand` | Obtenir la baguette |
| `/npc select [id]` | Sélectionner un PNJ |
| `/npc create` | Créer un PNJ |
| `/npc spawn [id]` | Faire réapparaître un PNJ |
| `/npc delete [id]` | Supprimer |
| `/npc id <nom> [id]` | Identifiant court |
| `/npc edit [id]` | Menu d'édition |
| `/npc gui` | Liste interactive paginée |
| `/npc list` | Liste en chat |
| `/npc info [id]` | Fiche détaillée |
| `/npc tp [id]` | Se téléporter au PNJ |
| `/npc here [id]` | Amener le PNJ sur soi |
| `/npc move <x> <y> <z> [id]` | Position exacte |
| `/npc rotate <body\|head> [id]` | Orientation vers soi |
| `/npc armor [id]` | Coffre d'équipement |
| `/npc drops [id]` | Coffre de butin |
| `/npc skin <me\|player:pseudo\|fichier.png\|reset> [id]` | Skin |
| `/npc race <race> [id]` | Race |
| `/npc pose <pose> [id]` | Posture |
| `/npc nametag <always\|hover\|hidden> [id]` | Visibilité du nom |
| `/npc anim <type> [distance] [vitesse] [pause] [id]` | Animation |
| `/npc dialogue [id]` | Dialogues simples |
| `/npc tree [id]` | Arbre de dialogue à choix |
| `/npc shop [id]` | Boutique et échanges |
| `/npc waypoint <add\|list\|remove\|clear\|start\|stop>` | Patrouille |
| `/npc visibility [id]` | Qui voit le PNJ |
| `/npc logs [nombre]` | Historique des modifications |
| `/npc copy` / `/npc paste` | Copier les réglages |
| `/npc refresh` / `/npc reload` | Rafraîchir / recharger |
| `/npc export` / `/npc import <fichier>` | Sauvegarde JSON |
| `/npc uuid` | Mode détection d'UUID |
| `/npc fakeplayer [id]` | Configuration hub |
| `/npc debug` | Diagnostic console |
| `/sudo <joueur> <action>` | Action à la place d'un joueur (`*` pour le chat) |

## Équipement et drops

`/npc armor` ouvre un vrai coffre. Les six premières cases de la ligne du haut correspondent à casque, plastron, jambières, bottes, main principale, main secondaire. N'importe quel item fonctionne, y compris enchanté ou renommé : l'item est sérialisé intégralement en NBT.

`/npc drops` ouvre un double coffre. Tout ce qui s'y trouve à la fermeture devient le butin du PNJ à sa mort.

## Nametag

Trois modes :

- **Toujours visible** : affiché à travers les blocs et à distance.
- **Visible au survol** : n'apparaît que si le joueur vise le PNJ d'assez près (comportement vanilla).
- **Caché** : aucun nom.

Placeholders utilisables dans le titre et le sous-titre, rafraîchis toutes les 5 secondes : `{online}`, `{max}`, `{world_players}`, `{world}`, `{tps}`.

## Animations

| Type | Effet |
|---|---|
| `climb` | Monte de la distance indiquée puis redescend, en boucle |
| `patrol` | Avance puis recule selon son orientation |
| `jump` | Saut sur place répété |
| `float` | Oscillation verticale douce |
| `spin` | Rotation continue |

Distance en blocs, vitesse en blocs par tick, pause en ticks à chaque extrémité. Une animation rend le PNJ immobile automatiquement.

## Dialogues

Une ligne par message, séparées par un retour à la ligne ou `|`. Déclenchés au clic droit, avec délai configurable et son optionnel. Placeholder `{player}` disponible.

## Migration depuis la 2.x

La base est migrée automatiquement au premier démarrage : l'ancienne table est conservée sous `npcs_legacy_backup`, et les données sont converties vers le nouveau format JSON. Les anciennes armures au format `id:meta` restent lisibles.

## Boutique

`/npc shop` ouvre l'éditeur. Chaque échange définit ce que le joueur reçoit, ce qu'il paie, et éventuellement une commande de paiement (économie externe) ou une commande à l'achat (grade, kit). Options par échange : permission requise, stock maximum, stock actuel, réapprovisionnement automatique en secondes.

Le stock illimité s'obtient en laissant le stock maximum à 0. Le réapprovisionnement se recalcule à l'ouverture de la boutique, sans tâche supplémentaire.

Si le PNJ n'a pas d'arbre de dialogue actif, la boutique s'ouvre directement au clic droit. Sinon, elle s'ouvre depuis un choix de dialogue avec l'action « Ouvrir la boutique ».

## Arbre de dialogue

`/npc tree` ouvre l'éditeur. Un arbre est fait de nœuds, chacun avec des lignes de texte et des choix. Chaque choix porte une action :

| Action | Valeur attendue |
|---|---|
| Aller à un autre nœud | identifiant du nœud |
| Commande (joueur / console) | la commande sans le `/` |
| Ouvrir la boutique | vide |
| Téléporter | `x y z [monde]` |
| Donner des items | `diamond:0:3;emerald` |
| Message | le texte à envoyer |
| Fermer | vide |

Un choix peut exiger une permission : il disparaît simplement pour les joueurs qui ne l'ont pas. Le bouton « Tester le dialogue » joue l'arbre sur soi sans toucher au PNJ.

L'arbre remplace les dialogues simples quand il est activé.

## Patrouille

`/npc waypoint` ou le menu « Patrouille ». Les points s'ajoutent à la position du staff, chacun avec son propre temps d'attente. Le PNJ rejoint les points dans l'ordre puis boucle.

```
/npc waypoint add
/npc waypoint list
/npc waypoint remove <n>
/npc waypoint start | stop | clear
```

Il faut au moins deux points. La vitesse se règle dans les paramètres de patrouille (blocs par tick).

## Visibilité

`/npc visibility` : le PNJ peut être visible par tout le monde, uniquement par les joueurs qui ont une permission, ou uniquement par ceux qui ne l'ont pas. Le second cas sert aux quêtes (masquer un donneur de quête déjà terminée). Une tâche vérifie l'état toutes les 2 secondes et fait apparaître ou disparaître l'entité par joueur.

## Historique

Toutes les créations, suppressions et modifications de patrouille sont enregistrées avec l'auteur et la date.

```
/npc logs [nombre]      historique du PNJ sélectionné, ou global sans sélection
/npc logs clear         vider l'historique
```

Configurable dans `config.yml` (`logs.enabled`, `logs.retention-days`). Les entrées plus anciennes que la rétention sont purgées au démarrage.

## Messages

Tous les messages courants sont dans `messages.yml`, rechargeable à chaud avec `/npc reload`. Une clé absente ou mal écrite retombe sur la valeur par défaut embarquée dans le plugin, donc un fichier partiel ne casse rien.

## Ce qui n'est pas encore implémenté

Conditions de quête autres que les permissions, et interface de traduction par langue.
