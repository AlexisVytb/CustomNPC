<?php

namespace CustomNPC\manager;

use pocketmine\entity\Skin;
use CustomNPC\Main;

class SkinManager {

    private Main $plugin;
    private ?string $defaultTexture = null;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function skinsFolder(): string {
        return $this->plugin->getDataFolder() . "skins/";
    }

    public function loadTexture(string $skinPath): ?string {
        if($skinPath === "") return null;

        if(str_starts_with($skinPath, "player:")) {
            $target = $this->plugin->getServer()->getPlayerByPrefix(substr($skinPath, 7));
            return $target !== null ? $target->getSkin()->getSkinData() : null;
        }

        $fullPath = $this->skinsFolder() . basename($skinPath);
        if(!file_exists($fullPath)) {
            $this->plugin->getLogger()->warning("Fichier skin introuvable : " . $fullPath);
            return null;
        }

        return $this->readTexture($fullPath);
    }

    public function readTexture(string $path): ?string {
        if(!function_exists('imagecreatefrompng')) return null;
        if(!file_exists($path)) return null;

        $img = @imagecreatefrompng($path);
        if($img === false) return null;

        imagealphablending($img, false);
        imagesavealpha($img, true);

        $width = imagesx($img);
        $height = imagesy($img);

        if(!$this->isValidSize($width, $height)) {
            imagedestroy($img);
            $this->plugin->getLogger()->warning("Dimensions de skin invalides ({$width}x{$height}) : " . basename($path));
            return null;
        }

        $data = '';
        for($y = 0; $y < $height; $y++) {
            for($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $alpha = ($rgba & 0x7F000000) >> 24;
                $a = 255 - (int)round($alpha * 255 / 127);
                $data .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }

        imagedestroy($img);

        if($width === 64 && $height === 32) {
            $data .= str_repeat("\x00", 64 * 32 * 4);
        }

        return $data;
    }

    public function buildSkin(string $texture, string $geometryName, string $geometryData, string $capeData = ""): Skin {
        $skinId = "CustomNPC_" . substr(md5($texture . $geometryData), 0, 16);
        return new Skin($skinId, $texture, $capeData, $geometryName, $geometryData);
    }

    public function getDefaultTexture(): string {
        if($this->defaultTexture !== null) return $this->defaultTexture;

        $path = $this->skinsFolder() . "steve.png";
        $texture = $this->readTexture($path);

        if($texture === null) {
            $texture = $this->generateSteveTexture();
        }

        $this->defaultTexture = $texture;
        return $texture;
    }

    private function generateSteveTexture(): string {
        $pixels = [];
        for($i = 0; $i < 64 * 64; $i++) {
            $pixels[] = chr(0) . chr(0) . chr(0) . chr(0);
        }

        $set = function(int $x0, int $y0, int $x1, int $y1, array $color) use (&$pixels): void {
            for($y = $y0; $y <= $y1; $y++) {
                for($x = $x0; $x <= $x1; $x++) {
                    $pixels[$y * 64 + $x] = chr($color[0]) . chr($color[1]) . chr($color[2]) . chr(255);
                }
            }
        };

        $skin = [229, 178, 139];
        $hair = [63, 44, 30];
        $shirt = [0, 172, 172];
        $pants = [58, 70, 148];
        $shoes = [104, 84, 66];
        $eye = [60, 60, 180];

        $set(0, 0, 31, 15, $hair);
        $set(8, 8, 15, 15, $skin);
        $set(9, 12, 10, 12, $eye);
        $set(13, 12, 14, 12, $eye);
        $set(16, 16, 39, 31, $shirt);
        $set(40, 16, 55, 31, $skin);
        $set(0, 16, 15, 31, $pants);
        $set(0, 28, 15, 31, $shoes);
        $set(16, 48, 31, 63, $pants);
        $set(16, 60, 31, 63, $shoes);
        $set(32, 48, 47, 63, $skin);

        return implode("", $pixels);
    }

    public function isValidSize(int $width, int $height): bool {
        foreach([[64, 32], [64, 64], [128, 64], [128, 128]] as [$w, $h]) {
            if($width === $w && $height === $h) return true;
        }
        return false;
    }

    public function listAvailableSkins(): array {
        $folder = $this->skinsFolder();
        if(!is_dir($folder)) {
            @mkdir($folder, 0777, true);
            return [];
        }

        $skins = [];
        foreach(scandir($folder) as $file) {
            if($file === "." || $file === "..") continue;
            if(strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== "png") continue;
            $skins[] = $file;
        }

        return $skins;
    }

    public function encodeSkin(\pocketmine\entity\Skin $skin): array {
        return [
            "skinId" => $skin->getSkinId(),
            "skinData" => base64_encode($skin->getSkinData()),
            "capeData" => base64_encode($skin->getCapeData()),
            "geometryName" => $skin->getGeometryName(),
            "geometryData" => base64_encode($skin->getGeometryData())
        ];
    }

    public function decodeTexture(?array $savedSkin): ?string {
        if(!is_array($savedSkin) || empty($savedSkin["skinData"])) return null;
        $data = base64_decode((string)$savedSkin["skinData"], true);
        return ($data === false || $data === "") ? null : $data;
    }
}
