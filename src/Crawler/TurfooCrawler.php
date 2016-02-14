<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 13/02/16
 * Time: 11:55
 */

namespace Pmu\Crawler;




class TurfooCrawler extends AbstractCrawler
{

    const DOMAINE = 'http://www.turfoo.fr';
    const URI_COURSE_DAY = '/programmes-courses/%s/';


    public function crawlResultByDay(\DateTime $date)
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
            throw new ContinueException('Nom de la course non trouvé');
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
            $concurrent->cote = is_numeric($coteTdDom->innertext) ? $coteTdDom->innertext : 0;
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

} 