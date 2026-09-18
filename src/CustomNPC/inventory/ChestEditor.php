<?php

namespace CustomNPC\inventory;

use CustomNPC\libs\muqsit\invmenu\InvMenu;
use CustomNPC\libs\muqsit\invmenu\InvMenuHandler;
use CustomNPC\libs\muqsit\invmenu\transaction\InvMenuTransaction;
use CustomNPC\libs\muqsit\invmenu\transaction\InvMenuTransactionResult;
use CustomNPC\libs\muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\inventory\Inventory;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;
use CustomNPC\utils\ItemParser;

class ChestEditor {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public static function isAvailable(): bool {
        return class_exists(InvMenuHandler::class) && InvMenuHandler::isRegistered();
    }

    public function openArmor(Player $player, string $uuid, ?\Closure $onClose = null): bool {
        if(!self::isAvailable()) return false;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return false;

        $armor = $data["armor"] ?? [];

        $menu = InvMenu::create(InvMenuTypeIds::TYPE_CHEST);
        $menu->setName("§8Equipement: §r" . ($data["title"] ?? "NPC"));

        $inventory = $menu->getInventory();

        foreach(Constants::ARMOR_SLOTS as $index => $slot) {
            $item = ItemParser::deserialize((string)($armor[$slot] ?? ""));
            $inventory->setItem($index, $item ?? VanillaItems::AIR());
        }

        $filler = VanillaItems::AIR();
        for($i = 6; $i < 9; $i++) {
            $inventory->setItem($i, $filler);
        }

        $labels = [
            9 => "§r§7Casque",
            10 => "§r§7Plastron",
            11 => "§r§7Jambieres",
            12 => "§r§7Bottes",
            13 => "§r§7Main principale",
            14 => "§r§7Main secondaire"
        ];

        foreach($labels as $slot => $label) {
            $inventory->setItem($slot, VanillaItems::PAPER()->setCustomName($label . "\n§8ligne du haut, case " . ($slot - 8)));
        }

        $menu->setListener(function(InvMenuTransaction $transaction): InvMenuTransactionResult {
            $slot = $transaction->getAction()->getSlot();
            if($slot >= 6) {
                return $transaction->discard();
            }
            return $transaction->continue();
        });

        $menu->setInventoryCloseListener(function(Player $player, Inventory $inventory) use ($uuid, $onClose): void {
            $armor = [];

            foreach(Constants::ARMOR_SLOTS as $index => $slot) {
                $item = $inventory->getItem($index);
                $armor[$slot] = $item->isNull() ? "" : ItemParser::serialize($item);
            }

            $this->npcManager->updateNPCData($uuid, ["armor" => $armor]);
            $this->npcManager->updateNPC($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aEquipement enregistre.");

            if($onClose !== null) {
                $onClose($player);
            }
        });

        $menu->send($player);
        $player->sendMessage("§7Cases 1 a 6 de la ligne du haut : casque, plastron, jambieres, bottes, main, main secondaire.");
        return true;
    }

    public function openDrops(Player $player, string $uuid, ?\Closure $onClose = null): bool {
        if(!self::isAvailable()) return false;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return false;

        $menu = InvMenu::create(InvMenuTypeIds::TYPE_DOUBLE_CHEST);
        $menu->setName("§8Drops: §r" . ($data["title"] ?? "NPC"));

        $inventory = $menu->getInventory();
        $items = ItemParser::deserializeList($data["drops"] ?? []);

        $slot = 0;
        foreach($items as $item) {
            if($slot >= $inventory->getSize()) break;
            $inventory->setItem($slot++, $item);
        }

        $menu->setInventoryCloseListener(function(Player $player, Inventory $inventory) use ($uuid, $onClose): void {
            $drops = [];

            foreach($inventory->getContents() as $item) {
                if($item->isNull()) continue;
                $drops[] = ItemParser::serialize($item);
            }

            $this->npcManager->updateNPCData($uuid, ["drops" => $drops]);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§a" . count($drops) . " drop(s) enregistre(s).");

            if($onClose !== null) {
                $onClose($player);
            }
        });

        $menu->send($player);
        return true;
    }

    public function openInventoryPreview(Player $player, string $uuid): bool {
        if(!self::isAvailable()) return false;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return false;

        $menu = InvMenu::create(InvMenuTypeIds::TYPE_CHEST);
        $menu->setName("§8Apercu: §r" . ($data["title"] ?? "NPC"));

        $inventory = $menu->getInventory();
        $slot = 0;

        foreach(Constants::ARMOR_SLOTS as $key) {
            $item = ItemParser::deserialize((string)($data["armor"][$key] ?? ""));
            if($item !== null) {
                $inventory->setItem($slot++, $item);
            }
        }

        $menu->setListener(InvMenu::readonly());
        $menu->send($player);
        return true;
    }
}
