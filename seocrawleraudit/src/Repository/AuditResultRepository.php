<?php

namespace PrestaShop\Module\Seocrawleraudit\Repository;

use Doctrine\DBAL\Connection;

class AuditResultRepository
{
    private Connection $connection;
    private string $table;

    public function __construct(Connection $connection, string $databasePrefix)
    {
        $this->connection = $connection;
        $this->table = $databasePrefix . 'seocrawler_audit';
    }

    public function truncate(): void
    {
        $this->connection->executeStatement(sprintf('TRUNCATE TABLE `%s`', $this->table));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function bulkInsert(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $sql = sprintf(
            'INSERT INTO `%s` (`url`, `page_type`, `entity_id`, `issue_type`, `severity`, `details`, `content_hash`, `similarity_hash`, `word_count`, `created_at`) VALUES (:url, :page_type, :entity_id, :issue_type, :severity, :details, :content_hash, :similarity_hash, :word_count, NOW())',
            $this->table
        );

        $count = 0;
        foreach ($rows as $row) {
            $this->connection->executeStatement($sql, [
                'url' => (string) ($row['url'] ?? ''),
                'page_type' => (string) ($row['page_type'] ?? ''),
                'entity_id' => (int) ($row['entity_id'] ?? 0),
                'issue_type' => (string) ($row['issue_type'] ?? ''),
                'severity' => (string) ($row['severity'] ?? 'warning'),
                'details' => (string) ($row['details'] ?? ''),
                'content_hash' => (string) ($row['content_hash'] ?? ''),
                'similarity_hash' => (string) ($row['similarity_hash'] ?? ''),
                'word_count' => (int) ($row['word_count'] ?? 0),
            ]);
            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(array $filters): array
    {
        $where = '';
        $params = [];

        if (!empty($filters['issue_type'])) {
            $where = 'WHERE issue_type = :issue_type';
            $params['issue_type'] = (string) $filters['issue_type'];
        }

        $sql = sprintf(
            'SELECT id_seocrawler_audit, url, page_type, entity_id, issue_type, severity, details, word_count, created_at
             FROM `%s`
             %s
             ORDER BY id_seocrawler_audit DESC
             LIMIT :limit OFFSET :offset',
            $this->table,
            $where
        );

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', (int) ($filters['limit'] ?? 100), \PDO::PARAM_INT);
        $statement->bindValue('offset', (int) ($filters['offset'] ?? 0), \PDO::PARAM_INT);

        return $statement->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters): int
    {
        $where = '';
        $params = [];

        if (!empty($filters['issue_type'])) {
            $where = 'WHERE issue_type = :issue_type';
            $params['issue_type'] = (string) $filters['issue_type'];
        }

        $sql = sprintf('SELECT COUNT(*) FROM `%s` %s', $this->table, $where);

        return (int) $this->connection->executeQuery($sql, $params)->fetchOne();
    }

    /**
     * @return array<int, string>
     */
    public function getDistinctIssueTypes(): array
    {
        $sql = sprintf('SELECT DISTINCT issue_type FROM `%s` ORDER BY issue_type ASC', $this->table);

        return array_map(
            static fn (array $row): string => (string) $row['issue_type'],
            $this->connection->fetchAllAssociative($sql)
        );
    }
}
