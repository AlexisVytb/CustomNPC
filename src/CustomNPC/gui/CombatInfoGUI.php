<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use jojoe77777\FormAPI\CustomForm;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class CombatInfoGUI {

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

        if(!($data["aggressive"] ?? false)) {
            $player->sendMessage("§cLe NPC doit être agressif pour modifier les infos de combat !");
            (new MainGUI($this->npcManager))->open($player, $uuid);
            return;
        }

        $form = new CustomForm(function(Player $player, $formData) use ($uuid) {
            if($formData === null) return;

            $updates = [
                "attackSpeed" => max(Constants::MIN_ATTACK_SPEED, min(Constants::MAX_ATTACK_SPEED, (int)$formData[0])),
                "attackDamage" => max(Constants::MIN_ATTACK_DAMAGE, min(Constants::MAX_ATTACK_DAMAGE, (int)$formData[1])),
                "arrowAttack" => $formData[2],
                "arrowSpeed" => max(Constants::MIN_SPEED, min(Constants::MAX_SPEED, (int)$formData[3])),
                "effectOnHit" => $formData[4]
            ];

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->saveNPC($uuid);
            
            $player->sendMessage("§aInfo de combat modifiées !");
            $player->sendMessage("§eUtilise §b/npcarmor " . $uuid . " §epour configurer l'armure !");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§cInfo Combat");
        $form->addInput("Vitesse d'attaque", "1-3", (string)($data["attackSpeed"] ?? 1));
        $form->addInput("Dégâts", "1-999", (string)($data["attackDamage"] ?? 1));
        $form->addToggle("Attaque par flèche", $data["arrowAttack"] ?? false);
        $form->addInput("Vitesse des flèches", "1-10", (string)($data["arrowSpeed"] ?? 1));
        $form->addInput("Effet si touché (ex: poison:100:1)", "", $data["effectOnHit"] ?? "");

        $player->sendForm($form);
    }
}