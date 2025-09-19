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
    private array $shipPatrolPaths = [];
    private array $shipStates = [];

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
            'width' => $mapData['map'][0],
            'height' => $mapData['map'][1]
        ];

        $this->obstacles = $mapData['obstacles'];
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

        return json_decode($response->getContent(), true);
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
        $tick = $gameState['tick'] ?? 0;

        // Инициализируем пути патрулирования
        $this->initializePatrolPaths($myShips);

        foreach ($myShips as $index => $ship) {
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

    private function initializePatrolPaths(array $ships): void
    {
        if (!empty($this->shipPatrolPaths)) {
            return;
        }

        // Делим карту на сектора для каждого корабля
        $sectors = $this->divideMapIntoSectors(count($ships));

        foreach ($ships as $index => $ship) {
            $sector = $sectors[$index % count($sectors)];
            $this->shipPatrolPaths[$ship['id']] = $this->createPatrolPath($sector, $index);

            // Инициализируем состояние корабля
            $this->shipStates[$ship['id']] = [
                'currentTarget' => null,
                'lastDirectionChange' => 0,
                'patrolPhase' => 0
            ];
        }
    }

    private function divideMapIntoSectors(int $shipCount): array
    {
        $sectors = [];

        // Для небольшого количества кораблей - разные стратегии
        if ($shipCount <= 3) {
            // Мало кораблей - покрываем всю карту
            $sectors = [
                ['x' => $this->mapInfo['width'] * 0.25, 'y' => $this->mapInfo['height'] * 0.25],
                ['x' => $this->mapInfo['width'] * 0.75, 'y' => $this->mapInfo['height'] * 0.25],
                ['x' => $this->mapInfo['width'] * 0.75, 'y' => $this->mapInfo['height'] * 0.75],
                ['x' => $this->mapInfo['width'] * 0.25, 'y' => $this->mapInfo['height'] * 0.75]
            ];
        } else {
            // Много кораблей - равномерное распределение
            $rows = ceil(sqrt($shipCount));
            $cols = ceil($shipCount / $rows);

            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $cols; $col++) {
                    if (count($sectors) < $shipCount) {
                        $sectors[] = [
                            'x' => ($col + 0.5) * $this->mapInfo['width'] / $cols,
                            'y' => ($row + 0.5) * $this->mapInfo['height'] / $rows
                        ];
                    }
                }
            }
        }

        return $sectors;
    }

    private function createPatrolPath(array $sector, int $shipIndex): array
    {
        // Разные типы патрулирования для разных кораблей
        $pathType = $shipIndex % 6;

        switch ($pathType) {
            case 0: // Большой круг
                return [
                    'type' => 'circle',
                    'centerX' => $sector['x'],
                    'centerY' => $sector['y'],
                    'radius' => 300,
                    'speed' => 4
                ];

            case 1: // Маленький круг
                return [
                    'type' => 'circle',
                    'centerX' => $sector['x'],
                    'centerY' => $sector['y'],
                    'radius' => 150,
                    'speed' => 3
                ];

            case 2: // Горизонтальное движение
                return [
                    'type' => 'horizontal',
                    'minX' => max(100, $sector['x'] - 200),
                    'maxX' => min($this->mapInfo['width'] - 100, $sector['x'] + 200),
                    'y' => $sector['y'],
                    'speed' => 5
                ];

            case 3: // Вертикальное движение
                return [
                    'type' => 'vertical',
                    'x' => $sector['x'],
                    'minY' => max(100, $sector['y'] - 200),
                    'maxY' => min($this->mapInfo['height'] - 100, $sector['y'] + 200),
                    'speed' => 5
                ];

            case 4: // Диагональное движение
                return [
                    'type' => 'diagonal',
                    'startX' => max(100, $sector['x'] - 250),
                    'startY' => max(100, $sector['y'] - 250),
                    'endX' => min($this->mapInfo['width'] - 100, $sector['x'] + 250),
                    'endY' => min($this->mapInfo['height'] - 100, $sector['y'] + 250),
                    'speed' => 4
                ];

            case 5: // Случайное блуждание
            default:
                return [
                    'type' => 'random',
                    'centerX' => $sector['x'],
                    'centerY' => $sector['y'],
                    'radius' => 400,
                    'speed' => 3
                ];
        }
    }

    private function handleShipLogic(array $ship, array $enemyShips, array $command, int $tick, int $shipIndex): array
    {
        $nearestEnemy = $this->findNearestEnemy($ship, $enemyShips);

        // Приоритет 1: Атаковать врага если видим
        if ($nearestEnemy && $this->calculateDistance($ship, $nearestEnemy) < $ship['scanRadius']) {
            return $this->attackEnemy($ship, $nearestEnemy, $command, $tick);
        }

        // Приоритет 2: Индивидуальное патрулирование
        return $this->individualPatrol($ship, $command, $tick, $shipIndex);
    }

    private function attackEnemy(array $ship, array $enemy, array $command, int $tick): array
    {
        $distance = $this->calculateDistance($ship, $enemy);

        // Стреляем если можем
        if ($ship['cannonCooldownLeft'] === 0 && $distance <= $ship['cannonRadius']) {
            $command['cannonShoot'] = [
                'x' => $enemy['x'],
                'y' => $enemy['y']
            ];
        }

        // Двигаемся к врагу с учетом ограничений скорости
        return $this->moveToAttack($ship, $enemy, $command, $tick);
    }

    private function moveToAttack(array $ship, array $enemy, array $command, int $tick): array
    {
        $distance = $this->calculateDistance($ship, $enemy);

        // Определяем желаемую скорость
        if ($distance > $ship['cannonRadius'] * 1.2) {
            // Двигаемся к врагу
            $command['acceleration'] = 1;
        } elseif ($distance < $ship['cannonRadius'] * 0.8) {
            // Отступаем на оптимальную дистанцию
            $command['acceleration'] = -1;
        } else {
            // Держим дистанцию
            $command['acceleration'] = 0;
        }

        // Поворачиваем только если скорость позволяет (<= 2)
        if ($ship['speed'] <= 2) {
            $desiredDirection = $this->getDirectionToTarget($ship, $enemy);
            $rotation = $this->calculateRotation($ship['direction'], $desiredDirection);

            // Проверяем, не пытаемся ли повернуть слишком часто
            $lastRotation = $this->shipStates[$ship['id']]['lastDirectionChange'] ?? 0;
            if ($tick - $lastRotation > 5) { // Не чаще чем раз в 5 тиков
                $command['rotate'] = $rotation;
                $this->shipStates[$ship['id']]['lastDirectionChange'] = $tick;
            }
        } else {
            // Слишком высокая скорость для поворота - замедляемся
            $command['acceleration'] = -1;
            $command['rotate'] = 0;
        }

        return $command;
    }

    private function individualPatrol(array $ship, array $command, int $tick, int $shipIndex): array
    {
        $path = $this->shipPatrolPaths[$ship['id']];
        $shipState = &$this->shipStates[$ship['id']];

        // Получаем следующую точку патрулирования
        $targetPoint = $this->getNextPatrolPoint($path, $tick, $shipIndex, $shipState);

        // Двигаемся к точке патрулирования
        return $this->moveToPatrol($ship, $targetPoint, $command, $tick);
    }

    private function getNextPatrolPoint(array $path, int $tick, int $shipIndex, array &$shipState): array
    {
        $phase = ($tick + $shipIndex * 25) % 360;
        $rad = deg2rad($phase);

        switch ($path['type']) {
            case 'circle':
                return [
                    'x' => $path['centerX'] + cos($rad) * $path['radius'],
                    'y' => $path['centerY'] + sin($rad) * $path['radius']
                ];

            case 'horizontal':
                $progress = (sin($rad) + 1) / 2;
                return [
                    'x' => $path['minX'] + $progress * ($path['maxX'] - $path['minX']),
                    'y' => $path['y']
                ];

            case 'vertical':
                $progress = (sin($rad) + 1) / 2;
                return [
                    'x' => $path['x'],
                    'y' => $path['minY'] + $progress * ($path['maxY'] - $path['minY'])
                ];

            case 'diagonal':
                $progress = (sin($rad) + 1) / 2;
                return [
                    'x' => $path['startX'] + $progress * ($path['endX'] - $path['startX']),
                    'y' => $path['startY'] + $progress * ($path['endY'] - $path['startY'])
                ];

            case 'random':
                // Меняем цель каждые 50 тиков
                if ($tick % 50 === 0 || !isset($shipState['currentTarget'])) {
                    $angle = deg2rad(($tick + $shipIndex * 30) % 360);
                    $distance = rand(100, 300);
                    $shipState['currentTarget'] = [
                        'x' => $path['centerX'] + cos($angle) * $distance,
                        'y' => $path['centerY'] + sin($angle) * $distance
                    ];
                }
                return $shipState['currentTarget'];

            default:
                return ['x' => $path['centerX'], 'y' => $path['centerY']];
        }
    }

    private function moveToPatrol(array $ship, array $target, array $command, int $tick): array
    {
        $distance = $this->calculateDistance($ship, $target);
        $path = $this->shipPatrolPaths[$ship['id']];

        // Контролируем скорость согласно патрульному плану
        $targetSpeed = $path['speed'] ?? 3;

        if ($ship['speed'] < $targetSpeed) {
            $command['acceleration'] = 1;
        } elseif ($ship['speed'] > $targetSpeed) {
            $command['acceleration'] = -1;
        } else {
            $command['acceleration'] = 0;
        }

        // Поворачиваем только на низкой скорости
        if ($ship['speed'] <= 2) {
            $desiredDirection = $this->getDirectionToPoint($ship['x'], $ship['y'], $target['x'], $target['y']);
            $rotation = $this->calculateRotation($ship['direction'], $desiredDirection);

            $lastRotation = $this->shipStates[$ship['id']]['lastDirectionChange'] ?? 0;
            if ($tick - $lastRotation > 3 && $rotation !== 0) {
                $command['rotate'] = $rotation;
                $this->shipStates[$ship['id']]['lastDirectionChange'] = $tick;
            }
        } else {
            // Слишком быстро для поворота - замедляемся
            $command['acceleration'] = -1;
            $command['rotate'] = 0;
        }

        return $command;
    }

    private function findNearestEnemy(array $ship, array $enemies): ?array
    {
        if (empty($enemies)) return null;

        $nearest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($enemies as $enemy) {
            $distance = $this->calculateDistance($ship, $enemy);
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearest = $enemy;
            }
        }

        return $nearest;
    }

    private function calculateDistance(array $a, array $b): float
    {
        return sqrt(pow($a['x'] - $b['x'], 2) + pow($a['y'] - $b['y'], 2));
    }

    private function getDirectionToTarget(array $ship, array $target): string
    {
        $dx = $target['x'] - $ship['x'];
        $dy = $target['y'] - $ship['y'];

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

    private function calculateRotation(string $currentDir, string $desiredDir): int
    {
        $directions = ['north', 'east', 'south', 'west'];
        $currentIdx = array_search($currentDir, $directions);
        $desiredIdx = array_search($desiredDir, $directions);

        if ($currentIdx === $desiredIdx) return 0;

        $diff = $desiredIdx - $currentIdx;

        if ($diff === 2 || $diff === -2) {
            return 90;
        } elseif ($diff === 1 || $diff === -3) {
            return 90;
        } else {
            return -90;
        }
    }
}
