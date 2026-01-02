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
    
    public function loadSkin(string $skinPath, ?Player $player = null): Skin {
        if($player !== null) {
            $this->plugin->getLogger()->info("§aCopie du skin du joueur: " . $player->getName());
            return $player->getSkin();
        }

        if(empty($skinPath)) {
            $this->plugin->getLogger()->info("§7Utilisation du skin par défaut (aucun skin spécifié)");
            return $this->getDefaultSkin();
        }

        if(strpos($skinPath, "player_") === 0) {
            $this->plugin->getLogger()->info("§7Skin de joueur sauvegardé détecté: {$skinPath}");
            return $this->getDefaultSkin(); 
        }

        if(strpos($skinPath, "player:") === 0) {
            $playerName = substr($skinPath, 7);
            $this->plugin->getLogger()->info("§eTentative de copie du skin du joueur: {$playerName}");
            
            $targetPlayer = $this->plugin->getServer()->getPlayerByPrefix($playerName);
            
            if($targetPlayer !== null) {
                $this->plugin->getLogger()->info("§aJoueur trouvé ! Copie du skin...");
                $skin = $targetPlayer->getSkin();
                $this->plugin->getLogger()->info("§aSkin copié avec succès ! ID: " . $skin->getSkinId());
                return $skin;
            } else {
                $this->plugin->getLogger()->warning("§cJoueur introuvable: {$playerName}");
                $this->plugin->getLogger()->warning("§7Joueurs en ligne: " . implode(", ", array_map(fn($p) => $p->getName(), $this->plugin->getServer()->getOnlinePlayers())));
                return $this->getDefaultSkin();
            }
        }
        
        $fullPath = $this->plugin->getDataFolder() . "skins/" . $skinPath;
        
        $this->plugin->getLogger()->info("§eTentative de chargement du skin: {$fullPath}");
        
        if(file_exists($fullPath)) {
            try {
                $skin = $this->loadSkinFromFile($fullPath);
                $this->plugin->getLogger()->info("§aSkin chargé avec succès: {$skinPath}");
                return $skin;
            } catch(\Exception $e) {
                $this->plugin->getLogger()->error("Erreur chargement skin: " . $e->getMessage());
                $this->plugin->getLogger()->error("Fichier: {$fullPath}");
                return $this->getDefaultSkin();
            }
        } else {
            $this->plugin->getLogger()->warning("§cFichier skin introuvable: {$fullPath}");
            $this->plugin->getLogger()->warning("§7Dossier: " . $this->plugin->getDataFolder() . "skins/");
            return $this->getDefaultSkin();
        }
    }
    
    public function loadSkinFromFile(string $path): Skin {
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
        
        $validSizes = [
            [64, 32],
            [64, 64],
            [128, 64],
            [128, 128]
        ];
        
        $isValid = false;
        foreach($validSizes as $size) {
            if($width === $size[0] && $height === $size[1]) {
                $isValid = true;
                break;
            }
        }
        
        if(!$isValid) {
            imagedestroy($img);
            throw new \Exception("Dimensions invalides ($width x $height). Utilisez 64x32, 64x64, 128x64 ou 128x128");
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

        $geometryName = "geometry.humanoid.custom";
        $geometryData = $this->getDefaultGeometry();

        // Fix skin dimensions if needed
        if ($width === 64 && $height === 32) {
            $newSkinData = $skinData . str_repeat("\x00", 8192); // Padding for 64x64
            $skinData = $newSkinData;
        }

        return new Skin(
            $skinId,
            $skinData,
            "",
            $geometryName,
            $geometryData
        );
    }
    
    private function getDefaultSkin(): Skin {
        // Create a bright red skin (255, 0, 0, 255)
        // 64x64 skin = 4096 pixels * 4 bytes = 16384 bytes
        $skinData = str_repeat(chr(255) . chr(0) . chr(0) . chr(255), 64 * 64);
        
        return new Skin(
            "Standard_Custom",
            $skinData,
            "",
            "geometry.humanoid.custom",
            $this->getDefaultGeometry()
        );
    }
    
    private function getDefaultGeometry(): string {
        return json_encode([
            "format_version" => "1.12.0",
            "minecraft:geometry" => [
                [
                    "description" => [
                        "identifier" => "geometry.humanoid.custom",
                        "texture_width" => 64,
                        "texture_height" => 64,
                        "visible_bounds_width" => 2,
                        "visible_bounds_height" => 2,
                        "visible_bounds_offset" => [0, 1, 0]
                    ],
                    "bones" => [
                        ["name" => "body", "pivot" => [0, 24, 0], "cubes" => [["origin" => [-4, 12, -2], "size" => [8, 12, 4], "uv" => [16, 16]]]],
                        ["name" => "head", "pivot" => [0, 24, 0], "cubes" => [["origin" => [-4, 24, -4], "size" => [8, 8, 8], "uv" => [0, 0]]]],
                        ["name" => "hat", "pivot" => [0, 24, 0], "cubes" => [["origin" => [-4, 24, -4], "size" => [8, 8, 8], "uv" => [32, 0], "inflate" => 0.5]]],
                        ["name" => "rightArm", "pivot" => [-5, 22, 0], "cubes" => [["origin" => [-8, 12, -2], "size" => [4, 12, 4], "uv" => [40, 16]]]],
                        ["name" => "leftArm", "pivot" => [5, 22, 0], "cubes" => [["origin" => [4, 12, -2], "size" => [4, 12, 4], "uv" => [32, 48]]]],
                        ["name" => "rightLeg", "pivot" => [-1.9, 12, 0], "cubes" => [["origin" => [-3.9, 0, -2], "size" => [4, 12, 4], "uv" => [0, 16]]]],
                        ["name" => "leftLeg", "pivot" => [1.9, 12, 0], "cubes" => [["origin" => [-0.1, 0, -2], "size" => [4, 12, 4], "uv" => [16, 48]]]]
                    ]
                ]
            ]
        ]);
    }
    

    public function listAvailableSkins(): array {
        $skinDir = $this->plugin->getDataFolder() . "skins/";
        
        if(!is_dir($skinDir)) {
            @mkdir($skinDir, 0777, true);
            return [];
        }
        
        $skins = [];
        $files = scandir($skinDir);
        
        foreach($files as $file) {
            if($file === "." || $file === "..") {
                continue;
            }
            
            if(pathinfo($file, PATHINFO_EXTENSION) === "png") {
                $filePath = $skinDir . $file;
                $img = @imagecreatefrompng($filePath);
                
                if($img) {
                    $width = imagesx($img);
                    $height = imagesy($img);
                    
                    $skins[] = [
                        "name" => $file,
                        "path" => $file,
                        "width" => $width,
                        "height" => $height,
                        "size" => filesize($filePath),
                        "valid" => $this->isValidSkinSize($width, $height)
                    ];
                    
                    imagedestroy($img);
                }
            }
        }
        
        return $skins;
    }
    
    private function isValidSkinSize(int $width, int $height): bool {
        $validSizes = [[64, 32], [64, 64], [128, 64], [128, 128]];
        
        foreach($validSizes as $size) {
            if($width === $size[0] && $height === $size[1]) {
                return true;
            }
        }
        
        return false;
    }
    
    public function importSkin(string $sourcePath, string $name): bool {
        $skinDir = $this->plugin->getDataFolder() . "skins/";
        
        if(!is_dir($skinDir)) {
            @mkdir($skinDir, 0777, true);
        }
        
        if(!file_exists($sourcePath)) {
            return false;
        }
        
        $destPath = $skinDir . $name;
        
        return copy($sourcePath, $destPath);
    }
}
