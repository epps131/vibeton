<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:update')]
readonly class UpdateDataCommand
{
    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    public function __invoke(): int
    {
        $mapRequest = $this->client->request(
            'GET',
            'https://games-test.datsteam.dev/api/map', [
            'headers' => [
                'accept' => 'application/json',
                'X-Auth-Token' => 'a183405e-8c23-4e4b-afca-7b4a5b115fa8',
            ],
        ]);

        file_put_contents('public/map.json', $mapRequest->getContent());

        while (true) {
            sleep(1);

            $scanRequest = $this->client->request(
                'GET',
                'https://games-test.datsteam.dev/api/scan', [
                'headers' => [
                    'accept' => 'application/json',
                    'X-Auth-Token' => 'a183405e-8c23-4e4b-afca-7b4a5b115fa8',
                ],
            ]);

            file_put_contents('public/ships.json', $scanRequest->getContent());

            echo 'updated' . "\n";
        }
    }
}
