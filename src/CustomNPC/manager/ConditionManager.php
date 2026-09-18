<?php

namespace CustomNPC\manager;

use pocketmine\player\Player;
use CustomNPC\utils\ItemParser;

class ConditionManager {

    public const TYPE_PERMISSION = "permission";
    public const TYPE_NOT_PERMISSION = "not_permission";
    public const TYPE_ITEM = "item";
    public const TYPE_NOT_ITEM = "not_item";
    public const TYPE_FLAG = "flag";
    public const TYPE_NOT_FLAG = "not_flag";
    public const TYPE_FLAG_AT_LEAST = "flag_at_least";
    public const TYPE_WORLD = "world";
    public const TYPE_GAMEMODE = "gamemode";
    public const TYPE_LEVEL = "level";
    public const TYPE_COOLDOWN = "cooldown";
    public const TYPE_ONCE = "once";

    public const TYPES = [
        self::TYPE_PERMISSION => "Permission requise",
        self::TYPE_NOT_PERMISSION => "Permission interdite",
        self::TYPE_ITEM => "Possede un item",
        self::TYPE_NOT_ITEM => "Ne possede pas un item",
        self::TYPE_FLAG => "Variable = valeur",
        self::TYPE_NOT_FLAG => "Variable != valeur",
        self::TYPE_FLAG_AT_LEAST => "Variable >= nombre",
        self::TYPE_WORLD => "Dans le monde",
        self::TYPE_GAMEMODE => "Mode de jeu",
        self::TYPE_LEVEL => "Niveau d'XP minimum",
        self::TYPE_COOLDOWN => "Cooldown (secondes)",
        self::TYPE_ONCE => "Une seule fois"
    ];

    private PlayerDataManager $playerData;

    public function __construct(PlayerDataManager $playerData) {
        $this->playerData = $playerData;
    }

    public static function getDefaultCondition(): array {
        return [
            "type" => self::TYPE_PERMISSION,
            "value" => "",
            "key" => ""
        ];
    }

    public function evaluateAll(Player $player, array $conditions, string $trackingId): bool {
        foreach($conditions as $condition) {
            if(!is_array($condition)) continue;
            if(!$this->evaluate($player, array_merge(self::getDefaultCondition(), $condition), $trackingId)) {
                return false;
            }
        }

        return true;
    }

    public function findFailure(Player $player, array $conditions, string $trackingId): ?array {
        foreach($conditions as $condition) {
            if(!is_array($condition)) continue;
            $condition = array_merge(self::getDefaultCondition(), $condition);

            if(!$this->evaluate($player, $condition, $trackingId)) {
                return $condition;
            }
        }

        return null;
    }

    public function evaluate(Player $player, array $condition, string $trackingId): bool {
        $type = (string)($condition["type"] ?? self::TYPE_PERMISSION);
        $value = trim((string)($condition["value"] ?? ""));

        return match($type) {
            self::TYPE_PERMISSION => $value === "" || $player->hasPermission($value),
            self::TYPE_NOT_PERMISSION => $value === "" || !$player->hasPermission($value),
            self::TYPE_ITEM => $this->hasItems($player, $value),
            self::TYPE_NOT_ITEM => !$this->hasItems($player, $value),
            self::TYPE_FLAG => $this->flagEquals($player, $condition, $value),
            self::TYPE_NOT_FLAG => !$this->flagEquals($player, $condition, $value),
            self::TYPE_FLAG_AT_LEAST => $this->flagAtLeast($player, $condition, $value),
            self::TYPE_WORLD => $value === "" || strcasecmp($player->getWorld()->getFolderName(), $value) === 0,
            self::TYPE_GAMEMODE => $this->matchesGameMode($player, $value),
            self::TYPE_LEVEL => $player->getXpManager()->getXpLevel() >= (int)$value,
            self::TYPE_COOLDOWN => $this->checkCooldown($player, $condition, $trackingId, $value),
            self::TYPE_ONCE => !$this->playerData->hasFlag($player->getName(), $this->trackingKey($condition, $trackingId, "once")),
            default => true
        };
    }

