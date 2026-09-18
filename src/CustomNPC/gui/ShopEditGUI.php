<?php

namespace CustomNPC\gui;

use CustomNPC\form\CustomForm;
use CustomNPC\form\SimpleForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\ShopManager;

class ShopEditGUI {

    private NPCManager $npcManager;
    private ShopManager $shopManager;

    public function __construct(NPCManager $npcManager, ShopManager $shopManager) {
        $this->npcManager = $npcManager;
        $this->shopManager = $shopManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $shop = $this->shopManager->getShop($uuid);
        $trades = $this->shopManager->normalizeTrades($shop["trades"]);

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $trades) {
            if($index === null) {
                (new MainGUI($this->npcManager))->open($player, $uuid);
                return;
            }

            switch($index) {
                case 0: $this->settings($player, $uuid); break;
                case 1: $this->editTrade($player, $uuid, null); break;
                case 2: $this->listTrades($player, $uuid); break;
                case 3: $this->preview($player, $uuid); break;
                case 4: (new MainGUI($this->npcManager))->open($player, $uuid); break;
            }
        });

        $form->setTitle("§6Boutique");
        $form->setContent(
            "§7Etat: " . (($shop["enabled"] ?? false) ? "§aouverte" : "§cfermee") . "\n" .
            "§7Nom: §f" . ($shop["title"] ?? "Boutique") . "\n" .
            "§7Echanges: §e" . count($trades)
        );

        $form->addButton("§bParametres");
        $form->addButton("§aAjouter un echange");
        $form->addButton("§eEchanges (" . count($trades) . ")");
        $form->addButton("§dApercu joueur");
        $form->addButton("§cRetour");

        $player->sendForm($form);
    }

    private function settings(Player $player, string $uuid): void {
        $shop = $this->shopManager->getShop($uuid);

        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) {
                $this->open($player, $uuid);
                return;
            }

            $shop = $this->shopManager->getShop($uuid);
            $shop["enabled"] = (bool)$result[0];

            $title = trim((string)$result[1]);
            $shop["title"] = $title === "" ? "Boutique" : $title;

            $this->shopManager->saveShop($uuid, $shop);
            $player->sendMessage("§aParametres de boutique enregistres.");

            $this->open($player, $uuid);
        });

        $form->setTitle("§bParametres de boutique");
        $form->addToggle("Boutique ouverte", (bool)($shop["enabled"] ?? false));
        $form->addInput("Nom affiche", "Boutique", (string)($shop["title"] ?? "Boutique"));

        $player->sendForm($form);
    }

    private function listTrades(Player $player, string $uuid): void {
        $trades = $this->shopManager->normalizeTrades($this->shopManager->getShop($uuid)["trades"]);

        if(empty($trades)) {
            $player->sendMessage("§cAucun echange configure.");
            $this->open($player, $uuid);
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $trades) {
            if($index === null || $index === count($trades)) {
                $this->open($player, $uuid);
                return;
            }

            $this->tradeActions($player, $uuid, (string)$trades[$index]["id"]);
        });

        $form->setTitle("§eEchanges");
        $form->setContent("§7Total: §e" . count($trades));

        foreach($trades as $trade) {
            $stock = (int)$trade["stock"];

            $form->addButton(
                "§f" . $this->shopManager->describeGive($trade) .
                "\n§7contre §e" . $this->shopManager->describeCost($trade) .
                ($stock < 0 ? "" : " §8| §7stock " . $stock)
            );
        }

        $form->addButton("§cRetour");
        $player->sendForm($form);
    }

    private function tradeActions(Player $player, string $uuid, string $tradeId): void {
        $trade = $this->shopManager->findTrade($uuid, $tradeId);

        if($trade === null) {
            $this->listTrades($player, $uuid);
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid, $tradeId) {
            if($index === null || $index === 3) {
                $this->listTrades($player, $uuid);
                return;
            }

            switch($index) {
                case 0: $this->editTrade($player, $uuid, $tradeId); break;
                case 1: $this->refill($player, $uuid, $tradeId); break;
                case 2: $this->delete($player, $uuid, $tradeId); break;
            }
        });

        $content = "§7Donne : §f" . $this->shopManager->describeGive($trade) . "\n";
        $content .= "§7Prix : §f" . $this->shopManager->describeCost($trade) . "\n";
        $content .= "§7Stock : §f" . ((int)$trade["stock"] < 0 ? "illimite" : $trade["stock"] . " / " . $trade["maxStock"]) . "\n";

        if((int)$trade["restock"] > 0) {
            $content .= "§7Reappro : §f+1 toutes les " . $trade["restock"] . "s\n";
        }
        if(trim((string)$trade["permission"]) !== "") {
            $content .= "§7Permission : §f" . $trade["permission"] . "\n";
        }

        $form->setTitle("§eEchange");
        $form->setContent($content);
        $form->addButton("§aEditer");
        $form->addButton("§bRemplir le stock");
        $form->addButton("§cSupprimer");
        $form->addButton("§7Retour");

        $player->sendForm($form);
    }

    private function editTrade(Player $player, string $uuid, ?string $tradeId): void {
        $trade = $tradeId === null ? ShopManager::getDefaultTrade() : $this->shopManager->findTrade($uuid, $tradeId);

        if($trade === null) {
            $this->listTrades($player, $uuid);
            return;
        }

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $tradeId, $trade) {
            if($result === null) {
                $this->open($player, $uuid);
                return;
            }

            $updated = $trade;
            $updated["id"] = $tradeId ?? uniqid("trade_");
            $updated["label"] = trim((string)$result[1]);
            $updated["give"] = $this->shopManager->textToItems((string)$result[2], $player);
            $updated["cost"] = $this->shopManager->textToItems((string)$result[3], $player);
            $updated["costCommand"] = trim((string)$result[4]);
            $updated["commandOnBuy"] = trim((string)$result[5]);
            $updated["permission"] = trim((string)$result[6]);

            $maxStock = (int)$result[7];
            $updated["maxStock"] = $maxStock <= 0 ? -1 : $maxStock;
            $updated["stock"] = $maxStock <= 0 ? -1 : min($maxStock, max(0, (int)$result[8]));
            $updated["restock"] = max(0, (int)$result[9]);
            $updated["lastRestock"] = time();

            if(empty($updated["give"]) && $updated["commandOnBuy"] === "") {
                $player->sendMessage("§cUn echange doit donner un item ou executer une commande.");
                return;
            }

            $shop = $this->shopManager->getShop($uuid);
            $trades = $this->shopManager->normalizeTrades($shop["trades"]);

            if($tradeId === null) {
                $trades[] = $updated;
            } else {
                foreach($trades as $index => $existing) {
                    if($existing["id"] === $tradeId) {
                        $trades[$index] = $updated;
                        break;
                    }
                }
            }

            $shop["trades"] = $trades;
            $this->shopManager->saveShop($uuid, $shop);

            $player->sendMessage("§aEchange enregistre.");
            $this->listTrades($player, $uuid);
        });

        $form->setTitle($tradeId === null ? "§aNouvel echange" : "§eEditer l'echange");
        $form->addLabel(
            "§7Items separes par §e;§7 au format §8nom_item:meta:quantite\n" .
            "§7Laisse le stock max a §e0§7 pour un stock illimite.\n" .
            "§7Reappro en secondes, §e0§7 pour aucun."
        );
        $form->addInput("Libelle (optionnel)", "Pack VIP", (string)$trade["label"]);
        $form->addInput("Items donnes", "diamond:0:3;golden_apple", $this->shopManager->itemsToText($trade["give"]));
        $form->addInput("Items demandes", "emerald:0:16", $this->shopManager->itemsToText($trade["cost"]));
        $form->addInput("Commande de paiement (optionnel)", "money take {player} 500", (string)$trade["costCommand"]);
        $form->addInput("Commande a l'achat (optionnel)", "lp user {player} parent add vip", (string)$trade["commandOnBuy"]);
        $form->addInput("Permission requise (optionnel)", "", (string)$trade["permission"]);
        $form->addInput("Stock maximum", "0", (string)((int)$trade["maxStock"] < 0 ? 0 : $trade["maxStock"]));
        $form->addInput("Stock actuel", "0", (string)((int)$trade["stock"] < 0 ? 0 : $trade["stock"]));
        $form->addInput("Reapprovisionnement (secondes)", "0", (string)$trade["restock"]);

        $player->sendForm($form);
    }

    private function refill(Player $player, string $uuid, string $tradeId): void {
        $shop = $this->shopManager->getShop($uuid);
        $trades = $this->shopManager->normalizeTrades($shop["trades"]);

        foreach($trades as $index => $trade) {
            if($trade["id"] !== $tradeId) continue;

            if((int)$trade["maxStock"] < 0) {
                $player->sendMessage("§7Cet echange a un stock illimite.");
                $this->tradeActions($player, $uuid, $tradeId);
                return;
            }

            $trades[$index]["stock"] = (int)$trade["maxStock"];
            $trades[$index]["lastRestock"] = time();
            break;
        }

        $shop["trades"] = $trades;
        $this->shopManager->saveShop($uuid, $shop);

        $player->sendMessage("§aStock rempli.");
        $this->tradeActions($player, $uuid, $tradeId);
    }

    private function delete(Player $player, string $uuid, string $tradeId): void {
        $shop = $this->shopManager->getShop($uuid);
        $trades = [];

        foreach($this->shopManager->normalizeTrades($shop["trades"]) as $trade) {
            if($trade["id"] === $tradeId) continue;
            $trades[] = $trade;
        }

        $shop["trades"] = $trades;
        $this->shopManager->saveShop($uuid, $shop);

        $player->sendMessage("§aEchange supprime.");
        $this->listTrades($player, $uuid);
    }

    private function preview(Player $player, string $uuid): void {
        \CustomNPC\Main::getInstance()->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(function() use ($player, $uuid): void {
            if($player->isConnected()) {
                (new ShopGUI($this->npcManager, $this->shopManager))->open($player, $uuid);
            }
        }), 5);
    }
}
