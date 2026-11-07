<?php

namespace local_studentrecords\local;

/**
 * The Student Records page shell: the menu by role (left drawer), and small shared page parts (stat tiles, status
 * tags, cards, "needs your attention" lists, quick links, empty states). Works with any theme; the Student Records
 * theme puts the menu in its left drawer, other themes show it at the top of the page.
 *
 * @package    local_studentrecords
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class shell {
    /** @var string Theme that shows the menu in its own left drawer. */
    const THEME = 'studentrecords';

    /** @var array|null Menu groups for this request. */
    protected static $groups = null;

    /** @var array Offerings each user may take attendance in, for this request: user id => offerings. */
    protected static $offerings = [];

    /**
     * Whether the page is a Student Records page (or an Academic Transcript page), so it gets the menu.
     *
     * @param string $path URL path below wwwroot, e.g. /local/studentrecords/index.php
     * @return bool
     */
    public static function is_sr_path(string $path): bool {
        return str_starts_with($path, '/local/studentrecords/') || str_starts_with($path, '/local/academictranscript/');
    }

    /**
     * The menu item that best matches a page: same path, and the most of its own parameters equal to the page's.
     * Items whose parameters do not all match the page are not picked.
     *
     * @param array $items key => [path, params]
     * @param string $path page path
     * @param array $params page parameters
     * @return string|null key
     */
    public static function best_match(array $items, string $path, array $params): ?string {
        $best = null;
        $bestscore = -1;
        foreach ($items as $key => [$itempath, $itemparams]) {
            if ($itempath !== $path) {
                continue;
            }
            $score = 0;
            foreach ($itemparams as $name => $value) {
                if (!array_key_exists($name, $params) || (string)$params[$name] !== (string)$value) {
                    continue 2;
                }
                $score++;
            }
            if ($score > $bestscore) {
                $best = $key;
                $bestscore = $score;
            }
        }
        return $best;
    }

    /**
     * Reset the menu for this request (tests, or after a change of role).
     */
    public static function reset(): void {
        self::$groups = null;
        self::$offerings = [];
    }

    /**
     * The offerings the user may take attendance in, for the teacher menu and home.
     *
     * @return array id => offer
     */
    public static function my_offerings(): array {
        global $DB, $USER;
        $uid = (int)$USER->id;
        if (isset(self::$offerings[$uid])) {
            return self::$offerings[$uid];
        }
        $out = [];
        if (isloggedin() && !isguestuser()) {
            $sql = "SELECT o.id, o.moodlecourseid
                       FROM {local_studentrecords_offer} o
                       JOIN {local_studentrecords_term} t ON t.id = o.termid
                      WHERE o.moodlecourseid > 0 AND t.active = 1";
            foreach ($DB->get_records_sql($sql) as $o) {
                $context = \context_course::instance((int)$o->moodlecourseid, IGNORE_MISSING);
                if (
                    $context && has_capability('local/studentrecords:takeattendance', $context) &&
                        (is_enrolled($context, $uid, '', true) || access::can_manage_students())
                ) {
                    $out[(int)$o->id] = $o;
                }
            }
        }
        return self::$offerings[$uid] = $out;
    }

    /**
     * The menu groups the current user may see, in order: student, records staff or grade change approver, finance,
     * financial aid, teacher, setup. Each group: ['key', 'heading', 'items' => [key => [moodle_url, label]], 'note'].
     *
     * @return array
     */
    public static function groups(): array {
        global $USER;
        if (self::$groups !== null) {
            return self::$groups;
        }
        $p = 'local_studentrecords';
        $groups = [];
        if (!isloggedin() || isguestuser()) {
            return self::$groups = [];
        }
        $sys = \context_system::instance();
        $u = fn(string $file, array $params = []) => new \moodle_url('/local/studentrecords/' . $file, $params);
        $s = fn(string $id) => get_string($id, $p);

        // Student: own pages only.
        $mine = null;
        try {
            $mine = students::get_by_user((int)$USER->id);
        } catch (\dml_exception $e) {
            $mine = null;
        }
        if ($mine && has_capability('local/studentrecords:viewown', $sys)) {
            $items = ['myhome' => [$u('index.php', ['view' => 'student']), $s('nav_myhome')]];
            if (billing::enabled()) {
                $items['myaccount'] = [$u('account.php', ['id' => $mine->id]), $s('nav_myaccount')];
            }
            $items['myrecord'] = [$u('student.php', ['id' => $mine->id]), $s('nav_myacademicrecord')];
            $items['myregistration'] = [$u('myregistration.php'), $s('nav_registration')];
            if (
                \core_component::get_component_directory('local_academictranscript') !== null &&
                    has_capability('local/academictranscript:viewown', $sys)
            ) {
                $items['mytranscript'] = [new \moodle_url('/local/academictranscript/index.php', ['view' => 'mine']),
                    $s('nav_mytranscript')];
            }
            $items['mydocuments'] = [$u('mydocuments.php'), $s('nav_mydocuments')];
            $items['mymessages'] = [new \moodle_url('/message/index.php'), $s('nav_messages')];
            $groups[] = ['key' => 'student', 'heading' => $s('pluginname'), 'items' => $items, 'note' => ''];
        }

        // Records staff (registrar).
        if (access::sees_all_students()) {
            $items = ['staffhome' => [$u('index.php', ['view' => 'staff']), $s('nav_staffhome')]];
            $items['students'] = [$u('students.php'), $s('nav_findstudent')];
            foreach (
                ['admissions' => 'nav_admissions', 'offerings' => 'nav_offerings', 'standing' => 'nav_holds',
                'gradechanges' => 'gradechanges', 'graduation' => 'graduation', 'reports' => 'reports',
                'outsidereports' => 'outsidereports'] as $name => $label
            ) {
                if (staffarea::can_open($name)) {
                    $items[$name] = [$u(staffarea::PAGES[$name][0]), $s($label)];
                }
            }
            if (
                \core_component::get_component_directory('local_academictranscript') !== null &&
                    has_capability('local/academictranscript:issueofficial', $sys) &&
                    file_exists(\core_component::get_component_directory('local_academictranscript') . '/requests.php')
            ) {
                $items['transcriptrequests'] = [new \moodle_url('/local/academictranscript/requests.php'),
                    $s('nav_transcriptrequests')];
            }
            $groups[] = ['key' => 'records', 'heading' => $s('pluginname'), 'items' => $items, 'note' => ''];
        } else if (staffarea::can_open('gradechanges')) {
            // An approver who isn't records staff: the page they decide on, and no records staff home.
            $groups[] = ['key' => 'approver', 'heading' => $s('pluginname'), 'note' => '',
                'items' => ['gradechanges' => [$u(staffarea::PAGES['gradechanges'][0]), $s('gradechanges')]]];
        }

        // Finance.
        if (billing::enabled() && has_capability('local/studentrecords:viewbilling', $sys)) {
            $items = [
                'financehome' => [$u('index.php', ['view' => 'finance']), $s('nav_financehome')],
                'billing' => [$u('billing.php'), $s('nav_studentaccounts')],
                'payments' => [$u('billingreports.php', ['report' => 'payments']), $s('nav_payments')],
                'plans' => [$u('billingreports.php', ['report' => 'late']), $s('nav_paymentplans')],
                'refunds' => [$u('refunds.php'), $s('refunds')],
                'tax1098' => [$u('billingreports.php', ['report' => 'tax1098']), $s('nav_1098t')],
                'billingreports' => [$u('billingreports.php', ['report' => 'owed']), $s('reports')],
            ];
            $groups[] = ['key' => 'finance', 'heading' => $s('nav_finance'), 'items' => $items, 'note' => ''];
        }

        // Financial aid (only when the federal aid module is on).
        if (aid::enabled() && has_capability('local/studentrecords:viewaid', $sys)) {
            $items = [
                'aidhome' => [$u('index.php', ['view' => 'aid']), $s('nav_aidhome')],
                'aidstudents' => [$u('aid.php', ['report' => 'students']), $s('nav_studentawards')],
                'aiddisb' => [$u('aid.php', ['report' => 'run']), $s('nav_disbursements')],
                'aidreturn' => [$u('aid.php', ['report' => 'returns']), $s('nav_returnofaid')],
                'aidverif' => [$u('aid.php', ['report' => 'verification']), $s('nav_verification')],
                'aidreports' => [$u('aid.php', ['report' => 'recipients']), $s('reports')],
            ];
            $groups[] = ['key' => 'aid', 'heading' => $s('nav_financialaid'), 'items' => $items,
                'note' => $s('nav_aidnote')];
        }

        // Teacher.
        if (self::my_offerings()) {
            $items = ['myclasses' => [$u('index.php', ['view' => 'teacher']), $s('nav_myclasses')],
                'atrisk' => [$u('index.php', ['view' => 'atrisk']), $s('nav_atrisk')]];
            $groups[] = ['key' => 'teacher', 'heading' => $s('nav_teaching'), 'items' => $items, 'note' => ''];
        }

        // Setup.
        $setup = [];
        foreach (
            ['setup' => 'setupwizard', 'terms' => 'terms', 'programs' => 'programs', 'courses' => 'courses',
            'grading' => 'grading', 'checklist' => 'appchecklist', 'saprules' => 'saprules', 'billingsetup' => 'billingsetup',
            'messages' => 'automessages', 'faculty' => 'facultyrecords', 'roster' => 'roster', 'import' => 'importtitle',
            'log' => 'auditlog'] as $name => $label
        ) {
            if (staffarea::can_open($name)) {
                $setup[$name] = [$u(staffarea::PAGES[$name][0]), $s($label)];
            }
        }
        if ($setup) {
            $setup['staffarea'] = [new \moodle_url(staffarea::URL), $s('nav_allstaffpages')];
            $groups[] = ['key' => 'setup', 'heading' => $s('nav_setupheading'), 'items' => $setup, 'note' => ''];
        }
        return self::$groups = $groups;
    }

    /**
     * Key of the menu item for the page being shown.
     *
     * @param \moodle_url $url page URL
     * @return string|null "group:item"
     */
    public static function current(\moodle_url $url): ?string {
        $items = [];
        foreach (self::groups() as $g) {
            foreach ($g['items'] as $key => [$itemurl]) {
                $items[$g['key'] . ':' . $key] = [(string)parse_url($itemurl->out_omit_querystring(), PHP_URL_PATH),
                    $itemurl->params()];
            }
        }
        $path = (string)parse_url($url->out_omit_querystring(), PHP_URL_PATH);
        return self::best_match($items, $path, $url->params());
    }

    /**
     * The menu: one list per group with its heading.
     *
     * @param \moodle_url $url page URL (marks the current item)
     * @param bool $withheadings show the group headings (the first heading is shown by the theme drawer)
     * @return string HTML, or '' when the user has no Student Records pages
     */
    public static function menu(\moodle_url $url, bool $withheadings = true): string {
        $groups = self::groups();
        if (!$groups) {
            return '';
        }
        $current = self::current($url);
        $out = '';
        foreach ($groups as $i => $g) {
            $links = '';
            foreach ($g['items'] as $key => [$itemurl, $label]) {
                $attrs = ['class' => 'sr-nav-link'];
                if ($current === $g['key'] . ':' . $key) {
                    $attrs['aria-current'] = 'page';
                    $attrs['class'] .= ' active';
                }
                $links .= \html_writer::tag('li', \html_writer::link($itemurl, $label, $attrs));
            }
            $headid = \html_writer::random_id('srnav');
            $heading = ($withheadings || $i > 0) ? \html_writer::div(s($g['heading']), 'sr-nav-heading', ['id' => $headid]) : '';
            $note = $g['note'] !== '' ? \html_writer::tag('p', s($g['note']), ['class' => 'sr-nav-note']) : '';
            $out .= \html_writer::tag('nav', $heading . \html_writer::tag('ul', $links, ['class' => 'sr-nav-list']) . $note, [
                'class' => 'sr-nav-group',
                'aria-label' => $g['heading'] . ' - ' . get_string('nav_menu', 'local_studentrecords'),
            ]);
        }
        return \html_writer::div($out, 'sr-nav');
    }

    /**
     * The menu for the theme's left drawer: ['heading' => first group's heading, 'html' => menu], or null.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public static function drawer(\moodle_page $page): ?array {
        if (!self::is_sr_url($page->url)) {
            return null;
        }
        $groups = self::groups();
        if (!$groups) {
            return null;
        }
        return ['heading' => $groups[0]['heading'], 'html' => self::menu($page->url, false)];
    }

    /**
     * Whether a page URL is a Student Records page.
     *
     * @param \moodle_url $url
     * @return bool
     */
    public static function is_sr_url(\moodle_url $url): bool {
        global $CFG;
        $full = $url->out_omit_querystring();
        $root = $CFG->wwwroot;
        if (!str_starts_with($full, $root)) {
            return false;
        }
        return self::is_sr_path(substr($full, strlen($root)));
    }

    /**
     * Whether the Student Records theme shows the menu in its drawer on this page.
     *
     * @return bool
     */
    public static function theme_has_drawer(): bool {
        global $PAGE;
        $theme = $PAGE->theme->name ?? '';
        return $theme === self::THEME || in_array(self::THEME, $PAGE->theme->parents ?? [], true);
    }

    /**
     * The menu at the top of the page for themes without the Student Records drawer: open on wide screens and folded on
     * small ones. Only a script could open one details element by screen width, and the page must work without one, so
     * there is a copy for each and styles.css shows one of them.
     *
     * @return string HTML
     */
    public static function inline_menu(): string {
        global $PAGE;
        if (self::theme_has_drawer()) {
            return '';
        }
        $menu = self::menu($PAGE->url);
        if ($menu === '') {
            return '';
        }
        $summary = \html_writer::tag('summary', get_string('nav_menu', 'local_studentrecords'));
        $wide = ['class' => 'sr-inline-menu sr-inline-menu-wide mb-3', 'open' => 'open'];
        $small = ['class' => 'sr-inline-menu sr-inline-menu-small mb-3'];
        // The wide copy comes first. Each copy has its own call to menu(), so that no id comes twice on the page.
        return \html_writer::tag('details', $summary . $menu, $wide) .
            \html_writer::tag('details', $summary . self::menu($PAGE->url), $small);
    }

    /**
     * A stat tile: label, big value, a line under it, optional link and tone (good, warn, bad).
     *
     * @param string $label
     * @param string $value
     * @param string $sub
     * @param \moodle_url|null $link
     * @param string $linktext
     * @param string $tone '', 'good', 'warn' or 'bad'
     * @return string HTML
     */
    public static function tile(
        string $label,
        string $value,
        string $sub = '',
        ?\moodle_url $link = null,
        string $linktext = '',
        string $tone = ''
    ): string {
        $html = \html_writer::div(s($label), 'sr-tile-label');
        $html .= \html_writer::div($value, 'sr-tile-value' . ($tone ? ' sr-tone-' . $tone : ''));
        if ($sub !== '') {
            $html .= \html_writer::div($sub, 'sr-tile-sub' . ($tone && $tone !== 'good' ? ' sr-tone-' . $tone : ''));
        }
        if ($link) {
            $html .= \html_writer::div(\html_writer::link($link, s($linktext)), 'sr-tile-link');
        }
        return \html_writer::div($html, 'sr-tile');
    }

    /**
     * A row of stat tiles (four across on wide screens, two on phones).
     *
     * @param string[] $tiles HTML of each tile
     * @return string HTML
     */
    public static function tiles(array $tiles): string {
        return $tiles ? \html_writer::div(implode('', $tiles), 'sr-tiles sr-tiles-' . count($tiles)) : '';
    }

    /**
     * A status tag. Colours only for status: good (green), warn (amber), bad (red), or neutral.
     *
     * @param string $text
     * @param string $tone 'good', 'warn', 'bad' or 'neutral'
     * @return string HTML
     */
    public static function tag(string $text, string $tone = 'neutral'): string {
        $tone = in_array($tone, ['good', 'warn', 'bad', 'neutral', 'info'], true) ? $tone : 'neutral';
        return \html_writer::span(s($text), 'sr-tag sr-tag-' . $tone);
    }

    /**
     * A card with an optional heading.
     *
     * @param string $body HTML
     * @param string $heading plain text
     * @param string $class extra classes
     * @return string HTML
     */
    public static function card(string $body, string $heading = '', string $class = ''): string {
        $h = $heading !== '' ? \html_writer::tag('h3', s($heading), ['class' => 'sr-card-title']) : '';
        return \html_writer::div($h . $body, trim('sr-card ' . $class));
    }

    /**
     * "Needs your attention": rows of text with a count and a link.
     *
     * @param array $rows each [text, moodle_url|null, link text, count|null]
     * @param string $empty text when there is nothing to do
     * @return string HTML
     */
    public static function attention(array $rows, string $empty): string {
        if (!$rows) {
            return self::empty_state($empty);
        }
        $items = '';
        foreach ($rows as [$text, $url, $linktext, $count]) {
            $right = $url ? \html_writer::link($url, s($linktext), ['class' => 'sr-attn-link']) : '';
            $num = $count !== null ? \html_writer::span((string)$count, 'sr-attn-count') : '';
            $items .= \html_writer::tag('li', \html_writer::span(s($text), 'sr-attn-text') . $num . $right);
        }
        return \html_writer::tag('ul', $items, ['class' => 'sr-attn']);
    }

    /**
     * Quick links as a grid of buttons.
     *
     * @param array $links each [moodle_url, label]
     * @return string HTML
     */
    public static function quick_links(array $links): string {
        $out = '';
        foreach ($links as [$url, $label]) {
            $out .= \html_writer::link($url, s($label), ['class' => 'btn btn-outline-secondary sr-quick']);
        }
        return $out !== '' ? \html_writer::div($out, 'sr-quicklinks') : '';
    }

    /**
     * An empty state: a short, friendly line.
     *
     * @param string $text
     * @return string HTML
     */
    public static function empty_state(string $text): string {
        return \html_writer::div(s($text), 'sr-empty');
    }

    /**
     * Greeting by the time of day in the user's time zone, with the preferred or first name.
     *
     * @param int $now
     * @return string
     */
    public static function greeting(int $now): string {
        global $USER;
        $hour = (int)userdate($now, '%H', 99, false, false);
        $key = $hour < 12 ? 'greet_morning' : ($hour < 18 ? 'greet_afternoon' : 'greet_evening');
        $name = trim((string)($USER->firstname ?? ''));
        return get_string($key, 'local_studentrecords', $name);
    }
}
