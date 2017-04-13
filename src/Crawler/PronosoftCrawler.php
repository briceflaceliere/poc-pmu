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

        //get list courses
        $url = sprintf(self::DOMAINE . self::URI_RESULT_DAY, $date->format('d/m/Y'));
        $result = $this->getDomUrl($url);
        $cotesElement = $result->find('#bg_parions .cotes tbody')[1];

        foreach($cotesElement->find('tr') as $element) {
            try{
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

                var_dump($element->getAttribute('class'));

                if ($this->courseExists($id)) {
                    throw new ContinueException('Le match ' . $id . ' existe deja');
                }

                $this->save('course', [
                    'id'                => $id,
                    'date'              => $date->format('Y-m-d'),
                    'horaire'           => $horaire,
                    'course_num'        => $course->numOrdre,
                    'reunion_num'       => $course->numReunion,
                    'name'              => $course->libelle,
                    'type'              => $course->discipline,
                    'statut'            => $course->statut,
                    'cat_statut'        => $course->categorieStatut,
                    'hyppodrome_code'   => $reunion->hippodrome->code,
                    'corde'             => $course->corde,
                    'montant_prix'      => $course->montantPrix,
                    'distance'          => $course->distance,
                    'cat_particularite' => $course->categorieParticularite,
                    'cond_sexe'         => $course->conditionSexe,
                    'conditions'        => $course->conditions,
                    'nbr_partants'      => $course->nombreDeclaresPartants,
                    'pays_code'         => $reunion->pays->code,
                ]);
            } catch (ContinueException $e){
                $this->output->writeln('<info>' . $e->getMessage() . '</info>');
            }
        }
        /*foreach($result->programme->reunions as $reunion) {
            foreach($reunion->courses as $course) {
                try{
                    $this->pdo->beginTransaction();

                    $horaire = substr($course->heureDepart, 0, 10);
                    $date = new \DateTime();
                    $date->setTimestamp($horaire);

                    $this->progress->setMessage($date->format('Y-m-d') . ' R' . $course->numReunion . 'C' . $course->numOrdre);
                    $this->progress->display();

                    if ($this->courseExists($date, $course->numOrdre, $course->numReunion)) {
                        throw new ContinueException('La course existe deja');
                    }

                    if ($course->distanceUnit != 'METRE') {
                        throw new \Exception('Distance n\'est pas en metre : ' . $course->distanceUnit);
                    }


                    $courseId = $date->format('Ymd') . str_pad($course->numReunion, 2, "0", STR_PAD_LEFT) . str_pad($course->numOrdre, 2, "0", STR_PAD_LEFT);
                    $this->save('course', [
                        'id'                => $courseId,
                        'date'              => $date->format('Y-m-d'),
                        'horaire'           => $horaire,
                        'course_num'        => $course->numOrdre,
                        'reunion_num'       => $course->numReunion,
                        'name'              => $course->libelle,
                        'type'              => $course->discipline,
                        'statut'            => $course->statut,
                        'cat_statut'        => $course->categorieStatut,
                        'hyppodrome_code'   => $reunion->hippodrome->code,
                        'corde'             => $course->corde,
                        'montant_prix'      => $course->montantPrix,
                        'distance'          => $course->distance,
                        'cat_particularite' => $course->categorieParticularite,
                        'cond_sexe'         => $course->conditionSexe,
                        'conditions'        => $course->conditions,
                        'nbr_partants'      => $course->nombreDeclaresPartants,
                        'pays_code'         => $reunion->pays->code,
                    ]);

                    $url = sprintf(self::DOMAINE . self::URI_DETAIL_COURSE, $date->format('dmY'), $course->numReunion, $course->numOrdre);
                    $this->crawlDetailCourse($url, $courseId);

                    $url = sprintf(self::DOMAINE . self::URI_RAPPORT, $date->format('dmY'), $course->numReunion, $course->numOrdre);
                    $this->crawlRapportCourse($url, $courseId);

                    $this->pdo->commit();
                } catch (ContinueException $e){
                    $this->pdo->rollBack();
                    if ($this->output->isVerbose()) {
                        $this->output->writeln('<info>' . $e->getMessage() . '</info>');
                    }
                } catch (\Exception $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
        }*/
    }

    protected function crawlRapportCourse($url, $courseId)
    {
        $result = $this->getApiResult($url);

        if (empty($result)) {
            throw new ContinueException('Aucun rapport');
        }

        foreach ($result as $typeRapport) {
            foreach ($typeRapport->rapports as $rapport) {
                if (!isset($rapport->combinaison[0]) && is_numeric($rapport->combinaison[0])) {
                    continue;
                }

                $rapportId = $this->save('rapport', [
                    'course_id'            => $courseId,
                    'type'                 => $typeRapport->typePari,
                    'mise_base'            => $rapport->dividendeUnite == 'PourUnEuro' ? 1 : $rapport->miseBase / 100,
                    'dividende'            => $rapport->dividende / 100,
                    'concurrent_1'         => isset($rapport->combinaison[0]) && is_numeric($rapport->combinaison[0]) ? $courseId .  str_pad($rapport->combinaison[0], 2, "0", STR_PAD_LEFT) : null,
                    'concurrent_2'         => isset($rapport->combinaison[1]) && is_numeric($rapport->combinaison[1]) ? $courseId .  str_pad($rapport->combinaison[1], 2, "0", STR_PAD_LEFT) : null,
                    'concurrent_3'         => isset($rapport->combinaison[2]) && is_numeric($rapport->combinaison[2]) ? $courseId .  str_pad($rapport->combinaison[2], 2, "0", STR_PAD_LEFT) : null,
                    'concurrent_4'         => isset($rapport->combinaison[3]) && is_numeric($rapport->combinaison[3]) ? $courseId .  str_pad($rapport->combinaison[3], 2, "0", STR_PAD_LEFT) : null,
                    'concurrent_5'         => isset($rapport->combinaison[4]) && is_numeric($rapport->combinaison[4]) ? $courseId .  str_pad($rapport->combinaison[4], 2, "0", STR_PAD_LEFT) : null,
                ]);
            }
        }
    }
} 