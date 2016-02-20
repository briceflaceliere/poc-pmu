<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 09/02/16
 * Time: 08:56
 */

namespace Pmu\Algo;


use Pmu\Command\ContinueException;
use Pmu\Factory\PdoFactory;

class MusiqueV2Algo implements AlgoInterface {

    protected $pdo;

    public function __construct(){
        $this->pdo = PdoFactory::GetConnection();
    }


    public function getName()
    {
        return 'musique-v2';
    }

    public function byNumero($course, $concurrents)
    {
        $result = [];
        foreach ($concurrents as $concurrent) {
            $result[$concurrent->pmu_numero] = $this->getScore($course, $concurrent);
        }

        return $result;
    }

    public function byScore($course, $concurrents)
    {

        $results = [];
        foreach ($concurrents as $concurrent) {
            $result = new \StdClass();
            $result->id = $concurrent->pmu_id;
            $result->numero = $concurrent->pmu_numero;
            $result->score = $this->getScore($course, $concurrent);
            $result->cote = $concurrent->pmu_cote;
            $results[] = $result;
        }

        usort($results, function ($a, $b) {
            if ($a->score == $b->score) {
                return 0;
            }
            return ($a->score > $b->score) ? -1 : 1;
        });


        return $results;
    }

    public function getWinner($course, $concurrents)
    {
        $byScore = $this->byScore($course, $concurrents);
        $pos1 = array_pop($byScore);
        $pos2 = array_pop($byScore);

        if ($pos1->score == $pos2->score) {


            if ($pos1->cote < $pos2->cote && $pos1->cote != 0) {
                return $pos1;
            } else if ($pos1->cote > $pos2->cote && $pos2->cote != 0) {
                return $pos2;
            }

            throw new ContinueException('Impossible de determiné avec précision le 1er de la course (1er => pos ' . $pos1->score . ' cote ' . $pos1->cote . ' | 2eme => ' . $pos2->score .  ' cote ' . $pos2->cote . ')');
        }

        return $pos1;
    }

    protected function getScore($course, $concurent)
    {
        $query = $this->pdo->prepare('SELECT h.pmu_position FROM pmu_concurrent h
                             LEFT JOIN pmu_course c ON h.pmu_course_id = c.pmu_id
                             WHERE c.pmu_date < :date
                             AND h.pmu_cheval_id = :chevalId
                             ORDER BY c.pmu_date DESC
                             LIMIT 4');
        $query->bindParam(':date', $course->pmu_date);
        $query->bindParam(':chevalId', $concurent->pmu_cheval_id);
        $query->execute();
        $musiques = $query->fetchAll(\PDO::FETCH_COLUMN);

        //$musiques = explode('-', $concurent->pmu_musique);

        $score = 0;

        foreach ($musiques as $musique) {
            $musique = strtolower(substr($musique, 0, 1));
            if (is_numeric($musique) && $musique >= 1 && $musique <= 8) {
                $score += (100 / $musique);
            } else if ($musique == 'd') {
                $score += (100 / 6);
            } else if ($musique == 'r') {
                $score += 100 / 6;
            } else if ($musique == 'a') {
                $score += 100 / 11;
            } else if ($musique == 't') {
                $score += 100 / 11;
            } else {
                $score += 100 / 11;
            }
        }
        if(count($musiques) != 0) {
            $score =  round($score / count($musiques));
        }

        return $score;
    }
}