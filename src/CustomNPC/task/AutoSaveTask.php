<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use CustomNPC\manager\NPCManager;

class AutoSaveTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        $this->npcManager->saveAll();
    }
}