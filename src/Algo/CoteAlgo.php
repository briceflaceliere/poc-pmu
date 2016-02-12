<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 09/02/16
 * Time: 08:56
 */

namespace Pmu\Algo;


use Pmu\Command\ContinueException;

class CoteAlgo implements AlgoInterface {


    public function getName()
    {
        return 'cote';
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
            $results[] = $result;
        }

        usort($results, function ($a, $b) {
            if ($a->score == $b->score) {
                return 0;
            }
            return ($a->score < $b->score) ? -1 : 1;
        });


        return $results;
    }

    public function getWinner($course, $concurrents)
    {
        $byScore = $this->byScore($course, $concurrents);
        $pos1 = array_pop($byScore);
        $pos2 = array_pop($byScore);

        if ($pos1->score == $pos2->score) {
            throw new ContinueException('Impossible de determiné avec précision le 1er de la course (1er => ' . $pos1->score . ' | 2eme => ' . $pos2->score . ')');
        }

        return $pos1;
    }

    protected function getScore($course, $concurent)
    {
        $score = $concurent->pmu_cote != null ? -($concurent->pmu_cote*100) : -10000;

        if ($score == 0){
            $score = -999;
        }
        return $score;
    }
}