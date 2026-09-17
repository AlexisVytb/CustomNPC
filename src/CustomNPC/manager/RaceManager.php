<?php

namespace CustomNPC\manager;

use CustomNPC\Main;
use CustomNPC\utils\Constants;

class RaceManager {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->ensureDefaultRaces();
    }

    public function racesFolder(): string {
        return $this->plugin->getDataFolder() . "races/";
    }

    private function ensureDefaultRaces(): void {
        @mkdir($this->racesFolder(), 0777, true);

        foreach(Constants::RACES as $id => $label) {
            if($id === Constants::DEFAULT_RACE) continue;

            $folder = $this->racesFolder() . $id . "/";
            @mkdir($folder, 0777, true);

            $texturePath = $folder . "texture.png";
            if(!file_exists($texturePath)) {
                $this->generatePlaceholderTexture($id, $texturePath);
            }
        }
    }

    public function listRaces(): array {
        $races = Constants::RACES;

        $folder = $this->racesFolder();
        if(is_dir($folder)) {
            foreach(scandir($folder) as $entry) {
                if($entry === "." || $entry === "..") continue;
                if(!is_dir($folder . $entry)) continue;
                if(!isset($races[$entry])) {
                    $races[$entry] = ucfirst($entry);
                }
            }
        }

        return $races;
    }

    public function raceExists(string $raceId): bool {
        if($raceId === Constants::DEFAULT_RACE) return true;
        return is_dir($this->racesFolder() . $raceId . "/");
    }

    public function getRaceLabel(string $raceId): string {
        return $this->listRaces()[$raceId] ?? ucfirst($raceId);
    }

    public function getTexturePath(string $raceId): ?string {
        $path = $this->racesFolder() . $raceId . "/texture.png";
        return file_exists($path) ? $path : null;
    }

    private function generatePlaceholderTexture(string $raceId, string $path): void {
        if(!function_exists('imagecreatetruecolor')) return;

        $img = imagecreatetruecolor(64, 64);
        imagesavealpha($img, true);

        [$r, $g, $b] = $this->getRaceColor($raceId);
        $base = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, 63, 63, $base);

        $shade = imagecolorallocate($img, max(0, $r - 25), max(0, $g - 25), max(0, $b - 25));
        imagefilledrectangle($img, 16, 16, 39, 31, $shade);

        [$er, $eg, $eb] = $this->getEyeColor($raceId);
        $eye = imagecolorallocate($img, $er, $eg, $eb);
        imagefilledrectangle($img, 9, 9, 10, 10, $eye);
        imagefilledrectangle($img, 13, 9, 14, 10, $eye);

        imagepng($img, $path);
        imagedestroy($img);
    }

    private function getRaceColor(string $raceId): array {
        return match($raceId) {
            "zombie" => [61, 130, 74],
            "squelette" => [214, 214, 201],
            "husk" => [156, 133, 92],
            "piglin" => [222, 154, 168],
            "enderman" => [18, 16, 22],
            default => [140, 140, 140]
        };
    }

    private function getEyeColor(string $raceId): array {
        return match($raceId) {
            "enderman" => [188, 47, 255],
            "zombie", "husk" => [255, 255, 255],
            default => [20, 20, 20]
        };
    }
}
