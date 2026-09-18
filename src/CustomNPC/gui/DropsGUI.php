<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\ItemParser;

class DropsGUI {

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

        $current = [];
        foreach(ItemParser::deserializeList($data["drops"] ?? []) as $item) {
            $current[] = strtolower(str_replace(" ", "_", $item->getVanillaName())) . ":0:" . $item->getCount();
        }

        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) return;

            $drops = [];

            foreach(explode(";", (string)$result[0]) as $entry) {
                $entry = trim($entry);
                if($entry === "") continue;

                $item = ItemParser::parse($entry);
                if($item === null) {
                    $player->sendMessage("§cItem invalide ignore : §7" . $entry);
                    continue;
                }

                $drops[] = ItemParser::serialize($item);
            }

            $this->npcManager->updateNPCData($uuid, ["drops" => $drops]);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§a" . count($drops) . " drop(s) enregistre(s).");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§6Drops du NPC");
        $form->addInput("Items separes par ;", "diamond:0:3;iron_ingot", implode(";", $current));

        $player->sendForm($form);
    }
}
