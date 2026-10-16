<?php
namespace Dualscan\CloudOS;
final class WordPressDatabase extends LogicalDatabase {
    private $wpdb;
    public function __construct($wpdb) { $this->wpdb = $wpdb; }
    protected function rows(string $sql): array {
        $old = $this->wpdb->suppress_errors(true);
        try { $rows = $this->wpdb->get_results($sql, ARRAY_A); if ($this->wpdb->last_error !== '') { throw new EngineException('Database query failed: ' . $this->wpdb->last_error); } return is_array($rows) ? $rows : []; }
        finally { $this->wpdb->suppress_errors($old); }
    }
    protected function execute(string $sql): void {
        $old = $this->wpdb->suppress_errors(true);
        try { if ($this->wpdb->query($sql) === false) { throw new EngineException('Database write failed: ' . $this->wpdb->last_error); } }
        finally { $this->wpdb->suppress_errors($old); }
    }
}
