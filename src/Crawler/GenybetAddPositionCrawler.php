<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 13/02/16
 * Time: 11:55
 */

namespace Pmu\Crawler;




use Pmu\Command\ContinueException;

class GenybetAddPositionCrawler extends GenybetCrawler
{


    protected function crawlRapports($url, &$rapport)
    {

        if ($this->output->isVerbose()) {
            $this->output->writeln('<comment>' . $url . '</comment>');
        }


        $this->progress->setMessage($rapport->date->format('Y-m-d') . ' R' . $rapport->reunionNum . 'C' . $rapport->courseNum);
        $this->progress->display();


        $rapport->courseId = $this->getCourseId($rapport);
        if (!$rapport->courseId) {
            throw new ContinueException('Course R' . $rapport->reunionNum . 'C' . $rapport->courseNum . ' n\'existe pas');
        }


        $urlResultat = str_replace('partants-pronostics', 'resultats', $rapport->url);
        $detailCourseResultatDom = $this->getDomUrl(self::DOMAINE . $urlResultat)->find('#resultats', 0);



        $this->addConcurrentsResultat($detailCourseResultatDom, $rapport);

        return true;
    }


    protected function addConcurrentsResultat(&$dom, &$rapport)
    {
        $error = true;

        $resultatTrListDom = $dom->find('.w55', 0)->find('tr');
        foreach ($resultatTrListDom as $resultatTrDom) {
            if($resultatTrDom->parent->tag == 'tbody') {
                $positionText = trim($resultatTrDom->children(0)->innertext);
                if (preg_match('/([0-9]+)/', $positionText, $matchs)) {
                    $position = $matchs[1];
                } else {
                    $position = strtolower(substr($positionText, 0, 1));
                }

                $numero = (int)trim($resultatTrDom->children(1)->innertext);

                $req = $this->pdo->prepare('UPDATE pmu_concurrent SET pmu_position = :positionNum WHERE pmu_course_id = :courseId AND pmu_numero = :numero');
                $req->bindParam(':courseId',$rapport->courseId);
                $req->bindParam(':numero',$numero);
                $req->bindParam(':positionNum',$position);
                $req->execute();

                $error = false;
            }
        }

        if ($error) {
            throw new ContinueException('Resultat de la course introuvable');
        }

        return $this;
    }


} 