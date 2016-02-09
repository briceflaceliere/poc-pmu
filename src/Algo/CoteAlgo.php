<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 09/02/16
 * Time: 08:56
 */

namespace Pmu\Algo;


class CoteAlgo implements AlgoInterface {


    public function getName()
    {
        return 'Cote';
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

    protected function getScore($course, $concurent)
    {

        return $concurent->pmu_cote != null ? -($concurent->pmu_cote*100) : -10000;
    }
}