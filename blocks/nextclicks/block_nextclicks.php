<?php
defined('MOODLE_INTERNAL') || die();

class block_nextclicks extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_nextclicks');
    }

    public function applicable_formats() {
        return [
            'course-view' => true,
            'mod'         => true,
        ];
    }

    public function get_content() {
        global $PAGE, $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        $courseid = isset($COURSE->id) ? (int)$COURSE->id : 0;
        if ($courseid <= 0 || $courseid === (int)SITEID) {
            $this->content->text = html_writer::tag('div', 'Not inside a course.', ['style' => 'font-size:12px;']);
            return $this->content;
        }

        // Detect cmid reliably.
        $cmid = 0;

        // Preferred: Moodle sets $PAGE->cm on activity pages.
        if (!empty($PAGE->cm) && !empty($PAGE->cm->id)) {
            $cmid = (int)$PAGE->cm->id;

        } else {
            // IMPORTANT: only treat ?id= as cmid on /mod/... pages.
            // On /course/view.php?id=2 the id is a course id, not cmid.
            $pagetype = (string)$PAGE->pagetype;
            if (strpos($pagetype, 'mod-') === 0) {
                $paramid = optional_param('id', 0, PARAM_INT);
                if ($paramid > 0) {
                    $cmid = $paramid;
                }
            }
        }

        $sourcekey = ($cmid > 0) ? ('cm:' . $cmid) : ('course:' . $courseid);

        try {
            $items = \local_nextclicks\service::get_top_next($courseid, $sourcekey, 3);

            if (empty($items)) {
                $this->content->text = html_writer::tag('div', get_string('norecommendations', 'block_nextclicks'));
                return $this->content;
            }

            $list = html_writer::start_tag('ul');
            foreach ($items as $item) {
                $link = html_writer::link($item['url'], format_string($item['name']));
                $list .= html_writer::tag('li', $link . ' (' . (int)$item['cnt'] . ')');
            }
            $list .= html_writer::end_tag('ul');

            $this->content->text = $list;
            return $this->content;

        } catch (\Throwable $e) {
            $this->content->text = html_writer::tag('pre',
                "ERROR:\n" . $e->getMessage() . "\n\n" . $e->getTraceAsString(),
                ['style' => 'white-space:pre-wrap; font-size:11px;']
            );
            return $this->content;
        }
    }
}
