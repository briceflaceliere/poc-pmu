<?php

namespace Pmu\Command;

use Pmu\Factory\PdoFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Sunra\PhpSimple\HtmlDomParser;

class CrawlerCommand extends Command
{
    const DOMAINE = 'http://www.turfoo.fr';
    const URI_COURSE_DAY = '/programmes-courses/%s/';

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

    protected $progress;

    protected function configure()
    {
        $this
            ->setName('crawler')
            ->setDescription('Crawl result of turf')
            ->addArgument('startDate', InputArgument::OPTIONAL, 'start date', null)
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

        $this->progress = new ProgressBar($this->output, iterator_count($daterange));
        $this->progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% <info>%message%</info>');

        foreach($daterange as $date){
            $this->progress->setMessage($date->format('Y-m-d'));
            $this->progress->advance();
            $this->crawlResultByDay($date);

        }

        $this->progress->finish();

        $total = (microtime(true) - $timestart) / 60;

        $this->output->writeln('<info>Crawl terminated in  ' . $total . 'm </info>');
    }

    protected function crawlResultByDay(\DateTime $date)
    {
        try {
            $this->pdo->beginTransaction();

            //get list courses
            $url = sprintf(self::DOMAINE . self::URI_COURSE_DAY, $date->format('ymd'));

            if ($this->output->isVerbose()) {
                $this->output->writeln('<comment>' . $url . '</comment>');
            }



            $dom = $this->getDomUrl($url);


            $elems = $dom->find('.programme_reunion .specialresultat a');

            foreach($elems as $elem) {
                $rapport = new \StdClass();
                $rapport->date = $date;

                try {
                    $this->crawlRapports(self::DOMAINE . $elem->href, $rapport);
                } catch (ContinueException $e){
                    $this->output->writeln('<info>' . $elem->href . ':' . $e->getMessage() . '</info>');
                }
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

    }

    protected function crawlRapports($url, &$rapport)
    {

        if ($this->output->isVerbose()) {
            $this->output->writeln('<comment>' . $url . '</comment>');
        }

        $this->addCourseReunion($url, $rapport);

        $this->progress->setMessage($rapport->date->format('Y-m-d') . ' R' . $rapport->reunionNum . 'C' . $rapport->courseNum);
        $this->progress->display();

        //@todo check course exist
        if ($this->courseExists($rapport)) {
            if ($this->output->isVerbose()) {
                $this->output->writeln('<comment>Course R' . $rapport->reunionNum . 'C' . $rapport->courseNum . ' existe deja</comment>');
            }
            return false;
        }


        $dom = $this->getDomUrl($url);

        $detailCourseResultatDom = $dom->find('.resultat', 0);

        $this->addCourseCaracteristiques($detailCourseResultatDom, $rapport)
             ->addHyppodromeAndCourseName($detailCourseResultatDom, $rapport)
             ->addConcurrents($detailCourseResultatDom, $rapport);

        $this->saveRapport($rapport);

        return true;
    }

    protected function addCourseReunion($url, &$rapport)
    {
        if (!preg_match('/reunion([0-9]+).+course([0-9]+)/i', $url, $matchs)) {
            throw new \Exception('Course et reunion non trouvé');
        }

        $rapport->reunionNum = (int)$matchs[1];
        $rapport->courseNum = (int)$matchs[2];

        return $this;
    }

    protected function addHyppodromeAndCourseName(&$dom, &$rapport)
    {

        $headDom = $dom->find('.head', 0);
        $courseNameDom = $headDom->find('.course', 0);
        if ($courseNameDom && !empty($courseNameDom->innertext)) {
            $rapport->name = $courseNameDom->innertext;
        } else {
            throw new \Exception('Nom de la course non trouvé');
        }

        $lieuDom = $headDom->find('.lieudatecourse', 0);
        if ($lieuDom && !empty($lieuDom->innertext) && preg_match('/-(.+),/', $lieuDom->innertext, $matchs)) {
            $rapport->hyppodromeId = $this->searchOrCreateHyppodrome($matchs[1]);
        } else {
            throw new \Exception('Nom de l\'hypodrome non trouvé');
        }

        if (!$rapport->hyppodromeId) {
            throw new \Exception('Hyppodrome inconnu');
        }

        return $this;
    }


    protected function addCourseCaracteristiques(&$dom, &$rapport)
    {
        $infoDom = $dom->find('.infos', 0);

        //type
        $disiplineDom = $infoDom->find('dfn[title=Discipline]', 0);
        if ($disiplineDom && !empty($disiplineDom->innertext)) {
            $rapport->type = strtolower(substr($disiplineDom->innertext, 0, 1));
        } else {
            throw new \Exception('Type introuvable');
        }

        //distance
        $distanceDom = $infoDom->find('dfn[title=Distance]', 0);
        if ($distanceDom && !empty($distanceDom->innertext) && preg_match('/([0-9]+)/i', $distanceDom->innertext, $matchs)) {
            $rapport->distance = (int)$matchs[1];
        } else {
            throw new \Exception('Distance introuvable');
        }

        //time
        $timeDom = $infoDom->find('dfn[title=Heure]', 0);
        if ($timeDom && !empty($timeDom->innertext)) {
            list($h, $m) = explode(':', $timeDom->innertext);
            $rapport->date->setTime($h, $m, 0);
        } else {
            throw new \Exception('Date introuvable');
        }

        return $this;
    }

    protected function addConcurrents(&$dom, &$rapport)
    {
        //Table children #colTwo
        $concurentsTrDom = $dom->find('.tablegreyed', 0)->find('.row');

        $rapport->concurrents = [];

        foreach ($concurentsTrDom as $concurentDom) {
            $rapport->concurrents[] = $this->addConcurrent($concurentDom, $rapport);
        }

        if (empty($rapport->concurrents)) {
            throw new ContinueException('Aucun concurrents');
        }

        return $this;
    }

    protected function addConcurrent(&$concurentTrDom, &$rapport)
    {
        $concurrent = new \StdClass();

        list($positionTdDom, $numeroTdDom, $chevalTdDom, $jockeyTdDom, $entraineurTdDom, $kmTdDom, $coteTdDom, $commentaireTdDom) = $concurentTrDom->find('td');

        //position
        $positionDom = $positionTdDom->find('.num_place', 0);
        if ($positionDom  &&  preg_match('/num_place\snum_([0-9A-Z]+)/i', $positionDom->class, $match)) {
            $concurrent->position = $match[1];
        } else {
            throw new \Exception('Position introuvable');
        }


        //numero
        if ($numeroTdDom  &&  !empty($numeroTdDom->innertext)) {
            $concurrent->numero = (int)$numeroTdDom->innertext;
        } else {
            throw new \Exception('Numero introuvable');
        }

        //cote
        if ($coteTdDom) {
            $concurrent->cote = (float)$coteTdDom->innertext;
        } else {
            throw new \Exception('Cote introuvable');
        }

        //cheval
        $chevalDom = $chevalTdDom->find('a', 0);
        if ($chevalDom  &&  !empty($chevalDom->innertext)) {
            $concurrent->chevalId = $this->searchOrCreateCheval($chevalDom->innertext);
        } else {
            throw new \Exception('Cheval introuvable');
        }
        if (!$concurrent->chevalId) {
            throw new \Exception('Cheval introuvable');
        }

        //jockey
        $jockeyDom = $jockeyTdDom->find('a', 0);
        if ($jockeyDom  &&  !empty($jockeyDom->innertext)) {
            $concurrent->jockeyId = $this->searchOrCreateJockey($jockeyDom->innertext);
        } else {
            $concurrent->jockeyId = null;
        }
        if (!$concurrent->jockeyId) {
            $concurrent->jockeyId = null;
        }

        //entraineur
        $entraineurDom = $entraineurTdDom->find('a', 0);
        if ($entraineurDom  &&  !empty($entraineurDom->innertext)) {
            $concurrent->entraineurId = $this->searchOrCreateEntraineur($entraineurDom->innertext);
        } else {
            $concurrent->entraineurId = null;
        }
        if (!$concurrent->entraineurId) {
            $concurrent->entraineurId = null;
        }


        return $concurrent;
    }

    protected function searchOrCreateCheval($name)
    {
        $name = strtolower($name);

        $req = $this->pdo->prepare('SELECT pmu_id
                            FROM pmu_cheval
                            WHERE pmu_name = :name
                            LIMIT 1');
        $req->bindParam(':name', $name);
        $req->execute();
        $id = $req->fetchColumn();

        if (!$id) {
            // not found create new cheval
            if ($this->output->isVerbose()) {
                $this->output->writeln('<info>Create new cheval "' . $name . '"</info>');
            }
            $req = $this->pdo->prepare('INSERT INTO pmu_cheval (pmu_name) VALUES (:name)');
            $req->bindParam(':name', $name);
            $req->execute();
            $id = $this->pdo->lastInsertId();
        }

        return (int)$id;
    }

    protected function searchOrCreateEntraineur($name)
    {
        $name = strtolower($name);

        $req = $this->pdo->prepare('SELECT pmu_id
                            FROM pmu_entraineur
                            WHERE pmu_name = :name
                            LIMIT 1');
        $req->bindParam(':name', $name);
        $req->execute();
        $id = $req->fetchColumn();

        if (!$id) {
            // not found create new entraineur
            if ($this->output->isVerbose()) {
                $this->output->writeln('<info>Create new entraineur "' . $name . '"</info>');
            }
            $req = $this->pdo->prepare('INSERT INTO pmu_entraineur (pmu_name) VALUES (:name)');
            $req->bindParam(':name', $name);
            $req->execute();
            $id = $this->pdo->lastInsertId();
        }

        return (int)$id;
    }

    protected function searchOrCreateJockey($name)
    {
        $name = strtolower($name);

        $req = $this->pdo->prepare('SELECT pmu_id
                            FROM pmu_jockey
                            WHERE pmu_name = :name
                            LIMIT 1');
        $req->bindParam(':name', $name);
        $req->execute();
        $id = $req->fetchColumn();

        if (!$id) {
            // not found create new jockey
            if ($this->output->isVerbose()) {
                $this->output->writeln('<info>Create new jockey "' . $name . '"</info>');
            }
            $req = $this->pdo->prepare('INSERT INTO pmu_jockey (pmu_name) VALUES (:name)');
            $req->bindParam(':name', $name);
            $req->execute();
            $id = $this->pdo->lastInsertId();
        }

        return (int)$id;
    }

    protected function searchOrCreateHyppodrome($name)
    {
        $name = strtolower($name);

        $req = $this->pdo->prepare('SELECT pmu_id
                            FROM pmu_hyppodrome
                            WHERE pmu_name = :name
                            LIMIT 1');
        $req->bindParam(':name', $name);
        $req->execute();
        $id = $req->fetchColumn();

        if (!$id) {
            // not found create new hyppodrome
            if ($this->output->isVerbose()) {
                $this->output->writeln('<info>Create new hyppodrome "' . $name . '"</info>');
            }
            $req = $this->pdo->prepare('INSERT INTO pmu_hyppodrome (pmu_name) VALUES (:name)');
            $req->bindParam(':name', $name);
            $req->execute();
            $id = $this->pdo->lastInsertId();
        }

        return (int)$id;
    }

    protected function turfomaniaCourseExists($turfomaniaId)
    {
        $req = $this->pdo->prepare('SELECT count(*)
                            FROM pmu_turfomania
                            WHERE pmu_course_id IS NOT NULL
                            AND pmu_turfomania_id = :turfomaniaId
                            LIMIT 1');
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();

        return ($req->fetchColumn() > 0);
    }

    protected function courseExists(&$rapport)
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

    protected function saveRapport(&$rapport)
    {
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
                              :courseNum,
                              :reunionNum,
                              :name,
                              :date,
                              :horaire,
                              :type,
                              :distance,
                              :hyppodromeId
                            )');
        $req->bindParam(':courseNum', $rapport->courseNum);
        $req->bindParam(':reunionNum', $rapport->reunionNum);
        $req->bindParam(':name', $rapport->name);
        $req->bindParam(':date', $rapport->date->format('Y-m-d'));
        $req->bindParam(':horaire', $rapport->date->format('H:i'));
        $req->bindParam(':type', $rapport->type);
        $req->bindParam(':distance', $rapport->distance);
        $req->bindParam(':hyppodromeId', $rapport->hyppodromeId);
        $req->execute();
        $id =  $this->pdo->lastInsertId();

        foreach($rapport->concurrents as $concurent) {
            $this->createConcurrent($id, $concurent);
        }

        return $id;
    }

    protected function createConcurrent($courseId, &$concurrent)
    {

        $req = $this->pdo->prepare('INSERT INTO pmu_concurrent(
                              pmu_course_id,
                              pmu_cheval_id,
                              pmu_jockey_id,
                              pmu_entraineur_id,
                              pmu_numero,
                              pmu_position,
                              pmu_cote
                            )
                            VALUES (
                              :courseId,
                              :chevalId,
                              :jockeyId,
                              :entraineurId,
                              :numero,
                              :position,
                              :cote
                            )');
        $req->bindParam(':courseId', $courseId);
        $req->bindParam(':chevalId', $concurrent->chevalId);
        $req->bindParam(':jockeyId', $concurrent->jockeyId);
        $req->bindParam(':entraineurId', $concurrent->entraineurId);
        $req->bindParam(':numero', $concurrent->numero);
        $req->bindParam(':position', $concurrent->position);
        $req->bindParam(':cote', $concurrent->cote);
        $req->execute();

        return $this->pdo->lastInsertId();
    }


    protected function getDomUrl($url)
    {
        $httpcode = null;

        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $data = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpcode>=200 && $httpcode<300) {
                $data = HtmlDomParser::str_get_html($data);

                if (!$data) {
                    throw new CurlException('Parse content from ' . $url . ' error ');
                }

                return $data;
            }
            sleep(10);
        }

        throw new CurlException('Get content from ' . $url . ' error ' . $httpcode);
    }

    protected function getLastCrawlDate()
    {
        $req = $this->pdo->prepare('SELECT pmu_date
                            FROM pmu_course
                            ORDER BY pmu_date DESC
                            LIMIT 1');
        $req->execute();
        $date = $req->fetchColumn();

        if (!$date) {
            return new \DateTime('2014-01-01');
        } else {
            $date = new \DateTime($date);
            $date->add(new \DateInterval('P1D'));
            return $date;
        }

    }
}

