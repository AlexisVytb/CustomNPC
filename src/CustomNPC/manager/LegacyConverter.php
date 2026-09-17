<?php

namespace CustomNPC\manager;

use CustomNPC\utils\Constants;

class LegacyConverter {

    public static function convert(array $row): array {
        $armor = [];
        foreach(["helmet", "chestplate", "leggings", "boots", "hand"] as $slot) {
            $armor[$slot] = (string)($row["armor_" . $slot] ?? "");
        }
        $armor["offhand"] = "";

        $commands = json_decode((string)($row["commands"] ?? "[]"), true);
        if(!is_array($commands)) $commands = [];

        $normalized = [];
        foreach($commands as $index => $entry) {
            if(is_string($entry)) {
                $normalized[] = [
                    "id" => uniqid("cmd_"),
                    "command" => $entry,
                    "executor" => "console",
                    "cooldown" => 0,
                    "permission" => null,
                    "oneTime" => false
                ];
            } elseif(is_array($entry)) {
                $entry["id"] = $entry["id"] ?? uniqid("cmd_");
                $normalized[] = $entry;
            }
        }

        $drops = json_decode((string)($row["drops"] ?? "[]"), true);
        if(!is_array($drops)) $drops = [];

        $savedSkin = json_decode((string)($row["saved_skin"] ?? "null"), true);

        $yaw = (float)($row["yaw"] ?? 0.0);

        return [
            "customId" => "",
            "title" => (string)($row["title"] ?? "NPC"),
            "subtitle" => (string)($row["subtitle"] ?? ""),
            "position" => [
                "x" => (float)($row["pos_x"] ?? 0.0),
                "y" => (float)($row["pos_y"] ?? 0.0),
                "z" => (float)($row["pos_z"] ?? 0.0),
                "world" => (string)($row["world"] ?? "")
            ],
            "yaw" => $yaw,
            "pitch" => (float)($row["pitch"] ?? 0.0),
            "headYaw" => $yaw,
            "health" => (float)($row["health"] ?? 100.0),
            "maxHealth" => (float)($row["max_health"] ?? 100.0),
            "speed" => (int)($row["speed"] ?? 1),
            "aggressive" => (bool)($row["aggressive"] ?? 0),
            "attackSpeed" => (int)($row["attack_speed"] ?? 1),
            "attackDamage" => (int)($row["attack_damage"] ?? 1),
            "arrowAttack" => (bool)($row["arrow_attack"] ?? 0),
            "arrowSpeed" => (int)($row["arrow_speed"] ?? 1),
            "effectOnHit" => (string)($row["effect_on_hit"] ?? ""),
            "canRegen" => (bool)($row["can_regen"] ?? 0),
            "regenAmount" => (int)($row["regen_amount"] ?? 1),
            "size" => (float)($row["size"] ?? 1.0),
            "skin" => (string)($row["skin"] ?? ""),
            "savedSkin" => is_array($savedSkin) ? $savedSkin : null,
            "race" => (string)($row["race"] ?? Constants::DEFAULT_RACE),
            "pose" => (string)($row["pose"] ?? Constants::DEFAULT_POSE),
            "immobile" => (bool)($row["immobile"] ?? 0),
            "autoRespawn" => (bool)($row["auto_respawn"] ?? 0),
            "canBeHit" => (bool)($row["can_be_hit"] ?? 1),
            "commandEnabled" => (bool)($row["command_enabled"] ?? 0),
            "commands" => $normalized,
            "drops" => $drops,
            "armor" => $armor,
            "creator" => (string)($row["creator"] ?? ""),
            "stored" => false,
            "nametagMode" => Constants::NAMETAG_ALWAYS,
            "lookAtPlayers" => false,
            "animation" => Constants::ANIM_NONE,
            "animationHeight" => 10.0,
            "animationSpeed" => 0.15,
            "animationPause" => 40,
            "dialogueEnabled" => false,
            "dialogue" => [],
            "dialogueDelay" => 20,
            "interactSound" => "",
            "usedOnce" => []
        ];
    }
}
