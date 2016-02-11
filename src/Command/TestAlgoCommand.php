<?php

namespace Pmu\Command;

use Pmu\Algo\AlgoInterface;
use Pmu\Algo\CoteAlgo;
use Pmu\Factory\PdoFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Sunra\PhpSimple\HtmlDomParser;

class TestAlgoCommand extends Command
{


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
     * @var AlgoInterface
     */
    protected $algo;

    protected $progress;

    public function __construct(AlgoInterface $algo) {
        $this->algo = $algo;
        parent::__construct(null);
    }

    protected function configure()
    {
        $this
            ->setName('test:algo:'.$this->algo->getName())
            ->setDescription('Crawl result of turf')
            ->addArgument('startDate', InputArgument::OPTIONAL, 'start date', '2015-01-01')
            ->addArgument('endDate', InputArgument::OPTIONAL, 'end date', null);
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->pdo = PdoFactory::GetConnection();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $timestart = microtime(true);

        //set date
        $nowDate = new \DateTime();
        $hierDate =clone $nowDate;
        $hierDate->setTimestamp(time() - (60*60*24));

        if ($input->getArgument('startDate')) {
            $startDate = new \DateTime($input->getArgument('startDate'));
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

        $this->progress = new ProgressBar($this->output, iterator_count($daterange));
        $this->progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% <info>%message%</info>');

        $rapports = [];
        foreach($daterange as $date){
            $this->progress->setMessage($date->format('Y-m-d'));
            $this->progress->advance();
            $rapports[$date->format('Y')][$date->format('m')][$date->format('d')] = $this->testAlgo($date);
        }

        $this->progress->finish();

        $this->formatRapports($rapports);

        $total = (microtime(true) - $timestart) / 60;

        $this->output->writeln('<info>Test terminated in  ' . $total . 'm </info>');
    }

    protected function formatRapports(&$rapports)
    {


        $rowsMois = [];
        $rowsAnnee = [];
        $rowsJours = [];

        foreach ($rapports as $a => $rapportsA) {
            $totalGaniantA = $totalPerdantA = $totalDepenseA = $totalGainA = 0;

            foreach ($rapportsA as $m => $rapportsM) {
                $totalGaniantM = $totalPerdantM = $totalDepenseM = $totalGainM = 0;

                foreach ($rapportsM as $j => $rapportsJ) {
                    $totalGaniantM += $rapportsJ['Cote']['ganiant'];
                    $totalPerdantM += $rapportsJ['Cote']['perdant'];
                    $totalDepenseM += $rapportsJ['Cote']['depense'];
                    $totalGainM += $rapportsJ['Cote']['gain'];

                    $pourcentageVictoires = ($rapportsJ['Cote']['ganiant'] / ($rapportsJ['Cote']['ganiant'] + $rapportsJ['Cote']['perdant'])) * 100;
                    $gain = $rapportsJ['Cote']['gain'] - $rapportsJ['Cote']['depense'];
                    $pourcentageGain = ($gain / $rapportsJ['Cote']['depense']) * 100;

                    $rowsJours[] = [$a.'-'.$m.'-'.$j, 'Cote',   round($pourcentageVictoires, 2) . '%', $rapportsJ['Cote']['depense']. '€', $rapportsJ['Cote']['gain']. '€', round($pourcentageGain, 2) . '%',  round($gain, 2) . '€'];
                    $rowsJours[] = new TableSeparator();
                }


                $pourcentageVictoiresM = ($totalGaniantM / ($totalGaniantM + $totalPerdantM)) * 100;
                $gainM = $totalGainM - $totalDepenseM;
                $pourcentageGainM = ($gainM / $totalDepenseM) * 100;

                $rowsMois[] = [$a.'-'.$m, 'Cote', round($pourcentageVictoiresM, 2) . '%', $totalDepenseM. '€', $totalGainM. '€', round($pourcentageGainM, 2) . '%',  round($gainM, 2) . '€'];
                $rowsMois[] = new TableSeparator();

                $totalGaniantA += $totalGaniantM;
                $totalPerdantA += $totalPerdantM;
                $totalDepenseA += $totalDepenseM;
                $totalGainA += $totalGainM;
            }

            $pourcentageVictoiresA = ($totalGaniantA / ($totalGaniantA + $totalPerdantA)) * 100;
            $gainA = $totalGainA - $totalDepenseA;
            $pourcentageGainA = ($gainA / $totalDepenseA) * 100;

            $rowsAnnee[] = [$a, 'Cote',  round($pourcentageVictoiresA, 2) . '%', $totalDepenseA. '€', $totalGainA. '€', round($pourcentageGainA, 2) . '%',  round($gainA, 2) . '€'];
            $rowsAnnee[] = new TableSeparator();

        }

        array_pop($rowsAnnee);
        array_pop($rowsMois);
        array_pop($rowsJours);

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>Resultat par jours</info>');
        $table = new Table($this->output);
        $table
            ->setHeaders(array('Date', 'Algo', '% victoire', 'Depense', 'Gain', '% Benef', 'Benef en €'))
            ->setRows($rowsJours);
        $table->render();

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>Resultat par mois</info>');
        $table = new Table($this->output);
        $table
            ->setHeaders(array('Date', 'Algo', '% victoire', 'Depense', 'Gain', '% Benef', 'Benef en €'))
            ->setRows($rowsMois);
        $table->render();

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>Resultat par ans</info>');
        $table = new Table($this->output);
        $table
            ->setHeaders(array('Date', 'Algo', '% victoire', 'Depense', 'Gain', '% Benef', 'Benef en €'))
            ->setRows($rowsAnnee);
        $table->render();
    }

    protected function testAlgo(\DateTime $date)
    {
        //create base rapport
        $rapport = [];
        foreach ([$date->format('Y-m-d'), $date->format('Y-m'), $date->format('d')] as $rapportType) {
            $rapport[$rapportType] = [
                'ganiant' => 0,
                'perdant' => 0,
                'depense' => 0,
                'gain'    => 0,
            ];
        }

        $req = $this->pdo->prepare('SELECT * FROM pmu_course WHERE pmu_date = :date');
        $req->bindParam(':date', $date->format('Y-m-d'));
        $req->execute();

        $courses = $req->fetchAll(\PDO::FETCH_OBJ);
        foreach ($courses as $course) {


            $this->progress->setMessage($date->format('Y-m-d') . ' R' . $course->pmu_reunion_num . 'C' . $course->pmu_course_num);
            $this->progress->display();

            $req = $this->pdo->prepare('SELECT * FROM pmu_concurrent WHERE pmu_course_id = :courseId ORDER BY pmu_position ASC');
            $req->bindParam(':courseId', $course->pmu_id);
            $req->execute();

            $concurrents = $req->fetchAll(\PDO::FETCH_OBJ);
            if (empty($concurrents)) {
                throw new \Exception('Aucun concurrents sur la course ' . $course->pmu_id);
            }

            $gagnant = null;
            foreach($concurrents as $concurrent) {
                if($concurrent->pmu_position == 1) {
                    $gagnant = $concurrent;
                    break;
                }
            }

            if ($gagnant == null) {
                $this->output->writeln('<info>Aucun gagniant sur la course ' . $course->pmu_id . '</info>');
                continue;
            }

            //recup donnée
            foreach ($this->algos as $algo) {

                $rapport[$algo->getName()]['depense']++;

                $results = $algo->byScore($course, $concurrents);

                $algoGagant = end($results);

                if ($algoGagant->numero == $gagnant->pmu_numero) {
                    $rapport[$algo->getName()]['ganiant']++;
                    $rapport[$algo->getName()]['gain'] += $gagnant->pmu_cote;
                } else {
                    $rapport[$algo->getName()]['perdant']++;
                }
            }
        }

        return $rapport;

    }




}
