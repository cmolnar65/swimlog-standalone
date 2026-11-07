# Standalone swim goals

The standalone Goals screen reads and writes the installed WordPress plugin's
schema 1.6 goals table. WordPress remains the only identity, database, and
recorded-workout authority. The standalone app adds no goals table, migration, or
copied workout history. Every list, progress query, calendar marker, save, and
delete is scoped to the signed-in owner's ID and requires that owner's existing
workout-management capability, including for users who can manage all swimmers
elsewhere in WordPress.

## One-time performance goals

A swimmer may choose a supported evaluated distance, a target pace per 100 in
meters or yards, or both, and a due date. The date is inclusive. A distance-only
goal completes when a matching evaluated performance is recorded in the selected
course by that date, at any pace. A pace-only goal completes when any evaluated
performance in that course meets or beats the pace target. When both are set,
the performance must match the exact distance and course and meet the pace
threshold. Any stroke can qualify. Only existing evaluated performances count;
manual time or distance fields do not become an evaluated performance.

## Date-range distance goals

The first and last dates are inclusive. Progress uses canonical
`total_distance_m` values for pool and open-water workouts, across all strokes.
Yard swims are normalized to meters in this field and count toward the same
swimming total as meter swims; land workouts and missing distances do not count.
The plugin stores goal targets canonically in meters as well. The standalone
compares those meter totals and targets, then displays both in the goal's
selected course unit (meters or yards). Cumulative goals stop on the first swim
that reaches the target, cap the displayed total there, and record that swim's
date. Recorded workouts and course-specific performances remain in their stored
units and are not rewritten or merged.

### Single Workout option

Checking **Single Workout** changes a date-range distance goal to require one
pool or open-water workout in the selected date range to meet the target. It
uses the same canonical meter distance and selected-unit display as cumulative
goals. Before completion, progress shows the longest individual workout in the
range, not the sum. The first qualifying workout marks the goal achieved, records
that workout's date, and caps progress at the target; later swims cannot raise
the displayed total. Leaving the option unchecked retains cumulative behavior.

Progress reflects current recorded history, so correcting, importing, or
removing a workout can change a goal's progress. It does not alter workout
history, personal bests, planned workouts, public visibility, or other training
evaluations. Goals stay private and are not shown by public workout views.

## Calendar

The private calendar marks a one-time goal on its due date. A date-range goal is
marked on its first and last days; a one-day range has a single marker. Expanding
a marker shows target, current progress, and status. Goal markers use the same
owner and capability checks as the Goals screen.
