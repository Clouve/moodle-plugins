<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A document of the application checklist (setup), for all programs or a level, credential type or program.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class reqdoc extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $catalog = \local_studentrecords\local\catalog::class;
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('text', 'name', get_string('reqdoc_name', $p), ['size' => 60]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        $m->addElement('textarea', 'description', get_string('description'), ['rows' => 2, 'cols' => 60]);
        $m->setType('description', PARAM_TEXT);
        $m->addElement('selectyesno', 'required', get_string('reqdoc_required', $p));
        $m->setDefault('required', 1);
        $m->addHelpButton('required', 'reqdoc_required', $p);
        $m->addElement('static', 'scopeintro', '', get_string('reqdoc_scope', $p));
        $m->addElement('select', 'levelid', get_string('level', $p), [0 => get_string('all')] +
            array_map('format_string', $catalog::levels()));
        $types = ['' => get_string('all')];
        foreach (\local_studentrecords\local\importer::CREDENTIALS as $t) {
            $types[$t] = get_string('cred_' . $t, $p);
        }
        $m->addElement('select', 'credentialtype', get_string('prog_credentialtype', $p), $types);
        $m->addElement('select', 'programid', get_string('program', $p), [0 => get_string('all')] + $catalog::programs(false));
        $m->addElement('text', 'sortorder', get_string('sortorder', $p), ['size' => 4]);
        $m->setType('sortorder', PARAM_INT);
        $m->addElement('advcheckbox', 'active', get_string('active', $p));
        $m->setDefault('active', 1);
        $this->add_action_buttons();
    }
}
