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

class PmuCrawler extends AbstractCrawler
{

    const DOMAINE = 'https://www.pmu.fr';
    const URI_COURSE_DAY = '/services/turfInfo/client/1/programme/%s?meteo=true&specialisation=INTERNET';
    const URI_DETAIL_COURSE = '/services/turfInfo/client/1/programme/%s/R%s/C%s/participants?specialisation=INTERNET';
    const URI_RAPPORT = '/services/turfInfo/client/1/programme/%s/R%s/C%s/rapports-definitifs?combinaisonEnTableau=true&specialisation=INTERNET';

    public function crawlResultByDay(\DateTime $date)
    {

        //get list courses
        $url = sprintf(self::DOMAINE . self::URI_COURSE_DAY, $date->format('dmY'));
        $result = $this->getApiResult($url);

        foreach($result->programme->reunions as $reunion) {
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
        }
    }

    protected function crawlDetailCourse($url, $courseId)
    {
        $result = $this->getApiResult($url);

        if (empty($result->participants)) {
            throw new ContinueException('Aucun concurrents');
        }

        foreach ($result->participants as $participant) {


            $musiqueSplit = str_split($participant->musique);

            $participantId = $this->save('concurrent', [
                'id'                  => $courseId .  str_pad($participant->numPmu, 2, "0", STR_PAD_LEFT),
                'course_id'           => $courseId,
                'numero'              => $participant->numPmu,
                'position'            => $participant->ordreArrivee,
                'status'              => $participant->statut,
                'place_corde'         => $participant->placeCorde,
                'cheval_name'         => $this->searchOrCreateCheval($participant->nom, $participant->sexe, $participant->race),
                'cheval_age'          => $participant->age,
                'jockey_name'         => $participant->driver,
                'entraineur_name'     => $participant->entraineur,
                'proprietaire_name'   => $participant->proprietaire,
                'cote_ref'            => $participant->dernierRapportReference->rapport,
                'cote_ref_horaire'    => $participant->dernierRapportReference->dateRapport ? substr($participant->dernierRapportReference->dateRapport, 0, 10) : null,
                'cote_direct'         => $participant->dernierRapportDirect->rapport,
                'cote_direct_horaire' => $participant->dernierRapportDirect->dateRapport ? substr($participant->dernierRapportDirect->dateRapport, 0, 10) : null,
                'cheval_inedit'       => (int)$participant->indicateurInedit,
                'musique_original'    => $participant->musique,
                'musique_1_pos'       => isset($musiqueSplit[0]) ? $musiqueSplit[0] : null,
                'musique_1_type'      => isset($musiqueSplit[1]) ? $musiqueSplit[1] : null,
                'musique_2_pos'       => isset($musiqueSplit[2]) ? $musiqueSplit[2] : null,
                'musique_2_type'      => isset($musiqueSplit[3]) ? $musiqueSplit[3] : null,
                'musique_3_pos'       => isset($musiqueSplit[4]) ? $musiqueSplit[4] : null,
                'musique_3_type'      => isset($musiqueSplit[5]) ? $musiqueSplit[5] : null,
                'musique_4_pos'       => isset($musiqueSplit[6]) ? $musiqueSplit[6] : null,
                'musique_4_type'      => isset($musiqueSplit[7]) ? $musiqueSplit[7] : null,
                'handicap_valeur'     => $participant->handicapValeur,
                'handicap_poids'      => $participant->handicapPoids,
                'jument_pleine'       => (int)$participant->jumentPleine,
                'oeilleres'           => $participant->oeilleres
            ]);
        }
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