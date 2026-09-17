<?php

namespace CustomNPC\utils;

use pocketmine\utils\Config;
use CustomNPC\Main;

class Messages {

    private static ?Config $config = null;
    private static array $fallback = [];

    public static function init(Main $plugin): void {
        $plugin->saveResource("messages.yml");
        self::$config = new Config($plugin->getDataFolder() . "messages.yml", Config::YAML);

        $resource = $plugin->getResource("messages.yml");
        if($resource !== null) {
            $raw = stream_get_contents($resource);
            fclose($resource);

            $parsed = function_exists('yaml_parse') ? @yaml_parse($raw) : null;
            self::$fallback = is_array($parsed) ? $parsed : [];
        }
    }

    public static function reload(Main $plugin): void {
        self::$config = new Config($plugin->getDataFolder() . "messages.yml", Config::YAML);
    }

    public static function get(string $key, array $replacements = [], bool $prefix = false): string {
        $value = self::lookup($key);

        if($value === null) {
            return "§c[" . $key . "]";
        }

        foreach($replacements as $search => $replace) {
            $value = str_replace("{" . $search . "}", (string)$replace, $value);
        }

        return ($prefix ? self::lookup("prefix") ?? "" : "") . $value;
    }

    private static function lookup(string $key): ?string {
        if(self::$config !== null) {
            $value = self::$config->getNested($key);
            if(is_string($value)) return $value;
        }

        $parts = explode(".", $key);
        $node = self::$fallback;

        foreach($parts as $part) {
            if(!is_array($node) || !isset($node[$part])) return null;
            $node = $node[$part];
        }

        return is_string($node) ? $node : null;
    }
}
