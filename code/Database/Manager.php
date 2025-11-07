<?php

namespace Database;

use PDO;
use Throwable;
use Exception;
use Database\QueryBuilder;

final class Manager
{
    private ?PDO $pdo = null; // 初回利用時に作成して以降再利用
    private $dbname;
    private $host;
    private $port;
    private $user;
    private $password;


    public function __construct(
        $dbname,
        $host,
        $port,
        $user,
        $password
    ){
        $this->dbname = $dbname;
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
    }


    /** 遅延でPDO生成し、以後はキャッシュを返す */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    'mysql:dbname=' . $this->dbname . ';host=' . $this->host . ($this->port ? ';port=' . $this->port : ''),
                    $this->user,
                    $this->password,
                    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8']
                );

                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                throw new Exception("DB接続に失敗しました。", __FILE__, __LINE__);
            }
        }
        return $this->pdo;
    }


    /** クエリビルダを開始 */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }


    /** @return list<array<string,mixed>> */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(is_int($k) ? $k + 1 : (string)$k, $v);
        }
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }


    /** @return array<string,mixed>|null */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(is_int($k) ? $k + 1 : (string)$k, $v);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }


    /** 変更行数を返す */
    public function statement(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(is_int($k) ? $k + 1 : (string)$k, $v);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @template T @param callable(self):T $fn @return T */
    public function transaction(callable $fn)
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
