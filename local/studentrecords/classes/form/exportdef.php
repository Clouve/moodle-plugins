<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_studentrecords\local\outside;

/**
 * A saved outside export: who it is for, which students, which fields in which order, own column names.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class exportdef extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $m->addElement('hidden', 'export');
        $m->setType('export', PARAM_INT);
        $m->addElement('text', 'name', get_string('exportname', $p), ['size' => 60]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        $options = fn($list, $prefix) => array_combine($list, array_map(fn($k) => get_string($prefix . $k, $p), $list));
        $m->addElement('select', 'purpose', get_string('exportpurpose', $p), $options(outside::PURPOSES, 'purpose_'));
        $m->addElement('select', 'population', get_string('exportpopulation', $p), $options(outside::POPULATIONS, 'population_'));
        $m->addElement('select', 'termid', get_string('term', $p), [0 => get_string('none')] +
            array_map('format_string', \local_studentrecords\local\catalog::terms()));
        $m->addHelpButton('termid', 'exportterm', $p);
        $m->addElement('date_selector', 'fromdate', get_string('date_from', $p), ['optional' => true]);
        $m->addElement('date_selector', 'todate', get_string('date_to', $p), ['optional' => true]);
        foreach (['fromdate', 'todate'] as $f) {
            $m->hideIf($f, 'population', 'in', ['all', 'active', 'term', 'admitted']);
        }
        $fields = $m->addElement('select', 'fields', get_string('exportfields', $p), outside::labels(), ['size' => 14]);
        $fields->setMultiple(true);
        $m->addHelpButton('fields', 'exportfields', $p);
        $m->addElement('textarea', 'headers', get_string('exportheaders', $p), ['rows' => 5, 'cols' => 50]);
        $m->setType('headers', PARAM_TEXT);
        $m->addHelpButton('headers', 'exportheaders', $p);
        $m->addElement('textarea', 'note', get_string('note', $p), ['rows' => 2, 'cols' => 60]);
        $m->setType('note', PARAM_TEXT);
        $this->add_action_buttons();
    }

    /**
     * Validation: at least one field; a term for term and admitted lists.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['fields'])) {
            $errors['fields'] = get_string('required');
        }
        if (in_array($data['population'], ['term', 'admitted'], true) && empty($data['termid'])) {
            $errors['termid'] = get_string('required');
        }
        return $errors;
    }
}
