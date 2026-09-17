<?php

namespace CustomNPC\manager;

use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\Server;
use CustomNPC\utils\ItemParser;
use CustomNPC\utils\Messages;

class ShopManager {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function getShop(string $uuid): array {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return self::getDefaultShop();

        $shop = $data["shop"] ?? [];
        return array_merge(self::getDefaultShop(), is_array($shop) ? $shop : []);
    }

    public static function getDefaultShop(): array {
        return [
            "enabled" => false,
            "title" => "Boutique",
            "trades" => []
        ];
    }

    public static function getDefaultTrade(): array {
        return [
            "id" => "",
            "label" => "",
            "give" => [],
            "cost" => [],
            "costCommand" => "",
            "commandOnBuy" => "",
            "permission" => "",
            "stock" => -1,
            "maxStock" => -1,
            "restock" => 0,
            "lastRestock" => 0
        ];
    }

    public function saveShop(string $uuid, array $shop): void {
        $this->npcManager->updateNPCData($uuid, ["shop" => $shop]);
        $this->npcManager->saveNPC($uuid);
    }

    public function normalizeTrades(array $trades): array {
        $normalized = [];

        foreach($trades as $trade) {
            if(!is_array($trade)) continue;

            $trade = array_merge(self::getDefaultTrade(), $trade);
            if($trade["id"] === "") {
                $trade["id"] = uniqid("trade_");
            }

            $normalized[] = $trade;
        }

        return $normalized;
    }

    public function findTrade(string $uuid, string $tradeId): ?array {
        foreach($this->normalizeTrades($this->getShop($uuid)["trades"]) as $trade) {
            if($trade["id"] === $tradeId) return $trade;
        }

        return null;
    }

    public function refreshStock(string $uuid): void {
        $shop = $this->getShop($uuid);
        $trades = $this->normalizeTrades($shop["trades"]);
        $changed = false;
        $now = time();

        foreach($trades as &$trade) {
            $restock = (int)$trade["restock"];
            $maxStock = (int)$trade["maxStock"];

            if($restock <= 0 || $maxStock < 0) continue;
            if((int)$trade["stock"] >= $maxStock) continue;

            $last = (int)$trade["lastRestock"];
            if($last === 0) {
                $trade["lastRestock"] = $now;
                $changed = true;
                continue;
            }

            $elapsed = intdiv($now - $last, $restock);
            if($elapsed <= 0) continue;

            $trade["stock"] = min($maxStock, (int)$trade["stock"] + $elapsed);
            $trade["lastRestock"] = $last + ($elapsed * $restock);
            $changed = true;
        }
        unset($trade);

        if($changed) {
            $shop["trades"] = $trades;
            $this->saveShop($uuid, $shop);
        }
    }

    public function describeCost(array $trade): string {
        if(trim((string)$trade["costCommand"]) !== "") {
            return (string)($trade["label"] !== "" ? $trade["label"] : "Voir details");
        }

        $parts = [];
        foreach(ItemParser::deserializeList($trade["cost"]) as $item) {
            $parts[] = $item->getCount() . "x " . ($item->hasCustomName() ? $item->getCustomName() : $item->getName());
        }

        return empty($parts) ? "Gratuit" : implode(" + ", $parts);
    }

    public function describeGive(array $trade): string {
        $parts = [];
        foreach(ItemParser::deserializeList($trade["give"]) as $item) {
            $parts[] = $item->getCount() . "x " . ($item->hasCustomName() ? $item->getCustomName() : $item->getName());
        }

        if(empty($parts) && trim((string)$trade["commandOnBuy"]) !== "") {
            return (string)($trade["label"] !== "" ? $trade["label"] : "Recompense");
        }

        return empty($parts) ? "Rien" : implode(" + ", $parts);
    }

    public function canAfford(Player $player, array $trade): bool {
        foreach(ItemParser::deserializeList($trade["cost"]) as $item) {
            if(!$player->getInventory()->contains($item)) return false;
        }

        return true;
    }

