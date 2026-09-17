<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\VisibilityManager;

class VisibilityTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            if(!VisibilityManager::isRestricted($data)) continue;

            $entity = $this->npcManager->getEntity($uuid);
            if($entity === null) continue;

            $world = $entity->getWorld();

            foreach($world->getPlayers() as $player) {
                $allowed = VisibilityManager::canSee($player, $data);
                $sees = isset($entity->getViewers()[$player->getId()]) || in_array($player, $entity->getViewers(), true);

                if($allowed && !$sees) {
                    $entity->spawnTo($player);
                    $this->npcManager->refreshNameTagFor($player, $uuid);
                } elseif(!$allowed && $sees) {
                    $entity->despawnFrom($player);
                }
            }
        }
    }
}
