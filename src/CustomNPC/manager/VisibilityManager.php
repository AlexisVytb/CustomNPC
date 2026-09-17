<?php

namespace CustomNPC\manager;

use pocketmine\player\Player;

class VisibilityManager {

    public const MODE_ALL = "all";
    public const MODE_WITH_PERMISSION = "with";
    public const MODE_WITHOUT_PERMISSION = "without";

    public const MODES = [
        self::MODE_ALL => "Tous les joueurs",
        self::MODE_WITH_PERMISSION => "Avec la permission",
        self::MODE_WITHOUT_PERMISSION => "Sans la permission"
    ];

    public static function getDefault(): array {
        return [
            "mode" => self::MODE_ALL,
            "permission" => ""
        ];
    }

    public static function isRestricted(array $data): bool {
        $visibility = $data["visibility"] ?? null;
        if(!is_array($visibility)) return false;

        $mode = (string)($visibility["mode"] ?? self::MODE_ALL);
        $permission = trim((string)($visibility["permission"] ?? ""));

        return $mode !== self::MODE_ALL && $permission !== "";
    }

    public static function canSee(Player $player, array $data): bool {
        if(!self::isRestricted($data)) return true;

        $visibility = $data["visibility"];
        $mode = (string)$visibility["mode"];
        $permission = trim((string)$visibility["permission"]);

        $has = $player->hasPermission($permission);

        return $mode === self::MODE_WITH_PERMISSION ? $has : !$has;
    }
}
