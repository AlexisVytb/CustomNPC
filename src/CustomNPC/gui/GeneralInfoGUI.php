<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class GeneralInfoGUI {

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

            $maxHealth = max(Constants::MIN_HEALTH, min(Constants::MAX_HEALTH, (float)$result[2]));
            $health = max(Constants::MIN_HEALTH, min($maxHealth, (float)$result[1]));

            $customId = trim((string)$result[3]);
            if($customId !== "" && $this->npcManager->customIdExists($customId, $uuid)) {
                $player->sendMessage("§cCet ID est deja pris, il n'a pas ete applique.");
                $customId = (string)($this->npcManager->getNPCData($uuid)["customId"] ?? "");
            }

            $updates = [
                "title" => (string)$result[4],
                "subtitle" => (string)$result[5],
                "customId" => $customId,
                "health" => $health,
                "maxHealth" => $maxHealth,
                "speed" => max(Constants::MIN_SPEED, min(Constants::MAX_SPEED, (int)$result[6])),
                "aggressive" => (bool)$result[7],
                "autoRespawn" => (bool)$result[8],
                "canRegen" => (bool)$result[9],
                "regenAmount" => max(Constants::MIN_REGEN, min(Constants::MAX_REGEN, (int)$result[10])),
                "commandEnabled" => (bool)$result[11],
                "canBeHit" => (bool)$result[12],
                "immobile" => (bool)$result[13],
                "lookAtPlayers" => (bool)$result[14]
            ];

            $this->npcManager->updateNPCData($uuid, $updates);
            $this->npcManager->updateNPC($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aInfos generales enregistrees.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§aInfos generales");
        $form->addLabel("§7Placeholders disponibles dans le titre et le sous-titre :\n§8{online} {max} {world_players} {world} {tps}");
        $form->addInput("Vie actuelle", "1-200000", (string)(int)($data["health"] ?? 100));
        $form->addInput("Vie maximum", "1-200000", (string)(int)($data["maxHealth"] ?? 100));
        $form->addInput("ID court (optionnel)", "ex: spawn_hub", (string)($data["customId"] ?? ""));
        $form->addInput("Titre", "", (string)($data["title"] ?? "NPC"));
        $form->addInput("Sous-titre", "", (string)($data["subtitle"] ?? ""));
        $form->addInput("Vitesse de deplacement", "1-10", (string)($data["speed"] ?? 1));
        $form->addToggle("Agressif", (bool)($data["aggressive"] ?? false));
        $form->addToggle("Respawn automatique", (bool)($data["autoRespawn"] ?? false));
        $form->addToggle("Regeneration", (bool)($data["canRegen"] ?? false));
        $form->addInput("Regeneration par seconde", "1-100", (string)($data["regenAmount"] ?? 1));
        $form->addToggle("Activer les commandes", (bool)($data["commandEnabled"] ?? false));
        $form->addToggle("Peut etre frappe", (bool)($data["canBeHit"] ?? true));
        $form->addToggle("Immobile", (bool)($data["immobile"] ?? false));
        $form->addToggle("Suit les joueurs du regard", (bool)($data["lookAtPlayers"] ?? false));

        $player->sendForm($form);
    }
}
