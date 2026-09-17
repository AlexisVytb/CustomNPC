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

        foreach($this->plugin->getServer()->getWorldManager()->getWorlds() as $world) {
            $count += $this->npcManager->spawnWorld($world);
        }

        $missing = [];
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $worldName = (string)($data["position"]["world"] ?? "");
            if($worldName === "") continue;
            if($this->plugin->getServer()->getWorldManager()->getWorldByName($worldName) === null) {
                $missing[$worldName] = true;
            }
        }

        foreach(array_keys($missing) as $worldName) {
            $this->plugin->getLogger()->warning("Monde non charge : " . $worldName . " (NPCs en attente)");
        }

        $this->plugin->getLogger()->info($count . " NPCs spawnes");
    }
}
