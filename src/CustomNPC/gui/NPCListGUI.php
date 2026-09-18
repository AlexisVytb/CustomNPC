<?php

namespace CustomNPC\gui;

use CustomNPC\form\SimpleForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;

class NPCListGUI {

    private const PER_PAGE = 15;

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, int $page = 0, bool $currentWorldOnly = false): void {
        $all = $this->npcManager->getAllNPCData();
        $worldName = $player->getWorld()->getFolderName();

        $entries = [];
        foreach($all as $uuid => $data) {
            if($currentWorldOnly && ($data["position"]["world"] ?? "") !== $worldName) continue;
            $entries[$uuid] = $data;
        }

        $uuids = array_keys($entries);
        $total = count($uuids);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = max(0, min($pages - 1, $page));

        $slice = array_slice($uuids, $page * self::PER_PAGE, self::PER_PAGE);

        $form = new SimpleForm(function(Player $player, $index) use ($slice, $page, $pages, $currentWorldOnly) {
            if($index === null) return;

            $count = count($slice);

            if($index < $count) {
                $this->openActions($player, $slice[$index], $page, $currentWorldOnly);
                return;
            }

            $offset = $index - $count;
            $hasPrevious = $page > 0;
            $hasNext = $page < $pages - 1;

            if($hasPrevious && $offset === 0) {
                $this->open($player, $page - 1, $currentWorldOnly);
                return;
            }

            if($hasNext && $offset === ($hasPrevious ? 1 : 0)) {
                $this->open($player, $page + 1, $currentWorldOnly);
                return;
            }

            $this->open($player, 0, !$currentWorldOnly);
        });

        $form->setTitle("§6NPCs (" . ($page + 1) . "/" . $pages . ")");
        $form->setContent("§7Total: §e" . $total . "\n§7Filtre: " . ($currentWorldOnly ? "§emonde actuel" : "§etous les mondes"));

        foreach($slice as $uuid) {
            $data = $entries[$uuid];
            $identifier = (string)($data["customId"] ?? "");
            $label = $identifier !== "" ? $identifier : substr($uuid, 0, 14);
            $status = $this->npcManager->getEntity($uuid) !== null ? "§aactif" : (($data["stored"] ?? false) ? "§6range" : "§cabsent");

            $form->addButton("§f" . ($data["title"] ?? "NPC") . " §8[" . $label . "]\n§7" . ($data["position"]["world"] ?? "?") . " §8| " . $status);
        }

        if($page > 0) {
            $form->addButton("§ePage precedente");
        }
        if($page < $pages - 1) {
            $form->addButton("§ePage suivante");
        }

        $form->addButton($currentWorldOnly ? "§bVoir tous les mondes" : "§bVoir ce monde uniquement");

        $player->sendForm($form);
    }

    private function openActions(Player $player, string $uuid, int $page, bool $currentWorldOnly): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $page, $currentWorldOnly) {
            if($index === null) {
                $this->open($player, $page, $currentWorldOnly);
                return;
            }

            switch($index) {
                case 0:
                    $this->npcManager->select($player, $uuid);
                    (new MainGUI($this->npcManager))->open($player, $uuid);
                    break;

                case 1:
                    $this->teleportTo($player, $uuid);
                    break;

                case 2:
                    $this->bringHere($player, $uuid);
                    break;

                case 3:
                    $this->npcManager->select($player, $uuid);
                    $player->sendMessage("§aNPC selectionne.");
                    break;

                case 4:
                    $this->open($player, $page, $currentWorldOnly);
                    break;
            }
        });

        $position = $data["position"];

        $form->setTitle("§6" . ($data["title"] ?? "NPC"));
        $form->setContent(
            "§7UUID: §e" . $uuid . "\n" .
            "§7Monde: §f" . ($position["world"] ?? "?") . "\n" .
            "§7Position: §f" . (int)$position["x"] . ", " . (int)$position["y"] . ", " . (int)$position["z"] . "\n" .
            "§7Cree par: §f" . (($data["creator"] ?? "") !== "" ? $data["creator"] : "inconnu")
        );

        $form->addButton("§aEditer");
        $form->addButton("§bMe teleporter au NPC");
        $form->addButton("§dAmener le NPC ici");
        $form->addButton("§eSelectionner");
        $form->addButton("§cRetour");

        $player->sendForm($form);
    }

    private function teleportTo(Player $player, string $uuid): void {
        if(!$player->hasPermission("customnpc.tp")) {
            $player->sendMessage("§cPermission manquante.");
            return;
        }

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $world = $player->getServer()->getWorldManager()->getWorldByName((string)$data["position"]["world"]);
        if($world === null) {
            $player->sendMessage("§cMonde non charge.");
            return;
        }

        $player->teleport(new \pocketmine\world\Position(
            (float)$data["position"]["x"],
            (float)$data["position"]["y"],
            (float)$data["position"]["z"],
            $world
        ));

        $player->sendMessage("§aTeleporte au NPC.");
    }

    private function bringHere(Player $player, string $uuid): void {
        if(!$player->hasPermission("customnpc.move")) {
            $player->sendMessage("§cPermission manquante.");
            return;
        }

        $position = $player->getPosition();
        $location = $player->getLocation();

        $this->npcManager->updateNPCData($uuid, [
            "position" => [
                "x" => $position->x,
                "y" => $position->y,
                "z" => $position->z,
                "world" => $player->getWorld()->getFolderName()
            ],
            "yaw" => $location->yaw,
            "pitch" => $location->pitch,
            "headYaw" => $location->yaw,
            "stored" => false
        ]);

        $this->npcManager->updateNPC($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aNPC deplace sur ta position.");
    }
}
