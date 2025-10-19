<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use jojoe77777\FormAPI\CustomForm;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class GeneralInfoGUI {

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
                "health" => max(Constants::MIN_HEALTH, min(Constants::MAX_HEALTH, (float)$formData[0])),
                "maxHealth" => max(Constants::MIN_HEALTH, min(Constants::MAX_HEALTH, (float)$formData[1])),
                "speed" => max(Constants::MIN_SPEED, min(Constants::MAX_SPEED, (int)$formData[2])),
                "title" => $formData[3],
                "subtitle" => $formData[4],
                "aggressive" => $formData[5],
                "autoRespawn" => $formData[6],
                "canRegen" => $formData[7]
            ];
            
            if($formData[7]) {
                $updates["regenAmount"] = max(Constants::MIN_REGEN, min(Constants::MAX_REGEN, (int)$formData[8]));
            }

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->updateNPC($player->getWorld(), $uuid);
            $this->npcManager->saveNPC($uuid);
            
            $player->sendMessage("§aInfo générales modifiées !");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§aInfo Général");
        $form->addInput("Vie actuelle", "1-200000", (string)($data["health"] ?? 100));
        $form->addInput("Vie maximum", "1-200000", (string)($data["maxHealth"] ?? 100));
        $form->addInput("Vitesse de déplacement", "1-10", (string)($data["speed"] ?? 1));
        $form->addInput("Titre", "", $data["title"] ?? "NPC");
        $form->addInput("Sous-titre", "", $data["subtitle"] ?? "");
        $form->addToggle("Agressif", $data["aggressive"] ?? false);
        $form->addToggle("Respawn automatique", $data["autoRespawn"] ?? false);
        $form->addToggle("Peut se régénérer", $data["canRegen"] ?? false);
        $form->addInput("Régénération (coeurs/seconde)", "1-100", (string)($data["regenAmount"] ?? 1));

        $player->sendForm($form);
    }
}