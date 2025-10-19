<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use CustomNPC\manager\NPCManager;

class RespawnTask extends Task {

    private NPCManager $npcManager;
    private string $uuid;

    public function __construct(NPCManager $npcManager, string $uuid) {
        $this->npcManager = $npcManager;
        $this->uuid = $uuid;
    }

    public function onRun(): void {
        $this->npcManager->respawnNPC($this->uuid);
    }
}