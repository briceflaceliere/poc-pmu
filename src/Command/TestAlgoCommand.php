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
            ->addArgument('startDate', InputArgument::OPTIONAL, 'start date', '2014-01-01')
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

        $req = $this->pdo->prepare('DELETE FROM pmu_test_algo_historique WHERE pmu_algo = :algo');
        $req->bindParam(':algo', $this->algo->getName());
        $req->execute();

        $this->progress = new ProgressBar($this->output, iterator_count($daterange));
        $this->progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% <info>%message%</info>    ');

        $rapports = ['D' => [], 'M' => [], 'Y' => [], 'countCourses' => 0, 'excludeCourses' => 0, 'winCourses' => 0, 'winCoursesNullCote' => 0];
        foreach($daterange as $date){
            $this->progress->setMessage($date->format('Y-m-d'));
            $this->progress->advance();
            $this->testAlgo($date, $rapports);
        }

        $this->progress->finish();

        $this->formatRapports($rapports);

        $total = (microtime(true) - $timestart) / 60;

        $this->output->writeln('<info>Test terminated in  ' . $total . 'm </info>');
    }

    protected function formatRapports(&$rapports)
    {
        foreach ($rapports as $type => $data) {
            $rows = [];
            if (in_array($type, ['M', 'D', 'Y'])) {
                foreach ($data as $date => $rapport) {
                    $pourcentageVictoires = round(($rapport['ganiant'] / ($rapport['ganiant'] + $rapport['perdant'])) * 100, 2);
                    $benef = round($rapport['gain'] - $rapport['depense'], 2);
                    $pourcentageBenef = round(($benef / $rapport['depense']) * 100, 2);

                    $rows[] = [$date, $pourcentageVictoires . '%', $rapport['depense'] . '€', $rapport['gain'] . '€', $pourcentageBenef . '%', $benef . '€'];

                }

                $this->output->writeln('');
                $this->output->writeln('');
                $typeName = ['M' => 'Mois', 'D' => 'Jours', 'Y' => 'Années'];
                $this->output->writeln('<info>Resultat par ' . $typeName[$type] . '</info>');
                $table = new Table($this->output);
                $table
                    ->setHeaders(array('Date', '% victoire', 'Depense', 'Gain', '% Benef', 'Benef en €'))
                    ->setRows($rows);
                $table->render();
            }
        }

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>Courses gagniante sans cote : ' . $rapports['winCoursesNullCote'] . '/' . $rapports['winCourses'] . ' (' . round(($rapports['winCoursesNullCote'] / $rapports['winCourses']) * 100, 2). '%)</info>');
        $this->output->writeln('<info>Courses exclus : ' . $rapports['excludeCourses'] . '/' . $rapports['countCourses'] . ' (' . round(($rapports['excludeCourses'] / $rapports['countCourses']) * 100, 2). '%)</info>');
    }

    protected function testAlgo(\DateTime $date, &$rapports)
    {
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

            //generate rapport
            try {
                $rapports['countCourses']++;

                if (!$gagnant) {
                    throw new ContinueException('Aucun gagniant sur la course ' . $course->pmu_id);
                }

                $algoGagant = $this->algo->getWinner($course, $concurrents);

                if ($algoGagant->numero == $gagnant->pmu_numero) {
                    $rapports['winCourses']++;
                    if ( $gagnant->pmu_cote <= 1 ) {
                        $rapports['winCoursesNullCote']++;
                    }
                    $this->addToRapport($date, 1, 0, 1, $gagnant->pmu_cote, $course, $gagnant, $algoGagant, $rapports);
                } else {
                    $this->addToRapport($date, 0, 1, 1, 0, $course, $gagnant, $algoGagant, $rapports);
                }
            } catch (ContinueException $e) {
                $rapports['excludeCourses']++;
                $this->output->writeln("\n".'<comment>' . $e->getMessage() . '</comment>');
            }
        }

        return $this;

    }

    protected function addToRapport(\DateTime $date, $gagniant, $perdant, $depense, $gain, &$course, &$gagnant, &$algoGagant, &$rapports)
    {
        $dateDayKey = $date->format('Y-m-d');
        $dateMouthKey = $date->format('Y-m');
        $dateYearKey = $date->format('Y');

        foreach (['D' => $dateDayKey, 'M' => $dateMouthKey, 'Y' => $dateYearKey] as $rapportType => $rapportDate) {
            if (!isset($rapports[$rapportType][$rapportDate])) {
                $rapports[$rapportType][$rapportDate] = ['ganiant' => 0, 'perdant' => 0, 'depense' => 0, 'gain' => 0];
            }
            $rapports[$rapportType][$rapportDate]['ganiant'] += $gagniant;
            $rapports[$rapportType][$rapportDate]['perdant'] += $perdant;
            $rapports[$rapportType][$rapportDate]['depense'] += $depense;
            $rapports[$rapportType][$rapportDate]['gain'] += $gain;
        }

        $req = $this->pdo->prepare('INSERT INTO pmu_test_algo_historique(
                              pmu_algo,
                              pmu_course_id,
                              pmu_course_winner_id,
                              pmu_algo_winner_id,
                              pmu_depense,
                              pmu_cote,
                              pmu_gain,
                              pmu_benef
                            )
                            VALUES (
                              :algo,
                              :courseId,
                              :courseWinnerId,
                              :algoWinnerId,
                              :depense,
                              :cote,
                              :gain,
                              :benef
                            )');
        $benef = $gain - $depense;
        $req->bindParam(':algo', $this->algo->getName());
        $req->bindParam(':courseId', $course->pmu_id);
        $req->bindParam(':courseWinnerId', $gagnant->pmu_id);
        $req->bindParam(':algoWinnerId', $algoGagant->pmu_id);
        $req->bindParam(':depense', $depense);
        $req->bindParam(':cote', $gagnant->cote);
        $req->bindParam(':gain', $gain);
        $req->bindParam(':benef', $benef);
        $req->execute();
    }




}
