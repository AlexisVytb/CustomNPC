<?php

namespace CustomNPC\gui;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\ShopManager;
use CustomNPC\utils\Messages;

class ShopGUI {

    private NPCManager $npcManager;
    private ShopManager $shopManager;

    public function __construct(NPCManager $npcManager, ShopManager $shopManager) {
        $this->npcManager = $npcManager;
        $this->shopManager = $shopManager;
    }

    public function open(Player $player, string $uuid): void {
        $this->shopManager->refreshStock($uuid);

        $shop = $this->shopManager->getShop($uuid);

        if(!($shop["enabled"] ?? false)) {
            $player->sendMessage(Messages::get("shop.closed"));
            return;
        }

        $trades = $this->shopManager->normalizeTrades($shop["trades"]);
        $visible = [];

        foreach($trades as $trade) {
            $permission = trim((string)$trade["permission"]);
            if($permission !== "" && !$player->hasPermission($permission)) continue;

            $visible[] = $trade;
        }

        if(empty($visible)) {
            $player->sendMessage(Messages::get("shop.empty"));
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $visible) {
            if($index === null || !isset($visible[$index])) return;

            $this->confirm($player, $uuid, $visible[$index]);
        });

        $form->setTitle("§6" . ($shop["title"] ?? "Boutique"));
        $form->setContent("§7Selectionne un echange.");

        foreach($visible as $trade) {
            $stock = (int)$trade["stock"];
            $stockLabel = $stock < 0 ? "" : ($stock === 0 ? "\n§cRupture" : "\n§7Stock: §e" . $stock);

            $form->addButton(
                "§f" . $this->shopManager->describeGive($trade) .
                "\n§7Prix: §e" . $this->shopManager->describeCost($trade) . $stockLabel
            );
        }

        $player->sendForm($form);
    }

    private function confirm(Player $player, string $uuid, array $trade): void {
        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $trade) {
            if($index === null) return;

            if($index === 0) {
                $this->shopManager->purchase($player, $uuid, (string)$trade["id"]);
            }

            $this->open($player, $uuid);
        });

        $stock = (int)$trade["stock"];

        $content = "§7Tu recois : §f" . $this->shopManager->describeGive($trade) . "\n";
        $content .= "§7Tu donnes : §f" . $this->shopManager->describeCost($trade) . "\n";

        if($stock >= 0) {
            $content .= "§7Stock restant : §e" . $stock . "\n";
        }

        $form->setTitle("§6Confirmer l'echange");
        $form->setContent($content);
        $form->addButton("§aConfirmer");
        $form->addButton("§cAnnuler");

        $player->sendForm($form);
    }
}
