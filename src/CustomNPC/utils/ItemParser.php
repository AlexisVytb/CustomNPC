<?php

namespace CustomNPC\utils;

use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;

class ItemParser {

    public static function serialize(Item $item): string {
        if($item->isNull()) return "";
        try {
            $nbt = $item->nbtSerialize();
            return base64_encode((new BigEndianNbtSerializer())->write(new TreeRoot($nbt)));
        } catch(\Throwable $e) {
            return "";
        }
    }

    public static function deserialize(string $data): ?Item {
        if($data === "") return null;

        $raw = base64_decode($data, true);
        if($raw !== false && $raw !== "") {
            try {
                $tag = (new BigEndianNbtSerializer())->read($raw)->mustGetCompoundTag();
                $item = Item::nbtDeserialize($tag);
                if(!$item->isNull()) return $item;
            } catch(\Throwable $e) {
            }
        }

        return self::parse($data);
    }

    public static function parse(string $itemString): ?Item {
        $itemString = trim($itemString);
        if($itemString === "") return null;

        $parts = explode(":", $itemString);
        $name = $parts[0];
        $meta = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;
        $count = isset($parts[2]) && is_numeric($parts[2]) ? max(1, (int)$parts[2]) : 1;

        $item = StringToItemParser::getInstance()->parse($name);

        if($item === null) {
            try {
                $item = LegacyStringToItemParser::getInstance()->parse($name . ":" . $meta);
            } catch(\Throwable $e) {
                $item = null;
            }
        }

        if($item === null) return null;

        $item->setCount($count);
        return $item;
    }

    public static function serializeList(array $items): array {
        $out = [];
        foreach($items as $item) {
            if($item instanceof Item && !$item->isNull()) {
                $out[] = self::serialize($item);
            }
        }
        return $out;
    }

    public static function deserializeList(array $data): array {
        $out = [];
        foreach($data as $entry) {
            if(!is_string($entry)) continue;
            $item = self::deserialize($entry);
            if($item !== null && !$item->isNull()) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public static function describe(?Item $item): string {
        if($item === null || $item->isNull()) return "§8vide";
        $name = $item->hasCustomName() ? $item->getCustomName() : $item->getName();
        return "§f" . $name . ($item->getCount() > 1 ? " §7x" . $item->getCount() : "");
    }

    public static function air(): Item {
        return VanillaItems::AIR();
    }
}
