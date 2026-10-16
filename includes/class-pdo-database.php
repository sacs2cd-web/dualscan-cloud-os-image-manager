<?php
namespace Dualscan\CloudOS;
final class PdoDatabase extends LogicalDatabase {
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true); }
    protected function rows(string $sql): array { return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC); }
    protected function execute(string $sql): void { $this->pdo->exec($sql); }
}