    public function markUsed(Player $player, array $conditions, string $trackingId): void {
        foreach($conditions as $condition) {
            if(!is_array($condition)) continue;
            $condition = array_merge(self::getDefaultCondition(), $condition);
            $type = (string)($condition["type"] ?? "");

            if($type === self::TYPE_COOLDOWN) {
                $this->playerData->setFlag($player->getName(), $this->trackingKey($condition, $trackingId, "cooldown"), (string)time());
            } elseif($type === self::TYPE_ONCE) {
                $this->playerData->setFlag($player->getName(), $this->trackingKey($condition, $trackingId, "once"), "1");
            }
        }
    }

    private function hasItems(Player $player, string $value): bool {
        if($value === "") return true;

        $found = false;

        foreach(explode(";", $value) as $entry) {
            $entry = trim($entry);
            if($entry === "") continue;

            $item = ItemParser::parse($entry);
            if($item === null) continue;

            $found = true;
            if(!$player->getInventory()->contains($item)) return false;
        }

        return $found;
    }

    private function flagEquals(Player $player, array $condition, string $value): bool {
        $key = $this->flagKey($condition);
        if($key === "") return true;

        $current = $this->playerData->getFlag($player->getName(), $key);

        if($value === "") {
            return $this->playerData->hasFlag($player->getName(), $key);
        }

        return $current !== null && $current === $value;
    }

    private function flagAtLeast(Player $player, array $condition, string $value): bool {
        $key = $this->flagKey($condition);
        if($key === "") return true;

        $current = (float)($this->playerData->getFlag($player->getName(), $key) ?? "0");
        return $current >= (float)$value;
    }

    private function flagKey(array $condition): string {
        $key = trim((string)($condition["key"] ?? ""));
        return $key;
    }

    private function matchesGameMode(Player $player, string $value): bool {
        if($value === "") return true;

        $mode = strtolower($value);
        $current = strtolower($player->getGamemode()->getEnglishName());

        $aliases = [
            "creative" => "creative", "creatif" => "creative", "c" => "creative",
            "survival" => "survival", "survie" => "survival", "s" => "survival",
            "adventure" => "adventure", "aventure" => "adventure", "a" => "adventure",
            "spectator" => "spectator", "spectateur" => "spectator", "sp" => "spectator"
        ];

        return ($aliases[$mode] ?? $mode) === $current;
    }

    private function checkCooldown(Player $player, array $condition, string $trackingId, string $value): bool {
        $seconds = (int)$value;
        if($seconds <= 0) return true;

        $key = $this->trackingKey($condition, $trackingId, "cooldown");
        $last = $this->playerData->getFlag($player->getName(), $key);
        if($last === null) return true;

        return (time() - (int)$last) >= $seconds;
    }

    public function cooldownRemaining(Player $player, array $condition, string $trackingId): int {
        $seconds = (int)trim((string)($condition["value"] ?? ""));
        if($seconds <= 0) return 0;

        $key = $this->trackingKey($condition, $trackingId, "cooldown");
        $last = $this->playerData->getFlag($player->getName(), $key);
        if($last === null) return 0;

        $remaining = $seconds - (time() - (int)$last);
        return max(0, $remaining);
    }

    private function trackingKey(array $condition, string $trackingId, string $suffix): string {
        $custom = trim((string)($condition["key"] ?? ""));
        $base = $custom !== "" ? $custom : $trackingId;
        return "npcquest:" . $suffix . ":" . $base;
    }

    public function describe(array $condition): string {
        $condition = array_merge(self::getDefaultCondition(), $condition);
        $type = (string)$condition["type"];
        $label = self::TYPES[$type] ?? $type;
        $value = (string)$condition["value"];
        $key = (string)$condition["key"];

        return match($type) {
            self::TYPE_FLAG, self::TYPE_NOT_FLAG, self::TYPE_FLAG_AT_LEAST =>
                $label . " : " . ($key !== "" ? $key : "?") . ($value !== "" ? " = " . $value : ""),
            self::TYPE_ONCE => $label,
            default => $label . ($value !== "" ? " : " . $value : "")
        };
    }
}
