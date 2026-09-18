<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use CustomNPC\form\SimpleForm;
use pocketmine\player\Player;
use CustomNPC\manager\DialogueRunner;
use CustomNPC\manager\NPCManager;

class DialogueTreeGUI {

    private NPCManager $npcManager;
    private DialogueRunner $runner;

    public function __construct(NPCManager $npcManager, DialogueRunner $runner) {
        $this->npcManager = $npcManager;
        $this->runner = $runner;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $tree = $this->runner->getTree($uuid);
        $nodes = array_keys($tree["nodes"]);

        $form = new SimpleForm(function(Player $player, $index) use ($uuid) {
            if($index === null) {
                (new MainGUI($this->npcManager))->open($player, $uuid);
                return;
            }

            switch($index) {
                case 0: $this->settings($player, $uuid); break;
                case 1: $this->listNodes($player, $uuid); break;
                case 2: $this->createNode($player, $uuid); break;
                case 3: $this->runner->start($player, $uuid); break;
                case 4: (new MainGUI($this->npcManager))->open($player, $uuid); break;
            }
        });

        $form->setTitle("§5Arbre de dialogue");
        $form->setContent(
            "§7Etat: " . (($tree["enabled"] ?? false) ? "§aactive" : "§cdesactive") . "\n" .
            "§7Noeud de depart: §f" . ($tree["start"] ?? "start") . "\n" .
            "§7Noeuds: §e" . count($nodes)
        );

        $form->addButton("§bParametres");
        $form->addButton("§eNoeuds (" . count($nodes) . ")");
        $form->addButton("§aCreer un noeud");
        $form->addButton("§dTester le dialogue");
        $form->addButton("§cRetour");

        $player->sendForm($form);
    }

