<?php

namespace CustomNPC\manager;

use CustomNPC\utils\Constants;

class ModelManager {

    private const LYING_HEIGHT = 4.0;
    private const LYING_LEG_OFFSET = 12.0;

    public function normalizePose(string $poseId): string {
        $poseId = strtolower(trim($poseId));
        if($poseId === "") return Constants::DEFAULT_POSE;

        $poseId = Constants::POSE_ALIASES[$poseId] ?? $poseId;

        return isset(Constants::POSES[$poseId]) ? $poseId : Constants::DEFAULT_POSE;
    }

    public function normalizeModel(string $model): string {
        $model = strtolower(trim($model));

        if(in_array($model, ["alex", "slim", "fin", "fins"], true)) return Constants::MODEL_ALEX;

        return Constants::MODEL_STEVE;
    }

    public function isSlim(array $data): bool {
        if(isset($data["skinModel"])) {
            return $this->normalizeModel((string)$data["skinModel"]) === Constants::MODEL_ALEX;
        }

        return (bool)($data["slim"] ?? false);
    }

    public function poseExists(string $poseId): bool {
        $poseId = strtolower(trim($poseId));
        $poseId = Constants::POSE_ALIASES[$poseId] ?? $poseId;

        return isset(Constants::POSES[$poseId]);
    }

    public function listPoses(): array {
        return Constants::POSES;
    }

    public function listModels(): array {
        return Constants::SKIN_MODELS;
    }

    public function getPoseLabel(string $poseId): string {
        $poseId = $this->normalizePose($poseId);
        return Constants::POSES[$poseId] ?? ucfirst($poseId);
    }

    public function getModelLabel(string $model): string {
        $model = $this->normalizeModel($model);
        return Constants::SKIN_MODELS[$model] ?? $model;
    }

    public function getIdentifier(string $raceId, string $poseId, bool $slim = false): string {
        return "geometry.customnpc." . $raceId . "." . $this->normalizePose($poseId) . "." . ($slim ? "alex" : "steve");
    }

    public function getHitbox(string $raceId, string $poseId): array {
        $poseId = $this->normalizePose($poseId);

        $base = match($raceId) {
            "enderman" => ["height" => 2.9, "eyeHeight" => 2.55, "width" => 0.6],
            default => ["height" => 1.8, "eyeHeight" => 1.62, "width" => 0.6]
        };

        return match($poseId) {
            "assis" => [
                "height" => $base["height"] * 0.65,
                "eyeHeight" => $base["eyeHeight"] * 0.62,
                "width" => $base["width"]
            ],
            "couche" => [
                "height" => 0.7,
                "eyeHeight" => 0.45,
                "width" => 1.2
            ],
            default => $base
        };
    }

    public function buildGeometry(string $raceId, string $poseId, bool $slim = false): string {
        $poseId = $this->normalizePose($poseId);

        $bones = $this->getRaceBones($raceId, $slim);
        $bones = $this->applyPose($bones, $poseId);
        $hitbox = $this->getHitbox($raceId, $poseId);

        return (string)json_encode([
            "format_version" => "1.12.0",
            "minecraft:geometry" => [
                [
                    "description" => [
                        "identifier" => $this->getIdentifier($raceId, $poseId, $slim),
                        "texture_width" => 64,
                        "texture_height" => 64,
                        "visible_bounds_width" => 4,
                        "visible_bounds_height" => max(2, (int)ceil($hitbox["height"] + 1)),
                        "visible_bounds_offset" => [0, 1, 0]
                    ],
                    "bones" => $bones
                ]
            ]
        ]);
    }

    public function getRaceBones(string $raceId, bool $slim = false): array {
        return match($raceId) {
            "enderman" => $this->endermanBones(),
            default => $this->humanoidBones($slim)
        };
    }

    private function applyPose(array $bones, string $poseId): array {
        if($poseId === Constants::DEFAULT_POSE) {
            return $bones;
        }

        return match($poseId) {
            "zombie" => $this->rotate($bones, [
                "rightArm" => [-90, 0, -8],
                "leftArm" => [-90, 0, 8]
            ]),
            "calin" => $this->rotate($bones, [
                "rightArm" => [-75, 25, 30],
                "leftArm" => [-75, -25, -30]
            ]),
            "bras_croises" => $this->rotate($bones, [
                "rightArm" => [-20, 0, -102],
                "leftArm" => [-12, 0, 98]
            ]),
            "salut_droite" => $this->rotate($bones, [
                "rightArm" => [-155, 0, -12]
            ]),
            "salut_gauche" => $this->rotate($bones, [
                "leftArm" => [-155, 0, 12]
            ]),
            "assis" => $this->sitting($bones),
            "couche" => $this->lying($bones),
            default => $bones
        };
    }

    private function rotate(array $bones, array $rotations): array {
        foreach($bones as &$bone) {
            if(isset($rotations[$bone["name"]])) {
                $bone["rotation"] = $rotations[$bone["name"]];
            }
        }
        unset($bone);

        return $bones;
    }

    private function sitting(array $bones): array {
        $bones = $this->translate($bones, -10.0);

        return $this->rotate($bones, [
            "rightLeg" => [-95, 0, 0],
            "leftLeg" => [-95, 0, 0],
            "rightArm" => [-25, 0, 0],
            "leftArm" => [-25, 0, 0]
        ]);
    }

