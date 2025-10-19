<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use pocketmine\entity\Living;
use CustomNPC\manager\NPCManager;

class NPCRegenTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        $npcData = $this->npcManager->getAllNPCData();

        foreach($npcData as $uuid => $data) {
            if(!($data["canRegen"] ?? false)) continue;

            $world = \CustomNPC\Main::getInstance()->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
            if($world === null) continue;

            $npc = $world->getEntity($data["runtimeId"] ?? 0);
            if($npc === null || !($npc instanceof Living)) continue;

            $currentHealth = $npc->getHealth();
            $maxHealth = $data["maxHealth"] ?? 100;
            $regenAmount = ($data["regenAmount"] ?? 1) * 2;

            if($currentHealth < $maxHealth) {
                $newHealth = min($maxHealth, $currentHealth + $regenAmount);
                $npc->setHealth($newHealth);
                
                if($data["aggressive"] ?? false) {
                    $this->npcManager->updateNameTag($npc, $uuid);
                }
            }
        }
    }
}