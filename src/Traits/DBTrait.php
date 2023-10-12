<?php

namespace AlexMorbo\React\Trassir\Traits;

use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Result;

use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

trait DBTrait
{
    protected DatabaseInterface $db;

    protected function initDB(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    protected function dbInsert(string $table, array $data): PromiseInterface
    {
        return $this->db
            ->query(
                'INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') '.
                'VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
                array_values($data)
            )->then(
                function (Result $result) {
                    return resolve($result->insertId);
                },
                function (\Exception $e) {
                    return reject($e->getMessage());
                }
            );
    }

    protected function dbSearch(string $table, array $where = [], array $select = ['*']): PromiseInterface
    {
        $query = 'SELECT ' . implode(', ', $select) . ' FROM ' . $table;
        $params = [];
        if (!empty($where)) {
            $query .= ' WHERE ';
            $whereQuery = [];
            foreach ($where as $key => $value) {
                $whereQuery[] = $key . ' = ?';
                $params[] = $value;
            }
            $query .= implode(' AND ', $whereQuery);
        }
        return $this->db
            ->query($query, $params)
            ->then(
                function (Result $result) {
                    return resolve($result->rows);
                },
                function (\Exception $e) {
                    return reject($e->getMessage());
                }
            );
    }

    protected function dbDelete(string $table, array $where = []): PromiseInterface
    {
        $query = 'DELETE FROM ' . $table;
        $params = [];
        if (!empty($where)) {
            $query .= ' WHERE ';
            $whereQuery = [];
            foreach ($where as $key => $value) {
                $whereQuery[] = $key . ' = ?';
                $params[] = $value;
            }
            $query .= implode(' AND ', $whereQuery);
        }
        return $this->db
            ->query($query, $params)
            ->then(
                function (Result $result) {
                    return resolve($result->changed);
                },
                function (\Exception $e) {
                    return reject($e->getMessage());
                }
            );
    }

    public function dbUpdate(string $table, array $data, array $where = []): PromiseInterface
    {
        $query = 'UPDATE ' . $table . ' SET ';
        $params = [];
        $updateQuery = [];
        foreach ($data as $key => $value) {
            $updateQuery[] = $key . ' = ?';
            $params[] = $value;
        }
        $query .= implode(', ', $updateQuery);
        if (!empty($where)) {
            $query .= ' WHERE ';
            $whereQuery = [];
            foreach ($where as $key => $value) {
                $whereQuery[] = $key . ' = ?';
                $params[] = $value;
            }
            $query .= implode(' AND ', $whereQuery);
        }
        return $this->db
            ->query($query, $params)
            ->then(
                function (Result $result) {
                    return resolve($result->changed);
                },
                function (\Exception $e) {
                    return reject($e->getMessage());
                }
            );
    }
}