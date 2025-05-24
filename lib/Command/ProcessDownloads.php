<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Command;

use OCA\TransferQuotaMonitor\Listener\UsageListener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessDownloads extends Command {
    /** @var UsageListener */
    private $usageListener;

    public function __construct(UsageListener $usageListener) {
        parent::__construct();
        $this->usageListener = $usageListener;
    }

    protected function configure(): void {
        $this->setName('transfer-quota:process-downloads')
            ->setDescription('Process download data from User Usage Report')
            ->addArgument('user-id', InputArgument::OPTIONAL, 'Process downloads for a specific user (optional)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getArgument('user-id');
        
        $output->writeln('<info>Processing download data...</info>');
        
        if ($userId) {
            $output->writeln("Processing downloads for user: $userId");
            $result = $this->usageListener->processUserOperations($userId);
        } else {
            $output->writeln('Processing downloads for all users');
            $result = $this->usageListener->processFileOperations();
        }
        
        if ($result) {
            $output->writeln('<info>Download processing completed successfully.</info>');
            return Command::SUCCESS;
        } else {
            $output->writeln('<error>Error processing downloads. Check the logs for details.</error>');
            return Command::FAILURE;
        }
    }
}
