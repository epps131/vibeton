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
        $this->apiUrl = 'https://games.datsteam.dev/api/';
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

        a:
        $io->title('Simple Aggressive Ship AI');
        $io->writeln('Initializing...');

        try {
            $this->shipAIService->initialize();
            $io->writeln('Ready to attack!');
            $io->writeln('');

            $tickCount = 0;

            do {
                $tickCount++;

                if ($tickCount > $maxTicks && !$continuous) {
                    $io->success(sprintf('Completed %d ticks', $tickCount - 1));
                    break;
                }

                $gameState = $this->shipAIService->getGameState();

                $io->section(sprintf('Tick: %d', $gameState['tick']));
                $io->writeln(sprintf(
                    'My ships: %d | Enemy ships: %d',
                    count($gameState['myShips'] ?? []),
                    count($gameState['enemyShips'] ?? [])
                ));

                // Логируем врагов
                if (!empty($gameState['enemyShips'])) {
                    $io->writeln('Enemies detected:');
                    foreach ($gameState['enemyShips'] as $enemy) {
                        $io->writeln(sprintf('  %s at (%d, %d) HP: %d',
                            $enemy['playerName'] ?? 'Unknown',
                            $enemy['x'],
                            $enemy['y'],
                            $enemy['hp']
                        ));
                    }
                }

                $commands = $this->shipAIService->makeDecisions($gameState);

                if (!empty($commands)) {
                    $io->writeln('Sending commands:');

                    foreach ($commands as $command) {
                        $actions = [];

                        if ($command['acceleration'] !== 0) {
                            $actions[] = sprintf('Accel: %d', $command['acceleration']);
                        }
                        if ($command['rotate'] !== 0) {
                            $actions[] = sprintf('Rotate: %d°', $command['rotate']);
                        }
                        if ($command['cannonShoot'] !== null) {
                            $actions[] = sprintf('FIRE! at (%d, %d)',
                                $command['cannonShoot']['x'],
                                $command['cannonShoot']['y']
                            );
                        }

                        if (!empty($actions)) {
                            $io->writeln(sprintf('  Ship %s: %s',
                                substr($command['id'], 0, 8),
                                implode(', ', $actions)
                            ));
                        } else {
                            $io->writeln(sprintf('  Ship %s: No action', substr($command['id'], 0, 8)));
                        }
                    }

                    $result = $this->shipAIService->sendCommands($commands);

                    if (isset($result['success']) && $result['success']) {
                        $io->writeln('✓ Commands sent successfully');
                    } else {
                        $io->error('✗ Command error: ' . ($result['error'] ?? 'Unknown'));
                    }
                } else {
                    $io->writeln('No commands to send');
                }

                $io->writeln('');

                if ($continuous) {
                    usleep($interval * 1000);
                }

            } while ($continuous);


        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());

            return $this->execute($input, $output);
        }

        return 1;
    }
}
