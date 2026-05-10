; moosh make manifest — Moodle 5.2 site for teaching programming.
;
; Build this with:
;   moosh make:build examples/programming-course.make /path/to/new/site         (preview)
;   moosh make:build examples/programming-course.make /path/to/new/site --run   (execute)

api = 1

; ── Moodle core ───────────────────────────────────────────────
; Branch MOODLE_502_STABLE is derived automatically from version.
[core]
version = 5.2

; ── Programming assessment ────────────────────────────────────
; CodeRunner — run student-submitted code against teacher-defined
; test cases. The bread-and-butter plugin for any CS course on
; Moodle. Requires a Jobe sandbox server, installed separately.
[qtype_coderunner]

; filter_ace_inline — runnable code blocks inside book chapters,
; pages, and forum posts. Companion plugin from the CodeRunner
; authors; great for live-demo lecture notes.
[filter_ace_inline]

; Virtual Programming Lab — full in-browser IDE with auto-grading
; and similarity detection. Heavier-weight than CodeRunner; useful
; for longer projects and lab-style assignments.
[mod_vpl]

; ── Course management ─────────────────────────────────────────
; Track attendance for in-person labs and lectures.
[mod_attendance]

; Visual progress bar — pairs with activity completion so students
; can see at a glance which exercises they have finished.
[block_completion_progress]

; ── Theme ─────────────────────────────────────────────────────
; Boost Union — customisable theme with good defaults for
; code-heavy sites. Pulled from GitHub on a pinned branch so you
; control when upstream updates land.
[theme_boost_union]
git    = https://github.com/moodle-an-hochschulen/moodle-theme_boost_union.git
branch = MOODLE_502_STABLE
