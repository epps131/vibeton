<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ShipAIService
{
    private HttpClientInterface $client;
    private string $authToken;
    private array $strategies;
    private array $mapInfo = [];
    private array $obstacles = [];
    private array $obstacleGrid = [];
    private array $stormInfo = [];
    private array $shipRoles = [];
    private bool $initialized = false;

    public function __construct(string $authToken)
    {
        $this->client = HttpClient::create();
        $this->authToken = $authToken;

        // Инициализируем стратегии правильно
        $this->strategies = [
            'aggressive' => [$this, 'aggressiveStrategy'],
            'defensive' => [$this, 'defensiveStrategy'],
            'support' => [$this, 'supportStrategy'],
            'storm_survival' => [$this, 'stormSurvivalStrategy']
        ];
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Получаем карту и препятствия
        $this->loadMapData();

        // Создаем сетку препятствий для быстрых проверок
        $this->buildObstacleGrid();

        $this->initialized = true;
    }

    private function loadMapData(): void
    {
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
    }

    private function buildObstacleGrid(): void
    {
        // Инициализируем пустую сетку
        $this->obstacleGrid = array_fill(0, $this->mapInfo['height'],
            array_fill(0, $this->mapInfo['width'], 0));

        // Заполняем препятствия
        foreach ($this->obstacles as $obstacle) {
            $startX = $obstacle['start'][0];
            $startY = $obstacle['start'][1];
            $map = $obstacle['map'];

            foreach ($map as $y => $row) {
                foreach ($row as $x => $cell) {
                    if ($cell === 1) {
                        $gridX = $startX + $x;
                        $gridY = $startY + $y;

                        if ($gridX < $this->mapInfo['width'] && $gridY < $this->mapInfo['height']) {
                            $this->obstacleGrid[$gridY][$gridX] = 1;
                        }
                    }
                }
            }
        }
    }

    public function getGameState(): array
    {
        $response = $this->client->request('GET', 'https://games-test.datsteam.dev/api/scan', [
            'headers' => [
                'Accept' => 'application/json',
                'X-Auth-Token' => $this->authToken
            ]
        ]);

        $state = json_decode($response->getContent(), true);

        // Сохраняем информацию о шторме если есть
        if (isset($state['storm'])) {
            $this->stormInfo = $state['storm'];
        }

        return $state;
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
        // Убеждаемся, что карта инициализирована
        if (!$this->initialized) {
            $this->initialize();
        }

        $commands = [];
        $myShips = $gameState['myShips'] ?? [];
        $enemyShips = $gameState['enemyShips'] ?? [];
        $tick = $gameState['tick'] ?? 0;

        // Определяем роли кораблей на основе их размера
        $this->assignShipRoles($myShips);

        foreach ($myShips as $ship) {
            // Пропускаем корабли с HP <= 0
            if ($ship['hp'] <= 0) {
                continue;
            }

            $command = [
                'id' => $ship['id'],
                'acceleration' => 0,
                'rotate' => 0,
                'cannonShoot' => null
            ];

            // Выбираем стратегию в зависимости от тика и ситуации
            $strategyName = $this->selectStrategy($ship, $enemyShips, $tick);

            // Вызываем стратегию через массив стратегий
            $command = $this->strategies[$strategyName]($ship, $enemyShips, $command, $tick);

            $commands[] = $command;
        }

        return $commands;
    }

    private function assignShipRoles(array $ships): void
    {
        foreach ($ships as $ship) {
            // Большие корабли - танки, маленькие - damage dealers
            if ($ship['size'] >= 4) {
                $this->shipRoles[$ship['id']] = 'tank';
            } elseif ($ship['size'] == 3) {
                $this->shipRoles[$ship['id']] = 'support';
            } else {
                $this->shipRoles[$ship['id']] = 'attacker';
            }
        }
    }

    private function selectStrategy(array $ship, array $enemyShips, int $tick): string
    {
        // Первые 10 тиков - избегаем столкновений
        if ($tick < 10) {
            return 'defensive';
        }

        // После 100 тиков учитываем шторм
        if ($tick >= 100 && !$this->isInSafeZone($ship)) {
            return 'storm_survival';
        }

        $role = $this->shipRoles[$ship['id']] ?? 'attacker';
        $nearestEnemy = $this->findNearestEnemy($ship, $enemyShips);

        if (!$nearestEnemy) {
            return 'defensive';
        }

        $distance = $this->calculateDistance($ship, $nearestEnemy);
        $hpPercent = $ship['hp'] / $ship['size'];

        // Тактика в зависимости от роли
        switch ($role) {
            case 'tank':
                return $distance <= $ship['cannonRadius'] ? 'aggressive' : 'defensive';

            case 'attacker':
                return $hpPercent > 0.6 ? 'aggressive' : 'defensive';

            case 'support':
                return 'support';

            default:
                return 'defensive';
        }
    }

    private function aggressiveStrategy(array $ship, array $enemyShips, array $command, int $tick): array
    {
        $nearestEnemy = $this->findNearestEnemy($ship, $enemyShips);

        if (!$nearestEnemy) {
            return $this->defensiveStrategy($ship, $enemyShips, $command, $tick);
        }

        // Стрельба если возможно
        if ($this->canShoot($ship, $nearestEnemy)) {
            $command['cannonShoot'] = [
                'x' => $nearestEnemy['x'],
                'y' => $nearestEnemy['y']
            ];
        }

        // Движение к врагу с обходом препятствий
        $targetPos = $this->calculateAttackPosition($ship, $nearestEnemy);
        $command = $this->navigateTo($ship, $targetPos['x'], $targetPos['y'], $command);

        return $command;
    }

    private function defensiveStrategy(array $ship, array $enemyShips, array $command, int $tick): array
    {
        $nearestEnemy = $this->findNearestEnemy($ship, $enemyShips);

        if ($nearestEnemy) {
            // Держим дистанцию
            $distance = $this->calculateDistance($ship, $nearestEnemy);
            $idealDistance = $ship['cannonRadius'] * 0.7;

            if ($distance < $idealDistance) {
                // Отступаем
                $retreatPos = $this->calculateRetreatPosition($ship, $nearestEnemy);
                $command = $this->navigateTo($ship, $retreatPos['x'], $retreatPos['y'], $command);
            } else {
                // Подходим на дистанцию атаки
                $attackPos = $this->calculateAttackPosition($ship, $nearestEnemy);
                $command = $this->navigateTo($ship, $attackPos['x'], $attackPos['y'], $command);
            }

            // Стрельба если возможно
            if ($this->canShoot($ship, $nearestEnemy)) {
                $command['cannonShoot'] = [
                    'x' => $nearestEnemy['x'],
                    'y' => $nearestEnemy['y']
                ];
            }
        } else {
            // Патрулирование
            $patrolPos = $this->getPatrolPosition($ship, $tick);
            $command = $this->navigateTo($ship, $patrolPos['x'], $patrolPos['y'], $command);
        }

        return $command;
    }

    private function supportStrategy(array $ship, array $enemyShips, array $command, int $tick): array
    {
        // Support корабли прикрывают атакующих
        $nearestAlly = $this->findNearestAlly($ship, $enemyShips);
        $nearestEnemy = $this->findNearestEnemy($ship, $enemyShips);

        if ($nearestAlly && $nearestEnemy) {
            // Занимаем позицию между союзником и врагом
            $supportX = ($nearestAlly['x'] + $nearestEnemy['x']) / 2;
            $supportY = ($nearestAlly['y'] + $nearestEnemy['y']) / 2;

            $command = $this->navigateTo($ship, $supportX, $supportY, $command);

            // Стрельба по врагу
            if ($this->canShoot($ship, $nearestEnemy)) {
                $command['cannonShoot'] = [
                    'x' => $nearestEnemy['x'],
                    'y' => $nearestEnemy['y']
                ];
            }
        }

        return $command;
    }

    private function stormSurvivalStrategy(array $ship, array $enemyShips, array $command, int $tick): array
    {
        // Срочно движемся в безопасную зону
        if ($this->stormInfo) {
            $safeX = $this->stormInfo['centerX'] ?? $this->mapInfo['width'] / 2;
            $safeY = $this->stormInfo['centerY'] ?? $this->mapInfo['height'] / 2;

            $command['acceleration'] = 1; // Максимальное ускорение
            $command = $this->navigateTo($ship, $safeX, $safeY, $command);
        }

        return $command;
    }

    private function navigateTo(array $ship, float $targetX, float $targetY, array $command): array
    {
        // Корректируем цель чтобы избежать препятствий
        $safeTarget = $this->findSafePath($ship, $targetX, $targetY);

        // Рассчитываем ускорение
        $distance = $this->calculateDistance($ship, ['x' => $safeTarget['x'], 'y' => $safeTarget['y']]);
        $command['acceleration'] = $this->calculateAcceleration($ship, $distance);

        // Рассчитываем поворот только если скорость позволяет
        if ($ship['speed'] <= 2) {
            $desiredDirection = $this->getDirectionToPoint($ship['x'], $ship['y'], $safeTarget['x'], $safeTarget['y']);
            $command['rotate'] = $this->calculateRotation($ship['direction'], $desiredDirection);
        }

        return $command;
    }

    private function findSafePath(array $ship, float $targetX, float $targetY): array
    {
        $currentX = $ship['x'];
        $currentY = $ship['y'];
        $shipSize = $ship['size'];

        // Проверяем прямую видимость
        if ($this->isPathClear($currentX, $currentY, $targetX, $targetY, $shipSize)) {
            return ['x' => $targetX, 'y' => $targetY];
        }

        // Ищем обходной путь с помощью A* или упрощенного алгоритма
        return $this->findAlternativePath($currentX, $currentY, $targetX, $targetY, $shipSize);
    }

    private function isPathClear(float $fromX, float $fromY, float $toX, float $toY, int $shipSize): bool
    {
        $distance = sqrt(pow($toX - $fromX, 2) + pow($toY - $fromY, 2));
        $steps = max(10, (int)($distance / 5));
        $dx = ($toX - $fromX) / $steps;
        $dy = ($toY - $fromY) / $steps;

        for ($i = 1; $i <= $steps; $i++) {
            $checkX = $fromX + $dx * $i;
            $checkY = $fromY + $dy * $i;

            if ($this->isObstacleAt($checkX, $checkY, $shipSize) ||
                $this->isOutOfBounds($checkX, $checkY, $shipSize)) {
                return false;
            }
        }

        return true;
    }

    private function isObstacleAt(float $x, float $y, int $size): bool
    {
        $checkRadius = $size + 1; // Безопасное расстояние

        for ($dy = -$checkRadius; $dy <= $checkRadius; $dy++) {
            for ($dx = -$checkRadius; $dx <= $checkRadius; $dx++) {
                $checkX = (int)round($x + $dx);
                $checkY = (int)round($y + $dy);

                if ($checkX >= 0 && $checkX < $this->mapInfo['width'] &&
                    $checkY >= 0 && $checkY < $this->mapInfo['height'] &&
                    $this->obstacleGrid[$checkY][$checkX] === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isOutOfBounds(float $x, float $y, int $size): bool
    {
        return $x < $size || $x > $this->mapInfo['width'] - $size ||
            $y < $size || $y > $this->mapInfo['height'] - $size;
    }

    private function isInSafeZone(array $ship): bool
    {
        if (!$this->stormInfo) return true;

        $distanceToCenter = sqrt(
            pow($ship['x'] - $this->stormInfo['centerX'], 2) +
            pow($ship['y'] - $this->stormInfo['centerY'], 2)
        );

        return $distanceToCenter <= $this->stormInfo['radius'];
    }

    private function findAlternativePath(float $fromX, float $fromY, float $toX, float $toY, int $shipSize): array
    {
        // Упрощенный алгоритм поиска пути
        $angles = [0, 45, 90, 135, 180, 225, 270, 315];
        $bestDistance = PHP_FLOAT_MAX;
        $bestX = $toX;
        $bestY = $toY;

        foreach ($angles as $angle) {
            $rad = deg2rad($angle);
            $testX = $fromX + cos($rad) * 50;
            $testY = $fromY + sin($rad) * 50;

            if (!$this->isObstacleAt($testX, $testY, $shipSize) &&
                !$this->isOutOfBounds($testX, $testY, $shipSize) &&
                $this->isPathClear($fromX, $fromY, $testX, $testY, $shipSize)) {

                $distanceToTarget = sqrt(pow($testX - $toX, 2) + pow($testY - $toY, 2));
                if ($distanceToTarget < $bestDistance) {
                    $bestDistance = $distanceToTarget;
                    $bestX = $testX;
                    $bestY = $testY;
                }
            }
        }

        return ['x' => $bestX, 'y' => $bestY];
    }

    private function calculateAttackPosition(array $ship, array $enemy): array
    {
        $idealDistance = $ship['cannonRadius'] * 0.8;
        $angle = atan2($enemy['y'] - $ship['y'], $enemy['x'] - $ship['x']);

        return [
            'x' => $enemy['x'] - cos($angle) * $idealDistance,
            'y' => $enemy['y'] - sin($angle) * $idealDistance
        ];
    }

    private function calculateRetreatPosition(array $ship, array $enemy): array
    {
        $retreatDistance = $ship['cannonRadius'] * 1.2;
        $angle = atan2($enemy['y'] - $ship['y'], $enemy['x'] - $ship['x']);

        return [
            'x' => $ship['x'] - cos($angle) * $retreatDistance,
            'y' => $ship['y'] - sin($angle) * $retreatDistance
        ];
    }

    private function getPatrolPosition(array $ship, int $tick): array
    {
        // Циклическое патрулирование
        $phase = $tick % 100 / 100 * 2 * M_PI;
        $radius = min($this->mapInfo['width'], $this->mapInfo['height']) * 0.3;

        return [
            'x' => $this->mapInfo['width'] / 2 + cos($phase) * $radius,
            'y' => $this->mapInfo['height'] / 2 + sin($phase) * $radius
        ];
    }

    private function canShoot(array $ship, array $enemy): bool
    {
        $distance = $this->calculateDistance($ship, $enemy);
        return $ship['cannonCooldownLeft'] === 0 && $distance <= $ship['cannonRadius'];
    }

    private function calculateAcceleration(array $ship, float $distance): int
    {
        if ($distance > 100) return 1;
        if ($distance > 30) return $ship['speed'] < $ship['maxSpeed'] ? 1 : 0;
        if ($distance > 10) return 0;
        return -1;
    }

    private function calculateRotation(string $currentDir, string $desiredDir): int
    {
        $directions = ['north', 'east', 'south', 'west'];
        $currentIdx = array_search($currentDir, $directions);
        $desiredIdx = array_search($desiredDir, $directions);

        if ($currentIdx === $desiredIdx) return 0;

        $diff = $desiredIdx - $currentIdx;
        if (abs($diff) === 2) return 90; // Разворот на 180
        return $diff > 0 ? 90 : -90;
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

    private function findNearestAlly(array $ship, array $enemies): ?array
    {
        // Для упрощения считаем что все свои корабли в состоянии игры
        // В реальности нужно передавать всех союзников
        return null;
    }

    private function calculateDistance(array $a, array $b): float
    {
        return sqrt(pow($a['x'] - $b['x'], 2) + pow($a['y'] - $b['y'], 2));
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
}
