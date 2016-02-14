<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 13/02/16
 * Time: 11:55
 */

namespace Pmu\Crawler;




use Pmu\Command\ContinueException;

class GenybetCrawler extends AbstractCrawler
{

    const DOMAINE = 'https://www.genybet.fr';
    const URI_COURSE_DAY = '/reunions/%s';


    public function crawlResultByDay(\DateTime $date)
    {
        try {
            $this->pdo->beginTransaction();

            //get list courses
            $url = sprintf(self::DOMAINE . self::URI_COURSE_DAY, $date->format('d-m-Y'));

            if ($this->output->isVerbose()) {
                $this->output->writeln('<comment>' . $url . '</comment>');
            }

            $dom = $this->getDomUrl($url);

            $elems = $dom->find('#programme .bloc-reunion');

            foreach($elems as $elem) {
                $rapport = new \StdClass();
                $rapport->date = $date;

                $tableContainerDom = $elem->find('.table-container', 0);
                if (preg_match('/reunion-([0-9]+)/', $tableContainerDom->id, $matchs)) {
                    $rapport->reunionNum = (int)$matchs[1];
                } else {
                    throw new \Exception('Num reunion non trouvé');
                }

                $trListDom = $tableContainerDom->find('table tr');
                foreach($trListDom as $trDom) {
                    if($trDom->parent->tag == 'tbody') {
                        $rapportCourse = clone $rapport;
                        $this->addCourseMainInformation($trDom, $rapportCourse);

                        try{
                            $this->crawlRapports(self::DOMAINE . $rapportCourse->url, $rapportCourse);
                        } catch (ContinueException $e){
                            $this->output->writeln('<info>' . $e->getMessage() . '</info>');
                            exit();
                        }
                    }
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


        $this->progress->setMessage($rapport->date->format('Y-m-d') . ' R' . $rapport->reunionNum . 'C' . $rapport->courseNum);
        $this->progress->display();

        if ($this->courseExists($rapport)) {
            throw new ContinueException('Course R' . $rapport->reunionNum . 'C' . $rapport->courseNum . ' existe deja');
        }

        $dom = $this->getDomUrl($url);
        $detailCoursePartantsDom = $dom->find('#partants-pronostics', 0);

        $urlResultat = str_replace('partants-pronostics', 'resultats', $rapport->url);
        $detailCourseResultatDom = $this->getDomUrl(self::DOMAINE . $urlResultat)->find('#resultats', 0);

        $this->addHyppodrome($detailCoursePartantsDom, $rapport)
             ->addCourseCaracteristiques($detailCoursePartantsDom, $rapport)
             ->addConcurrents($detailCoursePartantsDom, $rapport)
             ->addConcurrentsResultat($detailCourseResultatDom, $rapport);

        $this->saveRapport($rapport);

        return true;
    }

    protected function addCourseMainInformation(&$dom, &$rapport)
    {

        $rapport->courseNum = (int)$dom->children(0)->innertext;
        if (!$rapport->courseNum) {
            throw new \Exception('Num reunion non trouvé');
        }

        $courseNameDom = $dom->children(1)->children(0);
        $rapport->name = strtolower(trim($courseNameDom->innertext));
        if (empty($rapport->name)) {
            throw new \Exception('Nom course non trouvé');
        }

        $rapport->url = str_replace('resultats', 'partants-pronostics', $courseNameDom->href);
        if (empty($rapport->url)) {
            throw new \Exception('Url de la course non trouvé');
        }

        $rapport->type = substr(strtolower($dom->children(3)->children(0)->title), 0, 1);
        if (empty($rapport->type)) {
            throw new \Exception('Type de la course non trouvé');
        }

        list($h, $m) = explode(':', $dom->children(4)->innertext);
        $rapport->date->setTime($h, $m);

        return $this;
    }


    protected function addCourseReunion(&$dom, &$rapport)
    {

        $reunionDom = $dom->find('.reunion .action', 0);
        if ($reunionDom && !empty($reunionDom->innertext) && preg_match('/R([0-9]+)/', $reunionDom->innertext, $matchs)) {
            $rapport->reunionNum = (int)$matchs[1];
        } else {
            throw new \Exception('Num reunion non trouvé');
        }


        $courseDom = $dom->find('.courses .action', 0);
        if ($courseDom && !empty($courseDom->innertext) && preg_match('/C([0-9]+)/', $courseDom->innertext, $matchs)) {
            $rapport->courseNum = (int)$matchs[1];
        } else {
            throw new \Exception('Num course non trouvé');
        }

        return $this;
    }

    protected function addHyppodrome(&$dom, &$rapport)
    {
        $lieuDom = $dom->find('h1', 0);
        if ($lieuDom && !empty($lieuDom->innertext)) {
            $rapport->hyppodromeId = $this->searchOrCreateHyppodrome(strtolower(trim($lieuDom->innertext)));
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
        $descDom = $dom->find('.description-course .description');
        $descText = "";
        foreach($descDom as $desc) {
            $descText .= $desc->innertext;
        }
        //distance
        if (!empty($descText) && preg_match('/-([0-9]+)m/i', preg_replace('/[^0-9m-]/', '', $descText), $matchs)) {
            $rapport->distance = (int)$matchs[1];
        } else {
            throw new \Exception('Distance introuvable');
        }

        return $this;
    }

    protected function addConcurrents(&$dom, &$rapport)
    {
        //Table children #colTwo
        $concurentsTrDom = $dom->find('.table-container', 0)->find('tr');
        $concurentHeaderDom = array_shift($concurentsTrDom);
        $headerTable = [];
        foreach ($concurentHeaderDom->children() as $children) {
            $headerTable[] = trim(strtolower($children->innertext));
        }

        $rapport->concurrents = [];
        foreach ($concurentsTrDom as $concurentDom) {
            if($concurentDom->parent->tag == 'tbody' && strpos('non-partant', $concurentDom->class) === false) {
                $concurrentTdList = array_combine($headerTable, $concurentDom->children());
                $concurrent = $this->addConcurrent($concurrentTdList, $rapport);
                $rapport->concurrents[$concurrent->numero] = $concurrent;
            }
        }

        if (empty($rapport->concurrents)) {
            throw new ContinueException('Aucun concurrents');
        }

        return $this;
    }

    protected function addConcurrentsResultat(&$dom, &$rapport)
    {
        $error = true;

        $resultatTrListDom = $dom->find('.w55', 0)->find('tr');
        foreach ($resultatTrListDom as $resultatTrDom) {
            if($resultatTrDom->parent->tag == 'tbody') {
                $position = strtolower(substr(trim($resultatTrDom->children(0)->innertext), 0, 1));
                $numero = (int)trim($resultatTrDom->children(1)->innertext);

                $rapport->concurrents[$numero]->position = $position;

                $error = false;
            }
        }

        if ($error) {
            throw new ContinueException('Resultat de la course introuvable');
        }

        return $this;
    }

    protected function addConcurrent(&$concurrentTdList, &$rapport)
    {
        $concurrent = new \StdClass();

        //numero
        $raceNumberTdDom = $concurrentTdList['n°'];
        if ($raceNumberTdDom && !empty($raceNumberTdDom->innertext)) {
            $concurrent->numero = (int)trim($raceNumberTdDom->innertext);
        } else {
            throw new \Exception('Numero introuvable');
        }



        //cheval
        $chevalADom = $concurrentTdList['cheval']->children(0);
        if ($chevalADom && !empty($chevalADom->innertext)) {
            $concurrent->chevalId = $this->searchOrCreateCheval(trim($chevalADom->innertext));
        } else {
            throw new \Exception('Cheval introuvable');
        }
        if (!$concurrent->chevalId) {
            throw new \Exception('Cheval introuvable');
        }

        //cote
        $liveTdDom = $concurrentTdList['live'];
        if ($liveTdDom && preg_match('/([0-9\.]+)$/', trim($liveTdDom->innertext), $matchs)) {
            $concurrent->cote = is_numeric($matchs[1]) ? (float)$matchs[1] : null;
        } else {
            $concurrent->cote = null;
        }

        //jockey
        $jockeyDom = isset($concurrentTdList['jockey']) ? $concurrentTdList['jockey']->children(0) : $concurrentTdList['driver']->children(0);
        if ($jockeyDom  &&  !empty($jockeyDom->innertext)) {
            $concurrent->jockeyId = $this->searchOrCreateJockey(trim($jockeyDom->innertext));
        } else {
            $concurrent->jockeyId = null;
        }
        if (!$concurrent->jockeyId) {
            $concurrent->jockeyId = null;
        }

        //entraineur
        $entraineurDom = $concurrentTdList['entraîneur'];
        if ($entraineurDom  &&  !empty($entraineurDom->innertext)) {
            $concurrent->entraineurId = $this->searchOrCreateEntraineur(trim($entraineurDom->innertext));
        } else {
            $concurrent->entraineurId = null;
        }
        if (!$concurrent->entraineurId) {
            $concurrent->entraineurId = null;
        }

        //musique
        $concurrent->musique = "";
        $musiqueDom = $concurrentTdList['musique'];
        if ($musiqueDom) {
            $concurrent->musique = trim(preg_replace('/\([0-9]{2}\)/', '', $musiqueDom->title));
            $concurrent->musique = trim(preg_replace('/([a-z])/', '$1-', $concurrent->musique), '-');
        } else {
            throw new \Exception('Musique introuvable');
        }



        return $concurrent;
    }

} 