<?php

namespace CustomNPC\manager;

use CustomNPC\Main;

class DatabaseManager {

    private Main $plugin;
    private \SQLite3 $database;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->initDatabase();
    }

    private function initDatabase(): void {
        $this->database = new \SQLite3($this->plugin->getDataFolder() . "npcs.db");
        
        $this->database->exec("CREATE TABLE IF NOT EXISTS npcs (
            uuid TEXT PRIMARY KEY,
            title TEXT,
            subtitle TEXT,
            pos_x REAL,
            pos_y REAL,
            pos_z REAL,
            world TEXT,
            yaw REAL,
            pitch REAL,
            health REAL,
            max_health REAL,
            speed INTEGER,
            aggressive INTEGER,
            attack_speed INTEGER,
            attack_damage INTEGER,
            arrow_attack INTEGER,
            arrow_speed INTEGER,
            effect_on_hit TEXT,
            can_regen INTEGER,
            regen_amount INTEGER,
            size REAL,
            skin TEXT,
            immobile INTEGER,
            auto_respawn INTEGER,
            can_be_hit INTEGER,
            commands TEXT,
            drops TEXT,
            armor_helmet TEXT,
            armor_chestplate TEXT,
            armor_leggings TEXT,
            armor_boots TEXT,
            armor_hand TEXT
        )");
    }

    public function loadAllNPCs(): array {
        $result = $this->database->query("SELECT * FROM npcs");
        $npcs = [];
        
        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $uuid = $row["uuid"];
            $canBeHit = (bool)$row["can_be_hit"];
            
            $npcs[$uuid] = [
                "title" => $row["title"],
                "subtitle" => $row["subtitle"],
                "position" => [
                    "x" => (float)$row["pos_x"],
                    "y" => (float)$row["pos_y"],
                    "z" => (float)$row["pos_z"],
                    "world" => $row["world"]
                ],
                "runtimeId" => 0,
                "health" => (float)$row["health"],
                "maxHealth" => (float)$row["max_health"],
                "speed" => (int)$row["speed"],
                "aggressive" => (bool)$row["aggressive"],
                "attackSpeed" => (int)$row["attack_speed"],
                "attackDamage" => (int)$row["attack_damage"],
                "arrowAttack" => (bool)$row["arrow_attack"],
                "arrowSpeed" => (int)$row["arrow_speed"],
                "effectOnHit" => $row["effect_on_hit"],
                "canRegen" => (bool)$row["can_regen"],
                "regenAmount" => (int)$row["regen_amount"],
                "size" => (float)$row["size"],
                "entityType" => "human",
                "skin" => $row["skin"],
                "immobile" => (bool)$row["immobile"],
                "autoRespawn" => (bool)$row["auto_respawn"],
                "canBeHit" => $canBeHit,
                "commands" => json_decode($row["commands"], true) ?: [],
                "drops" => json_decode($row["drops"], true) ?: [],
                "armor" => [
                    "helmet" => $row["armor_helmet"],
                    "chestplate" => $row["armor_chestplate"],
                    "leggings" => $row["armor_leggings"],
                    "boots" => $row["armor_boots"],
                    "hand" => $row["armor_hand"]
                ]
            ];
        }
        
        return $npcs;
    }

    public function saveNPC(string $uuid, array $data): void {
        $pos = $data["position"];
        
        $stmt = $this->database->prepare("INSERT OR REPLACE INTO npcs VALUES (
            :uuid, :title, :subtitle, :pos_x, :pos_y, :pos_z, :world,
            :health, :max_health, :speed, :aggressive, :attack_speed, :attack_damage,
            :arrow_attack, :arrow_speed, :effect_on_hit, :can_regen, :regen_amount,
            :size, :skin, :immobile, :auto_respawn, :can_be_hit,
            :commands, :drops, :armor_helmet, :armor_chestplate, :armor_leggings,
            :armor_boots, :armor_hand
        )");
        
        $stmt->bindValue(":uuid", $uuid);
        $stmt->bindValue(":title", $data["title"]);
        $stmt->bindValue(":subtitle", $data["subtitle"]);
        $stmt->bindValue(":pos_x", $pos["x"]);
        $stmt->bindValue(":pos_y", $pos["y"]);
        $stmt->bindValue(":pos_z", $pos["z"]);
        $stmt->bindValue(":world", $pos["world"]);
        $stmt->bindValue(":health", $data["health"]);
        $stmt->bindValue(":max_health", $data["maxHealth"]);
        $stmt->bindValue(":speed", $data["speed"]);
        $stmt->bindValue(":aggressive", (int)$data["aggressive"]);
        $stmt->bindValue(":attack_speed", $data["attackSpeed"]);
        $stmt->bindValue(":attack_damage", $data["attackDamage"]);
        $stmt->bindValue(":arrow_attack", (int)$data["arrowAttack"]);
        $stmt->bindValue(":arrow_speed", $data["arrowSpeed"]);
        $stmt->bindValue(":effect_on_hit", $data["effectOnHit"]);
        $stmt->bindValue(":can_regen", (int)$data["canRegen"]);
        $stmt->bindValue(":regen_amount", $data["regenAmount"]);
        $stmt->bindValue(":size", $data["size"]);
        $stmt->bindValue(":skin", $data["skin"]);
        $stmt->bindValue(":immobile", (int)$data["immobile"]);
        $stmt->bindValue(":auto_respawn", (int)$data["autoRespawn"]);
        $stmt->bindValue(":can_be_hit", (int)$data["canBeHit"]);
        $stmt->bindValue(":commands", json_encode($data["commands"]));
        $stmt->bindValue(":drops", json_encode($data["drops"]));
        $stmt->bindValue(":armor_helmet", $data["armor"]["helmet"]);
        $stmt->bindValue(":armor_chestplate", $data["armor"]["chestplate"]);
        $stmt->bindValue(":armor_leggings", $data["armor"]["leggings"]);
        $stmt->bindValue(":armor_boots", $data["armor"]["boots"]);
        $stmt->bindValue(":armor_hand", $data["armor"]["hand"]);
        
        $stmt->execute();
    }

    public function deleteNPC(string $uuid): void {
        $stmt = $this->database->prepare("DELETE FROM npcs WHERE uuid = :uuid");
        $stmt->bindValue(":uuid", $uuid);
        $stmt->execute();
    }

    public function close(): void {
        $this->database->close();
    }
}