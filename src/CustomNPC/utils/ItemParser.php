<?php

namespace CustomNPC\utils;

use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\LegacyStringToItemParser;

class ItemParser {

    public static function parse(string $itemString): ?Item {
        if(empty($itemString)) return null;
        
        // Essayer le parser moderne
        $item = StringToItemParser::getInstance()->parse($itemString);
        if($item !== null) return $item;
        
        // Essayer le parser legacy (ID:META)
        $parts = explode(":", $itemString);
        if(count($parts) >= 1) {
            $id = $parts[0];
            $meta = isset($parts[1]) ? (int)$parts[1] : 0;
            
            $item = LegacyStringToItemParser::getInstance()->parse($id . ":" . $meta);
            if($item !== null) return $item;
        }
        
        return null;
    }
}