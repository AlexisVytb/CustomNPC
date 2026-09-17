<?php

namespace CustomNPC\gui;

use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class WaypointGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $waypoints = $this->npcManager->getWaypoints($uuid);

        $form = new SimpleForm(function(Player $player, $index) use ($uuid) {
            if($index === null) {
                (new MainGUI($this->npcManager))->open($player, $uuid);
                return;
            }

            switch($index) {
                case 0: $this->addHere($player, $uuid); break;
                case 1: $this->listPoints($player, $uuid); break;
                case 2: $this->settings($player, $uuid); break;
                case 3: $this->toggle($player, $uuid); break;
                case 4: $this->clear($player, $uuid); break;
                case 5: (new MainGUI($this->npcManager))->open($player, $uuid); break;
            }
        });

        $running = (string)($data["animation"] ?? Constants::ANIM_NONE) === Constants::ANIM_WAYPOINTS;

        $form->setTitle("§dPatrouille");
        $form->setContent(
            "§7Points: §e" . count($waypoints) . "\n" .
            "§7Etat: " . ($running ? "§aen cours" : "§carretee") . "\n" .
            "§7Vitesse: §f" . ($data["animationSpeed"] ?? 0.15) . " §8blocs/tick\n" .
            "§8Le NPC rejoint chaque point dans l'ordre puis recommence."
        );

        $form->addButton("§aAjouter un point ici");
        $form->addButton("§ePoints (" . count($waypoints) . ")");
        $form->addButton("§bVitesse et attente par defaut");
        $form->addButton($running ? "§cArreter la patrouille" : "§aDemarrer la patrouille");
        $form->addButton("§4Tout effacer");
        $form->addButton("§7Retour");

        $player->sendForm($form);
    }

    private function addHere(Player $player, string $uuid): void {
        $position = $player->getPosition();

        $waypoints = $this->npcManager->getWaypoints($uuid);
        $waypoints[] = [
            "x" => round($position->x, 2),
            "y" => round($position->y, 2),
            "z" => round($position->z, 2),
            "wait" => 40
        ];

        $this->npcManager->setWaypoints($uuid, $waypoints);
        $player->sendMessage("§aPoint §e#" . count($waypoints) . "§a ajoute.");

        $this->open($player, $uuid);
    }

    private function listPoints(Player $player, string $uuid): void {
        $waypoints = $this->npcManager->getWaypoints($uuid);

        if(empty($waypoints)) {
            $player->sendMessage("§cAucun point enregistre.");
            $this->open($player, $uuid);
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $waypoints) {
            if($index === null || $index === count($waypoints)) {
                $this->open($player, $uuid);
                return;
            }

            $this->pointActions($player, $uuid, $index);
        });

        $form->setTitle("§ePoints de patrouille");

        foreach($waypoints as $index => $point) {
            $form->addButton(
                "§f#" . ($index + 1) . " §7" . (int)$point["x"] . ", " . (int)$point["y"] . ", " . (int)$point["z"] .
                "\n§7Attente: §e" . (int)($point["wait"] ?? 0) . " ticks"
            );
        }

        $form->addButton("§cRetour");
        $player->sendForm($form);
    }

    private function pointActions(Player $player, string $uuid, int $index): void {
        $form = new SimpleForm(function(Player $player, $choice) use ($uuid, $index) {
            if($choice === null || $choice === 3) {
                $this->listPoints($player, $uuid);
                return;
            }

            $waypoints = $this->npcManager->getWaypoints($uuid);
            if(!isset($waypoints[$index])) return;

            switch($choice) {
                case 0:
                    $player->teleport(new \pocketmine\world\Position(
                        (float)$waypoints[$index]["x"],
                        (float)$waypoints[$index]["y"],
                        (float)$waypoints[$index]["z"],
                        $player->getWorld()
                    ));
                    break;

                case 1:
                    $this->editWait($player, $uuid, $index);
                    return;

                case 2:
                    unset($waypoints[$index]);
                    $this->npcManager->setWaypoints($uuid, array_values($waypoints));
                    $player->sendMessage("§aPoint §e#" . ($index + 1) . "§a supprime.");
                    break;
            }

            $this->listPoints($player, $uuid);
        });

        $form->setTitle("§ePoint #" . ($index + 1));
        $form->addButton("§bS'y teleporter");
        $form->addButton("§aModifier l'attente");
        $form->addButton("§cSupprimer");
        $form->addButton("§7Retour");

        $player->sendForm($form);
    }

    private function editWait(Player $player, string $uuid, int $index): void {
        $waypoints = $this->npcManager->getWaypoints($uuid);
        if(!isset($waypoints[$index])) return;

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $index) {
            if($result === null) {
                $this->listPoints($player, $uuid);
                return;
            }

            $waypoints = $this->npcManager->getWaypoints($uuid);
            if(!isset($waypoints[$index])) return;

            $waypoints[$index]["wait"] = max(0, min(1200, (int)$result[0]));
            $this->npcManager->setWaypoints($uuid, $waypoints);

            $player->sendMessage("§aAttente mise a jour.");
            $this->listPoints($player, $uuid);
        });

        $form->setTitle("§aAttente du point #" . ($index + 1));
        $form->addInput("Attente en ticks (20 = 1s)", "40", (string)((int)($waypoints[$index]["wait"] ?? 40)));

        $player->sendForm($form);
    }

    private function settings(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) {
                $this->open($player, $uuid);
                return;
            }

            $this->npcManager->updateNPCData($uuid, [
                "animationSpeed" => max(0.02, min(1.0, (float)$result[0])),
                "animationPause" => max(0, min(1200, (int)$result[1]))
            ]);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aParametres enregistres.");
            $this->open($player, $uuid);
        });

        $form->setTitle("§bParametres de patrouille");
        $form->addInput("Vitesse (blocs/tick)", "0.15", (string)($data["animationSpeed"] ?? 0.15));
        $form->addInput("Attente par defaut (ticks)", "40", (string)($data["animationPause"] ?? 40));

        $player->sendForm($form);
    }

    private function toggle(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $running = (string)($data["animation"] ?? Constants::ANIM_NONE) === Constants::ANIM_WAYPOINTS;

        if(!$running && count($this->npcManager->getWaypoints($uuid)) < 2) {
            $player->sendMessage("§cIl faut au moins deux points pour une patrouille.");
            $this->open($player, $uuid);
            return;
        }

        $this->npcManager->updateNPCData($uuid, [
            "animation" => $running ? Constants::ANIM_NONE : Constants::ANIM_WAYPOINTS,
            "immobile" => !$running
        ]);
        $this->npcManager->updateNPC($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage($running ? "§cPatrouille arretee." : "§aPatrouille demarree.");
        $this->open($player, $uuid);
    }

    private function clear(Player $player, string $uuid): void {
        $this->npcManager->setWaypoints($uuid, []);
        $this->npcManager->updateNPCData($uuid, ["animation" => Constants::ANIM_NONE]);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aTous les points ont ete supprimes.");
        $this->open($player, $uuid);
    }
}
