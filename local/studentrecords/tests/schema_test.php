<?php

namespace local_studentrecords;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for db/install.xml: no reserved words, and table names short enough for Moodle 4.5.
 *
 * @package    local_studentrecords
 * @category   test
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 * @coversNothing
 */
final class schema_test extends \basic_testcase {
    /** @var string[] Reserved words of MySQL 8, MariaDB and PostgreSQL that could pass for a table or column name. */
    const RESERVED = ['accessible', 'add', 'all', 'alter', 'analyze', 'and', 'array', 'as', 'asc', 'between', 'both',
        'by', 'call', 'case', 'cast', 'change', 'check', 'collate', 'column', 'condition', 'constraint', 'convert',
        'create', 'cross', 'cube', 'current', 'current_date', 'current_time', 'current_user', 'cursor', 'database',
        'default', 'delete', 'desc', 'describe', 'distinct', 'div', 'do', 'drop', 'each', 'else', 'empty', 'end',
        'except', 'exists', 'explain', 'false', 'fetch', 'for', 'force', 'foreign', 'from', 'full', 'function', 'grant',
        'group', 'grouping', 'groups', 'having', 'if', 'ignore', 'in', 'index', 'inner', 'insert', 'interval', 'into',
        'is', 'join', 'key', 'keys', 'kill', 'lag', 'lateral', 'lead', 'leading', 'left', 'like', 'limit', 'lines',
        'load', 'lock', 'match', 'mod', 'natural', 'not', 'null', 'of', 'offset', 'on', 'only', 'option', 'or', 'order',
        'out', 'outer', 'over', 'partition', 'percent_rank', 'primary', 'procedure', 'range', 'rank', 'read', 'recursive',
        'references', 'release', 'rename', 'repeat', 'replace', 'require', 'return', 'returning', 'revoke', 'right',
        'row', 'rows', 'schema', 'select', 'session_user', 'set', 'show', 'some', 'system', 'system_user', 'table',
        'then', 'to', 'trailing', 'trigger', 'true', 'union', 'unique', 'unlock', 'update', 'usage', 'use', 'user',
        'using', 'values', 'when', 'where', 'while', 'window', 'with', 'write'];

    /**
     * Table and field names are safe, and table names fit the 28-character limit of older Moodle versions.
     */
    public function test_names(): void {
        $xml = simplexml_load_file(dirname(__DIR__) . '/db/install.xml');
        $this->assertNotFalse($xml);
        $bad = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $name = (string)$table['NAME'];
            if (strlen($name) > 28 || !str_starts_with($name, 'local_studentrecords_')) {
                $bad[] = $name;
            }
            foreach ($table->FIELDS->FIELD as $field) {
                if (in_array(strtolower((string)$field['NAME']), self::RESERVED, true)) {
                    $bad[] = $name . '.' . $field['NAME'];
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
