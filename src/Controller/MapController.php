<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MapController extends AbstractController
{
    #[Route('/map')]
    public function map(): Response
    {
        $mapData = json_decode(file_get_contents('map.json'), true);

        // Создаем пустую карту
        $fullMap = array_fill(0, $mapData['map'][1], array_fill(0, $mapData['map'][0], 0));

        // Заполняем препятствия
        foreach ($mapData['obstacles'] as $obstacle) {
            $startX = $obstacle['start'][0];
            $startY = $obstacle['start'][1];

            foreach ($obstacle['map'] as $y => $row) {
                foreach ($row as $x => $value) {
                    if ($value == 1) {
                        $mapX = $startX + $x;
                        $mapY = $startY + $y;

                        // Проверяем границы карты
                        if ($mapX < $mapData['map'][0] && $mapY < $mapData['map'][1]) {
                            $fullMap[$mapY][$mapX] = 1;
                        }
                    }
                }
            }
        }

        // Для большой карты лучше разбить на чанки
        $chunkSize = 100;
        $mapChunks = array_chunk($fullMap, $chunkSize);

        // Передаем в Twig
        return $this->render('map.html.twig', [
            'mapChunks' => $mapChunks,
            'chunkSize' => $chunkSize,
            'mapWidth' => $mapData['map'][0],
            'mapHeight' => $mapData['map'][1]
        ]);
    }
}
