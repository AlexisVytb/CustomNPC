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
    
    /**
     * Charge un skin depuis un fichier ou récupère celui d'un joueur
     * Si $skinPath commence par "player:", copie le skin d'un joueur
     * Si $player est fourni, utilise son skin directement
     */
    public function loadSkin(string $skinPath, ?Player $player = null): Skin {
        // PRIORITÉ 1 : Si un joueur est fourni directement, on clone son skin
        if($player !== null) {
            $this->plugin->getLogger()->info("§aCopie du skin du joueur: " . $player->getName());
            return $player->getSkin();
        }
        
        // Si pas de skin spécifié, retourner le skin par défaut
        if(empty($skinPath)) {
            $this->plugin->getLogger()->info("§7Utilisation du skin par défaut (aucun skin spécifié)");
            return $this->getDefaultSkin();
        }
        
        // PRIORITÉ 2 : Si le skin commence par "player_", c'est un skin déjà sauvegardé
        // On ne fait rien ici, c'est géré par NPCManager avec savedSkin
        if(strpos($skinPath, "player_") === 0) {
            $this->plugin->getLogger()->info("§7Skin de joueur sauvegardé détecté: {$skinPath}");
            return $this->getDefaultSkin(); // Sera remplacé par savedSkin dans NPCManager
        }
        
        // PRIORITÉ 3 : Si le skin commence par "player:", copier le skin d'un joueur en ligne
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
        
        // PRIORITÉ 4 : Charger depuis un fichier PNG
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
    
    /**
     * Charge un skin depuis un fichier PNG
     */
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
        
        // Tailles valides pour les skins Minecraft
        $validSizes = [
            [64, 32],   // Skin classique
            [64, 64],   // Skin avec overlay
            [128, 64],  // Skin HD
            [128, 128]  // Skin HD avec overlay
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
        
        // Conversion en données RGBA
        $skinData = '';
        for($y = 0; $y < $height; $y++) {
            for($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                
                // Correction de l'alpha pour Minecraft Bedrock
                $alpha = ($rgba & 0x7F000000) >> 24;
                $a = 255 - ($alpha * 2);
                
                $skinData .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }
        
        imagedestroy($img);
        
        // Créer le skin avec les bonnes propriétés
        $skinId = "CustomNPC_" . basename($path, ".png") . "_" . time();
        
        // Géométrie par défaut (humanoïde)
        $geometryName = "geometry.humanoid.custom";
        $geometryData = $this->getDefaultGeometry();
        
        // Créer le skin avec toutes les propriétés nécessaires
        return new Skin(
            $skinId,           // ID unique du skin
            $skinData,         // Données RGBA du skin
            "",                // Cape data (vide)
            $geometryName,     // Nom de la géométrie
            $geometryData      // Données JSON de la géométrie
        );
    }
    
    /**
     * Retourne un skin par défaut (Steve)
     */
    private function getDefaultSkin(): Skin {
        // Skin Steve par défaut - couleur chair visible
        // Format RGBA : R=205, G=132, B=92, A=255 (couleur peau de Steve)
        $skinData = str_repeat(chr(205) . chr(132) . chr(92) . chr(255), 64 * 64);
        
        return new Skin(
            "Standard_Steve",
            $skinData,
            "",
            "geometry.humanoid.custom",
            $this->getDefaultGeometry()
        );
    }
    
    /**
     * Retourne la géométrie par défaut pour un humanoïde
     */
    private function getDefaultGeometry(): string {
        // Géométrie standard Minecraft
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
                    ]
                ]
            ]
        ]);
    }
    
    /**
     * Liste tous les skins disponibles dans le dossier skins/
     */
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
    
    /**
     * Vérifie si les dimensions sont valides pour un skin
     */
    private function isValidSkinSize(int $width, int $height): bool {
        $validSizes = [[64, 32], [64, 64], [128, 64], [128, 128]];
        
        foreach($validSizes as $size) {
            if($width === $size[0] && $height === $size[1]) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Copie un skin depuis un chemin externe vers le dossier skins/
     */
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
