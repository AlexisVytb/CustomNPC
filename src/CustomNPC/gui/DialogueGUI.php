<?php

namespace CustomNPC\gui;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;

class DialogueGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable.");
            return;
        }

        $lines = $data["dialogue"] ?? [];
        $text = implode("\n", array_filter($lines, 'is_string'));

        $form = new CustomForm(function(Player $player, $result) use ($uuid) {
            if($result === null) return;

            $raw = (string)$result[2];
            $lines = [];

            foreach(preg_split('/\r\n|\r|\n|\|/', $raw) as $line) {
                $line = trim($line);
                if($line !== "") $lines[] = $line;
            }

            $this->npcManager->updateNPCData($uuid, [
                "dialogueEnabled" => (bool)$result[1],
                "dialogue" => $lines,
                "dialogueDelay" => max(1, min(200, (int)$result[3])),
                "interactSound" => trim((string)$result[4])
            ]);

            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§a" . count($lines) . " ligne(s) de dialogue enregistree(s).");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§5Dialogues");
        $form->addLabel(
            "§7Une ligne par message. Separe avec un saut de ligne ou le caractere §e|§7.\n" .
            "§7Placeholder : §8{player}\n" .
            "§7Le dialogue se declenche au clic droit sur le NPC."
        );
        $form->addToggle("Activer les dialogues", (bool)($data["dialogueEnabled"] ?? false));
        $form->addInput("Lignes de dialogue", "Bonjour {player} !|Bienvenue sur le serveur.", $text);
        $form->addInput("Delai entre les lignes (ticks)", "20", (string)($data["dialogueDelay"] ?? 20));
        $form->addInput("Son a l'interaction (optionnel)", "random.levelup", (string)($data["interactSound"] ?? ""));

        $player->sendForm($form);
    }
}
