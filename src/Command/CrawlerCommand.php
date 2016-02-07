<?php

namespace Pmu\Command;

use Pmu\Factory\PdoFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Sunra\PhpSimple\HtmlDomParser;

class CrawlerCommand extends Command
{
    const DOMAINE = 'http://www.turfomania.fr';
    const URI_COURSE_DAY = '/arrivees-rapports/index.php?choixdate=%s';

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

    protected function configure()
    {
        $this
            ->setName('crawler')
            ->setDescription('Crawl result of turf')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->pdo = PdoFactory::GetConnection();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = new \DateTime();
        $date->setTimestamp(time() - (60*60*24));

        $this->crawlResultByDay($date);
    }

    protected function crawlResultByDay(\DateTime $date)
    {
        $this->output->writeln('<info>Crawl list courses for ' . $date->format('Y-m-d') . '</info>');
        //get list courses
        $data = [];
        $url = sprintf(self::DOMAINE . self::URI_COURSE_DAY, $date->format('d/m/Y'));

        if ($this->output->isVerbose()) {
            $this->output->writeln('<comment>' . $url . '</comment>');
        }


        $dom = HtmlDomParser::file_get_html($url);
        $elems = $dom->find('#colTwo .trOne .btn');

        foreach($elems as $elem) {
            $rapport = new \StdClass();
            $rapport->date = $date;

            $href = str_replace(['..', 'partants-'], [self::DOMAINE, 'rapports-'], $elem->href);
            $this->crawlRapports($href, $rapport);

            var_dump($rapport);
            break;
        }
    }

