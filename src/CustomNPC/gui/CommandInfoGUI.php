<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use CustomNPC\form\SimpleForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;

class CommandInfoGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable.");
            return;
        }

        $commands = $this->normalize($data["commands"] ?? []);
        $count = count($commands);

        $form = new SimpleForm(function(Player $player, $index) use ($uuid) {
            if($index === null) {
                (new MainGUI($this->npcManager))->open($player, $uuid);
                return;
            }

            switch($index) {
                case 0: $this->openAdd($player, $uuid); break;
                case 1: $this->openList($player, $uuid); break;
                case 2: $this->resetUsage($player, $uuid); break;
                case 3: (new MainGUI($this->npcManager))->open($player, $uuid); break;
            }
        });

        $form->setTitle("§3Commandes");
        $form->setContent(
            "§7NPC: §e" . ($data["title"] ?? "NPC") . "\n" .
            "§7Commandes: §b" . $count . "\n" .
            "§7Etat: " . (($data["commandEnabled"] ?? false) ? "§aactivees" : "§cdesactivees") . "\n" .
            "§8Active-les dans les infos generales si besoin."
        );

        $form->addButton("§aAjouter une commande", 0, "textures/ui/color_plus");
        $form->addButton("§eVoir les commandes (" . $count . ")", 0, "textures/ui/book");
        $form->addButton("§6Reinitialiser les usages uniques", 0, "textures/ui/refresh");
        $form->addButton("§cRetour", 0, "textures/ui/cancel");

        $player->sendForm($form);
    }

    private function normalize(array $commands): array {
        $normalized = [];

        foreach($commands as $entry) {
            if(is_string($entry)) {
                $normalized[] = [
                    "id" => md5($entry),
                    "command" => $entry,
                    "executor" => "console",
                    "cooldown" => 0,
                    "permission" => null,
                    "oneTime" => false
                ];
            } elseif(is_array($entry) && isset($entry["command"])) {
                $entry["id"] = (string)($entry["id"] ?? uniqid("cmd_"));
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    private function openAdd(Player $player, string $uuid): void {
        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) {
                $this->open($player, $uuid);
                return;
            }

            $command = ltrim(trim((string)$result[1]), "/");

            if($command === "") {
                $player->sendMessage("§cLa commande ne peut pas etre vide.");
                $this->openAdd($player, $uuid);
                return;
            }

            $permission = trim((string)$result[4]);

            $entry = [
                "id" => uniqid("cmd_"),
                "command" => $command,
                "executor" => ((int)$result[2] === 0) ? "player" : "console",
                "cooldown" => max(0, (int)$result[3]),
                "permission" => $permission === "" ? null : $permission,
                "oneTime" => (bool)$result[5]
            ];

            $data = $this->npcManager->getNPCData($uuid);
            $commands = $this->normalize($data["commands"] ?? []);
            $commands[] = $entry;

            $this->npcManager->updateNPCData($uuid, ["commands" => $commands]);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aCommande ajoutee : §b/" . $command);
            $this->open($player, $uuid);
        });

        $form->setTitle("§aAjouter une commande");
        $form->addLabel("§7Executee quand un joueur interagit avec le NPC.\n§8Placeholders: {player} {x} {y} {z} {world} {xuid} {uuid}");
        $form->addInput("§eCommande §7(sans le /)", "gamemode creative {player}", "");
        $form->addDropdown("§eExecutee par", ["§eJoueur", "§cConsole"], 1);
        $form->addInput("§eCooldown (secondes)", "0", "0");
        $form->addInput("§ePermission requise (optionnel)", "customnpc.use", "");
        $form->addToggle("§eUsage unique par joueur", false);

        $player->sendForm($form);
    }

    private function openList(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $commands = $this->normalize($data["commands"] ?? []);

        if(empty($commands)) {
            $player->sendMessage("§cAucune commande configuree.");
            $this->open($player, $uuid);
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $commands) {
            if($index === null || $index === count($commands)) {
                $this->open($player, $uuid);
                return;
            }

            $this->openEdit($player, $uuid, (string)$commands[$index]["id"]);
        });

        $form->setTitle("§eListe des commandes");
        $form->setContent("§7Total: §b" . count($commands));

        foreach($commands as $entry) {
            $executor = ($entry["executor"] ?? "console") === "player" ? "§eJoueur" : "§cConsole";
            $cooldown = ($entry["cooldown"] ?? 0) > 0 ? " §8| §7CD " . $entry["cooldown"] . "s" : "";
            $once = ($entry["oneTime"] ?? false) ? " §8| §6unique" : "";

            $form->addButton("§b/" . $entry["command"] . "\n" . $executor . $cooldown . $once, 0, "textures/ui/book");
        }

        $form->addButton("§cRetour", 0, "textures/ui/cancel");
        $player->sendForm($form);
    }

    private function openEdit(Player $player, string $uuid, string $commandId): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $commands = $this->normalize($data["commands"] ?? []);
        $entry = null;

        foreach($commands as $candidate) {
            if((string)$candidate["id"] === $commandId) {
                $entry = $candidate;
                break;
            }
        }

        if($entry === null) {
            $this->openList($player, $uuid);
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $commandId) {
            if($index === null || $index === 1) {
                $this->openList($player, $uuid);
                return;
            }

            if($index === 0) {
                $this->delete($player, $uuid, $commandId);
            }
        });

        $content = "§b/" . $entry["command"] . "\n";
        $content .= "§7Executeur: " . (($entry["executor"] ?? "console") === "player" ? "§eJoueur" : "§cConsole") . "\n";

        if(($entry["cooldown"] ?? 0) > 0) {
            $content .= "§7Cooldown: §b" . $entry["cooldown"] . "s\n";
        }
        if(!empty($entry["permission"])) {
            $content .= "§7Permission: §b" . $entry["permission"] . "\n";
        }
        if($entry["oneTime"] ?? false) {
            $content .= "§7Usage: §6une seule fois par joueur\n";
        }

        $form->setTitle("§eEditer la commande");
        $form->setContent($content);
        $form->addButton("§cSupprimer", 0, "textures/ui/trash_default");
        $form->addButton("§eRetour", 0, "textures/ui/cancel");

        $player->sendForm($form);
    }

    private function delete(Player $player, string $uuid, string $commandId): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $commands = [];
        $removed = null;

        foreach($this->normalize($data["commands"] ?? []) as $entry) {
            if((string)$entry["id"] === $commandId) {
                $removed = $entry;
                continue;
            }
            $commands[] = $entry;
        }

        $usedOnce = array_values(array_filter(
            $data["usedOnce"] ?? [],
            fn($key) => !str_ends_with((string)$key, "|" . $commandId)
        ));

        $this->npcManager->updateNPCData($uuid, ["commands" => $commands, "usedOnce" => $usedOnce]);
        $this->npcManager->saveNPC($uuid);

        if($removed !== null) {
            $player->sendMessage("§aCommande supprimee : §7/" . $removed["command"]);
        }

        $this->openList($player, $uuid);
    }

    private function resetUsage(Player $player, string $uuid): void {
        $this->npcManager->updateNPCData($uuid, ["usedOnce" => []]);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aUsages uniques reinitialises pour tous les joueurs.");
        $this->open($player, $uuid);
    }
}
