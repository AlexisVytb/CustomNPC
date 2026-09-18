<?php

namespace CustomNPC\manager;

use pocketmine\entity\Skin;
use CustomNPC\Main;

class SkinManager {

    public const RESULT_OK = "ok";
    public const RESULT_NOT_FOUND = "not_found";
    public const RESULT_INVALID = "invalid";

    private Main $plugin;
    private ?string $defaultTexture = null;
    private string $lastError = self::RESULT_OK;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        @mkdir($this->skinsFolder(), 0777, true);
    }

    public function skinsFolder(): string {
        return $this->plugin->getDataFolder() . "skins/";
    }

    public function getLastError(): string {
        return $this->lastError;
    }

    public function resolveFile(string $skinPath): ?string {
        $skinPath = trim(str_replace("\\", "/", $skinPath));
        if($skinPath === "") return null;

        $name = basename($skinPath);
        if($name === "" || $name === "." || $name === "..") return null;

        $folder = $this->skinsFolder();

        $candidates = [$name];

        if(strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== "png") {
            $candidates[] = $name . ".png";
            $candidates[] = $name . ".PNG";
        }

        foreach($candidates as $candidate) {
            if(is_file($folder . $candidate)) {
                return $folder . $candidate;
            }
        }

        if(!is_dir($folder)) return null;

        $wanted = strtolower($name);
        $wantedPng = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === "png" ? $wanted : $wanted . ".png";

        $entries = @scandir($folder);
        if($entries === false) return null;

        foreach($entries as $entry) {
            if($entry === "." || $entry === "..") continue;
            if(!is_file($folder . $entry)) continue;

            $lower = strtolower($entry);
            if($lower === $wanted || $lower === $wantedPng) {
                return $folder . $entry;
            }
        }

        foreach($entries as $entry) {
            if($entry === "." || $entry === "..") continue;
            if(!is_file($folder . $entry)) continue;
            if(strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== "png") continue;

            if(strtolower(pathinfo($entry, PATHINFO_FILENAME)) === strtolower(pathinfo($name, PATHINFO_FILENAME))) {
                return $folder . $entry;
            }
        }

        return null;
    }

    public function loadTexture(string $skinPath): ?string {
        $this->lastError = self::RESULT_OK;

        if(trim($skinPath) === "") return null;

        if(str_starts_with($skinPath, "player:")) {
            $target = $this->plugin->getServer()->getPlayerByPrefix(substr($skinPath, 7));

            if($target === null) {
                $this->lastError = self::RESULT_NOT_FOUND;
                return null;
            }

            return $target->getSkin()->getSkinData();
        }

        $fullPath = $this->resolveFile($skinPath);

        if($fullPath === null) {
            $this->lastError = self::RESULT_NOT_FOUND;
            $this->plugin->getLogger()->warning("Fichier skin introuvable : " . $skinPath . " (dossier " . $this->skinsFolder() . ")");
            return null;
        }

        $texture = $this->readTexture($fullPath);

        if($texture === null) {
            $this->lastError = self::RESULT_INVALID;
        }

        return $texture;
    }

    public function readTexture(string $path): ?string {
        if(!function_exists('imagecreatefrompng')) {
            $this->plugin->getLogger()->warning("Extension GD absente : impossible de lire les skins PNG.");
            return null;
        }

        if(!is_file($path)) return null;

        $source = @imagecreatefrompng($path);
        if($source === false) {
            $this->plugin->getLogger()->warning("PNG illisible : " . basename($path));
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if(!$this->isValidSize($width, $height)) {
            imagedestroy($source);
            $this->plugin->getLogger()->warning("Dimensions de skin invalides (" . $width . "x" . $height . ") : " . basename($path));
            return null;
        }

        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $transparent);
        imagecopy($img, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);

        $data = '';

        for($y = 0; $y < $height; $y++) {
            for($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($img, $x, $y);

                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $alpha = ($rgba >> 24) & 0x7F;
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
        $skinId = "CustomNPC_" . substr(md5($texture . $geometryData), 0, 24);
        return new Skin($skinId, $texture, $capeData, $geometryName, $geometryData);
    }

    public function getDefaultTexture(): string {
        if($this->defaultTexture !== null) return $this->defaultTexture;

        $path = $this->resolveFile("steve.png");
        $texture = $path !== null ? $this->readTexture($path) : null;

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

        $entries = @scandir($folder);
        if($entries === false) return [];

        $skins = [];

        foreach($entries as $file) {
            if($file === "." || $file === "..") continue;
            if(!is_file($folder . $file)) continue;
            if(strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== "png") continue;

            $skins[] = $file;
        }

        sort($skins, SORT_NATURAL | SORT_FLAG_CASE);
        return $skins;
    }

    public function encodeSkin(Skin $skin): array {
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
