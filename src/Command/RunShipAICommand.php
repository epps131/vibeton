<?php

namespace App\Command;

use App\Service\ShipAIService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:run-ship-ai')]
class RunShipAICommand extends Command
{
    private mixed $apiUrl;

    public function __construct(
        private ShipAIService $shipAIService,
    ) {
        parent::__construct();
        $this->apiUrl = 'https://games-test.datsteam.dev/api/';
    }

    protected function configure()
    {
        $this
            ->addOption('continuous', 'c', InputOption::VALUE_NONE, 'Run continuously')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Interval between ticks in milliseconds', 1000)
            ->addOption('max-ticks', 'm', InputOption::VALUE_REQUIRED, 'Maximum number of ticks to run', 1000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $continuous = $input->getOption('continuous');
        $interval = (int) $input->getOption('interval');
        $maxTicks = (int) $input->getOption('max-ticks');

        $io->title('Advanced Ship AI Controller');
        $io->writeln('Initializing map data...');

        try {
            // Инициализируем карту и препятствия ПЕРЕД началом цикла
            $this->shipAIService->initialize();
            $io->writeln('Map initialized successfully');
            $io->writeln('');

            $tickCount = 0;
            do {
                $tickCount++;

                if ($tickCount > $maxTicks && !$continuous) {
                    $io->success(sprintf('Completed %d ticks', $tickCount - 1));
                    break;
                }

                // Получаем состояние игры
                $gameState = $this->shipAIService->getGameState();

                $io->writeln(sprintf(
                    'Tick: %d | My ships: %d | Enemy ships: %d',
                    $gameState['tick'],
                    count($gameState['myShips'] ?? []),
                    count($gameState['enemyShips'] ?? [])
                ));

                // Принимаем решения
                $commands = $this->shipAIService->makeDecisions($gameState);

                if (!empty($commands)) {
                    $io->writeln(sprintf('Sending %d commands:', count($commands)));

                    foreach ($commands as $command) {
                        $action = [];
                        if ($command['acceleration'] !== 0) {
                            $action[] = sprintf('Accel: %d', $command['acceleration']);
                        }
                        if ($command['rotate'] !== 0) {
                            $action[] = sprintf('Rotate: %d', $command['rotate']);
                        }
                        if ($command['cannonShoot'] !== null) {
                            $action[] = sprintf('Shoot: (%d, %d)',
                                $command['cannonShoot']['x'],
                                $command['cannonShoot']['y']
                            );
                        }

                        if (!empty($action)) {
                            $io->writeln(sprintf('  Ship %s: %s',
                                substr($command['id'], 0, 8),
                                implode(', ', $action)
                            ));
                        }
                    }

                    // Отправляем команды
                    $result = $this->shipAIService->sendCommands($commands);

                    if (isset($result['success']) && $result['success']) {
                        $io->writeln('Commands executed successfully');
                    } else {
                        $io->error('Failed to execute commands: ' . ($result['error'] ?? 'Unknown error'));
                    }
                } else {
                    $io->writeln('No commands to send');
                }

                $io->writeln('');

                if ($continuous) {
                    usleep($interval * 1000);
                }

            } while ($continuous);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage() . '. Line : ' . $e->getLine());

            return Command::FAILURE;
        }
    }
}
