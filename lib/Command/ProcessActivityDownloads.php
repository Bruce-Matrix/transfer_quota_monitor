<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Command;

use OCA\TransferQuotaMonitor\Service\ActivityDownloadTracker;
use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to manually process activity downloads for testing
 */
class ProcessActivityDownloads extends Command {
    /** @var ActivityDownloadTracker */
    private $activityDownloadTracker;
    
    /** @var IDBConnection */
    private $db;
    
    /** @var TransferQuotaService */
    private $quotaService;

    /**
     * @param ActivityDownloadTracker $activityDownloadTracker
     * @param IDBConnection $db
     * @param TransferQuotaService $quotaService
     */
    public function __construct(
        ActivityDownloadTracker $activityDownloadTracker,
        IDBConnection $db,
        TransferQuotaService $quotaService
    ) {
        parent::__construct();
        $this->activityDownloadTracker = $activityDownloadTracker;
        $this->db = $db;
        $this->quotaService = $quotaService;
    }

    protected function configure() {
        $this
            ->setName('transfer-quota:process-activity-downloads')
            ->setDescription('Process downloads from activity log')
            ->addOption(
                'lookback',
                'l',
                InputOption::VALUE_OPTIONAL,
                'How many minutes to look back',
                60 // Default to 60 minutes
            )
            ->addOption(
                'debug',
                'd',
                InputOption::VALUE_NONE,
                'Show debug information about activities'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $lookbackMinutes = (int) $input->getOption('lookback');
        $debug = $input->getOption('debug');
        $lookbackSeconds = $lookbackMinutes * 60;
        
        $output->writeln("<info>Processing downloads from activity log (looking back $lookbackMinutes minutes)</info>");
        
        // If debug mode is enabled, show what's in the activity table first
        if ($debug) {
            $this->showActivityEntries($output, $lookbackSeconds);
        }
        
        // Process downloads
        $count = $this->activityDownloadTracker->processRecentDownloads($lookbackSeconds);
        
        $output->writeln("<info>Processed $count download activities</info>");
        
        // If debug is enabled, show user quotas
        if ($debug) {
            $this->showUserQuotas($output);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Show recent activity entries for debugging
     */
    private function showActivityEntries(OutputInterface $output, int $lookbackSeconds) {
        $time = time() - $lookbackSeconds;
        
        $output->writeln("<comment>Recent activities:</comment>");
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('activity')
           ->where($qb->expr()->gt('timestamp', $qb->createNamedParameter($time, \PDO::PARAM_INT)))
           ->orderBy('timestamp', 'DESC')
           ->setMaxResults(20);
        
        $result = $qb->executeQuery();
        
        $output->writeln("+-----+-----------------+------------------+------------+---------------+---------------------+");
        $output->writeln("| ID  | App             | Type             | User       | Object         | Time                |");
        $output->writeln("+-----+-----------------+------------------+------------+---------------+---------------------+");
        
        while ($row = $result->fetch()) {
            $id = str_pad((string)($row['activity_id'] ?? ''), 3, ' ', STR_PAD_LEFT);
            $app = str_pad((string)($row['app'] ?? ''), 15, ' ', STR_PAD_RIGHT);
            $type = str_pad((string)($row['type'] ?? ''), 16, ' ', STR_PAD_RIGHT);
            $user = str_pad((string)($row['affecteduser'] ?? ''), 10, ' ', STR_PAD_RIGHT);
            $object = str_pad(substr((string)($row['object_name'] ?? ''), 0, 13), 13, ' ', STR_PAD_RIGHT);
            $time = date('Y-m-d H:i:s', (int)($row['timestamp'] ?? time()));
            
            $output->writeln("| $id | $app | $type | $user | $object | $time |");
        }
        
        $output->writeln("+-----+-----------------+------------------+------------+---------------+---------------------+");
        $result->closeCursor();
        
        // Specifically look for files_downloadactivity entries
        $output->writeln("\n<comment>Download activities:</comment>");
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('activity')
           ->where($qb->expr()->eq('app', $qb->createNamedParameter('files_downloadactivity')))
           ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('file_downloaded')))
           ->andWhere($qb->expr()->gt('timestamp', $qb->createNamedParameter($time, \PDO::PARAM_INT)))
           ->orderBy('timestamp', 'DESC');
        
        $result = $qb->executeQuery();
        
        if ($result->rowCount() === 0) {
            $output->writeln("<error>No files_downloadactivity entries found in the activity table!</error>");
            $output->writeln("<info>Check that the files_downloadactivity app is installed and enabled.</info>");
        } else {
            $output->writeln("+-----+----------+------------------+---------------+---------------------+");
            $output->writeln("| ID  | User     | Object Type      | Object Name    | Time                |");
            $output->writeln("+-----+----------+------------------+---------------+---------------------+");
            
            while ($row = $result->fetch()) {
                $id = str_pad((string)($row['activity_id'] ?? ''), 3, ' ', STR_PAD_LEFT);
                $user = str_pad((string)($row['affecteduser'] ?? ''), 8, ' ', STR_PAD_RIGHT);
                $objectType = str_pad((string)($row['object_type'] ?? ''), 16, ' ', STR_PAD_RIGHT);
                $objectName = str_pad(substr((string)($row['object_name'] ?? ''), 0, 13), 13, ' ', STR_PAD_RIGHT);
                $time = date('Y-m-d H:i:s', (int)($row['timestamp'] ?? time()));
                
                $output->writeln("| $id | $user | $objectType | $objectName | $time |");
            }
            
            $output->writeln("+-----+----------+------------------+---------------+---------------------+");
        }
        
        $result->closeCursor();
    }
    
    /**
     * Show user transfer quotas
     */
    private function showUserQuotas(OutputInterface $output) {
        $output->writeln("\n<comment>User transfer quotas:</comment>");
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('transfer_quota_limits')
           ->orderBy('user_id');
        
        $result = $qb->executeQuery();
        
        $output->writeln("+----------+---------------+---------------+-------------------+");
        $output->writeln("| User     | Limit (MB)     | Usage (MB)     | Last Reset        |");
        $output->writeln("+----------+---------------+---------------+-------------------+");
        
        while ($row = $result->fetch()) {
            $user = str_pad((string)($row['user_id'] ?? ''), 8, ' ', STR_PAD_RIGHT);
            $limit = str_pad(number_format((int)($row['monthly_limit'] ?? 0) / (1024*1024), 2), 13, ' ', STR_PAD_LEFT);
            $usage = str_pad(number_format((int)($row['current_usage'] ?? 0) / (1024*1024), 2), 13, ' ', STR_PAD_LEFT);
            $lastReset = str_pad(substr((string)($row['last_reset'] ?? ''), 0, 17), 17, ' ', STR_PAD_RIGHT);
            
            $output->writeln("| $user | $limit | $usage | $lastReset |");
        }
        
        $output->writeln("+----------+---------------+---------------+-------------------+");
        $result->closeCursor();
    }
}