    public function purchase(Player $player, string $uuid, string $tradeId): bool {
        $this->refreshStock($uuid);

        $shop = $this->getShop($uuid);

        if(!($shop["enabled"] ?? false)) {
            $player->sendMessage(Messages::get("shop.closed"));
            return false;
        }

        $trades = $this->normalizeTrades($shop["trades"]);
        $index = null;

        foreach($trades as $position => $trade) {
            if($trade["id"] === $tradeId) {
                $index = $position;
                break;
            }
        }

        if($index === null) {
            $player->sendMessage(Messages::get("shop.empty"));
            return false;
        }

        $trade = $trades[$index];

        $permission = trim((string)$trade["permission"]);
        if($permission !== "" && !$player->hasPermission($permission)) {
            $player->sendMessage(Messages::get("shop.permission-required", ["permission" => $permission]));
            return false;
        }

        if((int)$trade["stock"] === 0) {
            $player->sendMessage(Messages::get("shop.out-of-stock"));
            return false;
        }

        $cost = ItemParser::deserializeList($trade["cost"]);
        $give = ItemParser::deserializeList($trade["give"]);

        if(!$this->canAfford($player, $trade)) {
            $player->sendMessage(Messages::get("shop.cannot-afford"));
            return false;
        }

        if(!$this->hasRoom($player, $give, $cost)) {
            $player->sendMessage(Messages::get("shop.inventory-full"));
            return false;
        }

        foreach($cost as $item) {
            $player->getInventory()->removeItem($item);
        }

        foreach($give as $item) {
            $player->getInventory()->addItem($item);
        }

        $costCommand = trim((string)$trade["costCommand"]);
        if($costCommand !== "") {
            $this->dispatch($player, $costCommand);
        }

        $commandOnBuy = trim((string)$trade["commandOnBuy"]);
        if($commandOnBuy !== "") {
            $this->dispatch($player, $commandOnBuy);
        }

        if((int)$trade["stock"] > 0) {
            $trades[$index]["stock"] = (int)$trade["stock"] - 1;

            if((int)$trades[$index]["lastRestock"] === 0) {
                $trades[$index]["lastRestock"] = time();
            }

            $shop["trades"] = $trades;
            $this->saveShop($uuid, $shop);
        }

        $player->sendMessage(Messages::get("shop.purchased", ["item" => $this->describeGive($trade)]));
        return true;
    }

    private function hasRoom(Player $player, array $give, array $cost): bool {
        if(empty($give)) return true;

        $inventory = $player->getInventory();
        $free = 0;

        foreach($inventory->getContents(true) as $item) {
            if($item->isNull()) $free++;
        }

        $free += count($cost);

        foreach($give as $item) {
            if($inventory->canAddItem($item)) continue;
            if($free > 0) {
                $free--;
                continue;
            }
            return false;
        }

        return true;
    }

    private function dispatch(Player $player, string $command): void {
        $command = ltrim(str_replace("{player}", $player->getName(), $command), "/");

        try {
            Server::getInstance()->dispatchCommand(Server::getInstance()->getConsoleSender(), $command);
        } catch(\Throwable $e) {
        }
    }

    public function itemsToText(array $serialized): string {
        $parts = [];

        foreach(ItemParser::deserializeList($serialized) as $item) {
            $parts[] = strtolower(str_replace(" ", "_", $item->getVanillaName())) . ":0:" . $item->getCount();
        }

        return implode(";", $parts);
    }

    public function textToItems(string $text, ?Player $feedback = null): array {
        $items = [];

        foreach(explode(";", $text) as $entry) {
            $entry = trim($entry);
            if($entry === "") continue;

            $item = ItemParser::parse($entry);

            if($item === null) {
                if($feedback !== null) {
                    $feedback->sendMessage(Messages::get("general.invalid-item", ["item" => $entry]));
                }
                continue;
            }

            $items[] = ItemParser::serialize($item);
        }

        return $items;
    }

    public function itemsFromInventory(array $items): array {
        $serialized = [];

        foreach($items as $item) {
            if(!($item instanceof Item) || $item->isNull()) continue;
            $serialized[] = ItemParser::serialize($item);
        }

        return $serialized;
    }
}
