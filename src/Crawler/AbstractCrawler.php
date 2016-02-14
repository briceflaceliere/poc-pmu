<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 13/02/16
 * Time: 11:55
 */

namespace Pmu\Crawler;

use Pmu\Command\CurlException;
use Pmu\Factory\PdoFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Sunra\PhpSimple\HtmlDomParser;

abstract class AbstractCrawler
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

    protected $progress;

    abstract public function crawlResultByDay(\DateTime $date);

    public function initialise($input, $output, $progress)
    {
        $this->input = $input;
        $this->output = $output;
        $this->progress = $progress;

        $this->pdo = PdoFactory::GetConnection();
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
                              pmu_cote,
                              pmu_position,
                              pmu_musique
                            )
                            VALUES (
                              :courseId,
                              :chevalId,
                              :jockeyId,
                              :entraineurId,
                              :numero,
                              :cote,
                              :positionNum,
                              :musique
                            )');
        $req->bindParam(':courseId', $courseId);
        $req->bindParam(':chevalId', $concurrent->chevalId);
        $req->bindParam(':jockeyId', $concurrent->jockeyId);
        $req->bindParam(':entraineurId', $concurrent->entraineurId);
        $req->bindParam(':numero', $concurrent->numero);
        $req->bindParam(':positionNum', $concurrent->position);
        $req->bindParam(':cote', $concurrent->cote);
        $req->bindParam(':musique', $concurrent->musique);
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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

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


} 