<?php

namespace CustomNPC\gui;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\VisibilityManager;

class VisibilityGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $visibility = array_merge(VisibilityManager::getDefault(), is_array($data["visibility"] ?? null) ? $data["visibility"] : []);

        $modes = VisibilityManager::MODES;
        $modeIds = array_keys($modes);
        $modeIndex = array_search((string)$visibility["mode"], $modeIds, true);
        if($modeIndex === false) $modeIndex = 0;

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $modeIds) {
            if($result === null) {
                (new MainGUI($this->npcManager))->open($player, $uuid);
                return;
            }

            $this->npcManager->updateNPCData($uuid, [
                "visibility" => [
                    "mode" => $modeIds[(int)$result[1]] ?? VisibilityManager::MODE_ALL,
                    "permission" => trim((string)$result[2])
                ]
            ]);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aVisibilite enregistree.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§9Visibilite");
        $form->addLabel(
            "§7Tous : visible par tout le monde.\n" .
            "§7Avec permission : visible uniquement par les joueurs qui ont la permission.\n" .
            "§7Sans permission : visible uniquement par ceux qui ne l'ont pas.\n" .
            "§8Utile pour masquer un PNJ aux joueurs ayant deja fait une quete."
        );
        $form->addDropdown("Mode", array_values($modes), (int)$modeIndex);
        $form->addInput("Permission", "monserveur.quete.1", (string)$visibility["permission"]);

        $player->sendForm($form);
    }
}