    protected function crawlRapports($url, &$rapport)
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln('<comment>' . $url . '</comment>');
        }

        $dom = HtmlDomParser::file_get_html($url);
        $detailCourseInfosDom = $dom->find('#detailCourseInfos', 0);

        $this->addCourseTurfomaniaId($url, $rapport)
             ->addCourseReunion($detailCourseInfosDom, $rapport)
             ->addTime($detailCourseInfosDom, $rapport)
             ->addHyppodrome($detailCourseInfosDom, $rapport)
             ->addCourseName($detailCourseInfosDom, $rapport)
             ->addCourseCaracteristiques($dom, $rapport);

        if ($this->courseExist($rapport)) {
            $this->output->writeln('<comment>Course ' . $rapport->turfomaniaId . ' existe deja</comment>');
        } else {
            $this->createCourse($rapport);
        }

        return $this;
    }

    protected function addCourseTurfomaniaId($url, &$rapport)
    {
        if (!preg_match('/idcourse=([0-9]+)/i', $url, $matchs)) {
            throw new \Exception('Course : turfomaniaId non trouvé');
        }
        $rapport->turfomaniaId = (int)$matchs[1];

        return $this;
    }

    protected function addCourseReunion(&$detailCourseInfosDom, &$rapport)
    {
        if (!preg_match('/R([0-9]+)C([0-9]+)/i', $detailCourseInfosDom->find('#detailCourseLiveRC', 0)->innertext, $matchs)) {
            throw new \Exception('Course et reunion non trouvé');
        }

        $rapport->reunionNum = (int)$matchs[1];
        $rapport->courseNum = (int)$matchs[2];

        return $this;
    }

    protected function addTime(&$detailCourseInfosDom, &$rapport)
    {
        list($h, $m) = explode('h', $detailCourseInfosDom->find('#detailCourseLiveHeure', 0)->innertext);
        $rapport->date->setTime($h, $m);

        return $this;
    }

    protected function addHyppodrome(&$detailCourseInfosDom, &$rapport)
    {

        $detailCourseLiveHippodromeDom = $detailCourseInfosDom->find('#detailCourseLiveHippodrome a', 0);
        $name = $detailCourseLiveHippodromeDom->innertext;

        if (!preg_match('/idhippo=([0-9]+)/i', $detailCourseLiveHippodromeDom->href, $matchs)) {
            throw new \Exception('Hyppodrome : turfomaniaId non trouvé');
        }

        $hyppodromeId = $this->searchOrCreateHyppodrome((int)$matchs[1], $name);
        if (!$hyppodromeId) {
            throw new \Exception('Hyppodrome inconnu');
        }

        $rapport->hyppodromeId = (int)$hyppodromeId;

        return $this;
    }

    protected function addCourseName(&$detailCourseInfosDom, &$rapport)
    {
        $detailCourseInfosDom->find('#detailCourseAutresCourse', 0)->innertext;

        if (!preg_match('/-\s(.+)/i', $detailCourseInfosDom->find('#detailCourseAutresCourse', 0)->innertext, $matchs)) {
            throw new \Exception('Nom de la course non trouvé');
        }

        $rapport->name = $matchs[1];

        return $this;
    }

    protected function addCourseCaracteristiques(&$dom, &$rapport)
    {
        $caractText = $dom->find('.detailCourseCaract p', 0)->innertext;

        //type
        if (preg_match('/(Plat|Steeple|Haies|Attelé|Monté)/i', $caractText, $matchs)) {
            $rapport->type = strtolower(substr($matchs[1], 0, 1));
        } else {
            $rapport->type = 'p';
        }

        //distance
        if (preg_match('/([0-9]+[\.,]{0,1}[0-9]+)\s{0,1}m/i', $caractText, $matchs)) {
            $rapport->distance = (int)str_replace('.', '', $matchs[1]);
        } else {
            $rapport->distance = null;
        }

        return $this;
    }

    protected function searchOrCreateHyppodrome($turfomaniaId, $name)
    {
        //search by turfomania id
        $req = $this->pdo->prepare('SELECT pmu_hyppodrome_id
                            FROM pmu_turfomania
                            WHERE pmu_hyppodrome_id IS NOT NULL
                            AND pmu_turfomania_id = :turfomaniaId
                            LIMIT 1');
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();
        $id = $req->fetchColumn();

        if ($id) {
            return $id;
        }

        //search by name
        $req = $this->pdo->prepare('SELECT pmu_id
                            FROM pmu_hyppodrome
                            WHERE pmu_name LIKE :name
                            LIMIT 1');
        $req->bindParam(':name', $name);
        $req->execute();
        $id = $req->fetchColumn();

        if ($id) {
            return $id;
        }

        // not found create new hyppodrome
        $this->output->writeln('<info>Create new hyppodrome "' . $name . '"</info>');

        $req = $this->pdo->prepare('INSERT INTO pmu_hyppodrome (pmu_name) VALUES (:name)');
        $req->bindParam(':name', $name);
        $req->execute();
        $id = $this->pdo->lastInsertId();

        $req = $this->pdo->prepare('INSERT INTO pmu_turfomania (pmu_turfomania_id, pmu_hyppodrome_id) VALUES (:turfomaniaId, :id)');
        $req->bindParam(':id', $id);
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();

        return $id;
    }

    protected function courseExist(&$rapport)
    {
        $req = $this->pdo->prepare('SELECT count(*)
                            FROM pmu_course
                            WHERE pmu_date = :dateCourse
                            AND pmu_course_num = :courseNum
                            AND pmu_reunion_num = :reunionNum');
        $req->bindParam(':dateCourse', $rapport->date->format('Y-m-d'));
        $req->bindParam(':courseNum', $rapport->courseNum);
        $req->bindParam(':reunionNum', $rapport->reunionNum);
        $req->execute();
        $count = $req->fetchColumn();

        return ($count > 0);
    }

    protected function createCourse(&$rapport)
    {
        $this->output->writeln('<info>Create new course "' . $rapport->date->format('Y-m-d') . ' - R' . $rapport->reunionNum . 'C'. $rapport->courseNum . ' "</info>');

        $req = $this->pdo->prepare('INSERT INTO pmu_course(
                              pmu_course_num,
                              pmu_reunion_num,
                              pmu_name,
                              pmu_date,
                              pmu_horaire,
                              pmu_type,
                              pmu_distance,
                              pmu_hyppodrome_id
                            )
                            VALUES (

                            )');
        $req->bindParam(':dateCourse', $rapport->date->format('Y-m-d'));
        $req->bindParam(':courseNum', $rapport->courseNum);
        $req->bindParam(':reunionNum', $rapport->reunionNum);
        $req->execute();
        $count = $req->fetchColumn();

        return ($count > 0);
    }
}