    private function lying(array $bones): array {
        foreach($bones as &$bone) {
            $pivotY = (float)($bone["pivot"][1] ?? 0.0);
            $offsetY = self::LYING_HEIGHT - $pivotY;
            $offsetZ = str_contains(strtolower($bone["name"]), "leg") ? self::LYING_LEG_OFFSET : 0.0;

            $bone["pivot"][1] = $bone["pivot"][1] + $offsetY;
            $bone["pivot"][2] = ($bone["pivot"][2] ?? 0) + $offsetZ;

            if(isset($bone["cubes"])) {
                foreach($bone["cubes"] as &$cube) {
                    $cube["origin"][1] = $cube["origin"][1] + $offsetY;
                    $cube["origin"][2] = $cube["origin"][2] + $offsetZ;
                }
                unset($cube);
            }

            $bone["rotation"] = [-90, 0, 0];
        }
        unset($bone);

        return $bones;
    }

    private function translate(array $bones, float $offsetY): array {
        foreach($bones as &$bone) {
            if(isset($bone["pivot"][1])) {
                $bone["pivot"][1] += $offsetY;
            }
            if(isset($bone["cubes"])) {
                foreach($bone["cubes"] as &$cube) {
                    if(isset($cube["origin"][1])) {
                        $cube["origin"][1] += $offsetY;
                    }
                }
                unset($cube);
            }
        }
        unset($bone);

        return $bones;
    }

    private function humanoidBones(bool $slim = false): array {
        $armWidth = $slim ? 3 : 4;
        $rightArmX = $slim ? -7 : -8;
        $armPivotY = $slim ? 21.5 : 22;

        return [
            ["name" => "body", "pivot" => [0, 24, 0], "cubes" => [
                ["origin" => [-4, 12, -2], "size" => [8, 12, 4], "uv" => [16, 16]],
                ["origin" => [-4, 12, -2], "size" => [8, 12, 4], "uv" => [16, 32], "inflate" => 0.25]
            ]],
            ["name" => "head", "pivot" => [0, 24, 0], "cubes" => [
                ["origin" => [-4, 24, -4], "size" => [8, 8, 8], "uv" => [0, 0]],
                ["origin" => [-4, 24, -4], "size" => [8, 8, 8], "uv" => [32, 0], "inflate" => 0.5]
            ]],
            ["name" => "rightArm", "pivot" => [-5, $armPivotY, 0], "cubes" => [
                ["origin" => [$rightArmX, 12, -2], "size" => [$armWidth, 12, 4], "uv" => [40, 16]],
                ["origin" => [$rightArmX, 12, -2], "size" => [$armWidth, 12, 4], "uv" => [40, 32], "inflate" => 0.25]
            ]],
            ["name" => "leftArm", "pivot" => [5, $armPivotY, 0], "cubes" => [
                ["origin" => [4, 12, -2], "size" => [$armWidth, 12, 4], "uv" => [32, 48]],
                ["origin" => [4, 12, -2], "size" => [$armWidth, 12, 4], "uv" => [48, 48], "inflate" => 0.25]
            ]],
            ["name" => "rightLeg", "pivot" => [-1.9, 12, 0], "cubes" => [
                ["origin" => [-3.9, 0, -2], "size" => [4, 12, 4], "uv" => [0, 16]],
                ["origin" => [-3.9, 0, -2], "size" => [4, 12, 4], "uv" => [0, 32], "inflate" => 0.25]
            ]],
            ["name" => "leftLeg", "pivot" => [1.9, 12, 0], "cubes" => [
                ["origin" => [-0.1, 0, -2], "size" => [4, 12, 4], "uv" => [16, 48]],
                ["origin" => [-0.1, 0, -2], "size" => [4, 12, 4], "uv" => [0, 48], "inflate" => 0.25]
            ]]
        ];
    }

    private function endermanBones(): array {
        return [
            ["name" => "body", "pivot" => [0, 36, 0], "cubes" => [
                ["origin" => [-4, 18, -2], "size" => [8, 18, 4], "uv" => [16, 16]]
            ]],
            ["name" => "head", "pivot" => [0, 36, 0], "cubes" => [
                ["origin" => [-4, 36, -4], "size" => [8, 8, 8], "uv" => [0, 0]],
                ["origin" => [-4, 36, -4], "size" => [8, 8, 8], "uv" => [32, 0], "inflate" => 0.5]
            ]],
            ["name" => "rightArm", "pivot" => [-5, 34, 0], "cubes" => [
                ["origin" => [-7, 6, -2], "size" => [3, 28, 3], "uv" => [40, 16]]
            ]],
            ["name" => "leftArm", "pivot" => [5, 34, 0], "cubes" => [
                ["origin" => [4, 6, -2], "size" => [3, 28, 3], "uv" => [32, 48]]
            ]],
            ["name" => "rightLeg", "pivot" => [-2, 18, 0], "cubes" => [
                ["origin" => [-4, 0, -2], "size" => [3, 18, 3], "uv" => [0, 16]]
            ]],
            ["name" => "leftLeg", "pivot" => [2, 18, 0], "cubes" => [
                ["origin" => [1, 0, -2], "size" => [3, 18, 3], "uv" => [16, 48]]
            ]]
        ];
    }
}
