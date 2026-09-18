<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class FakePlayerGUI {

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

        $worlds = [];
        foreach($player->getServer()->getWorldManager()->getWorlds() as $world) {
            $worlds[] = $world->getFolderName();
        }

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $worlds) {
            if($result === null) return;

            $title = trim((string)$result[1]);
            $subtitle = trim((string)$result[2]);

            if((bool)$result[3]) {
                $counter = ((int)$result[4] === 0) ? "{online}" : "{world_players}";
                $subtitle .= ($subtitle === "" ? "" : "\n") . "§a" . $counter . " joueur(s)";
            }

            $updates = [
                "title" => $title === "" ? "Hub" : $title,
                "subtitle" => $subtitle,
                "canBeHit" => false,
                "immobile" => true,
                "aggressive" => false,
                "nametagMode" => Constants::NAMETAG_ALWAYS,
                "lookAtPlayers" => (bool)$result[5]
            ];

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->updateNPC($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aFake player configure.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§aFake Player");
        $form->addLabel("§7Configure un NPC de type hub, non frappable et immobile.\n§7Le compteur de joueurs se met a jour tout seul.");
        $form->addInput("Nom affiche", "Ex: PvP", (string)($data["title"] ?? "Hub"));
        $form->addInput("Ligne secondaire", "Ex: Faction", explode("\n", (string)($data["subtitle"] ?? ""))[0] ?? "");
        $form->addToggle("Afficher un compteur de joueurs", true);
        $form->addDropdown("Portee du compteur", ["Serveur entier", "Monde du NPC"], 0);
        $form->addToggle("Suit les joueurs du regard", (bool)($data["lookAtPlayers"] ?? false));

        $player->sendForm($form);
    }
}
