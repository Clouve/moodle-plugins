<?php

namespace local_studentrecords\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * IPEDS demographics of a student (all optional, "not reported" allowed).
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class demog extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $m = $this->_form;
        $p = 'local_studentrecords';
        $a = \local_studentrecords\local\admissions::class;
        foreach (['id' => PARAM_INT, 'part' => PARAM_ALPHA] as $f => $type) {
            $m->addElement('hidden', $f);
            $m->setType($f, $type);
        }
        $m->addElement('static', 'demog_intro', '', get_string('demog_intro', $p));
        $menu = fn(array $codes, string $prefix) => ['' => get_string('choosedots')] +
            array_combine($codes, array_map(fn($c) => get_string($prefix . $c, $p), $codes));
        $m->addElement('select', 'sex', get_string('demog_sex', $p), $menu($a::SEX, 'sex_'));
        $m->addElement('select', 'hispanic', get_string('demog_hispanic', $p), $menu($a::HISPANIC, 'hispanic_'));
        $races = [];
        foreach ($a::RACES as $r) {
            $races[] = $m->createElement('advcheckbox', 'race_' . $r, '', get_string('race_' . $r, $p));
        }
        $races[] = $m->createElement('advcheckbox', 'race_notreported', '', get_string('race_notreported', $p));
        $m->addGroup($races, 'racegroup', get_string('demog_race', $p), '<br>', false);
        $m->addHelpButton('racegroup', 'demog_race', $p);
        $this->add_action_buttons();
    }

    /**
     * Validation: "not reported" cannot be combined with a race.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $any = false;
        foreach (\local_studentrecords\local\admissions::RACES as $r) {
            $any = $any || !empty($data['race_' . $r]);
        }
        if ($any && !empty($data['race_notreported'])) {
            $errors['racegroup'] = get_string('err_racenotreported', 'local_studentrecords');
        }
        return $errors;
    }
}
