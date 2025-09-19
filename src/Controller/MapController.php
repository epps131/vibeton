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

        return $this->render('map.html.twig', [
            'mapWidth' => $mapData['map'][0],
            'mapHeight' => $mapData['map'][1],
            'obstacles' => $mapData['obstacles']
        ]);
    }
}
