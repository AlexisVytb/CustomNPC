<?php

namespace CustomNPC\manager;

use CustomNPC\Main;

class DatabaseManager {

    private Main $plugin;
    private ?object $database = null;
    private string $type;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadConfig();
        $this->initDatabase();
    }

    private function loadConfig(): void {
        $this->plugin->saveDefaultConfig();
        $config = $this->plugin->getConfig();
        
        $this->type = strtolower($config->get("database")["type"] ?? "sqlite");
        
        if(!in_array($this->type, ["sqlite", "mysql"])) {
            $this->plugin->getLogger()->warning("Type de BDD invalide: {$this->type}, utilisation de SQLite");
            $this->type = "sqlite";
        }
        
        $this->plugin->getLogger()->info("Utilisation de la base de données: " . strtoupper($this->type));
    }

    private function initDatabase(): void {
        if($this->type === "sqlite") {
            $this->initSQLite();
        } else {
            $this->initMySQL();
        }
    }

    private function initSQLite(): void {
        $config = $this->plugin->getConfig()->get("database");
        $file = $config["sqlite"]["file"] ?? "npcs.db";
        
        $this->database = new \SQLite3($this->plugin->getDataFolder() . $file);
        
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
            saved_skin TEXT,
            immobile INTEGER,
            auto_respawn INTEGER,
            can_be_hit INTEGER,
            command_enabled INTEGER,
            commands TEXT,
            drops TEXT,
            armor_helmet TEXT,
            armor_chestplate TEXT,
            armor_leggings TEXT,
            armor_boots TEXT,
            armor_hand TEXT
        )");
        
        // Migration: ajouter les colonnes si elles n'existent pas
        try {
            $this->database->exec("ALTER TABLE npcs ADD COLUMN command_enabled INTEGER DEFAULT 0");
        } catch(\Exception $e) {
            // Colonne existe déjà
        }
        
        try {
            $this->database->exec("ALTER TABLE npcs ADD COLUMN saved_skin TEXT");
        } catch(\Exception $e) {
            // Colonne existe déjà
        }
        
        $this->plugin->getLogger()->info("SQLite initialisé avec succès");
    }

    private function initMySQL(): void {
        $config = $this->plugin->getConfig()->get("database")["mysql"];
        
        $host = $config["host"] ?? "localhost";
        $port = $config["port"] ?? 3306;
        $username = $config["username"] ?? "root";
        $password = $config["password"] ?? "";
        $database = $config["database"] ?? "customnpc";
        
        try {
            $this->database = new \mysqli($host, $username, $password, $database, $port);
            
            if($this->database->connect_error) {
                throw new \Exception("Erreur de connexion MySQL: " . $this->database->connect_error);
            }
            
            $this->database->set_charset("utf8mb4");
            
            // Créer la table
            $query = "CREATE TABLE IF NOT EXISTS npcs (
                uuid VARCHAR(255) PRIMARY KEY,
                title TEXT,
                subtitle TEXT,
                pos_x DOUBLE,
                pos_y DOUBLE,
                pos_z DOUBLE,
                world VARCHAR(255),
                yaw FLOAT,
                pitch FLOAT,
                health DOUBLE,
                max_health DOUBLE,
                speed INT,
                aggressive TINYINT(1),
                attack_speed INT,
                attack_damage INT,
                arrow_attack TINYINT(1),
                arrow_speed INT,
                effect_on_hit TEXT,
                can_regen TINYINT(1),
                regen_amount INT,
                size FLOAT,
                skin TEXT,
                saved_skin TEXT,
                immobile TINYINT(1),
                auto_respawn TINYINT(1),
                can_be_hit TINYINT(1),
                command_enabled TINYINT(1) DEFAULT 0,
                commands TEXT,
                drops TEXT,
                armor_helmet VARCHAR(255),
                armor_chestplate VARCHAR(255),
                armor_leggings VARCHAR(255),
                armor_boots VARCHAR(255),
                armor_hand VARCHAR(255)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->database->query($query);
            
            // Migration: ajouter les colonnes si n'existent pas
            $this->database->query("ALTER TABLE npcs ADD COLUMN command_enabled TINYINT(1) DEFAULT 0");
            $this->database->query("ALTER TABLE npcs ADD COLUMN saved_skin TEXT");
            
            $this->plugin->getLogger()->info("MySQL connecté avec succès à {$host}:{$port}/{$database}");
            
        } catch(\Exception $e) {
            $this->plugin->getLogger()->error("Impossible de se connecter à MySQL: " . $e->getMessage());
            $this->plugin->getLogger()->warning("Basculement vers SQLite...");
            $this->type = "sqlite";
            $this->initSQLite();
        }
    }

    public function loadAllNPCs(): array {
        if($this->type === "sqlite") {
            return $this->loadAllNPCsSQLite();
        } else {
            return $this->loadAllNPCsMySQL();
        }
    }

    private function loadAllNPCsSQLite(): array {
        $result = $this->database->query("SELECT * FROM npcs");
        $npcs = [];
        
        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $npcs[$row["uuid"]] = $this->parseNPCData($row);
        }
        
        return $npcs;
    }

    private function loadAllNPCsMySQL(): array {
        $result = $this->database->query("SELECT * FROM npcs");
        $npcs = [];
        
        while($row = $result->fetch_assoc()) {
            $npcs[$row["uuid"]] = $this->parseNPCData($row);
        }
        
        return $npcs;
    }

    private function parseNPCData(array $row): array {
        return [
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
            "savedSkin" => json_decode($row["saved_skin"] ?? "null", true),
            "immobile" => (bool)$row["immobile"],
            "autoRespawn" => (bool)$row["auto_respawn"],
            "canBeHit" => (bool)$row["can_be_hit"],
            "commandEnabled" => (bool)($row["command_enabled"] ?? 0),
            "commands" => json_decode($row["commands"] ?? "[]", true) ?: [],
            "drops" => json_decode($row["drops"] ?? "[]", true) ?: [],
            "armor" => [
                "helmet" => $row["armor_helmet"] ?? "",
                "chestplate" => $row["armor_chestplate"] ?? "",
                "leggings" => $row["armor_leggings"] ?? "",
                "boots" => $row["armor_boots"] ?? "",
                "hand" => $row["armor_hand"] ?? ""
            ],
            "yaw" => (float)$row["yaw"],
            "pitch" => (float)$row["pitch"],
        ];
    }

    public function saveNPC(string $uuid, array $data): void {
        if($this->type === "sqlite") {
            $this->saveNPCSQLite($uuid, $data);
        } else {
            $this->saveNPCMySQL($uuid, $data);
        }
    }

    private function saveNPCSQLite(string $uuid, array $data): void {
        $pos = $data["position"];
        
        $stmt = $this->database->prepare("
            INSERT OR REPLACE INTO npcs (
                uuid, title, subtitle, pos_x, pos_y, pos_z, world, yaw, pitch,
                health, max_health, speed, aggressive, attack_speed, attack_damage,
                arrow_attack, arrow_speed, effect_on_hit, can_regen, regen_amount,
                size, skin, saved_skin, immobile, auto_respawn, can_be_hit, command_enabled,
                commands, drops, armor_helmet, armor_chestplate, armor_leggings,
                armor_boots, armor_hand
            ) VALUES (
                :uuid, :title, :subtitle, :pos_x, :pos_y, :pos_z, :world, :yaw, :pitch,
                :health, :max_health, :speed, :aggressive, :attack_speed, :attack_damage,
                :arrow_attack, :arrow_speed, :effect_on_hit, :can_regen, :regen_amount,
                :size, :skin, :saved_skin, :immobile, :auto_respawn, :can_be_hit, :command_enabled,
                :commands, :drops, :armor_helmet, :armor_chestplate, :armor_leggings,
                :armor_boots, :armor_hand
            )
        ");
        
        $stmt->bindValue(":uuid", $uuid);
        $stmt->bindValue(":title", $data["title"]);
        $stmt->bindValue(":subtitle", $data["subtitle"]);
        $stmt->bindValue(":pos_x", $pos["x"]);
        $stmt->bindValue(":pos_y", $pos["y"]);
        $stmt->bindValue(":pos_z", $pos["z"]);
        $stmt->bindValue(":world", $pos["world"]);
        $stmt->bindValue(":yaw", $data["yaw"] ?? 0.0);
        $stmt->bindValue(":pitch", $data["pitch"] ?? 0.0);
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
        $stmt->bindValue(":saved_skin", isset($data["savedSkin"]) ? json_encode($data["savedSkin"]) : null);
        $stmt->bindValue(":immobile", (int)$data["immobile"]);
        $stmt->bindValue(":auto_respawn", (int)$data["autoRespawn"]);
        $stmt->bindValue(":can_be_hit", (int)$data["canBeHit"]);
        $stmt->bindValue(":command_enabled", (int)($data["commandEnabled"] ?? false));
        $stmt->bindValue(":commands", json_encode($data["commands"] ?? []));
        $stmt->bindValue(":drops", json_encode($data["drops"] ?? []));
        $stmt->bindValue(":armor_helmet", $data["armor"]["helmet"] ?? "");
        $stmt->bindValue(":armor_chestplate", $data["armor"]["chestplate"] ?? "");
        $stmt->bindValue(":armor_leggings", $data["armor"]["leggings"] ?? "");
        $stmt->bindValue(":armor_boots", $data["armor"]["boots"] ?? "");
        $stmt->bindValue(":armor_hand", $data["armor"]["hand"] ?? "");
        
        $stmt->execute();
    }

    private function saveNPCMySQL(string $uuid, array $data): void {
        $pos = $data["position"];
        
        $stmt = $this->database->prepare("
            INSERT INTO npcs (
                uuid, title, subtitle, pos_x, pos_y, pos_z, world, yaw, pitch,
                health, max_health, speed, aggressive, attack_speed, attack_damage,
                arrow_attack, arrow_speed, effect_on_hit, can_regen, regen_amount,
                size, skin, saved_skin, immobile, auto_respawn, can_be_hit, command_enabled,
                commands, drops, armor_helmet, armor_chestplate, armor_leggings,
                armor_boots, armor_hand
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?
            )
            ON DUPLICATE KEY UPDATE
                title=VALUES(title), subtitle=VALUES(subtitle),
                pos_x=VALUES(pos_x), pos_y=VALUES(pos_y), pos_z=VALUES(pos_z),
                world=VALUES(world), yaw=VALUES(yaw), pitch=VALUES(pitch),
                health=VALUES(health), max_health=VALUES(max_health), speed=VALUES(speed),
                aggressive=VALUES(aggressive), attack_speed=VALUES(attack_speed),
                attack_damage=VALUES(attack_damage), arrow_attack=VALUES(arrow_attack),
                arrow_speed=VALUES(arrow_speed), effect_on_hit=VALUES(effect_on_hit),
                can_regen=VALUES(can_regen), regen_amount=VALUES(regen_amount),
                size=VALUES(size), skin=VALUES(skin), saved_skin=VALUES(saved_skin), immobile=VALUES(immobile),
                auto_respawn=VALUES(auto_respawn), can_be_hit=VALUES(can_be_hit),
                command_enabled=VALUES(command_enabled), commands=VALUES(commands),
                drops=VALUES(drops), armor_helmet=VALUES(armor_helmet),
                armor_chestplate=VALUES(armor_chestplate), armor_leggings=VALUES(armor_leggings),
                armor_boots=VALUES(armor_boots), armor_hand=VALUES(armor_hand)
        ");
        
        $savedSkinJson = isset($data["savedSkin"]) ? json_encode($data["savedSkin"]) : null;
        
        $title = $data["title"];
        $subtitle = $data["subtitle"];
        $posX = $pos["x"];
        $posY = $pos["y"];
        $posZ = $pos["z"];
        $worldName = $pos["world"];
        $yaw = $data["yaw"];
        $pitch = $data["pitch"];
        $health = $data["health"];
        $maxHealth = $data["maxHealth"];
        $speed = $data["speed"];
        $aggressive = (int)$data["aggressive"];
        $attackSpeed = $data["attackSpeed"];
        $attackDamage = $data["attackDamage"];
        $arrowAttack = (int)$data["arrowAttack"];
        $arrowSpeed = $data["arrowSpeed"];
        $effectOnHit = $data["effectOnHit"];
        $canRegen = (int)$data["canRegen"];
        $regenAmount = $data["regenAmount"];
        $size = $data["size"];
        $skin = $data["skin"];
        $immobile = (int)$data["immobile"];
        $autoRespawn = (int)$data["autoRespawn"];
        $canBeHit = (int)$data["canBeHit"];
        $commandEnabled = (int)($data["commandEnabled"] ?? false);
        $commands = json_encode($data["commands"] ?? []);
        $drops = json_encode($data["drops"] ?? []);
        $helmet = $data["armor"]["helmet"] ?? "";
        $chestplate = $data["armor"]["chestplate"] ?? "";
        $leggings = $data["armor"]["leggings"] ?? "";
        $boots = $data["armor"]["boots"] ?? "";
        $hand = $data["armor"]["hand"] ?? "";

        $stmt->bind_param(
            "sssdddsddddiiiiiisiiidssiiiisssssss",
            $uuid,
            $title,
            $subtitle,
            $posX,
            $posY,
            $posZ,
            $worldName,
            $yaw,
            $pitch,
            $health,
            $maxHealth,
            $speed,
            $aggressive,
            $attackSpeed,
            $attackDamage,
            $arrowAttack,
            $arrowSpeed,
            $effectOnHit,
            $canRegen,
            $regenAmount,
            $size,
            $skin,
            $savedSkinJson,
            $immobile,
            $autoRespawn,
            $canBeHit,
            $commandEnabled,
            $commands,
            $drops,
            $helmet,
            $chestplate,
            $leggings,
            $boots,
            $hand
        );
        
        $stmt->execute();
        $stmt->close();
    }

    public function deleteNPC(string $uuid): void {
        if($this->type === "sqlite") {
            $stmt = $this->database->prepare("DELETE FROM npcs WHERE uuid = :uuid");
            $stmt->bindValue(":uuid", $uuid);
            $stmt->execute();
        } else {
            $stmt = $this->database->prepare("DELETE FROM npcs WHERE uuid = ?");
            $stmt->bind_param("s", $uuid);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function close(): void {
        if($this->database !== null) {
            if($this->type === "sqlite") {
                $this->database->close();
            } else {
                $this->database->close();
            }
        }
    }

    public function getDatabaseType(): string {
        return $this->type;
    }
}