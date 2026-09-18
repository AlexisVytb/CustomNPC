<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class CombatInfoGUI {

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

        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) return;

            $updates = [
                "aggressive" => (bool)$result[1],
                "attackSpeed" => max(Constants::MIN_ATTACK_SPEED, min(Constants::MAX_ATTACK_SPEED, (int)$result[2])),
                "attackDamage" => max(Constants::MIN_ATTACK_DAMAGE, min(Constants::MAX_ATTACK_DAMAGE, (int)$result[3])),
                "arrowAttack" => (bool)$result[4],
                "arrowSpeed" => max(Constants::MIN_SPEED, min(Constants::MAX_SPEED, (int)$result[5])),
                "effectOnHit" => trim((string)$result[6])
            ];

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->updateNameTag($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aParametres de combat enregistres.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§cCombat");
        $form->addLabel("§7L'equipement se configure dans le coffre §eEquipement§7 du menu principal.");
        $form->addToggle("Agressif", (bool)($data["aggressive"] ?? false));
        $form->addInput("Vitesse d'attaque", "1-3", (string)($data["attackSpeed"] ?? 1));
        $form->addInput("Degats", "1-999", (string)($data["attackDamage"] ?? 1));
        $form->addToggle("Attaque a l'arc", (bool)($data["arrowAttack"] ?? false));
        $form->addInput("Vitesse des fleches", "1-10", (string)($data["arrowSpeed"] ?? 1));
        $form->addInput("Effet inflige (effet:secondes:niveau)", "ex: poison:5:1", (string)($data["effectOnHit"] ?? ""));

        $player->sendForm($form);
    }
}
