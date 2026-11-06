<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A student's application checklist: each document received, waived or missing, with date and note.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class checklist extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        foreach (['id' => PARAM_INT, 'part' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $states = [];
        foreach (\local_studentrecords\local\admissions::DOCSTATES as $st) {
            $states[$st] = get_string('docstate_' . $st, $p);
        }
        foreach ($this->_customdata['docs'] as $doc) {
            $label = format_string($doc->name) . ($doc->required ? '' : ' (' . get_string('optional', $p) . ')');
            $m->addElement('header', 'h_' . $doc->id, $label);
            $m->setExpanded('h_' . $doc->id, true);
            if ($doc->description) {
                $m->addElement('static', 'd_' . $doc->id, '', s($doc->description));
            }
            $m->addElement('select', 'state_' . $doc->id, get_string('docstate', $p), $states);
            $m->addElement(
                'date_selector',
                'date_' . $doc->id,
                get_string('docstatedate', $p),
                ['timezone' => \local_studentrecords\local\catalog::timezone()]
            );
            $m->hideIf('date_' . $doc->id, 'state_' . $doc->id, 'eq', 'missing');
            $m->addElement('text', 'note_' . $doc->id, get_string('notes', $p), ['size' => 60]);
            $m->setType('note_' . $doc->id, PARAM_TEXT);
        }
        $this->add_action_buttons();
    }
}
