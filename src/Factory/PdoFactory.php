<?php
/**
 * Created by PhpStorm.
 * User: brice
 * Date: 07/02/16
 * Time: 18:37
 */

namespace Pmu\Factory;


class PdoFactory {
    protected function __construct(){}

    protected static $pdo;

    public static function GetConnection()
    {
        if (!self::$pdo) {
            self::$pdo = new \PDO('mysql:host=' . getenv('MYSQL_PORT_3306_TCP_ADDR') . ';dbname=' . getenv('MYSQL_ENV_MYSQL_DATABASE'),
                                getenv('MYSQL_ENV_MYSQL_USER'),
                                getenv('MYSQL_ENV_MYSQL_PASSWORD'));
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return self::$pdo;
    }
}