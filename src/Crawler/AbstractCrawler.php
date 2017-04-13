<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 13/02/16
 * Time: 11:55
 */

namespace Pmu\Crawler;

use Pmu\Command\CurlException;
use Pmu\Factory\PdoFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Sunra\PhpSimple\HtmlDomParser;

abstract class AbstractCrawler
{
    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var \Pdo
     */
    protected $pdo;

    protected $progress;

    abstract public function crawlResultByDay(\DateTime $date);

    public function initialise($input, $output, $progress)
    {
        $this->input = $input;
        $this->output = $output;
        $this->progress = $progress;

        $this->pdo = PdoFactory::GetConnection();
    }

    protected function matchExists($id)
    {
        $req = $this->pdo->prepare('SELECT id
                            FROM match_item
                            WHERE id = :id');
        $req->bindParam(':id', $id);
        $req->execute();

        return $req->fetchColumn();
    }

    protected function save($table, $collumns)
    {
        $keys = array_keys($collumns);
        $bindKeys = array_map(function($elm) {
            return ':' . $elm;
        }, $keys);

        $query = 'INSERT INTO ' . $table . '(' . implode(',', $keys) . ')
                      VALUES (' . implode(',', $bindKeys) . ')';

        $stm = $this->pdo->prepare($query);

        $params = [];
        foreach ($collumns as $key => $value) {
            $params[$key] = $value;
        }
        try {
            $stm->execute($params);
        } catch (\Exception $e) {
            var_dump($collumns);
            throw $e;
        }

        return $this->pdo->lastInsertId();
    }

    protected function getCurlResult($url)
    {

        //cache
        if (!$this->input->getOption('no-cache')) {
            $query = $this->pdo->prepare('SELECT data FROM curl_cache WHERE cache_key = SHA1(:url)');
            $query->bindParam(':url', $url);
            $query->execute();

            if ($data = $query->fetchColumn()) {
                if ($this->output->isVerbose()) {
                    $this->output->writeln('<comment>' . $url . ' in cache</comment>');
                }
                return $data;
            }
        }

        if ($this->output->isVerbose()) {
            $this->output->writeln('<comment>' . $url . '</comment>');
        }

        //no cache
        $httpcode = null;

        for ($i = 0; $i < 3; $i++) {
            //usleep(rand(1, 5000));

            $ch = curl_init();
            $ip = rand(1, 150) . '.' . rand(1, 150) . '.' . rand(1, 150) . '.' . rand(1, 150);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("REMOTE_ADDR: $ip", "HTTP_X_FORWARDED_FOR: $ip"));

            $data = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode>=200 && $httpcode<300) {
                //save cache
                $query = $this->pdo->prepare('REPLACE INTO curl_cache (cache_key, data) VALUES(SHA1(:url), :data)');
                $query->bindParam(':url', $url);
                $query->bindParam(':data', $data);
                $query->execute();

                return $data;
            }
            sleep(10);
        }

        throw new CurlException('Get content from ' . $url . ' error ' . $httpcode);
    }

    protected function getApiResult($url)
    {
        $data = json_decode($this->getCurlResult($url));

        if (!$data) {
            throw new CurlException('Parse json from ' . $url . ' error ');
        }

        return $data;
    }


    protected function getDomUrl($url)
    {
        $data = HtmlDomParser::str_get_html($this->getCurlResult($url));

        if (!$data) {
            throw new CurlException('Parse html content from ' . $url . ' error ');
        }

        return $data;
    }
} 