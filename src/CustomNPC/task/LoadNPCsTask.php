<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use CustomNPC\Main;
use CustomNPC\manager\NPCManager;

class LoadNPCsTask extends Task {

    private Main $plugin;
    private NPCManager $npcManager;

    public function __construct(Main $plugin, NPCManager $npcManager) {
        $this->plugin = $plugin;
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        $count = 0;
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $npcInfo) {
            $pos = $npcInfo["position"];
            $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($pos["world"]);
            
            if($world === null) {
                $this->plugin->getLogger()->warning("World " . $pos["world"] . " not found for NPC " . $uuid);
                continue;
            }

            $bb = new \pocketmine\math\AxisAlignedBB(
                $pos["x"] - 1, $pos["y"] - 1, $pos["z"] - 1,
                $pos["x"] + 1, $pos["y"] + 1, $pos["z"] + 1
            );
            $nearbyEntities = $world->getNearbyEntities($bb);
            
            foreach($nearbyEntities as $entity) {
                if($entity instanceof \pocketmine\entity\Human) {
                    $this->plugin->getLogger()->warning("Despawning duplicate entity at NPC spawn location for '$uuid'");
                    $entity->flagForDespawn();
                }
            }

            $this->npcManager->spawnNPC($world, $uuid);
            $count++;
        }
        
        $this->plugin->getLogger()->info("§a" . $count . " NPCs chargés !");
    }
}