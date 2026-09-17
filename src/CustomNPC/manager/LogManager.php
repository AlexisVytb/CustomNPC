<?php

namespace CustomNPC\manager;

use CustomNPC\Main;

class LogManager {

    private Main $plugin;
    private DatabaseManager $database;
    private bool $enabled;
    private int $retention;

    public function __construct(Main $plugin, DatabaseManager $database) {
        $this->plugin = $plugin;
        $this->database = $database;
        $this->enabled = (bool)$plugin->getConfig()->getNested("logs.enabled", true);
        $this->retention = max(0, (int)$plugin->getConfig()->getNested("logs.retention-days", 30));

        if($this->enabled) {
            $this->database->initLogTable();
            $this->prune();
        }
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    public function log(string $actor, string $action, string $uuid, string $details = ""): void {
        if(!$this->enabled) return;

        $this->database->addLog($uuid, $actor, $action, $details, time());
    }

    public function getLogs(?string $uuid, int $limit = 20): array {
        if(!$this->enabled) return [];

        return $this->database->getLogs($uuid, max(1, min(100, $limit)));
    }

    public function prune(): void {
        if(!$this->enabled || $this->retention <= 0) return;

        $this->database->pruneLogs(time() - ($this->retention * 86400));
    }

    public function clear(?string $uuid): void {
        $this->database->clearLogs($uuid);
    }
}
