<?php

namespace Pmu\Command;

use Pmu\Crawler\AbstractCrawler;
use Pmu\Factory\PdoFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CrawlerCommand extends Command
{
   /* const DOMAINE = 'http://www.pronosoft.com/fr/parions_sport/resultats_parions_sport.php?date=%s';
    const URI_COURSE_DAY = '/programmes-courses/%s/';*/

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var \Pdo
     */
    protected $pdo;

    /**
     * @var AbstractCrawler
     */
    protected $crawler;

    protected $progress;

    public function __construct(AbstractCrawler $crawler) {
        $this->crawler = $crawler;
        parent::__construct(null);
    }

    protected function configure()
    {
        $this
            ->setName('crawler')
            ->setDescription('Crawl result of turf')
            ->addArgument('startDate', InputArgument::OPTIONAL, 'start date', null)
            ->addArgument('endDate', InputArgument::OPTIONAL, 'end date', null)
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'No using cache');

    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->progress = new ProgressBar($output);
        $this->progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% <info>%message%</info>   ');


        $this->input = $input;
        $this->output = $output;

        $this->pdo = PdoFactory::GetConnection();

        $this->crawler->initialise($input, $output, $this->progress);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $timestart = microtime(true);

        //set crawl date
        $nowDate = new \DateTime();
        $hierDate =clone $nowDate;
        $hierDate->setTimestamp(time() - (60*60*24));

        if ($input->getArgument('startDate')) {
            $startDate = new \DateTime($input->getArgument('startDate'));
        } else {
            $startDate = $this->getLastCrawlDate();
        }

        if ($startDate > $hierDate) {
            $startDate = clone $hierDate;
        }

        if ($input->getArgument('endDate')) {
            $endDate = new \DateTime($input->getArgument('endDate'));
        } else {
            $endDate = clone $hierDate;
        }

        if ($endDate > $hierDate) {
            $endDate = clone $hierDate;
        }

        $endDate->setTime(23, 59, 59);

        $interval = new \DateInterval('P1D');
        $daterange = new \DatePeriod($startDate, $interval , $endDate);

        $this->progress->start(iterator_count($daterange));
        foreach($daterange as $date){
            $this->progress->setMessage($date->format('Y-m-d'));
            $this->progress->advance();
            $this->crawler->crawlResultByDay($date);

        }
        $this->progress->finish();

        $total = (microtime(true) - $timestart) / 60;

        $this->output->writeln('<info>Crawl terminated in  ' . $total . 'm </info>');
    }


    protected function getLastCrawlDate()
    {
        $req = $this->pdo->prepare('SELECT date
                            FROM match_item
                            ORDER BY date DESC
                            LIMIT 1');
        $req->execute();
        $date = $req->fetchColumn();

        if (!$date) {
            return new \DateTime('2014-01-01');
        } else {
            $date = new \DateTime($date);
            return $date;
        }

    }

}

