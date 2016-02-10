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

    protected function configure()
    {
        $this
            ->setName('crawler')
            ->setDescription('Crawl result of turf')
            ->addArgument('startDate', InputArgument::OPTIONAL, 'start date', null)
            ->addArgument('endDate', InputArgument::OPTIONAL, 'end date', null)
            ->addOption('first', null, InputOption::VALUE_NONE, 'First crawl (Crawl tous les jour depuis 2014-01-01)');
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
        } else if ($input->getOption('first')) {
            $startDate = new \DateTime('2014-01-01');
        } else {
            $startDate = clone $hierDate;
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

        $progress = new ProgressBar($this->output, iterator_count($daterange));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% <info>%message%</info>');

        foreach($daterange as $date){
            $progress->setMessage($date->format('Y-m-d'));
            $progress->advance();
            $this->crawlResultByDay($date);

        }

        $progress->finish();

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

                $this->crawlRapports(self::DOMAINE . $elem->href, $rapport);
            }

            $this->pdo->commit();
        } catch (Exception $e) {
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

        //@todo check course exist


        $dom = $this->getDomUrl($url);

        $detailCourseResultatDom = $dom->find('.resultat', 0);

        $this->addCourseCaracteristiques($detailCourseResultatDom, $rapport)
             ->addHyppodromeAndCourseName($detailCourseResultatDom, $rapport);

        /*
        if ($this->courseExists($rapport)) {
            $this->output->writeln('<comment>Course ' . $rapport->turfomaniaId . ' existe deja</comment>');
        } else {
            $rapport->id = $this->createCourse($rapport);*/

            //$this->addConcurrents($detailCourseResultatDom, $rapport);
       /* }*/

        //var_dump($rapport);

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

        $concurrentsList = [];

        foreach ($concurentsTrDom as $concurentDom) {
            $concurrentsList[] = $this->addConcurrent($concurentDom, $rapport);

            /*if($concurentsTableDom->parent->id == 'colTwo') {

                //Tr only tbody
                $concurentsTrListDom = $concurentsTableDom->find('tr');
                foreach ($concurentsTrListDom as $concurentTrDom) {
                    if ($concurentTrDom->parent->tag == 'tbody') {
                        $this->addConcurrent($concurentTrDom, $rapport);
                    }
                }
            }*/
        }

        if (empty($concurrentsList)) {
            throw new \Exception('Aucun concurrents');
        }
    }

    protected function addConcurrent(&$concurentTrDom, &$rapport)
    {
        $concurrent = new \StdClass();

        list($positionDom, $numeroDom, $chevalDom, $jockeyDom, $entraineurDom, $commentaireDom, $coteDom) = $concurentTrDom->find('td');

        if (!$coteDom && $this->output->isVerbose()) {
            $this->output->writeln('<comment>Course ' . $rapport->turfomaniaId . ' annuler</comment>');
            return $this;
        }

        $concurrent->courseId = $rapport->id;
        $concurrent->position = $positionDom->innertext;
        $concurrent->numero = (int)$numeroDom->innertext;
        $concurrent->cote = (float)$coteDom->innertext ? (float)$coteDom->innertext : null;

        //search cheval
        $chevalName = $chevalDom->children(0)->children(0)->innertext;
        if (empty($chevalName)) {
            $this->output->writeln('<info>1 cheval non renseigner</info>');
            return $this;
        }

        if (!preg_match('/_([0-9]+)$/', $chevalDom->children(0)->href, $match)){
            throw new \Exception('TurfomaniaId du Cheval non trouvé');
        }
        $chevalTurfomaniaId = (int)$match[1];
        $concurrent->chevalId = $this->searchOrCreateCheval($chevalTurfomaniaId, $chevalName);
        if (!$concurrent->chevalId) {
            throw new \Exception('Cheval not found');
        }

        //search jockey
        $jockeyName = $jockeyDom->children(0)->innertext;
        if (!preg_match('/idjockey=([0-9]+)$/', $jockeyDom->children(0)->href, $match)){
            throw new \Exception('TurfomaniaId du jockey non trouvé');
        }
        $jockeyTurfomaniaId = (int)$match[1];
        $concurrent->jockeyId = $this->searchOrCreateJockey($jockeyTurfomaniaId, $jockeyName);
        if (!$concurrent->jockeyId) {
            throw new \Exception('Jockey not found');
        }

        //search jockey
        $entraineurName = $entraineurDom->children(0)->innertext;
        if (!preg_match('/identraineur=([0-9]+)$/', $entraineurDom->children(0)->href, $match)){
            throw new \Exception('TurfomaniaId de l\'entraineur non trouvé');
        }
        $entraineurTurfomaniaId = (int)$match[1];
        $concurrent->entraineurId = $this->searchOrCreateEntraineur($entraineurTurfomaniaId, $entraineurName);
        if (!$concurrent->entraineurId) {
            throw new \Exception('Entraineur not found');
        }

        //$this->createConcurrent($concurrent);

        return $concurrent;
    }

    protected function searchOrCreateCheval($turfomaniaId, $name)
    {
        //search by turfomania id
        $req = $this->pdo->prepare('SELECT pmu_cheval_id
                            FROM pmu_turfomania
                            WHERE pmu_cheval_id IS NOT NULL
                            AND pmu_turfomania_id = :turfomaniaId
                            LIMIT 1');
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();
        $id = $req->fetchColumn();

        if ($id) {
            return (int)$id;
        }

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

        $req = $this->pdo->prepare('INSERT INTO pmu_turfomania (pmu_turfomania_id, pmu_cheval_id) VALUES (:turfomaniaId, :id)');
        $req->bindParam(':id', $id);
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();

        return (int)$id;
    }

    protected function searchOrCreateEntraineur($turfomaniaId, $name)
    {
        //search by turfomania id
        $req = $this->pdo->prepare('SELECT pmu_entraineur_id
                            FROM pmu_turfomania
                            WHERE pmu_entraineur_id IS NOT NULL
                            AND pmu_turfomania_id = :turfomaniaId
                            LIMIT 1');
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();
        $id = $req->fetchColumn();

        if ($id) {
            return (int)$id;
        }

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

        $req = $this->pdo->prepare('INSERT INTO pmu_turfomania (pmu_turfomania_id, pmu_entraineur_id) VALUES (:turfomaniaId, :id)');
        $req->bindParam(':id', $id);
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();

        return (int)$id;
    }

    protected function searchOrCreateJockey($turfomaniaId, $name)
    {
        //search by turfomania id
        $req = $this->pdo->prepare('SELECT pmu_jockey_id
                            FROM pmu_turfomania
                            WHERE pmu_jockey_id IS NOT NULL
                            AND pmu_turfomania_id = :turfomaniaId
                            LIMIT 1');
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();
        $id = $req->fetchColumn();

        if ($id) {
            return (int)$id;
        }

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

        $req = $this->pdo->prepare('INSERT INTO pmu_turfomania (pmu_turfomania_id, pmu_jockey_id) VALUES (:turfomaniaId, :id)');
        $req->bindParam(':id', $id);
        $req->bindParam(':turfomaniaId', $turfomaniaId);
        $req->execute();

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

    protected function createCourse(&$rapport)
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
        $req->bindParam(':horaire', $rapport->date->format('h:i'));
        $req->bindParam(':type', $rapport->type);
        $req->bindParam(':distance', $rapport->distance);
        $req->bindParam(':hyppodromeId', $rapport->hyppodromeId);
        $req->execute();
        $id =  $this->pdo->lastInsertId();

        $req = $this->pdo->prepare('INSERT INTO pmu_turfomania (pmu_turfomania_id, pmu_course_id) VALUES (:turfomaniaId, :id)');
        $req->bindParam(':id', $id);
        $req->bindParam(':turfomaniaId', $rapport->turfomaniaId);
        $req->execute();

        return $id;
    }

    protected function createConcurrent(&$concurrent)
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
        $req->bindParam(':courseId', $concurrent->courseId);
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $data = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpcode>=200 && $httpcode<300) {
                $data = HtmlDomParser::str_get_html($data);

                if (!$data) {
                    throw new \Exception('Parse content from ' . $url . ' error ');
                }

                return $data;
            }
            sleep(10);
        }

        throw new \Exception('Get content from ' . $url . ' error ' . $httpcode);
    }
}
