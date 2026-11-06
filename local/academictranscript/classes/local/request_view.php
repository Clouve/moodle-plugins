<?php

namespace local_academictranscript\local;

use html_writer;

/**
 * The student's side of My transcript: the form that requests an Official transcript, and the student's requests.
 *
 * @package    local_academictranscript
 * @copyright  2026 Clouve Inc.
 * @license    https://opensource.org/license/mit MIT
 */
class request_view {
    /** @var array Queue state => tone of its tag. */
    const TONES = ['pending' => 'info', 'onhold' => 'warn', 'waiting' => 'neutral', 'issued' => 'good', 'cancelled' => 'neutral'];

    /**
     * The request form.
     *
     * @param int $userid
     * @param \moodle_url $url where the form posts to
     * @return string HTML
     */
    public static function form(int $userid, \moodle_url $url): string {
        $at = 'local_academictranscript';
        $holds = requests::holds($userid);
        $f = html_writer::tag('h2', get_string('req_title', $at), ['class' => 'sr-card-title']);
        $f .= $holds ?
            html_writer::div(get_string('req_holds', $at, implode('; ', array_map('s', $holds))), 'sr-note sr-note-warn') :
            html_writer::div(get_string('req_noholds', $at), 'sr-note sr-note-good');
        $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'request']);

        $radios = '';
        foreach (array_keys(requests::DELIVERY) as $i => $delivery) {
            $id = 'atdel_' . $delivery;
            $fee = format_float(requests::fee($delivery), 2);
            $radios .= html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'delivery', 'value' => $delivery, 'id' => $id,
                'class' => 'sr-seg-input', 'data-fee' => $fee] + ($i === 0 ? ['checked' => 'checked'] : [])) .
                html_writer::label(
                    html_writer::span(get_string('req_delivery_' . $delivery, $at), 'd-block') .
                    html_writer::span(get_string('req_money', $at, $fee), 'd-block small'),
                    $id,
                    false,
                    ['class' => 'sr-seg-label sr-seg-excused']
                );
        }
        $f .= html_writer::tag('fieldset', html_writer::tag('legend', get_string('req_how', $at), ['class' => 'sr-legend']) .
            html_writer::div($radios, 'sr-seg-group sr-seg-3'), ['class' => 'mb-3']);

        $f .= self::field('recipient', get_string('req_sendto', $at), 'text', ['required' => 'required', 'maxlength' => 255]);
        $f .= self::field('email', get_string('req_email', $at), 'email', ['maxlength' => 255]);
        $f .= html_writer::div(
            html_writer::label(get_string('req_address', $at), 'at_address', false, ['class' => 'd-block small']) .
            html_writer::tag('textarea', '', ['name' => 'address', 'id' => 'at_address', 'rows' => 3, 'class' => 'form-control']),
            'mb-2 at-field-address'
        );

        // Waiting for grades or the degree needs Student Records. Without it the registrar sees the request at once.
        if (srsource::enabled()) {
            $when = '';
            foreach (requests::TIMING as $i => $timing) {
                $when .= html_writer::div(
                    html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'timing', 'value' => $timing,
                    'id' => 'attime_' . $timing, 'class' => 'form-check-input'] +
                    ($i === 0 ? ['checked' => 'checked'] : [])) . ' ' .
                    html_writer::label(
                        get_string('req_timing_' . $timing, $at),
                        'attime_' . $timing,
                        false,
                        ['class' => 'form-check-label']
                    ),
                    'form-check'
                );
            }
            $f .= html_writer::tag(
                'fieldset',
                html_writer::tag('legend', get_string('req_when', $at), ['class' => 'sr-legend']) . $when,
                ['class' => 'mb-3']
            );
        } else {
            $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timing', 'value' => 'now']);
        }

        $first = array_key_first(requests::DELIVERY);
        $f .= html_writer::div(html_writer::span(get_string('req_total', $at)) . html_writer::span(get_string(
            'req_money',
            $at,
            format_float(requests::fee($first), 2)
        ), 'at-total'), 'sr-costtotal');
        $f .= html_writer::tag('button', get_string('req_submit', $at), ['type' => 'submit', 'class' => 'btn btn-primary w-100']);
        $f .= html_writer::tag(
            'p',
            get_string(requests::can_charge($userid) ? 'req_feenote' : 'req_feenote_nocharge', $at),
            ['class' => 'sr-muted small mt-2 mb-0']
        );
        return html_writer::tag('form', $f, ['method' => 'post', 'action' => $url->out_omit_querystring(),
            'class' => 'sr-card at-request']);
    }

    /**
     * One labelled input of the form.
     *
     * @param string $name
     * @param string $label
     * @param string $type
     * @param array $attributes
     * @return string HTML
     */
    protected static function field(string $name, string $label, string $type, array $attributes): string {
        return html_writer::div(
            html_writer::label($label, 'at_' . $name, false, ['class' => 'd-block small']) .
            html_writer::empty_tag('input', ['type' => $type, 'name' => $name, 'id' => 'at_' . $name,
                'class' => 'form-control'] + $attributes),
            'mb-2 at-field-' . $name
        );
    }

    /**
     * The student's requests, newest first.
     *
     * @param int $userid
     * @return string HTML
     */
    public static function mine(int $userid): string {
        $at = 'local_academictranscript';
        $out = html_writer::tag('h2', get_string('req_mine', $at), ['class' => 'sr-h2']);
        $mine = requests::for_user($userid);
        if (!$mine) {
            return $out . html_writer::div(get_string('req_none', $at), 'sr-empty');
        }
        $table = new \html_table();
        $table->attributes['class'] = 'sr-table';
        $table->head = [get_string('req_requested', $at), get_string('req_sentto', $at), get_string('status')];
        foreach ($mine as $r) {
            $state = requests::state($r);
            $table->data[] = [userdate((int)$r->timecreated, get_string('strftimedateshortmonthabbr', 'langconfig')),
                s($r->recipient) . ' · ' . get_string('req_delivery_' . $r->delivery, $at),
                html_writer::span(get_string('req_state_' . $state, $at), 'sr-tag sr-tag-' . self::TONES[$state])];
        }
        return $out . html_writer::table($table);
    }

    /**
     * Script of the form: show the fields of the chosen delivery and its fee.
     *
     * @return string JavaScript
     */
    public static function script(): string {
        $money = json_encode(get_string('req_money', 'local_academictranscript', 'X'));
        return "
            (function() {
                const form = document.querySelector('form.at-request');
                if (!form) {
                    return;
                }
                const show = function() {
                    const d = form.querySelector('input[name=delivery]:checked');
                    form.querySelector('.at-field-email').hidden = d.value !== 'email';
                    form.querySelector('.at-field-email input').disabled = d.value !== 'email';
                    form.querySelector('.at-field-address').hidden = d.value !== 'mail';
                    form.querySelector('.at-field-address textarea').disabled = d.value !== 'mail';
                    form.querySelector('.at-total').textContent = $money.replace('X', d.dataset.fee);
                };
                form.addEventListener('change', show);
                show();
            })();
        ";
    }
}
