<?php
declare(strict_types=1);

/**
 * Minimal in-memory PDO/PDOStatement double for daemon-free unit & contract tests.
 */
final class MockStatement extends PDOStatement
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;
    private int $colCount;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(array $rows = [], int $colCount = 0)
    {
        $this->rows = $rows;
        $this->colCount = $colCount;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function columnCount(): int
    {
        return $this->colCount;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

final class MockPdo extends PDO
{
    public bool $txActive = false;
    public int $commits = 0;
    public int $rollbacks = 0;

    /** @var callable(string, array): array{rows: array<int, array<string, mixed>>, cols?: int} */
    private $handler;

    /** @param callable(string, array): array{rows: array<int, array<string, mixed>>, cols?: int} $handler */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function beginTransaction(): bool
    {
        $this->txActive = true;
        return true;
    }

    public function commit(): bool
    {
        $this->txActive = false;
        $this->commits++;
        return true;
    }

    public function rollBack(): bool
    {
        $this->txActive = false;
        $this->rollbacks++;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->txActive;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $handler = $this->handler;
        return new class($query, $handler) extends PDOStatement {
            private string $sql;
            /** @var callable */
            private $handler;
            /** @var array<int, array<string, mixed>> */
            private array $rows = [];
            private int $cols = 0;

            public function __construct(string $sql, callable $handler)
            {
                $this->sql = $sql;
                $this->handler = $handler;
            }

            public function execute(?array $params = null): bool
            {
                $res = ($this->handler)($this->sql, $params ?? []);
                $this->rows = $res['rows'] ?? [];
                $this->cols = $res['cols'] ?? (count($this->rows) > 0 ? count($this->rows[0]) : 0);
                return true;
            }

            public function columnCount(): int
            {
                return $this->cols;
            }

            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
            {
                return $this->rows;
            }
        };
    }
}

function make_pdo_exception(string $sqlstate, string $message): PDOException
{
    $e = new PDOException($message);
    $rp = new ReflectionProperty(PDOException::class, 'code');
    $rp->setValue($e, $sqlstate);
    $e->errorInfo = [$sqlstate, 0, $message];
    return $e;
}
