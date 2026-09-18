<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class AnimationGUI {

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

        $animations = Constants::ANIMATIONS;
        $ids = array_keys($animations);
        $index = array_search((string)($data["animation"] ?? Constants::ANIM_NONE), $ids, true);
        if($index === false) $index = 0;

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $ids) {
            if($result === null) return;

            $animation = $ids[(int)$result[1]] ?? Constants::ANIM_NONE;

            $updates = [
                "animation" => $animation,
                "animationHeight" => max(0.5, min(64.0, (float)$result[2])),
                "animationSpeed" => max(0.02, min(1.0, (float)$result[3])),
                "animationPause" => max(0, min(600, (int)$result[4]))
            ];

            if($animation !== Constants::ANIM_NONE) {
                $updates["immobile"] = true;
            }

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->updateNPC($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aAnimation enregistree.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§dAnimations");
        $form->addLabel(
            "§7Monte et descend : le NPC grimpe de la distance indiquee puis redescend.\n" .
            "§7Va-et-vient : deplacement en avant puis retour, selon son orientation.\n" .
            "§7Flotte : oscillation verticale douce.\n" .
            "§7Tourne : rotation continue sur lui-meme.\n\n" .
            "§7Distance : blocs parcourus. Vitesse : blocs par tick.\n" .
            "§7Pause : ticks d'arret a chaque extremite (20 ticks = 1s).\n" .
            "§8Une animation rend automatiquement le NPC immobile."
        );
        $form->addDropdown("Animation", array_values($animations), (int)$index);
        $form->addInput("Distance (blocs)", "0.5-64", (string)($data["animationHeight"] ?? 10.0));
        $form->addInput("Vitesse (blocs/tick)", "0.02-1", (string)($data["animationSpeed"] ?? 0.15));
        $form->addInput("Pause (ticks)", "0-600", (string)($data["animationPause"] ?? 40));

        $player->sendForm($form);
    }
}
