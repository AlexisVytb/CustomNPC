<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use CustomNPC\manager\NPCManager;

class NameTagRefreshTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $title = (string)($data["title"] ?? "");
            $subtitle = (string)($data["subtitle"] ?? "");

            if(!str_contains($title, "{") && !str_contains($subtitle, "{")) continue;
            if($this->npcManager->getEntity($uuid) === null) continue;

            $this->npcManager->updateNameTag($uuid);
        }
    }
}
