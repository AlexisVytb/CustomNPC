<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use jojoe77777\FormAPI\CustomForm;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class OtherInfoGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) {
            $player->sendMessage("§cAucun NPC sélectionné !");
            return;
        }

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable !");
            return;
        }

        $form = new CustomForm(function(Player $player, $formData) use ($uuid) {
            if($formData === null) return;

            $updates = [
                "size" => max(Constants::MIN_SIZE, min(Constants::MAX_SIZE, (float)$formData[0])),
                "skin" => $formData[1],
                "immobile" => $formData[2],
                "canBeHit" => $formData[3]
            ];
            
            // Drops
            $dropsRaw = $formData[5] ?? "";
            if(!empty(trim($dropsRaw))) {
                $drops = array_filter(array_map('trim', explode(";", $dropsRaw)));
                $updates["drops"] = $drops;
            } else {
                $updates["drops"] = [];
            }

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->updateNPC($player->getWorld(), $uuid);
            $this->npcManager->saveNPC($uuid);
            
            $player->sendMessage("§aAutres infos modifiées !");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§bInfo Autres");
        $form->addInput("Taille du NPC", "0.1-10", (string)($data["size"] ?? 1.0));
        $form->addInput("Nom du fichier skin", "ex: skin.png", $data["skin"] ?? "");
        $form->addToggle("Immobile", $data["immobile"] ?? false);
        $form->addToggle("Peut être frappé", $data["canBeHit"] ?? true);
        $form->addInput("Rotation Yaw (0-360°)", "ex: 90", (string)($data["yaw"] ?? 0.0));
        $form->addInput("Rotation Pitch (-90 à 90°)", "ex: 0", (string)($data["pitch"] ?? 0.0));
        $form->addInput("Items à drop (séparés par ;)", "ex: diamond:0;iron_ingot", implode(";", $data["drops"] ?? []));

        $player->sendForm($form);
    }
}
