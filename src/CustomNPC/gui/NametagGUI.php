<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class NametagGUI {

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

        $modes = Constants::NAMETAG_MODES;
        $modeIds = array_keys($modes);
        $index = array_search((string)($data["nametagMode"] ?? Constants::NAMETAG_ALWAYS), $modeIds, true);
        if($index === false) $index = 0;

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $modeIds) {
            if($result === null) return;

            $mode = $modeIds[(int)$result[1]] ?? Constants::NAMETAG_ALWAYS;

            $this->npcManager->updateNPCData($uuid, [
                "nametagMode" => $mode,
                "title" => (string)$result[2],
                "subtitle" => (string)$result[3]
            ]);

            $entity = $this->npcManager->getEntity($uuid);
            if($entity !== null) {
                $entity->applyNametagMode($mode);
            }

            $this->npcManager->updateNameTag($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aNametag enregistre.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§eNametag");
        $form->addLabel(
            "§7Toujours visible : affiche a travers les blocs et a distance.\n" .
            "§7Visible au survol : n'apparait que si le joueur vise le NPC de pres.\n" .
            "§7Cache : aucun nom affiche.\n\n" .
            "§7Placeholders : §8{online} {max} {world_players} {world} {tps}"
        );
        $form->addDropdown("Mode d'affichage", array_values($modes), (int)$index);
        $form->addInput("Titre", "", (string)($data["title"] ?? "NPC"));
        $form->addInput("Sous-titre", "", (string)($data["subtitle"] ?? ""));

        $player->sendForm($form);
    }
}
