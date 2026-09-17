<?php

namespace CustomNPC\manager;

use CustomNPC\utils\Constants;

class ModelManager {

    public function poseExists(string $poseId): bool {
        return isset(Constants::POSES[$poseId]);
    }

    public function listPoses(): array {
        return Constants::POSES;
    }

    public function getPoseLabel(string $poseId): string {
        return Constants::POSES[$poseId] ?? ucfirst($poseId);
    }

    public function getIdentifier(string $raceId, string $poseId): string {
        return "geometry.customnpc." . $raceId . "." . $poseId;
    }

    public function getHitbox(string $raceId, string $poseId): array {
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
                "height" => 0.6,
                "eyeHeight" => 0.4,
                "width" => 1.2
            ],
            default => $base
        };
    }

    public function buildGeometry(string $raceId, string $poseId): string {
        $bones = $this->getRaceBones($raceId);
        $bones = $this->applyPose($bones, $poseId);
        $hitbox = $this->getHitbox($raceId, $poseId);

        return json_encode([
            "format_version" => "1.12.0",
            "minecraft:geometry" => [
                [
                    "description" => [
                        "identifier" => $this->getIdentifier($raceId, $poseId),
                        "texture_width" => 64,
                        "texture_height" => 64,
                        "visible_bounds_width" => 3,
                        "visible_bounds_height" => max(2, (int)ceil($hitbox["height"] + 1)),
                        "visible_bounds_offset" => [0, 1, 0]
                    ],
                    "bones" => $bones
                ]
            ]
        ]);
    }

    public function getRaceBones(string $raceId): array {
        return match($raceId) {
            "enderman" => $this->endermanBones(),
            default => $this->humanoidBones()
        };
    }

    private function applyPose(array $bones, string $poseId): array {
        if($poseId === Constants::DEFAULT_POSE || !$this->poseExists($poseId)) {
            return $bones;
        }

        return match($poseId) {
            "zombie" => $this->rotate($bones, [
                "rightArm" => [-90, 0, -8],
                "leftArm" => [-90, 0, 8]
            ]),
            "bras_croises" => $this->rotate($bones, [
                "rightArm" => [-75, 25, 30],
                "leftArm" => [-75, -25, -30]
            ]),
            "salut" => $this->rotate($bones, [
                "rightArm" => [-150, 0, -10]
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
        $bones = $this->translate($bones, -14.0);

        foreach($bones as &$bone) {
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

    private function humanoidBones(): array {
        return [
            ["name" => "body", "pivot" => [0, 24, 0], "cubes" => [
                ["origin" => [-4, 12, -2], "size" => [8, 12, 4], "uv" => [16, 16]],
                ["origin" => [-4, 12, -2], "size" => [8, 12, 4], "uv" => [16, 32], "inflate" => 0.25]
            ]],
            ["name" => "head", "pivot" => [0, 24, 0], "cubes" => [
                ["origin" => [-4, 24, -4], "size" => [8, 8, 8], "uv" => [0, 0]],
                ["origin" => [-4, 24, -4], "size" => [8, 8, 8], "uv" => [32, 0], "inflate" => 0.5]
            ]],
            ["name" => "rightArm", "pivot" => [-5, 22, 0], "cubes" => [
                ["origin" => [-8, 12, -2], "size" => [4, 12, 4], "uv" => [40, 16]],
                ["origin" => [-8, 12, -2], "size" => [4, 12, 4], "uv" => [40, 32], "inflate" => 0.25]
            ]],
            ["name" => "leftArm", "pivot" => [5, 22, 0], "cubes" => [
                ["origin" => [4, 12, -2], "size" => [4, 12, 4], "uv" => [32, 48]],
                ["origin" => [4, 12, -2], "size" => [4, 12, 4], "uv" => [48, 48], "inflate" => 0.25]
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
