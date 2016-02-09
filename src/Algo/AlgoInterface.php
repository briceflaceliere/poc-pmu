<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 09/02/16
 * Time: 08:54
 */

namespace Pmu\Algo;


interface AlgoInterface {

    public function getName();

    public function byNumero($course, $concurent);

    public function byScore($course, $concurent);
} 