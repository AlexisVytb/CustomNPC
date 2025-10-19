<?php

namespace CustomNPC\manager;

use pocketmine\entity\Skin;
use pocketmine\player\Player;
use CustomNPC\Main;

class SkinManager {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function loadSkin(string $skinPath, ?Player $player): Skin {
        // Note: Les skins custom ne fonctionnent pas sur Bedrock Edition
        
        if($player !== null) {
            return $player->getSkin();
        }

        return $this->getDefaultSkin();
    }

    public function loadSkinFromFile(string $path): Skin {
        // Conservée pour compatibilité future
        
        if(!function_exists('imagecreatefrompng')) {
            throw new \Exception("Extension GD non disponible");
        }
        
        if(!file_exists($path)) {
            throw new \Exception("Fichier introuvable: $path");
        }
        
        $img = @imagecreatefrompng($path);
        if(!$img) {
            throw new \Exception("Impossible de charger le fichier PNG");
        }

        $width = imagesx($img);
        $height = imagesy($img);
        
        $validSizes = [[64, 32], [64, 64], [128, 64], [128, 128]];
        
        $isValid = false;
        foreach($validSizes as $size) {
            if($width === $size[0] && $height === $size[1]) {
                $isValid = true;
                break;
            }
        }
        
        if(!$isValid) {
            imagedestroy($img);
            throw new \Exception("Dimensions invalides ($width x $height)");
        }

        $skinData = '';
        for($y = 0; $y < $height; $y++) {
            for($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                
                $alpha = ($rgba & 0x7F000000) >> 24;
                $a = 255 - ($alpha * 2);
                
                $skinData .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }
        imagedestroy($img);

        $skinId = "CustomNPC_" . basename($path, ".png") . "_" . time();
        return new Skin($skinId, $skinData);
    }

    private function getDefaultSkin(): Skin {
        // Skin Steve par défaut
        $skinData = str_repeat(chr(0) . chr(0) . chr(0) . chr(0), 64 * 64);
        return new Skin("Standard_Steve", $skinData);
    }

    public function listAvailableSkins(): array {
        $skinDir = $this->plugin->getDataFolder() . "skins/";
        
        if(!is_dir($skinDir)) {
            return [];
        }
        
        $skins = [];
        $files = scandir($skinDir);
        
        foreach($files as $file) {
            if($file !== "." && $file !== ".." && pathinfo($file, PATHINFO_EXTENSION) === "png") {
                $filePath = $skinDir . $file;
                $img = @imagecreatefrompng($filePath);
                
                if($img) {
                    $skins[] = [
                        "name" => $file,
                        "width" => imagesx($img),
                        "height" => imagesy($img),
                        "size" => filesize($filePath)
                    ];
                    imagedestroy($img);
                }
            }
        }
        
        return $skins;
    }
}