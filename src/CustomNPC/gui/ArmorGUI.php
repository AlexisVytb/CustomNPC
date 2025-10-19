<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use jojoe77777\FormAPI\CustomForm;
use CustomNPC\manager\NPCManager;

class ArmorGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable !");
            return;
        }

        $form = new CustomForm(function(Player $player, $formData) use ($uuid) {
            if($formData === null) return;

            $armor = [
                "helmet" => $formData[0],
                "chestplate" => $formData[1],
                "leggings" => $formData[2],
                "boots" => $formData[3],
                "hand" => $formData[4]
            ];

            $this->npcManager->updateNPCData($uuid, ["armor" => $armor]);
            $this->npcManager->updateNPC($player->getWorld(), $uuid);
            $this->npcManager->saveNPC($uuid);
            
            $player->sendMessage("§aArmure configurée !");
        });

        $form->setTitle("§cArmure du NPC");
        $form->addInput("Casque (ID:META ou nom)", "ex: 310:0 ou diamond_helmet", $data["armor"]["helmet"] ?? "");
        $form->addInput("Plastron (ID:META ou nom)", "ex: 311:0 ou diamond_chestplate", $data["armor"]["chestplate"] ?? "");
        $form->addInput("Jambières (ID:META ou nom)", "ex: 312:0 ou diamond_leggings", $data["armor"]["leggings"] ?? "");
        $form->addInput("Bottes (ID:META ou nom)", "ex: 313:0 ou diamond_boots", $data["armor"]["boots"] ?? "");
        $form->addInput("Arme en main (ID:META ou nom)", "ex: 276:0 ou diamond_sword", $data["armor"]["hand"] ?? "");

        $player->sendForm($form);
    }
}