<?php

namespace CustomNPC\manager;

use CustomNPC\Main;

class PlayerDataManager {

    private DatabaseManager $database;
    private array $cache = [];

    public function __construct(Main $plugin, DatabaseManager $database) {
        $this->database = $database;
        $this->database->initPlayerFlagsTable();
    }

    public function setFlag(string $player, string $key, string $value): void {
        $player = strtolower($player);
        $this->database->setPlayerFlag($player, $key, $value);

        $this->cache[$player] ??= [];
        $this->cache[$player][$key] = $value;
    }

    public function getFlag(string $player, string $key): ?string {
        $player = strtolower($player);

        if(isset($this->cache[$player]) && array_key_exists($key, $this->cache[$player])) {
            return $this->cache[$player][$key];
        }

        $value = $this->database->getPlayerFlag($player, $key);

        $this->cache[$player] ??= [];
        $this->cache[$player][$key] = $value;

        return $value;
    }

    public function hasFlag(string $player, string $key): bool {
        $value = $this->getFlag($player, $key);
        return $value !== null && $value !== "" && $value !== "0" && strtolower($value) !== "false";
    }

    public function deleteFlag(string $player, string $key): void {
        $player = strtolower($player);
        $this->database->deletePlayerFlag($player, $key);

        if(isset($this->cache[$player])) {
            unset($this->cache[$player][$key]);
        }
    }

    public function getAllFlags(string $player): array {
        $player = strtolower($player);

        $flags = $this->database->getAllPlayerFlags($player);
        $this->cache[$player] = $flags;
        return $flags;
    }

    public function clearAll(string $player): void {
        $player = strtolower($player);
        $this->database->clearPlayerFlags($player);
        unset($this->cache[$player]);
    }

    public function increment(string $player, string $key, float $amount = 1.0): float {
        $current = (float)($this->getFlag($player, $key) ?? "0");
        $new = $current + $amount;

        $value = floor($new) == $new ? (string)(int)$new : (string)$new;
        $this->setFlag($player, $key, $value);

        return $new;
    }
}
