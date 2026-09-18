<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;
use CustomNPC\utils\ItemParser;

class ArmorGUI {

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

        $labels = [
            "helmet" => "Casque",
            "chestplate" => "Plastron",
            "leggings" => "Jambieres",
            "boots" => "Bottes",
            "hand" => "Main principale",
            "offhand" => "Main secondaire"
        ];

        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) return;

            $armor = $this->npcManager->getNPCData($uuid)["armor"] ?? [];
            $index = 1;

            foreach(Constants::ARMOR_SLOTS as $slot) {
                $value = trim((string)$result[$index++]);

                if($value === "") {
                    $armor[$slot] = "";
                    continue;
                }

                $item = ItemParser::parse($value);
                $armor[$slot] = $item === null ? "" : ItemParser::serialize($item);

                if($item === null) {
                    $player->sendMessage("§cItem invalide ignore : §7" . $value);
                }
            }

            $this->npcManager->updateNPCData($uuid, ["armor" => $armor]);
            $this->npcManager->updateNPC($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aEquipement enregistre.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§6Equipement du NPC");
        $form->addLabel("§7Le virion InvMenu n'est pas disponible, saisie manuelle.\n§8Format: nom_item ou nom_item:meta:quantite");

        foreach(Constants::ARMOR_SLOTS as $slot) {
            $current = ItemParser::deserialize((string)($data["armor"][$slot] ?? ""));
            $default = $current === null ? "" : strtolower(str_replace(" ", "_", $current->getVanillaName()));

            $form->addInput($labels[$slot], "ex: diamond_helmet", $default);
        }

        $player->sendForm($form);
    }
}
