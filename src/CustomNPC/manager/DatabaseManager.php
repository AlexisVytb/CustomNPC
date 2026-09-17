<?php

namespace CustomNPC\manager;

use CustomNPC\Main;

class DatabaseManager {

    private Main $plugin;
    private $database = null;
    private string $type;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadConfig();
        $this->initDatabase();
    }

    private function loadConfig(): void {
        $this->type = strtolower((string)$this->plugin->getConfig()->getNested("database.type", "sqlite"));

        if(!in_array($this->type, ["sqlite", "mysql"], true)) {
            $this->plugin->getLogger()->warning("Type de base de donnees invalide, utilisation de SQLite");
            $this->type = "sqlite";
        }
    }

    private function initDatabase(): void {
        if($this->type === "sqlite") {
            $this->initSQLite();
        } else {
            $this->initMySQL();
        }
    }

    private function initSQLite(): void {
        $file = (string)$this->plugin->getConfig()->getNested("database.sqlite.file", "npcs.db");
        $this->database = new \SQLite3($this->plugin->getDataFolder() . $file);
        $this->database->enableExceptions(false);

        $legacy = $this->sqliteHasColumn("npcs", "title") && !$this->sqliteHasColumn("npcs", "data");

        $legacyRows = [];
        if($legacy) {
            $result = $this->database->query("SELECT * FROM npcs");
            if($result !== false) {
                while($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $legacyRows[] = $row;
                }
            }
            $this->database->exec("ALTER TABLE npcs RENAME TO npcs_legacy_backup");
        }

        $this->database->exec("CREATE TABLE IF NOT EXISTS npcs (
            uuid TEXT PRIMARY KEY,
            custom_id TEXT,
            world TEXT,
            data TEXT
        )");
        $this->database->exec("CREATE INDEX IF NOT EXISTS idx_npcs_world ON npcs(world)");
        $this->database->exec("CREATE INDEX IF NOT EXISTS idx_npcs_custom ON npcs(custom_id)");

        if($legacy) {
            foreach($legacyRows as $row) {
                $data = LegacyConverter::convert($row);
                $this->saveNPC((string)$row["uuid"], $data);
            }
            $this->plugin->getLogger()->info("Migration terminee : " . count($legacyRows) . " NPCs convertis (ancienne table conservee sous npcs_legacy_backup)");
        }

        $this->plugin->getLogger()->info("SQLite initialise");
    }

    private function sqliteHasColumn(string $table, string $column): bool {
        $result = @$this->database->query("PRAGMA table_info(" . $table . ")");
        if($result === false) return false;

        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if(($row["name"] ?? "") === $column) return true;
        }
        return false;
    }

    private function initMySQL(): void {
        $host = (string)$this->plugin->getConfig()->getNested("database.mysql.host", "localhost");
        $port = (int)$this->plugin->getConfig()->getNested("database.mysql.port", 3306);
        $username = (string)$this->plugin->getConfig()->getNested("database.mysql.username", "root");
        $password = (string)$this->plugin->getConfig()->getNested("database.mysql.password", "");
        $database = (string)$this->plugin->getConfig()->getNested("database.mysql.database", "customnpc");

        try {
            $connection = @new \mysqli($host, $username, $password, $database, $port);

            if($connection->connect_error) {
                throw new \RuntimeException($connection->connect_error);
            }

            $this->database = $connection;
            $this->database->set_charset("utf8mb4");

            $legacy = $this->mysqlHasColumn("npcs", "title") && !$this->mysqlHasColumn("npcs", "data");
            $legacyRows = [];

            if($legacy) {
                $result = $this->database->query("SELECT * FROM npcs");
                if($result !== false) {
                    while($row = $result->fetch_assoc()) {
                        $legacyRows[] = $row;
                    }
                }
                $this->database->query("RENAME TABLE npcs TO npcs_legacy_backup");
            }

            $this->database->query("CREATE TABLE IF NOT EXISTS npcs (
                uuid VARCHAR(64) PRIMARY KEY,
                custom_id VARCHAR(64),
                world VARCHAR(255),
                data LONGTEXT,
                INDEX idx_world (world),
                INDEX idx_custom (custom_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            if($legacy) {
                foreach($legacyRows as $row) {
                    $this->saveNPC((string)$row["uuid"], LegacyConverter::convert($row));
                }
                $this->plugin->getLogger()->info("Migration terminee : " . count($legacyRows) . " NPCs convertis");
            }

            $this->plugin->getLogger()->info("MySQL connecte a {$host}:{$port}/{$database}");
        } catch(\Throwable $e) {
            $this->plugin->getLogger()->error("Connexion MySQL impossible : " . $e->getMessage());
            $this->plugin->getLogger()->warning("Basculement vers SQLite");
            $this->type = "sqlite";
            $this->initSQLite();
        }
    }

    private function mysqlHasColumn(string $table, string $column): bool {
        $result = @$this->database->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $this->database->real_escape_string($column) . "'");
        if($result === false) return false;
        return $result->num_rows > 0;
    }

    public function loadAllNPCs(): array {
        $npcs = [];

        if($this->type === "sqlite") {
            $result = $this->database->query("SELECT uuid, data FROM npcs");
            if($result === false) return [];
            while($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $decoded = json_decode((string)$row["data"], true);
                if(is_array($decoded)) {
                    $npcs[(string)$row["uuid"]] = $decoded;
                }
            }
        } else {
            $result = $this->database->query("SELECT uuid, data FROM npcs");
            if($result === false) return [];
            while($row = $result->fetch_assoc()) {
                $decoded = json_decode((string)$row["data"], true);
                if(is_array($decoded)) {
                    $npcs[(string)$row["uuid"]] = $decoded;
                }
            }
        }

        return $npcs;
    }

    public function saveNPC(string $uuid, array $data): void {
        unset($data["runtimeId"]);

        $json = json_encode($data);
        if($json === false) {
            $this->plugin->getLogger()->error("Impossible de serialiser le NPC " . $uuid);
            return;
        }

        $customId = (string)($data["customId"] ?? "");
        $world = (string)($data["position"]["world"] ?? "");

        if($this->type === "sqlite") {
            $stmt = $this->database->prepare("INSERT OR REPLACE INTO npcs (uuid, custom_id, world, data) VALUES (:uuid, :custom_id, :world, :data)");
            if($stmt === false) return;
            $stmt->bindValue(":uuid", $uuid, SQLITE3_TEXT);
            $stmt->bindValue(":custom_id", $customId, SQLITE3_TEXT);
            $stmt->bindValue(":world", $world, SQLITE3_TEXT);
            $stmt->bindValue(":data", $json, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->database->prepare("INSERT INTO npcs (uuid, custom_id, world, data) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE custom_id=VALUES(custom_id), world=VALUES(world), data=VALUES(data)");
            if($stmt === false) return;
            $stmt->bind_param("ssss", $uuid, $customId, $world, $json);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function saveBatch(array $npcs): void {
        if(empty($npcs)) return;

        $this->beginTransaction();
        foreach($npcs as $uuid => $data) {
            $this->saveNPC((string)$uuid, $data);
        }
        $this->commitTransaction();
    }

    private function beginTransaction(): void {
        if($this->type === "sqlite") {
            $this->database->exec("BEGIN TRANSACTION");
        } else {
            $this->database->begin_transaction();
        }
    }

    private function commitTransaction(): void {
        if($this->type === "sqlite") {
            $this->database->exec("COMMIT");
        } else {
            $this->database->commit();
        }
    }

    public function deleteNPC(string $uuid): void {
        if($this->type === "sqlite") {
            $stmt = $this->database->prepare("DELETE FROM npcs WHERE uuid = :uuid");
            if($stmt === false) return;
            $stmt->bindValue(":uuid", $uuid, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->database->prepare("DELETE FROM npcs WHERE uuid = ?");
            if($stmt === false) return;
            $stmt->bind_param("s", $uuid);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function initLogTable(): void {
        if($this->type === "sqlite") {
            $this->database->exec("CREATE TABLE IF NOT EXISTS npc_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                npc_uuid TEXT,
                actor TEXT,
                action TEXT,
                details TEXT,
                time INTEGER
            )");
            $this->database->exec("CREATE INDEX IF NOT EXISTS idx_logs_uuid ON npc_logs(npc_uuid)");
            $this->database->exec("CREATE INDEX IF NOT EXISTS idx_logs_time ON npc_logs(time)");
        } else {
            $this->database->query("CREATE TABLE IF NOT EXISTS npc_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                npc_uuid VARCHAR(64),
                actor VARCHAR(64),
                action VARCHAR(64),
                details TEXT,
                time BIGINT,
                INDEX idx_logs_uuid (npc_uuid),
                INDEX idx_logs_time (time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function addLog(string $uuid, string $actor, string $action, string $details, int $time): void {
        if($this->type === "sqlite") {
            $stmt = $this->database->prepare("INSERT INTO npc_logs (npc_uuid, actor, action, details, time) VALUES (:uuid, :actor, :action, :details, :time)");
            if($stmt === false) return;
            $stmt->bindValue(":uuid", $uuid, SQLITE3_TEXT);
            $stmt->bindValue(":actor", $actor, SQLITE3_TEXT);
            $stmt->bindValue(":action", $action, SQLITE3_TEXT);
            $stmt->bindValue(":details", $details, SQLITE3_TEXT);
            $stmt->bindValue(":time", $time, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->database->prepare("INSERT INTO npc_logs (npc_uuid, actor, action, details, time) VALUES (?, ?, ?, ?, ?)");
            if($stmt === false) return;
            $stmt->bind_param("ssssi", $uuid, $actor, $action, $details, $time);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function getLogs(?string $uuid, int $limit): array {
        $logs = [];

        if($this->type === "sqlite") {
            if($uuid === null) {
                $stmt = $this->database->prepare("SELECT npc_uuid, actor, action, details, time FROM npc_logs ORDER BY time DESC LIMIT :limit");
            } else {
                $stmt = $this->database->prepare("SELECT npc_uuid, actor, action, details, time FROM npc_logs WHERE npc_uuid = :uuid ORDER BY time DESC LIMIT :limit");
            }

            if($stmt === false) return [];

            if($uuid !== null) {
                $stmt->bindValue(":uuid", $uuid, SQLITE3_TEXT);
            }
            $stmt->bindValue(":limit", $limit, SQLITE3_INTEGER);

            $result = $stmt->execute();
            if($result !== false) {
                while($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $logs[] = $row;
                }
            }

            $stmt->close();
        } else {
            if($uuid === null) {
                $stmt = $this->database->prepare("SELECT npc_uuid, actor, action, details, time FROM npc_logs ORDER BY time DESC LIMIT ?");
                if($stmt === false) return [];
                $stmt->bind_param("i", $limit);
            } else {
                $stmt = $this->database->prepare("SELECT npc_uuid, actor, action, details, time FROM npc_logs WHERE npc_uuid = ? ORDER BY time DESC LIMIT ?");
                if($stmt === false) return [];
                $stmt->bind_param("si", $uuid, $limit);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            if($result !== false) {
                while($row = $result->fetch_assoc()) {
                    $logs[] = $row;
                }
            }

            $stmt->close();
        }

        return $logs;
    }

    public function pruneLogs(int $before): void {
        if($this->type === "sqlite") {
            $stmt = $this->database->prepare("DELETE FROM npc_logs WHERE time < :before");
            if($stmt === false) return;
            $stmt->bindValue(":before", $before, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->database->prepare("DELETE FROM npc_logs WHERE time < ?");
            if($stmt === false) return;
            $stmt->bind_param("i", $before);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function clearLogs(?string $uuid): void {
        if($uuid === null) {
            if($this->type === "sqlite") {
                $this->database->exec("DELETE FROM npc_logs");
            } else {
                $this->database->query("DELETE FROM npc_logs");
            }
            return;
        }

        if($this->type === "sqlite") {
            $stmt = $this->database->prepare("DELETE FROM npc_logs WHERE npc_uuid = :uuid");
            if($stmt === false) return;
            $stmt->bindValue(":uuid", $uuid, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->database->prepare("DELETE FROM npc_logs WHERE npc_uuid = ?");
            if($stmt === false) return;
            $stmt->bind_param("s", $uuid);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function close(): void {
        if($this->database !== null) {
            $this->database->close();
            $this->database = null;
        }
    }

    public function getDatabaseType(): string {
        return $this->type;
    }
}
