# Grade Lookup (report_gradelookup)

A Moodle site report that lets an administrator **look up any learner — enrolled or
not — and see their grades across every course**, alongside their certificates, with a
per-course item-by-item breakdown. It also **aggregates a filtered set of learners**
(average/median grade, completion, certificates, and log-based active time) and exports
everything to Excel.

Find it at **Site administration ▸ Reports ▸ Grade Lookup**.

## Why it exists

When a learner is unenrolled (for example by an annual cohort-expiry policy), Moodle
**purges their live grades** (`grade_grades`) — so the grader report, the user report
and the profile all show nothing. The data isn't gone: it survives in
`grade_grades_history`, but no core screen lets you type a name and see a past learner's
full grade record across all courses. This report fills that gap by reading history and
preferring live grades where present.

## What it does

**One unified search** (Site admin ▸ Reports ▸ Grade Lookup):

- **Find learners** — a type-ahead multi-select that searches all site users by
  name/email using Moodle's core AJAX user selector (`core_user/form_user_selector`).
  Pick one or several.
- **Filter by course / by cohort** — multi-select pickers; select several to widen the
  set (union), and combine facets to narrow it (intersection).
- **Active-time window (optional)** — native HTML5 date inputs that bound the active-time
  figures to a period.
- Active filters show as removable chips with a *clear all*; results are capped with an
  honest "showing X of Y" note.

**For a searched set** it shows a compact summary (learner count, average grade of those
who attempted, median, completion rate when a single course is selected, certificates,
and total/average **active time** reconstructed from the standard log the way Moodle's
Dedication block does), a per-learner list, and a **two-sheet Excel export** (aggregate
summary + raw list).

**For a single learner** it shows their course-total grade in every course (live where
available, recovered from grade history otherwise), each grade clickable to a full
**item-by-item breakdown** for that course; their **certificates** (mod_customcert) with
verification links; a CSV export; and their own summary tiles scoped to the list context.

## Design / robustness

- **Read-only.** It never writes; it only reads existing core tables. No schema
  (`install.xml`), no scheduled tasks, no cron, no observers.
- **Upgrade-safe.** Uses only stable public APIs — the DML API, the `autocomplete` form
  element with the core AJAX user selector, `\core_user\fields`, `moodle_url`,
  `format_string`, `userdate`, `get_course`, `MoodleExcelWorkbook`, `make_timestamp` —
  no core hacks and no private/internal calls. Targets Moodle **4.3 and later**
  (`$plugin->supported = [403, 500]`).
- **Efficient.** Grade, cohort and active-time data are gathered with bulk queries and
  streamed recordsets; work is capped (`AGG_CAP`, `TIME_CAP`) with an honest note.
- **No conflicts.** Frankenstyle-namespaced (`report_gradelookup\…`); CSS scoped to the
  report's own wrappers; mod_customcert and cohorts are queried only when present.
- **Privacy.** Stores no data of its own — ships a null privacy provider. It displays
  existing grade/certificate/log data owned by core subsystems.
- **Security.** Gated by the `report/gradelookup:view` capability (manager archetype,
  `RISK_PERSONAL`) at system context. Because it shows any learner's data across all
  courses, grant it only to trusted roles.

## Install

1. Copy this folder to `report/gradelookup` in your Moodle root, **or** upload the ZIP
   via **Site administration ▸ Plugins ▸ Install plugins**.
2. Visit **Site administration ▸ Notifications** to run the install.
3. Find it at **Site administration ▸ Reports ▸ Grade Lookup**.

## Capability

`report/gradelookup:view` — assign to the roles that should be able to look up learner
grade records (managers by default). The learner picker additionally relies on
`moodle/user:viewalldetails`, and the cohort filter on `moodle/cohort:view`, which the
target roles normally already have.

## Maintained by Sternfast

Grade Lookup is built and maintained by [Sternfast](https://sternfast.com), an LMS
engineering team — Moodle development, LMS migration, grade-history recovery, SCORM
repair, and managed hosting.

- **How it works, and the manual SQL it replaces:**
  <https://sternfast.com/blog/moodle-view-all-learner-grades>
- **Bugs & feature requests:** open an issue on this repository.
- **Need a report core Moodle doesn't have?** [Get in touch](https://sternfast.com/contact).

## License

GPL v3 or later. See [LICENSE](LICENSE).
