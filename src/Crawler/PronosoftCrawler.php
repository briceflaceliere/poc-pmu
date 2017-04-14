<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 13/02/16
 * Time: 11:55
 */

namespace Pmu\Crawler;

/**
 * Liste course du jour : https://www.pmu.fr/services/turfInfo/client/1/programme/19022016?meteo=true&specialisation=INTERNET
 *
 */

use Pmu\Command\ContinueException;

class PronosoftCrawler extends AbstractCrawler
{

    const DOMAINE = 'http://www.pronosoft.com';
    const URI_RESULT_DAY = '/fr/parions_sport/resultats_parions_sport.php?date=%s';

    public function crawlResultByDay(\DateTime $date)
    {
        try{
            //get list courses
            $url = sprintf(self::DOMAINE . self::URI_RESULT_DAY, $date->format('d/m/Y'));
            $result = $this->getDomUrl($url);
            $cotesElement = $result->find('#bg_parions .cotes tbody')[1];

            foreach($cotesElement->find('tr') as $element) {

                    $htmlClass = $element->getAttribute('class');
                    if (substr($htmlClass, 0, 4) != 'm-s-') {
                        continue;
                    }

                    list($sport, $compet, $id) = explode(' ', $htmlClass);
                    $sport = substr($sport, 4);
                    $compet = substr($compet, 4);
                    $id = substr($id, 2);

                    $this->progress->setMessage($date->format('Y-m-d') . ' MATCH ' . $id);
                    $this->progress->display();

                    if ($this->matchExists($id)) {
                        throw new ContinueException('Le match ' . $id . ' existe deja');
                    }

                    $childs = $element->children;
                    list($h, $m) = explode('h', trim($childs[1]->plaintext));
                    $horaire = clone $date;
                    $horaire->setTime($h, $m);

                    $equipe = explode('-', trim(explode("\t", $childs[2]->first_child()->plaintext)[0]));
                    $resultat = explode('-', trim($childs[3]->plaintext));
                    if (count($resultat) != 2) {
                        throw new ContinueException('Pas de resultat pour le match ' . $id . ' (' . $resultat[0] . ')');
                    }

                    $nbrParis = trim($childs[4]->plaintext) ?: 0;
                    $coteEquipe1 = trim($childs[5]->plaintext) ?: null;
                    if (!$coteEquipe1) {
                        throw new ContinueException('Pas de cote pour le match ' . $id . ' (' . $resultat[0] . ')');
                    }

                    $coteNull = trim($childs[6]->plaintext) ?: null;
                    $coteEquipe2 = trim($childs[7]->plaintext) ?: null;
                    $resultatPronosticPronosoft = trim($childs[8]->first_child()->plaintext) ?: null;
                    $resultatPronosticPronosoft = $resultatPronosticPronosoft == 'N' ? 0 : $resultatPronosticPronosoft;

                    $finalResultat = 0;
                    if ($resultat[0] > $resultat[1]) {
                        $finalResultat = 1;
                    } elseif ($resultat[0] < $resultat[1]) {
                        $finalResultat = 2;
                    }

                    $this->save('match_item', [
                        'id'                => $id,
                        'date'              => $date->format('Y-m-d'),
                        'horaire'           => $horaire->getTimestamp(),
                        'sport'             => $sport,
                        'competition'       => $compet,
                        'equipe1'           => trim($equipe[0]),
                        'equipe2'           => trim($equipe[1]),
                        'resultatEquipe1'   => (int)$resultat[0],
                        'resultatEquipe2'   => (int)$resultat[1],
                        'coteEquipe1'       => str_replace(',', '.', $coteEquipe1),
                        'coteEquipe2'       => str_replace(',', '.', $coteEquipe2),
                        'coteNull'          => $coteNull ? str_replace(',', '.', $coteNull) : null,
                        'resultat'          => $finalResultat,
                        'resultatPronosticPronosoft' => (int)$resultatPronosticPronosoft,
                        'nbrParis'         => (int)$nbrParis,
                    ]);

            }

        } catch (ContinueException $e){
            $this->output->writeln('<info>' . $e->getMessage() . '</info>');
        }
        
    }
} 