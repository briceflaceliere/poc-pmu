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

class MusiqueAlgo implements AlgoInterface {

    protected $pdo;

    public function __construct(){
        $this->pdo = PdoFactory::GetConnection();
    }


    public function getName()
    {
        return 'musique';
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
        /*$req = $this->pdo->prepare('SELECT con.pmu_position, d.pmu_date FROM pmu_concurrent con INNER JOIN pmu_course d ON con.pmu_course_id = d.pmu_id WHERE con.pmu_cheval_id = :chevalId AND d.pmu_date < :dayDate ORDER BY d.pmu_date DESC LIMIT 10');
        $req->bindParam(':chevalId', $concurent->pmu_cheval_id);
        $req->bindParam(':dayDate', $course->pmu_date);

        $req->execute();
        $musiques = $req->fetchAll(\PDO::FETCH_OBJ);*/

        $musiques = explode('-', $concurent->pmu_musique);


        $score = 0;

        foreach ($musiques as $musique) {
            if ($musique->pmu_position >= 1 && $musique->pmu_position <= 8) {
                $score += 100 / $musique->pmu_position;
            } else if (strtolower(substr($musique->pmu_position, 0, 1)) == 'd') {
                $score += 100 / 6;
            } else if (strtolower(substr($musique->pmu_position, 0, 1)) == 'r') {
                $score += 100 / 6;
            } else if (strtolower(substr($musique->pmu_position, 0, 1)) == 'a') {
                $score += 100 / 11;
            } else if (strtolower(substr($musique->pmu_position, 0, 1)) == 't') {
                $score += 100 / 11;
            } else {
                $score += 100 / 11;
            }
        }
        if(count($musique) != 0) {
            $score =  round($score / count($musique));
        }

        return $score;
    }
}