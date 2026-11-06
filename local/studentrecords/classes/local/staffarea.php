<?php

namespace local_studentrecords\local;

/**
 * The Student Records staff area: its own entry page and menu, outside Site administration. Each page is shown
 * to those with its capability.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class staffarea {
    /** @var string The staff area entry page. */
    const URL = '/local/studentrecords/staff.php';

    /** @var array Staff pages in menu order: name => [file, language string, capability]. */
    const PAGES = [
        'students' => ['students.php', 'studentrecords', 'local/studentrecords:viewstudents'],
        'admissions' => ['admissions.php', 'admissionsreports', 'local/studentrecords:managestudents'],
        'standing' => ['standing.php', 'academicstanding', 'local/studentrecords:managestudents'],
        'gradechanges' => ['gradechange.php', 'gradechanges', 'local/studentrecords:managestudents'],
        'graduation' => ['graduation.php', 'graduation', 'local/studentrecords:managestudents'],
        'reports' => ['reports.php', 'reports', 'local/studentrecords:managestudents'],
        'outsidereports' => ['outsidereports.php', 'outsidereports', 'local/studentrecords:managestudents'],
        'billing' => ['billing.php', 'billing', 'local/studentrecords:viewbilling'],
        'refunds' => ['refunds.php', 'refunds', 'local/studentrecords:viewbilling'],
        'aid' => ['aid.php', 'federalaid', 'local/studentrecords:viewaid'],
        'billingreports' => ['billingreports.php', 'billingreports', 'local/studentrecords:viewbilling'],
        'billingsetup' => ['billingsetup.php', 'billingsetup', 'local/studentrecords:managebilling'],
        'offerings' => ['offerings.php', 'offerings', 'local/studentrecords:configure'],
        'terms' => ['terms.php', 'terms', 'local/studentrecords:configure'],
        'programs' => ['programs.php', 'programs', 'local/studentrecords:configure'],
        'courses' => ['courses.php', 'courses', 'local/studentrecords:configure'],
        'grading' => ['grading.php', 'grading', 'local/studentrecords:configure'],
        'checklist' => ['checklist.php', 'appchecklist', 'local/studentrecords:configure'],
        'saprules' => ['saprules.php', 'saprules', 'local/studentrecords:configure'],
        'faculty' => ['faculty.php', 'facultyrecords', 'local/studentrecords:viewfaculty'],
        'roster' => ['roster.php', 'roster', 'local/studentrecords:viewfaculty'],
        'import' => ['import.php', 'importtitle', 'local/studentrecords:import'],
        'setup' => ['setup.php', 'setupwizard', 'local/studentrecords:configure'],
        'messages' => ['messages.php', 'automessages', 'local/studentrecords:configure'],
        'log' => ['log.php', 'auditlog', 'local/studentrecords:viewlog'],
    ];

    /**
     * Whether the current user may open a staff page.
     *
     * @param string $name key of PAGES
     * @return bool
     */
    public static function can_open(string $name): bool {
        if ($name === 'students') {
            return access::sees_all_students();
        }
        if ($name === 'aid' && !aid::enabled()) {
            // The federal aid module is off unless the school turns it on.
            return false;
        }
        return has_capability(self::PAGES[$name][2], \context_system::instance());
    }

    /**
     * Staff pages the current user may open, name => [url, label].
     *
     * @return array
     */
    public static function pages(): array {
        $out = [];
        if (!isloggedin() || isguestuser()) {
            return $out;
        }
        foreach (self::PAGES as $name => [$file, $string]) {
            if (self::can_open($name)) {
                $out[$name] = [new \moodle_url('/local/studentrecords/' . $file), get_string($string, 'local_studentrecords')];
            }
        }
        return $out;
    }

    /**
     * Whether the current user may enter the staff area (at least one staff page).
     *
     * @return bool
     */
    public static function can_enter(): bool {
        return (bool)self::pages();
    }

    /**
     * Start a staff page: login, capability, page layout, title and breadcrumbs (Student Records > Staff area > page).
     *
     * @param string $name key of PAGES
     * @param \moodle_url|null $url page URL (default: the page file)
     */
    public static function setup(string $name, ?\moodle_url $url = null): void {
        global $PAGE;
        require_login(null, false);
        $syscontext = \context_system::instance();
        if (!self::can_open($name)) {
            throw new \required_capability_exception($syscontext, self::PAGES[$name][2], 'nopermissions', '');
        }
        self::start_page($url ?? new \moodle_url('/local/studentrecords/' . self::PAGES[$name][0]), $name);
        $PAGE->navbar->add(get_string(self::PAGES[$name][1], 'local_studentrecords'), $PAGE->url);
    }

    /**
     * Page settings shared by the staff area pages (system context, standard layout, Student Records breadcrumbs).
     *
     * @param \moodle_url $url
     * @param string|null $current key of PAGES being shown, for the title, if any
     */
    public static function start_page(\moodle_url $url, ?string $current = null): void {
        global $PAGE;
        $p = 'local_studentrecords';
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('standard');
        $title = $current ? get_string(self::PAGES[$current][1], $p) : get_string('staffarea', $p);
        $PAGE->set_title($title . ' - ' . get_string('pluginname', $p));
        $PAGE->set_heading($title);
        $PAGE->navbar->ignore_active();
        $PAGE->navbar->add(get_string('pluginname', $p), new \moodle_url(home::URL));
        $PAGE->navbar->add(get_string('staffarea', $p), new \moodle_url(self::URL));
    }

    /**
     * The page header followed by the Student Records menu (shown in the left drawer by the Student Records theme).
     *
     * @return string HTML
     */
    public static function header(): string {
        global $OUTPUT;
        return $OUTPUT->header() . shell::inline_menu();
    }
}
