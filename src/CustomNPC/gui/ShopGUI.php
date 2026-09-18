<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use CustomNPC\form\SimpleForm;
use CustomNPC\Main;
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

    public function open(Player $player, string $uuid, bool $force = false): void {
        $this->shopManager->refreshStock($uuid);

        $shop = $this->shopManager->getShop($uuid);
        $trades = $this->shopManager->normalizeTrades($shop["trades"]);

        $admin = $this->npcManager->isAdmin($player->getName()) || $player->hasPermission("customnpc.shop");

        if(!($shop["enabled"] ?? false)) {
            if($force && $admin && !empty($trades)) {
                $shop["enabled"] = true;
                $this->shopManager->saveShop($uuid, $shop);
                $player->sendMessage("§eLa boutique etait fermee, elle vient d'etre ouverte automatiquement.");
            } else {
                $player->sendMessage(Messages::get($admin ? "shop.admin-closed" : "shop.closed"));
                return;
            }
        }

        $visible = [];

        foreach($trades as $trade) {
            $permission = trim((string)$trade["permission"]);
            if($permission !== "" && !$player->hasPermission($permission)) continue;

            $visible[] = $trade;
        }

        if(empty($visible)) {
            $player->sendMessage(Messages::get($admin && empty($trades) ? "shop.admin-empty" : "shop.empty"));
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $visible) {
            if($index === null || !isset($visible[$index])) return;

            $this->later($player, function(Player $player) use ($uuid, $visible, $index): void {
                $this->confirm($player, $uuid, $visible[$index]);
            });
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

            $this->later($player, function(Player $player) use ($uuid): void {
                $this->open($player, $uuid);
            });
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

    private function later(Player $player, \Closure $action): void {
        Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $action): void {
            if($player->isConnected()) {
                $action($player);
            }
        }), 5);
    }
}
