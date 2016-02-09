<?php

namespace Pmu\Command;

use Pmu\Algo\AlgoInterface;
use Pmu\Algo\CoteAlgo;
use Pmu\Factory\PdoFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
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
     * @var AlgoInterface[]
     */
    protected $algos = [];

    protected function configure()
    {
        $this
            ->setName('test:algo')
            ->setDescription('Crawl result of turf')
            ->addArgument('startDate', InputArgument::OPTIONAL, 'start date', '2015-01-01')
            ->addArgument('endDate', InputArgument::OPTIONAL, 'end date', null);
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->pdo = PdoFactory::GetConnection();
        $this->algos[] = new CoteAlgo();
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
            $startDate = $hierDate;
        }

        if ($input->getArgument('endDate')) {
            $endDate = new \DateTime($input->getArgument('endDate'));
        } else {
            $endDate = $hierDate;
        }

        if ($endDate > $hierDate) {
            $endDate = $hierDate;
        }


        $interval = new \DateInterval('P1D');
        $daterange = new \DatePeriod($startDate, $interval , $endDate);

        $progress = new ProgressBar($this->output, iterator_count($daterange));

        $rapports = [];
        foreach($daterange as $date){
            $rapports[$date->format('Y')][$date->format('m')][$date->format('d')] = $this->testAlgo($date);
            $progress->advance();
        }

        $progress->finish();

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
                $totalGaniant = $totalPerdant = $totalDepense = $totalGain = 0;

                foreach ($rapportsM as $j => $rapportsJ) {
                    $totalGaniant += $rapportsJ['Cote']['ganiant'];
                    $totalPerdant += $rapportsJ['Cote']['perdant'];
                    $totalDepense += $rapportsJ['Cote']['depense'];
                    $totalGain += $rapportsJ['Cote']['gain'];

                    $pourcentageVictoires = ($rapportsJ['Cote']['ganiant'] / ($rapportsJ['Cote']['ganiant'] + $rapportsJ['Cote']['perdant'])) * 100;
                    $gain = $rapportsJ['Cote']['gain'] - $rapportsJ['Cote']['depense'];
                    $pourcentageGain = ($gain / $rapportsJ['Cote']['depense']) * 100;

                    $rowsJours[] = [$a.'-'.$m.'-'.$j, round($pourcentageVictoires, 2) . '%', round($pourcentageGain, 2) . '%', $gain . '€'];
                }


                $pourcentageVictoires = ($totalGaniant / ($totalGaniant + $totalPerdant)) * 100;
                $gain = $totalGain - $totalDepense;
                $pourcentageGain = ($gain / $totalDepense) * 100;

                $rowsMois[] = [$a.'-'.$m, round($pourcentageVictoires, 2) . '%', round($pourcentageGain, 2) . '%', $gain . '€'];


                $totalGaniantA += $totalGaniant;
                $totalPerdantA += $totalPerdant;
                $totalDepenseA += $totalDepense;
                $totalGainA += $totalGain;
            }

            $pourcentageVictoires = ($totalGaniant / ($totalGaniant + $totalPerdant)) * 100;
            $gain = $totalGain - $totalDepense;
            $pourcentageGain = ($gain / $totalDepense) * 100;

            $rowsAnnee[] = [$a, round($pourcentageVictoires, 2) . '%', round($pourcentageGain, 2) . '%', $gain . '€'];

        }

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>Resultat par jours</info>');
        $table = new Table($this->output);
        $table
            ->setHeaders(array('Date', '% victoire',  '% Gain', 'Gain en €'))
            ->setRows($rowsJours);
        $table->render();

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>Resultat par mois</info>');
        $table = new Table($this->output);
        $table
            ->setHeaders(array('Date', '% victoire',  '% Gain', 'Gain en €'))
            ->setRows($rowsMois);
        $table->render();

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>Resultat par ans</info>');
        $table = new Table($this->output);
        $table
            ->setHeaders(array('Date', '% victoire', '% Gain', 'Gain en €'))
            ->setRows($rowsAnnee);
        $table->render();
    }

    protected function testAlgo(\DateTime $date)
    {
        $rapport = [];
        foreach ($this->algos as $algo) {
            $rapport[$algo->getName()] = [
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

            $req = $this->pdo->prepare('SELECT * FROM pmu_concurrent WHERE pmu_course_id = :courseId ORDER BY pmu_position ASC');
            $req->bindParam(':courseId', $course->pmu_id);
            $req->execute();

            $concurrents = $req->fetchAll(\PDO::FETCH_OBJ);
            if (empty($concurrents)) {
                $this->output->writeln('<info>Aucun concurrents sur la course ' . $course->pmu_id . '</info>');
                continue;
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