    private function settings(Player $player, string $uuid): void {
        $tree = $this->runner->getTree($uuid);
        $nodes = array_keys($tree["nodes"]);

        $startIndex = array_search((string)($tree["start"] ?? "start"), $nodes, true);
        if($startIndex === false) $startIndex = 0;

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $nodes) {
            if($result === null) {
                $this->open($player, $uuid);
                return;
            }

            $tree = $this->runner->getTree($uuid);
            $tree["enabled"] = (bool)$result[0];
            $tree["start"] = $nodes[(int)$result[1]] ?? "start";

            $this->runner->saveTree($uuid, $tree);

            if($tree["enabled"]) {
                $this->npcManager->updateNPCData($uuid, ["dialogueEnabled" => false]);
                $this->npcManager->saveNPC($uuid);
            }

            $player->sendMessage("§aParametres enregistres.");
            $this->open($player, $uuid);
        });

        $form->setTitle("§bParametres du dialogue");
        $form->addToggle("Activer l'arbre de dialogue", (bool)($tree["enabled"] ?? false));
        $form->addDropdown("Noeud de depart", $nodes, (int)$startIndex);

        $player->sendForm($form);
    }

    private function listNodes(Player $player, string $uuid): void {
        $tree = $this->runner->getTree($uuid);
        $nodes = array_keys($tree["nodes"]);

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $nodes) {
            if($index === null || $index === count($nodes)) {
                $this->open($player, $uuid);
                return;
            }

            $this->nodeActions($player, $uuid, $nodes[$index]);
        });

        $form->setTitle("§eNoeuds");
        $form->setContent("§7Total: §e" . count($nodes));

        foreach($nodes as $nodeId) {
            $node = $tree["nodes"][$nodeId];
            $lines = count($node["lines"] ?? []);
            $choices = count($node["choices"] ?? []);

            $form->addButton("§f" . $nodeId . "\n§7" . $lines . " ligne(s), " . $choices . " choix");
        }

        $form->addButton("§cRetour");
        $player->sendForm($form);
    }

    private function createNode(Player $player, string $uuid): void {
        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) {
                $this->open($player, $uuid);
                return;
            }

            $nodeId = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$result[0]));

            if($nodeId === "") {
                $player->sendMessage("§cIdentifiant invalide.");
                return;
            }

            $tree = $this->runner->getTree($uuid);

            if(isset($tree["nodes"][$nodeId])) {
                $player->sendMessage("§cCe noeud existe deja.");
                return;
            }

            $tree["nodes"][$nodeId] = ["lines" => [], "choices" => []];
            $this->runner->saveTree($uuid, $tree);

            $player->sendMessage("§aNoeud cree : §e" . $nodeId);
            $this->editNode($player, $uuid, $nodeId);
        });

        $form->setTitle("§aNouveau noeud");
        $form->addInput("Identifiant", "boutique", "");

        $player->sendForm($form);
    }

    private function nodeActions(Player $player, string $uuid, string $nodeId): void {
        $tree = $this->runner->getTree($uuid);
        $node = $tree["nodes"][$nodeId] ?? null;

        if($node === null) {
            $this->listNodes($player, $uuid);
            return;
        }

        $choices = $node["choices"] ?? [];

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $nodeId) {
            if($index === null || $index === 4) {
                $this->listNodes($player, $uuid);
                return;
            }

            switch($index) {
                case 0: $this->editNode($player, $uuid, $nodeId); break;
                case 1: $this->editChoice($player, $uuid, $nodeId, null); break;
                case 2: $this->listChoices($player, $uuid, $nodeId); break;
                case 3: $this->deleteNode($player, $uuid, $nodeId); break;
            }
        });

        $form->setTitle("§e" . $nodeId);
        $form->setContent(
            "§7Lignes: §e" . count($node["lines"] ?? []) . "\n" .
            "§7Choix: §e" . count($choices) . "\n" .
            "§8Sans choix, le dialogue se termine apres les lignes."
        );

        $form->addButton("§aEditer les lignes");
        $form->addButton("§bAjouter un choix");
        $form->addButton("§eChoix (" . count($choices) . ")");
        $form->addButton("§cSupprimer le noeud");
        $form->addButton("§7Retour");

        $player->sendForm($form);
    }

    private function editNode(Player $player, string $uuid, string $nodeId): void {
        $tree = $this->runner->getTree($uuid);
        $node = $tree["nodes"][$nodeId] ?? ["lines" => [], "choices" => []];

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $nodeId) {
            if($result === null) {
                $this->nodeActions($player, $uuid, $nodeId);
                return;
            }

            $lines = [];
            foreach(preg_split('/\r\n|\r|\n|\|/', (string)$result[1]) as $line) {
                $line = trim($line);
                if($line !== "") $lines[] = $line;
            }

            $tree = $this->runner->getTree($uuid);
            $tree["nodes"][$nodeId]["lines"] = $lines;
            $this->runner->saveTree($uuid, $tree);

            $player->sendMessage("§a" . count($lines) . " ligne(s) enregistree(s).");
            $this->nodeActions($player, $uuid, $nodeId);
        });

        $form->setTitle("§aLignes de §e" . $nodeId);
        $form->addLabel("§7Une ligne par message, separees par un retour a la ligne ou §e|§7.\n§7Placeholder : §8{player}");
        $form->addInput("Lignes", "Bonjour {player} !|Que veux-tu ?", implode("\n", array_filter($node["lines"] ?? [], 'is_string')));

        $player->sendForm($form);
    }

    private function listChoices(Player $player, string $uuid, string $nodeId): void {
        $tree = $this->runner->getTree($uuid);
        $choices = $tree["nodes"][$nodeId]["choices"] ?? [];

        if(empty($choices)) {
            $player->sendMessage("§cAucun choix sur ce noeud.");
            $this->nodeActions($player, $uuid, $nodeId);
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $nodeId, $choices) {
            if($index === null || $index === count($choices)) {
                $this->nodeActions($player, $uuid, $nodeId);
                return;
            }

            $this->choiceActions($player, $uuid, $nodeId, $index);
        });

        $form->setTitle("§eChoix de §f" . $nodeId);

        foreach($choices as $choice) {
            $choice = array_merge(DialogueRunner::getDefaultChoice(), is_array($choice) ? $choice : []);
            $label = DialogueRunner::ACTIONS[$choice["action"]] ?? $choice["action"];

            $form->addButton("§f" . $choice["text"] . "\n§7" . $label . ($choice["value"] !== "" ? " §8-> " . $choice["value"] : ""));
        }

        $form->addButton("§cRetour");
        $player->sendForm($form);
    }

    private function choiceActions(Player $player, string $uuid, string $nodeId, int $choiceIndex): void {
        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $nodeId, $choiceIndex) {
            if($index === null || $index === 2) {
                $this->listChoices($player, $uuid, $nodeId);
                return;
            }

            if($index === 0) {
                $this->editChoice($player, $uuid, $nodeId, $choiceIndex);
                return;
            }

            $tree = $this->runner->getTree($uuid);
            $choices = $tree["nodes"][$nodeId]["choices"] ?? [];

            unset($choices[$choiceIndex]);
            $tree["nodes"][$nodeId]["choices"] = array_values($choices);
            $this->runner->saveTree($uuid, $tree);

            $player->sendMessage("§aChoix supprime.");
            $this->nodeActions($player, $uuid, $nodeId);
        });

        $form->setTitle("§eChoix");
        $form->addButton("§aEditer");
        $form->addButton("§cSupprimer");
        $form->addButton("§7Retour");

        $player->sendForm($form);
    }

    private function editChoice(Player $player, string $uuid, string $nodeId, ?int $choiceIndex): void {
        $tree = $this->runner->getTree($uuid);
        $choices = $tree["nodes"][$nodeId]["choices"] ?? [];

        $choice = $choiceIndex === null
            ? DialogueRunner::getDefaultChoice()
            : array_merge(DialogueRunner::getDefaultChoice(), is_array($choices[$choiceIndex] ?? null) ? $choices[$choiceIndex] : []);

        $actionIds = array_keys(DialogueRunner::ACTIONS);
        $actionIndex = array_search((string)$choice["action"], $actionIds, true);
        if($actionIndex === false) $actionIndex = 0;

        $nodeIds = array_keys($tree["nodes"]);

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $nodeId, $choiceIndex, $actionIds) {
            if($result === null) {
                $this->nodeActions($player, $uuid, $nodeId);
                return;
            }

            $text = trim((string)$result[1]);

            if($text === "") {
                $player->sendMessage("§cLe texte du choix ne peut pas etre vide.");
                return;
            }

            $updated = [
                "text" => $text,
                "action" => $actionIds[(int)$result[2]] ?? DialogueRunner::ACTION_CLOSE,
                "value" => trim((string)$result[3]),
                "permission" => trim((string)$result[4])
            ];

            $tree = $this->runner->getTree($uuid);
            $choices = $tree["nodes"][$nodeId]["choices"] ?? [];

            if($choiceIndex === null) {
                $choices[] = $updated;
            } else {
                $choices[$choiceIndex] = $updated;
            }

            $tree["nodes"][$nodeId]["choices"] = array_values($choices);
            $this->runner->saveTree($uuid, $tree);

            $player->sendMessage("§aChoix enregistre.");
            $this->listChoices($player, $uuid, $nodeId);
        });

        $form->setTitle($choiceIndex === null ? "§aNouveau choix" : "§eEditer le choix");
        $form->addLabel(
            "§7Valeur attendue selon l'action :\n" .
            "§8- Noeud : identifiant du noeud (" . implode(", ", $nodeIds) . ")\n" .
            "§8- Commande : la commande sans le /\n" .
            "§8- Teleporter : x y z [monde]\n" .
            "§8- Donner : diamond:0:3;emerald\n" .
            "§8- Message : le texte a envoyer\n" .
            "§8- Boutique et Fermer : laisser vide"
        );
        $form->addInput("Texte du bouton", "Voir la boutique", (string)$choice["text"]);
        $form->addDropdown("Action", array_values(DialogueRunner::ACTIONS), (int)$actionIndex);
        $form->addInput("Valeur", "", (string)$choice["value"]);
        $form->addInput("Permission requise (optionnel)", "", (string)$choice["permission"]);

        $player->sendForm($form);
    }

    private function deleteNode(Player $player, string $uuid, string $nodeId): void {
        $tree = $this->runner->getTree($uuid);

        if(count($tree["nodes"]) <= 1) {
            $player->sendMessage("§cImpossible de supprimer le dernier noeud.");
            $this->nodeActions($player, $uuid, $nodeId);
            return;
        }

        unset($tree["nodes"][$nodeId]);

        if(($tree["start"] ?? "") === $nodeId) {
            $tree["start"] = array_key_first($tree["nodes"]);
        }

        $this->runner->saveTree($uuid, $tree);

        $player->sendMessage("§aNoeud supprime : §e" . $nodeId);
        $this->listNodes($player, $uuid);
    }
}
