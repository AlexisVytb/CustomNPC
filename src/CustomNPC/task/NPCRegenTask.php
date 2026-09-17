<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use CustomNPC\manager\NPCManager;

class NPCRegenTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            if(!($data["canRegen"] ?? false)) continue;

            $entity = $this->npcManager->getEntity($uuid);
            if($entity === null || !$entity->isAlive()) continue;

            $maxHealth = $entity->getMaxHealth();
            $current = $entity->getHealth();
            if($current >= $maxHealth) continue;

            $amount = max(1, (int)($data["regenAmount"] ?? 1));
            $entity->setHealth(min($maxHealth, $current + $amount));

            $this->npcManager->updateNPCData($uuid, ["health" => $entity->getHealth()]);

            if($data["aggressive"] ?? false) {
                $this->npcManager->updateNameTag($uuid);
            }
        }
    }
}
