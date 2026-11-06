<?php

namespace local_academictranscript;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for db/install.xml: no table or column may use an SQL reserved word.
 *
 * Moodle quotes reserved words when it creates tables, but not in the column list of an insert, so a
 * column named like a reserved word installs fine and then breaks the first insert ('system' on MySQL 8).
 *
 * @package    local_academictranscript
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
     * Every table and field name in install.xml is safe to use unquoted.
     */
    public function test_no_reserved_names(): void {
        $xml = simplexml_load_file(dirname(__DIR__) . '/db/install.xml');
        $this->assertNotFalse($xml);
        $bad = [];
        foreach ($xml->TABLES->TABLE as $table) {
            foreach ($table->FIELDS->FIELD as $field) {
                $name = (string)$field['NAME'];
                if (in_array(strtolower($name), self::RESERVED, true)) {
                    $bad[] = $table['NAME'] . '.' . $name;
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
