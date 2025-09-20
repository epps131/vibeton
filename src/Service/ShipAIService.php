<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ShipAIService
{
    private HttpClientInterface $client;
    private string $authToken;
    private array $mapInfo = [];
    private array $obstacles = [];
    private bool $initialized = false;
    private array $shipStates = [];
    private array $enemyPositions = [];
    private array $zoneInfo = [];
    private array $globalTargets = [];
    private int $lastGlobalScan = 0;
    private int $lastScanTick = 0;
    private int $lastZoneUpdate = 0;

    // Константы для направлений
    private const DIRECTIONS = [
        'north' => ['dx' => 0, 'dy' => -1, 'angle' => -M_PI/2],
        'south' => ['dx' => 0, 'dy' => 1, 'angle' => M_PI/2],
        'east' => ['dx' => 1, 'dy' => 0, 'angle' => 0],
        'west' => ['dx' => -1, 'dy' => 0, 'angle' => M_PI]
    ];

    public function __construct(string $authToken)
    {
        $this->client = HttpClient::create();
        $this->authToken = $authToken;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $response = $this->client->request('GET', 'https://games-test.datsteam.dev/api/map', [
            'headers' => [
                'Accept' => 'application/json',
                'X-Auth-Token' => $this->authToken
            ]
        ]);

        $mapData = json_decode($response->getContent(), true);

        $this->mapInfo = [
            'width' => floatval($mapData['map'][0] ?? 1000),
            'height' => floatval($mapData['map'][1] ?? 1000)
        ];

        $this->obstacles = $mapData['obstacles'] ?? [];
        $this->initialized = true;
    }

    public function getGameState(): array
    {
        $response = $this->client->request('GET', 'https://games-test.datsteam.dev/api/scan', [
            'headers' => [
                'Accept' => 'application/json',
                'X-Auth-Token' => $this->authToken
            ]
        ]);

        $data = json_decode($response->getContent(), true);
        $this->lastScanTick = intval($data['tick'] ?? 0);

        // Обновляем информацию о зоне
        if (isset($data['zone'])) {
            $this->zoneInfo = [
                'centerX' => floatval($data['zone']['x'] ?? 0),
                'centerY' => floatval($data['zone']['y'] ?? 0),
                'radius' => floatval($data['zone']['radius'] ?? 1000),
                'lastUpdate' => $this->lastScanTick
            ];
            $this->lastZoneUpdate = $this->lastScanTick;
        }

        return $data;
    }

    public function sendCommands(array $commands): array
    {
        $payload = ['ships' => $commands];

        $response = $this->client->request('POST', 'https://games-test.datsteam.dev/api/shipCommand', [
            'headers' => [
                'Accept' => 'application/json',
                'X-Auth-Token' => $this->authToken
            ],
            'json' => $payload
        ]);

        return json_decode($response->getContent(), true);
    }

    public function makeDecisions(array $gameState): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $commands = [];
        $myShips = $gameState['myShips'] ?? [];
        $enemyShips = $gameState['enemyShips'] ?? [];
        $tick = intval($gameState['tick'] ?? 0);

        // Обновляем глобальные цели каждые 10 тиков
        if ($tick - $this->lastGlobalScan > 10) {
            $this->updateGlobalTargets($enemyShips, $tick);
            $this->lastGlobalScan = $tick;
        }

        // Обновляем позиции врагов
        $this->updateEnemyPositions($enemyShips, $tick);

        // Инициализируем состояния кораблей
        $this->initializeShipStates($myShips, $tick);

        // Распределяем цели между кораблями
        $this->assignTargets($myShips, $enemyShips, $tick);

        foreach ($myShips as $index => $ship) {
            $ship = $this->normalizeShipData($ship);

            if ($ship['hp'] <= 0) continue;

            $command = [
                'id' => $ship['id'],
                'acceleration' => 0,
                'rotate' => 0,
                'cannonShoot' => null
            ];

            $command = $this->handleShipLogic($ship, $enemyShips, $command, $tick, $index);
            $commands[] = $command;
        }

        return $commands;
    }

    private function updateGlobalTargets(array $enemyShips, int $tick): void
    {
        $this->globalTargets = [];

        foreach ($enemyShips as $enemy) {
            $normalizedEnemy = $this->normalizeShipData($enemy);
            $this->globalTargets[] = [
                'enemy' => $normalizedEnemy,
                'priority' => $this->calculateTargetPriority($normalizedEnemy),
                'lastSeen' => $tick
            ];
        }

        // Сортируем по приоритету (самые опасные цели первыми)
        usort($this->globalTargets, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    private function calculateTargetPriority(array $enemy): int
    {
        // Большие корабли - более приоритетные цели
        $sizePriority = match($this->estimateEnemySize($enemy)) {
            5 => 100,
            4 => 80,
            3 => 60,
            2 => 40,
            default => 20
        };

        // Близкие цели более приоритетны
        $distancePriority = 0;
        foreach ($this->shipStates as $shipId => $state) {
            if (isset($state['lastPosition'])) {
                $distance = $this->calculateDistance($state['lastPosition'], $enemy);
                $distancePriority += max(0, 100 - $distance / 10);
            }
        }

        return $sizePriority + $distancePriority;
    }

    private function estimateEnemySize(array $enemy): int
    {
        // Оцениваем размер врага по его скорости и другим параметрам
        $speed = $enemy['speed'] ?? 0;

        if ($speed <= 2) return 5; // Самые медленные - крупные
        if ($speed <= 3) return 4;
        if ($speed <= 4) return 3;
        return 2; // Быстрые - мелкие
    }

    private function assignTargets(array $myShips, array $enemyShips, int $tick): void
    {
        if (empty($this->globalTargets)) return;

        $availableShips = [];

        // Собираем доступные корабли
        foreach ($myShips as $ship) {
            $ship = $this->normalizeShipData($ship);
            if ($ship['hp'] > 0) {
                $availableShips[] = $ship;
            }
        }

        // Распределяем цели
        foreach ($this->globalTargets as $targetIndex => $targetData) {
            $enemy = $targetData['enemy'];

            // Проверяем, что цель в безопасной зоне
            if ($this->isInDangerZone($enemy)) {
                continue;
            }

            // Находим лучшего корабля для этой цели
            $bestShip = null;
            $bestScore = -1;

            foreach ($availableShips as $ship) {
                $distance = $this->calculateDistance($ship, $enemy);
                $role = $this->shipStates[$ship['id']]['role'] ?? 'scout';

                // Оценка пригодности корабля для цели
                $score = $this->calculateAssignmentScore($ship, $enemy, $role, $distance);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestShip = $ship;
                }
            }

            if ($bestShip) {
                $this->shipStates[$bestShip['id']]['assignedTarget'] = $enemy;
                $this->shipStates[$bestShip['id']]['targetPriority'] = $targetData['priority'];

                // Убираем корабль из доступных
                $availableShips = array_filter($availableShips, fn($s) => $s['id'] !== $bestShip['id']);
            }

            if (empty($availableShips)) break;
        }
    }

    private function calculateAssignmentScore(array $ship, array $enemy, string $role, float $distance): float
    {
        $score = 1000 - $distance; // Близость к цели

        // Бонусы в зависимости от роли
        switch ($role) {
            case 'attacker':
                $score += 500; // Атакующие предпочтительнее
                break;
            case 'support':
                $score += 300;
                break;
            case 'scout':
                $score += 100;
                break;
        }

        // Штраф за уже имеющуюся цель
        if (isset($this->shipStates[$ship['id']]['assignedTarget'])) {
            $score -= 200;
        }

        return $score;
    }

    private function normalizeShipData(array $ship): array
    {
        return [
            'id' => $ship['id'],
            'x' => floatval($ship['x'] ?? 0),
            'y' => floatval($ship['y'] ?? 0),
            'hp' => intval($ship['hp'] ?? 0),
            'speed' => floatval($ship['speed'] ?? 0),
            'direction' => $ship['direction'] ?? 'north',
            'cannonCooldownLeft' => intval($ship['cannonCooldownLeft'] ?? 0),
            'cannonRadius' => floatval($ship['cannonRadius'] ?? 0),
            'scanRadius' => floatval($ship['scanRadius'] ?? 0),
            'size' => intval($ship['size'] ?? 2)
        ];
    }

    private function initializeShipStates(array $ships, int $tick): void
    {
        foreach ($ships as $ship) {
            $shipId = $ship['id'];
            if (!isset($this->shipStates[$shipId])) {
                $this->shipStates[$shipId] = [
                    'role' => $this->assignShipRole($ship),
                    'lastAttackTick' => 0,
                    'lastDirectionChange' => 0,
                    'currentTarget' => null,
                    'retreating' => false,
                    'formationPosition' => null,
                    'lastPosition' => ['x' => floatval($ship['x']), 'y' => floatval($ship['y'])],
                    'assignedTarget' => null,
                    'targetPriority' => 0,
                    'retreatStartTick' => 0
                ];
            } else {
                // Обновляем последнюю позицию
                $this->shipStates[$shipId]['lastPosition'] = [
                    'x' => floatval($ship['x']),
                    'y' => floatval($ship['y'])
                ];
            }
        }
    }

    private function assignShipRole(array $ship): string
    {
        $size = $this->getShipSize($ship);

        if ($size >= 4) return 'attacker';
        if ($size === 3) return 'support';
        return 'scout';
    }

    private function getShipSize(array $ship): int
    {
        $shipId = strval($ship['id']);
        $lastChar = substr($shipId, -1);

        return match($lastChar) {
            '0', '1', '2', '3' => 2,
            '4', '5', '6' => 3,
            '7', '8' => 4,
            '9' => 5,
            default => intval($ship['size'] ?? 2)
        };
    }

    private function updateEnemyPositions(array $enemyShips, int $tick): void
    {
        foreach ($enemyShips as $enemy) {
            $this->enemyPositions[$enemy['id']] = [
                'x' => floatval($enemy['x'] ?? 0),
                'y' => floatval($enemy['y'] ?? 0),
                'tick' => $tick,
                'direction' => $enemy['direction'] ?? 'north',
                'speed' => floatval($enemy['speed'] ?? 0),
                'size' => intval($enemy['size'] ?? 2)
            ];
        }
    }

    private function isInDangerZone(array $position): bool
    {
        if (empty($this->zoneInfo)) {
            return false;
        }

        $distanceToCenter = $this->calculateDistance($position, [
            'x' => $this->zoneInfo['centerX'],
            'y' => $this->zoneInfo['centerY']
        ]);

        return $distanceToCenter > $this->zoneInfo['radius'];
    }

    private function isApproachingDangerZone(array $ship): bool
    {
        if (empty($this->zoneInfo)) {
            return false;
        }

        $currentDistance = $this->getDistanceToSafety($ship);

        // Предсказываем позицию через несколько ходов
        $predictedPosition = $this->predictPosition($ship, 5);
        $predictedDistance = $this->calculateDistance($predictedPosition, [
                'x' => $this->zoneInfo['centerX'],
                'y' => $this->zoneInfo['centerY']
            ]) - $this->zoneInfo['radius'];

        return $predictedDistance < 50; // Если через 5 ходов окажемся близко к краю
    }

    private function getDistanceToSafety(array $ship): float
    {
        if (empty($this->zoneInfo)) {
            return 0;
        }

        $distanceToCenter = $this->calculateDistance($ship, [
            'x' => $this->zoneInfo['centerX'],
            'y' => $this->zoneInfo['centerY']
        ]);

        return $distanceToCenter - $this->zoneInfo['radius'];
    }

    private function getSafeRetreatPosition(array $ship): array
    {
        // Двигаемся прямо к центру безопасной зоны
        return [
            'x' => $this->zoneInfo['centerX'],
            'y' => $this->zoneInfo['centerY']
        ];
    }

    private function getPreventiveSafePosition(array $ship): array
    {
        // Двигаемся в сторону центра, но не обязательно прямо к нему
        $directionToCenter = $this->getDirectionToPoint(
            $ship['x'], $ship['y'],
            $this->zoneInfo['centerX'], $this->zoneInfo['centerY']
        );

        // Выбираем точку на 80% радиуса от центра
        $safetyMargin = $this->zoneInfo['radius'] * 0.8;
        $angle = $this->directionToAngle($directionToCenter);

        return [
            'x' => $this->zoneInfo['centerX'] - cos(deg2rad($angle)) * $safetyMargin,
            'y' => $this->zoneInfo['centerY'] - sin(deg2rad($angle)) * $safetyMargin
        ];
    }


    private function moveToPreventDanger(array $ship, array $safePosition, array $command, int $tick): array
    {
        // Профилактическое движение от шторма
        $distance = $this->calculateDistance($ship, $safePosition);

        if ($distance > 50) {
            $command['acceleration'] = 1;
        } else {
            $command['acceleration'] = 0;
        }

        $desiredDirection = $this->getDirectionToPoint(
            $ship['x'], $ship['y'],
            $safePosition['x'], $safePosition['y']
        );

        $rotation = $this->calculateOptimalRotation($ship['direction'], $desiredDirection);
        $command['rotate'] = $rotation;

        return $command;
    }

    private function getEnemiesOnRetreatPath(array $ship, array $safePosition): array
    {
        $enemies = [];
        $direction = $this->getDirectionToPoint($ship['x'], $ship['y'], $safePosition['x'], $safePosition['y']);

        foreach ($this->enemyPositions as $enemy) {
            $enemyDirection = $this->getDirectionToPoint($ship['x'], $ship['y'], $enemy['x'], $enemy['y']);
            if ($enemyDirection === $direction) {
                $enemies[] = $enemy;
            }
        }

        return $enemies;
    }

    private function predictPosition(array $ship, int $ticksAhead): array
    {
        $direction = self::DIRECTIONS[$ship['direction']] ?? ['dx' => 0, 'dy' => 0];
        $predictedX = $ship['x'] + $direction['dx'] * $ship['speed'] * $ticksAhead;
        $predictedY = $ship['y'] + $direction['dy'] * $ship['speed'] * $ticksAhead;

        return [
            'x' => $predictedX,
            'y' => $predictedY
        ];
    }

    private function directionToAngle(string $direction): float
    {
        return match($direction) {
            'north' => 270,
            'south' => 90,
            'east' => 0,
            'west' => 180,
            default => 0
        };
    }

    private function isTargetValid(array $target, int $currentTick): bool
    {
        // Цель действительна если она не слишком старая
        $targetAge = $currentTick - ($this->enemyPositions[$target['id']]['tick'] ?? 0);
        return $targetAge < 20; // 20 тиков - максимальный возраст цели
    }

    private function getVisibleEnemies(array $ship, array $enemies): array
    {
        $visible = [];
        foreach ($enemies as $enemy) {
            $normalizedEnemy = $this->normalizeShipData($enemy);
            $distance = $this->calculateDistance($ship, $normalizedEnemy);

            if ($distance <= $ship['scanRadius']) {
                $visible[] = $normalizedEnemy;
            }
        }
        return $visible;
    }

    private function executeRoleBehavior(array $ship, array $command, int $tick, int $shipIndex, string $role): array
    {
        $shipState = &$this->shipStates[$ship['id']];

        switch ($role) {
            case 'attacker':
                // Атакующие ищут врагов в центре
                $target = $this->getAggressiveSearchPosition($ship, $shipIndex);
                break;

            case 'support':
                // Поддержка движется к атакующим
                $target = $this->getSupportPosition($ship, $tick);
                break;

            case 'scout':
            default:
                // Разведчики активно ищут врагов
                $target = $this->getScoutingPosition($ship, $shipIndex, $tick);
                break;
        }

        $shipState['currentTarget'] = $target;
        return $this->moveToPosition($ship, $target, $command, $tick);
    }

    private function getAggressiveSearchPosition(array $ship, int $shipIndex): array
    {
        // Атакующие двигаются к последним известным позициям врагов
        if (!empty($this->globalTargets)) {
            $latestTarget = $this->globalTargets[0]['enemy'];
            return [
                'x' => $latestTarget['x'],
                'y' => $latestTarget['y']
            ];
        }

        // Если врагов не видели - двигаемся к центру безопасной зоны
        if (!empty($this->zoneInfo)) {
            return [
                'x' => $this->zoneInfo['centerX'],
                'y' => $this->zoneInfo['centerY']
            ];
        }

        // Или к центру карты
        return $this->getCenterPosition();
    }

    private function getSupportPosition(array $ship, int $tick): array
    {
        // Поддержка движется к ближайшему атакующему
        $nearestAttacker = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($this->shipStates as $shipId => $state) {
            if ($state['role'] === 'attacker' && isset($state['lastPosition'])) {
                $distance = $this->calculateDistance($ship, $state['lastPosition']);
                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $nearestAttacker = $state['lastPosition'];
                }
            }
        }

        return $nearestAttacker ?? $this->getCenterPosition();
    }

    private function getScoutingPosition(array $ship, int $shipIndex, int $tick): array
    {
        // Разведчики движутся к краям безопасной зоны
        if (!empty($this->zoneInfo)) {
            $angle = deg2rad(($tick / 2 + $shipIndex * 90) % 360);
            $safetyMargin = $this->zoneInfo['radius'] * 0.9;

            return [
                'x' => $this->zoneInfo['centerX'] + cos($angle) * $safetyMargin,
                'y' => $this->zoneInfo['centerY'] + sin($angle) * $safetyMargin
            ];
        }

        // Если зоны нет - обычное патрулирование
        $angle = deg2rad(($tick / 2 + $shipIndex * 90) % 360);
        $distance = 500;

        return [
            'x' => $this->mapInfo['width'] / 2 + cos($angle) * $distance,
            'y' => $this->mapInfo['height'] / 2 + sin($angle) * $distance
        ];
    }

    private function moveToPosition(array $ship, array $target, array $command, int $tick): array
    {
        $distance = $this->calculateDistance($ship, $target);

        // Контроль скорости
        if ($distance > 200) {
            $command['acceleration'] = 1;
        } elseif ($distance < 50) {
            $command['acceleration'] = -1;
        }

        // Поворот к цели
        $desiredDirection = $this->getDirectionToPoint($ship['x'], $ship['y'], $target['x'], $target['y']);
        $rotation = $this->calculateOptimalRotation($ship['direction'], $desiredDirection);

        $lastRotation = $this->shipStates[$ship['id']]['lastDirectionChange'] ?? 0;
        if ($tick - $lastRotation > 2 && $rotation !== 0) {
            $command['rotate'] = $rotation;
            $this->shipStates[$ship['id']]['lastDirectionChange'] = $tick;
        }

        return $command;
    }

    private function calculateAimPoint(array $ship, array $enemy): array
    {
        // Простое предсказание позиции врага
        $enemySpeed = floatval($enemy['speed'] ?? 0);
        $enemyDirection = $enemy['direction'] ?? 'north';

        // Время полета снаряда (примерно)
        $travelTime = max(0.1, $this->calculateDistance($ship, $enemy) / 100);

        // Предсказываем позицию врага
        $predictedX = floatval($enemy['x']);
        $predictedY = floatval($enemy['y']);

        $movement = $enemySpeed * $travelTime;

        switch ($enemyDirection) {
            case 'north':
                $predictedY -= $movement;
                break;
            case 'south':
                $predictedY += $movement;
                break;
            case 'east':
                $predictedX += $movement;
                break;
            case 'west':
                $predictedX -= $movement;
                break;
        }

        // Ограничиваем координаты картой
        $predictedX = max(0, min($this->mapInfo['width'], $predictedX));
        $predictedY = max(0, min($this->mapInfo['height'], $predictedY));

        return [
            'x' => (int)round($predictedX),
            'y' => (int)round($predictedY)
        ];
    }

    private function findNearestEnemy(array $ship, array $enemies): ?array
    {
        if (empty($enemies)) return null;

        $nearest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($enemies as $enemy) {
            $normalizedEnemy = $this->normalizeShipData($enemy);
            $distance = $this->calculateDistance($ship, $normalizedEnemy);

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearest = $normalizedEnemy;
            }
        }

        return $nearest;
    }

    private function calculateDistance(array $a, array $b): float
    {
        $dx = floatval($a['x']) - floatval($b['x']);
        $dy = floatval($a['y']) - floatval($b['y']);

        return sqrt($dx * $dx + $dy * $dy);
    }

    private function getDirectionToTarget(array $ship, array $target): string
    {
        $dx = floatval($target['x']) - floatval($ship['x']);
        $dy = floatval($target['y']) - floatval($ship['y']);

        if (abs($dx) > abs($dy)) {
            return $dx > 0 ? 'east' : 'west';
        } else {
            return $dy > 0 ? 'south' : 'north';
        }
    }

    private function getDirectionToPoint(float $fromX, float $fromY, float $toX, float $toY): string
    {
        $dx = $toX - $fromX;
        $dy = $toY - $fromY;

        if (abs($dx) > abs($dy)) {
            return $dx > 0 ? 'east' : 'west';
        } else {
            return $dy > 0 ? 'south' : 'north';
        }
    }

    private function calculateOptimalRotation(string $currentDir, string $desiredDir): int
    {
        $directions = ['north', 'east', 'south', 'west'];
        $currentIdx = array_search($currentDir, $directions);
        $desiredIdx = array_search($desiredDir, $directions);

        if ($currentIdx === false || $desiredIdx === false) {
            return 0;
        }

        if ($currentIdx === $desiredIdx) {
            return 0;
        }

        $diff = ($desiredIdx - $currentIdx + 4) % 4;
        return $diff === 1 ? 90 : -90;
    }

    private function getCenterPosition(): array
    {
        return [
            'x' => floatval($this->mapInfo['width'] / 2),
            'y' => floatval($this->mapInfo['height'] / 2)
        ];
    }

    private function handleShipLogic(array $ship, array $enemyShips, array $command, int $tick, int $shipIndex): array
    {
        $shipState = &$this->shipStates[$ship['id']];
        $role = $shipState['role'];

        // ВЫСШИЙ ПРИОРИТЕТ: Проверяем безопасную зону
        $isInDanger = $this->isInDangerZone($ship);
        $distanceToSafety = $this->getDistanceToSafety($ship);

        if ($isInDanger && !$shipState['retreating']) {
            $shipState['retreating'] = true;
            $shipState['currentTarget'] = $this->getSafeRetreatPosition($ship);
            $shipState['retreatStartTick'] = $tick;
        }

        // Если отступаем от шторма
        if ($shipState['retreating']) {
            if (!$isInDanger) {
                $shipState['retreating'] = false;
                $shipState['currentTarget'] = null;
            } else {
                return $this->emergencyRetreat($ship, $shipState['currentTarget'], $command, $tick, $distanceToSafety);
            }
        }

        // ВЫСОКИЙ ПРИОРИТЕТ: Предотвращение столкновений с границами и препятствиями
        $collisionRisk = $this->checkCollisionRisk($ship, $tick);
        if ($collisionRisk['riskLevel'] > 0) {
            return $this->avoidCollision($ship, $collisionRisk, $command, $tick);
        }

        // Средний приоритет: Предотвращение попадания в шторм
        $isApproachingDanger = $this->isApproachingDangerZone($ship);
        if ($isApproachingDanger && !$shipState['retreating']) {
            $safePosition = $this->getPreventiveSafePosition($ship);

            return $this->moveToPreventDanger($ship, $safePosition, $command, $tick);
        }

        // Низкий приоритет: Боевые действия
        $assignedTarget = $shipState['assignedTarget'] ?? null;
        if ($assignedTarget && $this->isTargetValid($assignedTarget, $tick)) {
            $distance = $this->calculateDistance($ship, $assignedTarget);

            // Проверяем, что цель не ведет нас в шторм или к столкновению
            $targetInDanger = $this->isInDangerZone($assignedTarget);
            $pathSafe = $this->isPathSafe($ship, $assignedTarget);

            if (!$targetInDanger && $pathSafe) {
                return $this->engageTarget($ship, $assignedTarget, $command, $tick, $distance, $role);
            }
        }

        // Ищем ближайшего видимого врага вне шторма
        $visibleEnemies = $this->getVisibleEnemies($ship, $enemyShips);
        $safeEnemies = array_filter($visibleEnemies, fn($enemy) => !$this->isInDangerZone($enemy) && $this->isPathSafe($ship, $enemy)
        );
        $nearestEnemy = $this->findNearestEnemy($ship, $safeEnemies);

        if ($nearestEnemy) {
            $distance = $this->calculateDistance($ship, $nearestEnemy);

            return $this->engageTarget($ship, $nearestEnemy, $command, $tick, $distance, $role);
        }

        // Если врагов нет - действуем по роли, учитывая зону и безопасность
        return $this->executeRoleBehavior($ship, $command, $tick, $shipIndex, $role);
    }

    private function checkCollisionRisk(array $ship, int $tick): array
    {
        $riskLevel = 0;
        $riskDirection = '';
        $distanceToRisk = PHP_FLOAT_MAX;
        $riskType = '';

        // Проверяем границы карты
        $borderRisk = $this->checkBorderCollisionRisk($ship);
        if ($borderRisk['riskLevel'] > $riskLevel) {
            $riskLevel = $borderRisk['riskLevel'];
            $riskDirection = $borderRisk['direction'];
            $distanceToRisk = $borderRisk['distance'];
            $riskType = 'border';
        }

        // Проверяем препятствия
        $obstacleRisk = $this->checkObstacleCollisionRisk($ship);
        if ($obstacleRisk['riskLevel'] > $riskLevel) {
            $riskLevel = $obstacleRisk['riskLevel'];
            $riskDirection = $obstacleRisk['direction'];
            $distanceToRisk = $obstacleRisk['distance'];
            $riskType = 'obstacle';
        }

        // Проверяем необходимость поворота при высокой скорости
        $turnRisk = $this->checkTurnRisk($ship);
        if ($turnRisk['riskLevel'] > $riskLevel) {
            $riskLevel = $turnRisk['riskLevel'];
            $riskDirection = $turnRisk['direction'];
            $riskType = 'turn';
        }

        return [
            'riskLevel' => $riskLevel,
            'direction' => $riskDirection,
            'distance' => $distanceToRisk,
            'type' => $riskType
        ];
    }

    private function checkBorderCollisionRisk(array $ship): array
    {
        $direction = self::DIRECTIONS[$ship['direction']] ?? ['dx' => 0, 'dy' => 0];
        $predictedX = $ship['x'];
        $predictedY = $ship['y'];

        $stoppingDistance = $this->calculateStoppingDistance($ship);

        // Предсказываем позицию через время остановки
        $predictedX += $direction['dx'] * $stoppingDistance;
        $predictedY += $direction['dy'] * $stoppingDistance;

        $riskLevel = 0;
        $riskDirection = '';
        $minDistance = PHP_FLOAT_MAX;

        // Проверяем все границы
        if ($predictedX < 50) {
            $distance = abs($predictedX);
            $riskLevel = max($riskLevel, $this->calculateRiskLevel($distance, $stoppingDistance));
            $riskDirection = 'west';
            $minDistance = min($minDistance, $distance);
        }

        if ($predictedX > $this->mapInfo['width'] - 50) {
            $distance = $this->mapInfo['width'] - $predictedX;
            $riskLevel = max($riskLevel, $this->calculateRiskLevel($distance, $stoppingDistance));
            $riskDirection = 'east';
            $minDistance = min($minDistance, $distance);
        }

        if ($predictedY < 50) {
            $distance = abs($predictedY);
            $riskLevel = max($riskLevel, $this->calculateRiskLevel($distance, $stoppingDistance));
            $riskDirection = 'north';
            $minDistance = min($minDistance, $distance);
        }

        if ($predictedY > $this->mapInfo['height'] - 50) {
            $distance = $this->mapInfo['height'] - $predictedY;
            $riskLevel = max($riskLevel, $this->calculateRiskLevel($distance, $stoppingDistance));
            $riskDirection = 'south';
            $minDistance = min($minDistance, $distance);
        }

        return [
            'riskLevel' => $riskLevel,
            'direction' => $riskDirection,
            'distance' => $minDistance
        ];
    }

    private function checkObstacleCollisionRisk(array $ship): array
    {
        $direction = self::DIRECTIONS[$ship['direction']] ?? ['dx' => 0, 'dy' => 0];
        $stoppingDistance = $this->calculateStoppingDistance($ship);

        $riskLevel = 0;
        $riskDirection = '';
        $minDistance = PHP_FLOAT_MAX;

        foreach ($this->obstacles as $obstacle) {
            $startX = $obstacle['start'][0];
            $startY = $obstacle['start'][1];

            foreach ($obstacle['map'] as $localY => $row) {
                foreach ($row as $localX => $cell) {
                    if ($cell === 1) {
                        $obstacleX = $startX + $localX;
                        $obstacleY = $startY + $localY;

                        // Проверяем расстояние до препятствия по текущему направлению
                        $distance = $this->calculateDistanceToObstacle($ship, $obstacleX, $obstacleY, $direction);

                        if ($distance < $stoppingDistance * 1.5) {
                            $currentRisk = $this->calculateRiskLevel($distance, $stoppingDistance);
                            if ($currentRisk > $riskLevel) {
                                $riskLevel = $currentRisk;
                                $minDistance = $distance;

                                // Определяем направление от препятствия
                                $dx = $obstacleX - $ship['x'];
                                $dy = $obstacleY - $ship['y'];

                                if (abs($dx) > abs($dy)) {
                                    $riskDirection = $dx > 0 ? 'east' : 'west';
                                } else {
                                    $riskDirection = $dy > 0 ? 'south' : 'north';
                                }
                            }
                        }
                    }
                }
            }
        }

        return [
            'riskLevel' => $riskLevel,
            'direction' => $riskDirection,
            'distance' => $minDistance
        ];
    }

    private function checkTurnRisk(array $ship): array
    {
        // Если скорость слишком высока для поворота, считаем это риском
        if ($ship['speed'] > 2) {
            return [
                'riskLevel' => 1, // Низкий уровень риска, но нужно снизить скорость
                'direction' => $ship['direction'],
                'distance' => PHP_FLOAT_MAX
            ];
        }

        return [
            'riskLevel' => 0,
            'direction' => '',
            'distance' => PHP_FLOAT_MAX
        ];
    }

    private function calculateStoppingDistance(array $ship): float
    {
        // Рассчитываем дистанцию остановки: скорость^2 / (2 * ускорение)
        // Предполагаем максимальное торможение -1
        if ($ship['speed'] <= 0) {
            return 0;
        }

        return pow($ship['speed'], 2) / 2;
    }

    private function calculateRiskLevel(float $distance, float $stoppingDistance): float
    {
        if ($distance <= $stoppingDistance) {
            return 3; // Критический риск - немедленное действие
        } elseif ($distance <= $stoppingDistance * 2) {
            return 2; // Высокий риск
        } elseif ($distance <= $stoppingDistance * 3) {
            return 1; // Средний риск
        }

        return 0; // Нет риска
    }

    private function calculateDistanceToObstacle(array $ship, float $obstacleX, float $obstacleY, array $direction): float
    {
        // Упрощенный расчет расстояния до препятствия по текущему направлению
        $dx = $obstacleX - $ship['x'];
        $dy = $obstacleY - $ship['y'];

        // Проекция на текущее направление движения
        $projection = $dx * $direction['dx'] + $dy * $direction['dy'];

        if ($projection <= 0) {
            return PHP_FLOAT_MAX; // Препятствие позади
        }

        return abs($projection);
    }

    private function avoidCollision(array $ship, array $collisionRisk, array $command, int $tick): array
    {
        $riskLevel = $collisionRisk['riskLevel'];
        $riskDirection = $collisionRisk['direction'];
        $riskType = $collisionRisk['type'];

        // Критический риск - экстренное торможение
        if ($riskLevel >= 3) {
            $command['acceleration'] = -1;
            $command['rotate'] = 0;

            return $command;
        }

        // Высокий риск - торможение и попытка поворота
        if ($riskLevel >= 2) {
            $command['acceleration'] = -1;

            // Пытаемся повернуть только если скорость позволяет
            if ($ship['speed'] <= 2) {
                $safeDirection = $this->findSafeDirection($ship, $riskDirection);
                $rotation = $this->calculateOptimalRotation($ship['direction'], $safeDirection);
                $command['rotate'] = $rotation;
            } else {
                $command['rotate'] = 0;
            }

            return $command;
        }

        // Средний риск - плавное торможение
        if ($riskLevel >= 1) {
            $command['acceleration'] = -1;
            $command['rotate'] = 0;

            return $command;
        }

        return $command;
    }

    private function findSafeDirection(array $ship, string $dangerDirection): string
    {
        $currentDirection = $ship['direction'];
        $directions = ['north', 'east', 'south', 'west'];

        // Ищем безопасное направление, противоположное опасному
        $safeDirections = array_filter($directions, fn($dir) => $dir !== $dangerDirection);

        // Предпочитаем направления, которые ближе к текущему
        usort($safeDirections, function ($a, $b) use ($currentDirection, $directions) {
            $diffA = abs(array_search($a, $directions) - array_search($currentDirection, $directions));
            $diffB = abs(array_search($b, $directions) - array_search($currentDirection, $directions));

            return $diffA <=> $diffB;
        });

        // Проверяем безопасность каждого направления
        foreach ($safeDirections as $direction) {
            if ($this->isDirectionSafe($ship, $direction)) {
                return $direction;
            }
        }

        // Если все направления опасны, выбираем наименее опасное
        return $safeDirections[0] ?? $currentDirection;
    }

    private function isDirectionSafe(array $ship, string $direction): bool
    {
        $testShip = $ship;
        $testShip['direction'] = $direction;

        // Проверяем границы
        $borderRisk = $this->checkBorderCollisionRisk($testShip);
        if ($borderRisk['riskLevel'] > 1) {
            return false;
        }

        // Проверяем препятствия
        $obstacleRisk = $this->checkObstacleCollisionRisk($testShip);
        if ($obstacleRisk['riskLevel'] > 1) {
            return false;
        }

        return true;
    }

    private function isPathSafe(array $from, array $to): bool
    {
        // Проверяем безопасность пути к цели
        $direction = $this->getDirectionToPoint($from['x'], $from['y'], $to['x'], $to['y']);
        $testShip = $from;
        $testShip['direction'] = $direction;

        $borderRisk = $this->checkBorderCollisionRisk($testShip);
        $obstacleRisk = $this->checkObstacleCollisionRisk($testShip);

        return $borderRisk['riskLevel'] <= 1 && $obstacleRisk['riskLevel'] <= 1;
    }

    private function engageTarget(array $ship, array $target, array $command, int $tick, float $distance, string $role): array
    {
        // Сначала проверяем, можем ли мы безопасно повернуть
        if ($ship['speed'] > 2) {
            // Скорость слишком высока для поворота - сначала тормозим
            $command['acceleration'] = -1;
            $command['rotate'] = 0;

            return $command;
        }

        // Агрессивное преследование цели
        $optimalRange = $ship['cannonRadius'] * 0.8;

        // Всегда стреляем если можем
        if ($ship['cannonCooldownLeft'] === 0 && $distance <= $ship['cannonRadius']) {
            $command['cannonShoot'] = $this->calculateAimPoint($ship, $target);
            $this->shipStates[$ship['id']]['lastAttackTick'] = $tick;
        }

        // Агрессивное движение к цели
        if ($distance > $optimalRange) {
            // Ускоряемся к цели, но проверяем безопасность
            $command['acceleration'] = $this->canAccelerateSafely($ship) ? 1 : 0;
        } elseif ($distance < $optimalRange * 0.6) {
            // Слишком близко - отступаем
            $command['acceleration'] = -1;
        } else {
            // Держим оптимальную дистанцию
            $command['acceleration'] = 0;
        }

        // Поворачиваем только если это безопасно
        $desiredDirection = $this->getDirectionToTarget($ship, $target);
        if ($this->isDirectionSafe($ship, $desiredDirection)) {
            $rotation = $this->calculateOptimalRotation($ship['direction'], $desiredDirection);
            $command['rotate'] = $rotation;
        } else {
            // Ищем безопасное направление
            $safeDirection = $this->findSafeDirection($ship, '');
            $rotation = $this->calculateOptimalRotation($ship['direction'], $safeDirection);
            $command['rotate'] = $rotation;
        }

        return $command;
    }

    private function canAccelerateSafely(array $ship): bool
    {
        // Проверяем, безопасно ли ускоряться
        $testShip = $ship;
        $testShip['speed'] = min($ship['speed'] + 1, $ship['maxSpeed'] ?? 8);

        $borderRisk = $this->checkBorderCollisionRisk($testShip);
        $obstacleRisk = $this->checkObstacleCollisionRisk($testShip);

        return $borderRisk['riskLevel'] <= 1 && $obstacleRisk['riskLevel'] <= 1;
    }

    private function emergencyRetreat(array $ship, array $safePosition, array $command, int $tick, float $distanceToSafety): array
    {
        // Сначала проверяем безопасность пути отступления
        if (!$this->isPathSafe($ship, $safePosition)) {
            // Если путь небезопасен, ищем альтернативный маршрут
            $safePosition = $this->findAlternativeSafePosition($ship);
        }

        // Управляем скоростью в зависимости от срочности
        if ($distanceToSafety < 50) {
            // Критическая ситуация - максимальное ускорение
            $command['acceleration'] = 1;
        } else {
            // Плавное движение к безопасности
            $command['acceleration'] = $this->canAccelerateSafely($ship) ? 1 : 0;
        }

        $desiredDirection = $this->getDirectionToPoint(
            $ship['x'], $ship['y'],
            $safePosition['x'], $safePosition['y']
        );

        // Поворачиваем только если скорость позволяет
        if ($ship['speed'] <= 2) {
            $rotation = $this->calculateOptimalRotation($ship['direction'], $desiredDirection);
            $command['rotate'] = $rotation;
        } else {
            $command['rotate'] = 0;
        }

        return $command;
    }

    private function findAlternativeSafePosition(array $ship): array
    {
        // Ищем альтернативную безопасную позицию
        $directions = ['north', 'east', 'south', 'west'];
        $bestPosition = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($directions as $direction) {
            $testX = $ship['x'] + self::DIRECTIONS[$direction]['dx'] * 100;
            $testY = $ship['y'] + self::DIRECTIONS[$direction]['dy'] * 100;

            // Проверяем границы
            if ($testX < 0 || $testX > $this->mapInfo['width'] ||
                $testY < 0 || $testY > $this->mapInfo['height']) {
                continue;
            }

            // Проверяем препятствия
            $obstacleSafe = true;
            foreach ($this->obstacles as $obstacle) {
                if ($this->isPointInObstacle($testX, $testY, $obstacle)) {
                    $obstacleSafe = false;
                    break;
                }
            }

            if ($obstacleSafe) {
                $distance = $this->calculateDistance($ship, ['x' => $testX, 'y' => $testY]);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestPosition = ['x' => $testX, 'y' => $testY];
                }
            }
        }

        return $bestPosition ?? $this->getSafeRetreatPosition($ship);
    }

    private function isPointInObstacle(float $x, float $y, array $obstacle): bool
    {
        $startX = $obstacle['start'][0];
        $startY = $obstacle['start'][1];

        foreach ($obstacle['map'] as $localY => $row) {
            foreach ($row as $localX => $cell) {
                if ($cell === 1) {
                    $obstacleX = $startX + $localX;
                    $obstacleY = $startY + $localY;

                    if (abs($x - $obstacleX) < 1 && abs($y - $obstacleY) < 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
