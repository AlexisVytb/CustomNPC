<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use jojoe77777\FormAPI\CustomForm;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class FakePlayerGUI {

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

        // Pre-fill existing data if it looks like a fake player setup
        // Name is title
        // Faction is extracted from subtitle if possible
        $name = $data["title"] ?? "Hub";
        $faction = "";
        
        // Try to parse faction from subtitle if it matches "Faction\n20 / 20" format or similar
        $currentSubtitle = $data["subtitle"] ?? "";
        $lines = explode("\n", $currentSubtitle);
        if(count($lines) > 0) {
            $faction = $lines[0];
        }

        $form = new CustomForm(function(Player $player, $formData) use ($uuid) {
            if($formData === null) return;

            $name = $formData[0];
            $faction = $formData[1];
            
            // Format:
            // Name (Title)
            // Faction (Subtitle line 1)
            // 20 PV (Subtitle line 2)
            
            $subtitle = $faction . "\n§a20 PV"; // Hardcoded 20 HP display as requested

            $updates = [
                "title" => $name,
                "subtitle" => $subtitle,
                "health" => 20.0,
                "maxHealth" => 20.0,
                "canBeHit" => false, // Usually fake players are static
                "immobile" => true   // Usually fake players don't move
            ];

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->updateNPC($player->getWorld(), $uuid);
            $this->npcManager->saveNPC($uuid);
            
            $player->sendMessage("§aNPC configuré en Fake Player !");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§aFake Player Config");
        $form->addLabel("§7Configure l'apparence type 'Fake Player'");
        $form->addInput("Nom (Titre)", "Ex: PvP", $name);
        $form->addInput("Faction (Sous-titre)", "Ex: 10 joueurs", $faction);
        $form->addLabel("§eNote: 20 PV seront affichés automatiquement en dessous.");

        $player->sendForm($form);
    }
}